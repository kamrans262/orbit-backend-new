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
<div class="two-col" style="margin-top:20px">
  <section class="panel">
    <div class="panel-head"><div><p class="eyebrow">PROFILE / MILESTONE 03</p><h2>Account profile metadata</h2>
      <p>Non-sensitive account data only. Biography text, private content and contact identifiers are not shown here.</p></div></div>
    <div class="admin-kv">
      <div class="kv"><small>Display name</small><strong>{{ $orbitUser->display_name ?: 'Not supplied' }}</strong></div>
      <div class="kv"><small>Username</small><strong>{{ $orbitUser->username ?: 'Not supplied' }}</strong></div>
      <div class="kv"><small>Biography status</small><strong>{{ filled($orbitUser->bio) ? 'On file · Private' : 'Not supplied' }}</strong></div>
      <div class="kv"><small>Account created (UTC)</small><strong>{{ $orbitUser->created_at?->format('Y-m-d H:i') ?: 'Unavailable' }}</strong></div>
      <div class="kv"><small>Profile updated (UTC)</small><strong>{{ $orbitUser->updated_at?->format('Y-m-d H:i') ?: 'Unavailable' }}</strong></div>
      <div class="kv"><small>Deletion request</small><strong>{{ $orbitUser->deletion_requested_at ? 'Pending request' : 'No pending request' }}</strong></div>
    </div>
  </section>
  <section class="panel">
    <div class="panel-head"><div><p class="eyebrow">SETTINGS / MILESTONE 03</p><h2>Settings record status</h2>
      <p>Only whether preferences have been saved is visible. Actual privacy, location and notification choices remain private.</p></div></div>
    <div class="admin-kv">
      <div class="kv"><small>Privacy preferences</small><strong>{{ $preferenceRecord?->privacy !== null ? 'Saved on server' : 'Default policy' }}</strong></div>
      <div class="kv"><small>Notification preferences</small><strong>{{ $preferenceRecord?->notifications !== null ? 'Saved on server' : 'Default policy' }}</strong></div>
      <div class="kv"><small>Preferences last updated (UTC)</small><strong>{{ $preferenceRecord?->updated_at ?: 'Not configured' }}</strong></div>
    </div>
    @if(auth()->guard('admin')->user()->canPerform('settings.audit.read'))
      <div style="padding:0 22px 20px"><a href="{{ route('orbit.admin.settings.activity', ['user_id'=>$orbitUser->id]) }}">View authorized field-only settings audit →</a></div>
    @endif
  </section>
</div>
@if($deletionRecord)
<section class="panel" style="margin-top:20px">
  <div class="panel-head"><div><p class="eyebrow">ACCOUNT LIFECYCLE / MILESTONE 03</p><h2>Deletion request status</h2>
  <p>Eligibility date does not mean the user has been erased. Final retention/erasure processing is a separate controlled workflow.</p></div></div>
  <div class="admin-kv">
    <div class="kv"><small>Current status</small><strong>{{ ucfirst($deletionRecord->status) }}</strong></div>
    <div class="kv"><small>Requested (UTC)</small><strong>{{ $deletionRecord->requested_at }}</strong></div>
    <div class="kv"><small>Eligible (UTC)</small><strong>{{ $deletionRecord->eligible_for_erasure_at }}</strong></div>
    <div class="kv"><small>Completed (UTC)</small><strong>{{ $deletionRecord->completed_at ?: 'Not completed' }}</strong></div>
  </div>
  <div style="padding:0 22px 20px"><a href="{{ route('orbit.admin.deletions.index') }}">View authorized deletion queue →</a></div>
</section>
@endif
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
@if(auth()->guard('admin')->user()->canPerform('users.delete'))
<section class="panel danger-zone" aria-labelledby="delete-user-heading">
  <div class="danger-zone-layout">
    <div class="danger-zone-heading">
      <span class="danger-zone-symbol" aria-hidden="true">!</span>
      <div class="danger-zone-copy">
        <p class="eyebrow danger-text">DANGER ZONE</p>
        <h2 id="delete-user-heading">Delete this user</h2>
        <p class="subtle">Permanently remove this account and its linked identities, devices, sessions, refresh history, OTP challenges, and identity activity.</p>
        <p class="danger-zone-note">This cannot be undone. Administrator audit records are retained.</p>
      </div>
    </div>
    <button type="button" class="btn danger danger-zone-cta" data-open-delete-user>Delete user</button>
  </div>
</section>
<dialog class="admin-confirm-dialog" id="delete-user-dialog" aria-labelledby="delete-user-title" aria-describedby="delete-user-description">
  <form method="post" action="{{ route('orbit.admin.users.destroy', $orbitUser) }}" data-delete-user-form>
    @csrf
    @method('DELETE')
    <div class="confirm-icon" aria-hidden="true">!</div>
    <h2 id="delete-user-title">Permanently delete user?</h2>
    <p id="delete-user-description">You are about to permanently remove <strong>{{ $orbitUser->display_name ?: ($orbitUser->username ?: 'this account') }}</strong> and their registered devices, verified contacts, active sessions and related identity records. This cannot be undone.</p>
    <p class="subtle">Account ID: {{ $orbitUser->id }}</p>
    <label>Reason for deletion (10–500 characters)
      <textarea name="reason" rows="3" minlength="10" maxlength="500" required placeholder="Enter an administrative reason; avoid private account details"></textarea>
    </label>
    <label>Type <strong>DELETE</strong> to confirm
      <input type="text" name="confirmation" autocomplete="off" autocapitalize="characters" spellcheck="false" pattern="DELETE" maxlength="6" required placeholder="DELETE" data-delete-user-confirm>
    </label>
    <div class="confirm-actions">
      <button type="button" class="btn secondary" data-cancel-delete-user>Cancel</button>
      <button type="submit" class="btn danger" data-submit-delete-user disabled>Permanently delete</button>
    </div>
  </form>
</dialog>
@endif
@endsection
