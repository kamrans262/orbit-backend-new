<section class="panel">
  <div class="panel-head"><div><p class="eyebrow">SERVICE READINESS</p><h2>Operational availability</h2>
    <p>Administration is available independently of the member-facing feature.</p></div></div>
  <div class="admin-kv">
    <div class="kv"><small>Database</small><strong>{{ $installed ? 'Installed' : 'Not installed' }}</strong></div>
    <div class="kv"><small>Feature configuration</small><strong>{{ $enabled ? 'Enabled' : 'Disabled' }}</strong></div>
  </div>
  <div class="admin-guidance" role="status">
    {{ !$installed ? 'Operational records are unavailable until the required schema is installed.' : 'The member-facing feature is disabled. Operational records and actions remain protected.' }}
    No live service connectivity is implied by configuration status.
  </div>
</section>
