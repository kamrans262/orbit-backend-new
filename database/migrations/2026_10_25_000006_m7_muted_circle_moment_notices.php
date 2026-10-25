<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void{
        Schema::create('moment_notification_muted_circles',function(Blueprint $t):void{
            $t->uuid('user_id');
            $t->uuid('circle_id');
            $t->timestamps();
            $t->primary(['user_id','circle_id']);
            $t->foreign('user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
            $t->foreign('circle_id')->references('id')->on('circles')->cascadeOnDelete();
        });
    }
    public function down():void{
        Schema::dropIfExists('moment_notification_muted_circles');
    }
};
