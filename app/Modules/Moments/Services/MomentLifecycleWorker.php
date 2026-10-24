<?php
namespace App\Modules\Moments\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MomentLifecycleWorker
{
    public function run(int $limit=30):array
    {
        $stats=['expired'=>0,'queued_purges'=>0,'held'=>0,'errors'=>0];
        // Retention remains active for existing content even if posting is later disabled.
        $jobs=DB::table('moment_lifecycle_jobs')->where('state','pending')
            ->where('due_at','<=',now())->orderBy('due_at')->limit(max(1,min($limit,100)))->get();
        foreach($jobs as $job){
            try{
                DB::transaction(function()use($job,&$stats):void{
                    $j=DB::table('moment_lifecycle_jobs')->where('id',$job->id)->lockForUpdate()->first();
                    if(!$j || $j->state!=='pending'||now()->lt($j->due_at))return;
                    $m=DB::table('moments')->where('id',$j->moment_id)->lockForUpdate()->first();
                    if(!$m){DB::table('moment_lifecycle_jobs')->where('id',$j->id)->update(['state'=>'done','processed_at'=>now()]);return;}
                    if($j->job_type==='view_expiry'){
                        if($m->state==='published' && now()->gte($m->view_expires_at)) {
                            DB::table('moments')->where('id',$m->id)->update(['state'=>'expired','updated_at'=>now()]);
                            DB::table('moment_events_outbox')->insert(['id'=>(string)Str::uuid7(),'moment_id'=>$m->id,'event_type'=>'moment.expired.v1',
                                'audience_revision'=>$m->audience_revision,'status'=>'pending','created_at'=>now()]);
                            $stats['expired']++;
                        }
                    } elseif($j->job_type==='purge'){
                        if(now()->lt($m->purge_due_at))return;
                        $mediaIds=DB::table('moment_media')->where('moment_id',$m->id)->pluck('media_id')->all();
                        $held=DB::table('media_objects')->whereIn('id',$mediaIds)->where('legal_hold_until','>',now())->exists();
                        if($held){
                            $stats['held']++;DB::table('moment_lifecycle_jobs')->where('id',$j->id)
                                ->update(['due_at'=>now()->addDay(),'updated_at'=>now()]);return;
                        }
                        foreach($mediaIds as $mediaId){
                            DB::table('media_references')->where('media_id',$mediaId)->where('consumer_type','moment')
                                ->where('consumer_id',$m->id)->whereNull('released_at')
                                ->update(['released_at'=>now(),'updated_at'=>now()]);
                            if(DB::table('media_references')->where('media_id',$mediaId)->whereNull('released_at')->exists())continue;
                            DB::table('media_objects')->where('id',$mediaId)->whereNotIn('state',['deleted','deleting'])
                                ->update(['state'=>'deleting','deleted_at'=>now(),'updated_at'=>now()]);
                            DB::table('media_audience_grants')->where('media_id',$mediaId)->whereNull('revoked_at')
                                ->update(['revoked_at'=>now(),'updated_at'=>now()]);
                            DB::table('media_device_key_envelopes')->where('media_id',$mediaId)->whereNull('revoked_at')
                                ->update(['revoked_at'=>now(),'updated_at'=>now()]);
                            DB::table('media_delete_jobs')->insertOrIgnore(['id'=>(string)Str::uuid7(),
                                'media_id'=>$mediaId,'reason'=>'moment_retention',
                                'status'=>'pending','attempts'=>0,'next_attempt_at'=>now(),
                                'created_at'=>now(),'updated_at'=>now()]);
                            $stats['queued_purges']++;
                        }
                        DB::table('moments')->where('id',$m->id)->update(['state'=>'deleted',
                            'deleted_at'=>$m->deleted_at??now(),'updated_at'=>now()]);
                    }
                    DB::table('moment_lifecycle_jobs')->where('id',$j->id)->update([
                        'state'=>'done','processed_at'=>now(),'updated_at'=>now()]);
                },3);
            }catch(\Throwable){
                $stats['errors']++;
                DB::table('moment_lifecycle_jobs')->where('id',$job->id)->increment('attempts',1,[
                    'last_error_code'=>'lifecycle_transaction_failed','updated_at'=>now()]);
            }
        }
        return $stats;
    }
}
