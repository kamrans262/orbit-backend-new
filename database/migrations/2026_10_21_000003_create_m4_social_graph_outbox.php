<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_graph_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('actor_id');
            $table->uuid('subject_id')->nullable();
            $table->string('event_type', 64);
            // Only action metadata. Never profile content, contacts, GPS or invite tokens.
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->index(['processed_at', 'created_at']);
            $table->index(['actor_id', 'created_at']);
            $table->index(['subject_id', 'created_at']);
            $table->foreign('actor_id')->references('id')->on('orbit_users')->cascadeOnDelete();
            $table->foreign('subject_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_graph_outbox');
    }
};
