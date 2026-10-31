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
<section class="panel">
  <div class="panel-head"><div><h2>Registered devices</h2><p>Review account devices and revoke compromised access. Approval remains with trusted Orbit devices.</p></div></div>
  <div class="table-scroll"><table><thead><tr><th>DEVICE</th><th>ACCESS</th><th>SESSIONS</th><th>ACTION</th></tr></thead><tbody>
    @forelse($orbitUser->devices as $device)
    @php
      $revoked = $device->revoked_at !== null;
      $onlyTrusted = !$revoked && $device->trust_state === 'trusted' && $trustedDevicesCount < 2;
    @endphp
    <tr>
      <td><strong>{{ $device->name }}</strong><small>{{ strtoupper($device->platform) }} · Last seen {{ $device->last_seen_at?->format('Y-m-d H:i') ?: 'Never' }} UTC</small></td>
      <td><span class="pill {{ $revoked ? 'neutral' : ($device->trust_state === 'trusted' ? 'success' : 'neutral') }}">{{ $revoked ? 'Revoked' : ucfirst($device->trust_state) }}</span></td>
      <td>{{ $activeSessionsByDevice[$device->id] ?? 0 }} active</td>
      <td>
        @if($revoked)<span class="subtle">No access</span>
        @elseif($onlyTrusted)<span class="subtle" title="Protects against irreversible device lockout">Only trusted device</span>
        @elseif(auth()->guard('admin')->user()->canPerform('users.manage'))
          <button type="button" class="btn secondary" data-open-device-revoke="{{ $device->id }}" aria-label="Revoke {{ $device->name }}">Revoke</button>
        @else<span class="subtle">View only</span>@endif
      </td>
    </tr>
    @empty<tr><td colspan="4">No registered devices.</td></tr>@endforelse
  </tbody></table></div>
  <div style="padding:18px 22px 20px">
    <h3>Recent sign-in and device activity</h3>
    <div class="table-scroll"><table><thead><tr><th>EVENT</th><th>DATE (UTC)</th></tr></thead><tbody>
      @forelse($identityEvents as $event)
        <tr><td>{{ ucwords(str_replace(['.', '_'], ' ', $event->action)) }}</td><td>{{ $event->created_at }}</td></tr>
      @empty<tr><td colspan="2">No recent authentication events.</td></tr>@endforelse
    </tbody></table></div>
  </div>
</section></div>
@if(auth()->guard('admin')->user()->canPerform('users.manage'))
  @foreach($orbitUser->devices as $device)
    @if(!$device->revoked_at && !($device->trust_state === 'trusted' && $trustedDevicesCount < 2))
    <dialog class="admin-confirm-dialog" id="revoke-device-{{ $device->id }}" aria-labelledby="revoke-device-title-{{ $device->id }}">
      <form method="post" action="{{ route('orbit.admin.users.devices.revoke',['user'=>$orbitUser,'device'=>$device]) }}">
        @csrf
        <h2 id="revoke-device-title-{{ $device->id }}">Revoke device access?</h2>
        <p>This will revoke <strong>{{ $device->name }}</strong> and sign out its active sessions. This device cannot be reapproved from the admin panel.</p>
        <label>Reason (10–500 characters)
          <textarea name="reason" rows="3" minlength="10" maxlength="500" required placeholder="Security incident or support ticket reference"></textarea>
        </label>
        <label>Type <strong>REVOKE</strong> to confirm
          <input name="confirmation" type="text" required pattern="REVOKE" maxlength="6" autocomplete="off" spellcheck="false" placeholder="REVOKE">
        </label>
        <div class="confirm-actions">
          <button type="button" class="btn secondary" data-close-device-revoke>Cancel</button>
          <button type="submit" class="btn danger">Revoke device</button>
        </div>
      </form>
    </dialog>
    @endif
  @endforeach
@endif
<div class="two-col" style="margin-top:20px">
  <section class="panel">
    <div class="panel-head"><div><p class="eyebrow">PROFILE / ACCOUNT INFORMATION</p><h2>Account profile metadata</h2>
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
    <div class="panel-head"><div><p class="eyebrow">SETTINGS / PRIVACY CONTROLS</p><h2>Settings record status</h2>
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
@if(auth()->guard('admin')->user()->canPerform('social.read'))
<section class="panel" style="margin-top:20px">
  <div class="panel-head"><div><p class="eyebrow">SOCIAL CONNECTIONS / ACCESS CONTROL</p><h2>Relationship operations</h2>
    <p>Review restricted relationship counters and event types without exposing counterparts or private content.</p></div>
    <a href="{{ route('orbit.admin.social.index',['user_id'=>$orbitUser->id]) }}">Review social metadata →</a>
  </div>
</section>
@endif
@if($deletionRecord)
<section class="panel" style="margin-top:20px">
  <div class="panel-head"><div><p class="eyebrow">ACCOUNT LIFECYCLE / PRIVACY REQUESTS</p><h2>Deletion request status</h2>
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
@if(auth()->guard('admin')->user()->canPerform('users.delete') && !$orbitUser->deletion_requested_at)
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
