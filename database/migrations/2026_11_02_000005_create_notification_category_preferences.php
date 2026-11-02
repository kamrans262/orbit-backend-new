<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_category_preferences', function (Blueprint $table): void {
            $table->uuid('user_id');
            $table->string('category', 24);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->primary(['user_id', 'category'], 'notification_user_category_unique');
            $table->foreign('user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_category_preferences');
    }
};
