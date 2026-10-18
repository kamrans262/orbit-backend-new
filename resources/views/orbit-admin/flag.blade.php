@extends('orbit-admin.layout')
@section('title', $flag?->exists ? 'Edit feature flag' : 'New feature flag')
@section('crumb', 'FEATURE FLAGS')
@section('content')
<a class="back" href="{{ route('orbit.admin.dashboard') }}">← Back to configuration</a>
<div class="page-heading"><div><p class="eyebrow">RELEASE CONTROL</p><h1>{{ $flag?->exists ? 'Manage feature flag' : 'Create feature flag' }}</h1><p>Audience constraints and emergency disable take effect immediately for new requests.</p></div></div>
<div class="form-layout">
  <section class="panel form-panel"><form method="post" action="{{ $flag?->exists ? route('orbit.admin.flags.update',$flag) : route('orbit.admin.flags.store') }}">@csrf
  <div class="form-grid">
    <label>Feature key <input name="key" type="text" required maxlength="120" pattern="[a-z][a-z0-9_.-]*" value="{{ old('key',$flag?->key ?? '') }}" {{ $flag?->exists ? 'readonly' : '' }} placeholder="relay.beta"></label>
    <label>Rollout percentage <input name="rollout_percentage" required type="number" min="0" max="100" value="{{ old('rollout_percentage',$flag?->rollout_percentage ?? 0) }}"></label>
    <label class="span2">Description <input name="description" maxlength="500" value="{{ old('description',$flag?->description ?? '') }}" placeholder="Purpose of this flag"></label>
    <label>Minimum app version <input name="min_version" value="{{ old('min_version',$flag?->min_version ?? '') }}" placeholder="1.0.0"></label>
    <div class="form-field"><span class="input-label">Platforms</span><div class="checkbox-row">
      @foreach(['ios'=>'iOS','android'=>'Android','web'=>'Web'] as $value => $name)
        <label class="check"><input type="checkbox" name="platforms[]" value="{{ $value }}" {{ in_array($value,old('platforms',$flag?->platforms ?? [])) ? 'checked' : '' }}>{{ $name }}</label>
      @endforeach
    </div></div>
    <label>Countries (ISO 2-letter, comma-separated) <input name="countries" value="{{ old('countries',implode(',',$flag?->countries ?? [])) }}" placeholder="PK,US"></label>
    <label>Cohorts (comma-separated) <input name="cohorts" value="{{ old('cohorts',implode(',',$flag?->cohorts ?? [])) }}" placeholder="beta,internal"></label>
    <div class="form-field span2"><div class="checkbox-row">
      <label class="check"><input type="checkbox" name="enabled" value="1" {{ old('enabled',$flag?->enabled ?? false) ? 'checked' : '' }}>Enabled</label>
      <label class="check critical"><input type="checkbox" name="kill_switch" value="1" {{ old('kill_switch',$flag?->kill_switch ?? false) ? 'checked' : '' }}>Emergency kill switch (force off)</label>
    </div></div>
    <label class="span2">Audit reason (required) <textarea required minlength="5" maxlength="500" name="reason" rows="3" placeholder="Explain the rollout/change and approval">{{ old('reason') }}</textarea></label>
  </div><div class="form-actions"><a class="btn secondary" href="{{ route('orbit.admin.dashboard') }}">Cancel</a><button class="btn primary" type="submit">{{ $flag?->exists ? 'Save new revision' : 'Create flag' }} →</button></div>
  </form></section>
  <aside class="form-aside">
    <div class="info-card"><span class="info-icon">ⓘ</span><h3>Safety and permissions</h3><p>Feature flags affect client presentation only. Server APIs must still enforce privacy, entitlement, SOS and role permissions independently.</p></div>
    @if($flag?->exists)
      <div class="info-card"><h3>Preview rollout</h3><form method="get" action="{{ route('orbit.admin.flags.preview', $flag) }}"><label>Platform<select name="platform"><option value="ios">iOS</option><option value="android">Android</option><option value="web">Web</option></select></label><label>App version<input name="app_version" value="1.0.0" required></label><label>Country<input name="country" value="PK" maxlength="2" required></label><label>Cohort<input name="cohort" placeholder="optional"></label><label>Subject UUID<input name="subject" placeholder="for percentage rollout"></label><button class="btn secondary full">Preview eligibility</button></form></div>
      <div class="info-card"><h3>Revision history</h3><p>Rollback creates another audited revision; it never deletes history.</p>
      @forelse($revisions as $rev)<div class="revision"><div><strong>Version {{ $rev->version }}</strong><small>{{ $rev->created_at }}</small><small>{{ $rev->reason }}</small></div>
        <form method="post" action="{{ route('orbit.admin.rollback',['type'=>'flag','key'=>$flag->key]) }}" data-confirm="Create an audited rollback revision?">@csrf<input type="hidden" name="revision" value="{{ $rev->id }}"><input type="hidden" name="confirm" value="1"><input name="reason" required minlength="5" maxlength="500" placeholder="Reason for rollback"><button class="text-action">Restore</button></form></div>
      @empty<p>No revisions yet.</p>@endforelse
      </div>
    @endif
  </aside>
</div>
@endsection
