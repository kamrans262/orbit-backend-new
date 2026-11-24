@extends('orbit-admin.layout')
@section('title','Advertisements & Sponsorships')
@section('crumb') ADVERTISEMENTS / {{ strtoupper($area) }} @endsection
@section('content')
<div class="page-heading">
  <div>
    <p class="eyebrow">SPONSORSHIP / AD OPERATIONS</p>
    <h1>Advertisements &amp; Sponsorships</h1>
    <p>Manage sponsor verification, creative approvals, campaigns, allowed placements and privacy-safe performance. No subscription, billing or member profiling is included.</p>
  </div>
</div>
<section class="panel">
  <div class="panel-head"><div>
    <h2>Advertising workspace</h2>
    <p>Uses only the approved Orbit Admin navigation, forms, cards, tables and confirmation styles. Advertisements remain globally disabled until the policy gates are approved.</p>
  </div></div>
  <div class="heading-actions" style="display:flex;flex-wrap:wrap;gap:8px">
    @foreach(\App\Modules\Config\Services\AdminNavigation::workspace(auth()->guard('admin')->user(), 'advertising') as $link)
      <a class="btn {{ request()->routeIs(...$link['patterns'])?'primary':'secondary' }}" href="{{ route($link['route']) }}">{{ $link['title'] }}</a>
    @endforeach
  </div>
</section>
@if(!$ready)
<section class="panel"><div class="admin-guidance">The additive M20 database schema has not been provisioned in this environment. Advertising cannot serve or accept changes until its migration is applied in an explicitly approved deployment.</div></section>
@else
@if(in_array($area,['creatives','campaigns'],true) && auth()->guard('admin')->user()?->canPerform('advertising.manage'))
  <datalist id="advertising-sponsor-choices">@foreach($sponsorChoices as $choice)<option value="{{ $choice->id }}">{{ $choice->display_name }}</option>@endforeach</datalist>
  <datalist id="advertising-creative-choices">@foreach($creativeChoices as $choice)<option value="{{ $choice->id }}">{{ $choice->title }}</option>@endforeach</datalist>
@endif
@if($area==='overview')
<div class="stat-grid">
  @foreach(['Sponsor records'=>$counts['sponsors'],'Verified sponsors'=>$counts['verified'],
    'Creative submissions'=>$counts['creatives'],'Awaiting creative review'=>$counts['awaiting_review'],
    'Campaigns'=>$counts['campaigns'],'Active campaign states'=>$counts['active']] as $label=>$value)
    <article class="stat-card"><span class="stat-label">{{ $label }}</span><strong>{{ number_format($value) }}</strong></article>
  @endforeach
</div>
<section class="panel">
  <div class="panel-head"><div><h2>Delivery posture &amp; privacy</h2></div></div>
  <div class="admin-kv">
    <div class="kv"><small>Module delivery configuration</small><strong>{{ config('orbit_advertising.enabled')?'Enabled by environment':'Disabled by default' }}</strong></div>
    <div class="kv"><small>Required rollout approvals</small><strong>{{ app(\App\Modules\Advertising\Services\AdvertisingPolicy::class)->policyApproved()?'All environment approvals present':'Not fully authorized' }}</strong></div>
    <div class="kv"><small>Serving eligible now</small><strong>{{ app(\App\Modules\Advertising\Services\AdvertisingPolicy::class)->available()?'Environment gate available; placement and sponsor ACL still required':'Disabled' }}</strong></div>
    <div class="kv"><small>Authorized UI surfaces</small><strong>Home promo card; Moments promo card (both individually disabled at migration)</strong></div>
    <div class="kv"><small>Member tracking</small><strong>Only signed decision receipts; aggregate counts. No personal profiles or GPS</strong></div>
    <div class="kv"><small>Excluded workflows</small><strong>Messages, SOS, notifications, Activity Feed and Subscription Billing</strong></div>
  </div>
