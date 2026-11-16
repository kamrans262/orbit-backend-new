<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sos_trusted_contacts', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('owner_id');
            $t->uuid('recipient_id');
            $t->string('state', 16)->default('requested');
            $t->timestamp('accepted_at')->nullable();
            $t->timestamps();
            $t->unique(['owner_id','recipient_id'], 'sos_contact_unique');
            $t->index(['recipient_id','state'], 'sos_contact_incoming');
            $t->foreign('owner_id')->references('id')->on('orbit_users')->cascadeOnDelete();
            $t->foreign('recipient_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
        Schema::create('sos_incidents', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('initiator_id');
            $t->uuid('client_id');
            $t->char('request_hash', 64);
            $t->uuid('circle_id')->nullable();
            $t->string('status', 16)->default('active');
            $t->boolean('recording_consented')->default(false);
            $t->longText('location_ciphertext')->nullable();
            $t->timestamp('location_expires_at')->nullable();
            $t->timestamp('escalation_due_at')->nullable();
            $t->timestamp('escalated_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
            $t->unique(['initiator_id','client_id'], 'sos_client_unique');
            $t->index(['initiator_id','created_at'], 'sos_owner_history');
            $t->index(['status','escalation_due_at'], 'sos_escalation_due');
            $t->foreign('initiator_id')->references('id')->on('orbit_users')->cascadeOnDelete();
            $t->foreign('circle_id')->references('id')->on('circles')->nullOnDelete();
        });
        Schema::create('sos_recipients', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('incident_id');
            $t->uuid('user_id');
            $t->string('source', 16); // circle | trusted
            $t->string('state', 16)->default('pending'); // pending | acknowledged | suppressed
            $t->timestamp('in_app_available_at')->useCurrent();
            $t->timestamp('acknowledged_at')->nullable();
            $t->timestamp('suppressed_at')->nullable();
            $t->timestamps();
            $t->unique(['incident_id','user_id'], 'sos_recipient_unique');
            $t->index(['user_id','state','created_at'], 'sos_responder_inbox');
            $t->foreign('incident_id')->references('id')->on('sos_incidents')->cascadeOnDelete();
            $t->foreign('user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
        Schema::create('sos_delivery_jobs', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('recipient_id');
            $t->uuid('device_id');
            $t->string('state', 20)->default('pending');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->timestamp('provider_accepted_at')->nullable();
            $t->string('last_error_code', 64)->nullable();
            $t->timestamps();
            $t->unique(['recipient_id','device_id'], 'sos_job_recipient_device_unique');
            $t->index(['state','next_attempt_at','lease_until'], 'sos_delivery_pending');
            $t->foreign('recipient_id')->references('id')->on('sos_recipients')->cascadeOnDelete();
            $t->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();
        });
        Schema::create('sos_dispatch_attempts', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('job_id');
            $t->string('state', 24);
            $t->string('error_code', 64)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['job_id','created_at']);
            $t->foreign('job_id')->references('id')->on('sos_delivery_jobs')->cascadeOnDelete();
        });
        Schema::create('sos_controls', function (Blueprint $t): void {
            $t->string('key', 48)->primary();
            $t->string('value', 64);
            $t->uuid('updated_by')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
        Schema::create('sos_audit_events', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('incident_id');
            $t->char('actor_hash', 64)->nullable();
            $t->string('action', 48);
            $t->char('previous_hash', 64);
            $t->char('event_hash', 64);
            $t->timestamp('created_at')->useCurrent();
            $t->index(['incident_id','created_at'], 'sos_event_history');
            $t->foreign('incident_id')->references('id')->on('sos_incidents')->cascadeOnDelete();
        });
        Schema::create('sos_admin_audits', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('admin_id');
            $t->string('action', 48);
            $t->char('incident_hash', 64)->nullable();
            $t->string('reason', 500);
            $t->char('previous_hash', 64);
            $t->char('event_hash', 64);
            $t->timestamp('created_at')->useCurrent();
            $t->index(['created_at','action']);
        });
        DB::table('sos_controls')->insert([
            ['key'=>'push_retries_enabled','value'=>'1','updated_at'=>now()],
            ['key'=>'escalation_enabled','value'=>'1','updated_at'=>now()],
            ['key'=>'escalation_after_seconds','value'=>'120','updated_at'=>now()],
        ]);
    }

    public function down(): void
    {
        foreach (['sos_admin_audits','sos_audit_events','sos_controls','sos_dispatch_attempts',
            'sos_delivery_jobs','sos_recipients','sos_incidents','sos_trusted_contacts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
