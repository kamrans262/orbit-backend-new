@extends('orbit-admin.layout')
@section('title','Notification Operations')
@section('crumb') NOTIFICATIONS / {{ strtoupper($area) }} @endsection
@section('content')
<div class="page-heading">
  <div>
    <p class="eyebrow">COMMUNICATIONS / NOTIFICATION OPERATIONS</p>
    <h1>Notification Management</h1>
    <p>Monitor inbox projection and provider acceptance, manage delivery and operational controls without viewing anyone's private content.</p>
  </div>
</div>
<section class="panel">
  <div class="panel-head"><div><h2>Notifications workspace</h2>
    <p>This is a dedicated area, separate from Accounts and Configurations. Every management action requires permission, confirmation and an audit reason.</p></div></div>
  <div class="heading-actions" style="display:flex;flex-wrap:wrap;gap:8px">
    @foreach([
      'overview'=>'Overview','jobs'=>'Delivery Queue','devices'=>'Push Devices',
      'templates'=>'Templates','settings'=>'Settings & Controls','audits'=>'Audit Trail'
    ] as $tab=>$title)
    <a class="btn {{ $area===$tab?'primary':'secondary' }}"
      href="{{ route('orbit.admin.notifications.'.$tab) }}">{{ $title }}</a>
    @endforeach
  </div>
</section>
@if(!$ready)
<section class="panel"><div class="admin-guidance">
  Module 14 tables have not been migrated in this environment. This page is read-only until the additive migration is applied.
  No notification records or provider responses are being simulated.
</div></section>
@else
@if($area==='overview')
<div class="stat-grid">
  @foreach([
    'Events accepted'=>$stats['events'],'Private inbox records'=>$stats['inbox'],
    'Unread inbox records'=>$stats['unread'],'Active push registrations'=>$stats['devices'],
    'Provider accepted'=>$stats['accepted'],'Failed delivery jobs'=>$stats['failed']
  ] as $label=>$value)
  <article class="stat-card"><span class="stat-label">{{ $label }}</span><strong>{{ number_format($value) }}</strong>
  <p>Aggregate only; no private recipient or message data</p></article>
  @endforeach
</div>
<section class="panel">
  <div class="panel-head"><div><p class="eyebrow">CURRENT RELEASE POSTURE</p>
    <h2>Delivery capabilities</h2></div></div>
  <div class="admin-kv">
    <div class="kv"><small>HTTPS private inbox</small><strong>{{ config('orbit_notifications.enabled')?'Available':'Disabled' }}</strong></div>
    <div class="kv"><small>FCM/APNs provider</small><strong>{{ $pushReady?'Server ready; device QA required':'Disabled / not provisioned' }}</strong></div>
    <div class="kv"><small>Provider acceptance</small><strong>Not a device display or read receipt</strong></div>
    <div class="kv"><small>Existing Moments compatibility</small><strong>Independent M7 inbox &amp; push kept intact</strong></div>
    <div class="kv"><small>Retention</small><strong>{{ (int)config('orbit_notifications.retention_days',7) }} configured days</strong></div>
    <div class="kv"><small>Workers</small><strong>Laravel Scheduler / MariaDB</strong></div>
  </div>
  <p>Encrypted-message content, GPS coordinates, notification tokens and user-specific audience memberships are not exposed through this workspace.</p>
</section>
<section class="panel">
  <div class="panel-head"><div><h2>Activity by category</h2><p>Counts of projected records only.</p></div></div>
  <div class="table-scroll"><table><thead><tr><th>CATEGORY</th><th>PROJECTED NOTICES</th></tr></thead>
  <tbody>@forelse($categoryTotals as $record)
    <tr><td>{{ ucfirst(str_replace('_',' ',$record->category)) }}</td><td>{{ number_format($record->total) }}</td></tr>
  @empty<tr><td colspan="2">No real notifications projected yet.</td></tr>@endforelse</tbody></table></div>
  <div class="panel-head"><div><h2>Delivery states</h2></div></div>
  <div class="table-scroll"><table><thead><tr><th>STATE</th><th>JOB COUNT</th></tr></thead><tbody>
    @forelse($stateTotals as $record)<tr><td>{{ ucfirst($record->state) }}</td><td>{{ number_format($record->total) }}</td></tr>
    @empty<tr><td colspan="2">No push delivery jobs.</td></tr>@endforelse
  </tbody></table></div>
</section>
@if(auth()->guard('admin')->user()?->canPerform('notifications.manage'))
<section class="panel">
  <div class="panel-head"><div><p class="eyebrow">CONTROLLED MAINTENANCE</p><h2>Run a bounded notification task</h2>
  <p>These actions do not enable Firebase or override account consent. Worker eligibility is checked each time.</p></div></div>
  <div class="two-col">
  @foreach(['project'=>'Project eligible events','dispatch'=>'Process push queue','expire'=>'Invalidate expired notices'] as $action=>$label)
  <form method="post" action="{{ route('orbit.admin.notifications.maintenance',$action) }}">
    @csrf
    <p><strong>{{ $label }}</strong></p>
    <label>Audit reason <input name="reason" required minlength="10" maxlength="500" placeholder="Explain the operational need" style="width:100%"></label>
    <label style="display:block;margin:10px 0"><input type="checkbox" name="confirm" value="1" required> Confirm bounded operation</label>
    <button class="btn secondary" type="submit">{{ $label }}</button>
  </form>
  @endforeach
  </div>
