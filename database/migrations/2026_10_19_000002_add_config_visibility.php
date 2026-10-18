<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('remote_configs', fn (Blueprint $table) => $table->boolean('client_visible')->default(false));
    }
    public function down(): void {
        Schema::table('remote_configs', fn (Blueprint $table) => $table->dropColumn('client_visible'));
    }
};
