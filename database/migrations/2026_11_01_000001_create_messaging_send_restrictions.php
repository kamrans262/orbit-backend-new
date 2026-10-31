<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('messaging_reports', function(Blueprint $t): void {
            // Only the sender of the message EXPLICITLY chosen for a user
            // report; never enumerate unrelated chat participant identities.
            $t->uuid('subject_user_id')->nullable()->index();
            $t->foreign('subject_user_id')->references('id')
                ->on('orbit_users')->nullOnDelete();
        });
        // Moderation applies only to future sends. Existing device-owned
        // ciphertext remains private and is not decrypted or deleted.
        Schema::create('messaging_send_restrictions', function(Blueprint $t): void {
            $t->uuid('user_id')->primary();
            $t->string('reason',32);
            $t->timestamp('ends_at')->nullable()->index();
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->foreign('user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
            $t->foreign('updated_by')->references('id')->on('admin_users')->nullOnDelete();
        });
        Schema::create('messaging_send_restriction_audits', function(Blueprint $t): void {
            $t->bigIncrements('id');
            $t->uuid('user_id');
            $t->uuid('admin_user_id')->nullable();
            $t->string('action',24);
            $t->string('reason',32)->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->timestamp('created_at')->useCurrent()->index();
            $t->foreign('user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
            $t->foreign('admin_user_id')->references('id')->on('admin_users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messaging_reports', function(Blueprint $t): void {
            $t->dropForeign(['subject_user_id']);
            $t->dropColumn('subject_user_id');
        });
        Schema::dropIfExists('messaging_send_restriction_audits');
        Schema::dropIfExists('messaging_send_restrictions');
    }
};
