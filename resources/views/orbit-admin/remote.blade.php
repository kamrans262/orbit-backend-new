@extends('orbit-admin.layout')
@section('title', $config?->exists ? 'Edit remote config' : 'New remote config')
@section('crumb', 'REMOTE CONFIG')
@section('content')
<a class="back" href="{{ route('orbit.admin.dashboard') }}">← Back to configuration</a>
<div class="page-heading"><div><p class="eyebrow">APPLICATION SETTINGS</p><h1>{{ $config?->exists ? 'Manage remote configuration' : 'Create remote configuration' }}</h1><p>Publish validated, versioned settings without a new mobile app release.</p></div></div>
<div class="form-layout">
<section class="panel form-panel">
<form method="post" action="{{ $config?->exists ? route('orbit.admin.config.update',$config) : route('orbit.admin.config.store') }}">@csrf
<div class="form-grid">
<label>Configuration key <input name="key" required pattern="[a-z][a-z0-9_.-]*" maxlength="120" value="{{ old('key',$config?->key ?? '') }}" {{ $config?->exists ? 'readonly' : '' }} placeholder="location.refresh_interval"></label>
<label>JSON schema type <select name="value_type" required>@foreach(['string','number','boolean','array','object'] as $type)<option value="{{ $type }}" {{ old('value_type',$config?->value_type ?? 'object') === $type ? 'selected' : '' }}>{{ ucfirst($type) }}</option>@endforeach</select></label>
<label class="span2">Description <input name="description" maxlength="500" value="{{ old('description',$config?->description ?? '') }}"></label>
<label class="span2">JSON value <textarea name="value_json" required rows="8" spellcheck="false" class="code-input">{{ old('value_json',$config?->exists ? json_encode($config->value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) : '{}') }}</textarea></label>
<div class="form-field span2"><div class="checkbox-row">
<label class="check"><input type="checkbox" name="published" value="1" {{ old('published',$config?->published ?? false) ? 'checked' : '' }}>Published</label>
<label class="check"><input type="checkbox" name="client_visible" value="1" {{ old('client_visible',$config?->client_visible ?? false) ? 'checked' : '' }}>Visible in Flutter bootstrap</label>
</div></div>
<label class="span2">Audit reason (required) <textarea name="reason" rows="3" required minlength="5" maxlength="500" placeholder="Purpose, authorization and expected impact">{{ old('reason') }}</textarea></label>
</div>
<div class="form-actions"><a class="btn secondary" href="{{ route('orbit.admin.dashboard') }}">Cancel</a><button type="submit" class="btn primary">{{ $config?->exists ? 'Save new revision' : 'Create config' }} →</button></div>
</form></section>
<aside class="form-aside"><div class="info-card"><h3>Configuration visibility</h3><p>Drafts and server-only settings are excluded from the public bootstrap endpoint. Never place passwords, API keys or service credentials in client-visible settings.</p></div>
@if($config?->exists)<div class="info-card"><h3>Revision history</h3><p>Restore a previous snapshot via a new audited revision.</p>
@forelse($revisions as $rev)<div class="revision"><div><strong>Version {{ $rev->version }}</strong><small>{{ $rev->created_at }}</small><small>{{ $rev->reason }}</small></div>
<form method="post" action="{{ route('orbit.admin.rollback',['type'=>'remote','key'=>$config->key]) }}" data-confirm="Restore this version as a new audited revision?">@csrf<input type="hidden" name="revision" value="{{ $rev->id }}"><input type="hidden" name="confirm" value="1"><input name="reason" required minlength="5" maxlength="500" placeholder="Rollback reason"><button class="text-action">Restore</button></form></div>
@empty<p>No revisions yet.</p>@endforelse</div>@endif
</aside>
</div>
@endsection
