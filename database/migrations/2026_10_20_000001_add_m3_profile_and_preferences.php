<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Additive: do not modify existing credentials, identities or sessions.
        Schema::table('orbit_users', function (Blueprint $table): void {
            $table->string('bio', 280)->nullable();
        });

        Schema::create('orbit_user_preferences', function (Blueprint $table): void {
            $table->uuid('user_id')->primary();
            $table->json('notifications')->nullable();
            $table->json('privacy')->nullable();
            $table->timestamps();
            $table->foreign('user_id')->references('id')
                ->on('orbit_users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orbit_user_preferences');
        Schema::table('orbit_users', function (Blueprint $table): void {
            $table->dropColumn('bio');
        });
    }
};
