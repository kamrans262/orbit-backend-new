@extends('orbit-admin.layout')
@section('title','Messaging Settings')
@section('crumb','MESSAGING / OPERATIONS & SETTINGS')
@section('content')
<div class="page-heading"><div>
<p class="eyebrow">SECURE MESSAGING / SERVICE CONTROLS</p>
<h1>Messaging Settings</h1>
<p>Control encrypted mailbox operation without access to private messages. Every change is recorded against the signed-in administrator.</p>
</div></div>
@if(session('status'))<div class="admin-guidance" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="admin-guidance" role="alert">{{ $errors->first() }}</div>@endif
<section class="panel">
<div class="panel-head"><div><p class="eyebrow">RUNTIME CONTROLS</p><h2>Delivery and retention</h2>
<p>Changes apply to subsequent encrypted sends and uploads. Reducing retention never restores expired messages.</p></div></div>
@if(!$ready)
<div class="admin-guidance">Run the pending messaging administration database migration before editing settings.</div>
@else
<form method="post" action="{{ route('orbit.admin.messaging.settings.save') }}">
@csrf
<div class="admin-kv form-grid">
<div class="kv"><label for="retention_days">Server ciphertext retention (1–30 days)</label>
<input id="retention_days" name="retention_days" type="number" min="1" max="30" required value="{{ old('retention_days',$values['retention_days']) }}" /></div>
<div class="kv"><label for="send_rate_per_minute">Send limit per trusted device (5–120/min)</label>
<input id="send_rate_per_minute" name="send_rate_per_minute" type="number" min="5" max="120" required value="{{ old('send_rate_per_minute',$values['send_rate_per_minute']) }}" /></div>
<div class="kv"><label for="typing_http_enabled">HTTPS typing indicators</label>
<select id="typing_http_enabled" name="typing_http_enabled" required>
<option value="1" @selected((string)old('typing_http_enabled',$values['typing_http_enabled']?'1':'0')==='1')>Enabled</option>
<option value="0" @selected((string)old('typing_http_enabled',$values['typing_http_enabled']?'1':'0')==='0')>Disabled</option>
</select></div>
</div>
<button type="submit" class="btn primary">Save Messaging Settings</button>
</form>
@endif
<p>Messages and attachments sent under earlier deadlines keep their existing expiry. New retention applies to new ciphertext only; local encrypted history remains device-owned.</p>
</section>
<section class="panel">
<div class="panel-head"><div><p class="eyebrow">RELEASE SAFETY</p><h2>Security and infrastructure</h2>
<p>These require independent technical verification and cannot be overridden from an admin toggle.</p></div></div>
<div class="admin-kv">
<div class="kv"><small>Audited E2EE client release</small><strong>{{ $verified ? 'Approved build configured' : 'Blocked until independent verification' }}</strong></div>
<div class="kv"><small>WebSocket presence transport</small><strong>{{ $websocket ? 'Provisioned' : 'Not provisioned' }}</strong></div>
<div class="kv"><small>Firebase push transport</small><strong>{{ $push ? 'Provisioned' : 'Not provisioned' }}</strong></div>
</div>
</section>
<section class="panel">
<div class="panel-head"><div><p class="eyebrow">RETENTION / MAINTENANCE</p><h2>Expire due ciphertext</h2>
<p>Maintenance deletes only already-expired server envelopes and expired sender-key packets. It does not open chats, delete current messages, or change legal holds.</p></div></div>
@if($ready)
<form method="post" action="{{ route('orbit.admin.messaging.maintenance.prune') }}">
@csrf
<input type="hidden" name="confirm" value="PRUNE" />
<button type="submit" class="btn secondary">Run Expired Ciphertext Cleanup</button>
</form>
@endif
</section>
<section class="panel">
<div class="panel-head"><div><p class="eyebrow">ADMIN AUDIT</p><h2>Recent operational changes</h2>
<p>Only settings and anonymous cleanup counts are recorded. No conversation content or encryption keys.</p></div></div>
@if($audits->isEmpty())
<div class="admin-guidance">No administrative changes have been recorded.</div>
@else
@foreach($audits as $audit)
<div class="admin-kv">
<div class="kv"><small>Action</small><strong>{{ $audit->action }}</strong></div>
<div class="kv"><small>When</small><strong>{{ $audit->created_at }}</strong></div>
<div class="kv"><small>Administrator</small><strong>{{ $audit->actor_name ?? 'Former administrator' }}</strong></div>
</div>
@endforeach
@endif
</section>
@endsection
