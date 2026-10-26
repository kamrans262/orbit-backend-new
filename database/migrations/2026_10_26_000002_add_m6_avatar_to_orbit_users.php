<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orbit_users', function (Blueprint $table): void {
            $table->uuid('avatar_media_id')->nullable()->index();
        });
    }
    public function down(): void
    {
        Schema::table('orbit_users', function (Blueprint $table): void {
            $table->dropColumn('avatar_media_id');
        });
    }
};
