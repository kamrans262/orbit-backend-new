<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        Schema::create('orbit_realtime_auth_receipts',function(Blueprint $t):void{
            // Opaque session pointer only. No user ID, credentials, payload or history.
            $t->uuid('session_id')->primary();
            $t->char('project_hash',64);
            $t->timestamp('tombstone_after')->nullable()->index();
            $t->timestamp('auth_after')->nullable()->index();
            $t->timestamp('available_at')->index();
            $t->unsignedInteger('attempts')->default(0);
        });
    }
    public function down():void {Schema::dropIfExists('orbit_realtime_auth_receipts');}
};
