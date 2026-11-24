<?php
namespace App\Modules\Realtime\Services;

use App\Models\OrbitSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class RealtimeSessions
{
    private int $inlineSent=0;
    private function limit(string $key):int
    {
        return app(\App\Modules\Config\Services\OperationalControls::class)->effective('realtime',$key);
    }
    public function __construct(private readonly FirebaseRealtimeProvider $provider){}
    public function enabled(?string $module=null):bool
    {
        if(config('orbit_realtime.enabled')!==true||config('orbit_realtime.verified')!==true)return false;
        try{
            return \Illuminate\Support\Facades\Schema::hasTable('orbit_realtime_sessions')&&$this->limit('allow')===1&&($module===null||
                (config('orbit_realtime.modules.'.$module)===true&&$this->limit('allow_'.$module)===1));
        }catch(\Throwable){return false;}

    }
    /** Cleanup is allowed during an Admin pause, never when deployment is off. */
    public function cleanupEnabled():bool
    {
        return config('orbit_realtime.enabled')===true&&config('orbit_realtime.verified')===true
            &&\Illuminate\Support\Facades\Schema::hasTable('orbit_realtime_sessions');
    }
    private function nowMs():int{return (int)now()->getTimestampMs();}
    private function live(string $id):?object
    {
        return DB::table('orbit_sessions as s')->join('devices as d','d.id','=','s.device_id')
            ->join('orbit_users as u','u.id','=','s.user_id')->where('s.id',$id)
            ->whereColumn('s.user_id','d.user_id')->whereNull('s.revoked_at')->where('s.expires_at','>',now())
            ->where('d.trust_state','trusted')->whereNull('d.revoked_at')->whereNotNull('d.public_key')
            ->where('u.status','active')->whereNull('u.deletion_requested_at')
            ->first(['s.id','s.user_id','s.device_id','s.expires_at','d.platform']);
    }
    public function bootstrap(OrbitSession $session):array
    {
        if(!$this->enabled())return ['schema_version'=>1,'enabled'=>false,'transport'=>'https'];
        $id=(string)$session->id;
        try{
            $row=\Illuminate\Support\Facades\Cache::store('orbit_ephemeral')->lock('orbit:m25:admission',10)->block(2,fn()=>DB::transaction(function()use($id){
                // Same lock order used by the publisher; no stale auth snapshot renews a lease.
                DB::table('orbit_sessions')->where('id',$id)->lockForUpdate()->first();
                $live=$this->live($id);abort_unless($live,403);
                $client=$this->provider->client($live->platform);
                $current=DB::table('orbit_realtime_sessions')->where('session_id',$id)->lockForUpdate()->first();
                if(!$current||$current->lease_until_ms<=$this->nowMs()){
                    // Serialize foreground admission independently of provider I/O.
                    abort_if(DB::table('orbit_realtime_sessions')->where('lease_until_ms','>',$this->nowMs())->count()>=$this->limit('connections'),503,'Realtime capacity reached.');
                }
                $fresh=$current===null||(int)$current->lease_until_ms<=$this->nowMs();
                $generation=$fresh?(string)Str::uuid():$current->generation;
                $until=min($this->nowMs()+60000,\Illuminate\Support\Carbon::parse($live->expires_at)->getTimestampMs());
                $revision=(int)($current?->revision??0)+($fresh?1:0);
                app(RealtimeAuthCleanup::class)->register($id);
                DB::table('orbit_realtime_sessions')->updateOrInsert(['session_id'=>$id],[
                    'device_id'=>$live->device_id,'generation'=>$generation,'lease_until_ms'=>$until,
                    'revision'=>$revision,'visible_revision'=>$fresh?max(1,(int)($current?->visible_revision??0)+1):(int)$current->visible_revision,
                    ...($fresh?['pending_scopes'=>null]:[]),'dirty'=>true,'available_at'=>now(),'attempts'=>0,'updated_at'=>now(),
                    ...(!$current?['created_at'=>now()]:[]),
                ]);
                return ['firebase'=>$client,'generation'=>$generation,'device'=>$live->device_id,'lease_until_ms'=>$until];
            },3));
            if(!$this->publish($id))abort(503,'Realtime service unavailable.');
            // If revoked between signing and provider write, only a bounded
            // generic signal is accessible; all domain APIs independently deny.
            abort_unless($this->live($id),403);
            return ['schema_version'=>1,'enabled'=>true,'transport'=>'firebase_rtdb','uid'=>$id,
                'custom_token'=>$this->provider->customToken($id,$row['device'],$row['generation']),
                'firebase'=>$row['firebase'],'generation'=>$row['generation'],'modules'=>array_values(array_filter(array_keys(config('orbit_realtime.modules',[])),fn($m)=>$this->enabled($m))),'lease_until_ms'=>$row['lease_until_ms'],'renew_after_seconds'=>30,'fallback_min_poll_seconds'=>max(25,(int)ceil(60/max(1,$this->limit('poll_budget')))),
                'signal_path'=>'orbit_m25/sessions/'.$id.'/signal'];
        }catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){throw $e;}
        catch(\Throwable){abort(503,'Realtime service unavailable.');}
    }
    /** Only server-selected recipients; no client supplied channel or payload. */
    public function signalUsers(string $module,array $users,?array $scope=null):void
    {
        if(!$this->enabled($module)||!$users)return;
        $users=array_slice(array_values(array_unique($users)),0,1000);
        // Unscoped mutation-time invalidations are deliberately very short lived.
        $scope=$scope??['kind'=>'clear'];
        $scope['module']=$module;$scope['until']=$this->nowMs()+($scope['kind']==='clear'?5000:60000);
        foreach(array_chunk($users,100) as $chunk){
            DB::table('orbit_realtime_sessions as r')->join('orbit_sessions as s','s.id','=','r.session_id')
                ->join('devices as d','d.id','=','s.device_id')->join('orbit_users as u','u.id','=','s.user_id')
                ->whereIn('s.user_id',$chunk)->whereColumn('s.user_id','d.user_id')->whereNull('s.revoked_at')->where('s.expires_at','>',now())
                ->where('d.trust_state','trusted')->whereNull('d.revoked_at')->whereNotNull('d.public_key')
                ->where('u.status','active')->whereNull('u.deletion_requested_at')
                ->where('r.lease_until_ms','>',$this->nowMs())->select('r.session_id')
                ->orderBy('r.session_id')->chunkById(50,function($rows)use($module,$scope){
                    $ids=$rows->pluck('session_id')->all();
                    DB::transaction(function()use($ids,$scope){
                        foreach(DB::table('orbit_realtime_sessions')->whereIn('session_id',$ids)->orderBy('session_id')->lockForUpdate()->get() as $row){
                            if((int)$row->lease_until_ms<=$this->nowMs())continue;
                            $pending=json_decode($row->pending_scopes??'[]',true)?:[];
                            $pending=array_values(array_filter($pending,fn($v)=>($v['until']??0)>$this->nowMs()));
                            // Bounded latest descriptors only; duplicate sources coalesce.
                            $key=hash('sha256',json_encode(array_diff_key($scope,['until'=>true]),JSON_THROW_ON_ERROR));
                            $found=false;
                            foreach($pending as &$entry){
                                if(($entry['key']??null)===$key){
                                    $entry['count']=min(100,(int)$entry['count']+1);
                                    $entry['until']=$scope['until'];$found=true;break;
                                }
                            }
                            unset($entry);
                            if(!$found)$pending[]=$scope+['key'=>$key,'count'=>1];
                            $pending=array_slice($pending,-32);
                            DB::table('orbit_realtime_sessions')->where('session_id',$row->session_id)->increment('revision',1,[
                                'pending_scopes'=>json_encode($pending,JSON_THROW_ON_ERROR),'dirty'=>true,'updated_at'=>now(),
                            ]);
                        }
                    });
                    // Bounded immediate sends; the durable dirty revision remains for cron.
                    if($module!=='sos')DB::afterCommit(fn()=>$this->inline($ids));
                },'r.session_id','session_id');
        }
    }
    private function inline(array $ids):void
    {
        try{
            foreach($ids as $id){
                if($this->inlineSent >= $this->limit('inline_batch'))break;
                if(!DB::table('orbit_realtime_sessions')->where('session_id',$id)->where('available_at','<=',now())->exists())continue;
                $this->inlineSent++;
                $this->publish($id);
            }
        }catch(\Throwable){Log::warning('M25 immediate publication deferred.');}
    }
    public function publish(string $id):bool
    {
        if(!$this->cleanupEnabled())return false;
        $started=hrtime(true);
        try{
            return DB::transaction(function()use($id,$started):bool{
                DB::table('orbit_sessions')->where('id',$id)->lockForUpdate()->first();
                $r=DB::table('orbit_realtime_sessions')->where('session_id',$id)->lockForUpdate()->first();
                if(!$r)return false;
                $live=$this->live($id);
                if(!$this->enabled()||!$live||$live->device_id!==$r->device_id||(int)$r->lease_until_ms<=$this->nowMs()){
                    // Fence stale conditional PUTs with a nonempty, deny-only tombstone.
                    app(RealtimeAuthCleanup::class)->register($id);
                    $tombstone=['revoked'=>(string)Str::uuid()];
                    $this->provider->replace($id,$tombstone);
                    app(RealtimeAuthCleanup::class)->markTombstone($id);
                    DB::table('orbit_realtime_sessions')->where('session_id',$id)->delete();
                    return false;
                }
                if(!$r->dirty)return true;
                $visible=(int)$r->visible_revision;
                $pending=json_decode($r->pending_scopes??'[]',true)?:[];
                foreach($pending as $scope){
                    if(($scope['until']??0)<=$this->nowMs()||!$this->enabled($scope['module']??''))continue;
                    if(app(RealtimeSignalPolicy::class)->allows($scope,(string)$live->user_id,(string)$live->device_id))
                        $visible+=max(1,min(100,(int)($scope['count']??1)));
                }
                $payload=[
                    'lease'=>['device'=>$r->device_id,'generation'=>$r->generation,'until_ms'=>(int)$r->lease_until_ms],
                    'signal'=>['schema_version'=>1,'revision'=>$visible],
                ];
                $this->provider->replace($id,$payload);
                app(RealtimeMetrics::class)->record('published',strlen(json_encode($payload,JSON_THROW_ON_ERROR)),(int)((hrtime(true)-$started)/1000000));
                DB::table('orbit_realtime_sessions')->where('session_id',$id)->update([
                    'published_revision'=>$r->revision,'visible_revision'=>$visible,'pending_scopes'=>null,'dirty'=>false,'attempts'=>0,'available_at'=>now(),'last_success_at'=>now(),
                ]);
                return true;
            },1);
        }catch(\Throwable){
            // Preserve the durable dirty revision; no sensitive details in logs.
            try{
                DB::transaction(function()use($id){
                    $r=DB::table('orbit_realtime_sessions')->where('session_id',$id)->lockForUpdate()->first();
                    if(!$r)return;
                    $attempts=min(20,(int)$r->attempts+1);
                    DB::table('orbit_realtime_sessions')->where('session_id',$id)->update([
                        'attempts'=>$attempts,'available_at'=>now()->addSeconds(min($this->limit('retry_delay'),(2**min(8,$attempts))+random_int(0,5))),
                    ]);
                });
            }catch(\Throwable){}
            app(RealtimeMetrics::class)->record('failed',0,(int)((hrtime(true)-$started)/1000000));
            Log::warning('M25 realtime publication deferred.');
            return false;
        }
    }
    /** Mark revoked/removed access for cleanup; never renew from this path. */
    public function revokeInvalidUser(string $user):void
    {
        if(!\Illuminate\Support\Facades\Schema::hasTable('orbit_realtime_sessions'))return;
        $ids=DB::table('orbit_realtime_sessions as r')->join('orbit_sessions as s','s.id','=','r.session_id')
            ->where('s.user_id',$user)->pluck('r.session_id')->all();
        foreach($ids as $id){
            if($this->live($id))continue;
            DB::table('orbit_realtime_sessions')->where('session_id',$id)->update([
                'lease_until_ms'=>0,'pending_scopes'=>null,'dirty'=>true,'available_at'=>now(),
            ]);
            DB::afterCommit(fn()=>$this->inline([$id]));
        }
    }
    public function markErasedSessions(array $ids):void
    {
        if(!$ids||!\Illuminate\Support\Facades\Schema::hasTable('orbit_realtime_sessions'))return;
        DB::table('orbit_realtime_sessions')->whereIn('session_id',$ids)->update([
            'lease_until_ms'=>0,'dirty'=>true,'available_at'=>now(),
        ]);
        DB::afterCommit(fn()=>$this->inline($ids));
    }
    public function pruneEphemeral():void
    {
        app(RealtimeMetrics::class)->prune();
        // Pending authorization descriptors cannot survive expired access, even during provider outage.
        DB::table('orbit_realtime_sessions')->where('lease_until_ms','<=',$this->nowMs())->whereNotNull('pending_scopes')->update(['pending_scopes'=>null]);
        foreach(['orbit_ephemeral_cache','orbit_ephemeral_locks'] as $table){
            $keys=DB::table($table)->where('expiration','<=',now()->timestamp)->orderBy('expiration')->limit(1000)->pluck('key');
            DB::table($table)->whereIn('key',$keys)->where('expiration','<=',now()->timestamp)->delete();
        }
    }
    /** Explicit foreground disconnect; the same authenticated session may later rebootstrap. */
    public function disconnect(OrbitSession $session):void
    {
        if(!\Illuminate\Support\Facades\Schema::hasTable('orbit_realtime_sessions'))return;
        $id=(string)$session->id;
        DB::transaction(function()use($id){
            DB::table('orbit_sessions')->where('id',$id)->lockForUpdate()->first();
            DB::table('orbit_realtime_sessions')->where('session_id',$id)->update([
                'lease_until_ms'=>0,'dirty'=>true,'available_at'=>now(),
            ]);
        });
        if($this->cleanupEnabled())$this->publish($id);
    }

    /** Only reschedule a bounded eligible retry set; never accept arbitrary session IDs. */
    public function requestRetry():int
    {
        if(!$this->cleanupEnabled())return 0;
        $ids=DB::table('orbit_realtime_sessions')->where('attempts','>',0)
            ->orderBy('available_at')->orderBy('session_id')->limit($this->limit('worker_batch'))
            ->lockForUpdate()->pluck('session_id');
        $count=DB::table('orbit_realtime_sessions')->whereIn('session_id',$ids)->update(['available_at'=>now()]);
        $remaining=max(0,$this->limit('worker_batch')-$count);
        if($remaining){
            $cleanup=DB::table('orbit_realtime_auth_receipts')->where('attempts','>',0)->orderBy('available_at')->limit($remaining)->lockForUpdate()->pluck('session_id');
            $count+=DB::table('orbit_realtime_auth_receipts')->whereIn('session_id',$cleanup)->update(['available_at'=>now()]);
        }
        return $count;
    }

    public function process():int
    {
        if(!$this->cleanupEnabled())return 0;
        $paused=!$this->enabled();
        $ids=DB::table('orbit_realtime_sessions as r')->leftJoin('orbit_sessions as s','s.id','=','r.session_id')
            ->leftJoin('devices as d','d.id','=','s.device_id')->leftJoin('orbit_users as u','u.id','=','s.user_id')
            ->where('r.available_at','<=',now())->where(function($q)use($paused){
                if($paused){$q->whereRaw('1=1');return;}
                $q->where('r.dirty',true)->orWhere('r.lease_until_ms','<=',$this->nowMs())
                    ->orWhereNull('s.id')->orWhereNotNull('s.revoked_at')->orWhere('s.expires_at','<=',now())
                    ->orWhereNull('d.id')->orWhereNotNull('d.revoked_at')->orWhere('d.trust_state','!=','trusted')
                    ->orWhereNull('u.id')->orWhere('u.status','!=','active')->orWhereNotNull('u.deletion_requested_at');
            })->orderBy('r.available_at')->orderBy('r.session_id')->limit($this->limit('worker_batch'))->pluck('r.session_id');
        foreach($ids as $id)$this->publish($id);
        return $ids->count();
    }
}
