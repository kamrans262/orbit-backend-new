import assert from 'node:assert/strict';
import test from 'node:test';
import {normalizedEvent,sanitizedIncoming,validChannel,validTicket} from '../contracts.mjs';

const cid='01234567-89ab-cdef-0123-456789abcdef';
const sid='fedcba98-7654-3210-fedc-ba9876543210';
const now=Date.parse('2026-10-26T14:00:00Z');
function frame(event,issued_at='2026-10-26T13:59:59Z'){
  return JSON.stringify({v:1,issued_at,event});
}
test('websocket authentication requires opaque short-lived ticket', () => {
  assert.equal(validTicket('x'.repeat(43)),true);
  assert.equal(validTicket('bad token'),false);
  assert.equal(sanitizedIncoming(JSON.stringify({type:'auth',ticket:'x'.repeat(43)})).type,'auth');
  assert.equal(sanitizedIncoming('{"type":"auth","ticket":"wrong"}'),null);
});
test('never accept a client-specified user_id or generic event forwarding', () => {
  assert.deepEqual(sanitizedIncoming(JSON.stringify({type:'presence.update',state:'active',user_id:sid})),
    {kind:'presence.update',state:'active'});
  assert.equal(sanitizedIncoming('{"type":"publish","circle_id":"x"}'),null);
  assert.equal(sanitizedIncoming(JSON.stringify({type:'typing.update',circle_id:cid,typing:true})).kind,'typing.update');
});
test('redis names are server-generated opaque per-recipient channels', () => {
  assert.equal(validChannel('orbit:m9:viewer:'+'a'.repeat(64)),true);
  assert.equal(validChannel('orbit:m9:viewer:'+cid),false);
});
test('unknown, stale, oversized and expired protected events are never forwarded', () => {
  const active={type:'presence.changed',circle_id:cid,subject_id:sid,
    snapshot:{state:'active_now',expires_at:'2026-10-26T14:00:30Z'}};
  assert.equal(normalizedEvent(frame(active),now)?.type,'presence.changed');
  assert.equal(normalizedEvent(frame(active,'2026-10-26T13:55:00Z'),now),null);
  assert.equal(normalizedEvent(frame({...active,snapshot:{...active.snapshot,expires_at:'2026-10-26T13:59:59Z'}}),now),null);
  assert.equal(normalizedEvent(frame({...active,type:'private.message'}),now),null);
  assert.equal(normalizedEvent('x'.repeat(16001),now),null);
});
test('circle_clear contains no protected user payload; typing requires a 5s deadline', () => {
  assert.deepEqual(normalizedEvent(frame({type:'presence.circle_clear',circle_id:cid,subject_id:sid}),now),
    {type:'presence.circle_clear',circle_id:cid});
  assert.equal(normalizedEvent(frame({type:'presence.typing',circle_id:cid,subject_id:sid,
    typing:true,expires_at:'2026-10-26T13:59:59Z'}),now),null);
  assert.equal(normalizedEvent(frame({type:'presence.typing',circle_id:cid,subject_id:sid,
    typing:true,expires_at:'2026-10-26T14:00:04Z'}),now)?.typing,true);
});
