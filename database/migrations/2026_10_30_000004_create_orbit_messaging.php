<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Keys belong to an approved device, not the M6 media encryption key.
        // No private key, symmetric key, ratchet state or decrypted text is stored.
        Schema::create('messaging_device_keys', function (Blueprint $t): void {
            $t->uuid('device_id')->primary();
            $t->uuid('user_id')->index();
            $t->string('identity_ed25519',64);
            $t->string('signed_prekey_x25519',64);
            $t->string('signed_prekey_signature',128);
            $t->unsignedInteger('signed_prekey_id');
            $t->unsignedInteger('key_version')->default(1);
            $t->timestamp('registered_at');
            $t->timestamp('updated_at')->nullable();
            $t->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();
            $t->foreign('user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
        Schema::create('messaging_one_time_prekeys', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('device_id');
            $t->unsignedInteger('key_id');
            $t->string('public_x25519',64);
            $t->uuid('claimed_by')->nullable();
            $t->timestamp('claimed_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['device_id','key_id'],'msg_prekey_unique');
            $t->index(['device_id','claimed_at']);
            $t->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();
            $t->foreign('claimed_by')->references('id')->on('orbit_users')->nullOnDelete();
        });
        Schema::create('messaging_conversations', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('kind',12); // direct | circle
            $t->string('direct_pair',73)->nullable()->unique();
            $t->uuid('circle_id')->nullable()->unique();
            $t->unsignedInteger('group_epoch')->default(1);
            $t->char('roster_fingerprint',64)->nullable();
            $t->timestamps();
            $t->foreign('circle_id')->references('id')->on('circles')->cascadeOnDelete();
        });
        Schema::create('messaging_members', function (Blueprint $t): void {
            $t->uuid('conversation_id');
            $t->uuid('user_id');
            $t->timestamp('muted_until')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->timestamp('joined_at')->useCurrent();
            $t->timestamp('left_at')->nullable();
            $t->primary(['conversation_id','user_id']);
            $t->foreign('conversation_id')->references('id')->on('messaging_conversations')->cascadeOnDelete();
            $t->foreign('user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
        Schema::create('messaging_messages', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('conversation_id')->index();
            $t->uuid('sender_id')->index();
            $t->uuid('sender_device_id');
            $t->uuid('client_id');
            $t->string('kind',20); // metadata type only, not content
            $t->string('protocol',48);
            $t->unsignedInteger('group_epoch')->default(1);
            $t->timestamp('created_at')->useCurrent();
            $t->timestamp('expires_at')->nullable()->index();
            $t->timestamp('redacted_at')->nullable();
            $t->unique(['sender_device_id','client_id'],'msg_send_idempotency');
            $t->index(['conversation_id','created_at','id'],'msg_conversation_page');
            $t->foreign('conversation_id')->references('id')->on('messaging_conversations')->cascadeOnDelete();
            $t->foreign('sender_id')->references('id')->on('orbit_users')->cascadeOnDelete();
            $t->foreign('sender_device_id')->references('id')->on('devices')->cascadeOnDelete();
        });
        Schema::create('messaging_envelopes', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('message_id');
            $t->uuid('recipient_device_id');
            $t->longText('ciphertext_b64');
            $t->text('protocol_header_b64'); // opaque ratchet header, never plaintext
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['message_id','recipient_device_id'],'msg_envelope_unique');
            $t->index(['recipient_device_id','created_at'],'msg_device_mailbox');
            $t->foreign('message_id')->references('id')->on('messaging_messages')->cascadeOnDelete();
            $t->foreign('recipient_device_id')->references('id')->on('devices')->cascadeOnDelete();
        });
        Schema::create('messaging_reports', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('conversation_id');
            $t->uuid('message_id')->nullable();
            $t->uuid('reporter_id');
            $t->string('reason',32);
            $t->string('status',20)->default('pending');
            $t->timestamp('created_at')->useCurrent();
            $t->timestamp('reviewed_at')->nullable();
            $t->foreign('conversation_id')->references('id')->on('messaging_conversations')->cascadeOnDelete();
            $t->foreign('message_id')->references('id')->on('messaging_messages')->nullOnDelete();
            $t->foreign('reporter_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
        Schema::create('messaging_operational_events', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->string('action',40);
            $t->unsignedSmallInteger('status_code');
            $t->timestamp('created_at')->useCurrent()->index();
        });
    }
    public function down(): void
    {
        foreach (['messaging_operational_events','messaging_reports','messaging_envelopes',
            'messaging_messages','messaging_members','messaging_conversations',
            'messaging_one_time_prekeys','messaging_device_keys'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
