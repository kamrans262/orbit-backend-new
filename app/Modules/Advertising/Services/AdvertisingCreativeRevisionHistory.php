<?php

namespace App\Modules\Advertising\Services;

use Illuminate\Support\Facades\DB;

/**
 * Append-only content revision. Review-state fields may advance once under
 * the existing Admin independent-review transaction; creative payload cannot.
 */
final class AdvertisingCreativeRevisionHistory
{
    public function capture(string $creativeId): void
    {
        $row=DB::table('ad_creatives')->where('id',$creativeId)->first();
        abort_unless($row,404,'Creative not found.');
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

    public function review(string $creativeId,int $revision,string $status,
        string $reviewer,?string $reason): void
    {
        $updated=DB::table('ad_creative_revisions')
            ->where('creative_id',$creativeId)->where('revision',$revision)
            ->where('status','pending')
            ->update([
                'status'=>$status,'reviewed_by'=>$reviewer,
                'reviewed_at'=>now('UTC'),
                'rejection_reason'=>$reason,
                'updated_at'=>now('UTC'),
            ]);
        abort_unless($updated===1,409,'Review revision history conflict.');
    }
}