</section>
@elseif($area==='sponsors')
<section class="panel">
  <div class="panel-head"><div><h2>Sponsor directory</h2><p>Draft sponsor records require independent verification. A suspended or archived sponsor immediately stops all delivery.</p></div></div>
  @include('orbit-admin.partials.advertising-filters', ['area'=>$area])

  @if(auth()->guard('admin')->user()?->canPerform('advertising.manage'))
  <form method="post" action="{{ route('orbit.admin.advertising.sponsors.create') }}">@csrf
    <label>Sponsor display name<input type="text" name="display_name" maxlength="150" required></label>
    <label>Legal name<input type="text" name="legal_name" maxlength="180" required></label>
    <label>Verified destination domain (hostname only)<input type="text" name="approved_domain" maxlength="190" required placeholder="sponsor.example"></label>
    <label>Confidential contact email (optional)<input type="email" name="contact" maxlength="190"></label>
    <label>Reason<input type="text" name="reason" minlength="10" maxlength="500" required></label>
    <label><input type="checkbox" name="confirm" value="1" required> Confirm sponsor creation</label>
    <button type="submit" class="btn primary">Create Sponsor Draft</button>
  </form>
  @endif
  <div class="table-scroll"><table>
    <thead><tr><th>SPONSOR</th><th>LEGAL NAME</th><th>APPROVED DOMAIN</th><th>STATUS</th><th>VERIFICATION</th><th>OPERATIONS</th></tr></thead>
    <tbody>@forelse($sponsors as $sponsor)
      <tr><td>{{ $sponsor->display_name }}<p><code>{{ $sponsor->id }}</code></p></td><td>{{ $sponsor->legal_name }}</td>
        <td>{{ $sponsor->approved_domain }}</td><td>{{ ucfirst($sponsor->status) }}</td>
        <td>{{ ucfirst($sponsor->verification_status) }}</td>
        <td>
          @if(auth()->guard('admin')->user()?->canPerform('advertising.manage') && $sponsor->status!=='archived')
          <details><summary>Edit Sponsor</summary>
          <form method="post" action="{{ route('orbit.admin.advertising.sponsors.edit',['sponsor'=>$sponsor->id]) }}">@csrf
            <label>Display name<input name="display_name" required maxlength="150" value="{{ $sponsor->display_name }}"></label>
            <label>Legal name<input name="legal_name" required maxlength="180" value="{{ $sponsor->legal_name }}"></label>
            <label>Destination domain<input name="approved_domain" required maxlength="190" value="{{ $sponsor->approved_domain }}"></label>
            <label>Additional encrypted contact email (optional)<input type="email" name="contact" maxlength="190"></label>
            <label>Reason<input name="reason" minlength="10" maxlength="500" required></label>
            <label><input type="checkbox" name="confirm" value="1" required> Confirm details update</label>
            <button class="btn secondary">Save Sponsor</button>
          </form></details>
          <form method="post" action="{{ route('orbit.admin.advertising.sponsors.status',['sponsor'=>$sponsor->id]) }}">@csrf
            <label>Action<select name="status" required>
              @foreach(['verified'=>'Verify independently','suspended'=>'Suspend','archived'=>'Archive'] as $code=>$label)
                @if($sponsor->status!==$code && ($code!=='verified' || $sponsor->created_by!==auth()->guard('admin')->id()))<option value="{{ $code }}">{{ $label }}</option>@endif
              @endforeach
            </select></label>
            <label>Reason<input type="text" name="reason" minlength="10" maxlength="500" required></label>
            <label><input type="checkbox" name="confirm" value="1" required> Confirm</label>
            <button class="btn secondary">Update Sponsor</button>
          </form>
          @else Read-only @endif
        </td>
      </tr>
    @empty<tr><td colspan="6">No sponsor records yet.</td></tr>@endforelse</tbody>
  </table></div>
  {{ $sponsors->links() }}
