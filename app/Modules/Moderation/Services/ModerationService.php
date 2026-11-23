<?php

namespace App\Modules\Moderation\Services;

use App\Modules\Messaging\Services\MessagingSendRestrictionService;
use App\Modules\Social\Services\SocialGraphService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * M17 canonical moderation INDEX. Never copies E2EE content or circumvents
 * the established M4 blocks, M7 Moment evidence or M13 message evidence.
 */
final class ModerationService
{
    public function __construct(
        private readonly SocialGraphService $social,
        private readonly MessagingSendRestrictionService $messaging,
    ) {}

    public function installed(): bool
    {
        foreach (['moderation_reports','moderation_cases','moderation_case_sources',
            'moderation_case_events','moderation_actions','moderation_appeals',
            'moderation_evidence_access','moderation_controls',
            'moderation_control_audits'] as $table) {
            if (!Schema::hasTable($table)) return false;
        }
        return true;
    }

    public function assertReady(): void
    {
        abort_unless($this->installed(),503,'Moderation service is not provisioned.');
    }

    public function actor(Request $request): string
    {
        $this->assertReady();
        $session=$request->attributes->get('orbit_session');
        abort_unless($session,401);
        $user=(string)$session->user_id;
        abort_unless(DB::table('orbit_users')->where('id',$user)
            ->where('status','active')->whereNull('deletion_requested_at')->exists()
            && DB::table('devices')->where('id',(string)$session->device_id)
            ->where('user_id',$user)->where('trust_state','trusted')
            ->whereNotNull('approved_at')->whereNull('revoked_at')->exists(),
            403,'Active trusted device required.');
        return $user;
    }

    public function control(string $key): bool
    {
        return $this->installed() && DB::table('moderation_controls')
            ->where('key',$key)->value('value')==='1';
    }

    /** The owner alone gets a receipt. Investigation state is never included. */
    private function receipt(object $row): array
    {
        return ['id'=>$row->id,'status'=>'received','created_at'=>$row->created_at];
    }

    public function submit(string $actor,array $input): array
    {
        $this->assertReady();
        abort_unless(config('orbit_moderation.intake_enabled')===true,503,
            'Report intake temporarily unavailable.');
        $target=$input['target_id'];
        abort_if($actor===$target,422,'You cannot report your own account.');
        $normalized=[
            'target_id'=>$target,'reason'=>$input['reason'],
            'details'=>trim((string)($input['additional_details']??'')),
        ];
        $hash=hash('sha256',json_encode($normalized,JSON_THROW_ON_ERROR));
        $limit=(int)config('orbit_moderation.account_report_limit_per_hour',20);
        $key='orbit:moderation:report:'.hash('sha256',$actor);
        return DB::transaction(function()use($actor,$input,$target,$normalized,$hash,$limit,$key):array {
            // Serialize account retries; reused client IDs compare exact contents.
            abort_unless(DB::table('orbit_users')->where('id',$actor)->where('status','active')
                ->whereNull('deletion_requested_at')->lockForUpdate()->exists(),403);
            $existing=DB::table('moderation_reports')
                ->where('reporter_id',$actor)->where('client_id',$input['client_id'])->first();
            if($existing){
                abort_unless(hash_equals($existing->payload_hash,$hash),409,
                    'This report identifier was already used for different content.');
                return [$this->receipt($existing),false];
            }
            abort_if(RateLimiter::tooManyAttempts($key,$limit),429,
                'Please retry your report later.');
            // Generic 404 prevents target existence and block relationship probing.
            $this->social->assertVisible($actor,$target);
            $id=(string)Str::uuid7();
            $now=now();
            DB::table('moderation_reports')->insert([
                'id'=>$id,'reporter_id'=>$actor,'subject_user_id'=>$target,
                'client_id'=>$input['client_id'],'reason'=>$normalized['reason'],
                'details_enc'=>$normalized['details']!==''?Crypt::encryptString($normalized['details']):null,
                'payload_hash'=>$hash,'status'=>'received',
                'created_at'=>$now,'updated_at'=>$now,
            ]);
            $this->linkSource('account',$id,$target,$normalized['reason']);
            RateLimiter::hit($key,3600);
            return [$this->receipt(DB::table('moderation_reports')->where('id',$id)->first()),true];
        },3);
    }

    public function ownedReports(string $actor): array
    {
        $this->assertReady();
        return DB::table('moderation_reports')->where('reporter_id',$actor)
            ->orderByDesc('created_at')->limit(40)
            ->get(['id','created_at'])->map(fn($r)=>$this->receipt($r))->all();
    }

