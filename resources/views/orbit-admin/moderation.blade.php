@extends('orbit-admin.layout')
@section('title','Moderation & Reporting')
@section('crumb') MODERATION / {{ strtoupper($area) }} @endsection
@section('content')
<div class="page-heading">
  <div>
    <p class="eyebrow">TRUST &amp; SAFETY / REPORT OPERATIONS</p>
    <h1>Moderation &amp; Reporting</h1>
    <p>Independent, permission-restricted review of account, Moment and encrypted-message reports. No arbitrary private content, decryption keys, precise locations or reporter identities are displayed.</p>
  </div>
</div>
<section class="panel">
  <div class="panel-head"><div><h2>Moderation workspace</h2><p>Uses the existing Orbit Administration layout, tables, cards, filters and confirmation controls.</p></div></div>
  <div class="heading-actions" style="display:flex;flex-wrap:wrap;gap:8px">
    @foreach(['overview'=>'Overview','queue'=>'Report Queue','cases'=>'Cases & Investigations','actions'=>'Enforcement Actions','appeals'=>'Appeals','settings'=>'Settings'] as $tab=>$label)
      <a href="{{ route('orbit.admin.moderation.'.$tab) }}" class="btn {{ $area===$tab?'primary':'secondary' }}">{{ $label }}</a>
    @endforeach
    @if(auth()->guard('admin')->user()?->canPerform('moderation.audit'))
      <a href="{{ route('orbit.admin.moderation.audits') }}" class="btn {{ $area==='audits'?'primary':'secondary' }}">Audit Trail</a>
    @endif
  </div>
</section>
@if(!$ready)
<section class="panel"><div class="admin-guidance">The additive Moderation schema has not been provisioned. Existing M4 blocking, M7 Moment and M13 encrypted-message reporting remain independent; no M17 action is available until its migration has been applied.</div></section>
@else
@if($area==='overview')
  <div class="stat-grid">
    @foreach(['Total cases'=>$counts['total'],'Pending reports'=>$counts['pending'],'Investigating'=>$counts['investigating'],'Actioned'=>$counts['actioned'],'Dismissed'=>$counts['dismissed'],'Open appeals'=>$counts['appeals']] as $label=>$count)
      <article class="stat-card"><span class="stat-label">{{ $label }}</span><strong>{{ number_format($count) }}</strong></article>
    @endforeach
  </div>
  <section class="panel">
    <div class="panel-head"><div><h2>Reporting channels</h2><p>Original module report rows retain their own authorization and encrypted evidence policy. Synchronization is reference-only.</p></div></div>
    <div class="admin-kv">
      <div class="kv"><small>Account reports</small><strong>Authenticated M17 intake; owned receipts only</strong></div>
      <div class="kv"><small>Moment and comment reports</small><strong>Original M7 evidence and restriction gates retained</strong></div>
      <div class="kv"><small>Direct and Circle chat reports</small><strong>M13 end-to-end encryption and consent maintained</strong></div>
      <div class="kv"><small>Account blocking</small><strong>Original M4 graph; no second block database</strong></div>
      <div class="kv"><small>Enforcement switch</small><strong>{{ app(\App\Modules\Moderation\Services\ModerationService::class)->control('enforcement_enabled')?'Enabled by reviewed operator':'Disabled until reviewed release' }}</strong></div>
      <div class="kv"><small>Emergency SOS</small><strong>Independent M16 safety authorization preserved</strong></div>
    </div>
    @if(auth()->guard('admin')->user()?->canPerform('moderation.manage'))
    <form method="post" action="{{ route('orbit.admin.moderation.sync') }}">@csrf
      <label>Operational reason<input name="reason" required minlength="10" maxlength="300" placeholder="Reason for bounded report reference synchronization"></label>
      <label><input type="checkbox" name="confirm" value="1" required> Confirm reference-only synchronization</label>
      <button type="submit" class="btn secondary">Sync existing report references</button>
    </form>
    @endif
  </section>
