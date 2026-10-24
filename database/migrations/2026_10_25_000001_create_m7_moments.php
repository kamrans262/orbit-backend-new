<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('moments',function(Blueprint $t):void{
   $t->uuid('id')->primary();$t->uuid('creator_id')->index();$t->uuid('client_id');$t->char('request_key_hash',64);$t->char('request_payload_hash',64);
   $t->string('state',16)->default('published');$t->string('audience_kind',24);$t->longText('caption_ciphertext')->nullable();$t->string('caption_key_version',40)->nullable();
   $t->unsignedBigInteger('audience_revision')->default(1);$t->unsignedBigInteger('publish_version')->default(1);$t->timestamp('published_at');$t->timestamp('rail_expires_at');
   $t->timestamp('view_expires_at')->index();$t->timestamp('purge_due_at')->index();$t->timestamp('deleted_at')->nullable();$t->timestamps();
   $t->unique(['creator_id','client_id']);$t->unique(['creator_id','request_key_hash']);$t->index(['state','published_at','id']);$t->index(['creator_id','published_at','id']);
   $t->foreign('creator_id')->references('id')->on('orbit_users')->restrictOnDelete();
  });
  Schema::create('moment_media',function(Blueprint $t):void{
   $t->uuid('moment_id');$t->uuid('media_id');$t->string('role',16)->default('primary');$t->unsignedTinyInteger('position')->default(0);$t->timestamp('created_at')->useCurrent();
   $t->primary(['moment_id','media_id','role']);$t->index('media_id');$t->foreign('moment_id')->references('id')->on('moments')->cascadeOnDelete();$t->foreign('media_id')->references('id')->on('media_objects')->restrictOnDelete();
  });
  Schema::create('moment_audience',function(Blueprint $t):void{
   $t->uuid('id')->primary();$t->uuid('moment_id');$t->string('audience_kind',24);$t->uuid('target_id')->nullable();$t->string('context_id',64);
   $t->unsignedBigInteger('version');$t->timestamp('revoked_at')->nullable();$t->timestamp('created_at')->useCurrent();$t->index(['moment_id','version']);$t->index(['audience_kind','target_id']);
   $t->foreign('moment_id')->references('id')->on('moments')->cascadeOnDelete();
  });
  Schema::create('moment_recipients',function(Blueprint $t):void{
   $t->uuid('id')->primary();$t->uuid('moment_id');$t->uuid('viewer_id');$t->string('context_id',64);$t->uuid('authorizing_grant_id');
   $t->unsignedBigInteger('version');$t->timestamp('revoked_at')->nullable();$t->timestamp('created_at')->useCurrent();$t->unique(['moment_id','viewer_id','context_id'],'moment_recipient_context_unique');$t->index(['viewer_id','moment_id']);
   $t->foreign('moment_id')->references('id')->on('moments')->cascadeOnDelete();$t->foreign('viewer_id')->references('id')->on('orbit_users')->restrictOnDelete();
   $t->foreign('authorizing_grant_id')->references('id')->on('media_audience_grants')->restrictOnDelete();
  });
  Schema::create('moment_reactions',function(Blueprint $t):void{
   $t->uuid('id')->primary();$t->uuid('moment_id');$t->uuid('user_id');$t->string('context_id',64);$t->string('reaction_code',24);$t->timestamp('deleted_at')->nullable();$t->timestamps();
   $t->unique(['moment_id','user_id','context_id']);$t->foreign('moment_id')->references('id')->on('moments')->cascadeOnDelete();$t->foreign('user_id')->references('id')->on('orbit_users')->restrictOnDelete();
  });
  Schema::create('moment_comments',function(Blueprint $t):void{
   $t->uuid('id')->primary();$t->uuid('moment_id');$t->uuid('author_id');$t->uuid('parent_id')->nullable();$t->string('context_id',64);$t->longText('body_ciphertext');$t->string('key_version',40);$t->char('request_key_hash',64);$t->char('request_payload_hash',64);$t->timestamp('deleted_at')->nullable();$t->timestamps();
   $t->index(['moment_id','context_id','created_at','id']);$t->unique(['moment_id','author_id','request_key_hash'],'moment_comment_idempotency');$t->foreign('moment_id')->references('id')->on('moments')->cascadeOnDelete();$t->foreign('author_id')->references('id')->on('orbit_users')->restrictOnDelete();$t->foreign('parent_id')->references('id')->on('moment_comments')->nullOnDelete();
  });
  Schema::create('moment_views',function(Blueprint $t):void{
   $t->uuid('id')->primary();$t->uuid('moment_id');$t->uuid('viewer_id');$t->string('context_id',64);$t->timestamp('first_viewed_at');$t->timestamp('last_viewed_at');
   $t->unique(['moment_id','viewer_id','context_id']);$t->foreign('moment_id')->references('id')->on('moments')->cascadeOnDelete();$t->foreign('viewer_id')->references('id')->on('orbit_users')->restrictOnDelete();
  });
  Schema::create('moment_reports',function(Blueprint $t):void{
   $t->uuid('id')->primary();$t->uuid('moment_id');$t->uuid('comment_id')->nullable();$t->uuid('reporter_id');$t->string('context_id',64);$t->string('reason',32);
   $t->longText('details_ciphertext')->nullable();$t->longText('consent_evidence_ciphertext')->nullable();$t->boolean('evidence_consent')->default(false);$t->string('status',16)->default('pending');$t->timestamps();
   $t->index(['status','created_at']);$t->index(['moment_id','status']);$t->foreign('moment_id')->references('id')->on('moments')->cascadeOnDelete();$t->foreign('comment_id')->references('id')->on('moment_comments')->nullOnDelete();$t->foreign('reporter_id')->references('id')->on('orbit_users')->restrictOnDelete();
  });
  Schema::create('moment_moderation_actions',function(Blueprint $t):void{
   $t->uuid('id')->primary();$t->uuid('report_id');$t->uuid('operator_id');$t->string('action',24);$t->string('ticket',80);$t->string('reason_code',48);$t->timestamp('created_at')->useCurrent();$t->index(['report_id','created_at']);
   $t->foreign('report_id')->references('id')->on('moment_reports')->restrictOnDelete();$t->foreign('operator_id')->references('id')->on('admin_users')->restrictOnDelete();
  });
  Schema::create('moment_events_outbox',function(Blueprint $t):void{
   $t->uuid('id')->primary();$t->uuid('moment_id');$t->string('event_type',60);$t->unsignedBigInteger('audience_revision');$t->char('payload_digest',64)->nullable();$t->string('status',16)->default('pending');
   $t->timestamp('created_at')->useCurrent();$t->timestamp('processed_at')->nullable();$t->index(['status','created_at']);$t->index(['moment_id','created_at']);$t->foreign('moment_id')->references('id')->on('moments')->cascadeOnDelete();
  });
  Schema::create('moment_idempotency',function(Blueprint $t):void{
   $t->uuid('owner_id');$t->char('request_key_hash',64);$t->char('payload_digest',64);$t->uuid('moment_id');$t->timestamp('created_at')->useCurrent();$t->primary(['owner_id','request_key_hash']);
   $t->foreign('moment_id')->references('id')->on('moments')->cascadeOnDelete();$t->foreign('owner_id')->references('id')->on('orbit_users')->restrictOnDelete();
  });
  Schema::create('moment_lifecycle_jobs',function(Blueprint $t):void{
   $t->uuid('id')->primary();$t->uuid('moment_id');$t->string('job_type',24);$t->timestamp('due_at');$t->string('state',16)->default('pending');$t->unsignedInteger('attempts')->default(0);$t->string('last_error_code',60)->nullable();$t->timestamp('processed_at')->nullable();$t->timestamps();
   $t->unique(['moment_id','job_type']);$t->index(['state','due_at']);$t->foreign('moment_id')->references('id')->on('moments')->cascadeOnDelete();
  });
 }
 public function down():void{
  foreach(['moment_lifecycle_jobs','moment_idempotency','moment_events_outbox','moment_moderation_actions','moment_reports','moment_views','moment_comments','moment_reactions','moment_recipients','moment_audience','moment_media','moments'] as $name) Schema::dropIfExists($name);
 }
};
