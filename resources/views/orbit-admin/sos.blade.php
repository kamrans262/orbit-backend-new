@extends('orbit-admin.layout')
@section('title','SOS & Safety Operations')
@section('crumb') SAFETY / {{ strtoupper($area) }} @endsection
@section('content')
<div class="page-heading">
  <div>
    <p class="eyebrow">SAFETY / EMERGENCY OPERATIONS</p>
    <h1>SOS &amp; Safety Management</h1>
    <p>Privacy-scoped operational visibility for incidents, responder acknowledgements and delivery retries. No precise locations, recordings, private messages or contact identities appear here.</p>
  </div>
</div>
<section class="panel">
  <div class="panel-head"><div><h2>Safety workspace</h2><p>Dedicated, role-restricted operations using the existing Orbit Administration design.</p></div></div>
  <div class="heading-actions" style="display:flex;flex-wrap:wrap;gap:8px">
    @foreach(\App\Modules\Config\Services\AdminNavigation::workspace(auth()->guard('admin')->user(), 'safety') as $link)
      <a class="btn {{ request()->routeIs(...$link['patterns'])?'primary':'secondary' }}" href="{{ route($link['route']) }}">{{ $link['title'] }}</a>
    @endforeach
  </div>
</section>
@if(!$ready)
  <section class="panel"><div class="admin-guidance">SOS schema is not yet provisioned. No administrative operations are permitted until the additive M16 migration has been reviewed and deployed.</div></section>
@else
@if($area==='overview')
  <div class="stat-grid">
    @foreach(['All incidents'=>$counts['total'],'Active SOS'=>$counts['active'],'Responder acknowledgements'=>$counts['acknowledged'],'Resolved'=>$counts['resolved'],'Cancelled'=>$counts['cancelled'],'Pending push attempts'=>$counts['pending_jobs']] as $label=>$value)
      <article class="stat-card"><span class="stat-label">{{ $label }}</span><strong>{{ number_format($value) }}</strong></article>
    @endforeach
  </div>
  <section class="panel">
    <div class="panel-head"><div><h2>Safety service health</h2><p>Do not interpret queue or provider acceptance as remote confirmation. Emergency-service dispatch is not integrated.</p></div></div>
    <div class="admin-kv">
      <div class="kv"><small>Authenticated SOS API</small><strong>{{ config('orbit_sos.enabled')?'Configured (requires migration and mobile validation)':'Not configured' }}</strong></div>
      <div class="kv"><small>HTTPS responder inbox</small><strong>Available for eligible authenticated responders</strong></div>
      <div class="kv"><small>Provider transport</small><strong>{{ app(\App\Modules\Notifications\Services\NotificationPushTransport::class)->enabled()?'Configured':'Not ready; no push delivery claim' }}</strong></div>
      <div class="kv"><small>Accepted trusted-contact relationships</small><strong>{{ number_format($counts['trusted_contacts']) }}</strong></div>
      <div class="kv"><small>Failed provider jobs</small><strong>{{ number_format($counts['failed_jobs']) }}</strong></div>
      <div class="kv"><small>Recording / external dispatch</small><strong>No automatic recording; no emergency-services integration</strong></div>
      <div class="kv"><small>Confidential data</small><strong>Coordinates encrypted and omitted from all Admin views</strong></div>
    </div>
  </section>
@elseif($area==='incidents')
  <section class="panel">
    <div class="panel-head"><div><h2>Incident Directory</h2><p>Latest 50 operational records. IDs are truncated; no names, coordinates, private Circle titles or contacts are shown.</p></div></div>
    <form method="get" action="{{ route('orbit.admin.safety.incidents') }}" class="heading-actions" style="display:flex;gap:8px;flex-wrap:wrap">
      <select name="status"><option value="">All states</option>@foreach(['active','resolved','cancelled'] as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ ucfirst($status) }}</option>@endforeach</select>
      <button class="btn secondary" type="submit">Filter incidents</button>
    </form>
    <div class="table-scroll"><table><thead><tr><th>INCIDENT</th><th>STATE</th><th>RESPONDERS</th><th>ESCALATED</th><th>RECEIVED (UTC)</th><th>CLOSED</th></tr></thead><tbody>
    @forelse($incidents as $item)
      <tr><td><code>{{ substr($item->id,0,8) }}…</code></td><td><span class="pill {{ $item->status==='active'?'danger':'neutral' }}">{{ ucfirst($item->status) }}</span></td><td>{{ $item->recipient_count }}</td><td>{{ $item->escalated_at?'Yes':'No' }}</td><td>{{ $item->created_at }}</td><td>{{ $item->closed_at??'—' }}</td></tr>
    @empty<tr><td colspan="6">No matching SOS incidents.</td></tr>@endforelse
    </tbody></table></div>
  </section>
@elseif($area==='responders')
  <section class="panel"><div class="panel-head"><div><h2>Responder status</h2><p>Aggregate active and historical response state by routing category; recipient identities are not exposed.</p></div></div>
  <div class="table-scroll"><table><thead><tr><th>SOURCE</th><th>STATE</th><th>COUNT</th></tr></thead><tbody>
  @forelse($responders as $item)<tr><td>{{ ucfirst($item->source) }}</td><td>{{ ucfirst($item->state) }}</td><td>{{ number_format($item->total) }}</td></tr>
  @empty<tr><td colspan="3">No SOS responders.</td></tr>@endforelse
  </tbody></table></div></section>
