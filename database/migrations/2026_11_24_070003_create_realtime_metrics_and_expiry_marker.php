<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        Schema::create('orbit_realtime_metrics',function(Blueprint $t):void{
            $t->timestamp('hour')->primary();
            $t->unsignedBigInteger('published')->default(0);
            $t->unsignedBigInteger('failed')->default(0);
            $t->unsignedBigInteger('payload_bytes')->default(0);
            $t->unsignedBigInteger('total_ms')->default(0);
        });
        Schema::table('checkins',function(Blueprint $t):void{$t->timestamp('realtime_expiry_notified_at')->nullable();});
    }
    public function down():void {
        Schema::table('checkins',fn(Blueprint $t)=>$t->dropColumn('realtime_expiry_notified_at'));
        Schema::dropIfExists('orbit_realtime_metrics');
    }
};
