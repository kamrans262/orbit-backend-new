<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Opaque device-scoped deletion receipts. Message IDs are routing
        // metadata only; keep them after the message ciphertext is destroyed.
        // Do not store message text, media keys or recipient plaintext.
        Schema::create('messaging_redactions', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->uuid('conversation_id');
            $t->uuid('message_id');
            $t->uuid('recipient_device_id');
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['recipient_device_id','message_id'],'msg_redaction_device');
            $t->index(
                ['recipient_device_id','conversation_id','created_at'],
                'msg_redaction_sync'
            );
            $t->foreign('conversation_id')
                ->references('id')->on('messaging_conversations')
                ->cascadeOnDelete();
            $t->foreign('recipient_device_id')
                ->references('id')->on('devices')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_redactions');
    }
};
