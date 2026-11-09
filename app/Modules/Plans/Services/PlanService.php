<?php

namespace App\Modules\Plans\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PlanService
{
    public function __construct(private readonly PlanPolicy $policy) {}

    private function utc(string $value): string
    {
        return Carbon::parse($value)->utc()->toDateTimeString();
    }

    private function offsets(string $user): array
    {
        $row=DB::table('plan_reminder_preferences')->where('user_id',$user)->first();
        if($row && !$row->enabled)return [];
        $offsets=$row ? json_decode((string)$row->offsets_json,true)
            : config('orbit_plans.default_reminder_offsets',[15,60]);
        return array_values(array_intersect(
            config('orbit_plans.allowed_reminder_offsets',[0,15,60,1440]),
            is_array($offsets)?array_map('intval',$offsets):[]
        ));
    }

    private function recipients(object $plan): array
    {
        return array_merge([(string)$plan->organizer_id],
            DB::table('plan_invitees')->where('plan_id',$plan->id)
                ->where('state','joined')->pluck('user_id')->all());
    }

    private function enqueueEvent(object $plan,string $user,string $type): void
    {
        DB::table('plan_change_events')->insert([
            'id'=>(string)Str::uuid(),'plan_id'=>$plan->id,'recipient_user_id'=>$user,
            'event_type'=>$type,'plan_revision'=>$plan->revision,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    private function reminders(object $plan): void
    {
        if(!$this->policy->control('reminders_enabled') || $plan->status!=='scheduled'
            || !now()->lt($plan->starts_at)) return;
        foreach(array_unique($this->recipients($plan)) as $user) {
            foreach($this->offsets($user) as $offset) {
                $due=Carbon::parse($plan->starts_at)->subMinutes($offset);
                if($due->isPast())continue;
                DB::table('plan_reminder_jobs')->insertOrIgnore([
                    'id'=>(string)Str::uuid(),'plan_id'=>$plan->id,'user_id'=>$user,
                    'plan_revision'=>$plan->revision,'offset_minutes'=>$offset,
                    'due_at'=>$due,'state'=>'pending',
                    'dedupe_key'=>hash('sha256',$plan->id.'|'.$plan->revision.'|'.$user.'|'.$offset),
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
        }
    }

    private function circleIds(string $plan): array
    {
        return DB::table('plan_circles')->where('plan_id',$plan)->pluck('circle_id')->all();
    }

    private function attachInvitees(object $plan,array $people,array $circles=[]): void
    {
        $circleIds=array_unique(array_merge($this->circleIds($plan->id),$circles));
        foreach($people as $person) {
            abort_unless($this->policy->allowedInvitee($plan->organizer_id,$person,$circleIds),
                422,'One or more invitees are unavailable.');
        }
        $invited=DB::table('plan_invitees')->where('plan_id',$plan->id)->count();
        $candidates=array_unique($people);
        foreach($circles as $circle) {
            // Select only explicit active members; Circle membership itself
            // NEVER bypasses plan-specific consent and current block checks.
            $members=DB::table('circle_members')->where('circle_id',$circle)
                ->whereNull('removed_at')->limit(501)->pluck('user_id');
            abort_unless($members->count()<=500,422,'Circle invitation exceeds safe bounds.');
            foreach($members as $person) {
                if($this->policy->allowedInvitee($plan->organizer_id,$person,$circleIds))
                    $candidates[]=$person;
            }
        }
        $candidates=array_values(array_unique(array_diff($candidates,[$plan->organizer_id])));
        $max=(int)config('orbit_plans.max_invitees',100);
        abort_unless(count($candidates)+$invited<=$max,422,'Too many plan invitees.');
        foreach($candidates as $person) {
            $id=(string)Str::uuid();
            $inserted=DB::table('plan_invitees')->insertOrIgnore([
                'id'=>$id,'plan_id'=>$plan->id,'user_id'=>$person,
                'state'=>'invited','created_at'=>now(),'updated_at'=>now(),
            ]);
            if($inserted)$this->enqueueEvent($plan,$person,'invited');
        }
    }

    public function create(string $actor,array $input): array
    {
        $this->policy->assertReady();
        abort_unless($this->policy->control('creation_enabled'),403,'Plan creation paused.');
        $circles=array_values(array_unique($input['circle_ids']??[]));
        $people=array_values(array_unique($input['invitee_ids']??[]));
        $starts=$this->utc($input['starts_at']);
        $ends=isset($input['ends_at']) && $input['ends_at']!==null
            ? $this->utc($input['ends_at']) : null;
        abort_unless(Carbon::parse($starts)->isFuture()
            && ($ends===null || $ends>$starts),422,'Invalid plan schedule.');
        $normalized=[
            'title'=>trim($input['title']),
            'description'=>trim($input['description']??''),
            'starts_at'=>$starts,'ends_at'=>$ends,
            'place_label'=>trim($input['place_label']??''),
            'place_ref'=>$input['place_ref']??null,
            'place_lat'=>$input['place_lat']??null,
            'place_lng'=>$input['place_lng']??null,
            'invitee_ids'=>$people,'circle_ids'=>$circles,
        ];
        sort($normalized['invitee_ids']);sort($normalized['circle_ids']);
        $hash=hash('sha256',json_encode($normalized,JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($actor,$input,$normalized,$hash):array {
            $id=(string)Str::uuid();
            $created=DB::table('plans')->insertOrIgnore([
                'id'=>$id,'organizer_id'=>$actor,'client_id'=>$input['client_id'],
                'payload_hash'=>$hash,
                'title'=>$normalized['title'],'description'=>$normalized['description'],
                'starts_at'=>$normalized['starts_at'],'ends_at'=>$normalized['ends_at'],
                'place_label'=>$normalized['place_label']?:null,
                'place_ref'=>$normalized['place_ref'],
                'place_lat'=>$normalized['place_lat'],'place_lng'=>$normalized['place_lng'],
                'status'=>'scheduled','revision'=>1,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $plan=DB::table('plans')->where('organizer_id',$actor)
                ->where('client_id',$input['client_id'])->first();
            abort_unless($plan && hash_equals((string)$plan->payload_hash,$hash),
                409,'Idempotency key reused with different data.');
            if(!$created)return [$this->present($plan,$actor),false];
            foreach($normalized['circle_ids'] as $circle) {
                abort_unless(app(\App\Modules\Circle\Services\CircleAccessPolicy::class)
                    ->isMember($circle,$actor),422,'Circle unavailable.');
                DB::table('plan_circles')->insert(['plan_id'=>$id,'circle_id'=>$circle]);
            }
            $this->attachInvitees($plan,$normalized['invitee_ids'],$normalized['circle_ids']);
            $this->reminders($plan);
            return [$this->present($plan,$actor),true];
        },3);
    }

    public function present(object $plan,string $viewer,bool $participants=false): array
    {
        $state=$viewer===$plan->organizer_id?'organizer':
            DB::table('plan_invitees')->where('plan_id',$plan->id)
                ->where('user_id',$viewer)->value('state');
        $data=[
            'id'=>$plan->id,'title'=>$plan->title,'description'=>$plan->description,
            'starts_at'=>Carbon::parse($plan->starts_at)->utc()->toIso8601String(),
            'ends_at'=>$plan->ends_at?Carbon::parse($plan->ends_at)->utc()->toIso8601String():null,
            'place'=>[
                'label'=>$plan->place_label,'ref'=>$plan->place_ref,
                'latitude'=>$plan->place_lat===null?null:(float)$plan->place_lat,
                'longitude'=>$plan->place_lng===null?null:(float)$plan->place_lng,
            ],
            'status'=>$plan->status,'revision'=>(int)$plan->revision,
            'organizer'=>DB::table('orbit_users')->where('id',$plan->organizer_id)
                ->first(['id','display_name']),
            'my_rsvp'=>$state,
            'can_edit'=>$plan->organizer_id===$viewer && $plan->status==='scheduled'
                && now()->lt($plan->starts_at),
        ];
        if($participants)$data['participants']=$this->participants($plan,$viewer);
        return $data;
    }

    public function participants(object $plan,string $viewer): array
    {
        abort_unless($this->policy->canViewRecord($plan,$viewer),404);
        return DB::table('plan_invitees as i')
            ->join('orbit_users as u','u.id','=','i.user_id')
            ->where('i.plan_id',$plan->id)->where('u.status','active')
            ->whereNull('u.deletion_requested_at')
            ->orderBy('u.display_name')->limit(101)
            ->get(['u.id','u.display_name','i.state'])->map(fn($r)=>[
                'id'=>$r->id,'display_name'=>$r->display_name,'state'=>$r->state
            ])->all();
    }

    public function listing(string $actor,string $tab,int $limit,?string $cursor): array
    {
        $limit=max(1,min((int)config('orbit_plans.page_limit',30),$limit));
        $query=DB::table('plans as p')
            ->leftJoin('plan_invitees as i',function($join)use($actor){
                $join->on('i.plan_id','=','p.id')->where('i.user_id','=',$actor);
            })
            ->where(fn($q)=>$q->where('p.organizer_id',$actor)
                ->orWhereIn('i.state',['invited','joined','declined']));
        if($tab==='upcoming')$query->where('p.status','scheduled')->where('p.starts_at','>=',now());
        else $query->where(fn($q)=>$q->where('p.starts_at','<',now())
            ->orWhere('p.status','cancelled'));
        if($cursor) {
            $raw=base64_decode(strtr($cursor,'-_','+/'),true);
            $parts=is_string($raw)?explode('|',$raw):[];
            abort_unless(count($parts)===2 && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/D',$parts[0])
                && Str::isUuid($parts[1]),422,'Invalid page cursor.');
            $query->where(fn($q)=>$q->where('p.starts_at',$tab==='upcoming'?'>':'<',$parts[0])
                ->orWhere(fn($w)=>$w->where('p.starts_at',$parts[0])
                    ->where('p.id',$tab==='upcoming'?'>':'<',$parts[1])));
        }
        $order=$tab==='upcoming'?'asc':'desc';
        $rows=$query->orderBy('p.starts_at',$order)->orderBy('p.id',$order)
            ->limit(min(501,$limit*10+1))->get(['p.*']);
        $out=[];$last=null;
        foreach($rows as $plan) {
            $last=$plan;
            if($this->policy->canViewRecord($plan,$actor))$out[]=$this->present($plan,$actor);
            if(count($out)>=$limit)break;
        }
        $more=$last && (count($out)>=$limit || count($rows)>=min(501,$limit*10+1));
        return ['items'=>$out,'next_cursor'=>$more?
            rtrim(strtr(base64_encode($last->starts_at.'|'.$last->id),'+/','-_'),'='):null];
    }

    public function update(string $id,string $actor,array $input): array
    {
        return DB::transaction(function()use($id,$actor,$input):array{
            $plan=DB::table('plans')->where('id',$id)->lockForUpdate()->first();
            abort_unless($plan && $this->policy->canViewRecord($plan,$actor),404);
            $this->policy->editable($plan,$actor);
            abort_unless((int)$input['revision']===(int)$plan->revision,409,'Plan has changed.');
            $start=isset($input['starts_at'])?$this->utc($input['starts_at']):$plan->starts_at;
            $end=array_key_exists('ends_at',$input)?($input['ends_at']
                ?$this->utc($input['ends_at']):null):$plan->ends_at;
            abort_unless(Carbon::parse($start)->isFuture()
                && ($end===null || $end>$start),422,'Invalid plan schedule.');
            $updates=['revision'=>(int)$plan->revision+1,'updated_at'=>now()];
            foreach(['title','description','place_label','place_ref','place_lat','place_lng'] as $field)
                if(array_key_exists($field,$input))$updates[$field]=$input[$field];
            $updates['starts_at']=$start;$updates['ends_at']=$end;
            DB::table('plans')->where('id',$id)->update($updates);
            $plan=DB::table('plans')->where('id',$id)->first();
            DB::table('plan_reminder_jobs')->where('plan_id',$id)
                ->whereIn('state',['pending','retry','processing'])
                ->update(['state'=>'suppressed','updated_at'=>now()]);
            $this->reminders($plan);
            $circleIds=array_unique($input['circle_ids']??[]);
            foreach($circleIds as $circle) {
                abort_unless(app(\App\Modules\Circle\Services\CircleAccessPolicy::class)
                    ->isMember($circle,$actor),422,'Circle unavailable.');
                DB::table('plan_circles')->insertOrIgnore(['plan_id'=>$id,'circle_id'=>$circle]);
            }
            if(!empty($input['invitee_ids']) || !empty($circleIds))
                $this->attachInvitees($plan,$input['invitee_ids']??[],$circleIds);
            foreach(DB::table('plan_invitees')->where('plan_id',$id)
                ->whereIn('state',['invited','joined'])->pluck('user_id') as $user)
                $this->enqueueEvent($plan,$user,'updated');
            return $this->present($plan,$actor,true);
        },3);
    }

    public function cancel(string $id,string $actor,int $revision): array
    {
        return DB::transaction(function()use($id,$actor,$revision):array{
            $plan=DB::table('plans')->where('id',$id)->lockForUpdate()->first();
            abort_unless($plan && $this->policy->canViewRecord($plan,$actor),404);
            $this->policy->editable($plan,$actor);
            abort_unless((int)$plan->revision===$revision,409,'Plan has changed.');
            $this->cancelRecord($plan);
            return $this->present(DB::table('plans')->where('id',$id)->first(),$actor);
        },3);
    }

    private function cancelRecord(object $plan): void
    {
        DB::table('plans')->where('id',$plan->id)->update([
            'status'=>'cancelled','revision'=>$plan->revision+1,
            'cancelled_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('plan_reminder_jobs')->where('plan_id',$plan->id)
            ->whereIn('state',['pending','retry','processing'])
            ->update(['state'=>'suppressed','updated_at'=>now()]);
        $updated=DB::table('plans')->where('id',$plan->id)->first();
        foreach(DB::table('plan_invitees')->where('plan_id',$plan->id)
            ->whereIn('state',['invited','joined'])->pluck('user_id') as $user)
            $this->enqueueEvent($updated,$user,'cancelled');
    }

    public function moderateCancel(string $id,string $admin,string $reason): void
    {
        DB::transaction(function()use($id,$admin,$reason):void{
            $plan=DB::table('plans')->where('id',$id)->lockForUpdate()->first();
            abort_unless($plan && $plan->status==='scheduled' && now()->lt($plan->starts_at),
                409,'This plan cannot be cancelled.');
            $this->cancelRecord($plan);
            DB::table('plan_admin_audits')->insert([
                'id'=>(string)Str::uuid(),'admin_id'=>$admin,'action'=>'plan.cancelled',
                'plan_hash'=>hash('sha256',$id),'reason'=>$reason,'created_at'=>now(),
            ]);
        },3);
    }

    public function respond(string $id,string $actor,string $state): array
    {
        return DB::transaction(function()use($id,$actor,$state):array{
            $plan=DB::table('plans')->where('id',$id)->lockForUpdate()->first();
            abort_unless($plan && $plan->status==='scheduled' && now()->lt($plan->starts_at),409,
                'Plan can no longer accept responses.');
            $invite=DB::table('plan_invitees')->where('plan_id',$id)
                ->where('user_id',$actor)->lockForUpdate()->first();
            abort_unless($invite,404,'Invitation unavailable.');
            abort_unless($this->policy->allowedInvitee(
                $plan->organizer_id,$actor,$this->circleIds($id)),404,'Invitation unavailable.');
            if($state==='left')abort_unless($invite->state==='joined',409,'Not joined.');
            elseif($state==='joined')abort_unless(in_array($invite->state,
                ['invited','declined','joined'],true),409,'Cannot join this plan.');
            else abort_unless(in_array($invite->state,
                ['invited','joined','declined'],true),409,'Cannot decline this plan.');
            if($invite->state!==$state){
                DB::table('plan_invitees')->where('id',$invite->id)->update([
                    'state'=>$state,'responded_at'=>now(),'updated_at'=>now(),
                ]);
                DB::table('plan_reminder_jobs')->where('plan_id',$id)->where('user_id',$actor)
                    ->whereIn('state',['pending','retry','processing'])
                    ->update(['state'=>'suppressed','updated_at'=>now()]);
                if($state==='joined')$this->reminders($plan);
                $this->enqueueEvent($plan,$plan->organizer_id,'rsvp');
            }
            return $this->present($plan,$actor);
        },3);
    }

    public function preferences(string $user): array
    {
        $row=DB::table('plan_reminder_preferences')->where('user_id',$user)->first();
        return ['enabled'=>$row?(bool)$row->enabled:true,
            'offsets_minutes'=>$row?json_decode($row->offsets_json,true)
                :config('orbit_plans.default_reminder_offsets',[15,60]),
            'allowed_offsets_minutes'=>config('orbit_plans.allowed_reminder_offsets',[0,15,60,1440])];
    }

    public function savePreferences(string $user,bool $enabled,array $offsets): array
    {
        $offsets=array_values(array_unique(array_map('intval',$offsets)));
        sort($offsets);
        abort_unless(count($offsets)<=4 && count(array_diff($offsets,
            config('orbit_plans.allowed_reminder_offsets',[0,15,60,1440])))===0,
            422,'Invalid reminder offsets.');
        DB::transaction(function()use($user,$enabled,$offsets):void{
            DB::table('plan_reminder_preferences')->updateOrInsert(['user_id'=>$user],[
                'enabled'=>$enabled,'offsets_json'=>json_encode($offsets),
                'updated_at'=>now(),
            ]);
            DB::table('plan_reminder_jobs')->where('user_id',$user)
                ->whereIn('state',['pending','retry','processing'])
                ->update(['state'=>'suppressed','updated_at'=>now()]);
            if($enabled){
                $ids=DB::table('plans as p')->leftJoin('plan_invitees as i',function($join)use($user){
                    $join->on('i.plan_id','=','p.id')->where('i.user_id','=',$user);
                })->where('p.status','scheduled')->where('p.starts_at','>',now())
                    ->where(fn($q)=>$q->where('p.organizer_id',$user)
                        ->orWhere('i.state','joined'))
                    ->limit(100)->pluck('p.id');
                foreach($ids as $id)$this->reminders(DB::table('plans')->where('id',$id)->first());
            }
        },3);
        return $this->preferences($user);
    }
}
