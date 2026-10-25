<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        // Independent cursor: a private inbox projection must NEVER consume
        // the main durable Moment outbox needed by other approved transports.
        Schema::create('moment_notification_projected_events',function(Blueprint $t):void{
            $t->uuid('event_id')->primary();
            $t->timestamp('processed_at')->useCurrent();
            $t->foreign('event_id')->references('id')
                ->on('moment_events_outbox')->cascadeOnDelete();
        });
        Schema::create('moment_notification_inbox',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('moment_id');
            $t->uuid('event_id');
            $t->uuid('recipient_user_id');
            $t->string('context_id',64);
            $t->timestamp('created_at')->useCurrent();
            $t->timestamp('read_at')->nullable();
            $t->unique(['event_id','recipient_user_id','context_id'],'m7_notice_delivery_unique');
            $t->index(['recipient_user_id','created_at'],'m7_notice_recipient_date');
            $t->foreign('moment_id')->references('id')->on('moments')->cascadeOnDelete();
            $t->foreign('event_id')->references('id')->on('moment_events_outbox')->cascadeOnDelete();
            $t->foreign('recipient_user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
    }
    public function down():void {
        Schema::dropIfExists('moment_notification_inbox');
        Schema::dropIfExists('moment_notification_projected_events');
    }
};
