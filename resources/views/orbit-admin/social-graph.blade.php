@extends('orbit-admin.layout')
@section('title', 'Social Graph operations')
@section('crumb', 'ACCOUNTS / SOCIAL GRAPH')
@section('content')
<div class="page-heading">
  <div><p class="eyebrow">SOCIAL GRAPH / MILESTONE 04</p><h1>Friends &amp; blocking</h1>
    <p>Permission-restricted operational counters and event metadata. Private contacts, messages, locations and relationship targets are never shown.</p></div>
</div>
<div class="stat-grid">
  <article class="stat-card"><span class="stat-label">Pending friend requests</span><strong>{{ $stats['pending'] }}</strong><p>Requests awaiting recipient action</p></article>
  <article class="stat-card"><span class="stat-label">Friendship pairs</span><strong>{{ $stats['friends'] }}</strong><p>Canonical accepted relationships</p></article>
  <article class="stat-card"><span class="stat-label">Active directional blocks</span><strong>{{ $stats['blocks'] }}</strong><p>Protected social access denied</p></article>
  <article class="stat-card"><span class="stat-label">Requests last 24 hours</span><strong>{{ $stats['requests_24h'] }}</strong><p>Coarse abuse review indicator</p></article>
</div>
<section class="panel" style="margin-top:22px">
  <div class="panel-head"><div><p class="eyebrow">SUPPORT / AUTHORIZED METADATA</p><h2>Account relationship summary</h2>
    <p>Exact Orbit UUID lookup. Neither counterpart identities nor private lists are exposed in Admin.</p></div></div>
  <form class="admin-filters" method="get" action="{{ route('orbit.admin.social.index') }}">
    <label>Orbit user UUID <input name="user_id" type="text" maxlength="36" value="{{ request('user_id') }}" placeholder="Exact UUID"></label>
    <label>Activity category <select name="action"><option value="">All social events</option>
      @foreach(['social.request_sent'=>'Request sent','social.request_accepted'=>'Request accepted','social.request_declined'=>'Request declined','social.unfriended'=>'Unfriended','social.blocked'=>'Blocked','social.unblocked'=>'Unblocked'] as $code=>$title)
        <option value="{{ $code }}" @selected(request('action')===$code)>{{ $title }}</option>
      @endforeach
    </select></label>
    <button class="btn primary" type="submit">Review</button>
    <a class="btn secondary" href="{{ route('orbit.admin.social.index') }}">Reset</a>
  </form>
  @if(request('user_id'))
    <div class="admin-guidance" role="status">
      @if($account)
        <strong>{{ $account->username ?: 'Unnamed account' }}</strong> · {{ $account->status }} · exact UUID matched.
        <div class="admin-kv" style="margin-top:12px">
          <div class="kv"><small>Friendship pairs</small><strong>{{ $userCounts['friends'] }}</strong></div>
          <div class="kv"><small>Outgoing pending</small><strong>{{ $userCounts['sent_pending'] }}</strong></div>
          <div class="kv"><small>Incoming pending</small><strong>{{ $userCounts['received_pending'] }}</strong></div>
          <div class="kv"><small>Blocks made</small><strong>{{ $userCounts['blocks_made'] }}</strong></div>
        </div>
      @else <strong>No matching account.</strong> No relationship details were revealed. @endif
    </div>
  @endif
</section>
<section class="panel" style="margin-top:22px">
  <div class="panel-head"><div><p class="eyebrow">ABUSE SIGNALS / READ ONLY</p><h2>Relationship events</h2>
    <p>Audited event name and time only. This view does not permit overriding user blocks or friendships.</p></div></div>
  <div class="table-scroll"><table><thead><tr><th>TIME (UTC)</th><th>ACTION</th><th>ACTOR</th></tr></thead><tbody>
    @forelse($events as $event)
      <tr><td>{{ $event->created_at }}</td>
        <td><span class="pill neutral">{{ str_replace(['social.','_'],['',' '],$event->action) }}</span></td>
        <td>
          @if($event->user_id && auth()->guard('admin')->user()->canPerform('users.read'))
            <a href="{{ route('orbit.admin.users.show',$event->user_id) }}">{{ $event->user_id }}</a>
          @else <span class="subtle">Restricted actor</span> @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="3"><div class="empty-state"><strong>No matching social events</strong><p>Trusted-device social actions will appear after M4 is enabled.</p></div></td></tr>
    @endforelse
  </tbody></table></div>
  <div class="admin-pagination">{{ $events->links() }}</div>
</section>
<div class="admin-guidance" role="note"><strong>Implementation boundary:</strong> This is M4 operational oversight only. Reporting, moderation and appeals belong to M17. Realtime and Firebase notification delivery are not asserted here.</div>
@endsection
