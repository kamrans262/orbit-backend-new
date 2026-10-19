<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Do not alter or drop the existing Laravel users table. Orbit identities use UUIDs.
        Schema::create('orbit_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('display_name', 120)->nullable();
            $table->string('username', 60)->nullable()->unique();
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('deletion_requested_at')->nullable();
            $table->timestamps();
        });
        Schema::create('user_identities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('provider', 20);
            $table->string('provider_subject', 190);
            $table->string('email_norm', 190)->nullable()->index();
            $table->string('phone_e164', 20)->nullable()->index();
            $table->timestamp('verified_at');
            $table->timestamps();
            $table->unique(['provider', 'provider_subject']);
            $table->foreign('user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
        Schema::create('otp_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('channel', 10);
            $table->char('recipient_hash', 64)->index();
            $table->char('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->uuid('device_public_id');
            $table->string('name', 80);
            $table->string('platform', 20);
            $table->text('public_key')->nullable();
            $table->text('push_token_enc')->nullable();
            $table->string('attestation_state', 30)->default('unverified');
            $table->string('trust_state', 30)->default('pending');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'device_public_id']);
            $table->foreign('user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
        Schema::create('orbit_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->uuid('device_id')->index();
            $table->uuid('token_family_id')->index();
            $table->char('refresh_token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->json('risk_metadata')->nullable();
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
            $table->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();
        });
        Schema::create('refresh_token_history', function (Blueprint $table): void {
            $table->char('token_hash', 64)->primary();
            $table->uuid('session_id')->index();
            $table->timestamp('used_at');
            $table->foreign('session_id')->references('id')->on('orbit_sessions')->cascadeOnDelete();
        });
        Schema::create('orbit_identity_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->uuid('device_id')->nullable();
            $table->string('action', 80);
            $table->string('reason_code', 80)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orbit_identity_events');
        Schema::dropIfExists('refresh_token_history');
        Schema::dropIfExists('orbit_sessions');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('otp_challenges');
        Schema::dropIfExists('user_identities');
        Schema::dropIfExists('orbit_users');
    }
};
