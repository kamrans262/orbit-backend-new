<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkins', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('creator_id');
            $t->uuid('client_id');
            $t->char('payload_hash', 64);
            $t->string('type', 24);
            $t->string('text', 640)->nullable();
            $t->string('place_ref', 255)->nullable();
            $t->unsignedBigInteger('audience_revision')->default(1);
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
            $t->unique(['creator_id', 'client_id'], 'checkins_creator_client');
            $t->index(['creator_id', 'created_at', 'id'], 'checkins_creator_history');
            $t->index(['expires_at', 'deleted_at'], 'checkins_expiry_state');
            $t->foreign('creator_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
        Schema::create('checkin_audience', function (Blueprint $t): void {
            $t->uuid('checkin_id');
            $t->unsignedBigInteger('audience_revision');
            $t->string('audience_type', 24);
            // Empty string represents non-ID scopes; avoids nullable unique pitfalls on MySQL.
            $t->string('audience_id', 36)->default('');
            $t->primary(['checkin_id', 'audience_revision', 'audience_type', 'audience_id'], 'checkin_audience_unique');
            $t->foreign('checkin_id')->references('id')->on('checkins')->cascadeOnDelete();
        });
        Schema::create('checkin_recipients', function (Blueprint $t): void {
            $t->uuid('checkin_id');
            $t->uuid('recipient_user_id');
            $t->unsignedBigInteger('audience_revision');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->primary(['checkin_id', 'recipient_user_id', 'audience_revision'], 'checkin_recipient_unique');
            $t->index(['recipient_user_id', 'revoked_at', 'checkin_id'], 'checkin_recipient_feed');
            $t->foreign('checkin_id')->references('id')->on('checkins')->cascadeOnDelete();
            $t->foreign('recipient_user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
        // Operational metadata only: no content, users, recipients, IPs or locations.
        Schema::create('checkin_operational_events', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('action', 24);
            $t->unsignedSmallInteger('status_code');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['action', 'status_code', 'created_at'], 'checkin_ops_aggregate');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkin_operational_events');
        Schema::dropIfExists('checkin_recipients');
        Schema::dropIfExists('checkin_audience');
        Schema::dropIfExists('checkins');
    }
};