    public function ownedReport(string $actor,string $id): array
    {
        $this->assertReady();
        $row=DB::table('moderation_reports')->where('id',$id)
            ->where('reporter_id',$actor)->first() ?? abort(404,'Report unavailable.');
        return $this->receipt($row);
    }

    private function event(string $case,string $kind,?string $actor,
        string $action,?string $reason,int $revision): void
    {
        // Always called under a transaction and a locked case row for updates.
        $previous=(string)(DB::table('moderation_case_events')
            ->where('case_id',$case)->orderByDesc('id')->value('event_hash')
            ??str_repeat('0',64));
        $id=(string)Str::uuid7();
        $stamp=now()->toDateTimeString();
        $actorDigest=$actor?hash_hmac('sha256',$actor,(string)config('app.key')):null;
        $digest=hash_hmac('sha256',
            implode('|',[$id,$case,$kind,$actorDigest??'',$action,$reason??'',$revision,$previous,$stamp]),
            (string)config('app.key'));
        DB::table('moderation_case_events')->insert([
            'id'=>$id,'case_id'=>$case,'actor_kind'=>$kind,'actor_digest'=>$actorDigest,
            'action'=>$action,'reason_code'=>$reason,'revision_after'=>$revision,
            'previous_hash'=>$previous,'event_hash'=>$digest,'created_at'=>$stamp,
        ]);
    }

