@extends('orbit-admin.layout')
@section('title','Activity Feed Operations')
@section('crumb') ACTIVITY FEED / {{ strtoupper($area) }} @endsection
@section('content')
<div class="page-heading">
  <div>
    <p class="eyebrow">SOCIAL / ACTIVITY FEED</p>
    <h1>Activity Feed Management</h1>
    <p>Private activity indexing, source availability, synchronization health and restricted operational controls. No user activity bodies or audience lists are accessible here.</p>
  </div>
</div>
<section class="panel">
  <div class="panel-head"><div>
    <h2>Activity workspace</h2>
    <p>Only existing Orbit Admin styling and navigation are used. This workspace is separate from Configurations and Notifications.</p>
  </div></div>
  <div class="heading-actions" style="display:flex;flex-wrap:wrap;gap:8px">
    @foreach(\App\Modules\Config\Services\AdminNavigation::workspace(auth()->guard('admin')->user(), 'activity') as $link)
      <a class="btn {{ request()->routeIs(...$link['patterns'])?'primary':'secondary' }}" href="{{ route($link['route']) }}">{{ $link['title'] }}</a>
    @endforeach
  </div>
</section>
@if(!$ready)
  <section class="panel"><div class="admin-guidance">The Activity Feed migration has not run in this environment. Activity is unavailable. No projected events are simulated.</div></section>
@else
@if($area==='overview')
  <div class="stat-grid">
  @foreach(['Indexed activity pointers'=>$stats['events'],'Moment pointers'=>$stats['moments'],
    'Check-In pointers'=>$stats['check_ins'],'Invalidated pointers'=>$stats['invalidated'],
    'Completed projector runs'=>$stats['completed_runs']] as $label=>$value)
    <article class="stat-card"><span class="stat-label">{{ $label }}</span><strong>{{ number_format($value) }}</strong>
    <p>Aggregate operational metadata only</p></article>
  @endforeach
  </div>
  <section class="panel">
    <div class="panel-head"><div><h2>Privacy &amp; delivery posture</h2></div></div>
    <div class="admin-kv">
      <div class="kv"><small>Activity feed</small><strong>{{ config('orbit_activity.enabled')?'Configured':'Disabled' }}</strong></div>
      <div class="kv"><small>Current sync policy</small><strong>Authorized foreground refresh</strong></div>
      <div class="kv"><small>Live WebSocket delivery</small><strong>Not provisioned</strong></div>
      <div class="kv"><small>Eligibility</small><strong>Original source policy checked at every read</strong></div>
      <div class="kv"><small>Excluded</small><strong>Messages, SOS, location, moderation, security and sponsorships</strong></div>
    </div>
  </section>
@endif
@if($area==='sources')
  <section class="panel">
    <div class="panel-head"><div><h2>Allowed source adapters</h2>
      <p>Changes are effective at the next request. Disabling an adapter also hides its already indexed pointers. No new source category may be enabled here.</p></div></div>
    <div class="table-scroll"><table>
      <thead><tr><th>SOURCE</th><th>STATUS</th><th>CONTROL</th></tr></thead><tbody>
      @foreach($controls as $control)
        <tr>
          <td>{{ $control->key==='source.moment'?'Moments':'Check-In' }}</td>
          <td>{{ $control->enabled?'Enabled':'Disabled' }}</td>
          <td>
            @if(auth()->guard('admin')->user()?->canPerform('activity.manage'))
            <form method="post" action="{{ route('orbit.admin.activity.control') }}">
              @csrf
              <input type="hidden" name="key" value="{{ $control->key }}">
              <input type="hidden" name="enabled" value="{{ $control->enabled?'0':'1' }}">
              <input type="text" name="reason" minlength="10" maxlength="500" required placeholder="Operational reason" aria-label="Reason for source change">
              <label><input type="checkbox" name="confirm" value="1" required> Confirm</label>
              <button type="submit" class="btn secondary">{{ $control->enabled?'Disable':'Enable' }}</button>
            </form>
            @else
              <span>Read-only</span>
            @endif
          </td>
        </tr>
      @endforeach
      </tbody></table></div>
  </section>
@endif
@if($area==='operations')
  <section class="panel">
    <div class="panel-head"><div><h2>Bounded source projection</h2>
      <p>Projects source references without copying private content. This is not an event delivery or read receipt.</p></div></div>
    @if(auth()->guard('admin')->user()?->canPerform('activity.manage'))
    <form method="post" action="{{ route('orbit.admin.activity.project') }}">
      @csrf
      <input type="text" name="reason" minlength="10" maxlength="500" required placeholder="Reason for manual projection" aria-label="Reason for manual projection">
      <label><input type="checkbox" name="confirm" value="1" required> Confirm bounded projection</label>
      <button class="btn primary" type="submit">Run Projection</button>
    </form>
    @endif
    <div class="table-scroll"><table>
      <thead><tr><th>WHEN</th><th>STATUS</th><th>MOMENTS</th><th>CHECK-INS</th><th>INVALIDATED</th></tr></thead>
      <tbody>
      @forelse($runs as $run)
      <tr><td>{{ $run->created_at }}</td><td>{{ $run->status }}</td>
      <td>{{ $run->moments_added }}</td><td>{{ $run->check_ins_added }}</td><td>{{ $run->invalidated }}</td></tr>
      @empty<tr><td colspan="5">No source projections yet.</td></tr>@endforelse
      </tbody></table></div>{{ $runs->links() }}
  </section>
@endif
@if($area==='audits' && auth()->guard('admin')->user()?->canPerform('activity.audit'))
  <section class="panel">
    <div class="panel-head"><div><h2>Administrative audit trail</h2><p>Access restricted to authorized Activity auditors.</p></div></div>
    <div class="table-scroll"><table><thead><tr><th>WHEN</th><th>ACTOR</th><th>ACTION</th><th>SETTING</th><th>FROM</th><th>TO</th><th>REASON</th></tr></thead>
    <tbody>@forelse($audits as $record)
      <tr><td>{{ $record->created_at }}</td><td>{{ $record->admin_id }}</td><td>{{ $record->action }}</td><td>{{ $record->subject_key }}</td><td>{{ $record->old_value??'—' }}</td><td>{{ $record->new_value??'—' }}</td><td>{{ $record->reason }}</td></tr>
    @empty<tr><td colspan="7">No Activity administration changes recorded.</td></tr>@endforelse
    </tbody></table></div>{{ $audits->links() }}
  </section>
@endif
@endif
@endsection
