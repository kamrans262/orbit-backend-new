@extends('orbit-admin.layout')
@section('title','Messaging Moderation')
@section('crumb','MESSAGING / USER REPORTS')
@section('content')
<div class="page-heading"><div>
<p class="eyebrow">RESTRICTED MODERATION / EXPLICIT USER REPORTS</p>
<h1>Messaging Reports</h1>
<p>Review user-submitted reports, escalate cases and record resolutions. No private conversation can be opened through this queue.</p>
</div></div>
@if(session('status'))<div class="admin-guidance" role="status">{{ session('status') }}</div>@endif
<section class="panel">
<div class="panel-head"><div><p class="eyebrow">CASE MANAGEMENT</p>
<h2>Moderation queue</h2><p>Search by report reference, status or reason. Only deliberately shared evidence is viewable after a separate audited action.</p></div></div>
<div class="admin-kv">
<div class="kv"><small>Pending</small><strong>{{ $counts['pending'] ?? 0 }}</strong></div>
<div class="kv"><small>Escalated</small><strong>{{ $counts['escalated'] ?? 0 }}</strong></div>
<div class="kv"><small>Reviewed</small><strong>{{ $counts['reviewed'] ?? 0 }}</strong></div>
<div class="kv"><small>Dismissed</small><strong>{{ $counts['dismissed'] ?? 0 }}</strong></div>
</div>
<form method="get" action="{{ route('orbit.admin.messaging.reports') }}">
<div class="admin-kv">
<div class="kv"><label for="report_status">Status</label>
<select id="report_status" name="status">
<option value="">All statuses</option>
@foreach(['pending'=>'Pending','escalated'=>'Escalated','reviewed'=>'Reviewed','dismissed'=>'Dismissed'] as $code=>$title)
<option value="{{ $code }}" @selected(($filters['status']??'')===$code)>{{ $title }}</option>
@endforeach
</select></div>
<div class="kv"><label for="report_reason">Reason</label>
<input id="report_reason" name="reason" type="text" maxlength="32" value="{{ $filters['reason']??'' }}" placeholder="e.g. harassment" /></div>
<div class="kv"><label for="report_ref">Report ID</label>
<input id="report_ref" name="reference" type="text" maxlength="36" value="{{ $filters['reference']??'' }}" placeholder="Optional UUID reference" /></div>
</div>
<button type="submit" class="btn btn-secondary">Filter Reports</button>
<a href="{{ route('orbit.admin.messaging.reports') }}" class="btn btn-secondary">Clear Filters</a>
</form>
</section>
<section class="panel">
<div class="panel-head"><div><p class="eyebrow">RESTRICTED RECORDS</p><h2>Reported cases</h2></div></div>
@if($reports->count()===0)
<div class="admin-guidance">No reports match the selected filters.</div>
@else
@foreach($reports as $report)
<div class="admin-kv">
<div class="kv"><small>Report reference</small><strong>{{ $report->id }}</strong></div>
<div class="kv"><small>Reason / status</small><strong>{{ $report->reason }} · {{ ucfirst($report->status) }}</strong></div>
<div class="kv"><small>Submitted</small><strong>{{ $report->created_at }}</strong></div>
<div class="kv"><small>User evidence</small><strong>{{ $report->evidence_shared ? 'Shared by reporter' : 'Not shared' }}</strong></div>
</div>
<p><a href="{{ route('orbit.admin.messaging.reports.show',$report->id) }}" class="btn btn-secondary">Open Case</a></p>
<hr>
@endforeach
<div class="admin-kv">
<div class="kv"><small>Results</small><strong>{{ $reports->total() }} total · Page {{ $reports->currentPage() }} of {{ $reports->lastPage() }}</strong></div>
</div>
@if($reports->previousPageUrl())<a class="btn btn-secondary" href="{{ $reports->previousPageUrl() }}">Previous page</a>@endif
@if($reports->nextPageUrl())<a class="btn btn-secondary" href="{{ $reports->nextPageUrl() }}">Next page</a>@endif
@endif
</section>
@endsection
