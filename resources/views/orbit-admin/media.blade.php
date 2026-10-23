@extends('orbit-admin.layout')
@section('title','Encrypted Media Operations')
@section('crumb','ACCOUNTS / ENCRYPTED MEDIA OPERATIONS')
@section('content')
<div class="page-heading">
  <div><p class="eyebrow">STORAGE OPERATIONS / MILESTONE 06</p>
    <h1>Encrypted Media Operations</h1>
    <p>Encrypted-object lifecycle and health only. No owner identity, thumbnails, media contents, key envelopes or recipient lists are accessible here.</p>
  </div>
</div>
<div class="stat-grid">
  <article class="stat-card"><span class="stat-label">Total media objects</span><strong>{{ $metrics['objects'] }}</strong></article>
  <article class="stat-card"><span class="stat-label">Ready ciphertext</span><strong>{{ $metrics['ready'] }}</strong></article>
  <article class="stat-card"><span class="stat-label">Awaiting verification</span><strong>{{ $metrics['verifying'] }}</strong></article>
  <article class="stat-card"><span class="stat-label">Deleting</span><strong>{{ $metrics['deleting'] }}</strong></article>
  <article class="stat-card"><span class="stat-label">Stored ciphertext MiB</span><strong>{{ number_format($metrics['encrypted_bytes']/1048576,1) }}</strong></article>
  <article class="stat-card"><span class="stat-label">Deletion queue</span><strong>{{ $metrics['queued_purges'] }}</strong></article>
  <article class="stat-card"><span class="stat-label">Temporary legal holds</span><strong>{{ $metrics['held'] }}</strong></article>
</div>
<section class="panel">
  <div class="panel-head"><div><p class="eyebrow">AUTHORIZED TECHNICAL LOOKUP</p><h2>Exact media UUID</h2>
    <p>Returns state and coarse metadata only. Never exposes encryption keys, object paths, signed URLs or private content.</p></div></div>
  <form class="admin-filters" method="get" action="{{ route('orbit.admin.media.index') }}">
    <label>Media UUID <input name="media_id" maxlength="36" value="{{ request('media_id') }}" placeholder="Exact UUID"></label>
    <button class="btn primary" type="submit">Review</button>
    <a class="btn secondary" href="{{ route('orbit.admin.media.index') }}">Reset</a>
  </form>
  @if(request('media_id'))
    <div class="admin-guidance" role="status">
      @if($row)
        <strong>Encrypted media record found</strong>
        <div class="admin-kv" style="margin-top:12px">
          <div class="kv"><small>State</small><strong>{{ ucfirst($row->state) }}</strong></div>
          <div class="kv"><small>Purpose / kind</small><strong>{{ $row->purpose }} / {{ $row->kind }}</strong></div>
          <div class="kv"><small>Encrypted bytes</small><strong>{{ number_format($row->cipher_bytes) }}</strong></div>
          <div class="kv"><small>Policy revision</small><strong>{{ $row->policy_version }}</strong></div>
          <div class="kv"><small>Created (UTC)</small><strong>{{ $row->created_at }}</strong></div>
          <div class="kv"><small>Retention deadline (UTC)</small><strong>{{ $row->retention_deadline ?? 'Not scheduled' }}</strong></div>
        </div>
      @else <strong>No matching record.</strong> @endif
    </div>
  @endif
</section>
<div class="admin-guidance" role="note" style="margin-top:20px">
  <strong>Safety boundary:</strong> Admin is read-only. No download, deletion, re-share, keys, quarantine overrides or approval of legal holds through this screen.
  Production bucket credentials and schedule monitoring require an independently reviewed release configuration.
</div>
@endsection