<form class="admin-filters" method="get" action="{{ route('orbit.admin.advertising.'.$area) }}">
  @if(in_array($area,['sponsors','creatives','campaigns'],true))
    @php $states=match($area){'sponsors'=>['draft','verified','suspended','archived'],'creatives'=>['pending','approved','rejected'],default=>['draft','pending','approved','rejected','scheduled','active','paused','completed','archived']}; @endphp
    <label>Search<input name="query" maxlength="150" value="{{ request('query') }}" placeholder="Name or full UUID"></label>
    <label>Status<select name="status"><option value="">All states</option>@foreach($states as $state)<option value="{{ $state }}" @selected(request('status')===$state)>{{ ucfirst($state) }}</option>@endforeach</select></label>
  @else
    <label>From (UTC)<input name="from" type="date" value="{{ request('from') }}"></label>
    <label>To (UTC)<input name="to" type="date" value="{{ request('to') }}"></label>
    @if($area==='analytics')<label>Campaign UUID<input name="campaign_id" maxlength="36" value="{{ request('campaign_id') }}"></label>@endif
  @endif
  <button class="btn primary" type="submit">Filter</button>
  <a class="btn secondary" href="{{ route('orbit.admin.advertising.'.$area) }}">Reset</a>
</form>
@if(in_array($area,['creatives','campaigns'],true) && auth()->guard('admin')->user()?->canPerform('advertising.manage'))
<p>Use the existing <a href="{{ route('orbit.admin.advertising.sponsors') }}">Sponsor directory</a> and <a href="{{ route('orbit.admin.advertising.creatives') }}">Creative directory</a> to find any eligible record UUID. Recent suggestions are optional.</p>
@endif
