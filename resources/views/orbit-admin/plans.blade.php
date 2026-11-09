@extends('orbit-admin.layout')
@section('title','Plan Operations')
@section('crumb') PLANS / {{ strtoupper($area) }} @endsection
@section('content')
<div class="page-heading">
  <div>
    <p class="eyebrow">COMMUNITY / PRIVATE PLAN OPERATIONS</p>
    <h1>Plans Management</h1>
    <p>Monitor plan lifecycle, attendance, reminders and operational controls. Private descriptions, locations and member identities are not displayed.</p>
  </div>
</div>
<section class="panel">
  <div class="panel-head"><div><h2>Plans workspace</h2><p>Dedicated administrative controls with audit history and restricted permissions.</p></div></div>
  <div class="heading-actions" style="display:flex;flex-wrap:wrap;gap:8px">
    @foreach(['overview'=>'Overview','directory'=>'Plan Directory','attendance'=>'Invitation & Attendance','reminders'=>'Reminder Operations','settings'=>'Settings','audits'=>'Audit Trail'] as $tab=>$label)
      @if($tab!=='audits' || auth()->guard('admin')->user()?->canPerform('plans.audit'))
      <a class="btn {{ $area===$tab?'primary':'secondary' }}" href="{{ route('orbit.admin.plans.'.$tab) }}">{{ $label }}</a>
      @endif
    @endforeach
  </div>
</section>
@if(!$ready)
<section class="panel"><div class="admin-guidance">Plans schema has not been provisioned. No changes can be made until the M15 migration is reviewed and deployed.</div></section>
@else
@if($area==='overview')
<div class="stat-grid">
  @foreach(['All plans'=>$counts['total'],'Upcoming'=>$counts['upcoming'],'Cancelled'=>$counts['cancelled'],'Invitations'=>$counts['invitees'],'Queued reminders'=>$counts['queued'],'Pending notices'=>$counts['events']] as $label=>$value)
  <article class="stat-card"><span class="stat-label">{{ $label }}</span><strong>{{ number_format($value) }}</strong></article>
  @endforeach
</div>
<section class="panel">
 <div class="panel-head"><div><h2>Service health</h2><p>Plan creation and reminders run on Laravel/MariaDB. Firebase is managed separately in Notifications.</p></div></div>
 <div class="admin-kv">
  <div class="kv"><small>Plans API</small><strong>{{ config('orbit_plans.enabled')?'Configured':'Paused by server configuration' }}</strong></div>
  <div class="kv"><small>In-app notice source</small><strong>{{ config('orbit_notifications.enabled')?'M14 shared inbox':'Disabled / awaiting M14' }}</strong></div>
  <div class="kv"><small>Push transport</small><strong>Controlled by Notifications; never enabled here</strong></div>
  <div class="kv"><small>Content exposure</small><strong>Private fields omitted from administrative reporting</strong></div>
 </div>
</section>
@elseif($area==='directory')
<section class="panel">
 <div class="panel-head"><div><h2>Plan Directory</h2><p>Latest 50 records. Operational identifiers and state only; private titles, descriptions, places and participant identities are hidden.</p></div></div>
 <form method="get" action="{{ route('orbit.admin.plans.directory') }}" class="heading-actions" style="display:flex;gap:8px;flex-wrap:wrap">
  <select name="status"><option value="">All states</option>@foreach(['scheduled','cancelled'] as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ ucfirst($status) }}</option>@endforeach</select>
  <button class="btn secondary" type="submit">Filter</button>
 </form>
 <div class="table-scroll"><table><thead><tr><th>OPAQUE PLAN ID</th><th>STATE</th><th>REVISION</th><th>INVITEES</th><th>CREATED (UTC)</th></tr></thead><tbody>
 @forelse($directory as $p)
  <tr><td><code title="{{ $p->id }}">{{ substr($p->id,0,8) }}…</code></td><td><span class="pill {{ $p->status==='cancelled'?'danger':'neutral' }}">{{ $p->status }}</span></td><td>{{ $p->revision }}</td><td>{{ $p->invited_count }}</td><td>{{ $p->created_at }}</td></tr>
 @empty<tr><td colspan="5">No matching plans.</td></tr>@endforelse
 </tbody></table></div>
</section>
@if(auth()->guard('admin')->user()?->canPerform('plans.manage'))
<section class="panel">
  <div class="panel-head"><div><h2>Moderated cancellation</h2><p>Emergency operational action only. Requires the full opaque Plan UUID, reason and confirmation. Not a substitute for a user's edit flow.</p></div></div>
  <form method="post" action="{{ route('orbit.admin.plans.cancel') }}">
    @csrf
    <div class="two-col"><label>Plan UUID<input name="plan_id" type="text" placeholder="Full plan UUID" required maxlength="36" pattern="[0-9a-fA-F-]{36}"></label>
    <label>Reason<input name="reason" required minlength="10" maxlength="500" placeholder="Required operational reason"></label></div>
    <label><input type="checkbox" name="confirm" value="1" required> I confirm this audited moderation action.</label>
    <button type="submit" class="btn secondary">Cancel plan and suppress reminders</button>
  </form>
