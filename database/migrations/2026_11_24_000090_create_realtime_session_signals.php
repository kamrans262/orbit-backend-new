<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        Schema::create('orbit_ephemeral_cache',function(Blueprint $t):void{
            $t->string('key')->primary();$t->mediumText('value');$t->integer('expiration')->index();
        });
        Schema::create('orbit_ephemeral_locks',function(Blueprint $t):void{
            $t->string('key')->primary();$t->string('owner');$t->integer('expiration')->index();
        });
        Schema::create('orbit_realtime_sessions',function(Blueprint $t):void{
            // No user ID or content. Retain orphan session pointer until provider cleanup succeeds.
            $t->uuid('session_id')->primary();
            $t->uuid('device_id');
            $t->uuid('generation');
            $t->unsignedBigInteger('revision')->default(1);
            $t->unsignedBigInteger('published_revision')->default(0);
            $t->unsignedBigInteger('lease_until_ms')->index();
            $t->unsignedInteger('attempts')->default(0);
            $t->boolean('dirty')->default(true)->index();
            $t->timestamp('available_at')->index();
            $t->timestamp('last_success_at')->nullable();
            $t->timestamps();
        });
    }
    public function down():void {Schema::dropIfExists('orbit_realtime_sessions');Schema::dropIfExists('orbit_ephemeral_locks');Schema::dropIfExists('orbit_ephemeral_cache');}
};
