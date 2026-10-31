@extends('orbit-admin.layout')
@section('title','Messaging Operations')
@section('crumb','MESSAGING / OVERVIEW')
@section('content')
<div class="page-heading"><div>
<p class="eyebrow">SECURE MESSAGING / OPERATIONS</p>
<h1>Messaging Management</h1>
<p>Manage private messaging availability, delivery health and report workflows while keeping end-to-end encrypted conversations inaccessible to the server.</p>
</div></div>
<section class="panel">
<div class="panel-head"><div><p class="eyebrow">SERVICE STATUS</p>
<h2>Encrypted messaging transport</h2>
<p>Mobile cryptographic approval is independent from backend readiness. Administrators cannot turn on unreviewed E2EE.</p></div></div>
<div class="admin-kv">
<div class="kv"><small>HTTPS ciphertext API</small><strong>{{ $ready && $backendEnabled ? 'Available' : 'Unavailable' }}</strong></div>
<div class="kv"><small>Circle messaging</small><strong>{{ $circleEnabled ? 'Consent-controlled' : 'Unavailable' }}</strong></div>
<div class="kv"><small>Verified mobile encryption</small><strong>{{ $clientVerified ? 'Release approved' : 'Independent review pending' }}</strong></div>
<div class="kv"><small>Encrypted send release</small><strong>{{ $clientVerified && count(config('orbit_messaging.approved_protocols', [])) > 0 ? 'Approved protocol only' : 'Safety hold' }}</strong></div>
<div class="kv"><small>Realtime / push</small><strong>{{ config('orbit_messaging.realtime_enabled') || config('orbit_messaging.push_enabled') ? 'Partially provisioned' : 'Unavailable; HTTPS fallback' }}</strong></div>
<div class="kv"><small>Ciphertext retention</small><strong>{{ $retentionDays }} days</strong></div>
<div class="kv"><small>Send limit</small><strong>{{ $sendLimit }} sends per minute per device</strong></div>
<div class="kv"><small>Typing via HTTPS</small><strong>{{ $typingEnabled ? 'Enabled' : 'Disabled' }}</strong></div>
</div>
</section>
<section class="panel">
<div class="panel-head"><div><p class="eyebrow">SERVICE METRICS</p>
<h2>Message delivery and moderation</h2>
<p>Counts only. No private messages, recipients, attachment content, keys or ciphertext can be browsed by administrators.</p></div></div>
@if($stats)
<div class="admin-kv">
<div class="kv"><small>Direct conversations</small><strong>{{ $stats['direct_conversations'] }}</strong></div>
<div class="kv"><small>Circle conversations</small><strong>{{ $stats['circle_conversations'] }}</strong></div>
<div class="kv"><small>Trusted devices with keys</small><strong>{{ $stats['trusted_registered_devices'] }}</strong></div>
<div class="kv"><small>Encrypted sends, last 24 hours</small><strong>{{ $stats['accepted_messages_24h'] }}</strong></div>
<div class="kv"><small>Pending device envelopes</small><strong>{{ $stats['pending_envelopes'] }}</strong></div>
<div class="kv"><small>Delivered envelopes, 24 hours</small><strong>{{ $stats['delivered_envelopes_24h'] }}</strong></div>
<div class="kv"><small>Read envelopes, 24 hours</small><strong>{{ $stats['read_envelopes_24h'] }}</strong></div>
<div class="kv"><small>Reports waiting</small><strong>{{ $stats['aggregate_reports_pending'] }}</strong></div>
<div class="kv"><small>Cases escalated</small><strong>{{ $stats['aggregate_reports_escalated'] }}</strong></div>
<div class="kv"><small>Rejected requests, 24 hours</small><strong>{{ $stats['rejections_24h'] ?? 'Unavailable' }}</strong></div>
</div>
@else
<div class="admin-guidance">Messaging tables are not yet provisioned. Apply the existing messaging migrations before using live operations.</div>
@endif
<p><strong>Privacy boundary:</strong> Admin access cannot decrypt direct or Circle messages. Moderators may read only evidence a reporting user deliberately submits and every evidence access is audited.</p>
<p><a href="{{ route('orbit.admin.messaging.settings') }}" class="btn btn-secondary">Manage delivery and retention settings</a>
@if(auth()->guard('admin')->user()?->canPerform('messages.moderate'))
<a href="{{ route('orbit.admin.messaging.reports') }}" class="btn btn-primary">Open moderation queue</a>
@endif
</p>
@if(!$controlsReady)
<div class="admin-guidance">The latest messaging administration migration is required for editable operational settings.</div>
@endif
</section>
@endsection
