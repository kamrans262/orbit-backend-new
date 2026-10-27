@extends('orbit-admin.layout')
@section('title','Location operations')
@section('crumb','CONFIGURATIONS / MODULE 10')
@section('content')
<div class="page-heading">
  <div><p class="eyebrow">ORBIT / MODULE 10 / NO PERSONAL GPS DATA</p>
  <h1>Location &amp; Orbits Map</h1>
  <p>Operational readiness only. No person-level coordinates, paths, recipients, location history or map views are provided to administrators.</p></div>
</div>
<section class="panel">
  <div class="panel-head"><div><p class="eyebrow">MODULE HEALTH / PRIVACY GATES</p>
    <h2>Readiness</h2>
    <p>Configuration switches are not proof of a provisioned live GPS transport, verified OS permissions or mobile acceptance.</p></div></div>
  <div class="admin-guidance" role="status">
    M10 location API: <strong>{{ $enabled ? 'Enabled in configuration (NOT released)' : 'Not activated' }}</strong>.
    M10 GPS realtime: <strong>Not provisioned or certified</strong>.
    API release requires current M5 consent, M3 global privacy, trusted device and verified runtime infrastructure.
  </div>
  <div class="admin-kv">
    <div class="kv"><small>Database tables</small><strong>{{ $schemaInstalled ? 'Installed' : 'Not migrated' }}</strong></div>
    <div class="kv"><small>M5 Location consumer</small><strong>{{ $consumer ? 'Enabled in configuration' : 'Disabled' }}</strong></div>
    <div class="kv"><small>Latest sample TTL</small><strong>{{ $ttl }} seconds</strong></div>
    <div class="kv"><small>Active consent records</small><strong>{{ $activeShares===null ? 'Unavailable' : $activeShares }}</strong></div>
    <div class="kv"><small>Unexpired encrypted latest records</small><strong>{{ $freshSamples===null ? 'Unavailable' : $freshSamples }}</strong></div>
  </div>
  <p>Counts are aggregate operational diagnostics and do not certify that location is available to anyone. No sensitive coordinates are shown or logged.</p>
  <p><a href="{{ route('orbit.admin.flags.index') }}">View feature flags</a></p>
</section>
@endsection
