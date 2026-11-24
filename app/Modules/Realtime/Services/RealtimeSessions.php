<?php
namespace App\Modules\Realtime\Services;

use App\Models\OrbitSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class RealtimeSessions
{
    public function __construct(private readonly FirebaseRealtimeProvider $provider){}
    public function enabled(?string $module=null):bool
    {
        return config('orbit_realtime.enabled')===true&&config('orbit_realtime.verified')===true
            &&($module===null||config('orbit_realtime.modules.'.$module)===true);
    }
    private function nowMs():int{return (int)now()->getTimestampMs();}
    private function live(string $id):?object
    {
        return DB::table('orbit_sessions as s')->join('devices as d','d.id','=','s.device_id')
            ->join('orbit_users as u','u.id','=','s.user_id')->where('s.id',$id)
            ->whereColumn('s.user_id','d.user_id')->whereNull('s.revoked_at')->where('s.expires_at','>',now())
            ->where('d.trust_state','trusted')->whereNull('d.revoked_at')->whereNotNull('d.public_key')
            ->where('u.status','active')->whereNull('u.deletion_requested_at')
            ->first(['s.id','s.device_id','s.expires_at','d.platform']);
    }
    public function bootstrap(OrbitSession $session):array
    {
        if(!$this->enabled())return ['schema_version'=>1,'enabled'=>false,'transport'=>'https'];
        $id=(string)$session->id;
        try{
            $row=DB::transaction(function()use($id){
                // Same lock order used by the publisher; no stale auth snapshot renews a lease.
                DB::table('orbit_sessions')->where('id',$id)->lockForUpdate()->first();
                $live=$this->live($id);abort_unless($live,403);
                $client=$this->provider->client($live->platform);
                $current=DB::table('orbit_realtime_sessions')->where('session_id',$id)->lockForUpdate()->first();
                $generation=$current?->generation??(string)Str::uuid();
                $until=min($this->nowMs()+60000,\Illuminate\Support\Carbon::parse($live->expires_at)->getTimestampMs());
                $revision=(int)($current?->revision??0)+1;
                DB::table('orbit_realtime_sessions')->updateOrInsert(['session_id'=>$id],[
                    'device_id'=>$live->device_id,'generation'=>$generation,'lease_until_ms'=>$until,
                    'revision'=>$revision,'available_at'=>now(),'attempts'=>0,'updated_at'=>now(),
                    ...(!$current?['created_at'=>now()]:[]),
                ]);
                return ['firebase'=>$client,'generation'=>$generation,'device'=>$live->device_id,'lease_until_ms'=>$until];
            },3);
            if(!$this->publish($id))abort(503,'Realtime service unavailable.');
            // If revoked between signing and provider write, only a bounded
            // generic signal is accessible; all domain APIs independently deny.
            abort_unless($this->live($id),403);
            return ['schema_version'=>1,'enabled'=>true,'transport'=>'firebase_rtdb','uid'=>$id,
                'custom_token'=>$this->provider->customToken($id,$row['device'],$row['generation']),
                'firebase'=>$row['firebase'],'lease_until_ms'=>$row['lease_until_ms'],'renew_after_seconds'=>30,
                'signal_path'=>'orbit_m25/sessions/'.$id.'/signal'];
        }catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){throw $e;}
        catch(\Throwable){abort(503,'Realtime service unavailable.');}
    }
    /** Only server-selected recipients; no client supplied channel or payload. */
    public function signalUsers(string $module,array $users):void
    {
        if(!$this->enabled($module)||!$users)return;
        $users=array_values(array_unique($users));
        foreach(array_chunk($users,100) as $chunk){
            DB::table('orbit_realtime_sessions as r')->join('orbit_sessions as s','s.id','=','r.session_id')
                ->whereIn('s.user_id',$chunk)->whereNull('s.revoked_at')->where('s.expires_at','>',now())
                ->where('r.lease_until_ms','>',$this->nowMs())->select('r.session_id')
                ->orderBy('r.session_id')->chunkById(50,function($rows){
                    $ids=$rows->pluck('session_id')->all();
                    DB::table('orbit_realtime_sessions')->whereIn('session_id',$ids)
                        ->increment('revision',1,['updated_at'=>now()]);
                    // Bounded immediate sends; the durable dirty revision remains for cron.
                    DB::afterCommit(function()use($ids){
                        foreach(array_slice($ids,0,2) as $id)$this->publish($id);
                    });
                },'r.session_id','session_id');
        }
    }
    public function publish(string $id):bool
    {
        if(!$this->enabled())return false;
        try{
            return DB::transaction(function()use($id):bool{
                DB::table('orbit_sessions')->where('id',$id)->lockForUpdate()->first();
                $r=DB::table('orbit_realtime_sessions')->where('session_id',$id)->lockForUpdate()->first();
                if(!$r)return false;
                $live=$this->live($id);
                if(!$live||$live->device_id!==$r->device_id||(int)$r->lease_until_ms<=$this->nowMs()){
                    $this->provider->replace($id,null);
                    DB::table('orbit_realtime_sessions')->where('session_id',$id)->delete();
                    return false;
                }
                if((int)$r->published_revision===(int)$r->revision)return true;
                $this->provider->replace($id,[
                    'lease'=>['device'=>$r->device_id,'generation'=>$r->generation,'until_ms'=>(int)$r->lease_until_ms],
                    'signal'=>['schema_version'=>1,'revision'=>(int)$r->revision],
                ]);
                DB::table('orbit_realtime_sessions')->where('session_id',$id)->update([
                    'published_revision'=>$r->revision,'attempts'=>0,'available_at'=>now(),'last_success_at'=>now(),
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
                        'attempts'=>$attempts,'available_at'=>now()->addSeconds(min(300,2**min(8,$attempts))+random_int(0,5)),
                    ]);
                });
            }catch(\Throwable){}
            Log::warning('M25 realtime publication deferred.');
            return false;
        }
    }
    public function process():int
    {
        if(!$this->enabled())return 0;
        $ids=DB::table('orbit_realtime_sessions as r')->leftJoin('orbit_sessions as s','s.id','=','r.session_id')
            ->where('r.available_at','<=',now())->where(function($q){
                $q->whereColumn('r.revision','>','r.published_revision')->orWhere('r.lease_until_ms','<=',$this->nowMs())
                    ->orWhereNull('s.id')->orWhereNotNull('s.revoked_at')->orWhere('s.expires_at','<=',now());
            })->orderBy('r.available_at')->limit(20)->pluck('r.session_id');
        foreach($ids as $id)$this->publish($id);
        return $ids->count();
    }
}
