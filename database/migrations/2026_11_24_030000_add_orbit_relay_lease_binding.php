<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {
        Schema::table('relay_leases',function(Blueprint $t):void{
            $t->uuid('session_id')->nullable();
            $t->string('roster_hash',64)->nullable();
            $t->unsignedInteger('source_epoch')->nullable();
        });
    }
    public function down():void {
        Schema::table('relay_leases',fn(Blueprint $t)=>$t->dropColumn(['session_id','roster_hash','source_epoch']));
    }
};
