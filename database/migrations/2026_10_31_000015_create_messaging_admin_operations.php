<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Operational controls only. Never store private plaintext, content
        // keys, raw message identifiers or recipient identities here.
        Schema::create('messaging_admin_controls', function(Blueprint $t): void {
            $t->string('key',48)->primary();
            $t->string('value',80);
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->foreign('updated_by')->references('id')->on('admin_users')->nullOnDelete();
        });
        Schema::create('messaging_admin_activity', function(Blueprint $t): void {
            $t->bigIncrements('id');
            $t->uuid('admin_user_id');
            $t->string('action',64);
            $t->json('details')->nullable(); // Whitelisted aggregate operations only.
            $t->timestamp('created_at')->useCurrent()->index();
            $t->foreign('admin_user_id')->references('id')->on('admin_users')->cascadeOnDelete();
        });
        Schema::table('messaging_reports', function(Blueprint $t): void {
            $t->text('moderator_note_enc')->nullable();
            $t->index(['status','created_at'],'messaging_reports_queue_idx');
        });
    }

    public function down(): void
    {
        Schema::table('messaging_reports', function(Blueprint $t): void {
            $t->dropIndex('messaging_reports_queue_idx');
            $t->dropColumn('moderator_note_enc');
        });
        Schema::dropIfExists('messaging_admin_activity');
        Schema::dropIfExists('messaging_admin_controls');
    }
};
