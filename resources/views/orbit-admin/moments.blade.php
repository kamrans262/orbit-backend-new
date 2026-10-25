@extends('orbit-admin.layout')
@section('title','Moment operations')
@section('crumb','ACCOUNTS / MOMENT MANAGEMENT')
@section('content')
<div class="page-heading">
 <div><p class="eyebrow">PRIVATE MOMENT OPERATIONS / MILESTONE 07</p>
 <h1>Moment Management</h1>
 <p>Permission-restricted lifecycle and report review. Private photos, videos, captions, comments, keys and recipient lists are never shown here.</p></div>
</div>
<div class="stat-grid">
 <article class="stat-card"><span class="stat-label">Published Moments</span><strong>{{ $metrics['published'] }}</strong></article>
 <article class="stat-card"><span class="stat-label">Expired Moments</span><strong>{{ $metrics['expired'] }}</strong></article>
 <article class="stat-card"><span class="stat-label">Restricted Moments</span><strong>{{ $metrics['restricted'] }}</strong></article>
 <article class="stat-card"><span class="stat-label">Deleted Moments</span><strong>{{ $metrics['deleted'] }}</strong></article>
 <article class="stat-card"><span class="stat-label">Created last 24 Hours</span><strong>{{ $metrics['created_24h'] }}</strong></article>
 <article class="stat-card"><span class="stat-label">Pending Reports</span><strong>{{ $metrics['pending_reports'] }}</strong></article>
 <article class="stat-card"><span class="stat-label">Pending Retention Jobs</span><strong>{{ $metrics['queued_cleanup'] }}</strong></article>
 <article class="stat-card"><span class="stat-label">Lifecycle Retry Errors</span><strong>{{ $metrics['lifecycle_failures'] }}</strong></article>
 <article class="stat-card"><span class="stat-label">Transport Events Awaiting Delivery</span><strong>{{ $metrics['undelivered_transport_events'] }}</strong></article>
</div>
<section class="panel" style="margin-top:18px">
 <div class="panel-head"><div><p class="eyebrow">RETENTION HEALTH / METADATA ONLY</p>
 <h2>Lifecycle processing errors</h2>
 <p>Retry counts and machine-readable failure codes only. No content, recipient identity, media filenames or keys.</p></div></div>
 @forelse($failures as $failure)
 <div class="admin-kv" role="group" style="margin-bottom:12px" aria-label="Moment processing failure">
  <div class="kv"><small>Job type</small><strong>{{ $failure->job_type }}</strong></div>
  <div class="kv"><small>Attempts</small><strong>{{ $failure->attempts }}</strong></div>
  <div class="kv"><small>Error code</small><strong>{{ $failure->last_error_code ?? 'Not classified' }}</strong></div>
  <div class="kv"><small>Last attempted UTC</small><strong>{{ $failure->updated_at }}</strong></div>
 </div>
 @empty<div class="admin-guidance" role="status">No outstanding lifecycle retry errors.</div>
 @endforelse
</section>
<section class="panel">
 <div class="panel-head"><div><p class="eyebrow">AUTHORIZED TECHNICAL LOOKUP</p><h2>Exact Moment UUID</h2>
 <p>Technical state and UTC deadlines only. No content, recipients, attachments, user identities or access URLs.</p></div></div>
 <form class="admin-filters" action="{{ route('orbit.admin.moments.index') }}" method="get">
  <label>Moment UUID <input type="text" name="moment_id" maxlength="36" value="{{ request('moment_id') }}" placeholder="Exact UUID"></label>
  <button class="btn primary" type="submit">Review</button><a class="btn secondary" href="{{ route('orbit.admin.moments.index') }}">Reset</a>
 </form>
 @if(request('moment_id'))<div class="admin-guidance" role="status">
 @if($moment)<strong>Moment record found</strong><div class="admin-kv" style="margin-top:12px">
  <div class="kv"><small>State</small><strong>{{ ucfirst($moment->state) }}</strong></div>
  <div class="kv"><small>Audience kind</small><strong>{{ $moment->audience_kind }}</strong></div>
  <div class="kv"><small>Audience revision</small><strong>{{ $moment->audience_revision }}</strong></div>
  <div class="kv"><small>Published UTC</small><strong>{{ $moment->published_at }}</strong></div>
  <div class="kv"><small>Rail expiry UTC</small><strong>{{ $moment->rail_expires_at }}</strong></div>
  <div class="kv"><small>View expiry UTC</small><strong>{{ $moment->view_expires_at }}</strong></div>
  <div class="kv"><small>Purge deadline UTC</small><strong>{{ $moment->purge_due_at }}</strong></div>
 </div>@else<strong>No matching record.</strong>@endif
 </div>@endif
</section>
<section class="panel" style="margin-top:18px">
 <div class="panel-head"><div><p class="eyebrow">RESTRICTED MODERATION</p><h2>Pending reports</h2>
 <p>Only metadata required for decision-making. Reports do not grant the operator any media decryption keys or the contents of user submissions.</p></div></div>
 @forelse($reports as $report)
 <div class="admin-kv" style="margin-bottom:12px" role="group" aria-label="Pending Moment report">
 <div class="kv"><small>Report UUID</small><strong style="overflow-wrap:anywhere">{{ $report->id }}</strong></div>
 <div class="kv"><small>Reason</small><strong>{{ $report->reason }}</strong></div>
 <div class="kv"><small>Received UTC</small><strong>{{ $report->created_at }}</strong></div>
 </div>
 @if(config('orbit_moments.moderation_evidence_enabled') && $report->evidence_consent && $report->consent_evidence_ciphertext
     && auth()->guard('admin')->user()?->canPerform('moments.moderate'))
 <form class="admin-filters" action="{{ route('orbit.admin.moments.reports.evidence',['report'=>$report->id]) }}"
   method="post" target="_blank" style="margin-bottom:12px">
  @csrf
  <label>Evidence review ticket <input name="ticket" type="text" required minlength="10" maxlength="80"
    placeholder="Authorized investigation ticket"></label>
  <button class="btn secondary" type="submit">Review consented evidence</button>
 </form>
 @endif
 @if(auth()->guard('admin')->user()?->canPerform('moments.moderate'))
 <form class="admin-filters" action="{{ route('orbit.admin.moments.reports.action',['report'=>$report->id]) }}" method="post" style="margin-bottom:20px">
 @csrf
 <label>Action <select name="action" required><option value="dismiss">Dismiss report</option><option value="restrict">Restrict Moment</option></select></label>
 <label>Reason code <select name="reason_code" required><option value="reviewed_safe">Reviewed safe</option><option value="privacy_violation">Privacy violation</option><option value="abusive_content">Abusive content</option><option value="spam">Spam</option><option value="other">Other</option></select></label>
 <label>Audit ticket <input name="ticket" type="text" minlength="10" maxlength="80" required placeholder="Review ticket"></label>
 <label>Confirm <input name="confirm" type="text" pattern="REVIEWED" required placeholder="REVIEWED"></label>
 <button class="btn primary" type="submit">Record reviewed action</button>
 </form>
 @endif
 @empty<div class="admin-guidance" role="status">No pending reports.</div>
 @endforelse
</section>
<div class="admin-guidance" role="note" style="margin-top:20px">
 <strong>Security boundary:</strong> E2EE content cannot be decrypted by administrators. A report reason alone is not proof of the content.
 Any restriction is reversible only through a separate audited approval process, which is not enabled here.
</div>
@endsection
