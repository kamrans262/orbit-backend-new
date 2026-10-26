<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('dashboard_preferences', function (Blueprint $table): void {
            $table->uuid('user_id')->primary();
            $table->json('circle_order');
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
        Schema::create('dashboard_card_dismissals', function (Blueprint $table): void {
            $table->uuid('user_id');
            $table->string('card_key', 64);
            $table->timestamp('dismissed_until')->index();
            $table->timestamps();
            $table->primary(['user_id', 'card_key']);
            $table->foreign('user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_card_dismissals');
        Schema::dropIfExists('dashboard_preferences');
    }
};