</section>
@elseif($area==='creatives')
<section class="panel">
  <div class="panel-head"><div><h2>Creative review queue</h2><p>Image assets are stored privately. Only reviewed exact revisions can be served; revisions invalidate campaign eligibility.</p></div></div>
  @include('orbit-admin.partials.advertising-filters', ['area'=>$area])

  @if(auth()->guard('admin')->user()?->canPerform('advertising.manage'))
  <form method="post" enctype="multipart/form-data" action="{{ route('orbit.admin.advertising.creatives.upload') }}">@csrf
    <label>Sponsor<input name="sponsor_id" list="advertising-sponsor-choices" maxlength="36" required placeholder="Sponsor UUID from directory"></label>
    <label>Headline<input type="text" name="title" maxlength="120" required></label>
    <label>Supporting text<input type="text" name="subtitle" maxlength="220" required></label>
    <label>HTTPS sponsor destination<input type="url" name="cta_url" maxlength="500" required></label>
    <label>Static approved image (JPEG, PNG or WebP)<input type="file" name="asset" accept="image/jpeg,image/png,image/webp" required></label>
    <label>Reason<input type="text" name="reason" minlength="10" maxlength="500" required></label>
    <label><input type="checkbox" name="confirm" value="1" required> Confirm private upload</label>
    <button class="btn primary">Submit Creative for Review</button>
  </form>
  @endif
  <div class="table-scroll"><table><thead>
    <tr><th>TITLE</th><th>SPONSOR</th><th>REVISION</th><th>STATE</th><th>ASSET</th><th>REVIEW</th><th>REVISION</th></tr></thead>
    <tbody>@forelse($creatives as $creative)
      <tr>
        <td>{{ $creative->title }}<p><code>{{ $creative->id }}</code></p><p>{{ $creative->subtitle }}</p><small>{{ $creative->cta_url }}</small></td>
        <td>{{ $creative->sponsor_name??'Unknown' }}</td>
        <td>{{ $creative->revision }}</td><td>{{ ucfirst($creative->status) }}</td>
        <td>{{ $creative->width }}×{{ $creative->height }} / {{ $creative->mime }}
          @if(auth()->guard('admin')->user()?->canPerform('advertising.review'))
            <p><a class="btn secondary" href="{{ route('orbit.admin.advertising.creatives.preview',['creative'=>$creative->id]) }}">Review Image</a></p>
          @endif
        </td>
        <td>
          @if($creative->status==='pending' && $creative->submitted_by!==auth()->guard('admin')->id() && auth()->guard('admin')->user()?->canPerform('advertising.review'))
          <form method="post" action="{{ route('orbit.admin.advertising.creatives.review',['creative'=>$creative->id]) }}">@csrf
            <input type="hidden" name="revision" value="{{ $creative->revision }}">
            <label>Decision<select name="action"><option value="approved">Approve</option><option value="rejected">Reject</option></select></label>
            <label>Review reason<input name="reason" required minlength="10" maxlength="500"></label>
            <label><input type="checkbox" name="confirm" value="1" required> Confirm</label>
            <button class="btn secondary" type="submit">Save Review</button>
          </form>
          @else {{ $creative->reviewed_at??'Awaiting review' }} @endif
        </td>
        <td>
          @if(auth()->guard('admin')->user()?->canPerform('advertising.manage'))
          <details><summary>Revise creative</summary>
            <form method="post" enctype="multipart/form-data" action="{{ route('orbit.admin.advertising.creatives.revise',['creative'=>$creative->id]) }}">@csrf
              <input type="hidden" name="sponsor_id" value="{{ $creative->sponsor_id }}">
              <label>Headline<input name="title" required maxlength="120" value="{{ $creative->title }}"></label>
              <label>Supporting text<input name="subtitle" required maxlength="220" value="{{ $creative->subtitle }}"></label>
              <label>HTTPS destination<input type="url" name="cta_url" required maxlength="500" value="{{ $creative->cta_url }}"></label>
              <label>Replacement image<input type="file" name="asset" required accept="image/jpeg,image/png,image/webp"></label>
              <label>Reason<input name="reason" required minlength="10" maxlength="500"></label>
              <label><input type="checkbox" name="confirm" value="1" required> Confirm new revision</label>
              <button class="btn secondary">Submit Revision</button>
            </form>
          </details>
          @else Read-only @endif
        </td>
      </tr>
    @empty<tr><td colspan="7">No creative submissions.</td></tr>@endforelse</tbody></table></div>
  {{ $creatives->links() }}