    /** One case per existing source report, with no copied evidence. */
    public function linkSource(string $kind,string $source,string|null $subject,
        string $reason): string
    {
        $this->assertReady();
        abort_unless(in_array($kind,['account','moment','messaging'],true),422);
        return DB::transaction(function()use($kind,$source,$subject,$reason):string {
            $existing=DB::table('moderation_cases')->where('source_kind',$kind)
                ->where('source_report_id',$source)->value('id');
            if($existing)return (string)$existing;
            $id=(string)Str::uuid7();
            $priority=in_array($reason,['threat','safety_concern'],true)?'urgent':'normal';
            $created=DB::table('moderation_cases')->insertOrIgnore([
                'id'=>$id,'source_kind'=>$kind,'source_report_id'=>$source,
                'subject_user_id'=>$subject,'status'=>'pending',
                'priority'=>$priority,'revision'=>1,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $actual=(string)($created?$id:DB::table('moderation_cases')
                ->where('source_kind',$kind)->where('source_report_id',$source)->value('id'));
            abort_unless($actual!=='',409,'Case intake conflicted.');
            DB::table('moderation_case_sources')->insertOrIgnore([
                'id'=>(string)Str::uuid7(),'case_id'=>$actual,
                'source_kind'=>$kind,'source_report_id'=>$source,'linked_at'=>now(),
            ]);
            if($created)$this->event($actual,'system',null,'report.received',$reason,1);
            return $actual;
        },3);
    }

    /** Bounded idempotent ingestion, safe on a MariaDB cron without Redis. */
    public function syncSources(?int $limit=null): array
    {
        $this->assertReady();
        $max=max(1,min(100,$limit??(int)config('orbit_moderation.source_sync_batch',40)));
        $result=['moment'=>0,'messaging'=>0];
        foreach(['moment'=>'moment_reports','messaging'=>'messaging_reports'] as $kind=>$table){
            if(!Schema::hasTable($table))continue;
            $pending=DB::table($table)->whereNotExists(function($q)use($table,$kind){
                $q->selectRaw('1')->from('moderation_cases as c')
                    ->where('c.source_kind',$kind)->whereColumn('c.source_report_id',$table.'.id');
            })->orderBy($table.'.created_at')->limit($max)->get();
            foreach($pending as $row){
                $subject=$kind==='moment'
                    ?($row->reported_user_id??DB::table('moments')->where('id',$row->moment_id)->value('creator_id'))
                    :($row->subject_user_id??null);
                $before=DB::table('moderation_cases')->where('source_kind',$kind)
                    ->where('source_report_id',$row->id)->exists();
                $this->linkSource($kind,$row->id,$subject,(string)$row->reason);
                if(!$before)$result[$kind]++;
            }
        }
        return $result;
    }

    public function requireCase(string $id): object
    {
        $this->assertReady();
        return DB::table('moderation_cases')->where('id',$id)->first()
            ?? abort(404,'Case unavailable.');
    }

    public function transition(string $id,string $admin,int $revision,
        string $action,string $reason,?string $assignee=null): object
    {
        return DB::transaction(function()use($id,$admin,$revision,$action,$reason,$assignee):object{
            $row=DB::table('moderation_cases')->where('id',$id)->lockForUpdate()->first()
                ?? abort(404,'Case unavailable.');
            abort_unless((int)$row->revision===$revision,409,'Case changed. Refresh first.');
            $next=match($action){
                'triage'=>$row->status==='pending'?'triaged':null,
                'investigate'=>in_array($row->status,['triaged','reopened'],true)?'investigating':null,
                'dismiss'=>in_array($row->status,['pending','triaged','investigating'],true)?'dismissed':null,
                'reopen'=>in_array($row->status,['actioned','dismissed'],true)?'reopened':null,
                default=>null,
            };
            abort_unless($next!==null,409,'Invalid moderation transition.');
            if($assignee!==null)abort_unless(DB::table('admin_users')
                ->where('id',$assignee)->where('status','active')->exists(),422,
                'Active assignee required.');
            $updates=['status'=>$next,'revision'=>$revision+1,'updated_at'=>now(),
                'closed_at'=>$next==='dismissed'?now():null];
            if($assignee!==null)$updates['assigned_admin_id']=$assignee;
            DB::table('moderation_cases')->where('id',$id)->update($updates);
            $this->event($id,'admin',$admin,'case.'.$action,$reason,$revision+1);
            return DB::table('moderation_cases')->where('id',$id)->first();
        },3);
    }

    public function enforce(string $id,string $admin,int $revision,string $scope,
        string $reason,string $duration): object
    {
        abort_unless($this->control('enforcement_enabled'),403,
            'Reviewed enforcement release has not been enabled.');
        abort_unless(in_array($scope,['warning','messaging_send'],true),422);
        abort_unless(in_array($reason,['spam','harassment','fraud','abuse','other'],true),422);
        abort_unless(in_array($duration,['1h','24h','7d','30d','indefinite'],true),422);
        return DB::transaction(function()use($id,$admin,$revision,$scope,$reason,$duration):object{
            $case=DB::table('moderation_cases')->where('id',$id)->lockForUpdate()->first()
                ?? abort(404,'Case unavailable.');
            abort_unless((int)$case->revision===$revision,409,'Case changed. Refresh first.');
            abort_unless($case->status==='investigating',409,'Investigate before enforcement.');
            abort_unless($case->subject_user_id!==null
                && DB::table('orbit_users')->where('id',$case->subject_user_id)->exists(),
                409,'Action requires a currently valid account subject.');
            if($scope==='messaging_send'){
                abort_unless($this->messaging->provisioned(),503,
                    'Messaging restrictions unavailable.');
                // Cannot overwrite an unrelated active restriction or earlier
                // M17 sanction. No automatic account/SOS suspension.
                abort_unless(!DB::table('messaging_send_restrictions')
                    ->where('user_id',$case->subject_user_id)
                    ->where(fn($q)=>$q->whereNull('ends_at')->orWhere('ends_at','>',now()))
                    ->exists(),409,'An active messaging restriction already exists.');
                $this->messaging->update($case->subject_user_id,$admin,[
                    'action'=>'suspend','reason'=>$reason,'duration'=>$duration,
                ]);
            }
            // Store the exact restriction expiry from the existing M13
            // service; independently calculating now() would make appeal
            // reversal compare a different timestamp.
            $ends=$scope==='messaging_send'
                ?DB::table('messaging_send_restrictions')
                    ->where('user_id',$case->subject_user_id)->value('ends_at')
                :null;
            $actionId=(string)Str::uuid7();
            DB::table('moderation_actions')->insert([
                'id'=>$actionId,'case_id'=>$id,'subject_user_id'=>$case->subject_user_id,
                'scope'=>$scope,'reason_code'=>$reason,'duration'=>$duration,
                'ends_at'=>$ends,
                'status'=>'active','issued_by'=>$admin,'created_at'=>now(),
            ]);
            DB::table('moderation_cases')->where('id',$id)->update([
                'status'=>'actioned','revision'=>$revision+1,'closed_at'=>now(),
                'updated_at'=>now(),
            ]);
            $this->event($id,'admin',$admin,'case.enforced.'.$scope,$reason,$revision+1);
            return DB::table('moderation_actions')->where('id',$actionId)->first();
        },3);
    }

    public function myActions(string $actor): array
    {
        $this->assertReady();
        return DB::table('moderation_actions')->where('subject_user_id',$actor)
            ->orderByDesc('created_at')->limit(40)
            ->get(['id','scope','status','ends_at','created_at'])->map(fn($r)=>[
                'id'=>$r->id,'scope'=>$r->scope,'status'=>$r->status,
                'ends_at'=>$r->ends_at,'created_at'=>$r->created_at,
            ])->all();
    }

    public function appeal(string $actor,string $actionId,array $input): array
    {
        $this->assertReady();
        return DB::transaction(function()use($actor,$actionId,$input):array{
            $action=DB::table('moderation_actions')->where('id',$actionId)->lockForUpdate()->first()
                ?? abort(404,'Action unavailable.');
            abort_unless($action->subject_user_id===$actor,404,'Action unavailable.');
            $message=trim((string)($input['statement']??''));
            $hash=hash('sha256',$message);
            $existing=DB::table('moderation_appeals')->where('action_id',$actionId)->first();
            if($existing){
                abort_unless($existing->client_id===$input['client_id']
                    && hash_equals($existing->payload_hash,$hash),409,
                    'This action already has an appeal.');
                return [['id'=>$existing->id,'status'=>$existing->status,
                    'created_at'=>$existing->created_at],false];
            }
            abort_unless($action->status==='active',409,'Action is not appealable.');
            $id=(string)Str::uuid7();
            DB::table('moderation_appeals')->insert([
                'id'=>$id,'action_id'=>$actionId,'appellant_id'=>$actor,
                'client_id'=>$input['client_id'],
                'statement_enc'=>$message!==''?Crypt::encryptString($message):null,
                'payload_hash'=>$hash,'status'=>'pending','revision'=>1,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $case=DB::table('moderation_cases')->where('id',$action->case_id)
                ->lockForUpdate()->first();
            $this->event($action->case_id,'user',$actor,'appeal.created',null,
                (int)$case->revision);
            return [['id'=>$id,'status'=>'pending','created_at'=>now()],true];
        },3);
    }

    public function myAppeals(string $actor): array
    {
        $this->assertReady();
        return DB::table('moderation_appeals')->where('appellant_id',$actor)
            ->orderByDesc('created_at')->limit(40)
            ->get(['id','status','decision_code','created_at','decided_at'])
            ->map(fn($r)=>(array)$r)->all();
    }

    /** Independent adjudicator, with safe reversal of the exact active action. */
    public function decideAppeal(string $appealId,string $admin,int $revision,
        string $decision,string $reason): void
    {
        abort_unless(in_array($decision,['upheld','reversed'],true),422);
        DB::transaction(function()use($appealId,$admin,$revision,$decision,$reason):void{
            $first=DB::table('moderation_appeals')->where('id',$appealId)->first()
                ?? abort(404,'Appeal unavailable.');
            $action=DB::table('moderation_actions')->where('id',$first->action_id)->lockForUpdate()->first()
                ?? abort(404);
            $case=DB::table('moderation_cases')->where('id',$action->case_id)->lockForUpdate()->first()
                ?? abort(404);
            $appeal=DB::table('moderation_appeals')->where('id',$appealId)->lockForUpdate()->first();
            abort_unless($appeal && $appeal->status==='pending'
                && (int)$appeal->revision===$revision,409,'Appeal has changed.');
            abort_unless($action->issued_by!==$admin,403,
                'The original reviewer cannot adjudicate their own decision.');
            if($decision==='reversed'){
                abort_unless($action->status==='active',409,'Action already reversed.');
                if($action->scope==='messaging_send'){
                    $current=DB::table('messaging_send_restrictions')
                        ->where('user_id',$action->subject_user_id)->first();
                    // Never undo a distinct/newer unrelated restriction.
                    abort_unless($current
                        && $current->updated_by===$action->issued_by
                        && $current->reason===$action->reason_code
                        && (string)$current->ends_at===(string)$action->ends_at,409,
                        'Restriction changed; manual independent review required.');
                    $this->messaging->update($action->subject_user_id,$admin,
                        ['action'=>'restore','duration'=>'24h','reason'=>'other']);
                }
                DB::table('moderation_actions')->where('id',$action->id)->update([
                    'status'=>'reversed','reversed_by'=>$admin,'reversed_at'=>now(),
                ]);
            }
            DB::table('moderation_appeals')->where('id',$appealId)->update([
                'status'=>$decision,'decision_code'=>$reason,
                'reviewing_admin_id'=>$admin,'revision'=>$revision+1,
                'decided_at'=>now(),'updated_at'=>now(),
            ]);
            $this->event($action->case_id,'admin',$admin,'appeal.'.$decision,$reason,
                (int)$case->revision);
        },3);
    }
}
