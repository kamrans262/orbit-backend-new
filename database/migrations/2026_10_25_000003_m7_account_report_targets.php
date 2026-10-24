<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
  public function up():void {
    Schema::table('moment_reports',function(Blueprint $table):void {
      $table->string('report_target',16)->default('moment');
      $table->uuid('reported_user_id')->nullable()->index();
      $table->foreign('reported_user_id')->references('id')
        ->on('orbit_users')->nullOnDelete();
    });
  }
  public function down():void {
    Schema::table('moment_reports',function(Blueprint $table):void {
      $table->dropForeign(['reported_user_id']);
      $table->dropColumn(['reported_user_id','report_target']);
    });
  }
};
