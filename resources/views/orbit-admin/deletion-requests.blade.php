@extends('orbit-admin.layout')
@section('title', 'Account deletion requests')
@section('crumb', 'ACCOUNTS / DELETION REQUESTS')
@section('content')
<div class="page-heading">
  <div><p class="eyebrow">ACCOUNTS / PRIVACY REQUESTS</p><h1>Account deletion requests</h1>
    <p>Operational request states and deadlines only. A request is not proof of erasure; no private content is exposed.</p></div>
</div>
<div class="stat-grid stat-grid--compact">
  <article class="stat-card">
    <span class="stat-label">Pending requests</span>
    <span class="stat-value">{{ $pendingCount }}</span>
    <p>Waiting for cancellation or erasure review.</p>
  </article>
  <article class="stat-card stat-card--attention">
    <span class="stat-label">Past eligibility date</span>
    <span class="stat-value">{{ $eligibleCount }}</span>
    <p>Review required — not automatically erased.</p>
  </article>
</div>
<section class="panel">
 <div class="panel-head"><div><p class="eyebrow">PRIVACY WORKFLOW</p><h2>Deletion request queue</h2><p>Read-only review. User reauthentication and cancellation happen in the mobile app.</p></div></div>
 <form class="admin-filters" method="get" action="{{ route('orbit.admin.deletions.index') }}">
  <label>Status <select name="status"><option value="">All requests</option>
   @foreach(['pending'=>'Pending','cancelled'=>'Cancelled','completed'=>'Completed'] as $key => $label)
   <option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>@endforeach
  </select></label><button class="btn primary" type="submit">Filter</button>
  <a class="btn secondary" href="{{ route('orbit.admin.deletions.index') }}">Reset</a>
 </form>
 <div class="table-scroll"><table><thead><tr><th>ACCOUNT</th><th>STATUS</th><th>REQUESTED (UTC)</th><th>ELIGIBLE FOR ERASURE (UTC)</th><th>COMPLETED (UTC)</th></tr></thead><tbody>
 @forelse($requests as $item)
 <tr>
  <td>
   @if(auth()->guard('admin')->user()->canPerform('users.read'))
   <a href="{{ route('orbit.admin.users.show', $item->user_id) }}"><strong>{{ $item->username ?: 'Unnamed account' }}</strong></a>
   @else<strong>{{ $item->user_id }}</strong>@endif
   <small>Request {{ $item->request_id }}</small>
  </td>
  <td><span class="pill {{ $item->status === 'completed' ? 'success' : ($item->status === 'pending' ? 'danger' : 'neutral') }}">{{ ucfirst($item->status) }}</span></td>
  <td>{{ $item->requested_at }}</td>
  <td>{{ $item->eligible_for_erasure_at }} @if($item->status === 'pending' && \Illuminate\Support\Carbon::parse($item->eligible_for_erasure_at)->isPast())<small>Review eligibility — erasure NOT confirmed</small>@endif</td>
  <td>{{ $item->completed_at ?: 'Not completed' }}</td>
 </tr>
 @empty<tr><td colspan="5"><div class="empty-state"><strong>No deletion requests found</strong><p>Records appear when accounts submit a verified request.</p></div></td></tr>@endforelse
 </tbody></table></div>
 <div class="admin-pagination">{{ $requests->links() }}</div>
</section>
<section class="panel" style="margin-top:22px">
 <div class="panel-head">
  <div>
   <p class="eyebrow">PROCESSED / PRIVACY AUDIT</p>
   <h2>Completed identity and profile data cleanup</h2>
   <p>Non-identifying receipts for reviewed, current-schema erasure only. Media, backups and other account data are not covered by this receipt.</p>
  </div>
 </div>
 <div class="table-scroll">
  <table>
   <thead><tr><th>REQUEST ID</th><th>COMPLETED (UTC)</th><th>VERIFIED SCOPE</th></tr></thead>
   <tbody>
    @forelse($completedReceipts as $receipt)
      <tr><td>{{ $receipt->request_id }}</td><td>{{ $receipt->completed_at }}</td>
      <td><span class="pill success">{{ $receipt->scope }}</span></td></tr>
    @empty
      <tr><td colspan="3"><div class="empty-state"><strong>No completed identity and profile cleanup receipts</strong>
      <p>No erasure has been recorded by the reviewed processor.</p></div></td></tr>
    @endforelse
   </tbody>
  </table>
 </div>
</section>
<div class="admin-guidance" role="note"><strong>Release dependency:</strong> Complete erasure, backup retention and legal holds require the dedicated privacy-erasure service. Do not manually treat an elapsed deadline as completed deletion.</div>
@endsection
