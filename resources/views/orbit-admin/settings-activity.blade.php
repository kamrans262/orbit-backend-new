@extends('orbit-admin.layout')
@section('title', 'Settings change activity')
@section('crumb', 'ACCOUNTS / SETTINGS AUDIT')
@section('content')
<div class="page-heading"><div><p class="eyebrow">ACCOUNT SETTINGS / ACTIVITY AUDIT</p><h1>Profile &amp; settings activity</h1><p>Field-only event history for authorized auditors. No private settings values, bios, contacts or coordinates are recorded here.</p></div></div>
<section class="panel">
 <div class="panel-head"><div><h2>Account settings changes</h2><p>Search uses an exact internal user UUID; events are retained according to security policy.</p></div></div>
 <form class="admin-filters" method="get" action="{{ route('orbit.admin.settings.activity') }}">
  <label>User UUID <input name="user_id" type="text" maxlength="36" value="{{ request('user_id') }}" placeholder="Exact account UUID"></label>
  <label>Event category <select name="action"><option value="">All changes</option>
   @foreach($actions as $action)<option value="{{ $action }}" @selected(request('action') === $action)>{{ str_replace(['settings.','_'],['',' '],$action) }}</option>@endforeach
  </select></label>
  <button class="btn primary" type="submit">Search audit</button>
  <a class="btn secondary" href="{{ route('orbit.admin.settings.activity') }}">Reset</a>
 </form>
 <div class="table-scroll"><table><thead><tr><th>TIME (UTC)</th><th>EVENT</th><th>CHANGED FIELD</th><th>ACCOUNT</th></tr></thead><tbody>
 @forelse($events as $event)
 <tr>
  <td>{{ $event->created_at }}</td>
  <td><span class="pill neutral">{{ str_replace('settings.','',$event->action) }}</span></td>
  <td><strong>{{ $event->reason_code ?: 'Not specified' }}</strong><small>Field name only — value never shown</small></td>
  <td>
   @if($event->user_id && auth()->guard('admin')->user()->canPerform('users.read'))
    <a href="{{ route('orbit.admin.users.show', $event->user_id) }}">{{ $event->user_id }}</a>
   @else{{ $event->user_id ?: 'Account unavailable' }}@endif
  </td>
 </tr>
 @empty<tr><td colspan="4"><div class="empty-state"><strong>No matching settings events</strong><p>Profile and settings activity appears after successful, authenticated updates.</p></div></td></tr>@endforelse
 </tbody></table></div>
 <div class="admin-pagination">{{ $events->links() }}</div>
</section>
@endsection
