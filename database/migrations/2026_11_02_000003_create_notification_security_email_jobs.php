<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notification_security_email_jobs', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('notice_id')->unique();
            $t->string('state',20)->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->string('last_error_code',60)->nullable();
            $t->timestamps();
            $t->index(['state','next_attempt_at','lease_until'],'notification_mail_pending');
            $t->foreign('notice_id')->references('id')->on('notification_inbox')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_security_email_jobs');
    }
};
