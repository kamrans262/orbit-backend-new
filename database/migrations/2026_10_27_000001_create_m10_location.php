<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('location_shares', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('owner_id');
            $table->string('audience_type', 12); // person or circle, never public
            $table->uuid('audience_id');
            $table->string('precision', 12); // precise or approximate
            $table->string('duration', 20); // one_hour, until_tonight, manual, always
            $table->string('status', 12)->default('active');
            $table->timestamp('starts_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            // Only for separately approved "until leaving"; sealed private
            // anchor, no plaintext geometry, GPS history or center in API.
            $table->longText('geo_anchor_encrypted')->nullable();
            $table->unsignedSmallInteger('geo_radius_m')->nullable();
            $table->unsignedTinyInteger('geo_exit_streak')->default(0);
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['owner_id','status','expires_at']);
            $table->index(['audience_type','audience_id','status','expires_at'],'location_shares_audience_scope');
            $table->foreign('owner_id')->references('id')->on('orbit_users')->cascadeOnDelete();
            // audience_id deliberately not FK: an audience can be friend or Circle.
            // Every access revalidates current relationship & M5 membership.
        });
        Schema::create('location_latest', function (Blueprint $table): void {
            $table->uuid('owner_id')->primary();
            $table->uuid('device_id');
            // JSON with latitude/longitude/heading/speed is encrypted at rest
            // using Laravel's authenticated encryption. No index on GPS.
            $table->longText('encrypted_payload');
            $table->timestamp('observed_at');
            $table->timestamp('received_at');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index('expires_at');
            $table->foreign('owner_id')->references('id')->on('orbit_users')->cascadeOnDelete();
            $table->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();
        });
        Schema::create('location_consent_audits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('owner_id');
            $table->string('action', 16); // activated, revoked, expired
            $table->string('audience_type', 12);
            $table->string('precision', 12);
            $table->timestamp('occurred_at');
            $table->index(['owner_id','occurred_at']);
            $table->index('occurred_at');
            $table->foreign('owner_id')->references('id')->on('orbit_users')->cascadeOnDelete();
            // No coordinates, audience IDs, device info, or sample times in audit.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_consent_audits');
        Schema::dropIfExists('location_latest');
        Schema::dropIfExists('location_shares');
    }
};
