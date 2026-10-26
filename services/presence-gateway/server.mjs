import fs from 'node:fs';
import https from 'node:https';
import {createClient} from 'redis';
import {WebSocketServer, WebSocket} from 'ws';
import {normalizedEvent, sanitizedIncoming, validChannel, validTicket} from './contracts.mjs';

/**
 * Orbit M9 gateway: requires a separately hosted always-on TLS Node service,
 * TLS Redis/Valkey and an authenticated Laravel M9 backend. No Hostinger shared
 * hosting process or Firebase configuration is silently assumed.
 */
const origin = process.env.ORBIT_BACKEND_API_ORIGIN ?? '';
const redisUrl = process.env.ORBIT_GATEWAY_REDIS_URL ?? '';
const secret = process.env.ORBIT_M9_GATEWAY_SECRET ?? '';
const certPath = process.env.ORBIT_GATEWAY_TLS_CERT_PATH ?? '';
const keyPath = process.env.ORBIT_GATEWAY_TLS_KEY_PATH ?? '';
const host = process.env.ORBIT_GATEWAY_BIND_HOST ?? '127.0.0.1';
const port = Number(process.env.ORBIT_GATEWAY_PORT ?? '8443');
const maxSockets = Number(process.env.ORBIT_GATEWAY_MAX_CLIENTS ?? '2000');
const maxSocketsPerViewer = Number(process.env.ORBIT_GATEWAY_MAX_VIEWER_DEVICES ?? '12');

function configurationValid() {
  let parsed;
  try { parsed = new URL(origin); } catch { return false; }
  return parsed.protocol === 'https:' && parsed.username === ''
    && parsed.password === '' && parsed.pathname === '/'
    && /^rediss:\/\//.test(redisUrl)
    && secret.length >= 32 && certPath !== '' && keyPath !== ''
    && Number.isInteger(port) && port > 0 && port <= 65535
    && Number.isInteger(maxSockets) && maxSockets > 0 && maxSockets <= 10000
    && Number.isInteger(maxSocketsPerViewer)
    && maxSocketsPerViewer > 0 && maxSocketsPerViewer <= 25;
}
if (!configurationValid()) {
  throw new Error('M9 gateway: TLS/Redis, backend HTTPS, shared gateway secret and limits are required.');
}
const redis = createClient({url: redisUrl, socket: {
  connectTimeout: 8000,
  reconnectStrategy: retries => Math.min(1000 * 2 ** Math.min(retries,4),15000),
}});
redis.on('error', () => { /* Never emit tokens, Redis endpoints or payloads in logs. */ });
await redis.connect();
const subscriber = redis.duplicate();
subscriber.on('error', () => {});
await subscriber.connect();

async function backend(path, payload) {
  let response;
  try {
    response = await fetch(origin + 'api/v1/presence/realtime/' + path, {
      method:'POST',
      headers: { 'Content-Type': 'application/json',
        'X-Orbit-Presence-Gateway': secret },
      body: JSON.stringify(payload),
      signal: AbortSignal.timeout(4000),
    });
  } catch { return null; }
  if (!response.ok) return null;
  try {
    const data = (await response.json())?.data;
    return data && typeof data === 'object' ? data : null;
  } catch { return null; }
}

const tlsServer = https.createServer({
  key: fs.readFileSync(keyPath),
  cert: fs.readFileSync(certPath),
  minVersion:'TLSv1.2',
}, (req,res) => {
  // No user/session data at this endpoint.
  res.writeHead(req.url === '/healthz' && redis.isReady && subscriber.isReady ? 200 : 503,
    {'Content-Type':'text/plain','Cache-Control':'no-store'});
  res.end(req.url === '/healthz' ? 'ok' : 'unavailable');
});
const wss = new WebSocketServer({
  server: tlsServer, path:'/m9/presence', maxPayload:2048,
  perMessageDeflate:false, clientTracking:true,
});
const channels = new Map(); // private recipient channel -> Set<authenticated socket contexts>
let activeSockets = 0;

async function subscribe(ctx, channel) {
  let entries = channels.get(channel);
  if (!entries) {
    entries = new Set();
    channels.set(channel,entries);
    try {
      await subscriber.subscribe(channel, message => {
        void deliver(channel,message);
      });
    } catch {
      channels.delete(channel);
      return false;
    }
  }
  entries.add(ctx);
  ctx.channel = channel;
  return true;
}

async function unbind(ctx) {
  const channel = ctx.channel;
  ctx.channel = null;
  if (!channel) return;
  const entries = channels.get(channel);
  if (!entries) return;
  entries.delete(ctx);
  if (entries.size === 0) {
    channels.delete(channel);
    try { await subscriber.unsubscribe(channel); } catch { /* Redis unavailable => closed. */ }
  }
}

