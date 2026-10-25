<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void{
        Schema::create('moment_evidence_access_logs',function(Blueprint $t):void{
            $t->uuid('id')->primary();
            $t->uuid('report_id');
            $t->uuid('operator_id');
            $t->char('ticket_digest',64);
            $t->timestamp('created_at')->useCurrent();
            $t->index(['report_id','created_at']);
            $t->foreign('report_id')->references('id')->on('moment_reports')->cascadeOnDelete();
            $t->foreign('operator_id')->references('id')->on('admin_users')->restrictOnDelete();
        });
    }
    public function down():void{
        Schema::dropIfExists('moment_evidence_access_logs');
    }
};
