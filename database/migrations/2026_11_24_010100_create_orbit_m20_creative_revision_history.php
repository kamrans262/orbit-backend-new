<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_creative_revisions', function (Blueprint $t): void {
            $t->uuid('creative_id');
            $t->unsignedInteger('revision');
            $t->uuid('sponsor_id');
            $t->string('title',120);
            $t->string('subtitle',220);
            $t->string('private_path',255);
            $t->char('sha256',64);
            $t->string('mime',30);
            $t->unsignedSmallInteger('width');
            $t->unsignedSmallInteger('height');
            $t->string('cta_url',500);
            $t->string('status',24);
            $t->uuid('submitted_by');
            $t->uuid('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->string('rejection_reason',500)->nullable();
            $t->timestamp('created_at');
            $t->timestamp('updated_at')->nullable();
            $t->primary(['creative_id','revision'],'ad_creative_revision_pk');
            $t->foreign('creative_id')->references('id')->on('ad_creatives')->cascadeOnDelete();
            $t->index(['sponsor_id','status']);
        });
        // For an existing M20 Stage 2 environment, preserve the current
        // revision exactly. Earlier versions overwritten *before* this
        // migration cannot be reconstructed and are not misrepresented.
        DB::table('ad_creatives')->orderBy('id')->chunk(200,function ($rows):void {
            foreach($rows as $row) {
                DB::table('ad_creative_revisions')->insert([
                    'creative_id'=>$row->id,'revision'=>$row->revision,
                    'sponsor_id'=>$row->sponsor_id,'title'=>$row->title,
                    'subtitle'=>$row->subtitle,'private_path'=>$row->private_path,
                    'sha256'=>$row->sha256,'mime'=>$row->mime,
                    'width'=>$row->width,'height'=>$row->height,
                    'cta_url'=>$row->cta_url,'status'=>$row->status,
                    'submitted_by'=>$row->submitted_by,
                    'reviewed_by'=>$row->reviewed_by,'reviewed_at'=>$row->reviewed_at,
                    'rejection_reason'=>$row->rejection_reason,
                    'created_at'=>$row->created_at,'updated_at'=>$row->updated_at,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Stage 2 creative data always survives a Stage 4-only rollback.
        Schema::dropIfExists('ad_creative_revisions');
    }
};
