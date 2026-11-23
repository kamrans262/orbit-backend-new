<?php

namespace App\Modules\Safety\Services;

use App\Modules\Social\Services\SocialGraphService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SosService
{
    public function __construct(
        private readonly SosPolicy $policy,
        private readonly SosAuditService $audit,
        private readonly SocialGraphService $social,
    ) {}

    public function create(string $actor, array $input): array
    {
        $this->policy->assertReady();
        $share=(bool)$input['share_location'];
        $capture=null;
        if ($share) {
            abort_unless(isset($input['latitude'],$input['longitude'],$input['location_captured_at']),
                422, 'A fresh location sample is required when sharing is requested.');
            $capture=Carbon::parse($input['location_captured_at']);
        } else {
            abort_unless(!isset($input['latitude']) && !isset($input['longitude'])
                && !isset($input['location_captured_at']), 422,
                'Location values require explicit location sharing.');
        }
        $normalized=[
            'circle_id'=>$input['circle_id']??null,
            'share_location'=>$share,
            'latitude'=>$share?(float)$input['latitude']:null,
            'longitude'=>$share?(float)$input['longitude']:null,
            'location_captured_at'=>$capture?->toIso8601String(),
            'recording_consent'=>(bool)($input['recording_consent']??false),
        ];
        $hash=hash('sha256',json_encode($normalized,JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($actor,$input,$normalized,$hash):array {
            // Per-initiator mutex protects idempotency under concurrent device retries.
            abort_unless(DB::table('orbit_users')->where('id',$actor)
                ->where('status','active')->whereNull('deletion_requested_at')
                ->lockForUpdate()->exists(), 403);
            $existing=DB::table('sos_incidents')->where('initiator_id',$actor)
                ->where('client_id',$input['client_id'])->first();
            if($existing){
                abort_unless(hash_equals($existing->request_hash,$hash),409,
                    'An SOS already exists for this client_id with different parameters.');
                return [$this->present($existing,$actor),false];
            }
            if($normalized['share_location']){
                abort_unless(abs(Carbon::parse($normalized['location_captured_at'])->diffInSeconds(now())) <=
                    (int)config('orbit_sos.max_location_age_seconds',120),
                    422, 'Location sample is stale. SOS may be started without location.');
            }
            $circle=$normalized['circle_id'];
            $circleResponders=$circle
                ? $this->policy->defaultCircleResponders($circle,$actor) : [];
            $trusted=DB::table('sos_trusted_contacts')->where('owner_id',$actor)
                ->where('state','accepted')->limit(100)->pluck('recipient_id')->all();
            $trusted=array_values(array_filter($trusted,
                fn($id)=>$this->policy->trusted($actor,(string)$id)));
            $id=(string)Str::uuid();
            $now=now();
            $location=$normalized['share_location']?Crypt::encryptString(json_encode([
                'latitude'=>$normalized['latitude'],
                'longitude'=>$normalized['longitude'],
                'captured_at'=>$normalized['location_captured_at'],
            ],JSON_THROW_ON_ERROR)):null;
            $delay=(int)$this->policy->control('escalation_after_seconds','120');
            DB::table('sos_incidents')->insert([
                'id'=>$id,'initiator_id'=>$actor,'client_id'=>$input['client_id'],
                'request_hash'=>$hash,'circle_id'=>$circle,'status'=>'active',
                'recording_consented'=>$normalized['recording_consent'],
                'location_ciphertext'=>$location,
                'location_expires_at'=>$location?$now->copy()->addMinutes(
                    (int)config('orbit_sos.location_ttl_minutes',30)):null,
                'escalation_due_at'=>$circle && $trusted && $circleResponders ? $now->copy()->addSeconds($delay):null,
                'created_at'=>$now,'updated_at'=>$now,
            ]);
            $included=[];
            foreach ($circleResponders as $recipient) {
                if($recipient!==$actor && !isset($included[$recipient])) {
                    $this->recipient($id,$recipient,'circle');
                    $included[$recipient]=true;
                }
            }
            // If a Circle has zero eligible responders, contact trusted users
            // immediately: an emergency must not wait for a useless timeout.
            if(!$circle || $circleResponders===[])foreach($trusted as $recipient) {
                if(!isset($included[$recipient])) {
                    $this->recipient($id,$recipient,'trusted');
                    $included[$recipient]=true;
                }
            }
            $this->audit->incident($id,$actor,'sos.started');
            return [$this->present(DB::table('sos_incidents')->where('id',$id)->first(),$actor),true];
        },3);
    }

    public function recipient(string $incident,string $user,string $source): void
    {
        DB::table('sos_recipients')->insertOrIgnore([
            'id'=>(string)Str::uuid(),'incident_id'=>$incident,'user_id'=>$user,
            'source'=>$source,'state'=>'pending','in_app_available_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    public function present(object $incident, string $actor): array
    {
        $owner=$incident->initiator_id===$actor;
        $recipients=DB::table('sos_recipients')->where('incident_id',$incident->id)->get();
        $eligible=$recipients->filter(fn($recipient)=>$this->policy->recipientEligible($incident,$recipient));
        $ack=$eligible->where('state','acknowledged')->count();
        $accepted=DB::table('sos_delivery_jobs as j')
            ->join('sos_recipients as r','r.id','=','j.recipient_id')
            ->where('r.incident_id',$incident->id)->where('j.state','accepted')->count();
        // These names are exposed ONLY after requireIncident/canView
        // authorization. Never expose contact details or coordinates here.
        $initiatorName=trim((string)DB::table('orbit_users')
            ->where('id',$incident->initiator_id)->value('display_name'));
        if($initiatorName==='')$initiatorName='Orbit contact';
        $result=[
            'initiator_name'=>$initiatorName,
            'id'=>$incident->id,'status'=>$incident->status,
            'created_at'=>$incident->created_at,'closed_at'=>$incident->closed_at,
            'revision'=>(int)$incident->revision,
            'server_accepted'=>true,
            'in_app_available_for_eligible_responders'=>$eligible->isNotEmpty(),
            'push_provider_accepted_jobs'=>$accepted,
            'responder_acknowledgements'=>$ack,
            'remote_responder_acknowledged'=>$ack>0,
            'emergency_services_contacted'=>false,
            'location_sharing_active'=>$incident->status==='active'
                && $incident->location_ciphertext!==null
                && $incident->location_expires_at!==null
                && now()->lt($incident->location_expires_at),
            'recording_consented'=>(bool)$incident->recording_consented,
            'recording_active'=>false,
            'recording_backend_available'=>false,
        ];
        if ($owner) {
            $result['my_role']='initiator';
            $result['recipients']=$eligible->map(fn($row)=>[
                'user_id'=>$row->user_id,
                'display_name'=>trim((string)DB::table('orbit_users')
                    ->where('id',$row->user_id)->value('display_name')) ?: 'Orbit contact',
                'source'=>$row->source,
                'state'=>$row->state,'acknowledged_at'=>$row->acknowledged_at,
            ])->values()->all();
        } else {
            $mine=$eligible->firstWhere('user_id',$actor);
            $result['my_role']='responder';
            $result['my_response']=$mine?->state;
        }
        return $result;
    }

    public function listing(string $actor, int $limit=20): array
    {
        $limit=max(1,min((int)config('orbit_sos.max_history',40),$limit));
        $ids=DB::table('sos_incidents as i')
            ->leftJoin('sos_recipients as r',function($join)use($actor){
                $join->on('r.incident_id','=','i.id')->where('r.user_id','=',$actor);
            })->where(fn($q)=>$q->where('i.initiator_id',$actor)
                ->orWhereNotNull('r.id'))
            ->orderByDesc('i.created_at')->limit($limit*5)->pluck('i.id')->unique();
        $out=[];
        foreach($ids as $id){
            $incident=DB::table('sos_incidents')->where('id',$id)->first();
            if($incident && $this->policy->canView($incident,$actor))$out[]=$this->present($incident,$actor);
            if(count($out)>=$limit)break;
        }
        return $out;
    }

    public function acknowledge(string $id,string $actor): array
    {
        return DB::transaction(function()use($id,$actor):array {
            $incident=DB::table('sos_incidents')->where('id',$id)->lockForUpdate()->first();
            abort_unless($incident && $incident->status==='active',409,'SOS is no longer active.');
            $recipient=DB::table('sos_recipients')->where('incident_id',$id)
                ->where('user_id',$actor)->lockForUpdate()->first();
            abort_unless($recipient && $this->policy->recipientEligible($incident,$recipient),404,
                'Responder access unavailable.');
            if($recipient->state!=='acknowledged') {
                DB::table('sos_recipients')->where('id',$recipient->id)->update([
                    'state'=>'acknowledged','acknowledged_at'=>now(),'updated_at'=>now(),
                ]);
                $this->audit->incident($id,$actor,'sos.acknowledged');
            }
            return $this->present($incident,$actor);
        },3);
    }

    public function close(string $id,string $actor,string $state,int $revision): array
    {
        return DB::transaction(function()use($id,$actor,$state,$revision):array {
            $incident=DB::table('sos_incidents')->where('id',$id)->lockForUpdate()->first();
            abort_unless($incident && $incident->initiator_id===$actor,404,
                'Only the initiator can close an SOS.');
            abort_unless($incident->status==='active',409,'SOS already closed.');
            abort_unless((int)$incident->revision===$revision,409,'SOS status changed.');
            DB::table('sos_incidents')->where('id',$id)->update([
                'status'=>$state,'revision'=>$revision+1,'closed_at'=>now(),
                'location_ciphertext'=>null,'location_expires_at'=>null,'updated_at'=>now(),
            ]);
            DB::table('sos_delivery_jobs')->whereIn('recipient_id',
                DB::table('sos_recipients')->where('incident_id',$id)->select('id'))
                ->whereIn('state',['pending','retry','processing'])
                ->update(['state'=>'suppressed','lease_until'=>null,'updated_at'=>now()]);
            $this->audit->incident($id,$actor,'sos.'.$state);
            return $this->present(DB::table('sos_incidents')->where('id',$id)->first(),$actor);
        },3);
    }

    public function location(object $incident,string $actor): array
    {
        abort_unless($incident->status==='active' && $this->policy->canView($incident,$actor)
            && $incident->location_ciphertext && $incident->location_expires_at
            && now()->lt($incident->location_expires_at),404,'Location unavailable.');
        $data=json_decode(Crypt::decryptString($incident->location_ciphertext),true);
        abort_unless(is_array($data),404);
        return ['latitude'=>$data['latitude'],'longitude'=>$data['longitude'],
            'captured_at'=>$data['captured_at'],
            'expires_at'=>$incident->location_expires_at];
    }

    public function contacts(string $actor): array
    {
        $rows=DB::table('sos_trusted_contacts')
            ->where('owner_id',$actor)->orWhere('recipient_id',$actor)
            ->orderByDesc('created_at')->limit(150)->get();
        $out=[];
        foreach($rows as $r){
            if(!$this->policy->active($r->owner_id) || !$this->policy->active($r->recipient_id)
                || $this->social->isBlockedEitherWay($r->owner_id,$r->recipient_id))continue;
            $out[]=['id'=>$r->id,'owner_id'=>$r->owner_id,
                'recipient_id'=>$r->recipient_id,'state'=>$r->state,
                'accepted_at'=>$r->accepted_at];
        }
        return $out;
    }

    public function requestContact(string $actor,string $recipient): array
    {
        abort_unless($this->policy->active($recipient) && $this->social->isFriend($actor,$recipient)
            && $actor!==$recipient,404,'Trusted contact must be an active friend.');
        DB::transaction(function()use($actor,$recipient):void{
            $row=DB::table('sos_trusted_contacts')->where('owner_id',$actor)
                ->where('recipient_id',$recipient)->lockForUpdate()->first();
            if($row && $row->state==='accepted')return;
            DB::table('sos_trusted_contacts')->updateOrInsert(
                ['owner_id'=>$actor,'recipient_id'=>$recipient],
                ['id'=>$row?->id??(string)Str::uuid(),'state'=>'requested',
                    'accepted_at'=>null,'updated_at'=>now(),
                    'created_at'=>$row?->created_at??now()]
            );
        },3);
        return $this->contacts($actor);
    }

    public function decideContact(string $id,string $actor,string $state): array
    {
        DB::transaction(function()use($id,$actor,$state):void{
            $row=DB::table('sos_trusted_contacts')->where('id',$id)->lockForUpdate()->first();
            abort_unless($row && $row->recipient_id===$actor,404);
            if($state==='accepted')abort_unless($this->social->isFriend($row->owner_id,$actor)
                && $this->policy->active($row->owner_id),404);
            DB::table('sos_trusted_contacts')->where('id',$id)->update([
                'state'=>$state,'accepted_at'=>$state==='accepted'?now():null,
                'updated_at'=>now(),
            ]);
        },3);
        return $this->contacts($actor);
    }

    public function removeContact(string $id,string $actor): void
    {
        $deleted=DB::table('sos_trusted_contacts')->where('id',$id)
            ->where(fn($q)=>$q->where('owner_id',$actor)->orWhere('recipient_id',$actor))
            ->delete();
        abort_unless($deleted>0,404);
    }
}
