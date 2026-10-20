<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('circles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('owner_id');
            $table->string('name', 80);
            $table->string('icon_key', 40)->nullable();
            $table->string('color_value', 7)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->char('creation_key_hash',64)->nullable()->unique();
            $table->char('creation_payload_hash',64)->nullable();
            $table->timestamps();
            $table->index(['owner_id', 'archived_at']);
            // No cascade: deleting an owner must never silently destroy other users' Circle.
            $table->foreign('owner_id')->references('id')->on('orbit_users')->restrictOnDelete();
        });
        Schema::create('circle_members', function (Blueprint $table): void {
            $table->uuid('circle_id');
            $table->uuid('user_id');
            $table->string('role', 16)->default('member');
            $table->timestamp('joined_at');
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
            $table->primary(['circle_id', 'user_id']);
            $table->index(['user_id','removed_at','circle_id']);
            $table->foreign('circle_id')->references('id')->on('circles')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
        Schema::create('circle_invites', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('circle_id');
            $table->uuid('inviter_id');
            $table->uuid('invitee_id');
            $table->string('status', 16)->default('pending');
            // Null when settled; unique concurrent pending recipient per Circle.
            $table->string('pending_pair_key', 73)->nullable()->unique();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['invitee_id','status','created_at']);
            $table->index(['circle_id','status','created_at']);
            $table->foreign('circle_id')->references('id')->on('circles')->cascadeOnDelete();
            $table->foreign('inviter_id')->references('id')->on('orbit_users')->cascadeOnDelete();
            $table->foreign('invitee_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
        Schema::create('circle_permissions', function (Blueprint $table): void {
            $table->uuid('circle_id');
            $table->string('scope_key', 80); // default or user:<uuid>; never nullable for unique key.
            $table->uuid('subject_user_id')->nullable();
            $table->string('permission_key', 32);
            $table->boolean('enabled')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->primary(['circle_id','scope_key','permission_key']);
            $table->index(['subject_user_id','circle_id']);
            $table->foreign('circle_id')->references('id')->on('circles')->cascadeOnDelete();
            $table->foreign('subject_user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
        Schema::create('circle_event_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('circle_id');
            $table->string('event_type', 48);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->index(['processed_at','created_at']);
            $table->index(['circle_id','created_at']);
            $table->foreign('circle_id')->references('id')->on('circles')->cascadeOnDelete();
            // No raw invites, member names, coordinates or private content in this table.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('circle_event_outbox');
        Schema::dropIfExists('circle_permissions');
        Schema::dropIfExists('circle_invites');
        Schema::dropIfExists('circle_members');
        Schema::dropIfExists('circles');
    }
};
