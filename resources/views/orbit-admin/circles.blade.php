@extends('orbit-admin.layout')
@section('title','Circle operations')
@section('crumb','ACCOUNTS / CIRCLE MANAGEMENT')
@section('content')
<div class="page-heading">
  <div><p class="eyebrow">CIRCLE OPERATIONS / MILESTONE 05</p>
    <h1>Circle Management</h1>
    <p>Permission-restricted operational health. Circle names, participants, invitations, location, presence and content are not available in Admin.</p></div>
</div>
<div class="stat-grid">
  <article class="stat-card"><span class="stat-label">Active Circles</span><strong>{{ $metrics['active'] }}</strong><p>Private groups currently active</p></article>
  <article class="stat-card"><span class="stat-label">Archived Circles</span><strong>{{ $metrics['archived'] }}</strong><p>Archived group records</p></article>
  <article class="stat-card"><span class="stat-label">Active Memberships</span><strong>{{ $metrics['memberships'] }}</strong><p>Private aggregate; no roster access</p></article>
  <article class="stat-card"><span class="stat-label">Pending Invitations</span><strong>{{ $metrics['pending_invites'] }}</strong><p>Invitation state only</p></article>
  <article class="stat-card"><span class="stat-label">Created in 24 Hours</span><strong>{{ $metrics['created_24h'] }}</strong><p>Coarse adoption indicator</p></article>
</div>
<section class="panel">
  <div class="panel-head"><div><p class="eyebrow">AUTHORIZED OPERATIONS</p><h2>Exact Circle UUID lookup</h2>
    <p>Only limited technical metadata is shown, with no people, names, links, coordinates or sensitive capabilities.</p></div></div>
  <form class="admin-filters" method="get" action="{{ route('orbit.admin.circles.index') }}">
    <label>Circle UUID <input name="circle_id" maxlength="36" type="text" value="{{ request('circle_id') }}" placeholder="Exact UUID"></label>
    <button type="submit" class="btn primary">Review</button>
    <a class="btn secondary" href="{{ route('orbit.admin.circles.index') }}">Reset</a>
  </form>
  @if(request('circle_id'))
    <div class="admin-guidance" role="status">
      @if($summary)
        <strong>Circle record found</strong>
        <div class="admin-kv" style="margin-top:12px">
          <div class="kv"><small>State</small><strong>{{ ucfirst($summary['status']) }}</strong></div>
          <div class="kv"><small>Active members</small><strong>{{ $summary['members'] }}</strong></div>
          <div class="kv"><small>Pending invitations</small><strong>{{ $summary['pending'] }}</strong></div>
          <div class="kv"><small>Revision</small><strong>{{ $summary['revision'] }}</strong></div>
          <div class="kv"><small>Created (UTC)</small><strong>{{ $summary['created_at'] }}</strong></div>
        </div>
      @else <strong>No matching Circle record.</strong> @endif
    </div>
  @endif
</section>
<div class="admin-guidance" role="note" style="margin-top:20px">
  <strong>M5 safe-release boundary:</strong> This screen is read-only.
  Ownership transfer, Circle dissolution, emergency intervention, join-code settings and monetization rules are disabled until the applicable product and privacy policies are approved.
</div>
@endsection
