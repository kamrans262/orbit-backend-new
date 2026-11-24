<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration{
    public function up():void{
        Schema::create('billing_products',function(Blueprint $t){
            $t->uuid('id')->primary();$t->string('tier',8);$t->string('provider',8);
            $t->string('environment',12);$t->string('product_id',150);$t->string('base_plan_id',100)->default('');
            $t->string('interval',12)->default('month');$t->boolean('enabled')->default(false);
            $t->unsignedInteger('revision')->default(1);$t->timestamps();
            $t->unique(['provider','environment','product_id','base_plan_id'],'bill_product_unique');
        });
        Schema::create('billing_purchase_contexts',function(Blueprint $t){
            $t->uuid('id')->primary();$t->foreignUuid('user_id')->constrained('orbit_users')->cascadeOnDelete();
            $t->foreignUuid('product_id')->constrained('billing_products')->restrictOnDelete();
            $t->uuid('client_request_id');$t->string('body_hash',64);$t->string('binding_hash',64)->index();
            $t->timestamp('expires_at');$t->timestamps();$t->unique(['user_id','client_request_id'],'bill_context_request');
        });
        Schema::create('billing_subscriptions',function(Blueprint $t){
            $t->uuid('id')->primary();$t->foreignUuid('user_id')->constrained('orbit_users')->cascadeOnDelete();
            $t->foreignUuid('product_id')->constrained('billing_products')->restrictOnDelete();
            $t->string('provider',8);$t->string('environment',12);$t->string('lineage_hash',64);
            $t->text('reference_cipher');$t->string('state',16);$t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();$t->boolean('auto_renew')->nullable();
            $t->uuid('replaced_by')->nullable();$t->string('ack_state',16)->default('not_required');$t->timestamp('verified_at',6);
            $t->timestamp('next_check_at')->index();$t->unsignedInteger('revision')->default(1);$t->timestamps();
            $t->unique(['provider','environment','lineage_hash'],'bill_lineage_unique');
            $t->index(['user_id','environment','state']);
        });
        Schema::create('billing_events',function(Blueprint $t){
            $t->uuid('id')->primary();$t->foreignUuid('user_id')->nullable()->constrained('orbit_users')->cascadeOnDelete();
            $t->string('provider',8);$t->string('environment',12);$t->string('event_hash',64);
            $t->string('body_hash',64);$t->string('reference_hash',64)->nullable()->index();$t->text('reference_cipher')->nullable();$t->string('state',16)->default('queued');
            $t->unsignedInteger('attempts')->default(0);$t->timestamp('next_attempt_at')->index();
            $t->string('error_code',40)->nullable();$t->timestamps();
            $t->unique(['provider','environment','event_hash'],'bill_event_unique');
        });
        Schema::create('billing_entitlements',function(Blueprint $t){
            $t->uuid('id')->primary();$t->foreignUuid('user_id')->constrained('orbit_users')->cascadeOnDelete();
            $t->string('environment',12);$t->string('tier',8)->nullable();$t->string('state',16);
            $t->timestamp('effective_until')->nullable();$t->boolean('auto_renew')->nullable();
            $t->unsignedInteger('revision')->default(1);$t->timestamp('verified_at',6)->nullable();$t->timestamps();
            $t->unique(['user_id','environment'],'bill_entitlement_owner');
        });
    }
    public function down():void{
        foreach(['billing_entitlements','billing_events','billing_subscriptions','billing_purchase_contexts','billing_products'] as $table)Schema::dropIfExists($table);
    }
};
