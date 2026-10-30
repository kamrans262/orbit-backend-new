@extends('orbit-admin.layout')
@section('title','Messaging Operations')
@section('crumb','CONFIGURATIONS / MESSAGING OPERATIONS')
@section('content')
<div class="page-heading"><div>
<p class="eyebrow">CIPHERTEXT MESSAGING / PRIVACY-SAFE MONITORING</p>
<h1>Messaging Operations</h1>
<p>Monitor delivery infrastructure and consent-aware message availability. Private conversations, text, media, participant identities and encryption keys are not available to administrators.</p>
</div></div>
<section class="panel">
<div class="panel-head"><div><p class="eyebrow">SERVICE SETTINGS</p>
<h2>Encrypted messaging transport</h2>
<p>Current server capabilities are separate from the required audited end-to-end encryption on each mobile device.</p></div></div>
<div class="admin-kv">
<div class="kv"><small>HTTPS ciphertext API</small><strong>{{ $ready && $backendEnabled ? 'Available to authorized devices' : 'Not available' }}</strong></div>
<div class="kv"><small>Circle messaging</small><strong>{{ $circleEnabled ? 'Enabled with reviewed per-user consent' : 'Unavailable' }}</strong></div>
<div class="kv"><small>Mobile E2EE validation</small><strong>{{ $clientVerified ? 'Verified' : 'Pending independent device/protocol verification' }}</strong></div>
<div class="kv"><small>Live WebSocket / Push</small><strong>Not provisioned — secure HTTPS fallback only</strong></div>
<div class="kv"><small>Server envelope retention</small><strong>{{ $retentionDays }} days maximum</strong></div>
</div>
@if($stats)
<div class="admin-kv">
<div class="kv"><small>Direct conversations</small><strong>{{ $stats['direct_conversations'] }}</strong></div>
<div class="kv"><small>Circle conversations</small><strong>{{ $stats['circle_conversations'] }}</strong></div>
<div class="kv"><small>Trusted devices with messaging keys</small><strong>{{ $stats['trusted_registered_devices'] }}</strong></div>
<div class="kv"><small>Accepted encrypted sends (24h)</small><strong>{{ $stats['accepted_messages_24h'] }}</strong></div>
<div class="kv"><small>Undelivered encrypted device envelopes</small><strong>{{ $stats['pending_envelopes'] }}</strong></div>
<div class="kv"><small>Pending user reports (count only)</small><strong>{{ $stats['aggregate_reports_pending'] }}</strong></div>
<div class="kv"><small>Rejected requests (24h)</small><strong>{{ $stats['rejections_24h'] ?? 'Unavailable' }}</strong></div>
</div>
@else <div class="admin-guidance">Apply the messaging schema migration to display operational aggregates.</div> @endif
<p><strong>Encryption transparency:</strong> the Laravel server accepts opaque per-device envelopes only and cannot decrypt message content. A registered device key is not proof that the Flutter Double Ratchet and group sender-key engine has passed security review. Do not describe the app as live end-to-end encrypted messaging until that separate review is complete.</p>
<p>Active friend/Circle permission, trusted-device status and recipient eligibility are checked on every read and send. Group sender-key epochs must rotate after roster changes.</p>
</section>
@endsection