@elseif(in_array($area,['queue','cases'],true))
  <section class="panel">
    <div class="panel-head"><div><h2>{{ $area==='queue'?'Open Report Queue':'Case Directory' }}</h2><p>Server-owned metadata only. Reporters, voluntary evidence and private message contents are omitted.</p></div></div>
    <form method="get" action="{{ route('orbit.admin.moderation.'.$area) }}" class="heading-actions" style="display:flex;flex-wrap:wrap;gap:8px">
      <select name="status" aria-label="Filter status"><option value="">All states</option>
        @foreach(['pending','triaged','investigating','actioned','dismissed','reopened'] as $state)
          <option value="{{ $state }}" @selected(request('status')===$state)>{{ ucfirst($state) }}</option>
        @endforeach
      </select>
      <select name="source" aria-label="Filter report channel"><option value="">All channels</option>
        @foreach(['account','moment','messaging'] as $source)
          <option value="{{ $source }}" @selected(request('source')===$source)>{{ ucfirst($source) }}</option>
        @endforeach
      </select>
      <select name="priority" aria-label="Filter priority"><option value="">All priorities</option>
        @foreach(['normal','urgent'] as $priority)
          <option value="{{ $priority }}" @selected(request('priority')===$priority)>{{ ucfirst($priority) }}</option>
        @endforeach
      </select>
      <button class="btn secondary" type="submit">Filter Cases</button>
    </form>
    <div class="table-scroll"><table>
      <thead><tr><th>CASE</th><th>CHANNEL</th><th>STATUS</th><th>PRIORITY</th><th>REVISION</th><th>RECEIVED</th><th>ACTION</th></tr></thead>
      <tbody>
      @forelse($cases as $item)
        <tr>
          <td><code>{{ substr($item->id,0,8) }}…</code></td>
          <td>{{ ucfirst($item->source_kind) }}</td>
          <td><span class="pill {{ $item->status==='pending'?'danger':'neutral' }}">{{ ucfirst($item->status) }}</span></td>
          <td>{{ ucfirst($item->priority) }}</td>
          <td>{{ $item->revision }}</td><td>{{ $item->created_at }}</td>
          <td><a href="{{ route('orbit.admin.moderation.cases.show',['case'=>$item->id]) }}" class="btn secondary">Review case</a></td>
        </tr>
      @empty <tr><td colspan="7">No matching reports. Previous M7/M13 entries are indexed by the scheduled synchronization worker.</td></tr>
      @endforelse
      </tbody>
    </table></div>
    {{ $cases->links() }}
  </section>
