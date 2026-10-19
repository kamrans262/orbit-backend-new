<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Additive migration: preserve existing pending OTP challenges.
        Schema::table('otp_challenges', function (Blueprint $table): void {
            $table->string('purpose', 16)->default('legacy')->index();
        });
    }

    public function down(): void
    {
        Schema::table('otp_challenges', function (Blueprint $table): void {
            $table->dropColumn('purpose');
        });
    }
};
