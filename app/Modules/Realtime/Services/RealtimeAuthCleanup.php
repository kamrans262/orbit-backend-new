<?php
namespace App\Modules\Realtime\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

final class RealtimeAuthCleanup
{
    public function __construct(private readonly FirebaseRealtimeProvider $provider){}
    /** Called under the Orbit session row lock before a new lease is published. */
    public function register(string $id):void
    {
        $hash=$this->provider->projectFingerprint();
        $r=DB::table('orbit_realtime_auth_receipts')->where('session_id',$id)->lockForUpdate()->first();
        if($r&&!hash_equals($r->project_hash,$hash))throw new \RuntimeException('Realtime project change requires reviewed cleanup.');
        DB::table('orbit_realtime_auth_receipts')->updateOrInsert(['session_id'=>$id],[
            'project_hash'=>$hash,'tombstone_after'=>null,'auth_after'=>null,'available_at'=>now(),'attempts'=>0,
        ]);
    }
    public function markTombstone(string $id):void
    {
        DB::table('orbit_realtime_auth_receipts')->where('session_id',$id)->update([
            'tombstone_after'=>now()->addMinutes(2),'available_at'=>now(),
        ]);
    }
    public function pendingForUser(string $user):bool
    {
        if(!Schema::hasTable('orbit_realtime_auth_receipts'))return false;
        return DB::table('orbit_realtime_auth_receipts as r')->join('orbit_sessions as s','s.id','=','r.session_id')
            ->where('s.user_id',$user)->exists();
    }
    public function process():int
    {
        if(!app(RealtimeSessions::class)->cleanupEnabled()||!Schema::hasTable('orbit_realtime_auth_receipts'))return 0;
        $ids=DB::table('orbit_realtime_auth_receipts as r')->leftJoin('orbit_sessions as s','s.id','=','r.session_id')
            ->where('r.available_at','<=',now())->where(function($q){
                $q->where('r.tombstone_after','<=',now())
                    ->orWhere(fn($q)=>$q->whereNull('r.auth_after')->where(fn($q)=>$q->whereNull('s.id')->orWhereNotNull('s.revoked_at')->orWhere('s.expires_at','<=',now())))
                    ->orWhere('r.auth_after','<=',now());
            })->orderBy('r.available_at')->limit(10)->pluck('r.session_id');
        foreach($ids as $id){
            try{
                DB::transaction(function()use($id){
                    $session=DB::table('orbit_sessions')->where('id',$id)->lockForUpdate()->first();
                    $r=DB::table('orbit_realtime_auth_receipts')->where('session_id',$id)->lockForUpdate()->first();
                    if(!$r)return;
                    if(!hash_equals($r->project_hash,$this->provider->projectFingerprint()))throw new \RuntimeException('Cleanup project mismatch.');
                    // Never delete a fresh lease or interfere with a current Orbit session.
                    if(DB::table('orbit_realtime_sessions')->where('session_id',$id)->exists())return;
                    $ended=!$session||$session->revoked_at!==null||now()->gte($session->expires_at);
                    if($r->tombstone_after&&now()->gte($r->tombstone_after)){
                        $this->provider->replace($id,null);
                        DB::table('orbit_realtime_auth_receipts')->where('session_id',$id)->update(['tombstone_after'=>null]);
                    }
                    if(!$ended)return;
                    if(!$r->auth_after){
                        // Legacy M5 custom tokens last 900s. Allow that lifetime
                        // plus clock skew before deleting the shared session UID.
                        DB::table('orbit_realtime_auth_receipts')->where('session_id',$id)->update(['auth_after'=>now()->addMinutes(16)]);
                        return;
                    }
                    if(now()->lt($r->auth_after))return;
                    $this->provider->deleteAuth($id,$r->project_hash);
                    DB::table('orbit_realtime_auth_receipts')->where('session_id',$id)->delete();
                },1);
            }catch(\Throwable){
                DB::table('orbit_realtime_auth_receipts')->where('session_id',$id)->increment('attempts',1,['available_at'=>now()->addMinutes(5)]);
                Log::warning('M25 remote metadata cleanup deferred.');
            }
        }
        return $ids->count();
    }
}
