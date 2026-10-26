@extends('orbit-admin.layout')
@section('title','Home Dashboard operations')
@section('crumb','CONFIGURATIONS / MODULE 08')
@section('content')
<div class="page-heading">
  <div><p class="eyebrow">ORBIT / MODULE 08 / AUTHORIZED METADATA ONLY</p>
  <h1>Home Dashboard</h1>
  <p>Rollout and non-sensitive operational status. No user names, Circle rosters, media, recipient identities, location or encryption keys are available to administrators.</p></div>
</div>
<section class="panel">
  <div class="panel-head"><div><p class="eyebrow">PRIVATE SUMMARY API</p>
  <h2>Rollout status</h2>
  <p>All M8 requests require a trusted Orbit device and current per-resource authorization.</p></div></div>
  <div class="admin-guidance" role="status">
    M8 API runtime: <strong>{{ config('orbit_dashboard.enabled') === true ? 'Enabled' : 'Not activated' }}</strong>.
    This admin page remains available for inspection independently of the mobile feature switch.
    A separately configured m8.dashboard audience flag may further restrict or disable rollout.
  </div>
  <div class="stat-grid">
    @foreach($limits as $label => $max)
    <article class="stat-card"><span class="stat-label">{{ $label }} per Home snapshot</span><strong>{{ $max }}</strong></article>
    @endforeach
  </div>
</section>
<section class="panel" style="margin-top:18px">
  <div class="panel-head"><div><p class="eyebrow">CAPABILITY READINESS</p>
  <h2>Section switches</h2>
  <p>Future services are explicitly unavailable; M8 never simulates their data or dispatches emergency actions.</p></div></div>
  <div class="admin-kv">
    @foreach($sections as $name => $enabled)
    <div class="kv"><small>{{ $name }}</small><strong>{{ $enabled ? 'Source gated / available' : 'Disabled or not yet implemented' }}</strong></div>
    @endforeach
  </div>
  <p>These are configuration indicators, not live endpoint checks. Future capabilities remain unavailable without their approved service providers.</p>
  <p><a href="{{ route('orbit.admin.flags.index') }}">Manage client-facing feature flags</a></p>
</section>
@endsection
