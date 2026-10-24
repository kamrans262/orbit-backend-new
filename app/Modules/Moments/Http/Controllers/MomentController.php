<?php
namespace App\Modules\Moments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Media\Services\MediaReferenceService;
use App\Modules\Moments\Services\MomentAudienceService;
use App\Modules\Moments\Services\MomentPolicy;
use App\Modules\Social\Services\SocialGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MomentController extends Controller
{
    private function actor(Request $r):array
    {
        abort_unless(config('orbit_moments.enabled')===true&&config('orbit_media.enabled')===true,404,'Moments unavailable.');
        $session=$r->attributes->get('orbit_session');abort_unless($session,401);
        return [(string)$session->user_id,(string)$session->device_id];
    }
    private function reply(array $data,int $status=200):JsonResponse
    {
        return response()->json(['data'=>$data],$status)->header('Cache-Control','private, no-store');
    }
    private function idem(Request $r):string
    {
        $key=(string)$r->header('Idempotency-Key','');
        abort_unless(Str::isUuid($key),422,'UUID Idempotency-Key required.');
        return hash('sha256',$key);
    }
    private function moment(string $id):object
    {
        return DB::table('moments')->where('id',$id)->first() ?? abort(404,'Moment unavailable.');
    }
    private function emit(string $id,string $event,int $rev,?string $digest=null):void
    {
        DB::table('moment_events_outbox')->insert([
            'id'=>(string)Str::uuid7(),'moment_id'=>$id,'event_type'=>$event,
            'audience_revision'=>$rev,'payload_digest'=>$digest,'status'=>'pending','created_at'=>now(),
        ]);
    }
    private function brief(object $m,string $context,string $viewer):array
    {
        $media=DB::table('moment_media as mm')
            ->join('media_objects as media','media.id','=','mm.media_id')
            ->where('mm.moment_id',$m->id)->where('mm.role','primary')
            ->first(['media.id','media.kind','media.declared_duration_ms']);
        $mediaId=$media?->id;
        $social=app(SocialGraphService::class);
        // A tag is sensitive identity metadata. Only disclose people who
        // currently belong to this same authorized audience context. Being
        // friends with the author alone does NOT permit Circle A to discover
        // a person who can only view through Circle B.
        $tags=DB::table('moment_people_tags as tag')
            ->where('tag.moment_id',$m->id)->whereNull('tag.revoked_at')
            ->whereExists(function($q)use($m,$context):void{
                $q->selectRaw('1')->from('moment_recipients as recipient')
                    ->whereColumn('recipient.viewer_id','tag.tagged_user_id')
                    ->where('recipient.moment_id',$m->id)
                    ->where('recipient.version',$m->audience_revision)
                    ->whereNull('recipient.revoked_at');
                if($context!=='self')$q->where('recipient.context_id',$context);
            })
            ->pluck('tag.tagged_user_id')
            ->filter(fn($id)=>$social->isFriend($m->creator_id,(string)$id)
                && !$social->isBlockedEitherWay($m->creator_id,(string)$id))
            ->values()->all();
        $reaction=DB::table('moment_reactions')->where('moment_id',$m->id)
            ->where('user_id',$viewer)->where('context_id',$context)
            ->whereNull('deleted_at')->value('reaction_code');
        return ['id'=>$m->id,'creator_id'=>$m->creator_id,'media_id'=>$mediaId,
            'my_reaction'=>$reaction,
            'tagged_user_ids'=>$tags,
            'place_ciphertext'=>$m->location_consent?$m->place_ciphertext:null,
            'place_key_version'=>$m->location_consent?$m->place_key_version:null,
            'media_kind'=>$media?->kind??'image',
            'duration_ms'=>$media?->declared_duration_ms,
            'context_id'=>$context,'published_at'=>$m->published_at,
            'rail_expires_at'=>$m->rail_expires_at,'view_expires_at'=>$m->view_expires_at,
            'audience_revision'=>(int)$m->audience_revision,'publish_version'=>(int)$m->publish_version,
            'caption_ciphertext'=>$m->caption_ciphertext,'caption_key_version'=>$m->caption_key_version];
    }
    private function pairsWrite(string $moment,array $pairs,string $kind,array $targets,int $version):void
    {
        $audiences=$kind==='only_me'||$kind==='accepted_friends'?[null]:$targets;
        foreach($audiences as $target) {
            $context=$kind==='selected_circles'?'circle:'.$target:($kind==='only_me'?'self':'friends');
            DB::table('moment_audience')->insert([
                'id'=>(string)Str::uuid7(),'moment_id'=>$moment,'audience_kind'=>$kind,
                'target_id'=>$target,'context_id'=>$context,'version'=>$version,'created_at'=>now(),
            ]);
        }
        foreach($pairs as $pair)DB::table('moment_recipients')->insert([
            'id'=>(string)Str::uuid7(),'moment_id'=>$moment,'viewer_id'=>$pair['recipient'],
            'context_id'=>$pair['context'],'authorizing_grant_id'=>$pair['grant_id'],
            'version'=>$version,'created_at'=>now(),
        ]);
    }

    public function publish(Request $r,MomentAudienceService $audience,MediaReferenceService $refs):JsonResponse
    {
        [$owner]=$this->actor($r);$key=$this->idem($r);
        $d=$r->validate([
            'client_id'=>['required','uuid'],'media_id'=>['required','uuid'],
            'audience_kind'=>['required','in:only_me,accepted_friends,selected_friends,selected_circles'],
            'target_ids'=>['present','array','max:100'],'target_ids.*'=>['uuid','distinct'],
            'caption_ciphertext'=>['nullable','string','max:8192'],
            'caption_key_version'=>['nullable','string','max:40'],
            'location_consent'=>['sometimes','boolean'],
            'place_ciphertext'=>['nullable','string','max:8192'],
            'place_key_version'=>['nullable','string','max:40'],
            'tagged_user_ids'=>['sometimes','array','max:20'],
            'tagged_user_ids.*'=>['required','uuid','distinct'],
        ]);
        abort_if(!empty($d['caption_ciphertext'])&&empty($d['caption_key_version']),422);
        abort_if(!empty($d['place_ciphertext'])
            && (empty($d['place_key_version']) || empty($d['location_consent'])),422,
            'Explicit location consent and encrypted place are required.');
        $digest=hash('sha256',json_encode($d,JSON_THROW_ON_ERROR));
        return DB::transaction(function()use($r,$d,$key,$digest,$owner,$audience,$refs):JsonResponse{
            DB::table('orbit_users')->where('id',$owner)->lockForUpdate()->first();
            $old=DB::table('moment_idempotency')->where('owner_id',$owner)->where('request_key_hash',$key)->first();
            if($old){
                abort_unless(hash_equals($old->payload_digest,$digest),409,'Idempotency conflict.');
                return $this->reply(['moment_id'=>$old->moment_id,'published'=>true,
                    'audience_keys_confirmed'=>true,
                    'audience_revision'=>(int)(DB::table('moments')
                        ->where('id',$old->moment_id)->value('audience_revision')??1)],200);
            }
            $media=DB::table('media_objects')->where('id',$d['media_id'])->lockForUpdate()->first();
            abort_unless($media&&$media->owner_user_id===$owner,404,'Media unavailable.');
            abort_if(DB::table('media_references')->where('media_id',$media->id)->whereNull('released_at')->exists(),409,
                'Media is already attached to another consumer.');
            abort_if(DB::table('moments')->where('creator_id',$owner)->where('client_id',$d['client_id'])->exists(),409,'Client publish conflict.');
            $pairs=$audience->resolve($owner,$media->id,$d['audience_kind'],$d['target_ids']);
            $tags=$d['tagged_user_ids']??[];
            if($tags){
                $eligible=array_column($pairs,'recipient');
                $social=app(SocialGraphService::class);
                foreach($tags as $tag){
                    abort_unless(in_array($tag,$eligible,true)
                        && $tag!==$owner && $social->isFriend($owner,$tag)
                        && !$social->isBlockedEitherWay($owner,$tag),422,
                        'Tagged person is not eligible for this Moment.');
                }
            }
            $id=(string)Str::uuid7();$when=now();
            DB::table('moments')->insert([
                'id'=>$id,'creator_id'=>$owner,'client_id'=>$d['client_id'],
                'request_key_hash'=>$key,'request_payload_hash'=>$digest,'state'=>'published',
                'audience_kind'=>$d['audience_kind'],'caption_ciphertext'=>$d['caption_ciphertext']??null,
                'place_ciphertext'=>$d['place_ciphertext']??null,
                'place_key_version'=>$d['place_key_version']??null,
                'location_consent'=>(bool)($d['location_consent']??false),
                'caption_key_version'=>$d['caption_key_version']??null,'published_at'=>$when,
                'rail_expires_at'=>$when->copy()->addHours((int)config('orbit_moments.rail_hours',24)),
                'view_expires_at'=>$when->copy()->addDays((int)config('orbit_moments.view_days',7)),
                'purge_due_at'=>$when->copy()->addDays((int)config('orbit_moments.purge_days',30)),
                'created_at'=>$when,'updated_at'=>$when,
            ]);
            DB::table('moment_media')->insert(['moment_id'=>$id,'media_id'=>$media->id,'role'=>'primary','position'=>0,'created_at'=>$when]);
            $refs->attach($media->id,$owner,'moment',$id);
            $this->pairsWrite($id,$pairs,$d['audience_kind'],$d['target_ids'],1);
            foreach($tags as $tag){
                DB::table('moment_people_tags')->insert([
                    'moment_id'=>$id,'tagged_user_id'=>$tag,'created_at'=>$when,
                ]);
            }
            // The M6 worker must never remove media seven days after an old upload
            // when Moment visibility is measured from server publication time.
            DB::table('media_objects')->where('id',$media->id)
                ->update(['retention_deadline'=>$when->copy()->addDays(30),'updated_at'=>$when]);
            DB::table('moment_idempotency')->insert([
                'owner_id'=>$owner,'request_key_hash'=>$key,'payload_digest'=>$digest,'moment_id'=>$id,'created_at'=>$when,
            ]);
            foreach(['view_expiry'=>(int)config('orbit_moments.view_days',7),
                    'purge'=>(int)config('orbit_moments.purge_days',30)] as $job=>$days) {
                DB::table('moment_lifecycle_jobs')->insert([
                    'id'=>(string)Str::uuid7(),'moment_id'=>$id,'job_type'=>$job,
                    'due_at'=>$when->copy()->addDays($days),'state'=>'pending','attempts'=>0,
                    'created_at'=>$when,'updated_at'=>$when,
                ]);
            }
            $this->emit($id,'moment.published.v1',1,$digest);
            return $this->reply(['moment_id'=>$id,'published'=>true,
                'audience_keys_confirmed'=>true,'audience_revision'=>1,
                'rail_expires_at'=>$when->copy()->addHours(24)->toIso8601String(),
                'view_expires_at'=>$when->copy()->addDays(7)->toIso8601String()],201);
        },3);
    }

    public function receipt(Request $r,string $client):JsonResponse
    {
        [$owner]=$this->actor($r);
        abort_unless(Str::isUuid($client),404,'Publication not found.');
        $moment=DB::table('moments')->where('creator_id',$owner)
            ->where('client_id',$client)->first();
        abort_unless($moment,404,'Publication not found.');
        // The explicit owner-only lookup resolves an ambiguous network timeout
        // without publishing twice or accepting a client-generated success.
        return $this->reply([
            'moment_id'=>$moment->id,'published'=>$moment->state==='published',
            'state'=>$moment->state,
            'audience_keys_confirmed'=>$moment->state==='published',
            'audience_revision'=>(int)$moment->audience_revision,
        ]);
    }

    public function feed(Request $r,MomentPolicy $policy,?string $circle=null,?string $profile=null):JsonResponse
    {
        [$viewer]=$this->actor($r);
        $d=$r->validate(['before'=>['nullable','date'],'before_id'=>['nullable','uuid'],
            'limit'=>['nullable','integer','min:1','max:30']]);
        $limit=(int)($d['limit']??20);$limit=min($limit,(int)config('orbit_moments.max_feed_page',30));
        $rail=$r->is('api/v1/moments/rail');
        $q=DB::table('moments')->where('state','published')->whereNull('deleted_at')
            ->where('view_expires_at','>',now())
            ->where(function($authorized)use($viewer){
                $authorized->where('creator_id',$viewer)->orWhereExists(function($sub)use($viewer){
                    $sub->selectRaw('1')->from('moment_recipients as visible')
                        ->whereColumn('visible.moment_id','moments.id')
                        ->whereColumn('visible.version','moments.audience_revision')
                        ->where('visible.viewer_id',$viewer)->whereNull('visible.revoked_at');
                });
            });
        if($rail)$q->where('rail_expires_at','>',now());
        if($profile)$q->where('creator_id',$profile);
        if($circle)$q->whereExists(function($sub)use($viewer,$circle){
            $sub->selectRaw('1')->from('moment_recipients as mr')
                ->whereColumn('mr.moment_id','moments.id')->where('mr.viewer_id',$viewer)
                ->where('mr.context_id','circle:'.$circle)->whereNull('mr.revoked_at');
        });
        if(isset($d['before']))$q->where(function($query)use($d){
            $query->where('published_at','<',$d['before']);
            if(isset($d['before_id']))$query->orWhere(fn($same)=>$same
                ->where('published_at',$d['before'])->where('id','<',$d['before_id']));
        });
        $candidates=$q->orderByDesc('published_at')->orderByDesc('id')->limit($limit*8)->get();
        $items=[];$last=null;
        foreach($candidates as $m){
            $last=$m;$context=$policy->context($m,$viewer,$circle?'circle:'.$circle:null);
            if(!$context)continue;
            $items[]=$this->brief($m,$context,$viewer);
            if(count($items)>=$limit)break;
        }
        return $this->reply(['items'=>$items,'next_cursor'=>$last?[
            'before'=>$last->published_at,'before_id'=>$last->id]:null]);
    }
    public function rail(Request $r,MomentPolicy $p):JsonResponse{return $this->feed($r,$p);}
    public function circleFeed(Request $r,string $circle,MomentPolicy $p):JsonResponse{return $this->feed($r,$p,$circle);}
    public function profileFeed(Request $r,string $user,MomentPolicy $p):JsonResponse{return $this->feed($r,$p,null,$user);}

    public function detail(Request $r,string $moment,MomentPolicy $p):JsonResponse
    {
        [$viewer]=$this->actor($r);$m=$this->moment($moment);
        $context=$p->requireContext($m,$viewer,$r->query('context_id'));
        return $this->reply($this->brief($m,$context,$viewer));
    }
    public function manifest(Request $r,string $moment,MomentPolicy $p):JsonResponse
    {
        [$viewer]=$this->actor($r);$m=$this->moment($moment);
        $context=$p->requireContext($m,$viewer,$r->query('context_id'));
        $media=DB::table('moment_media as mm')->join('media_objects as mo','mo.id','=','mm.media_id')
            ->where('mm.moment_id',$moment)->where('mm.role','primary')
            ->first(['mo.id','mo.kind','mo.state','mo.policy_version']);
        abort_unless($media && $media->state==='ready',404,'Encrypted media unavailable.');
        return $this->reply(['moment_id'=>$moment,'context_id'=>$context,'media_id'=>$media->id,
            'kind'=>$media->kind,'policy_version'=>$media->policy_version,
            'variants'=>DB::table('media_variants')->where('media_id',$media->id)
                ->where('state','ready')->pluck('variant')->all(),
            'access_via'=>'/api/v1/media/{media}/access','key_via'=>'/api/v1/media/{media}/key-envelope']);
    }
    public function revisions(Request $r,MomentPolicy $p,SocialGraphService $social):JsonResponse
    {
        [$viewer]=$this->actor($r);
        $rows=DB::table('moments')->where('state','published')->where('view_expires_at','>',now())
            ->orderByDesc('updated_at')->limit(200)->get();
        $authorized=[];
        foreach($rows as $m)if($p->context($m,$viewer)!==null)$authorized[]=$m->id.':'.$m->audience_revision.':'.$m->updated_at;
        return $this->reply(['revision_token'=>hash('sha256',implode('|',$authorized).'|'.$social->currentRevision($viewer)),
            'mode'=>'https_poll','push_confirmed'=>false]);
    }
    public function edit(Request $r,string $moment):JsonResponse
    {
        [$owner]=$this->actor($r);$m=$this->moment($moment);
        abort_unless($m->creator_id===$owner&&$m->state==='published'&&now()->lt($m->view_expires_at),404);
        // Native clients can send the version as a JSON field because the
        // trusted-device transport deliberately limits custom request headers.
        // Both If-Match and body revisions use the same optimistic lock.
        $v=(int)$r->header('If-Match',$r->input('revision',0));
        abort_unless($v===(int)$m->publish_version,409,'Version conflict.');
        $d=$r->validate(['caption_ciphertext'=>['nullable','string','max:8192'],
            'caption_key_version'=>['nullable','string','max:40']]);
        abort_if(!empty($d['caption_ciphertext'])&&empty($d['caption_key_version']),422);
        $new=$v+1;DB::transaction(function()use($moment,$owner,$v,$d,$new,$m):void{
            $count=DB::table('moments')->where('id',$moment)->where('creator_id',$owner)
                ->where('publish_version',$v)->update([
                'caption_ciphertext'=>$d['caption_ciphertext']??null,
                'caption_key_version'=>$d['caption_key_version']??null,
                'publish_version'=>$new,'updated_at'=>now()]);
            abort_unless($count===1,409);
            $this->emit($moment,'moment.edited.v1',(int)$m->audience_revision);
        },3);
        return $this->reply(['publish_version'=>$new]);
    }

}
