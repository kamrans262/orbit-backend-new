<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('friend_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('sender_id');
            $table->uuid('recipient_id');
            $table->string('status', 16)->default('pending');
            // NULL after resolution; MariaDB permits many NULLs in a UNIQUE column.
            // Canonical unordered-pair key prevents crossing pending requests.
            $table->string('active_pair_key', 73)->nullable()->unique();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->index(['recipient_id', 'status', 'created_at']);
            $table->index(['sender_id', 'status', 'created_at']);
            $table->foreign('sender_id')->references('id')->on('orbit_users')->cascadeOnDelete();
            $table->foreign('recipient_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
        Schema::create('friendships', function (Blueprint $table): void {
            $table->uuid('user_low_id');
            $table->uuid('user_high_id');
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['user_low_id', 'user_high_id']);
            $table->index(['user_high_id', 'user_low_id']);
            $table->foreign('user_low_id')->references('id')->on('orbit_users')->cascadeOnDelete();
            $table->foreign('user_high_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
        Schema::create('blocks', function (Blueprint $table): void {
            $table->uuid('blocker_id');
            $table->uuid('blocked_id');
            $table->string('reason_code', 40)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['blocker_id', 'blocked_id']);
            $table->index(['blocked_id', 'blocker_id']);
            $table->foreign('blocker_id')->references('id')->on('orbit_users')->cascadeOnDelete();
            $table->foreign('blocked_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
        // Future realtime/caches can check an authoritative revision; no WS delivery is claimed.
        Schema::create('social_graph_revisions', function (Blueprint $table): void {
            $table->uuid('user_id')->primary();
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamp('updated_at')->nullable();
            $table->foreign('user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_graph_revisions');
        Schema::dropIfExists('blocks');
        Schema::dropIfExists('friendships');
        Schema::dropIfExists('friend_requests');
    }
};
