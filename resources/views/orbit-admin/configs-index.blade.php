@extends('orbit-admin.layout')
@section('title', 'Remote Config')
@section('crumb', 'CONFIGURATIONS / REMOTE CONFIG')
@section('content')
<div class="page-heading"><div><p class="eyebrow">CONFIGURATIONS / CLIENT SETTINGS</p><h1>Remote Config</h1><p>Schema-validated, versioned and selectively published application configuration.</p></div>
<div class="heading-actions"><a class="btn primary" href="{{ route('orbit.admin.config.new') }}">+ New remote config</a></div></div>
<section class="panel"><div class="panel-head"><div><h2>Configuration registry</h2><p>Only published, client-visible values are returned to consumer applications.</p></div></div>
<div class="table-scroll"><table><thead><tr><th>KEY</th><th>TYPE</th><th>VISIBILITY</th><th>PUBLICATION</th><th>VERSION</th><th></th></tr></thead><tbody>
@forelse($configs as $config)
<tr><td><strong>{{ $config->key }}</strong><small>{{ $config->description }}</small></td><td>{{ $config->value_type }}</td>
<td>{{ $config->client_visible ? 'Client-visible' : 'Internal' }}</td>
<td><span class="pill {{ $config->published ? 'success' : 'neutral' }}">{{ $config->published ? 'Published' : 'Draft' }}</span></td>
<td>{{ $config->version }}</td><td><a href="{{ route('orbit.admin.config.edit', $config) }}">Manage →</a></td></tr>
@empty<tr><td colspan="6"><div class="empty-state"><strong>No remote configurations</strong><p>Create a schema-validated configuration to get started.</p></div></td></tr>@endforelse
</tbody></table></div><div class="admin-pagination">{{ $configs->links() }}</div></section>
@endsection