function terminate(ctx) {
  if (ctx.closed) return;
  ctx.closed = true;
  clearInterval(ctx.guard);
  void unbind(ctx);
  ctx.socket.terminate();
}
async function validate(ctx, event = undefined) {
  if (ctx.closed || !validTicket(ctx.ticket)) return null;
  const result = await backend('introspect', {
    ticket:ctx.ticket, ...(event ? {event} : {}),
  });
  if (!result || result.viewer_id !== ctx.viewerId || result.channel !== ctx.channel
      || !validChannel(result.channel)) return null;
  return result;
}
async function deliver(channel,raw) {
  // Discard stale or malformed envelopes before doing any auth lookup.
  const event = normalizedEvent(raw);
  if (!event) return;
  const entries = channels.get(channel);
  if (!entries) return;
  for (const ctx of [...entries]) {
    if (ctx.closed || ctx.socket.readyState !== WebSocket.OPEN) continue;
    // Bound stale queued work and backpressure per recipient. Other recipients
    // must never wait for a slow/expired session in this Circle.
    ctx.pending=(ctx.pending ?? 0)+1;
    if (ctx.pending > 20 || ctx.socket.bufferedAmount > 65536) {
      terminate(ctx);
      continue;
    }
    const one = async () => {
      if (ctx.closed) return;
      // Every event is reauthorized in delivery order for THIS socket. Laravel
      // remains authority for current membership, consent, Ghost and blocks.
      const active = await validate(ctx,event);
      if (!active) {
        if (ctx.socket.readyState === WebSocket.OPEN) {
          try { ctx.socket.send(JSON.stringify({event:{
            type:'presence.circle_clear',circle_id:event.circle_id,
          }})); } catch {}
        }
        terminate(ctx);
        return;
      }
      if (ctx.socket.readyState !== WebSocket.OPEN || ctx.closed) return;
      ctx.socket.send(JSON.stringify({event}));
    };
    // Different clients are authorized concurrently, but messages for a
    // single client are never delivered out of order.
    ctx.queue=(ctx.queue ?? Promise.resolve()).then(one).catch(() => terminate(ctx))
      .finally(() => { ctx.pending=Math.max(0,(ctx.pending ?? 1)-1); });
  }
}

wss.on('connection', (socket) => {
  if (++activeSockets > maxSockets) {
    activeSockets--;
    socket.close(1013,'Capacity limit');
    return;
  }
  const ctx = {socket, viewerId:null, ticket:null, channel:null,
    guard:null, closed:false, authenticated:false, busy:false};
  const authDeadline = setTimeout(() => {
    if (!ctx.authenticated) terminate(ctx);
  },5000);
  ctx.guard = setInterval(async () => {
    if (ctx.closed || !ctx.authenticated) return;
    if (!await validate(ctx)) terminate(ctx);
  },10000);

  socket.on('message', async (buffer, binary) => {
    if (binary || ctx.closed || ctx.busy) { terminate(ctx); return; }
    const frame = sanitizedIncoming(buffer.toString('utf8'));
    if (!frame) {terminate(ctx); return;}
    ctx.busy = true;
    try {
      if (!ctx.authenticated) {
        if (frame.type !== 'auth') {terminate(ctx);return;}
        const auth = await backend('introspect',{ticket:frame.ticket});
        if (!auth || !validChannel(auth.channel)
            || typeof auth.viewer_id !== 'string') {
          terminate(ctx);return;
        }
        const peers = channels.get(auth.channel);
        if (peers?.size >= maxSocketsPerViewer) {terminate(ctx);return;}
        ctx.viewerId=auth.viewer_id;
        ctx.ticket=frame.ticket;
        if (!await subscribe(ctx,auth.channel)) {terminate(ctx);return;}
        ctx.authenticated=true;
        clearTimeout(authDeadline);
        if (socket.readyState === WebSocket.OPEN) {
          socket.send(JSON.stringify({type:'authenticated'}));
        }
        return;
      }
      if (frame.type==='auth') {terminate(ctx);return;}
      if (!await validate(ctx)) {terminate(ctx);return;}
      const reply = await backend('inbound',{ticket:ctx.ticket,...frame});
      if (!reply) {terminate(ctx);return;}
    } finally {
      ctx.busy=false;
    }
  });
  socket.on('close', () => {
    clearTimeout(authDeadline);
    activeSockets=Math.max(0,activeSockets-1);
    terminate(ctx);
  });
  socket.on('error', () => terminate(ctx));
});

async function shutdown() {
  for (const socket of wss.clients) socket.terminate();
  tlsServer.close();
  try {await subscriber.quit();await redis.quit();} catch {}
}
process.on('SIGTERM', () => {void shutdown();});
process.on('SIGINT', () => {void shutdown();});
tlsServer.listen(port,host,()=> {
  process.stdout.write('Orbit M9 gateway listening (TLS; runtime flags still separately required).\n');
});