</section>
@elseif($area==='campaigns')
<section class="panel">
  <div class="panel-head"><div><h2>Campaign management</h2><p>Separate create, submit, independent approval and activation. Active status never bypasses the verified sponsor, approved creative, schedule, domain or placement gates.</p></div></div>
  @include('orbit-admin.partials.advertising-filters', ['area'=>$area])

  @if(auth()->guard('admin')->user()?->canPerform('advertising.manage'))
  <form method="post" action="{{ route('orbit.admin.advertising.campaigns.create') }}">@csrf
    <label>Campaign name<input name="name" required maxlength="120"></label>
    <label>Sponsor<input name="sponsor_id" list="advertising-sponsor-choices" maxlength="36" required placeholder="Sponsor UUID from directory"></label>
    <label>Approved creative<input name="creative_id" list="advertising-creative-choices" maxlength="36" required placeholder="Creative UUID from directory"></label>
    <label>Start UTC<input name="starts_at" type="datetime-local" required></label>
    <label>End UTC<input name="ends_at" type="datetime-local" required></label>
    <label>Priority (1–10)<input name="priority" type="number" min="1" max="10" value="5" required></label>
    <label><input type="checkbox" name="placements[]" value="home.promo"> Home promo slot</label>
    <label><input type="checkbox" name="placements[]" value="moments.promo"> Moments promo slot</label>
    <label>Reason<input name="reason" required minlength="10" maxlength="500"></label>
    <label><input type="checkbox" name="confirm" value="1" required> Confirm draft creation</label>
    <button class="btn primary">Create Campaign Draft</button>
  </form>
  @endif
  <div class="table-scroll"><table><thead><tr><th>CAMPAIGN</th><th>STATE</th><th>SCHEDULE (UTC)</th><th>CREATIVE</th><th>REVISION</th><th>OPERATIONS</th></tr></thead>
    <tbody>@forelse($campaigns as $campaign)
    <tr><td>{{ $campaign->name }}<p><code>{{ $campaign->id }}</code></p><p>{{ $campaign->sponsor_name }}</p><p>Priority {{ $campaign->priority }}</p></td>
      <td>{{ ucfirst($campaign->status) }}</td>
      <td>{{ $campaign->starts_at }} – {{ $campaign->ends_at }}</td>
      <td>{{ $campaign->creative_title??'Unavailable' }} (r{{ $campaign->creative_revision }})</td>
      <td>{{ $campaign->revision }}</td>
      <td>
        @php
          $states=['draft'=>['pending','archived'],'pending'=>['draft','approved','rejected','archived'],
            'approved'=>['scheduled','active','archived'],'scheduled'=>['active','paused','completed','archived'],
            'active'=>['paused','completed'],'paused'=>['scheduled','active','completed','archived'],
            'rejected'=>['draft','archived'],'completed'=>['archived'],'archived'=>[]];
          $allowed=array_filter($states[$campaign->status]??[],
            fn($next)=>($next!=='approved' || $campaign->created_by!==auth()->guard('admin')->id()) && auth()->guard('admin')->user()?->canPerform(in_array($next,['approved','rejected'],true)?'advertising.review':'advertising.manage'));
        @endphp
        @if($allowed)
        <form method="post" action="{{ route('orbit.admin.advertising.campaigns.transition',['campaign'=>$campaign->id]) }}">@csrf
          <input type="hidden" name="revision" value="{{ $campaign->revision }}">
          <label>Next state<select name="status">
            @foreach($allowed as $next)<option value="{{ $next }}">{{ ucfirst($next) }}</option>@endforeach
          </select></label>
          <label>Reason<input name="reason" minlength="10" maxlength="500" required></label>
          <label><input type="checkbox" name="confirm" value="1" required> Confirm change</label>
          <button class="btn secondary">Transition</button>
        </form>
        @else No actions @endif
        @if($campaign->status!=='archived' && auth()->guard('admin')->user()?->canPerform('advertising.manage'))
          <details><summary>Edit Campaign (resets approval)</summary>
          <form method="post" action="{{ route('orbit.admin.advertising.campaigns.edit',['campaign'=>$campaign->id]) }}">@csrf
            <input type="hidden" name="revision" value="{{ $campaign->revision }}">
            <label>Name<input name="name" required maxlength="120" value="{{ $campaign->name }}"></label>
            <label>Sponsor<input name="sponsor_id" list="advertising-sponsor-choices" maxlength="36" required value="{{ $campaign->sponsor_id }}" placeholder="Sponsor UUID from directory"></label>
            <label>Approved creative<input name="creative_id" list="advertising-creative-choices" maxlength="36" required value="{{ $campaign->creative_id }}" placeholder="Creative UUID from directory"></label>
            <label>Start UTC<input name="starts_at" type="datetime-local" value="{{ \Illuminate\Support\Carbon::parse($campaign->starts_at)->format('Y-m-dTH:i') }}" required></label>
            <label>End UTC<input name="ends_at" type="datetime-local" value="{{ \Illuminate\Support\Carbon::parse($campaign->ends_at)->format('Y-m-dTH:i') }}" required></label>
            <label>Priority<input name="priority" type="number" min="1" max="10" required value="{{ $campaign->priority }}"></label>
            <label><input type="checkbox" name="placements[]" value="home.promo" @checked(($campaignPlacements->get($campaign->id)??collect())->contains("placement_key","home.promo"))> Home promo</label>
            <label><input type="checkbox" name="placements[]" value="moments.promo" @checked(($campaignPlacements->get($campaign->id)??collect())->contains("placement_key","moments.promo"))> Moments promo</label>
            <label>Reason<input name="reason" required minlength="10" maxlength="500"></label>
            <label><input type="checkbox" name="confirm" value="1" required> Invalidate approval and save new draft</label>
            <button class="btn secondary">Save Revised Campaign</button>
          </form></details>
        @endif
      </td>
    </tr>
    @empty<tr><td colspan="6">No campaigns created.</td></tr>@endforelse</tbody></table></div>
  {{ $campaigns->links() }}