</section>
@endif
@elseif($area==='jobs')
<section class="panel">
  <div class="panel-head"><div><h2>Delivery queue</h2><p>Provider acceptance and retries, without payloads or target identities.</p></div></div>
  <form method="get" action="{{ route('orbit.admin.notifications.jobs') }}" class="heading-actions">
    <label>Status <select name="state"><option value="">All statuses</option>
      @foreach(['pending','retry','processing','accepted','failed','suppressed'] as $status)
        <option value="{{ $status }}" @selected(request('state')===$status)>{{ ucfirst($status) }}</option>
      @endforeach
    </select></label>
    <label>Category <select name="category"><option value="">All categories</option>
      @foreach($categories as $category)<option value="{{ $category }}" @selected(request('category')===$category)>{{ ucfirst($category) }}</option>@endforeach
    </select></label>
    <button class="btn secondary" type="submit">Filter</button>
  </form>
  <div class="table-scroll"><table><thead><tr>
    <th>CATEGORY</th><th>CHANNEL</th><th>STATE</th><th>ATTEMPTS</th>
    <th>LAST ERROR CODE</th><th>CREATED</th><th>MANAGE</th>
  </tr></thead><tbody>
  @forelse($jobs as $job)
  <tr><td>{{ $job->category }}</td><td>{{ $job->channel }}</td>
    <td><span class="pill {{ $job->state==='accepted'?'success':($job->state==='failed'?'danger':'neutral') }}">{{ $job->state }}</span></td>
    <td>{{ $job->attempts }}</td><td>{{ $job->last_error_code??'—' }}</td>
    <td>{{ $job->created_at }}</td><td>
    @if(auth()->guard('admin')->user()?->canPerform('notifications.manage') && in_array($job->state,['failed','retry'],true))
    <form method="post" action="{{ route('orbit.admin.notifications.retry',$job->id) }}">
      @csrf
      <input name="reason" required minlength="10" maxlength="500" placeholder="Reason for retry" style="max-width:170px">
      <label><input name="confirm" value="1" type="checkbox" required> Confirm</label>
      <button class="btn secondary" type="submit">Retry safely</button>
    </form>
    @else <span class="subtle">No action</span> @endif
    </td></tr>
  @empty<tr><td colspan="7">No jobs match this filter.</td></tr>@endforelse
  </tbody></table></div>
  {{ $jobs->links() }}
</section>
<section class="panel"><div class="panel-head"><div><h2>Recent transport attempts</h2>
  <p>Only provider and error codes; token values and private content are never recorded.</p></div></div>
  <div class="table-scroll"><table><thead><tr><th>PROVIDER</th><th>STATE</th><th>ERROR CODE</th><th>TIME</th></tr></thead>
    <tbody>@forelse($attempts as $attempt)<tr>
      <td>{{ $attempt->provider }}</td><td>{{ $attempt->state }}</td>
      <td>{{ $attempt->error_code??'—' }}</td><td>{{ $attempt->attempted_at }}</td>
      </tr>@empty<tr><td colspan="4">No provider attempts recorded.</td></tr>@endforelse</tbody></table></div>
</section>
@elseif($area==='devices')
<section class="panel"><div class="panel-head"><div><h2>Push device registrations</h2>
  <p>Device references and token fingerprint prefixes only; raw tokens and account information are hidden.</p></div></div>
  <div class="table-scroll"><table><thead><tr>
    <th>DEVICE REFERENCE</th><th>PLATFORM</th><th>FINGERPRINT</th><th>STATE</th><th>UPDATED</th><th>MANAGE</th>
  </tr></thead><tbody>
  @forelse($devices as $device)<tr>
    <td><code>{{ substr($device->device_id,0,8) }}…</code></td><td>{{ strtoupper($device->platform) }}</td>
    <td><code>{{ substr($device->token_digest,0,12) }}…</code></td>
    <td><span class="pill {{ $device->disabled_at?'neutral':'success' }}">{{ $device->disabled_at?'Disabled':'Active' }}</span></td>
    <td>{{ $device->updated_at }}</td>
    <td>@if(!$device->disabled_at && auth()->guard('admin')->user()?->canPerform('notifications.manage'))
    <form method="post" action="{{ route('orbit.admin.notifications.devices.disable',$device->device_id) }}">
      @csrf
      <input name="reason" required minlength="10" maxlength="500" placeholder="Disable reason" style="max-width:170px">
      <label><input type="checkbox" name="confirm" value="1" required> Confirm</label>
      <button class="btn secondary" type="submit">Disable</button>
    </form>
    @else <span class="subtle">No action</span>@endif</td>
  </tr>@empty<tr><td colspan="6">No registered notification devices.</td></tr>@endforelse
  </tbody></table></div>{{ $devices->links() }}
  <p>Existing Moments token delivery remains an independent compatibility transport until a reviewed cutover. This control manages only shared notification registrations.</p>
