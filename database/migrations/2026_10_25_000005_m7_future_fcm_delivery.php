<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        Schema::create('moment_push_devices',function(Blueprint $t):void{
            $t->uuid('device_id')->primary();
            $t->uuid('user_id');
            $t->longText('token_ciphertext');
            $t->char('token_digest',64)->unique();
            $t->string('platform',12);
            $t->timestamp('disabled_at')->nullable();
            $t->timestamps();
            $t->index(['user_id','disabled_at']);
            $t->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();
            $t->foreign('user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
        Schema::create('moment_push_deliveries',function(Blueprint $t):void{
            $t->uuid('notice_id');
            $t->uuid('device_id');
            $t->string('state',16)->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->string('last_error_code',60)->nullable();
            $t->timestamps();
            $t->primary(['notice_id','device_id']);
            $t->index(['state','next_attempt_at']);
            $t->foreign('notice_id')->references('id')->on('moment_notification_inbox')->cascadeOnDelete();
            $t->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();
        });
    }
    public function down():void{
        Schema::dropIfExists('moment_push_deliveries');
        Schema::dropIfExists('moment_push_devices');
    }
};
