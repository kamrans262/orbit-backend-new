<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Additive, nullable migration. Existing Orbit accounts and Laravel
        // admin/users tables remain untouched.
        Schema::table('orbit_users', function (Blueprint $table): void {
            $table->string('password_hash')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orbit_users', function (Blueprint $table): void {
            $table->dropColumn('password_hash');
        });
    }
};
