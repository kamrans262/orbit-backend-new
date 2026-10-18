<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('email', 190)->unique();
            $table->string('password');
            $table->text('totp_secret'); // encrypted via Eloquent cast
            $table->string('status', 24)->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
        Schema::create('admin_roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 80)->unique();
            $table->json('permissions');
            $table->timestamps();
        });
        Schema::create('admin_user_roles', function (Blueprint $table): void {
            $table->uuid('admin_user_id');
            $table->uuid('admin_role_id');
            $table->primary(['admin_user_id', 'admin_role_id']);
            $table->foreign('admin_user_id')->references('id')->on('admin_users')->cascadeOnDelete();
            $table->foreign('admin_role_id')->references('id')->on('admin_roles')->cascadeOnDelete();
        });
        Schema::create('feature_flags', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key', 120)->unique();
            $table->string('description', 500)->nullable();
            $table->boolean('enabled')->default(false);
            $table->boolean('kill_switch')->default(false);
            $table->unsignedTinyInteger('rollout_percentage')->default(0);
            $table->json('platforms')->nullable();
            $table->string('min_version', 40)->nullable();
            $table->json('countries')->nullable();
            $table->json('cohorts')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('remote_configs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key', 120)->unique();
            $table->string('description', 500)->nullable();
            $table->json('value');
            $table->string('value_type', 16);
            $table->boolean('published')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('config_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('resource_type', 32);
            $table->string('resource_key', 120);
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->uuid('actor_id');
            $table->string('reason', 500);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['resource_type', 'resource_key', 'version']);
        });
        Schema::create('admin_audit_log', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('actor_id')->nullable();
            $table->string('action', 120);
            $table->string('target', 190);
            $table->string('reason', 500);
            $table->string('request_id', 80)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->char('before_hash', 64)->nullable();
            $table->char('after_hash', 64)->nullable();
            $table->char('previous_hash', 64)->nullable();
            $table->char('event_hash', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['actor_id', 'created_at']);
            $table->index(['target', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_log');
        Schema::dropIfExists('config_revisions');
        Schema::dropIfExists('remote_configs');
        Schema::dropIfExists('feature_flags');
        Schema::dropIfExists('admin_user_roles');
        Schema::dropIfExists('admin_roles');
        Schema::dropIfExists('admin_users');
    }
};
