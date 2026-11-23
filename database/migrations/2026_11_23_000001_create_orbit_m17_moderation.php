<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Only additive storage. M4/M7/M13 reporting tables and their encrypted
        // evidence remain authoritative; M16 SOS records are never imported.
        Schema::create('moderation_reports', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('reporter_id')->nullable();
            $t->uuid('subject_user_id')->nullable();
            $t->uuid('client_id');
            $t->string('reason',32);
            $t->text('details_enc')->nullable();
            $t->char('payload_hash',64);
            $t->string('status',20)->default('received');
            $t->timestamps();
            $t->unique(['reporter_id','client_id'],'moderation_report_client_unique');
            $t->index(['status','created_at']);
            $t->foreign('reporter_id')->references('id')->on('orbit_users')->nullOnDelete();
            $t->foreign('subject_user_id')->references('id')->on('orbit_users')->nullOnDelete();
        });
        Schema::create('moderation_cases', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('source_kind',20); // account, moment, messaging
            $t->uuid('source_report_id');
            $t->uuid('subject_user_id')->nullable();
            $t->string('status',24)->default('pending');
            $t->string('priority',12)->default('normal');
            $t->uuid('assigned_admin_id')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->unique(['source_kind','source_report_id'],'moderation_case_source_unique');
            $t->index(['status','created_at']);
            $t->index(['subject_user_id','status']);
            $t->foreign('subject_user_id')->references('id')->on('orbit_users')->nullOnDelete();
            $t->foreign('assigned_admin_id')->references('id')->on('admin_users')->nullOnDelete();
        });
        Schema::create('moderation_case_sources', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('case_id');
            $t->string('source_kind',20);
            $t->uuid('source_report_id');
            $t->timestamp('linked_at')->useCurrent();
            $t->unique(['source_kind','source_report_id'],'moderation_source_once');
            $t->foreign('case_id')->references('id')->on('moderation_cases')->restrictOnDelete();
        });
        Schema::create('moderation_case_events', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('case_id');
            $t->char('actor_digest',64)->nullable();
            $t->string('actor_kind',12);
            $t->string('action',48);
            $t->string('reason_code',48)->nullable();
            $t->unsignedInteger('revision_after');
            $t->char('previous_hash',64);
            $t->char('event_hash',64);
            $t->timestamp('created_at')->useCurrent();
            $t->index(['case_id','id']);
            $t->foreign('case_id')->references('id')->on('moderation_cases')->restrictOnDelete();
        });
        Schema::create('moderation_actions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('case_id');
            $t->uuid('subject_user_id')->nullable();
            $t->string('scope',24); // warning, messaging_send
            $t->string('reason_code',24);
            $t->string('duration',16)->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->string('status',20)->default('active');
            $t->uuid('issued_by');
            $t->uuid('reversed_by')->nullable();
            $t->timestamp('reversed_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['subject_user_id','scope','status']);
            $t->foreign('case_id')->references('id')->on('moderation_cases')->restrictOnDelete();
            $t->foreign('subject_user_id')->references('id')->on('orbit_users')->nullOnDelete();
            $t->foreign('issued_by')->references('id')->on('admin_users')->restrictOnDelete();
            $t->foreign('reversed_by')->references('id')->on('admin_users')->nullOnDelete();
        });
        Schema::create('moderation_appeals', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('action_id');
            $t->uuid('appellant_id')->nullable();
            $t->uuid('client_id');
            $t->text('statement_enc')->nullable();
            $t->char('payload_hash',64);
            $t->string('status',20)->default('pending');
            $t->string('decision_code',32)->nullable();
            $t->uuid('reviewing_admin_id')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->unique('action_id');
            $t->foreign('action_id')->references('id')->on('moderation_actions')->restrictOnDelete();
            $t->foreign('appellant_id')->references('id')->on('orbit_users')->nullOnDelete();
            $t->foreign('reviewing_admin_id')->references('id')->on('admin_users')->nullOnDelete();
        });
        Schema::create('moderation_evidence_access', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('case_id');
            $t->uuid('admin_id');
            $t->char('ticket_digest',64);
            $t->string('source_kind',20);
            $t->timestamp('created_at')->useCurrent();
            $t->index(['case_id','created_at']);
            $t->foreign('case_id')->references('id')->on('moderation_cases')->restrictOnDelete();
            $t->foreign('admin_id')->references('id')->on('admin_users')->restrictOnDelete();
        });
        Schema::create('moderation_controls', function (Blueprint $t): void {
            $t->string('key',48)->primary();
            $t->string('value',80);
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->foreign('updated_by')->references('id')->on('admin_users')->nullOnDelete();
        });
        Schema::create('moderation_control_audits',function (Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('admin_id');
            $t->string('key',48);
            $t->string('old_value',80);
            $t->string('new_value',80);
            $t->char('reason_digest',64);
            $t->timestamp('created_at')->useCurrent();
            $t->foreign('admin_id')->references('id')->on('admin_users')->restrictOnDelete();
        });
        DB::table('moderation_controls')->insert([
            ['key'=>'enforcement_enabled','value'=>'0','created_at'=>now(),'updated_at'=>now()],
        ]);
    }
    public function down(): void
    {
        // Rollback only M17-owned tables; never drop or alter M4/M7/M13/M16.
        foreach (['moderation_control_audits','moderation_controls','moderation_evidence_access',
            'moderation_appeals','moderation_actions','moderation_case_events',
            'moderation_case_sources','moderation_cases','moderation_reports'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
