// Private M10 invalidation gateway; binds loopback only behind verified WSS TLS
// termination. Never carries GPS coordinates, profile images or session tokens.
import { createServer } from 'node:http';
import { WebSocketServer } from 'ws';
import { createClient } from 'redis';

const host=process.env.ORBIT_M10_GATEWAY_BIND || '127.0.0.1';
const port=Number(process.env.ORBIT_M10_GATEWAY_PORT||8091);
const api=process.env.ORBIT_M10_GATEWAY_INTROSPECT_URL||'';
const secret=process.env.ORBIT_M10_GATEWAY_SECRET||'';
const redisUrl=process.env.ORBIT_M10_REDIS_URL||'';
if(!api.startsWith('https://')||secret.length<32||
   !redisUrl||host!=='127.0.0.1'||!Number.isInteger(port)||port<1024||port>65535) {
  throw new Error('Secure M10 gateway configuration is incomplete.');
}
const redis=createClient({url:redisUrl,socket:{reconnectStrategy:(n)=>Math.min(200*n,3000)}});
redis.on('error',()=>{}); // No message bodies/tickets in logs.
await redis.connect();
const http=createServer((req,res)=>{
  res.writeHead(404,{'Cache-Control':'no-store'});res.end();
});
const wss=new WebSocketServer({server:http,maxPayload:2048,perMessageDeflate:false});
async function check(ticket){
  const response=await fetch(api,{
    method:'POST',
    headers:{'Content-Type':'application/json','X-Orbit-Location-Gateway':secret},
    body:JSON.stringify({ticket}),
    signal:AbortSignal.timeout(3500),
  });
  if(!response.ok)return null;
  const obj=await response.json();
  const data=obj?.data;
  if(data?.authorized!==true||typeof data.channel!=='string'||
     !/^orbit:m10:viewer:[0-9a-f]{64}$/.test(data.channel))return null;
  return data;
}
wss.on('connection',(ws,req)=>{
  // Reverse proxy MUST enforce TLS, trusted Origin and request limits.
  // A one-time subscribe message carries the 60s ticket INSIDE TLS, not URL.
  let subscribed=false,viewer=null,ticket=null,stream=null;
  const idle=setTimeout(()=>ws.close(1008,'Authentication required'),6000);
  let lease=null;
  async function dispose(){
    clearTimeout(idle);clearTimeout(lease);
    if(stream){try{await stream.quit();}catch{}stream=null;}
  }
  ws.on('close',()=>{void dispose();});
  ws.on('error',()=>{void dispose();});
  ws.on('message',async(raw)=>{
    if(subscribed){ws.close(1008,'Read-only location stream');return;}
    let msg;
    try{msg=JSON.parse(raw.toString());}catch{ws.close(1008,'Invalid subscription');return;}
    if(msg?.type!=='subscribe'||typeof msg.ticket!=='string'||msg.ticket.length<40||
       msg.ticket.length>100){ws.close(1008,'Invalid subscription');return;}
    try{
      const auth=await check(msg.ticket);
      if(!auth){ws.close(1008,'Unauthorized');return;}
      subscribed=true;ticket=msg.ticket;viewer=auth.channel;
      clearTimeout(idle);
      lease=setTimeout(()=>ws.close(1008,'Renew ticket'),55000);
      stream=redis.duplicate();await stream.connect();
      await stream.subscribe(viewer,async(message)=>{
        if(ws.readyState!==1)return;
        try{
          const event=JSON.parse(message);
          if(event?.type!=='location.invalidated'||event?.v!==1)return;
          // Every delivery requires fresh Laravel session/device authorization.
          const fresh=await check(ticket);
          if(!fresh||fresh.channel!==viewer){
            ws.close(1008,'Session expired');return;
          }
          ws.send(JSON.stringify({v:1,type:'location.invalidated'}));
        }catch{ws.close(1011,'Backend unavailable');}
      });
      if(ws.readyState===1)ws.send(JSON.stringify({v:1,type:'subscribed',ttl:55}));
    }catch{ws.close(1011,'Gateway unavailable');}
  });
});
http.listen(port,host);
process.on('SIGTERM',async()=>{
  for(const client of wss.clients)client.close(1001,'Shutdown');
  wss.close();http.close();await redis.quit();
});
