<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('organizer_id');
            $t->uuid('client_id');
            $t->char('payload_hash',64);
            $t->string('title',120);
            $t->text('description')->nullable();
            $t->timestamp('starts_at');
            $t->timestamp('ends_at')->nullable();
            $t->string('place_label',160)->nullable();
            $t->string('place_ref',255)->nullable();
            $t->decimal('place_lat',10,7)->nullable();
            $t->decimal('place_lng',10,7)->nullable();
            $t->string('status',16)->default('scheduled');
            $t->unsignedBigInteger('revision')->default(1);
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
            $t->unique(['organizer_id','client_id'],'plan_client_unique');
            $t->index(['organizer_id','starts_at'],'plan_organizer_schedule');
            $t->index(['status','starts_at'],'plan_schedule_state');
            $t->foreign('organizer_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
        Schema::create('plan_invitees', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('plan_id');
            $t->uuid('user_id');
            $t->string('state',16)->default('invited');
            $t->timestamp('responded_at')->nullable();
            $t->timestamps();
            $t->unique(['plan_id','user_id'],'plan_invitee_unique');
            $t->index(['user_id','state'],'plan_invitee_inbox');
            $t->foreign('plan_id')->references('id')->on('plans')->cascadeOnDelete();
            $t->foreign('user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
        Schema::create('plan_circles', function (Blueprint $t): void {
            $t->uuid('plan_id');
            $t->uuid('circle_id');
            $t->primary(['plan_id','circle_id']);
            $t->foreign('plan_id')->references('id')->on('plans')->cascadeOnDelete();
            $t->foreign('circle_id')->references('id')->on('circles')->cascadeOnDelete();
        });
        Schema::create('plan_reminder_preferences', function (Blueprint $t): void {
            $t->uuid('user_id')->primary();
            $t->boolean('enabled')->default(true);
            $t->json('offsets_json');
            $t->timestamps();
            $t->foreign('user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
        Schema::create('plan_reminder_jobs', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('plan_id');
            $t->uuid('user_id');
            $t->unsignedBigInteger('plan_revision');
            $t->unsignedInteger('offset_minutes');
            $t->timestamp('due_at');
            $t->string('state',16)->default('pending');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->string('error_code',48)->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->char('dedupe_key',64)->unique();
            $t->timestamps();
            $t->index(['state','due_at'],'plan_reminder_queue');
            $t->foreign('plan_id')->references('id')->on('plans')->cascadeOnDelete();
            $t->foreign('user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
        Schema::create('plan_change_events', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('plan_id');
            $t->uuid('recipient_user_id');
            $t->string('event_type',24);
            $t->unsignedBigInteger('plan_revision');
            $t->timestamp('projected_at')->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamps();
            $t->index(['projected_at','lease_until','created_at'],'plan_event_pending');
            $t->foreign('plan_id')->references('id')->on('plans')->cascadeOnDelete();
            $t->foreign('recipient_user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
        Schema::create('plan_controls', function (Blueprint $t): void {
            $t->string('key',40)->primary();
            $t->boolean('enabled')->default(true);
            $t->uuid('updated_by')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
        Schema::create('plan_admin_audits', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('admin_id');
            $t->string('action',48);
            $t->char('plan_hash',64)->nullable();
            $t->string('reason',500);
            $t->timestamp('created_at')->useCurrent();
            $t->index(['created_at','action']);
        });
        foreach (['creation_enabled','reminders_enabled'] as $key) {
            DB::table('plan_controls')->insert(['key'=>$key,'enabled'=>true,'updated_at'=>now()]);
        }
        // Generic templates only. No title, location or attendee information enters M14 notices.
        if (Schema::hasTable('notification_templates')) {
            foreach ([
                ['plans.invited','New plan invitation','A plan invitation is available in Orbit.'],
                ['plans.updated','Plan update','A plan you can access has been updated.'],
                ['plans.reminder','Plan reminder','An upcoming plan has a reminder.'],
            ] as [$key,$title,$body]) {
                DB::table('notification_templates')->insertOrIgnore([
                    'key'=>$key,'category'=>'plans','title'=>$title,'body'=>$body,
                    'enabled'=>true,'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
        }
    }
    public function down(): void
    {
        if (Schema::hasTable('notification_templates')) {
            DB::table('notification_templates')->whereIn('key',[
                'plans.invited','plans.updated','plans.reminder',
            ])->delete();
        }
        foreach ([
            'plan_admin_audits','plan_controls','plan_change_events',
            'plan_reminder_jobs','plan_reminder_preferences','plan_circles',
            'plan_invitees','plans',
        ] as $table) Schema::dropIfExists($table);
    }
};
