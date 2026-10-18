@extends('orbit-admin.layout')
@section('title', 'Flag preview')
@section('crumb', 'FLAG PREVIEW')
@section('content')
<a class="back" href="{{ route('orbit.admin.flags.edit',$flag) }}">← Back to feature flag</a>
<div class="page-heading"><div><p class="eyebrow">NON-MUTATING PREVIEW</p><h1>{{ $flag->key }}</h1><p>Preview for the selected target context. No changes have been published.</p></div></div>
<div class="panel form-panel preview-panel">
<p class="eyebrow">EVALUATED RESULT</p><strong class="preview-result {{ $eligible ? 'on' : 'off' }}">{{ $eligible ? 'ELIGIBLE' : 'DISABLED' }}</strong>
<div class="preview-data">@foreach($context as $label => $value)<div><span>{{ str_replace('_',' ',$label) }}</span><strong>{{ $value ?: '—' }}</strong></div>@endforeach</div>
<p class="subtle">This is a presentation/rollout decision, not proof of entitlement or server authorization.</p>
</div>
@endsection
