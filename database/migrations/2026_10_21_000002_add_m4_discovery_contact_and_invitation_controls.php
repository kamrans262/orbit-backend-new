<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orbit_users', function (Blueprint $table): void {
            $table->index('display_name', 'orbit_users_name_search_index');
        });

        Schema::create('social_discovery_settings', function (Blueprint $table): void {
            $table->uuid('user_id')->primary();
            $table->boolean('discoverable')->default(true);
            $table->boolean('discoverable_by_name')->default(true);
            $table->boolean('discoverable_by_contacts')->default(false);
            $table->boolean('allow_friend_requests')->default(true);
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });

        Schema::create('social_friend_invites', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('creator_id')->index();
            $table->char('token_hash', 64)->unique();
            $table->uuid('redeemed_by')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();
            $table->index(['creator_id', 'created_at']);
            $table->foreign('creator_id')->references('id')->on('orbit_users')->cascadeOnDelete();
            $table->foreign('redeemed_by')->references('id')->on('orbit_users')->nullOnDelete();
        });

        Schema::create('social_contact_lookup_quotas', function (Blueprint $table): void {
            $table->uuid('user_id');
            $table->date('quota_date');
            $table->unsignedInteger('contact_count')->default(0);
            $table->primary(['user_id','quota_date']);
            $table->foreign('user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_contact_lookup_quotas');
        Schema::dropIfExists('social_friend_invites');
        Schema::dropIfExists('social_discovery_settings');
        Schema::table('orbit_users', fn (Blueprint $table) =>
            $table->dropIndex('orbit_users_name_search_index'));
    }
};
