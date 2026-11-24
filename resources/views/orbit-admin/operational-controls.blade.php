@extends('orbit-admin.layout')
@section('title',$definition[0].' Settings')
@section('crumb',strtoupper($definition[0]).' / SETTINGS')
@section('content')
<div class="page-heading"><div><p class="eyebrow">OPERATIONS / SETTINGS</p><h1>{{ $definition[0] }} Settings</h1><p>Manage supported operational limits. Existing user permissions and deployment safety gates remain in effect.</p></div></div>
<section class="panel"><div class="panel-head"><div><h2>Settings &amp; Controls</h2><p>Policy revision {{ $settings['revision'] }} · effective on subsequent requests</p></div></div>
@if(!$controls->installed())
<div class="admin-guidance">Configuration storage has not been provisioned.</div>
@elseif(auth()->guard('admin')->user()?->canPerform($definition[2]))
<form method="post" action="{{ route('orbit.admin.operations.'.$module.'.save') }}">@csrf
<input type="hidden" name="revision" value="{{ $settings['revision'] }}">
<div class="form-grid">
@foreach($definition[3] as $key=>$field)
<label>{{ $field[1] }}<input type="number" step="1" name="values[{{ $key }}]" min="{{ $field[2] }}" max="{{ $controls->ceiling($field) }}" value="{{ old('values.'.$key,$settings['values'][$key]) }}" required></label>
@endforeach
<label>Documented reason<textarea name="reason" minlength="10" maxlength="500" required></textarea></label>
<label><input type="checkbox" name="confirm" value="1" required> Confirm these operational changes</label>
</div><div class="form-actions"><button class="btn primary" type="submit">Save Controls</button></div></form>
@else
<div class="admin-kv">@foreach($definition[3] as $key=>$field)<div class="kv"><small>{{ $field[1] }}</small><strong>{{ number_format($settings['values'][$key]) }}</strong></div>@endforeach</div>
@endif
<div class="admin-guidance">Limits cannot exceed the approved deployment ceiling. They do not activate unavailable infrastructure, extend retention, approve consent or expose private content.</div>
</section>
<section class="panel"><div class="panel-head"><h2>Configuration History</h2></div><div class="table-scroll"><table><thead><tr><th>REVISION</th><th>ACTOR</th><th>REASON</th><th>DATE</th></tr></thead><tbody>
@forelse($history as $row)<tr><td>{{ $row->version }}</td><td>{{ $row->actor_id }}</td><td>{{ $row->reason }}</td><td>{{ $row->created_at }}</td></tr>@empty<tr><td colspan="4">No recorded changes.</td></tr>@endforelse
</tbody></table></div></section>
@endsection
