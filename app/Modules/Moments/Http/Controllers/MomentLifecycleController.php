<?php
namespace App\Modules\Moments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Moments\Services\MomentAudienceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MomentLifecycleController extends Controller
{
    private function owner(Request $r,string $moment):object {
        abort_unless(config('orbit_moments.enabled')===true,404);
        $s=$r->attributes->get('orbit_session');abort_unless($s,401);
        return DB::table('moments')->where('id',$moment)->where('creator_id',$s->user_id)
            ->first() ?? abort(404,'Moment unavailable.');
    }
    private function reply(array $data,int $status=200):JsonResponse{
        return response()->json(['data'=>$data],$status)->header('Cache-Control','private, no-store');
    }
    private function emit(string $id,string $event,int $rev):void {
        DB::table('moment_events_outbox')->insert(['id'=>(string)Str::uuid7(),
            'moment_id'=>$id,'event_type'=>$event,'audience_revision'=>$rev,
            'status'=>'pending','created_at'=>now()]);
    }
    private function revokeGrants(array $ids):void{
        if(!$ids)return;
        DB::table('media_audience_grants')->whereIn('id',$ids)->whereNull('revoked_at')
            ->update(['revoked_at'=>now(),'updated_at'=>now()]);
        DB::table('media_device_key_envelopes')->whereIn('grant_id',$ids)->whereNull('revoked_at')
            ->update(['revoked_at'=>now(),'updated_at'=>now()]);
    }
    public function audience(Request $r,string $moment,MomentAudienceService $svc):JsonResponse {
        $m=$this->owner($r,$moment);abort_unless($m->state==='published'&&now()->lt($m->view_expires_at),404);
        $d=$r->validate(['audience_kind'=>['required','in:only_me,accepted_friends,selected_friends,selected_circles'],
            'target_ids'=>['present','array','max:100'],'target_ids.*'=>['uuid','distinct'],
            'revision'=>['required','integer','min:1']]);
        abort_unless((int)$d['revision']===(int)$m->audience_revision,409,'Audience version conflict.');
        $media=DB::table('moment_media')->where('moment_id',$moment)->where('role','primary')->value('media_id');
        abort_unless($media,404);
        return DB::transaction(function()use($m,$moment,$d,$media,$svc):JsonResponse{
            $locked=DB::table('moments')->where('id',$moment)->lockForUpdate()->first();
            abort_unless($locked && (int)$locked->audience_revision===(int)$d['revision'],409);
            $pairs=$svc->resolve($m->creator_id,$media,$d['audience_kind'],$d['target_ids']);
            $old=DB::table('moment_recipients')->where('moment_id',$moment)->whereNull('revoked_at')->get();
            $newKeys=array_flip(array_map(fn($p)=>$p['recipient'].'|'.$p['context'].'|'.$p['grant_id'],$pairs));
            $stale=[];
            foreach($old as $row){
                if(!isset($newKeys[$row->viewer_id.'|'.$row->context_id.'|'.$row->authorizing_grant_id]))
                    $stale[]=$row->authorizing_grant_id;
            }
            // Revoke excluded M6 media grants in the same transaction as M7 audience changes.
            $stale=array_values(array_diff(array_unique($stale),array_column($pairs,'grant_id')));
            $this->revokeGrants($stale);
            DB::table('moment_recipients')->where('moment_id',$moment)->whereNull('revoked_at')->update(['revoked_at'=>now()]);
            DB::table('moment_audience')->where('moment_id',$moment)->whereNull('revoked_at')->update(['revoked_at'=>now()]);
            // Active unique(context,viewer) reuses rows instead of duplicating versions.
            $revision=(int)$locked->audience_revision+1;
            foreach($pairs as $pair){
                $where=['moment_id'=>$moment,'viewer_id'=>$pair['recipient'],'context_id'=>$pair['context']];
                if(DB::table('moment_recipients')->where($where)->exists()){
                    DB::table('moment_recipients')->where($where)->update([
                        'authorizing_grant_id'=>$pair['grant_id'],'version'=>$revision,'revoked_at'=>null]);
                }else{
                    DB::table('moment_recipients')->insert($where+[
                        'id'=>(string)Str::uuid7(),'authorizing_grant_id'=>$pair['grant_id'],
                        'version'=>$revision,'created_at'=>now()]);
                }
            }
            $targets=$d['audience_kind']==='only_me'||$d['audience_kind']==='accepted_friends'?[null]:$d['target_ids'];
            foreach($targets as $target){
                $context=$d['audience_kind']==='selected_circles'?'circle:'.$target:($d['audience_kind']==='only_me'?'self':'friends');
                DB::table('moment_audience')->insert(['id'=>(string)Str::uuid7(),
                    'moment_id'=>$moment,'audience_kind'=>$d['audience_kind'],
                    'target_id'=>$target,'context_id'=>$context,'version'=>$revision,'created_at'=>now()]);
            }
            // Privacy: an audience change must immediately hide tags for
            // people excluded from every new authorized context. A stale tag
            // may never reveal a former recipient in an unrelated Circle.
            $eligibleTagged=array_unique(array_column($pairs,'recipient'));
            $tagQuery=DB::table('moment_people_tags')->where('moment_id',$moment)
                ->whereNull('revoked_at');
            if($eligibleTagged)$tagQuery->whereNotIn('tagged_user_id',$eligibleTagged);
            $tagQuery->update(['revoked_at'=>now()]);
            DB::table('moments')->where('id',$moment)->update([
                'audience_kind'=>$d['audience_kind'],'audience_revision'=>$revision,'updated_at'=>now()]);
            DB::table('media_objects')->where('id',$media)->increment('policy_version');
            $this->emit($moment,'moment.audience.changed.v1',$revision);
            return $this->reply(['audience_revision'=>$revision,'recipient_keys_confirmed'=>true]);
        },3);
    }
    public function delete(Request $r,string $moment):JsonResponse {
        $m=$this->owner($r,$moment);
        if($m->deleted_at!==null)return $this->reply(['deletion_queued'=>true],202);
        DB::transaction(function()use($m,$moment):void{
            $locked=DB::table('moments')->where('id',$moment)->lockForUpdate()->first();
            if(!$locked || $locked->deleted_at!==null)return;
            DB::table('moments')->where('id',$moment)->update(['state'=>'deleted','deleted_at'=>now(),'updated_at'=>now()]);
            $ids=DB::table('moment_recipients')->where('moment_id',$moment)->pluck('authorizing_grant_id')->all();
            $this->revokeGrants(array_unique($ids));
            DB::table('moment_recipients')->where('moment_id',$moment)->update(['revoked_at'=>now()]);
            DB::table('moment_audience')->where('moment_id',$moment)->update(['revoked_at'=>now()]);
            $media=DB::table('moment_media')->where('moment_id',$moment)->pluck('media_id');
            foreach($media as $mediaId) {
                DB::table('media_references')->where('consumer_type','moment')->where('consumer_id',$moment)
                    ->where('media_id',$mediaId)->update(['released_at'=>now(),'updated_at'=>now()]);
                DB::table('media_objects')->where('id',$mediaId)->whereNull('deleted_at')
                    ->update(['state'=>'deleting','deleted_at'=>now(),'updated_at'=>now()]);
                DB::table('media_audience_grants')->where('media_id',$mediaId)->whereNull('revoked_at')
                    ->update(['revoked_at'=>now(),'updated_at'=>now()]);
                DB::table('media_device_key_envelopes')->where('media_id',$mediaId)->whereNull('revoked_at')
                    ->update(['revoked_at'=>now(),'updated_at'=>now()]);
                DB::table('media_delete_jobs')->insertOrIgnore([
                    'id'=>(string)Str::uuid7(),'media_id'=>$mediaId,'reason'=>'moment_owner_delete',
                    'status'=>'pending','attempts'=>0,'next_attempt_at'=>now(),
                    'created_at'=>now(),'updated_at'=>now()]);
            }
            DB::table('moment_lifecycle_jobs')->where('moment_id',$moment)->where('state','pending')
                ->update(['state'=>'cancelled','updated_at'=>now()]);
            $this->emit($moment,'moment.deleted.v1',(int)$locked->audience_revision+1);
        },3);
        return $this->reply(['deletion_queued'=>true],202);
    }
}
