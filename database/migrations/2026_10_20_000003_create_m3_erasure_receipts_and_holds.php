<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only, non-identifying proof remains after the user FK is removed.
        Schema::create('orbit_erasure_receipts', function (Blueprint $table): void {
            $table->uuid('request_id')->primary();
            $table->char('user_reference_hash', 64)->index();
            $table->string('scope', 48);
            $table->json('removed_counts');
            $table->uuid('operator_id');
            $table->timestamp('completed_at');
        });
        Schema::create('orbit_data_retention_holds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('reason_code', 60);
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('orbit_users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orbit_data_retention_holds');
        Schema::dropIfExists('orbit_erasure_receipts');
    }
};
