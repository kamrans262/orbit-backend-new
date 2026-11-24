@extends('orbit-admin.layout')
@section('title','Camera & Editing')
@section('crumb') CAMERA &amp; EDITING @endsection
@section('content')
<div class="page-heading"><div><p class="eyebrow">CAPTURE &amp; MEDIA</p><h1>Camera &amp; Editing</h1><p>Manage capture limits and on-device editing policy. Existing private media and Moment operations remain unchanged.</p></div></div>
@if(!$ready)
<section class="panel"><div class="admin-guidance">Camera policy is not provisioned. Existing media defaults remain in effect.</div></section>
@else
<section class="panel"><div class="panel-head"><div><h2>Settings &amp; Controls</h2><p>Policy revision {{ $settings['revision'] }} · Encrypted media {{ $mediaAvailable?'available':'disabled' }} · Moments {{ $momentsAvailable?'available':'disabled' }}</p></div></div>
<div class="admin-guidance">Limits apply to declared Moment media. The server cannot inspect private codecs, pixels, audio or location metadata. Keep enhanced validation disabled until the compatible mobile release is approved.</div>
@php
$labels=[
'photo_long_edge_px'=>'Photo maximum long edge (px)',
'photo_data_saver_long_edge_px'=>'Photo data saver long edge (px)',
'photo_jpeg_quality'=>'JPEG quality',
'video_duration_ms'=>'Video maximum duration (ms)',
'video_max_long_edge_px'=>'Video maximum long edge (px)',
'video_max_fps'=>'Video maximum frames per second',
'video_target_bps'=>'Video target bitrate (bps)',
'video_data_saver_target_bps'=>'Video data saver bitrate (bps)',
'voice_duration_ms'=>'Voice maximum duration (ms)',
];
@endphp
@if(auth()->guard('admin')->user()?->canPerform('camera.manage'))
<form method="post" action="{{ route('orbit.admin.camera.settings.save') }}">@csrf
<input type="hidden" name="revision" value="{{ $settings['revision'] }}">
<label>Enhanced capture and editing policy<select name="enhancements_enabled"><option value="0" @selected(!$settings['enhancements_enabled'])>Disabled — compatible baseline</option><option value="1" @selected($settings['enhancements_enabled'])>Enabled — strict declarations</option></select></label>
@foreach($ranges as $key=>$range)
<label>{{ $labels[$key] }}<input type="number" name="{{ $key }}" min="{{ $range[0] }}" max="{{ $range[1] }}" step="1" value="{{ old($key,$settings[$key]) }}" required></label>
@endforeach
<div class="admin-kv"><div class="kv"><small>Voice encoding policy</small><strong>AAC-LC · mono · 64 kbps</strong></div></div>
<label>Reason<input name="reason" minlength="10" maxlength="500" required></label>
<label><input type="checkbox" name="confirm" value="1" required> Confirm policy change</label>
<button class="btn primary" type="submit">Save Controls</button>
</form>
@else
<div class="admin-kv"><div class="kv"><small>Enhanced policy</small><strong>{{ $settings['enhancements_enabled']?'Enabled':'Disabled' }}</strong></div>
@foreach($ranges as $key=>$range)<div class="kv"><small>{{ $labels[$key] }}</small><strong>{{ number_format($settings[$key]) }}</strong></div>@endforeach
<div class="kv"><small>Voice encoding policy</small><strong>AAC-LC · mono · 64 kbps</strong></div></div>
@endif
</section>
<section class="panel"><div class="panel-head"><div><h2>Configuration History</h2><p>Latest 30 policy revisions. Administrative metadata only.</p></div></div>
<div class="table-scroll"><table><thead><tr><th>REVISION</th><th>ACTOR</th><th>REASON</th><th>DATE</th></tr></thead><tbody>
@forelse($audits as $audit)<tr><td>{{ $audit->version }}</td><td>{{ $audit->actor_id }}</td><td>{{ $audit->reason }}</td><td>{{ $audit->created_at }}</td></tr>
@empty<tr><td colspan="4">No administrative changes recorded.</td></tr>@endforelse
</tbody></table></div></section>
@endif
<section class="panel"><div class="panel-head"><div><h2>Approved editing tools</h2><p>Catalog revision {{ $tools['revision'] }} · Existing local filters and stickers only. Requires compatible app integration; no access to captured content.</p></div></div>
@if(auth()->guard('admin')->user()?->canPerform('camera.manage'))
<form method="post" action="{{ route('orbit.admin.camera.tools.save') }}">@csrf
<input type="hidden" name="revision" value="{{ $tools['revision'] }}"><input type="hidden" name="filters[]" value="natural">
<div class="form-grid"><div><h3>Photo filters</h3><label>Natural (always available)</label>
@foreach(\App\Modules\Camera\Services\CameraToolCatalog::FILTERS as $filter)@if($filter!=='natural')
<label><input type="checkbox" name="filters[]" value="{{ $filter }}" @checked(in_array($filter,$tools['filters'],true))>{{ $filter==='blackAndWhite'?'Black & white':ucfirst($filter) }}</label>
@endif @endforeach</div><div><h3>Sticker collection</h3>
@foreach(\App\Modules\Camera\Services\CameraToolCatalog::STICKERS as $sticker)<label><input type="checkbox" name="stickers[]" value="{{ $sticker }}" @checked(in_array($sticker,$tools['stickers'],true))>{{ $sticker }}</label>@endforeach
</div><label>Reason<input name="reason" minlength="10" maxlength="500" required></label><label><input type="checkbox" name="confirm" value="1" required> Confirm supported catalog</label></div><button class="btn primary">Save editing tools</button></form>
@else
<div class="admin-kv"><div class="kv"><small>Filters</small><strong>{{ implode(', ',$tools['filters']) }}</strong></div><div class="kv"><small>Stickers</small><strong>{{ implode(' ',$tools['stickers']) ?: 'None' }}</strong></div></div>
@endif
<div class="admin-guidance">This catalog is a presentation policy. Laravel cannot inspect private encrypted captures or determine whether an on-device filter was applied. No AR, sponsorship or paid activation.</div>
</section>
@endsection
