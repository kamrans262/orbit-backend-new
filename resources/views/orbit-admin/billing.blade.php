@extends('orbit-admin.layout')
@section('title','Subscriptions & Billing')
@section('crumb') SUBSCRIPTIONS &amp; BILLING @endsection
@section('content')
<div class="page-heading"><div><p class="eyebrow">SUBSCRIPTIONS &amp; BILLING</p><h1>{{ ['products'=>'Plans & Products','subscriptions'=>'Subscriptions','operations'=>'Billing Operations'][$area] }}</h1><p>Sandbox billing only. Live purchases and unapproved premium capabilities remain disabled.</p></div></div>
@if(!$installed)
<section class="panel"><div class="admin-guidance">Billing schema is not installed.</div></section>
@else
<div class="admin-guidance">Sandbox processing {{ $enabled?'enabled':'disabled' }}. Store prices are authoritative; no charge, cancellation, refund or manual entitlement action is provided.</div>
@if($area==='products')
<section class="panel"><div class="panel-head"><div><h2>Plan catalog</h2><p>Free, Orbit Lite, Orbit Plus. Monthly store mappings; commercial benefits pending approval.</p></div></div>
<div class="admin-kv">@foreach($catalogs as $catalog)<div class="kv"><small>{{ ucfirst($catalog['provider']) }}</small><strong>{{ $catalog['purchase_enabled']?'Sandbox available':'Unavailable' }}</strong></div>@endforeach</div>
<div class="table-scroll"><table><thead><tr><th>PLAN</th><th>PROVIDER</th><th>PRODUCT</th><th>BASE PLAN</th><th>STATE</th><th>REVISION</th></tr></thead><tbody>
@forelse($products as $product)<tr><td>{{ ucfirst($product->tier) }}</td><td>{{ $product->provider }}</td><td>{{ $product->product_id }}</td><td>{{ $product->base_plan_id?:'—' }}</td><td>{{ $product->enabled?'Enabled':'Disabled' }}</td><td>{{ $product->revision }}</td></tr>
@if(auth()->guard('admin')->user()?->canPerform('billing.manage'))
<tr><td colspan="6"><form method="post" action="{{ route('orbit.admin.billing.products.save') }}">@csrf
@foreach(['id','revision','tier','provider','product_id','base_plan_id'] as $key)<input type="hidden" name="{{ $key }}" value="{{ $product->$key }}">@endforeach
<label>Availability<select name="enabled"><option value="0" @selected(!$product->enabled)>Disabled</option><option value="1" @selected($product->enabled)>Sandbox enabled</option></select></label>
<label>Reason<input name="reason" minlength="10" maxlength="500" required></label><label><input type="checkbox" name="confirm" value="1" required> Confirm</label><button class="btn primary">Save Controls</button></form></td></tr>
@endif
@empty<tr><td colspan="6">No store products mapped.</td></tr>@endforelse
</tbody></table></div>{{ $products->links() }}</section>
@if(auth()->guard('admin')->user()?->canPerform('billing.manage'))
<section class="panel"><div class="panel-head"><h2>Add sandbox product</h2></div><form method="post" action="{{ route('orbit.admin.billing.products.save') }}">@csrf
<input type="hidden" name="revision" value="0"><input type="hidden" name="enabled" value="0">
<label>Plan<select name="tier"><option value="lite">Orbit Lite</option><option value="plus">Orbit Plus</option></select></label>
<label>Provider<select name="provider"><option value="apple">Apple</option><option value="google">Google Play</option></select></label>
<label>Product ID<input name="product_id" maxlength="150" required></label><label>Google base plan (blank for Apple)<input name="base_plan_id" maxlength="100"></label>
<label>Reason<input name="reason" minlength="10" maxlength="500" required></label><label><input type="checkbox" name="confirm" value="1" required> Confirm mapping</label><button class="btn primary">Add Product</button></form></section>
@endif
@elseif($area==='subscriptions')
<section class="panel"><div class="panel-head"><h2>Verified subscription records</h2></div>
<form method="get"><label>Account ID<input name="user" value="{{ $filters['user']??'' }}"></label><label>Provider<select name="provider"><option value="">All</option>@foreach(['apple','google'] as $p)<option value="{{ $p }}" @selected(($filters['provider']??'')===$p)>{{ $p }}</option>@endforeach</select></label>
<label>State<select name="state"><option value="">All</option>@foreach(['pending','active','grace','hold','paused','expired','revoked','unknown'] as $s)<option value="{{ $s }}" @selected(($filters['state']??'')===$s)>{{ ucfirst($s) }}</option>@endforeach</select></label><button class="btn">Filter</button></form>
<div class="table-scroll"><table><thead><tr><th>ACCOUNT</th><th>PLAN</th><th>PROVIDER</th><th>STATE</th><th>PAID THROUGH</th><th>RENEWAL</th><th>VERIFIED</th><th>ACKNOWLEDGEMENT</th></tr></thead><tbody>
@forelse($subscriptions as $s)<tr><td>{{ $s->user_id }}</td><td>{{ $s->tier }}</td><td>{{ $s->provider }}</td><td>{{ $s->state }}</td><td>{{ $s->ends_at??'—' }}</td><td>{{ $s->auto_renew===null?'Unknown':($s->auto_renew?'On':'Off') }}</td><td>{{ $s->verified_at }}</td><td>{{ $s->ack_state }}</td></tr>
@empty<tr><td colspan="8">No verified records.</td></tr>@endforelse</tbody></table></div>{{ $subscriptions->links() }}</section>
@else
<section class="panel"><div class="panel-head"><h2>Provider event processing</h2></div>
<div class="admin-kv">@foreach($counts as $state=>$count)<div class="kv"><small>{{ ucfirst($state) }} records</small><strong>{{ $count }}</strong></div>@endforeach</div>
<div class="table-scroll"><table><thead><tr><th>EVENT</th><th>PROVIDER</th><th>STATE</th><th>ATTEMPTS</th><th>ERROR</th><th>NEXT ATTEMPT</th></tr></thead><tbody>
@forelse($events as $event)<tr><td>{{ $event->id }}</td><td>{{ $event->provider }}</td><td>{{ $event->state }}</td><td>{{ $event->attempts }}</td><td>{{ $event->error_code??'—' }}</td><td>{{ $event->next_attempt_at }}</td></tr>
@if(in_array($event->state,['retry','review'],true)&&auth()->guard('admin')->user()?->canPerform('billing.reconcile'))
<tr><td colspan="6"><form method="post" action="{{ route('orbit.admin.billing.events.retry',$event->id) }}">@csrf<label>Reason<input name="reason" minlength="10" maxlength="500" required></label><label><input type="checkbox" name="confirm" value="1" required> Confirm retry</label><button class="btn">Retry verification</button></form></td></tr>
@endif
@empty<tr><td colspan="6">No provider events.</td></tr>@endforelse</tbody></table></div>{{ $events->links() }}</section>
@if(auth()->guard('admin')->user()?->canPerform('billing.audit'))
<section class="panel"><div class="panel-head"><h2>Audit history</h2></div><div class="table-scroll"><table><thead><tr><th>ACTOR</th><th>ACTION</th><th>TARGET</th><th>REASON</th><th>DATE</th></tr></thead><tbody>@forelse($audits as $a)<tr><td>{{ $a->actor_id }}</td><td>{{ $a->action }}</td><td>{{ $a->target }}</td><td>{{ $a->reason }}</td><td>{{ $a->created_at }}</td></tr>@empty<tr><td colspan="5">No changes recorded.</td></tr>@endforelse</tbody></table></div></section>
@endif
@endif
@endif
@endsection
