@extends('orbit-admin.layout')
@section('title','User Messaging Reports')
@section('crumb','CONFIGURATIONS / MESSAGING / USER REPORTS')
@section('content')
<div class="page-heading"><div>
<p class="eyebrow">RESTRICTED MODERATION / EXPLICIT USER REPORTS</p>
<h1>User Messaging Reports</h1>
<p>Only the selected report reason and evidence deliberately shared by the reporting member can be reviewed. No ordinary encrypted chats or attachments are readable here.</p>
</div></div>
<section class="panel">
@if($reports->isEmpty())
<div class="admin-guidance">No reports have been submitted.</div>
@else
@foreach($reports as $report)
<div class="admin-kv">
<div class="kv"><small>Report</small><strong>{{ $report->id }}</strong></div>
<div class="kv"><small>Reason</small><strong>{{ $report->reason }}</strong></div>
<div class="kv"><small>Status</small><strong>{{ $report->status }}</strong></div>
<div class="kv"><small>Received</small><strong>{{ $report->created_at }}</strong></div>
</div>
@if($report->evidence_shared)
<form method="post" action="{{ route('orbit.admin.messaging.reports.evidence',$report->id) }}">
@csrf<input type="hidden" name="confirm" value="1">
<button type="submit" class="btn btn-primary">View explicitly shared evidence (audited)</button>
</form>
@endif
@if($report->status==='pending')
<form method="post" action="{{ route('orbit.admin.messaging.reports.action',$report->id) }}">
@csrf<input type="hidden" name="action" value="reviewed">
<button type="submit" class="btn btn-secondary">Mark reviewed</button>
</form>
@endif
<hr>
@endforeach
@endif
</section>
@endsection
