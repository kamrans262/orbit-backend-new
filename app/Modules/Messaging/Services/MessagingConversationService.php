<?php
namespace App\Modules\Messaging\Services;

use App\Modules\Circle\Services\CircleConsentPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MessagingConversationService
{
    public function __construct(private readonly MessagingAccessPolicy $access) {}

    public function ready(): bool
    {
        return config('orbit_messaging.enabled')===true
            && \Illuminate\Support\Facades\Schema::hasTable('messaging_conversations')
            && \Illuminate\Support\Facades\Schema::hasTable('messaging_envelopes')
            && \Illuminate\Support\Facades\Schema::hasTable('messaging_device_keys')
            && \Illuminate\Support\Facades\Schema::hasTable('messaging_redactions')
            && \Illuminate\Support\Facades\Schema::hasTable('messaging_sender_key_batches')
            && \Illuminate\Support\Facades\Schema::hasTable('messaging_sender_key_envelopes');
    }
    public function assertReady(): void
    {
        abort_unless($this->ready(),503,'Messaging service has not been provisioned.');
    }

    public function get(string $id,string $viewer): object
    {
        $this->assertReady();
        $row=DB::table('messaging_conversations')->where('id',$id)->first();
        abort_unless($row,404,'Conversation unavailable.');
        $this->access->assertConversation($row,$viewer);
        return $row;
    }

    public function direct(string $user,string $other): array
    {
        $this->assertReady();
        $this->access->assertDirect($user,$other);
        $ids=[$user,$other];
        sort($ids,SORT_STRING);
        $pair=$ids[0].':'.$ids[1];
        $id=(string)Str::uuid();
        DB::transaction(function() use($id,$pair,$ids):void{
            DB::table('messaging_conversations')->insertOrIgnore([
                'id'=>$id,'kind'=>'direct','direct_pair'=>$pair,
                'group_epoch'=>1,'created_at'=>now(),'updated_at'=>now(),
            ]);
            $current=DB::table('messaging_conversations')
                ->where('direct_pair',$pair)->value('id');
            foreach($ids as $member){
                DB::table('messaging_members')->insertOrIgnore([
                    'conversation_id'=>$current,'user_id'=>$member,'joined_at'=>now(),
                ]);
            }
        });
        return $this->present($this->getByPair($pair),$user);
    }

    private function getByPair(string $pair): object
    {
        return DB::table('messaging_conversations')->where('direct_pair',$pair)->first();
    }

    public function circle(string $circle,string $viewer): array
    {
        $this->assertReady();
        $this->access->assertCircle($circle,$viewer);
        DB::table('messaging_conversations')->insertOrIgnore([
            'id'=>(string)Str::uuid(),'kind'=>'circle','circle_id'=>$circle,
            'group_epoch'=>1,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $row=DB::table('messaging_conversations')->where('circle_id',$circle)->first();
        $row=$this->refreshEpoch($row);
        return $this->present($row,$viewer);
    }

    /**
     * Server group epoch increments whenever Circle roster, roles or trusted
     * messaging-device roster changes. Stage 3 MUST rotate sender keys before
     * sending to the new epoch. Server never receives those sender keys.
     */
    public function refreshEpoch(object $row): object
    {
        if($row->kind!=='circle')return $row;
        return DB::transaction(function() use($row):object {
            $locked=DB::table('messaging_conversations')->where('id',$row->id)
                ->lockForUpdate()->first();
            $members=DB::table('circle_members')->where('circle_id',$locked->circle_id)
                ->whereNull('removed_at')->orderBy('user_id')
                ->get(['user_id','role','joined_at']);
            $list=[];
            foreach($members as $member){
                $devices=DB::table('messaging_device_keys as k')
                    ->join('devices as d','d.id','=','k.device_id')
                    ->where('k.user_id',$member->user_id)->where('d.trust_state','trusted')
                    ->whereNull('d.revoked_at')->orderBy('k.device_id')
                    ->pluck('k.key_version','k.device_id')->all();
                $list[]=[$member->user_id,$member->role,$member->joined_at,$devices,
                    $this->access->circleSenderAllowed((string)$locked->circle_id,
                        (string)$member->user_id)];
            }
            $fingerprint=hash('sha256',json_encode($list,JSON_THROW_ON_ERROR));
            if($locked->roster_fingerprint!==$fingerprint){
                DB::table('messaging_conversations')->where('id',$locked->id)->update([
                    'roster_fingerprint'=>$fingerprint,
                    'group_epoch'=>($locked->roster_fingerprint===null)
                        ?(int)$locked->group_epoch:(int)$locked->group_epoch+1,
                    'updated_at'=>now(),
                ]);
                $locked=DB::table('messaging_conversations')->where('id',$row->id)->first();
            }
            return $locked;
        });
    }

    public function present(object $row,string $viewer): array
    {
        $other=null;
        if($row->kind==='direct'){
            $other=DB::table('messaging_members')->where('conversation_id',$row->id)
                ->where('user_id','!=',$viewer)->value('user_id');
        }
        return [
            'id'=>$row->id,'kind'=>$row->kind,
            'peer_id'=>$other,'circle_id'=>$row->circle_id,
            'group_epoch'=>(int)$row->group_epoch,
            'created_at'=>(string)$row->created_at,
            'ciphertext_only'=>true,
        ];
    }

    public function listing(string $viewer,string $device,int $page): array
    {
        $this->assertReady();
        // Add only Circles for which the current viewer has explicit consent.
        $circleIds=DB::table('circle_members')->where('user_id',$viewer)
            ->whereNull('removed_at')->limit(60)->pluck('circle_id');
        foreach($circleIds as $circle) {
            if($this->access->circleSenderAllowed((string)$circle,$viewer)) {
                $this->circle((string)$circle,$viewer);
            }
        }
        $records=DB::table('messaging_conversations as c')
            ->where(function($q) use($viewer):void{
                $q->whereExists(function($s) use($viewer):void{
                    $s->selectRaw('1')->from('messaging_members as m')
                        ->whereColumn('m.conversation_id','c.id')->where('m.user_id',$viewer);
                })->orWhere(function($s) use($viewer):void{
                    $s->where('c.kind','circle')->whereIn('c.circle_id',
                        DB::table('circle_members')->where('user_id',$viewer)
                            ->whereNull('removed_at')->select('circle_id'));
                });
            })->orderByDesc('c.updated_at')->orderByDesc('c.id')
                ->offset(($page-1)*25)->limit(26)->get(['c.*']);
        $items=[];
        $hasMore=$records->count()>25;
        foreach($records->take(25) as $row){
            try{$this->access->assertConversation($row,$viewer);}
            catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface){continue;}
            $record=$this->present($row,$viewer);
            $record['unread_envelopes']=DB::table('messaging_envelopes as e')
                ->join('messaging_messages as m','m.id','=','e.message_id')
                ->where('e.recipient_device_id',$device)
                ->where('m.conversation_id',$row->id)
                ->where('m.sender_id','!=',$viewer)->whereNull('e.read_at')
                ->whereNull('m.redacted_at')->where('m.expires_at','>',now())->count();
            $prefs=DB::table('messaging_members')->where('conversation_id',$row->id)
                ->where('user_id',$viewer)->first();
            $record['muted_until']=$prefs?->muted_until;
            $record['archived']=$prefs?->archived_at!==null;
            $items[]=$record;
        }
        return ['items'=>$items,'page'=>$page,'has_more'=>$hasMore];
    }

    public function preferences(object $row,string $viewer,array $prefs): array
    {
        $this->access->assertConversation($row,$viewer);
        $current=DB::table('messaging_members')->where('conversation_id',$row->id)
            ->where('user_id',$viewer)->first();
        $muted=array_key_exists('mute',$prefs)
            ?($prefs['mute']?now()->addDays(365):null):($current?->muted_until);
        $archived=array_key_exists('archive',$prefs)
            ?($prefs['archive']?now():null):($current?->archived_at);
        DB::table('messaging_members')->updateOrInsert(
            ['conversation_id'=>$row->id,'user_id'=>$viewer],
            ['muted_until'=>$muted,'archived_at'=>$archived],
        );
        return ['muted'=>$muted!==null,'archived'=>$archived!==null];
    }

    public function consent(string $circle,string $subject): array
    {
        $member=DB::table('circle_members')->where('circle_id',$circle)
            ->where('user_id',$subject)->whereNull('removed_at')->first();
        abort_unless($member,404,'Circle unavailable.');
        $row=DB::table('circle_permissions')->where('circle_id',$circle)
            ->where('scope_key','user:'.$subject)->where('permission_key','message')->first();
        return [
            'circle_id'=>$circle,
            'enabled'=>$row!==null && app(CircleConsentPolicy::class)
                ->consentCurrentlyValid($row,'message'),
            'requested'=>$row!==null && (bool)$row->enabled,
        ];
    }

    public function updateConsent(string $circle,string $subject,bool $enabled): array
    {
        if($enabled){
            abort_unless($this->access->circleSenderAllowed($circle,$subject)
                || $this->canStartConsent($circle,$subject),403,'Circle privacy does not allow messaging.');
        }else{
            app(CircleConsentPolicy::class)->revoke($circle,$subject,'message');
            return ['circle_id'=>$circle,'enabled'=>false];
        }
        DB::table('circle_permissions')->updateOrInsert([
            'circle_id'=>$circle,'scope_key'=>'user:'.$subject,'permission_key'=>'message',
        ],[
            'subject_user_id'=>$subject,'enabled'=>true,'expires_at'=>null,
            'value_json'=>json_encode(['reviewed'=>true,'audience'=>'circle',
                'duration'=>'persistent','typing_enabled'=>true],JSON_THROW_ON_ERROR),
            'updated_at'=>now(),
        ]);
        return ['circle_id'=>$circle,'enabled'=>true];
    }

    private function canStartConsent(string $circle,string $subject): bool
    {
        // Starting a grant still requires current membership, nonrestricted
        // role and global My Circle eligibility.
        $member=DB::table('circle_members')->where('circle_id',$circle)
            ->where('user_id',$subject)->whereNull('removed_at')->first();
        if(!$member || $member->role==='restricted' ||
            !app(\App\Modules\Circle\Services\CircleAccessPolicy::class)
                ->isMember($circle,$subject))return false;
        $json=DB::table('orbit_user_preferences')->where('user_id',$subject)->value('privacy');
        $p=$json?json_decode((string)$json,true):null;
        return is_array($p)&&($p['message_eligibility']??'Nobody')==='My Circle';
    }
}
