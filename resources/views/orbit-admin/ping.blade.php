@extends('orbit-admin.layout')
@section('title','Ping Operations')
@section('crumb','CONFIGURATIONS / PING OPERATIONS')
@section('content')
<div class="page-heading">
  <div>
    <p class="eyebrow">PING SERVICE / PRIVACY-SAFE OPERATIONS</p>
    <h1>Ping Operations</h1>
    <p>Monitor secure Ping requests and confirmed recipient acknowledgements. Messages, recipient identities, private Circle rosters and device information are never exposed here.</p>
  </div>
</div>

<section class="panel">
  <div class="panel-head"><div>
    <p class="eyebrow">DELIVERY SERVICE / CURRENT AVAILABILITY</p>
    <h2>Service readiness</h2>
    <p>Authenticated HTTP requests provide the authoritative inbox and status. Delivery is confirmed only after a trusted recipient device acknowledges the Ping.</p>
  </div></div>
  <div class="admin-guidance" role="status">
    Ping API: <strong>{{ $ready && $enabled ? 'Available to authorized members' : ($ready ? 'Disabled by configuration' : 'Database setup required') }}</strong>.
    Delivery transport: <strong>Secure foreground refresh</strong>.
    Background push: <strong>Not connected</strong>. Instant WebSocket delivery: <strong>Not connected</strong>.
    All send and read actions require current privacy permissions and trusted-device authentication.
  </div>
  <div class="admin-kv">
    <div class="kv"><small>Database readiness</small><strong>{{ $ready ? 'Installed' : 'Migration required' }}</strong></div>
    <div class="kv"><small>Circle Ping access</small><strong>{{ $circleEnabled ? 'Available with consent' : 'Disabled' }}</strong></div>
    <div class="kv"><small>Account rate limit</small><strong>{{ $perMinute }} per minute</strong></div>
    <div class="kv"><small>Recipient-pair rate limit</small><strong>{{ $perPair }} per hour</strong></div>
  </div>
  <p>Circle membership alone never authorizes a Ping. Each request also respects user privacy, blocks, individual permissions and active membership.</p>
</section>

<section class="panel" style="margin-top:18px">
  <div class="panel-head"><div>
    <p class="eyebrow">AGGREGATE TRAFFIC / LAST 24 HOURS</p>
    <h2>Delivery and acknowledgement</h2>
    <p>Only aggregated events are displayed. These figures do not identify individual members or expose custom messages.</p>
  </div></div>
  @if($metrics)
  <div class="admin-kv">
    <div class="kv"><small>Accepted requests</small><strong>{{ $metrics['accepted_24h'] }}</strong></div>
    <div class="kv"><small>Confirmed delivered</small><strong>{{ $metrics['confirmed_delivery_24h'] }}</strong></div>
    <div class="kv"><small>Viewed on recipient devices</small><strong>{{ $metrics['viewed_24h'] }}</strong></div>
    <div class="kv"><small>Quick acknowledgements</small><strong>{{ $metrics['replies_24h'] }}</strong></div>
    <div class="kv"><small>Awaiting recipient confirmation (all time)</small><strong>{{ $metrics['awaiting_receipt'] }}</strong></div>
    <div class="kv"><small>Average recent confirmation lag</small><strong>{{ $metrics['delivery_lag_seconds'] === null ? 'Not available' : $metrics['delivery_lag_seconds'].' seconds' }}</strong></div>
    <div class="kv"><small>Rejected requests</small><strong>Not instrumented</strong></div>
    <div class="kv"><small>Offline retry backlog</small><strong>Managed on member devices</strong></div>
  </div>
  @else
  <div class="admin-guidance" role="status">Ping metrics will be available after the database migration has been applied.</div>
  @endif
  <p>Accepted is not delivered. Failed or rejected requests and offline retries are not counted as successful delivery. Recipient “Seen” remains subject to their read-receipt preference.</p>
  <p><a href="{{ route('orbit.admin.flags.index') }}">View feature flags</a></p>
</section>
@endsection
