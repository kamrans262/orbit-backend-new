@extends('orbit-admin.layout')
@section('title','User identities')
@section('crumb','USERS / IDENTITIES')
@section('content')
<div class="page-heading"><div><p class="eyebrow">IDENTITY / MODULE 02</p><h1>Users &amp; devices</h1><p>Minimal account metadata for authorized support. No OTP codes, private messages or precise locations.</p></div></div>
<div class="stat-grid">
<article class="stat-card"><span class="stat-label">Active accounts</span><strong>{{ $activeUsers }}</strong><p>Accounts with active status</p></article>
<article class="stat-card"><span class="stat-label">Pending device approvals</span><strong>{{ $pendingDevices }}</strong><p>New device trust requests</p></article>
<article class="stat-card"><span class="stat-label">Matching accounts</span><strong>{{ $users->total() }}</strong><p>Paginated Orbit accounts</p></article>
</div>
<section class="panel"><div class="panel-head"><div><p class="eyebrow">ACCOUNT INVENTORY</p><h2>Orbit users</h2><p>Account-specific actions require the appropriate support or safety permissions.</p></div></div>
<form method="get" action="{{ route('orbit.admin.users.index') }}" style="display:flex;gap:10px;flex-wrap:wrap;padding:0 22px 18px">
<label class="subtle">Username or Orbit ID <input name="query" type="search" maxlength="60" pattern="[A-Za-z0-9_-]+" value="{{ request('query') }}" placeholder="Search account" style="display:block;border:1px solid #dfe4ed;padding:10px;border-radius:9px"></label>
<label class="subtle">Status <select name="status" style="display:block;border:1px solid #dfe4ed;padding:10px;border-radius:9px"><option value="">All</option><option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option><option value="suspended" {{ request('status') === 'suspended' ? 'selected' : '' }}>Suspended</option></select></label>
<button class="btn primary" style="align-self:end" type="submit">Search</button>
</form>
<div class="table-scroll"><table><thead><tr><th>USER</th><th>STATUS</th><th>IDENTITIES</th><th>DEVICES</th><th>SESSIONS</th><th></th></tr></thead><tbody>
@forelse($users as $user)
<tr><td><strong>{{ $user->display_name ?: 'Profile incomplete' }}</strong><small>{{ $user->username ?: substr($user->id,0,12).'…' }}</small></td>
<td><span class="pill {{ $user->status === 'active' ? 'success' : 'neutral' }}">{{ $user->status }}</span></td>
<td>{{ $user->identities_count }}</td><td>{{ $user->devices_count }}</td><td>{{ $user->sessions_count }}</td>
<td><a href="{{ route('orbit.admin.users.show',$user) }}">View →</a></td></tr>
@empty<tr><td colspan="6"><div class="empty-state"><strong>No Orbit accounts yet</strong><p>New accounts will appear after a successful user OTP verification.</p></div></td></tr>@endforelse
</tbody></table></div><div style="padding:18px">{{ $users->links() }}</div></section>
@endsection
