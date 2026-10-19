@extends('orbit-admin.layout')
@section('title', 'Feature Flags')
@section('crumb', 'CONFIGURATIONS / FEATURE FLAGS')
@section('content')
<div class="page-heading"><div><p class="eyebrow">CONFIGURATIONS / RELEASE CONTROL</p><h1>Feature Flags</h1><p>Controlled rollouts, minimum app versions and emergency kill switches.</p></div>
<div class="heading-actions"><a class="btn primary" href="{{ route('orbit.admin.flags.new') }}">+ New feature flag</a></div></div>
<section class="panel"><div class="panel-head"><div><h2>Configured flags</h2><p>Changes require a documented reason and produce an audited revision.</p></div></div>
<div class="table-scroll"><table><thead><tr><th>KEY</th><th>STATE</th><th>ROLLOUT</th><th>VERSION</th><th></th></tr></thead><tbody>
@forelse($flags as $flag)
<tr><td><strong>{{ $flag->key }}</strong><small>{{ $flag->description }}</small></td>
<td><span class="pill {{ $flag->kill_switch ? 'danger' : ($flag->enabled ? 'success' : 'neutral') }}">{{ $flag->kill_switch ? 'Killed' : ($flag->enabled ? 'Enabled' : 'Disabled') }}</span></td>
<td>{{ $flag->rollout_percentage }}%</td><td>{{ $flag->version }}</td>
<td><a href="{{ route('orbit.admin.flags.edit', $flag) }}">Manage →</a></td></tr>
@empty<tr><td colspan="5"><div class="empty-state"><strong>No feature flags</strong><p>Create an approved flag to configure your first rollout.</p></div></td></tr>@endforelse
</tbody></table></div><div class="admin-pagination">{{ $flags->links() }}</div></section>
@endsection
