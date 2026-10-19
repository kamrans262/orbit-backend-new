@extends('orbit-admin.layout')
@section('title','Orbit user')
@section('crumb','USERS / DETAIL')
@section('content')
<a class="back" href="{{ route('orbit.admin.users.index') }}">← Back to users</a>
<div class="page-heading"><div><p class="eyebrow">PRIVACY-LIMITED RECORD</p><h1>{{ $orbitUser->display_name ?: 'Profile incomplete' }}</h1><p>Orbit user ID: {{ $orbitUser->id }} · Status: {{ $orbitUser->status }}</p></div></div>
<div class="two-col">
<section class="panel"><div class="panel-head"><div><h2>Verified identity methods</h2><p>Addresses and phone numbers are masked for support.</p></div></div><div class="table-scroll"><table><thead><tr><th>METHOD</th><th>VERIFIED</th><th>IDENTIFIER</th></tr></thead><tbody>
@foreach($orbitUser->identities as $identity)<tr><td>{{ ucfirst($identity->provider) }}</td><td>{{ $identity->verified_at?->format('Y-m-d H:i') }}</td><td>
@if($identity->provider==='email'){{ substr($identity->email_norm ?? '',0,2) }}***@***{{ explode('@',$identity->email_norm ?? '@')[1] ?? 'hidden' }}
@elseif($identity->provider==='phone')••••{{ substr($identity->phone_e164 ?? '', -4) }}
@else••••••@endif
</td></tr>@endforeach</tbody></table></div></section>
<section class="panel"><div class="panel-head"><div><h2>Registered devices</h2><p>Device approval remains a consumer-side trusted-device action.</p></div></div><div class="table-scroll"><table><thead><tr><th>DEVICE</th><th>PLATFORM</th><th>TRUST</th><th>REVOKED</th></tr></thead><tbody>
@foreach($orbitUser->devices as $device)<tr><td>{{ $device->name }}</td><td>{{ $device->platform }}</td><td>{{ $device->trust_state }}</td><td>{{ $device->revoked_at ? 'Yes' : 'No' }}</td></tr>@endforeach
</tbody></table></div></section></div>
@if(auth()->guard('admin')->user()->canPerform('users.manage'))
<section class="panel" style="margin-top:20px;padding:22px">
  <h2>Account access controls</h2>
  <p class="subtle">Suspension revokes all sessions. This action is permission-restricted and recorded in the audit log.</p>
  <form action="{{ route('orbit.admin.users.status', $orbitUser) }}" method="post" data-confirm="Change this account status and revoke existing sessions if suspended?">
    @csrf
    <div class="form-grid">
      <label>Status <select name="status"><option value="active" {{ $orbitUser->status === 'active' ? 'selected' : '' }}>Active</option><option value="suspended" {{ $orbitUser->status === 'suspended' ? 'selected' : '' }}>Suspended</option></select></label>
      <label>Documented justification <textarea name="reason" minlength="10" maxlength="500" rows="3" required placeholder="Enter ticket ID and reason"></textarea></label>
    </div>
    <div class="form-actions"><button class="btn primary" type="submit">Save audited status</button></div>
  </form>
</section>
@endif
@endsection
