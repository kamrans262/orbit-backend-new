@extends('orbit-admin.layout')
@section('title','Messaging Access Moderation')
@section('crumb','MESSAGING / ACCOUNT ACCESS')
@section('content')
<div class="page-heading"><div>
<p class="eyebrow">RESTRICTED ADMINISTRATION / ABUSE PREVENTION</p>
<h1>Messaging Access</h1>
<p>Temporarily suspend an account's ability to send new direct or Circle messages without accessing private chats, reading ciphertext or changing the user's general account access.</p>
</div></div>
@if(session('status'))<div class="admin-guidance" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="admin-guidance" role="alert">{{ $errors->first() }}</div>@endif
<section class="panel">
<div class="panel-head"><div><p class="eyebrow">ACCOUNT LOOKUP</p>
<h2>Messaging restrictions</h2><p>Search by exact Orbit ID or the beginning of a username. Without a search, only accounts with a recorded messaging restriction appear.</p></div></div>
<form method="get" action="{{ route('orbit.admin.messaging.restrictions') }}" class="admin-filters">
<label>Username or Orbit ID
<input type="search" name="query" maxlength="60" pattern="[A-Za-z0-9_-]+" value="{{ $search }}" placeholder="Find an account" /></label>
<button type="submit" class="btn primary">Search</button>
<a class="btn secondary" href="{{ route('orbit.admin.messaging.restrictions') }}">Show Restrictions</a>
</form>
@if($users->isEmpty())
<div class="admin-guidance">No matching accounts or current restriction records.</div>
@else
@foreach($users as $user)
<div class="admin-kv">
<div class="kv"><small>Account</small><strong>{{ $user->display_name ?: $user->username }} ({{ $user->username }})</strong>
<small>{{ $user->id }}</small></div>
<div class="kv"><small>Messaging access</small><strong>
@if($user->reason && (!$user->ends_at || \Carbon\Carbon::parse($user->ends_at)->isFuture()))
Restricted · {{ $user->reason }} @if($user->ends_at) until {{ $user->ends_at }} @else indefinitely @endif
@else Active @endif
</strong></div>
</div>
<form method="post" action="{{ route('orbit.admin.messaging.restrictions.update',$user->id) }}" style="padding:0 20px 20px">
@csrf
<div class="form-grid">
<label>Action
<select name="action" required>
<option value="suspend">Restrict new messages</option>
<option value="restore">Restore messaging access</option>
</select></label>
<label>Duration (for restriction)
<select name="duration">
<option value="24h">24 hours</option>
<option value="1h">1 hour</option>
<option value="7d">7 days</option>
<option value="30d">30 days</option>
<option value="indefinite">Indefinite, until moderator restores</option>
</select></label>
<label>Reason (for restriction)
<select name="reason">
<option value="spam">Spam</option>
<option value="harassment">Harassment</option>
<option value="fraud">Fraud</option>
<option value="abuse">Abusive behavior</option>
<option value="other">Other verified safety issue</option>
</select></label>
</div>
<button type="submit" class="btn secondary">Apply Messaging Access Decision</button>
</form>
<hr>
@endforeach
<div style="padding:16px">{{ $users->links() }}</div>
@endif
<p style="padding:0 20px 16px"><strong>Scope:</strong> only future encrypted sends are restricted. Existing ciphertext, message history, legitimate reports, account login and other Orbit features are not erased.</p>
</section>
<section class="panel">
<div class="panel-head"><div><p class="eyebrow">MODERATION AUDIT</p><h2>Recent messaging access decisions</h2>
<p>Every action is attributed to a moderator, with reason, expiry and timestamp.</p></div></div>
@if($audit->isEmpty())
<div class="admin-guidance">No moderation restrictions have been recorded.</div>
@else
<div class="table-scroll"><table><thead><tr><th>ACTION</th><th>USER ID</th><th>REASON</th><th>MODERATOR</th><th>DATE</th></tr></thead><tbody>
@foreach($audit as $row)
<tr><td>{{ ucfirst($row->action) }}</td><td>{{ $row->user_id }}</td>
<td>{{ $row->reason ?: 'Not applicable' }}</td>
<td>{{ $row->actor_name ?? 'Former administrator' }}</td>
<td>{{ $row->created_at }}</td></tr>
@endforeach
</tbody></table></div>
@endif
</section>
@endsection
