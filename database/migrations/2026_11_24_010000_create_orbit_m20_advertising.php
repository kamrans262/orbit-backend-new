<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ad_sponsors', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('display_name', 150);
            $t->string('legal_name', 180);
            $t->string('approved_domain', 190);
            $t->string('status', 24)->default('draft');
            $t->string('verification_status', 24)->default('pending');
            $t->uuid('created_by');
            $t->uuid('verified_by')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'verification_status']);
            $t->foreign('created_by')->references('id')->on('admin_users')->restrictOnDelete();
            $t->foreign('verified_by')->references('id')->on('admin_users')->nullOnDelete();
        });
        Schema::create('ad_sponsor_contacts', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('sponsor_id')->constrained('ad_sponsors')->cascadeOnDelete();
            $t->string('contact_kind', 24);
            $t->text('encrypted_contact');
            $t->timestamps();
        });
        Schema::create('ad_creatives', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('sponsor_id')->constrained('ad_sponsors')->restrictOnDelete();
            $t->unsignedInteger('revision')->default(1);
            $t->string('title', 120);
            $t->string('subtitle', 220);
            $t->string('private_path', 255);
            $t->char('sha256', 64);
            $t->string('mime', 30);
            $t->unsignedSmallInteger('width');
            $t->unsignedSmallInteger('height');
            $t->string('cta_url', 500);
            $t->string('status', 24)->default('pending');
            $t->uuid('submitted_by');
            $t->uuid('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->string('rejection_reason', 500)->nullable();
            $t->timestamps();
            $t->index(['sponsor_id', 'status', 'revision']);
            $t->foreign('submitted_by')->references('id')->on('admin_users')->restrictOnDelete();
            $t->foreign('reviewed_by')->references('id')->on('admin_users')->nullOnDelete();
        });
        Schema::create('ad_campaigns', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('sponsor_id')->constrained('ad_sponsors')->restrictOnDelete();
            $t->foreignUuid('creative_id')->constrained('ad_creatives')->restrictOnDelete();
            $t->string('name', 120);
            $t->unsignedInteger('creative_revision');
            $t->unsignedInteger('revision')->default(1);
            $t->string('status', 24)->default('draft');
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->unsignedTinyInteger('priority')->default(5);
            $t->uuid('created_by');
            $t->uuid('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'starts_at', 'ends_at']);
            $t->foreign('created_by')->references('id')->on('admin_users')->restrictOnDelete();
            $t->foreign('reviewed_by')->references('id')->on('admin_users')->nullOnDelete();
        });
        Schema::create('ad_placements', function (Blueprint $t): void {
            $t->string('key', 48)->primary();
            $t->boolean('enabled')->default(false);
            $t->string('component_name', 90);
            $t->string('disclosure_mode', 24)->default('Sponsored');
            $t->unsignedSmallInteger('daily_decision_cap')->nullable();
            $t->unsignedSmallInteger('daily_impression_cap')->nullable();
            $t->unsignedInteger('rule_version')->default(1);
            $t->uuid('updated_by')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->foreign('updated_by')->references('id')->on('admin_users')->nullOnDelete();
        });
        Schema::create('ad_campaign_placements', function (Blueprint $t): void {
            $t->foreignUuid('campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $t->string('placement_key', 48);
            $t->timestamp('created_at');
            $t->primary(['campaign_id', 'placement_key'], 'ad_campaign_placement_pk');
            $t->foreign('placement_key')->references('key')->on('ad_placements')->restrictOnDelete();
            $t->index(['placement_key','campaign_id']);
        });
        Schema::create('ad_decisions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('campaign_id')->constrained('ad_campaigns')->restrictOnDelete();
            $t->string('placement_key', 48);
            $t->char('viewer_digest',64);
            $t->uuid('request_id');
            $t->unsignedInteger('creative_revision');
            $t->timestamp('issued_at');
            $t->timestamp('expires_at');
            $t->timestamp('impression_at')->nullable();
            $t->timestamp('click_at')->nullable();
            $t->string('status',24)->default('issued');
            $t->unique(['request_id','viewer_digest','placement_key'],'ad_decision_idempotency');
            $t->index(['viewer_digest','issued_at']);
            $t->index(['campaign_id','issued_at']);
        });
        Schema::create('ad_frequency_windows', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->char('viewer_digest',64);
            $t->foreignUuid('campaign_id')->constrained('ad_campaigns')->restrictOnDelete();
            $t->string('placement_key',48);
            $t->date('window_start_utc');
            $t->unsignedSmallInteger('decisions')->default(0);
            $t->unsignedSmallInteger('impressions')->default(0);
            $t->timestamp('last_decision_at')->nullable();
            $t->unique(['viewer_digest','campaign_id','placement_key','window_start_utc'],'ad_frequency_unique');
        });
        Schema::create('ad_measurement_events', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('decision_id')->constrained('ad_decisions')->restrictOnDelete();
            $t->string('event_type', 16);
            $t->uuid('client_event_id');
            $t->timestamp('accepted_at');
            $t->char('proof_digest',64);
            $t->unique(['decision_id','event_type'], 'ad_single_event_per_decision');
            $t->unique(['decision_id','event_type','client_event_id'],'ad_measure_event_retry');
        });
        Schema::create('ad_daily_aggregates', function (Blueprint $t): void {
            $t->foreignUuid('campaign_id')->constrained('ad_campaigns')->restrictOnDelete();
            $t->string('placement_key', 48);
            $t->date('day_utc');
            $t->unsignedBigInteger('impressions')->default(0);
            $t->unsignedBigInteger('clicks')->default(0);
            $t->timestamp('updated_at');
            $t->primary(['campaign_id','placement_key','day_utc'],'ad_aggregate_pk');
        });
        Schema::create('ad_admin_audits', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('admin_id');
            $t->string('action', 64);
            $t->string('subject_type', 32);
            $t->string('subject_id', 64);
            $t->string('old_state', 120)->nullable();
            $t->string('new_state', 120)->nullable();
            $t->string('reason',500);
            $t->timestamp('created_at');
            $t->index(['created_at','subject_type','subject_id'],'ad_audit_time_subject');
            $t->foreign('admin_id')->references('id')->on('admin_users')->restrictOnDelete();
        });
        DB::table('ad_placements')->insert([
            ['key'=>'home.promo','enabled'=>false,'component_name'=>'PromoBannerCard',
                'disclosure_mode'=>'Sponsored','rule_version'=>1],
            ['key'=>'moments.promo','enabled'=>false,'component_name'=>'MomentPromoFeedItem',
                'disclosure_mode'=>'Sponsored','rule_version'=>1],
        ]);
    }

    public function down(): void
    {
        // All previous Orbit tables are strictly preserved.
        foreach (['ad_admin_audits','ad_daily_aggregates','ad_measurement_events',
            'ad_frequency_windows','ad_decisions','ad_campaign_placements',
            'ad_placements','ad_campaigns','ad_creatives',
            'ad_sponsor_contacts','ad_sponsors'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
