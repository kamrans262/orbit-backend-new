<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ping_operational_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('request_id')->unique();
            $table->string('action', 16);
            $table->unsignedSmallInteger('status_code');
            $table->string('reason_code', 32);
            $table->timestamp('created_at')->index();
            $table->index(['action','created_at']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('ping_operational_events');
    }
};
