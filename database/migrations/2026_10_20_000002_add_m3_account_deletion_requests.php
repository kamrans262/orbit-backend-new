<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orbit_account_deletion_requests', function (Blueprint $table): void {
            $table->uuid('user_id')->primary();
            $table->uuid('request_id')->unique();
            $table->string('status', 24)->default('pending')->index();
            $table->timestamp('requested_at');
            $table->timestamp('eligible_for_erasure_at')->index();
            $table->timestamp('cancelled_at')->nullable();
            // A separate erasure service must mark actual completion.
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orbit_account_deletion_requests');
    }
};
