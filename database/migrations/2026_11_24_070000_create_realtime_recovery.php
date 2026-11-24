<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        Schema::create('orbit_realtime_recovery',function(Blueprint $t):void{
            $t->uuid('id')->primary();
            $t->string('operation',12);
            $t->string('result',32)->nullable();
            $t->unsignedInteger('affected')->default(0);
            $t->timestamp('processed_at')->nullable();
            $t->timestamp('created_at')->index();
        });
    }
    public function down():void {Schema::dropIfExists('orbit_realtime_recovery');}
};
