@extends('orbit-admin.layout')
@section('title','Presence operations')
@section('crumb','CONFIGURATIONS / MODULE 09')
@section('content')
<div class="page-heading">
  <div><p class="eyebrow">ORBIT / MODULE 09 / NO PERSONAL ACTIVITY DATA</p>
  <h1>Presence</h1>
  <p>Privacy-first operational switches. Administrators cannot inspect individual live status, last-seen times, Circle rosters or typing indicators here.</p></div>
</div>
<section class="panel">
  <div class="panel-head"><div><p class="eyebrow">MODULE HEALTH / FEATURE GATES</p>
  <h2>Readiness</h2>
  <p>Redis configuration does not establish connectivity; the realtime gateway requires separate certification.</p></div></div>
  <div class="admin-kv">
    <div class="kv"><small>Ephemeral device lease</small><strong>{{ $lease }} seconds</strong></div>
    <div class="kv"><small>Storage target</small><strong>{{ $store }}</strong></div>
    <div class="kv"><small>M5 Circle consumer</small><strong>{{ $consumer ? 'Enabled' : 'Disabled' }}</strong></div>
    <div class="kv"><small>Realtime transport switch</small><strong>{{ $gateway ? 'Enabled (NOT health verified)' : 'Disabled' }}</strong></div>
  </div>
  <p><a href="{{ route('orbit.admin.flags.index') }}">View feature flags</a></p>
</section>
@endsection
