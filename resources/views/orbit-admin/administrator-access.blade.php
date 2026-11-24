@extends('orbit-admin.layout')
@section('title','Administrators & Access')
@section('crumb','ADMINISTRATORS / ACCESS')
@section('content')
<div class="page-heading"><div><p class="eyebrow">ADMINISTRATIVE SECURITY</p><h1>Administrators &amp; Access</h1><p>Review existing administrator accounts and least-privilege roles. Access changes require two independent super administrators and fresh MFA.</p></div></div>
<p><a class="btn secondary" href="{{ route('orbit.admin.administrators.audits') }}">Search administrative audit</a></p>
@if(!$ready)<div class="admin-guidance">Access review schema has not been provisioned.</div>@endif
<section class="panel"><div class="panel-head"><h2>Administrator directory</h2></div><div class="table-scroll"><table><thead><tr><th>NAME</th><th>EMAIL</th><th>STATUS</th><th>ROLES</th><th>LAST LOGIN</th></tr></thead><tbody>
@forelse($admins as $admin)<tr><td>{{ $admin->name }}</td><td>{{ $admin->email }}</td><td>{{ $admin->status }}</td><td>{{ $admin->roles->pluck('name')->join(', ') }}</td><td>{{ $admin->last_login_at??'—' }}</td></tr>@empty<tr><td colspan="5">No administrators found.</td></tr>@endforelse
</tbody></table></div>{{ $admins->links() }}</section>
<section class="panel"><div class="panel-head"><h2>Roles and permissions</h2></div><div class="table-scroll"><table><thead><tr><th>ROLE</th><th>PERMISSIONS</th></tr></thead><tbody>
@foreach($roles as $role)<tr><td>{{ $role->name }}</td><td>{{ implode(', ',$role->permissions??[]) }}</td></tr>@endforeach</tbody></table></div></section>
@if($ready)
<section class="panel"><div class="panel-head"><h2>Create an unassigned role</h2></div><form method="post" action="{{ route('orbit.admin.administrators.roles.create') }}">@csrf
<label>Role name<input name="name" required maxlength="80"></label><div class="form-grid">@foreach($permissions as $permission)<label><input type="checkbox" name="permissions[]" value="{{ $permission }}">{{ $permission }}</label>@endforeach</div>
<label>Reason<input name="reason" required minlength="10" maxlength="500"></label><label>Verification code<input type="password" name="mfa_code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required></label><label><input type="checkbox" name="confirm" value="1" required> Confirm role creation</label><button class="btn primary">Create role</button></form></section>
<section class="panel"><div class="panel-head"><h2>Request access change</h2></div><form method="post" action="{{ route('orbit.admin.administrators.propose') }}">@csrf
<label>Administrator<select name="target_id" required><option value="">Select account</option>@foreach($admins as $admin)@if($admin->id!==auth()->guard('admin')->id()&&!$admin->roles->contains(fn($r)=>in_array('*',$r->permissions??[],true)))<option value="{{ $admin->id }}">{{ $admin->name }} — {{ $admin->email }}</option>@endif @endforeach</select></label>
<label>Operation<select name="operation"><option value="grant">Grant role</option><option value="revoke">Revoke role</option><option value="suspend">Suspend</option><option value="activate">Activate</option><option value="sign_out">Revoke browser sessions</option></select></label>
<label>Role (for grant/revoke only)<select name="role_id"><option value="">None</option>@foreach($delegable as $role)<option value="{{ $role->id }}">{{ $role->name }}</option>@endforeach</select></label>
<label>Reason<input name="reason" required minlength="10" maxlength="500"></label><label>Verification code<input type="password" name="mfa_code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required></label><label><input type="checkbox" name="confirm" value="1" required> Confirm request</label><button class="btn primary">Request review</button></form></section>
<section class="panel"><div class="panel-head"><h2>Independent access reviews</h2></div><div class="table-scroll"><table><thead><tr><th>ACCOUNT ID</th><th>OPERATION</th><th>ROLE ID</th><th>REQUESTED BY</th><th>REASON</th><th>STATE</th><th>EXPIRES</th></tr></thead><tbody>
@forelse($reviews as $review)<tr><td>{{ $review->target_id }}</td><td>{{ $review->operation }}</td><td>{{ $review->role_id??'—' }}</td><td>{{ $review->requested_by }}</td><td>{{ $review->reason }}</td><td>{{ $review->state }} @if($review->decided_by)<small>Reviewed by {{ $review->decided_by }}</small>@endif</td><td>{{ $review->expires_at }}</td></tr>
@if($review->state==='pending'&&now()->lt($review->expires_at)&&$review->requested_by!==auth()->guard('admin')->id())
<tr><td colspan="7"><form method="post" action="{{ route('orbit.admin.administrators.decide',$review->id) }}">@csrf
<label>Decision<select name="decision"><option value="reject">Reject</option><option value="approve">Approve</option></select></label><label>Reason<input name="reason" minlength="10" maxlength="500" required></label><label>Verification code<input type="password" name="mfa_code" maxlength="6" inputmode="numeric" autocomplete="one-time-code" required></label><label><input type="checkbox" name="confirm" value="1" required> Confirm decision</label><button class="btn primary">Record decision</button></form></td></tr>
@endif
@empty<tr><td colspan="7">No reviews submitted.</td></tr>@endforelse</tbody></table></div></section>
@endif
<div class="admin-guidance">Initial administrator provisioning and MFA enrollment remain a separate secure process. Wildcard administrators and credentials cannot be modified here.</div>
@endsection
