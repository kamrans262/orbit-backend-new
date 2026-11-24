@extends('orbit-admin.layout')
@section('title','Administrative Audit')
@section('crumb','ADMINISTRATORS / AUDIT')
@section('content')
<a class="back" href="{{ route('orbit.admin.administrators.index') }}">← Back to administrators</a>
<div class="page-heading"><div><p class="eyebrow">ADMINISTRATIVE SECURITY</p><h1>Administrative audit</h1><p>Bounded searchable metadata. No credentials, private content, IP addresses, or raw request bodies.</p></div></div>
<section class="panel"><div class="panel-head"><h2>Audit history</h2></div>
<form method="get" class="admin-filters"><label>Actor ID<input name="actor" value="{{ $filters['actor']??'' }}"></label><label>Action<input name="action" value="{{ $filters['action']??'' }}"></label>
<label>From<input type="date" name="from" value="{{ $filters['from']??now()->subDays(7)->toDateString() }}"></label><label>To<input type="date" name="to" value="{{ $filters['to']??now()->toDateString() }}"></label><button class="btn primary">Filter</button></form>
<div class="table-scroll"><table><thead><tr><th>ACTOR</th><th>ACTION</th><th>TARGET</th><th>DATE</th></tr></thead><tbody>
@forelse($events as $event)<tr><td>{{ $event->actor_id??'—' }}</td><td>{{ $event->action }}</td><td>{{ $event->target }}</td><td>{{ $event->created_at }}</td></tr>@empty<tr><td colspan="4">No matching events.</td></tr>@endforelse
</tbody></table></div>{{ $events->links() }}</section>
@endsection
