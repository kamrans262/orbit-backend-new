@extends('orbit-admin.layout')
@section('title','Location Sharing')
@section('crumb','CONFIGURATIONS / LOCATION SHARING')
@section('content')
<div class="page-heading">
  <div><p class="eyebrow">LOCATION SERVICES / PRIVACY-PROTECTED METRICS</p>
  <h1>Location Sharing &amp; Map</h1>
  <p>Review location service availability and aggregate usage. Personal coordinates, travel routes and recipient identities are never visible to administrators.</p></div>
</div>
<section class="panel">
  <div class="panel-head"><div><p class="eyebrow">SERVICE STATUS / PRIVACY CONTROLS</p>
    <h2>Location service status</h2>
    <p>Map access is available with an active service and trusted-device authorization. Location sharing separately requires the member's consent and approved audience permissions.</p></div></div>
  <div class="admin-guidance" role="status">
    Location API: <strong>{{ $enabled && $schemaInstalled ? 'Enabled' : ($enabled ? 'Enabled — database setup required' : 'Disabled') }}</strong>.
    Map access: <strong>{{ $enabled && $schemaInstalled ? 'Available to authorized devices' : 'Unavailable until setup is complete' }}</strong>.
    Live location updates: <strong>{{ $realtime ? 'Realtime configured — connection verification required' : 'Standard refresh available; realtime connection not configured' }}</strong>.
    Location access follows account privacy settings, trusted-device security and explicitly approved sharing permissions.
  </div>
  <div class="admin-kv">
    <div class="kv"><small>Database tables</small><strong>{{ $schemaInstalled ? 'Installed' : 'Not migrated' }}</strong></div>
    <div class="kv"><small>Circle location sharing</small><strong>{{ $consumer ? 'Enabled' : 'Disabled' }}</strong></div>
    <div class="kv"><small>Location update expiry</small><strong>{{ $ttl }} seconds</strong></div>
    <div class="kv"><small>Active consent records</small><strong>{{ $activeShares===null ? 'Unavailable' : $activeShares }}</strong></div>
    <div class="kv"><small>Current encrypted locations</small><strong>{{ $freshSamples===null ? 'Unavailable' : $freshSamples }}</strong></div>
  </div>
  <p>The figures above are aggregate totals. Individual access always requires current privacy permissions. No precise coordinates are shown or logged.</p>
  <p><a href="{{ route('orbit.admin.flags.index') }}">View feature flags</a></p>
</section>
@endsection
