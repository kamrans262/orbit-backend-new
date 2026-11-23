<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_events', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('source_type', 24);
            $t->uuid('source_id');
            $t->uuid('actor_user_id');
            $t->string('event_kind', 48);
            $t->unsignedBigInteger('source_revision')->default(1);
            $t->timestamp('occurred_at');
            $t->timestamp('invalidated_at')->nullable();
            $t->timestamps();
            $t->unique(['source_type', 'source_id'], 'activity_unique_source');
            $t->index(['invalidated_at', 'occurred_at', 'id'], 'activity_time_index');
            $t->index(['actor_user_id', 'occurred_at'], 'activity_actor_index');
            // No FK to source: source deletion must not prevent erasure or keep
            // private source rows alive; the worker invalidates orphan pointers.
            $t->foreign('actor_user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
        Schema::create('activity_controls', function (Blueprint $t): void {
            $t->string('key', 48)->primary();
            $t->boolean('enabled')->default(false);
            $t->uuid('updated_by')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->foreign('updated_by')->references('id')->on('admin_users')->nullOnDelete();
        });
        Schema::create('activity_projection_runs', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->unsignedSmallInteger('moments_added')->default(0);
            $t->unsignedSmallInteger('check_ins_added')->default(0);
            $t->unsignedSmallInteger('invalidated')->default(0);
            $t->string('status', 16);
            $t->string('error_code', 64)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['created_at', 'id']);
        });
        Schema::create('activity_admin_audits', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('admin_id');
            $t->string('action', 40);
            $t->string('subject_key', 48);
            $t->string('reason', 500);
            $t->timestamp('created_at')->useCurrent();
            $t->index(['created_at', 'id']);
            $t->foreign('admin_id')->references('id')->on('admin_users')->restrictOnDelete();
        });
        DB::table('activity_controls')->insert([
            ['key' => 'source.moment', 'enabled' => true, 'updated_at' => now()],
            ['key' => 'source.check_in', 'enabled' => true, 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        // Drops M18-only additive state; never touches source records.
        foreach (['activity_admin_audits','activity_projection_runs',
                  'activity_controls','activity_events'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
