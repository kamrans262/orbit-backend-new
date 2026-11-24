@extends('orbit-admin.layout')
@section('title','Orbit Relay')
@section('crumb') ORBIT RELAY / {{ strtoupper($area) }} @endsection
@section('content')
<div class="page-heading"><div><p class="eyebrow">OFFLINE COMMUNICATIONS</p><h1>Orbit Relay</h1><p>Manage Relay availability, authorization limits and privacy-safe operations.</p></div></div>
<section class="panel">
<div class="heading-actions">
@foreach(\App\Modules\Config\Services\AdminNavigation::workspace(auth()->guard('admin')->user(),'relay') as $link)
<a class="btn {{ request()->routeIs(...$link['patterns'])?'primary':'secondary' }}" href="{{ route($link['route']) }}">{{ $link['title'] }}</a>
@endforeach
</div>
</section>
@if(!$ready)
<section class="panel"><div class="admin-guidance">Relay database is not provisioned.</div></section>
@elseif($area==='overview')
<div class="stat-grid">@foreach($counts as $label=>$value)
<article class="stat-card"><span class="stat-label">{{ $label }}</span><strong>{{ number_format($value) }}</strong></article>
@endforeach</div>
<section class="panel"><div class="panel-head"><div><h2>Operational availability</h2><p>{{ $capabilities['enabled']?'Available':'Disabled' }} — {{ str_replace('_',' ',$capabilities['readiness']) }}</p></div></div>
<div class="admin-guidance">Nearby transfer availability depends on independent platform review. A receipt is not a read acknowledgement or an emergency delivery guarantee.</div>
<div class="admin-kv"><div class="kv"><small>Latest recorded reconciliation</small><strong>{{ $lastSync ?? 'No reconciliation recorded' }}</strong></div></div>
<div class="table-scroll"><table><thead><tr><th>DAY</th><th>OUTCOME</th><th>COUNT</th></tr></thead><tbody>
@forelse($metrics as $metric)<tr><td>{{ $metric->day }}</td><td>{{ $metric->outcome }}</td><td>{{ $metric->total }}</td></tr>
@empty<tr><td colspan="3">No aggregate outcomes recorded.</td></tr>@endforelse
</tbody></table></div></section>
@elseif($area==='settings')
<section class="panel"><div class="panel-head"><div><h2>Settings &amp; Controls</h2><p>Independent security and source-adapter gates cannot be changed here.</p></div></div>
@if(auth()->guard('admin')->user()?->canPerform('relay.manage'))
<form method="post" action="{{ route('orbit.admin.relay.settings.save') }}">@csrf
<input type="hidden" name="revision" value="{{ $settings['revision'] }}">
<label>Operational rollout<select name="enabled"><option value="0" @selected(!$settings['enabled'])>Disabled</option><option value="1" @selected($settings['enabled'])>Enabled when independently approved</option></select></label>
<label>Lease duration (seconds)<input type="number" name="lease_seconds" min="60" max="900" required value="{{ $settings['lease_seconds'] }}"></label>
<label>Reconciliation batch limit<input type="number" name="batch_limit" min="1" max="20" required value="{{ $settings['batch_limit'] }}"></label>
<label>Frame limit (bytes)<input type="number" name="frame_bytes" min="1024" max="32768" required value="{{ $settings['frame_bytes'] }}"></label>
<label>Reason<input name="reason" minlength="10" maxlength="500" required></label>
<label><input type="checkbox" name="confirm" value="1" required> Confirm policy change</label>
<button type="submit" class="btn primary">Save Controls</button></form>
@else
<div class="admin-kv">@foreach($settings as $key=>$value)<div class="kv"><small>{{ str_replace('_',' ',$key) }}</small><strong>{{ is_bool($value)?($value?'Enabled':'Disabled'):$value }}</strong></div>@endforeach</div>
@endif
</section>
@elseif($area==='audits')
<section class="panel"><div class="panel-head"><div><h2>Audit Trail</h2><p>Administrative configuration revisions only. No private messages, keys or encounters.</p></div></div>
<div class="table-scroll"><table><thead><tr><th>REVISION</th><th>ACTOR</th><th>REASON</th><th>DATE</th></tr></thead><tbody>
@forelse($audits as $audit)<tr><td>{{ $audit->version }}</td><td>{{ $audit->actor_id }}</td><td>{{ $audit->reason }}</td><td>{{ $audit->created_at }}</td></tr>
@empty<tr><td colspan="4">No administrative changes recorded.</td></tr>@endforelse
</tbody></table></div>{{ $audits->links() }}</section>
@endif
@endsection
