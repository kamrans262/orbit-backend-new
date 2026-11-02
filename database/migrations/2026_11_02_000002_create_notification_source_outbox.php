<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notification_source_outbox', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('source_type',32);
            $t->uuid('source_id');
            $t->timestamp('created_at')->useCurrent();
            $t->timestamp('projected_at')->nullable();
            $t->unique(['source_type','source_id'],'notification_source_outbox_unique');
            $t->index(['projected_at','created_at'],'notification_source_outbox_pending');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('notification_source_outbox');
    }
};
