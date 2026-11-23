<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('activity_admin_audits', function (Blueprint $t): void {
            $t->string('old_value', 32)->nullable();
            $t->string('new_value', 32)->nullable();
        });
    }
    public function down(): void {
        Schema::table('activity_admin_audits', function (Blueprint $t): void {
            $t->dropColumn(['old_value', 'new_value']);
        });
    }
};
