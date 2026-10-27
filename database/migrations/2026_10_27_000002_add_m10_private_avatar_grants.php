<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if(!Schema::hasTable('location_shares'))return;
        Schema::table('location_shares',function(Blueprint $table): void {
            $table->uuid('avatar_media_id')->nullable();
            $table->uuid('avatar_grant_id')->nullable();
        });
    }
    public function down(): void {
        if(!Schema::hasTable('location_shares'))return;
        Schema::table('location_shares',function(Blueprint $table): void {
            $table->dropColumn(['avatar_media_id','avatar_grant_id']);
        });
    }
};