@elseif($area==='detail' && $case)
  <section class="panel">
    <div class="panel-head"><div><h2>Case Review</h2><p>Every state mutation is revision-protected and audited. Reports are allegations, not evidence of wrongdoing.</p></div></div>
    <div class="admin-kv">
      <div class="kv"><small>Case reference</small><strong><code>{{ $case->id }}</code></strong></div>
      <div class="kv"><small>Report source</small><strong>{{ ucfirst($case->source_kind) }}</strong></div>
      <div class="kv"><small>Current state</small><strong>{{ ucfirst($case->status) }}</strong></div>
      <div class="kv"><small>Priority</small><strong>{{ ucfirst($case->priority) }}</strong></div>
      <div class="kv"><small>Version</small><strong>{{ $case->revision }}</strong></div>
      <div class="kv"><small>Assigned reviewer</small><strong>{{ $case->assigned_admin_id?substr($case->assigned_admin_id,0,8).'…':'Unassigned' }}</strong></div>
    </div>
    @if(auth()->guard('admin')->user()?->canPerform('moderation.triage'))
      <form method="post" action="{{ route('orbit.admin.moderation.cases.transition',['case'=>$case->id]) }}">@csrf
        <input type="hidden" name="revision" value="{{ $case->revision }}">
        <label>Review action
          <select name="action" required>
            @if(in_array($case->status,['pending','triaged','investigating','reopened'],true))<option value="reassign">Assign / reassign reviewer</option>@endif
            @if($case->status==='pending')<option value="triage">Triage</option>@endif
            @if(in_array($case->status,['triaged','reopened'],true))<option value="investigate">Open investigation</option>@endif
            @if(in_array($case->status,['pending','triaged','investigating'],true))<option value="dismiss">Dismiss after review</option>@endif
            @if(in_array($case->status,['actioned','dismissed'],true))<option value="reopen">Reopen for review</option>@endif
          </select>
        </label>
        <label>Decision reason
          <select name="reason" required>
            @foreach(['other'=>'Other','spam'=>'Spam','harassment'=>'Harassment','fraud'=>'Fraud','abuse'=>'Abuse','safety'=>'Safety concern','reviewed_safe'=>'Reviewed safe'] as $code=>$label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
          </select>
        </label>
        <label>Assign to moderator (optional)
          <select name="assign_to"><option value="">Keep current reviewer</option>
            @foreach($assignable as $moderator)<option value="{{ $moderator->id }}">{{ $moderator->name }}</option>@endforeach
          </select>
        </label>
        <label><input type="checkbox" name="confirm" value="1" required> Confirm this audited moderation decision</label>
        <button class="btn primary" type="submit">Save case decision</button>
      </form>
    @endif
  </section>
  @if(auth()->guard('admin')->user()?->canPerform('moderation.triage'))
  <section class="panel">
    <div class="panel-head"><div><h2>Investigation notes</h2><p>Reviewer-only notes are encrypted at rest, omitted from case searches, and never forwarded to push notifications or reported members.</p></div></div>
    <form method="post" action="{{ route('orbit.admin.moderation.cases.notes',['case'=>$case->id]) }}">@csrf
      <input type="hidden" name="revision" value="{{ $case->revision }}">
      <label>Confidential review note<textarea name="note" required minlength="10" maxlength="2000" rows="4" placeholder="Record the reasoned assessment and evidence ticket, not copied private chat transcripts"></textarea></label>
      <label><input type="checkbox" name="confirm" value="1" required> Confirm this audit-linked private note</label>
      <button class="btn secondary" type="submit">Save review note</button>
    </form>
    <div class="table-scroll"><table><thead><tr><th>RECORDED</th><th>REVIEW NOTE</th></tr></thead><tbody>
      @forelse($notes as $note)<tr><td>{{ $note->created_at }}</td><td>{{ $note->note }}</td></tr>
      @empty<tr><td colspan="2">No investigator notes yet.</td></tr>@endforelse
    </tbody></table></div>
  </section>
  @endif
  @if($case->status==='investigating' && auth()->guard('admin')->user()?->canPerform('moderation.decide'))
  <section class="panel">
    <div class="panel-head"><div><h2>Reviewed enforcement</h2><p>Only warning and messaging-send restriction are implemented; account-wide suspensions and Moment restriction stay in their original gated modules. SOS cannot be disabled here.</p></div></div>
    @if(app(\App\Modules\Moderation\Services\ModerationService::class)->control('enforcement_enabled'))
    <form method="post" action="{{ route('orbit.admin.moderation.cases.enforce',['case'=>$case->id]) }}">@csrf
      <input type="hidden" name="revision" value="{{ $case->revision }}">
      <label>Action<select name="scope"><option value="warning">Issue warning (record only)</option><option value="messaging_send">Restrict messaging sends</option></select></label>
      <label>Reason<select name="reason">@foreach(['spam','harassment','fraud','abuse','other'] as $reason)<option value="{{ $reason }}">{{ ucfirst($reason) }}</option>@endforeach</select></label>
      <label>Duration<select name="duration">@foreach(['1h','24h','7d','30d','indefinite'] as $duration)<option value="{{ $duration }}">{{ $duration }}</option>@endforeach</select></label>
      <label><input type="checkbox" name="confirm" value="1" required> I reviewed the evidence and authorize this limited, audited action</label>
      <button class="btn primary" type="submit">Apply reviewed action</button>
    </form>
    @else<div class="admin-guidance">Actions are not active. A separately authorized reviewer must enable the audited enforcement setting before an action can be executed.</div>@endif
  </section>
  @endif
  @if(auth()->guard('admin')->user()?->canPerform('moderation.evidence')
    && (($case->source_kind==='moment' && auth()->guard('admin')->user()?->canPerform('moments.moderate'))
      || ($case->source_kind==='messaging' && auth()->guard('admin')->user()?->canPerform('messages.moderate'))))
  <section class="panel">
    <div class="panel-head"><div><h2>Explicitly volunteered report evidence</h2><p>Source-module permission and consent checks still apply. Other private content is inaccessible. Every successful view is audited.</p></div></div>
    <form method="post" action="{{ route('orbit.admin.moderation.cases.evidence',['case'=>$case->id]) }}">@csrf
      <label>Review ticket<input name="ticket" required minlength="10" maxlength="80" placeholder="Approved investigation ticket"></label>
      <label><input type="checkbox" name="confirm" value="1" required> Confirm scope and volunteered evidence access</label>
      <button type="submit" class="btn secondary">Review permitted evidence</button>
    </form>
  </section>
  @endif
  <section class="panel"><div class="panel-head"><div><h2>Case source links</h2><p>References to original report records, never duplicated private evidence.</p></div></div>
  <div class="table-scroll"><table><thead><tr><th>TYPE</th><th>REFERENCE</th><th>LINKED AT</th></tr></thead><tbody>
    @foreach($sources as $source)<tr><td>{{ ucfirst($source->source_kind) }}</td><td><code>{{ substr($source->source_report_id,0,8) }}…</code></td><td>{{ $source->linked_at }}</td></tr>@endforeach
  </tbody></table></div></section>
  <section class="panel"><div class="panel-head"><div><h2>Actions on this case</h2><p>Subject details and reporter identities remain scoped; no account-wide suspension is inferred.</p></div></div>
  <div class="table-scroll"><table><thead><tr><th>ACTION</th><th>SCOPE</th><th>STATUS</th><th>ENDS AT</th></tr></thead><tbody>
    @forelse($actions as $item)<tr><td><code>{{ substr($item->id,0,8) }}…</code></td><td>{{ $item->scope }}</td><td>{{ $item->status }}</td><td>{{ $item->ends_at??'—' }}</td></tr>
    @empty<tr><td colspan="4">No sanction recorded for this case.</td></tr>@endforelse
  </tbody></table></div></section>
  <section class="panel"><div class="panel-head"><div><h2>Case history</h2><p>Append-only, chained decision receipts; plaintext narratives and actor identity omitted.</p></div></div>
  <div class="table-scroll"><table><thead><tr><th>ACTION</th><th>REASON</th><th>REVISION</th><th>TIME</th></tr></thead><tbody>
    @forelse($events as $item)<tr><td>{{ $item->action }}</td><td>{{ $item->reason_code??'—' }}</td><td>{{ $item->revision_after }}</td><td>{{ $item->created_at }}</td></tr>
    @empty<tr><td colspan="4">No case events yet.</td></tr>@endforelse
  </tbody></table></div></section>
@elseif($area==='actions')
  <section class="panel"><div class="panel-head"><div><h2>Enforcement directory</h2><p>Audited, scope-limited actions. Emergency SOS, media keys and account access remain outside this control.</p></div></div>
  <div class="stat-grid"><article class="stat-card"><span class="stat-label">Active recorded actions</span><strong>{{ number_format($counts['active_actions']) }}</strong></article></div>
  <div class="table-scroll"><table><thead><tr><th>CASE</th><th>SCOPE</th><th>REASON</th><th>STATE</th><th>EXPIRES</th><th>CREATED</th></tr></thead><tbody>
    @forelse($actions as $item)<tr>
      <td><a href="{{ route('orbit.admin.moderation.cases.show',['case'=>$item->case_id]) }}"><code>{{ substr($item->case_id,0,8) }}…</code></a></td>
      <td>{{ $item->scope }}</td><td>{{ $item->reason_code }}</td><td>{{ $item->status }}</td><td>{{ $item->ends_at??'—' }}</td><td>{{ $item->created_at }}</td>
    </tr>@empty<tr><td colspan="6">No moderation actions.</td></tr>@endforelse
  </tbody></table></div></section>
@elseif($area==='appeals')
  <section class="panel"><div class="panel-head"><div><h2>Appeals & independent review</h2><p>A moderator cannot review an appeal of their own action. Case evidence and reporter identity remain confidential.</p></div></div>
  <div class="table-scroll"><table><thead><tr><th>APPEAL</th><th>CASE</th><th>SCOPE</th><th>STATUS</th><th>SUBMITTED</th><th>REVIEW</th></tr></thead><tbody>
    @forelse($appeals as $item)<tr>
      <td><code>{{ substr($item->id,0,8) }}…</code></td>
      <td><a href="{{ route('orbit.admin.moderation.cases.show',['case'=>$item->case_id]) }}"><code>{{ substr($item->case_id,0,8) }}…</code></a></td>
      <td>{{ $item->scope }}</td><td>{{ ucfirst($item->status) }}</td><td>{{ $item->created_at }}</td>
      <td>
      @if($item->status==='pending' && auth()->guard('admin')->user()?->canPerform('moderation.appeals'))
        <form method="post" action="{{ route('orbit.admin.moderation.appeals.decision',['appeal'=>$item->id]) }}">@csrf
          <input type="hidden" name="revision" value="{{ $item->revision }}">
          <label>Decision<select name="decision"><option value="upheld">Uphold</option><option value="reversed">Reverse after review</option></select></label>
          <label>Basis<select name="reason">
          @foreach(['confirmed_violation','insufficient_evidence','proportionality','procedural_error','other'] as $code)<option value="{{ $code }}">{{ ucwords(str_replace('_',' ',$code)) }}</option>@endforeach
          </select></label>
          <label><input type="checkbox" name="confirm" value="1" required> Confirm independent review</label>
          <button type="submit" class="btn secondary">Record appeal decision</button>
        </form>
      @else <span class="subtle">{{ $item->decision_code??'No available action' }}</span> @endif
      </td></tr>
    @empty<tr><td colspan="6">No submitted appeals.</td></tr>@endforelse
  </tbody></table></div></section>
@elseif($area==='settings')
  <section class="panel"><div class="panel-head"><div><h2>Moderation operational controls</h2><p>Intake, M4 blocks, emergency access, original-source evidence and encryption are not disabled by this page.</p></div></div>
  @foreach($controls as $item)
    <div class="kv"><small>{{ ucwords(str_replace('_',' ',$item->key)) }}</small><strong>{{ $item->value==='1'?'Enabled':'Disabled' }}</strong></div>
    @if(auth()->guard('admin')->user()?->canPerform('moderation.manage'))
    <form method="post" action="{{ route('orbit.admin.moderation.settings.save') }}">@csrf
      <input type="hidden" name="key" value="{{ $item->key }}">
      <label>Validated setting<select name="value"><option value="0" @selected($item->value==='0')>Disabled</option><option value="1" @selected($item->value==='1')>Enabled</option></select></label>
      <label>Reason<input name="reason" required minlength="12" maxlength="300" placeholder="Reviewed operational policy and release ticket"></label>
      <label>To enable, type ENABLE<input name="release_confirmation" maxlength="6" placeholder="ENABLE"></label>
      <label><input type="checkbox" name="confirm" value="1" required> Confirm audited control change</label>
      <button type="submit" class="btn secondary">Save moderation control</button>
    </form>
    @endif
  @endforeach
  </section>
@elseif($area==='audits')
  <section class="panel"><div class="panel-head"><div><h2>Case decision audit</h2><p>Latest 80 hashed actor events; no chat evidence, notes or reporter identifiers.</p></div></div>
  <div class="table-scroll"><table><thead><tr><th>CASE</th><th>EVENT</th><th>REASON</th><th>REVISION</th><th>TIME</th></tr></thead><tbody>
    @forelse($events as $item)<tr><td><code>{{ substr($item->case_id,0,8) }}…</code></td><td>{{ $item->action }}</td><td>{{ $item->reason_code??'—' }}</td><td>{{ $item->revision_after }}</td><td>{{ $item->created_at }}</td></tr>
    @empty<tr><td colspan="5">No moderation decisions yet.</td></tr>@endforelse
  </tbody></table></div></section>
  <section class="panel"><div class="panel-head"><div><h2>Operational control audit</h2><p>Recorded old/new values, approver and hashed reason. No plaintext investigation narrative.</p></div></div>
  <div class="table-scroll"><table><thead><tr><th>SETTING</th><th>FROM</th><th>TO</th><th>WHEN</th></tr></thead><tbody>
    @forelse($controlAudits as $item)<tr><td>{{ $item->key }}</td><td>{{ $item->old_value }}</td><td>{{ $item->new_value }}</td><td>{{ $item->created_at }}</td></tr>
    @empty<tr><td colspan="4">No settings changed.</td></tr>@endforelse
  </tbody></table></div></section>
@endif
@endif
@endsection
