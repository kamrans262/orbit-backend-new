<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notification_circle_changes',function(Blueprint $t):void{
            $t->uuid('id')->primary();
            $t->uuid('circle_id');
            $t->uuid('recipient_user_id');
            $t->string('change_type',40);
            $t->timestamp('created_at')->useCurrent();
            $t->index(['created_at','change_type'],'notification_circle_change_feed');
            $t->foreign('circle_id')->references('id')->on('circles')->cascadeOnDelete();
            $t->foreign('recipient_user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('notification_circle_changes');
    }
};
