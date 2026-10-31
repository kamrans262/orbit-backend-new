@extends('orbit-admin.layout')
@section('title','Messaging Report Case')
@section('crumb','MESSAGING / REPORTS / CASE')
@section('content')
<div class="page-heading"><div>
<p class="eyebrow">RESTRICTED MODERATION / AUDITED ACCESS</p>
<h1>Report Case</h1>
<p>Moderation metadata and reporter-volunteered evidence only. Conversation history, participants, private attachments and cryptographic material remain inaccessible.</p>
</div></div>
@if(session('status'))<div class="admin-guidance" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="admin-guidance" role="alert">{{ $errors->first() }}</div>@endif
<section class="panel">
<div class="panel-head"><div><p class="eyebrow">CASE SUMMARY</p><h2>{{ $report->id }}</h2></div></div>
<div class="admin-kv">
<div class="kv"><small>Reason</small><strong>{{ $report->reason }}</strong></div>
<div class="kv"><small>Status</small><strong>{{ ucfirst($report->status) }}</strong></div>
<div class="kv"><small>Submitted</small><strong>{{ $report->created_at }}</strong></div>
<div class="kv"><small>Last resolution</small><strong>{{ $report->reviewed_at ?? 'Not yet reviewed' }}</strong></div>
<div class="kv"><small>Reporter evidence</small><strong>{{ $report->evidence_shared ? 'Explicitly shared' : 'Not shared' }}</strong></div>
</div>
@if($note)
<p><strong>Restricted moderator note</strong></p>
<p style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $note }}</p>
@endif
@if($report->evidence_shared)
<form method="post" action="{{ route('orbit.admin.messaging.reports.evidence',$report->id) }}">
@csrf
<input type="hidden" name="confirm" value="1" />
<button type="submit" class="btn secondary">View Explicit User-Shared Evidence (Audited)</button>
</form>
@endif
</section>
<section class="panel">
<div class="panel-head"><div><p class="eyebrow">MODERATION ACTION</p><h2>Resolve or escalate</h2>
<p>A case action updates the report record and creates an administrator audit entry. Notes are encrypted at rest.</p></div></div>
<form method="post" action="{{ route('orbit.admin.messaging.reports.action',$report->id) }}">
@csrf
<div class="admin-kv form-grid">
<div class="kv"><label for="moderation_action">Case decision</label>
<select id="moderation_action" name="action" required>
<option value="reviewed">Mark reviewed</option>
<option value="escalated">Escalate for further review</option>
<option value="dismissed">Dismiss report</option>
</select></div>
<div class="kv"><label for="moderator_note">Internal moderator note (optional)</label>
<textarea id="moderator_note" name="note" rows="3" maxlength="1000" placeholder="Private operational notes — do not paste unrelated private chats.">{{ old('note') }}</textarea></div>
</div>
<button type="submit" class="btn primary">Save Case Decision</button>
</form>
</section>
<section class="panel">
<div class="panel-head"><div><p class="eyebrow">MODERATION AUDIT</p><h2>Case activity</h2></div></div>
@if($history->isEmpty())<div class="admin-guidance">No moderator action has been recorded yet.</div>
@else
@foreach($history as $item)
<div class="admin-kv">
<div class="kv"><small>Action</small><strong>{{ str_replace('_',' ',ucfirst($item->action)) }}</strong></div>
<div class="kv"><small>Timestamp</small><strong>{{ $item->created_at }}</strong></div>
<div class="kv"><small>Moderator</small><strong>{{ $item->actor_name ?? 'Former administrator' }}</strong></div>
</div>
@endforeach
@endif
<p><a class="btn secondary" href="{{ route('orbit.admin.messaging.reports') }}">Back to Reports</a></p>
</section>
@endsection
