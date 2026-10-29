@extends('orbit-admin.layout')
@section('title','Location Sharing')
@section('crumb','CONFIGURATIONS / LOCATION SHARING')
@section('content')
<div class="page-heading">
  <div>
    <p class="eyebrow">LOCATION SERVICES / PRIVACY-PROTECTED METRICS</p>
    <h1>Location Sharing &amp; Map</h1>
    <p>Aggregate sharing activity only. Personal coordinates, routes and recipient identities remain private.</p>
  </div>
</div>
<section class="panel">
  <div class="panel-head"><div>
    <p class="eyebrow">SHARING ACTIVITY</p>
    <h2>Location activity</h2>
    <p>Only consented, unexpired sharing sessions and encrypted location records are counted.</p>
  </div></div>
  <div class="admin-kv">
    <div class="kv"><small>Active sharing sessions</small><strong>{{ $activeShares===null ? 'Unavailable' : $activeShares }}</strong></div>
    <div class="kv"><small>Current encrypted locations</small><strong>{{ $freshSamples===null ? 'Unavailable' : $freshSamples }}</strong></div>
    <div class="kv"><small>Location update expiry</small><strong>{{ $ttl }} seconds</strong></div>
  </div>
  @if(!$schemaInstalled)
  <div class="admin-guidance" role="status">Location activity is unavailable until the required database setup is installed.</div>
  @elseif($activeShares === 0)
  <div class="admin-guidance" role="note">No active sharing sessions. Members must explicitly enable location sharing and approve an audience before anyone can receive their location.</div>
  @elseif($freshSamples === 0)
  <div class="admin-guidance" role="note">Sharing is authorized, but no unexpired location update is available. Device permission and foreground use are required.</div>
  @endif
</section>
@endsection
