@extends('orbit-admin.layout')
@section('title', 'Configuration overview')
@section('content')
<div class="page-heading">
  <div><p class="eyebrow">PLATFORM OPERATIONS / SETTINGS</p><h1>Platform configuration</h1><p>Control release flags and public app configuration with tracked revisions.</p></div>
  <div class="heading-actions"><a class="btn secondary" href="{{ route('orbit.admin.config.new') }}">+ Remote config</a><a class="btn primary" href="{{ route('orbit.admin.flags.new') }}">+ Feature flag</a></div>
</div>
<div class="stat-grid">
  <article class="stat-card"><span class="stat-label">Feature flags</span><strong>{{ $flags->count() }}</strong><p>Configured release controls</p><span class="stat-icon violet">◇</span></article>
  <article class="stat-card"><span class="stat-label">Flags enabled</span><strong>{{ $flags->filter(fn ($f) => $f->enabled && ! $f->kill_switch)->count() }}</strong><p>Before targeting &amp; rollout checks</p><span class="stat-icon blue">↗</span></article>
  <article class="stat-card"><span class="stat-label">Emergency kill switches</span><strong>{{ $flags->where('kill_switch',true)->count() }}</strong><p>Currently forcing features off</p><span class="stat-icon red">!</span></article>
  <article class="stat-card"><span class="stat-label">Published configs</span><strong>{{ $configs->where('published',true)->count() }}</strong><p>Versioned and validated</p><span class="stat-icon green">⚙</span></article>
</div>
<div class="two-col">
  <section class="panel">
    <div class="panel-head"><div><p class="eyebrow">RELEASE CONTROL</p><h2>Feature flags</h2><p>Changes require a documented reason.</p></div><a href="{{ route('orbit.admin.flags.new') }}">Create flag →</a></div>
    <div class="table-scroll"><table><thead><tr><th>FLAG</th><th>STATE</th><th>ROLLOUT</th><th>VERSION</th><th></th></tr></thead><tbody>
    @forelse($flags as $flag)<tr>
      <td><strong>{{ $flag->key }}</strong><small>{{ $flag->description }}</small></td>
      <td>@if($flag->kill_switch)<span class="pill danger">Killed</span>@elseif($flag->enabled)<span class="pill success">Enabled</span>@else<span class="pill neutral">Disabled</span>@endif</td>
      <td>{{ $flag->rollout_percentage }}%</td><td>v{{ $flag->version }}</td>
      <td><a href="{{ route('orbit.admin.flags.edit', $flag) }}">Manage →</a></td></tr>
    @empty<tr><td colspan="5"><div class="empty-state"><span>◇</span><strong>No feature flags yet</strong><p>No custom rollout flags have been created. Core feature availability is controlled separately by server configuration.</p></div></td></tr>@endforelse
    </tbody></table></div>
  </section>
  <section class="panel">
    <div class="panel-head"><div><p class="eyebrow">MOBILE BOOTSTRAP</p><h2>Remote configuration</h2><p>Only published, client-visible values reach Flutter.</p></div><a href="{{ route('orbit.admin.config.new') }}">Create config →</a></div>
    <div class="table-scroll"><table><thead><tr><th>CONFIG KEY</th><th>VISIBILITY</th><th>STATE</th><th></th></tr></thead><tbody>
    @forelse($configs as $config)<tr><td><strong>{{ $config->key }}</strong><small>{{ $config->value_type }} · v{{ $config->version }}</small></td>
      <td>{{ $config->client_visible ? 'Client-visible' : 'Server-only' }}</td>
      <td><span class="pill {{ $config->published ? 'success' : 'neutral' }}">{{ $config->published ? 'Published' : 'Draft' }}</span></td>
      <td><a href="{{ route('orbit.admin.config.edit', $config) }}">Manage →</a></td></tr>
    @empty<tr><td colspan="4"><div class="empty-state"><span>⚙</span><strong>No configurations yet</strong><p>App settings will appear after approval and publication.</p></div></td></tr>@endforelse
    </tbody></table></div>
  </section>
</div>
<section class="panel audit-panel">
  <div class="panel-head"><div><p class="eyebrow">ACCOUNTABILITY</p><h2>Recent configuration actions</h2><p>Logged by administrator, target and reason.</p></div><span class="subtle">Latest 8</span></div>
  <div class="table-scroll"><table><thead><tr><th>ACTION</th><th>TARGET</th><th>JUSTIFICATION</th><th>TIME (UTC)</th></tr></thead><tbody>
    @forelse($audits as $audit)<tr><td><span class="pill neutral">{{ $audit->action }}</span></td><td>{{ $audit->target }}</td><td>{{ $audit->reason }}</td><td>{{ $audit->created_at }}</td></tr>
    @empty<tr><td colspan="4" class="empty-cell">No audited configuration actions yet.</td></tr>@endforelse
  </tbody></table></div>
</section>
@endsection