</section>
@elseif($area==='templates')
<section class="panel"><div class="panel-head"><div><h2>Approved generic templates</h2>
  <p>All copy must remain generic. Names, plaintext messages, locations, Circle rosters and keys are never supported as interpolation fields.</p></div></div>
  <div class="table-scroll"><table><thead><tr><th>KEY / CATEGORY</th><th>GENERIC COPY</th><th>STATE</th><th>MANAGE</th></tr></thead>
  <tbody>@foreach($templates as $template)<tr>
    <td><strong>{{ $template->key }}</strong><small>{{ $template->category }}</small></td>
    <td><strong>{{ $template->title }}</strong><small>{{ $template->body }}</small></td>
    <td><span class="pill {{ $template->enabled?'success':'neutral' }}">{{ $template->enabled?'Enabled':'Paused' }}</span></td>
    <td>@if(auth()->guard('admin')->user()?->canPerform('notifications.manage'))
      <form method="post" action="{{ route('orbit.admin.notifications.templates.save',$template->key) }}">
        @csrf
        <label>Title <input name="title" value="{{ $template->title }}" maxlength="120" required></label>
        <label>Body <input name="body" value="{{ $template->body }}" maxlength="200" required style="max-width:260px"></label>
        <label>State <select name="enabled"><option value="1" @selected($template->enabled)>Enabled</option>
          <option value="0" @selected(!$template->enabled)>Paused</option></select></label>
        <label>Reason <input name="reason" required minlength="10" maxlength="500" placeholder="Reason for change"></label>
        <label><input type="checkbox" name="confirm" value="1" required> Confirm</label>
        <button class="btn secondary" type="submit">Save</button>
      </form>
      @else <span class="subtle">Read only</span>@endif
    </td></tr>@endforeach
  </tbody></table></div>
</section>
@elseif($area==='settings')
<section class="panel"><div class="panel-head"><div><h2>Category and channel controls</h2>
  <p>Controls are server enforced and audited. Global user preferences and feature consent remain authoritative.</p></div></div>
  <div class="table-scroll"><table><thead><tr><th>CONTROL</th><th>STATE</th><th>UPDATE</th></tr></thead><tbody>
  @foreach(array_merge(['channel.in_app','channel.push'],array_map(fn($x)=>'category.'.$x,$categories)) as $key)
    @php $enabled=$controls->has($key)?(bool)$controls->get($key):true; @endphp
    <tr><td><strong>{{ $key }}</strong></td>
      <td><span class="pill {{ $enabled?'success':'neutral' }}">{{ $enabled?'Enabled':'Paused' }}</span></td>
      <td>@if(auth()->guard('admin')->user()?->canPerform('notifications.manage'))
      <form method="post" action="{{ route('orbit.admin.notifications.settings.save') }}">
        @csrf
        <input type="hidden" name="key" value="{{ $key }}">
        <select name="enabled"><option value="1" @selected($enabled)>Enable</option>
          <option value="0" @selected(!$enabled)>Pause</option></select>
        <input type="text" name="reason" required minlength="10" maxlength="500" placeholder="Audit reason">
        <label><input type="checkbox" name="confirm" value="1" required> Confirm</label>
        <button class="btn secondary" type="submit">Apply</button>
      </form>
      @else <span class="subtle">Read only</span> @endif</td>
    </tr>
  @endforeach
  </tbody></table></div>
  <p><strong>FCM release configuration:</strong> {{ $pushConfigured?'Configured switch enabled':'Disabled at server configuration' }}.
  {{ $pushReady?'Provider ready for controlled integration testing.':'No real provider delivery can be enabled through this panel.' }}</p>
  <p><strong>Privacy:</strong> No admin control overrides blocked relationships, Circle permissions, account consent, trusted-device status or opt-outs.</p>
</section>
@elseif($area==='audits')
<section class="panel"><div class="panel-head"><div><h2>Notification administration audit trail</h2>
  <p>Privileged changes are attributed to administrator accounts, with reasons and timestamps.</p></div></div>
  <div class="table-scroll"><table><thead><tr><th>ACTION</th><th>SUBJECT TYPE</th><th>SUBJECT REFERENCE</th><th>REASON</th><th>TIME (UTC)</th></tr></thead>
  <tbody>@forelse($audits as $audit)<tr>
    <td>{{ $audit->action }}</td><td>{{ $audit->subject_type }}</td><td>{{ $audit->subject_id??'—' }}</td>
    <td>{{ $audit->reason }}</td><td>{{ $audit->created_at }}</td>
  </tr>@empty<tr><td colspan="5">No notification management actions yet.</td></tr>@endforelse</tbody></table></div>
  {{ $audits->links() }}
</section>
@endif
@endif
@endsection
