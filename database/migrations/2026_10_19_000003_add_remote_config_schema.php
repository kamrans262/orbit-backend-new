<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('remote_configs', function (Blueprint $table): void {
            $table->json('schema_json')->nullable();
        });
    }
    public function down(): void {
        Schema::table('remote_configs', fn (Blueprint $table) => $table->dropColumn('schema_json'));
    }
};
