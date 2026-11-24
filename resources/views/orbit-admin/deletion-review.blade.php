@extends('orbit-admin.layout')
@section('title','Deletion eligibility review')
@section('crumb','ACCOUNTS / DELETION REVIEW')
@section('content')
<a class="back" href="{{ route('orbit.admin.deletions.index') }}">← Back to deletion requests</a>
<div class="page-heading"><div><p class="eyebrow">PRIVACY WORKFLOW</p><h1>Deletion eligibility review</h1><p>Review the existing erasure policy without changing the request, releasing a hold or deleting user data.</p></div></div>
<section class="panel"><div class="panel-head"><h2>Eligibility and dependencies</h2></div><div class="admin-kv">
@foreach(['status'=>'Request status','scope'=>'Verified processor scope','eligible'=>'Eligibility date passed','active_holds'=>'Active retention holds','execution_enabled'=>'Execution gate'] as $key=>$label)
@if(array_key_exists($key,$review))<div class="kv"><small>{{ $label }}</small><strong>{{ is_bool($review[$key])?($review[$key]?'Yes':'No'):$review[$key] }}</strong></div>@endif
@endforeach</div>
@if(!empty($review['unregistered_tables']))<div class="admin-guidance">Unregistered user-owned dependencies block erasure: {{ implode(', ',$review['unregistered_tables']) }}.</div>@endif
<div class="admin-guidance">Final execution remains with the existing gated erasure service. Eligibility does not prove deletion, media purge, backup erasure or legal clearance. Privacy defaults remain consent-safe and cannot be relaxed by an administrator.</div></section>
@endsection