@elseif($area==='delivery')
  <section class="panel"><div class="panel-head"><div><h2>Delivery queue</h2><p>Push-provider acceptance is not a device-display, read or emergency-response receipt. In-app availability is tracked separately.</p></div></div>
  <div class="stat-grid">@foreach($states as $row)<article class="stat-card"><span class="stat-label">{{ ucfirst($row->state) }}</span><strong>{{ number_format($row->total) }}</strong></article>@endforeach</div>
  <div class="table-scroll"><table><thead><tr><th>JOB</th><th>STATE</th><th>ATTEMPTS</th><th>RETRY AT</th><th>ERROR CODE</th><th>ACTION</th></tr></thead><tbody>
  @forelse($jobs as $job)
    <tr><td><code>{{ substr($job->id,0,8) }}…</code></td><td>{{ ucfirst($job->state) }}</td><td>{{ $job->attempts }}</td><td>{{ $job->next_attempt_at??'—' }}</td><td>{{ $job->last_error_code??'—' }}</td><td>
    @if($job->state==='failed' && auth()->guard('admin')->user()?->canPerform('safety.manage'))
      <form method="post" action="{{ route('orbit.admin.safety.retry',['job'=>$job->id]) }}">@csrf
        <input name="reason" placeholder="Operational reason (10+ characters)" required minlength="10" maxlength="500" aria-label="Retry reason">
        <label><input type="checkbox" name="confirm" value="1" required> Confirm</label>
        <button class="btn secondary" type="submit">Retry after eligibility check</button>
      </form>
    @else <span class="subtle">No action</span> @endif
    </td></tr>
  @empty<tr><td colspan="6">No push delivery jobs.</td></tr>@endforelse
  </tbody></table></div></section>
  @if(auth()->guard('admin')->user()?->canPerform('safety.manage'))
    <section class="panel"><div class="panel-head"><div><h2>Bounded manual worker</h2><p>Runs one limited pass through eligibility, escalation and provider jobs. Does not contact emergency services.</p></div></div>
    <form method="post" action="{{ route('orbit.admin.safety.run') }}">@csrf
      <label>Operational reason<input name="reason" required minlength="10" maxlength="500" placeholder="Reason for manual processing"></label>
      <label><input type="checkbox" name="confirm" value="1" required> I authorize this audited operation.</label>
      <button class="btn secondary" type="submit">Process eligible jobs</button>
    </form></section>
  @endif
@elseif($area==='settings')
  <section class="panel"><div class="panel-head"><div><h2>Safety operational controls</h2><p>Changing these settings never disables emergency intake. Recording, location and privacy policies are not overridable here.</p></div></div>
  @foreach($controls as $control)
    <div class="kv"><small>{{ ucwords(str_replace('_',' ',$control->key)) }}</small><strong>{{ $control->key==='escalation_after_seconds'?$control->value.' seconds':($control->value==='1'?'Enabled':'Disabled') }}</strong></div>
    @if(auth()->guard('admin')->user()?->canPerform('safety.manage'))
      <form method="post" action="{{ route('orbit.admin.safety.settings.save') }}">@csrf
        <input name="key" type="hidden" value="{{ $control->key }}">
        @if($control->key==='escalation_after_seconds')
          <label>Delay (30–1800 seconds)<input type="number" name="value" min="30" max="1800" value="{{ $control->value }}" required></label>
        @else
          <label>Value<select name="value"><option value="1" @selected($control->value==='1')>Enabled</option><option value="0" @selected($control->value==='0')>Disabled</option></select></label>
        @endif
        <label>Reason<input name="reason" required minlength="10" maxlength="500" placeholder="Required operational reason"></label>
        <label><input type="checkbox" name="confirm" value="1" required> Confirm audited change</label>
        <button class="btn secondary" type="submit">Save control</button>
      </form>
    @endif
  @endforeach
  </section>
@elseif($area==='audits')
  <section class="panel"><div class="panel-head"><div><h2>Security &amp; operations audit</h2><p>Last 100 administrative actions. Incident identifiers are keyed hashes; event hashes support tamper detection.</p></div></div>
  <div class="table-scroll"><table><thead><tr><th>ACTION</th><th>INCIDENT HASH</th><th>REASON</th><th>EVENT HASH</th><th>CREATED (UTC)</th></tr></thead><tbody>
  @forelse($audits as $a)<tr><td>{{ $a->action }}</td><td><code>{{ $a->incident_hash?substr($a->incident_hash,0,12).'…':'—' }}</code></td><td>{{ $a->reason }}</td><td><code>{{ substr($a->event_hash,0,12) }}…</code></td><td>{{ $a->created_at }}</td></tr>
  @empty<tr><td colspan="5">No SOS administration events.</td></tr>@endforelse
  </tbody></table></div></section>
@endif
@endif
@endsection
