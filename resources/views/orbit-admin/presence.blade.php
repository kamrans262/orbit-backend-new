@extends('orbit-admin.layout')
@section('title','Presence & Activity')
@section('crumb','CONFIGURATIONS / PRESENCE')
@section('content')
<div class="page-heading">
  <div><p class="eyebrow">PRESENCE SERVICES / PRIVATE ACTIVITY</p>
  <h1>Presence &amp; Activity</h1>
  <p>Review activity service availability. Individual online status, activity history, Circle members and typing indicators remain private.</p></div>
</div>
<section class="panel">
  <div class="panel-head"><div><p class="eyebrow">SERVICE STATUS / CONNECTIVITY</p>
  <h2>Presence service status</h2>
  <p>Real-time activity requires connected presence storage and a secure WebSocket gateway. Configuration alone does not confirm connectivity.</p></div></div>
  <div class="admin-guidance" role="status">
    Presence API: <strong>{{ $moduleEnabled ? 'Enabled' : 'Unavailable — presence storage not connected' }}</strong>.
    Instant activity updates: <strong>{{ $gateway ? 'Configured — connection verification required' : 'Not connected' }}</strong>.
    Activity visibility remains subject to member privacy settings, Circle permissions and trusted-device authorization.
  </div>
  <div class="admin-kv">
    <div class="kv"><small>Activity status expiry</small><strong>{{ $lease }} seconds</strong></div>
    <div class="kv"><small>Presence storage</small><strong>{{ $store }}</strong></div>
    <div class="kv"><small>Circle presence sharing</small><strong>{{ $consumer ? 'Enabled' : 'Disabled' }}</strong></div>
    <div class="kv"><small>Realtime connection setting</small><strong>{{ $gateway ? 'Configured — connectivity not yet verified' : 'Not connected' }}</strong></div>
  </div>
  <p>Live availability is distinct from configuration. Unverified storage or gateway connectivity cannot be presented as delivering instant activity updates.</p>
  <p><a href="{{ route('orbit.admin.flags.index') }}">View feature flags</a></p>
</section>
@endsection
