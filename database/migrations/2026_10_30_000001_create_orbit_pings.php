<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pings', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('sender_id');
            $t->uuid('recipient_id');
            $t->uuid('client_id');
            $t->char('payload_hash', 64);
            $t->string('type', 24);
            $t->string('custom_text', 640)->nullable();
            $t->string('context_type', 12);
            $t->uuid('context_id')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('seen_at')->nullable();
            $t->timestamp('responded_at')->nullable();
            $t->timestamps();
            $t->unique(['sender_id', 'client_id'], 'pings_sender_client_uniq');
            $t->index(['recipient_id', 'created_at', 'id'], 'pings_recipient_feed');
            $t->index(['sender_id', 'created_at', 'id'], 'pings_sender_feed');
            $t->foreign('sender_id')->references('id')->on('orbit_users')->cascadeOnDelete();
            $t->foreign('recipient_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
        Schema::create('ping_receipts', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('ping_id');
            $t->uuid('device_id');
            $t->string('kind', 12);
            $t->timestamp('created_at');
            $t->unique(['ping_id', 'device_id', 'kind'], 'ping_receipts_unique');
            $t->foreign('ping_id')->references('id')->on('pings')->cascadeOnDelete();
            $t->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();
        });
        Schema::create('ping_responses', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('ping_id')->unique();
            $t->uuid('responder_id');
            $t->uuid('client_reply_id');
            $t->string('type', 16);
            $t->timestamp('created_at');
            $t->unique(['responder_id', 'client_reply_id'], 'ping_reply_idempotent');
            $t->foreign('ping_id')->references('id')->on('pings')->cascadeOnDelete();
            $t->foreign('responder_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ping_responses');
        Schema::dropIfExists('ping_receipts');
        Schema::dropIfExists('pings');
    }
};
