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
    <p class="eyebrow">AGGREGATE TRAFFIC / LAST 24 HOURS</p>
    <h2>Delivery and acknowledgement</h2>
    <p>Only aggregated events are displayed. These figures do not identify individual members or expose custom messages.</p>
  </div></div>
  <div class="admin-kv">
    <div class="kv"><small>Ping availability</small><strong>{{ $ready && $enabled ? 'Available to authorized members' : ($ready ? 'Disabled by configuration' : 'Database setup required') }}</strong></div>
    <div class="kv"><small>Circle Ping</small><strong>{{ $circleEnabled ? 'Available with individual consent' : 'Disabled' }}</strong></div>
    <div class="kv"><small>Account sending limit</small><strong>{{ $perMinute }} per minute</strong></div>
    <div class="kv"><small>Recipient-pair sending limit</small><strong>{{ $perPair }} per hour</strong></div>
  </div>
  @if($metrics)
  <div class="admin-kv">
    <div class="kv"><small>Accepted requests</small><strong>{{ $metrics['accepted_24h'] }}</strong></div>
    <div class="kv"><small>Confirmed delivered</small><strong>{{ $metrics['confirmed_delivery_24h'] }}</strong></div>
    <div class="kv"><small>Viewed on recipient devices</small><strong>{{ $metrics['viewed_24h'] }}</strong></div>
    <div class="kv"><small>Quick acknowledgements</small><strong>{{ $metrics['replies_24h'] }}</strong></div>
    <div class="kv"><small>Awaiting recipient confirmation (all time)</small><strong>{{ $metrics['awaiting_receipt'] }}</strong></div>
    <div class="kv"><small>Average recent confirmation lag</small><strong>{{ $metrics['delivery_lag_seconds'] === null ? 'Not available' : $metrics['delivery_lag_seconds'].' seconds' }}</strong></div>
    <div class="kv"><small>Rejected requests (last 24h)</small><strong>{{ $metrics['rejected_24h'] === null ? 'Not available' : $metrics['rejected_24h'] }}</strong></div>
  </div>
  @else
  <div class="admin-guidance" role="status">Ping metrics will be available after the database migration has been applied.</div>
  @endif
  <p>Accepted is not delivered. Failed or rejected requests and offline retries are not counted as successful delivery. Recipient “Seen” remains subject to their read-receipt preference.</p>
  <p><a href="{{ route('orbit.admin.flags.index') }}">View feature flags</a></p>
</section>
@endsection
