@extends('orbit-admin.layout')
@section('title','Check-In Operations')
@section('crumb','CONFIGURATIONS / CHECK-IN OPERATIONS')
@section('content')
<div class="page-heading">
  <div>
    <p class="eyebrow">CHECK-IN SERVICE / PRIVACY-SAFE OPERATIONS</p>
    <h1>Check-In Operations</h1>
    <p>Monitor authorized Check-In service availability, publication activity and expiry. Private updates, audience identities and precise locations are not available to administrators.</p>
  </div>
</div>
<section class="panel">
  <div class="panel-head"><div>
    <p class="eyebrow">SERVICE SETTINGS</p>
    <h2>Publishing and protection</h2>
    <p>The service activates only after its release gate, database and privacy prerequisites have been verified.</p>
  </div></div>
  <div class="admin-kv">
    <div class="kv"><small>Check-In availability</small><strong>{{ $ready && $enabled ? 'Available to authorized members' : ($ready ? 'Disabled by configuration' : 'Database setup required') }}</strong></div>
    <div class="kv"><small>Circle sharing</small><strong>{{ $circleEnabled ? 'Available with individual consent' : 'Disabled pending consent approval' }}</strong></div>
    <div class="kv"><small>Publishing rate limit</small><strong>{{ $maxPerMinute }} per minute</strong></div>
    <div class="kv"><small>Technical expiry bound</small><strong>{{ $maxExpiryDays }} days (not a default status duration)</strong></div>
  </div>
  @if($metrics)
  <div class="admin-kv">
    <div class="kv"><small>Accepted Check-Ins (24h)</small><strong>{{ $metrics['accepted_24h'] }}</strong></div>
    <div class="kv"><small>Withdrawn (24h)</small><strong>{{ $metrics['withdrawn_24h'] }}</strong></div>
    <div class="kv"><small>Currently active</small><strong>{{ $metrics['active_total'] }}</strong></div>
    <div class="kv"><small>Expired (all time)</small><strong>{{ $metrics['expired_total'] }}</strong></div>
    <div class="kv"><small>Rejected publishing requests (24h)</small><strong>{{ $metrics['rejected_24h'] === null ? 'Unavailable' : $metrics['rejected_24h'] }}</strong></div>
  </div>
  @else
  <div class="admin-guidance" role="status">Operational statistics will appear after the Check-In migration has been applied.</div>
  @endif
  <p>Accepted publication is not a delivery acknowledgement. Reader visibility is checked against current relationships, permissions and expiry on every request. Foreground HTTPS is the supported transport; background push and realtime are not provisioned.</p>
  <p><a href="{{ route('orbit.admin.flags.index') }}">View feature flags</a></p>
</section>
@endsection
