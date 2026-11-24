<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        Schema::table('orbit_realtime_sessions',function(Blueprint $t):void{
            $t->unsignedBigInteger('visible_revision')->default(1);
            $t->json('pending_scopes')->nullable();
        });
        // Never replay pre-upgrade hints for which source authorization was not recorded.
        \Illuminate\Support\Facades\DB::table('orbit_realtime_sessions')->update(['lease_until_ms'=>0,'dirty'=>true]);
    }
    public function down():void {
        Schema::table('orbit_realtime_sessions',fn(Blueprint $t)=>$t->dropColumn(['visible_revision','pending_scopes']));
    }
};