</section>
@endif
@elseif($area==='attendance')
<section class="panel"><div class="panel-head"><div><h2>Invitation &amp; Attendance</h2><p>Aggregates only. No attendee lists or private social relationships exposed.</p></div></div>
<div class="table-scroll"><table><thead><tr><th>RESPONSE</th><th>COUNT</th></tr></thead><tbody>
@forelse($attendance as $row)<tr><td>{{ ucfirst($row->state) }}</td><td>{{ number_format($row->total) }}</td></tr>
@empty<tr><td colspan="2">No invitations recorded.</td></tr>@endforelse
</tbody></table></div></section>
@elseif($area==='reminders')
<section class="panel"><div class="panel-head"><div><h2>Reminder queue</h2><p>Latest 40 jobs; current delivery eligibility is rechecked during dispatch.</p></div></div>
<div class="stat-grid">@foreach($reminderStates as $item)<article class="stat-card"><span class="stat-label">{{ ucfirst($item->state) }}</span><strong>{{ number_format($item->total) }}</strong></article>@endforeach</div>
<div class="table-scroll"><table><thead><tr><th>JOB</th><th>STATE</th><th>ATTEMPTS</th><th>DUE (UTC)</th><th>ERROR</th><th>ACTION</th></tr></thead><tbody>
@forelse($queue as $job)
<tr><td><code>{{ substr($job->id,0,8) }}…</code></td><td>{{ $job->state }}</td><td>{{ $job->attempts }}</td><td>{{ $job->due_at }}</td><td>{{ $job->error_code??'—' }}</td><td>
@if($job->state==='failed' && auth()->guard('admin')->user()?->canPerform('plans.manage'))
<form method="post" action="{{ route('orbit.admin.plans.retry',['job'=>$job->id]) }}">@csrf
<input name="reason" required minlength="10" maxlength="500" placeholder="Retry reason" aria-label="Retry reason">
<label><input name="confirm" type="checkbox" value="1" required> Confirm</label>
<button class="btn secondary" type="submit">Retry</button></form>
@else<span class="subtle">No action</span>@endif
</td></tr>
@empty<tr><td colspan="6">No reminder jobs.</td></tr>@endforelse
</tbody></table></div></section>
@if(auth()->guard('admin')->user()?->canPerform('plans.manage'))
<section class="panel"><div class="panel-head"><div><h2>Bounded manual processing</h2><p>Process up to 20 eligible events/reminders. The global scheduler must be configured separately.</p></div></div>
<form method="post" action="{{ route('orbit.admin.plans.process') }}">@csrf
<label>Reason<input name="reason" required minlength="10" maxlength="500" placeholder="Reason for manual processing"></label>
<label><input type="checkbox" name="confirm" value="1" required> Confirm</label>
<button type="submit" class="btn secondary">Process eligible jobs</button></form></section>
@endif
@elseif($area==='settings')
<section class="panel"><div class="panel-head"><div><h2>Plans controls</h2><p>Runtime controls apply without code changes. Disabling reminders never enables Firebase or another channel.</p></div></div>
@foreach($controls as $control)
<div class="kv"><small>{{ ucwords(str_replace('_',' ',$control->key)) }}</small><strong>{{ $control->enabled?'Enabled':'Disabled' }}</strong></div>
@if(auth()->guard('admin')->user()?->canPerform('plans.manage'))
<form method="post" action="{{ route('orbit.admin.plans.settings.save') }}">@csrf
<input type="hidden" name="key" value="{{ $control->key }}">
<select name="enabled" aria-label="Set {{ $control->key }}"><option value="1" @selected($control->enabled)>Enabled</option><option value="0" @selected(!$control->enabled)>Disabled</option></select>
<input name="reason" required minlength="10" maxlength="500" placeholder="Reason for changing this control">
<label><input type="checkbox" name="confirm" value="1" required> Confirm audited change</label>
<button class="btn secondary" type="submit">Save control</button>
</form>
@endif
@endforeach
</section>
@elseif($area==='audits')
<section class="panel"><div class="panel-head"><div><h2>Operational audit trail</h2><p>Last 100 admin actions. Plan UUIDs are stored as hashes; no private plan contents are logged.</p></div></div>
<div class="table-scroll"><table><thead><tr><th>ACTION</th><th>HASH PREFIX</th><th>REASON</th><th>TIME</th></tr></thead><tbody>
@forelse($audits as $audit)
<tr><td>{{ $audit->action }}</td><td><code>{{ $audit->plan_hash?substr($audit->plan_hash,0,12).'…':'—' }}</code></td><td>{{ $audit->reason }}</td><td>{{ $audit->created_at }}</td></tr>
@empty<tr><td colspan="4">No administrative changes.</td></tr>@endforelse
</tbody></table></div></section>
@endif
@endif
@endsection
