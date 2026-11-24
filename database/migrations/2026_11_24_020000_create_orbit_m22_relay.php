<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void {
        Schema::create('relay_leases',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('user_id');
            $t->uuid('device_id');
            $t->uuid('peer_device_id');
            $t->uuid('scope_ref');
            $t->string('content_type',40);
            $t->string('protocol_version',40);
            $t->string('signing_key_id',64);
            $t->unsignedInteger('key_version');
            $t->unsignedInteger('peer_key_version');
            $t->unsignedInteger('policy_revision');
            $t->string('nonce_hash',64);
            $t->timestamp('issued_at');
            $t->timestamp('expires_at')->index();
            $t->timestamp('revoked_at')->nullable();
            $t->string('reason',40)->nullable();
            $t->unique(['device_id','nonce_hash'],'relay_nonce_unique');
            $t->index(['device_id','expires_at']);
            $t->foreign('user_id')->references('id')->on('orbit_users')->cascadeOnDelete();
            $t->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();
            $t->foreign('peer_device_id')->references('id')->on('devices')->cascadeOnDelete();
        });
        Schema::create('relay_reconciliations',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('lease_id');
            $t->uuid('device_id');
            $t->uuid('envelope_id');
            $t->uuid('source_client_id');
            $t->string('content_type',40);
            $t->string('digest',64);
            $t->string('status',24);
            $t->string('reason_code',48);
            $t->uuid('source_ref')->nullable();
            $t->timestamps();
            $t->unique(['device_id','content_type','source_client_id'],'relay_source_unique');
            $t->unique(['device_id','envelope_id'],'relay_envelope_unique');
            $t->index(['device_id','id']);
            $t->foreign('lease_id')->references('id')->on('relay_leases')->cascadeOnDelete();
            $t->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();
        });
        Schema::create('relay_receipts',function(Blueprint $t):void {
            $t->uuid('id')->primary();
            $t->uuid('reconciliation_id');
            $t->uuid('recipient_device_id');
            $t->string('digest',64);
            $t->string('proof_hash',64);
            $t->string('kind',24);
            $t->timestamp('received_at');
            $t->unique(['reconciliation_id','recipient_device_id','kind'],'relay_receipt_unique');
            $t->foreign('reconciliation_id')->references('id')->on('relay_reconciliations')->cascadeOnDelete();
            $t->foreign('recipient_device_id')->references('id')->on('devices')->cascadeOnDelete();
        });
        Schema::create('relay_daily_metrics',function(Blueprint $t):void {
            $t->date('day');
            $t->string('outcome',32);
            $t->unsignedBigInteger('total')->default(0);
            $t->primary(['day','outcome']);
        });
        DB::table('remote_configs')->insertOrIgnore([
            'id'=>(string)Str::uuid(),'key'=>'relay.operations',
            'description'=>'Orbit Relay operational controls',
            'value'=>json_encode(['enabled'=>false,'lease_seconds'=>900,'batch_limit'=>20,'frame_bytes'=>32768]),
            'schema_json'=>json_encode(['type'=>'object']),
            'value_type'=>'object','published'=>true,'client_visible'=>false,
            'version'=>1,'created_at'=>now(),'updated_at'=>now(),
        ]);
    }
    public function down():void {
        foreach(['relay_receipts','relay_reconciliations','relay_leases','relay_daily_metrics'] as $table) Schema::dropIfExists($table);
        DB::table('remote_configs')->where('key','relay.operations')->delete();
    }
};
