<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration{
    public function up():void{
        Schema::table('sos_controls',fn(Blueprint $t)=>$t->unsignedInteger('revision')->default(0));
        Schema::create('safety_policy_reviews',function(Blueprint $t){
            $t->uuid('id')->primary();$t->string('key',64);$t->unsignedInteger('expected_revision');$t->string('value',16);
            $t->foreignUuid('requested_by')->constrained('admin_users')->restrictOnDelete();
            $t->foreignUuid('decided_by')->nullable()->constrained('admin_users')->restrictOnDelete();
            $t->string('reason',500);$t->string('state',16)->default('pending');$t->timestamp('expires_at');$t->timestamps();
            $t->index(['key','state','expires_at']);
        });
    }
    public function down():void{
        Schema::dropIfExists('safety_policy_reviews');
        Schema::table('sos_controls',fn(Blueprint $t)=>$t->dropColumn('revision'));
    }
};
