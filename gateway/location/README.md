# M10 restricted location invalidation gateway

Stand-alone Node 20+ sidecar. Not part of the Hostinger shared-hosting PHP
worker; do not run there without verified always-on service and reverse proxy.
No raw GPS, profile images, access tokens, media keys or recipient names enter Redis.

* In this folder run npm install on a vetted, managed gateway host (do not commit secrets).
* Set ORBIT_M10_GATEWAY_BIND=127.0.0.1 and a valid private port; use a
  trusted HTTPS reverse proxy that upgrades WSS, enforces a tightly controlled
  allowed Origin, per-IP connection quotas and TLS. Never expose plain WS.
* Set ORBIT_M10_GATEWAY_INTROSPECT_URL to the HTTPS backend API
  /api/v1/location/realtime/introspect endpoint.
* Set a random >=32-character ORBIT_M10_GATEWAY_SECRET on BOTH Laravel
  and this server, and set a private authenticated ORBIT_M10_REDIS_URL.
* On Laravel, only after confirmed connectivity set M10 flags and
  ORBIT_M10_REALTIME_VERIFIED=true; set ORBIT_M10_WSS_URL to approved WSS endpoint.
* Flutter sends {type:"subscribe",ticket:"..."} as the first WSS message.
  Tickets have a maximum 60-second lease. Client must reconnect by requesting
  a new ticket; gateway rechecks authorization for EACH invalidation.
* Gateway transmits only {v:1,type:"location.invalidated"} with no coordinates
  or subject IDs. Flutter then fetches a fresh user-scoped REST map.
* On logout, revoked trust, blocked relationship or Ghost, REST remains the
  independent source of truth. No gateway is considered verified before
  full device and revocation tests, Redis TLS/ACL and security approval.
