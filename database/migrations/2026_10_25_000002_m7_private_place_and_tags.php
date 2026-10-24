<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {
        Schema::table('moments',function(Blueprint $table):void{
            // Place text is client-encrypted; no coordinates or GPS saved.
            $table->longText('place_ciphertext')->nullable();
            $table->string('place_key_version',40)->nullable();
            $table->boolean('location_consent')->default(false);
        });
        Schema::create('moment_people_tags',function(Blueprint $table):void{
            $table->uuid('moment_id');
            $table->uuid('tagged_user_id');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['moment_id','tagged_user_id'],'moment_people_tag_pk');
            $table->foreign('moment_id')->references('id')->on('moments')->cascadeOnDelete();
            $table->foreign('tagged_user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
        });
    }
    public function down():void {
        Schema::dropIfExists('moment_people_tags');
        Schema::table('moments',function(Blueprint $table):void{
            $table->dropColumn(['place_ciphertext','place_key_version','location_consent']);
        });
    }
};