</section>
@elseif($area==='placements')
<section class="panel">
  <div class="panel-head"><div><h2>Placement allowlist &amp; frequency policy</h2><p>Only the two approved UI component types may be configured. Actual delivery also requires the independently approved environment flags.</p></div></div>
  <div class="table-scroll"><table><thead><tr><th>SLOT</th><th>EXISTING COMPONENT</th><th>STATE</th><th>CAPS</th><th>REVISION</th><th>CONTROL</th></tr></thead>
  <tbody>@foreach($slots as $slot)
    <tr><td>{{ $slot->key }}</td><td>{{ $slot->component_name }} / Sponsored</td>
      <td>{{ $slot->enabled?'Enabled':'Disabled' }}</td>
      <td>{{ $slot->daily_decision_cap??'Unset' }} decisions / {{ $slot->daily_impression_cap??'Unset' }} impressions per day</td>
      <td>{{ $slot->rule_version }}</td>
      <td>@if(auth()->guard('admin')->user()?->canPerform('advertising.manage'))
        <form method="post" action="{{ route('orbit.admin.advertising.placements.control',['placement'=>$slot->key]) }}">@csrf
          <input name="rule_version" type="hidden" value="{{ $slot->rule_version }}">
          <label>Status<select name="enabled"><option value="0">Disabled</option><option value="1" @selected($slot->enabled)>Enabled (only if approved)</option></select></label>
          <label>Daily decisions cap<input type="number" name="daily_decision_cap" min="1" max="{{ config('orbit_advertising.max_cap_per_day') }}" value="{{ $slot->daily_decision_cap }}"></label>
          <label>Daily impressions cap<input type="number" name="daily_impression_cap" min="1" max="{{ config('orbit_advertising.max_cap_per_day') }}" value="{{ $slot->daily_impression_cap }}"></label>
          <label>Policy justification<input name="reason" minlength="10" maxlength="500" required></label>
          <label><input type="checkbox" name="confirm" value="1" required> Confirm controls</label>
          <button class="btn secondary">Save Placement</button>
        </form>
      @else Read-only @endif</td>
    </tr>
  @endforeach</tbody></table></div>
</section>
@elseif($area==='analytics')
<section class="panel">
  <div class="panel-head"><div><h2>Aggregate performance</h2><p>Counts are produced only from signed, accepted measurement receipts. No individual member logs, identifiers, device information or location data.</p></div></div>
  @include('orbit-admin.partials.advertising-filters', ['area'=>$area])

  <div class="stat-grid">
    <article class="stat-card"><span class="stat-label">Verified impressions</span><strong>{{ number_format($counts['impressions']) }}</strong></article>
    <article class="stat-card"><span class="stat-label">Verified clicks</span><strong>{{ number_format($counts['clicks']) }}</strong></article>
  </div>
  <div class="table-scroll"><table><thead><tr><th>DAY (UTC)</th><th>CAMPAIGN REF</th><th>PLACEMENT</th><th>IMPRESSIONS</th><th>CLICKS</th></tr></thead>
  <tbody>@forelse($daily as $entry)
    <tr><td>{{ $entry->day_utc }}</td><td><code>{{ substr($entry->campaign_id,0,8) }}…</code></td>
      <td>{{ $entry->placement_key }}</td><td>{{ number_format($entry->impressions) }}</td><td>{{ number_format($entry->clicks) }}</td></tr>
  @empty<tr><td colspan="5">No signed measurement events accepted.</td></tr>@endforelse</tbody></table></div>
  {{ $daily->links() }}
</section>
@elseif($area==='audits')
<section class="panel">
  <div class="panel-head"><div><h2>Administrative audit trail</h2><p>Restricted to advertising.audit. All mutations retain actor, subject, reason, previous state and new state.</p></div></div>
  @include('orbit-admin.partials.advertising-filters', ['area'=>$area])

  <div class="table-scroll"><table><thead><tr><th>WHEN</th><th>ACTOR</th><th>ACTION</th><th>SUBJECT</th><th>OLD</th><th>NEW</th><th>REASON</th></tr></thead>
    <tbody>@forelse($audits as $audit)
      <tr><td>{{ $audit->created_at }}</td><td><code>{{ substr($audit->admin_id,0,8) }}…</code></td>
        <td>{{ $audit->action }}</td><td>{{ $audit->subject_type }} / {{ $audit->subject_id }}</td>
        <td>{{ $audit->old_state??'—' }}</td><td>{{ $audit->new_state??'—' }}</td>
        <td>{{ $audit->reason }}</td></tr>
    @empty<tr><td colspan="7">No advertising administration changes.</td></tr>@endforelse</tbody></table></div>
  {{ $audits->links() }}
</section>
@endif
@endif
@endsection
