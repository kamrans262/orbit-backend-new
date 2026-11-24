<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration{
    public function up():void{
        Schema::table('admin_users',fn(Blueprint $t)=>$t->unsignedInteger('session_revision')->default(0));
        Schema::create('admin_access_reviews',function(Blueprint $t){
            $t->uuid('id')->primary();$t->foreignUuid('target_id')->constrained('admin_users')->restrictOnDelete();
            $t->foreignUuid('requested_by')->constrained('admin_users')->restrictOnDelete();
            $t->foreignUuid('decided_by')->nullable()->constrained('admin_users')->restrictOnDelete();
            $t->string('operation',16);$t->foreignUuid('role_id')->nullable()->constrained('admin_roles')->restrictOnDelete();
            $t->string('target_digest',64);$t->string('role_digest',64)->nullable();$t->string('reason',500);
            $t->string('state',16)->default('pending');$t->timestamp('expires_at');$t->timestamps();
            $t->index(['state','expires_at']);
        });
    }
    public function down():void{
        Schema::dropIfExists('admin_access_reviews');
        Schema::table('admin_users',fn(Blueprint $t)=>$t->dropColumn('session_revision'));
    }
};
