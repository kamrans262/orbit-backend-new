<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {
        Schema::table('orbit_realtime_auth_receipts',function(Blueprint $t):void{
            $t->char('owner_hash',64)->nullable()->index();
        });
        DB::table('orbit_realtime_auth_receipts as r')->join('orbit_sessions as s','s.id','=','r.session_id')
            ->select('r.session_id','s.user_id')->orderBy('r.session_id')->chunkById(100,function($rows){
                foreach($rows as $row)DB::table('orbit_realtime_auth_receipts')->where('session_id',$row->session_id)
                    ->update(['owner_hash'=>hash_hmac('sha256','m25:'.$row->user_id,(string)config('app.key'))]);
            },'r.session_id','session_id');
        // Pre-upgrade orphan receipts are deliberately unassigned and block
        // erasure until verified remote cleanup; never guess their owner.
    }
    public function down():void {
        Schema::table('orbit_realtime_auth_receipts',fn(Blueprint $t)=>$t->dropColumn('owner_hash'));
    }
};
