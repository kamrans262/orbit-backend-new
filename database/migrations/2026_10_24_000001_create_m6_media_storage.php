<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('media_objects', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('owner_user_id')->index();
            $t->string('purpose',16);
            $t->string('kind',16);
            $t->string('declared_mime',100);
            $t->unsignedInteger('declared_width')->nullable();
            $t->unsignedInteger('declared_height')->nullable();
            $t->unsignedInteger('declared_duration_ms')->nullable();
            $t->unsignedBigInteger('cipher_bytes')->default(0);
            $t->char('cipher_sha256',64)->nullable();
            $t->string('cipher_suite',60);
            $t->string('object_key',220)->unique();
            $t->string('state',16)->default('initiated');
            $t->unsignedBigInteger('policy_version')->default(1);
            $t->timestamp('ready_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamp('retention_deadline')->nullable()->index();
            $t->timestamp('legal_hold_until')->nullable();
            $t->timestamps();
            $t->index(['owner_user_id','state']);
            $t->foreign('owner_user_id')->references('id')->on('orbit_users')->restrictOnDelete();
        });
        Schema::create('media_upload_sessions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('media_id')->index();
            $t->uuid('variant_id')->nullable()->unique();
            $t->uuid('owner_user_id')->index();
            $t->uuid('device_id');
            $t->char('idempotency_key_hash',64);
            $t->char('payload_hash',64);
            // Provider multipart IDs are credentials; encrypted by Laravel's encrypter at rest.
            $t->text('provider_upload_id_encrypted')->nullable();
            $t->unsignedBigInteger('declared_bytes');
            $t->unsignedBigInteger('actual_cipher_bytes')->nullable();
            $t->char('final_cipher_sha256',64)->nullable();
            $t->char('complete_key_hash',64)->nullable();
            $t->char('complete_payload_hash',64)->nullable();
            $t->string('state',16)->default('initiated');
            $t->timestamp('expires_at')->index();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['owner_user_id','idempotency_key_hash'],'media_upload_idempotent');
            $t->foreign('media_id')->references('id')->on('media_objects')->cascadeOnDelete();
            $t->foreign('owner_user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
            $t->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();
        });
        Schema::create('media_variants', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('media_id')->index();
            $t->string('variant',24);
            $t->string('object_key',220)->unique();
            $t->unsignedBigInteger('cipher_bytes');
            $t->char('cipher_sha256',64);
            $t->string('state',16)->default('verifying');
            $t->timestamps();
            $t->unique(['media_id','variant']);
            $t->foreign('media_id')->references('id')->on('media_objects')->cascadeOnDelete();
        });
        Schema::create('media_audience_grants', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('media_id')->index();
            $t->uuid('issuer_user_id');
            $t->uuid('recipient_user_id')->nullable()->index();
            $t->uuid('circle_id')->nullable();
            $t->string('audience_kind',24);
            $t->unsignedBigInteger('policy_version');
            $t->char('request_key_hash',64)->nullable();
            $t->char('request_payload_hash',64)->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->foreign('media_id')->references('id')->on('media_objects')->cascadeOnDelete();
            $t->foreign('issuer_user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
            $t->foreign('recipient_user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
        Schema::create('media_device_key_envelopes', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('media_id')->index();
            $t->uuid('grant_id');
            $t->uuid('recipient_device_id');
            $t->string('variant',24)->default('original');
            $t->string('key_version',40);
            $t->string('algorithm',80);
            $t->longText('wrapped_key_ciphertext');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->unique(['grant_id','recipient_device_id','variant']);
            $t->foreign('grant_id')->references('id')->on('media_audience_grants')->cascadeOnDelete();
            $t->foreign('media_id')->references('id')->on('media_objects')->cascadeOnDelete();
            $t->foreign('recipient_device_id')->references('id')->on('devices')->cascadeOnDelete();
        });
        Schema::create('media_delete_jobs', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('media_id')->unique();
            $t->string('reason',40);
            $t->string('status',16)->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->index();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->foreign('media_id')->references('id')->on('media_objects')->cascadeOnDelete();
        });
        Schema::create('media_event_outbox', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('media_id')->index();
            $t->string('event_type',60);
            $t->unsignedBigInteger('policy_version')->default(1);
            $t->timestamp('created_at')->useCurrent();
            $t->timestamp('processed_at')->nullable()->index();
            $t->foreign('media_id')->references('id')->on('media_objects')->cascadeOnDelete();
        });
    }
    public function down(): void
    {
        // Rollback should only run against disposable test databases, never production data.
        foreach (['media_event_outbox','media_delete_jobs','media_device_key_envelopes',
            'media_audience_grants','media_variants','media_upload_sessions','media_objects'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};