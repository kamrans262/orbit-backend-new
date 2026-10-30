<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Server stores only authenticated routing metadata and opaque
        // client-encrypted sender-key packets; never group symmetric keys.
        Schema::create('messaging_sender_key_batches', function(Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('conversation_id');
            $t->unsignedInteger('group_epoch');
            $t->uuid('sender_id');
            $t->uuid('sender_device_id');
            $t->uuid('client_id');
            $t->string('protocol',48);
            $t->char('payload_hash',64);
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['sender_device_id','client_id'],'msg_group_sender_key_idem');
            $t->index(['conversation_id','group_epoch','created_at'],
                'msg_group_sender_key_epoch');
            $t->foreign('conversation_id')->references('id')
                ->on('messaging_conversations')->cascadeOnDelete();
            $t->foreign('sender_device_id')->references('id')
                ->on('devices')->cascadeOnDelete();
            $t->foreign('sender_id')->references('id')
                ->on('orbit_users')->cascadeOnDelete();
        });
        Schema::create('messaging_sender_key_envelopes', function(Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('batch_id');
            $t->uuid('recipient_device_id');
            $t->unsignedInteger('recipient_key_version');
            $t->longText('ciphertext_b64');
            $t->text('protocol_header_b64');
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['batch_id','recipient_device_id'],
                'msg_group_sender_key_recipient');
            $t->index(['recipient_device_id','created_at'],
                'msg_group_sender_key_mailbox');
            $t->foreign('batch_id')->references('id')
                ->on('messaging_sender_key_batches')->cascadeOnDelete();
            $t->foreign('recipient_device_id')->references('id')
                ->on('devices')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_sender_key_envelopes');
        Schema::dropIfExists('messaging_sender_key_batches');
    }
};
