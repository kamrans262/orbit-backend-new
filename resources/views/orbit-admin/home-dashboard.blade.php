@extends('orbit-admin.layout')
@section('title','Home Dashboard operations')
@section('crumb','CONFIGURATIONS / HOME DASHBOARD')
@section('content')
<div class="page-heading">
  <div><p class="eyebrow">HOME DASHBOARD / SECURE SUMMARY</p>
  <h1>Home Dashboard</h1>
  <p>Rollout and non-sensitive operational status. No user names, Circle rosters, media, recipient identities, location or encryption keys are available to administrators.</p></div>
</div>
<section class="panel">
  <div class="panel-head"><div><p class="eyebrow">PRIVATE SUMMARY API</p>
  <h2>Rollout status</h2>
  <p>Dashboard requests require a trusted Orbit device and current permissions for every section.</p></div></div>
  <div class="admin-guidance" role="status">
    Dashboard API: <strong>{{ config('orbit_dashboard.enabled') === true ? 'Enabled' : 'Disabled' }}</strong>.
    Available dashboard sections are shown to authorized members.
    Audience rollout rules may further limit access; this administration page remains available for service inspection.
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
  <p>Only connected providers are listed as available. Planned integrations do not simulate private data or emergency actions.</p></div></div>
  <div class="admin-kv">
    @foreach($sections as $name => $enabled)
    <div class="kv"><small>{{ $name }}</small><strong>{{ $enabled ? 'Available with permission' : 'Provider not connected' }}</strong></div>
    @endforeach
  </div>
  <p>These indicators describe configured integrations, not live performance. Additional capabilities appear when their actual service providers are connected.</p>
  <p><a href="{{ route('orbit.admin.flags.index') }}">Manage client-facing feature flags</a></p>
</section>
@endsection
