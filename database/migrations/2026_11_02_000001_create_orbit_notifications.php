<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notification_events', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('source_type', 32);
            $t->uuid('source_id');
            $t->string('category', 24);
            $t->string('type', 48);
            $t->uuid('target_id');
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['source_type', 'source_id'], 'notification_source_unique');
            $t->index(['category', 'created_at']);
        });
        Schema::create('notification_inbox', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('event_id');
            $t->uuid('recipient_user_id');
            $t->string('category', 24);
            $t->string('type', 48);
            $t->uuid('target_id');
            $t->string('context_id', 80)->nullable();
            $t->string('template_key', 64);
            $t->timestamp('created_at')->useCurrent();
            $t->timestamp('read_at')->nullable();
            $t->timestamp('invalidated_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->unique(['event_id', 'recipient_user_id'], 'notification_inbox_event_user');
            $t->index(['recipient_user_id', 'created_at', 'id'], 'notification_inbox_owner_page');
            $t->index(['recipient_user_id', 'read_at', 'invalidated_at'], 'notification_inbox_unread');
            $t->foreign('event_id')->references('id')->on('notification_events')->cascadeOnDelete();
            $t->foreign('recipient_user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
        Schema::create('notification_device_tokens', function (Blueprint $t): void {
            $t->uuid('device_id')->primary();
            $t->uuid('user_id');
            $t->string('platform', 12);
            $t->string('project_id', 100);
            $t->longText('token_ciphertext');
            $t->char('token_digest', 64)->unique();
            $t->timestamp('disabled_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'disabled_at']);
            $t->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();
            $t->foreign('user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
        Schema::create('notification_jobs', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('notice_id');
            $t->uuid('device_id');
            $t->string('channel', 12)->default('push');
            $t->string('state', 20)->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->string('last_error_code', 64)->nullable();
            $t->timestamps();
            $t->unique(['notice_id', 'device_id', 'channel'], 'notification_job_unique');
            $t->index(['state', 'next_attempt_at', 'lease_until'], 'notification_job_pending');
            $t->foreign('notice_id')->references('id')->on('notification_inbox')->cascadeOnDelete();
            $t->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();
        });
        Schema::create('notification_attempts', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('job_id');
            $t->string('provider', 20);
            $t->string('state', 20);
            $t->string('error_code', 64)->nullable();
            $t->timestamp('attempted_at')->useCurrent();
            $t->index(['job_id', 'attempted_at']);
            $t->foreign('job_id')->references('id')->on('notification_jobs')->cascadeOnDelete();
        });
        Schema::create('notification_controls', function (Blueprint $t): void {
            $t->string('key', 64)->primary();
            $t->boolean('enabled')->default(true);
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
        });
        Schema::create('notification_templates', function (Blueprint $t): void {
            $t->string('key', 64)->primary();
            $t->string('category', 24);
            $t->string('title', 120);
            $t->string('body', 200);
            $t->boolean('enabled')->default(true);
            $t->timestamps();
        });
        Schema::create('notification_context_mutes', function (Blueprint $t): void {
            $t->uuid('user_id');
            $t->string('context_type', 24);
            $t->uuid('context_id');
            $t->string('category', 24);
            $t->timestamp('muted_at')->useCurrent();
            $t->primary(['user_id', 'context_type', 'context_id', 'category'], 'notification_context_mute_unique');
            $t->foreign('user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
        Schema::create('notification_admin_audits', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('admin_id');
            $t->string('action', 48);
            $t->string('subject_type', 32);
            $t->string('subject_id', 100)->nullable();
            $t->string('reason', 500);
            $t->timestamp('created_at')->useCurrent();
            $t->index(['created_at', 'action']);
        });
        // Operational templates only: generic copy, never actual customer content.
        $now = now();
        foreach ([
            ['moments.available', 'moments', 'New activity', 'An update is available in Orbit.'],
            ['pings.received', 'pings', 'New Ping', 'You have a new Ping in Orbit.'],
            ['check_ins.available', 'check_ins', 'New Check-In', 'A Check-In is available in Orbit.'],
            ['messages.available', 'messages', 'New message', 'You have a new message in Orbit.'],
            ['friends.updated', 'friends', 'Friends update', 'Your friends activity has an update.'],
            ['circles.updated', 'circles', 'Circle update', 'Your Circle activity has an update.'],
            ['security.notice', 'security', 'Security notice', 'Review an important account update in Orbit.'],
        ] as [$key, $category, $title, $body]) {
            DB::table('notification_templates')->insert([
                'key'=>$key, 'category'=>$category, 'title'=>$title, 'body'=>$body,
                'enabled'=>true, 'created_at'=>$now, 'updated_at'=>$now,
            ]);
        }
    }
    public function down(): void
    {
        foreach (['notification_admin_audits', 'notification_context_mutes',
            'notification_templates', 'notification_controls', 'notification_attempts',
            'notification_jobs', 'notification_device_tokens', 'notification_inbox',
            'notification_events'] as $table) Schema::dropIfExists($table);
    }
};
