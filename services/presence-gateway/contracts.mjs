/**
 * Pure, fail-closed M9 gateway contract checks.
 * Not a source of authority: Laravel still authorizes each delivered event.
 */
export const allowedEvents = new Set([
  'presence.changed', 'presence.clear', 'presence.circle_clear', 'presence.typing',
]);
export const allowedInbound = new Set(['presence.update', 'typing.update']);
export function validTicket(value) {
  return typeof value === 'string' && /^[A-Za-z0-9_-]{40,100}$/.test(value);
}
export function validChannel(value) {
  return typeof value === 'string'
    && /^orbit:m9:viewer:[a-f0-9]{64}$/.test(value);
}
export function validUuid(value) {
  return typeof value === 'string'
    && /^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$/.test(value);
}
export function normalizedEvent(json, now = Date.now()) {
  if (typeof json !== 'string' || json.length > 16000) return null;
  let envelope;
  try { envelope = JSON.parse(json); } catch { return null; }
  if (!envelope || envelope.v !== 1 || typeof envelope !== 'object'
    || typeof envelope.issued_at !== 'string') return null;
  const issued = Date.parse(envelope.issued_at);
  if (!Number.isFinite(issued) || issued > now + 10000 || now - issued > 30000) return null;
  const value = envelope.event;
  if (!value || typeof value !== 'object' || Array.isArray(value)
      || !allowedEvents.has(value.type) || !validUuid(value.circle_id)) return null;
  if (value.type !== 'presence.circle_clear' && !validUuid(value.subject_id)) return null;
  if (value.type === 'presence.changed') {
    const snapshot = value.snapshot;
    if (!snapshot || (snapshot.state !== 'active_now' && snapshot.state !== 'idle')
        || !Number.isFinite(Date.parse(snapshot.expires_at))
        || Date.parse(snapshot.expires_at) <= now) return null;
  }
  if (value.type === 'presence.typing') {
    if (typeof value.typing !== 'boolean') return null;
    if (value.typing && (!Number.isFinite(Date.parse(value.expires_at))
        || Date.parse(value.expires_at) <= now)) return null;
  }
  return { type: value.type, circle_id: value.circle_id,
    ...(value.type !== 'presence.circle_clear' && value.subject_id
      ? { subject_id: value.subject_id } : {}),
    ...(value.type === 'presence.changed' ? { snapshot: value.snapshot } : {}),
    ...(value.type === 'presence.typing'
      ? { typing: value.typing, expires_at: value.expires_at ?? null } : {}) };
}
export function sanitizedIncoming(json) {
  if (typeof json !== 'string' || json.length > 2048) return null;
  let v;
  try { v = JSON.parse(json); } catch { return null; }
  if (!v || Array.isArray(v) || typeof v !== 'object') return null;
  if (v.type === 'auth') return validTicket(v.ticket) ? {type:'auth',ticket:v.ticket}: null;
  if (!allowedInbound.has(v.type)) return null;
  if (v.type === 'presence.update') {
    return ['active','idle'].includes(v.state)
      ? {kind:v.type,state:v.state} : null;
  }
  return validUuid(v.circle_id) && typeof v.typing === 'boolean'
    ? {kind:v.type,circle_id:v.circle_id,typing:v.typing} : null;
}
