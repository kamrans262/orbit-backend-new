@extends('orbit-admin.layout')
@section('title','User-Shared Messaging Evidence')
@section('crumb','CONFIGURATIONS / MESSAGING / REPORTED EVIDENCE')
@section('content')
<div class="page-heading"><div><p class="eyebrow">EXPLICIT USER-SHARED EVIDENCE / ACCESS AUDITED</p>
<h1>Reported Message Evidence</h1>
<p>Only the reporter's voluntarily submitted evidence is shown. The original conversation remains end-to-end encrypted and inaccessible to administrators.</p>
</div></div>
<section class="panel">
<div class="admin-kv">
<div class="kv"><small>Report reference</small><strong>{{ $report }}</strong></div>
<div class="kv"><small>Reason</small><strong>{{ $reason }}</strong></div>
</div>
<p style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $evidence }}</p>
<p>Viewing this user-selected evidence has been recorded in the moderator audit log.</p>
<p><a href="{{ route('orbit.admin.messaging.reports') }}">Back to reports</a></p>
</section>
@endsection
