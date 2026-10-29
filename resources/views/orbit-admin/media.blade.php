@extends('orbit-admin.layout')
@section('title','Encrypted Media Operations')
@section('crumb','ACCOUNTS / ENCRYPTED MEDIA OPERATIONS')
@section('content')
<div class="page-heading">
  <div><p class="eyebrow">SECURE STORAGE / MEDIA OPERATIONS</p>
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
  <article class="stat-card"><span class="stat-label">Failed verification</span><strong>{{ $metrics['failed'] }}</strong><p>Encrypted objects rejected during integrity verification</p></article>
  <article class="stat-card"><span class="stat-label">Expired upload sessions</span><strong>{{ $metrics['expired_sessions'] }}</strong></article>
  <article class="stat-card"><span class="stat-label">Reserved uploads</span><strong>{{ $metrics['active_reservations'] }}</strong></article>
  <article class="stat-card"><span class="stat-label">Retrying deletions</span><strong>{{ $metrics['retried_deletions'] }}</strong></article>
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
@if(auth()->guard('admin')->user()?->canPerform('media.quotas.read'))
<section class="panel" style="margin-top:18px">
  <div class="panel-head"><div><p class="eyebrow">ELEVATED PRIVILEGE</p><h2>Account storage quota</h2>
    <p>Exact account UUID; byte totals only. Requires media.quotas.read. No content or identity is exposed.</p></div></div>
  <form class="admin-filters" method="get" action="{{ route('orbit.admin.media.index') }}">
    <label>Account UUID <input name="owner_id" type="text" maxlength="36" value="{{ request('owner_id') }}" placeholder="Exact account UUID"></label>
    <button class="btn primary" type="submit">Review quota</button>
  </form>
  @if($quota)
  <div class="admin-kv" role="status">
    <div class="kv"><small>Verified ciphertext bytes</small><strong>{{ number_format($quota['used_bytes']) }}</strong></div>
    <div class="kv"><small>Reserved upload bytes</small><strong>{{ number_format($quota['reserved_bytes']) }}</strong></div>
    <div class="kv"><small>Allowed ciphertext bytes</small><strong>{{ number_format($quota['limit_bytes']) }}</strong></div>
  </div>
  @endif
</section>
@endif
@if(auth()->guard('admin')->user()?->canPerform('media.jobs.retry'))
<section class="panel" style="margin-top:18px">
  <div class="panel-head"><div><p class="eyebrow">PRIVATE CLEANUP QUEUE</p><h2>Failed deletion jobs</h2>
    <p>Job references only. No media ID, account details, filenames, thumbnails, keys or storage paths are shown.</p></div></div>
  @forelse($failedJobs as $job)
  <div class="admin-kv" role="group" aria-label="Retryable deletion job" style="margin-bottom:12px">
    <div class="kv"><small>Job UUID</small><strong style="overflow-wrap:anywhere">{{ $job->id }}</strong></div>
    <div class="kv"><small>Attempts</small><strong>{{ $job->attempts }}</strong></div>
    <div class="kv"><small>Scheduled retry (UTC)</small><strong>{{ $job->next_attempt_at }}</strong></div>
  </div>
  @empty
  <div class="admin-guidance" role="status">No failed cleanup jobs are waiting for retry.</div>
  @endforelse
</section>
<section class="panel" style="margin-top:18px">
  <div class="panel-head"><div><p class="eyebrow">ELEVATED PRIVILEGE / AUDITED ACTION</p><h2>Retry failed storage cleanup</h2>
    <p>Only an existing pending deletion job with prior failed attempts can be rescheduled. Never bypasses holds or deletes immediately.</p></div></div>
  <form class="admin-filters" method="post" action="{{ route('orbit.admin.media.jobs.retry') }}">
    @csrf
    <label>Deletion job UUID <input name="job_id" type="text" maxlength="36" required placeholder="Exact UUID"></label>
    <label>Audit ticket <input name="ticket" type="text" minlength="10" maxlength="80" required placeholder="Ticket reference"></label>
    <label>Confirm <input name="confirm" type="text" pattern="RETRY" required placeholder="RETRY"></label>
    <button class="btn primary" type="submit">Schedule guarded retry</button>
  </form>
</section>
@endif
@endsection