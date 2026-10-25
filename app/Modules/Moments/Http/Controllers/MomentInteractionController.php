<?php
namespace App\Modules\Moments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Moments\Services\MomentPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

final class MomentInteractionController extends Controller
{
    private function actor(Request $r):string {
        abort_unless(config('orbit_moments.enabled')===true&&config('orbit_media.enabled')===true,404);
        $s=$r->attributes->get('orbit_session');abort_unless($s,401);return (string)$s->user_id;
    }
    private function reply(array $d,int $status=200):JsonResponse {
        return response()->json(['data'=>$d],$status)->header('Cache-Control','private, no-store');
    }
    private function key(Request $r):string {
        $k=(string)$r->header('Idempotency-Key');abort_unless(Str::isUuid($k),422);
        return hash('sha256',$k);
    }
    private function scope(Request $r,string $moment,MomentPolicy $p):array {
        $viewer=$this->actor($r);
        $m=DB::table('moments')->where('id',$moment)->first()??abort(404);
        return [$viewer,$m,$p->requireContext($m,$viewer,
            $r->input('context_id',$r->query('context_id')))];
    }
    public function view(Request $r,string $moment,MomentPolicy $p):\Illuminate\Http\Response {
        [$user,$m,$context]=$this->scope($r,$moment,$p);
        // M3 read-receipt preference overrides M7 viewer tracking.
        // No stored view or cross-Circle viewer fingerprint without opt-in.
        $raw=DB::table('orbit_user_preferences')->where('user_id',$user)->value('privacy');
        $prefs=is_string($raw)?json_decode($raw,true):null;
        if(!is_array($prefs)||($prefs['read_receipts_enabled']??false)!==true){
            return response()->noContent();
        }
        $where=['moment_id'=>$moment,'viewer_id'=>$user,'context_id'=>$context];
        if(DB::table('moment_views')->where($where)->exists()){
            DB::table('moment_views')->where($where)->update(['last_viewed_at'=>now()]);
        }else{
            DB::table('moment_views')->insert($where+['id'=>(string)Str::uuid7(),
                'first_viewed_at'=>now(),'last_viewed_at'=>now()]);
        }
        return response()->noContent();
    }
    public function viewers(Request $r,string $moment,MomentPolicy $p):JsonResponse {
        $owner=$this->actor($r);$m=DB::table('moments')->where('id',$moment)->first();
        abort_unless($m && $m->creator_id===$owner && $p->active($m),404);
        $rows=DB::table('moment_views')->where('moment_id',$moment)
            ->orderByDesc('last_viewed_at')->limit(100)->get();
        $out=[];
        foreach($rows as $row) {
            if($row->viewer_id===$owner || !$p->context($m,$row->viewer_id,$row->context_id))continue;
            $out[]=['viewer_id'=>$row->viewer_id,'last_viewed_at'=>$row->last_viewed_at];
        }
        return $this->reply(['viewers'=>$out]); // No public viewer totals.
    }
    public function react(Request $r,string $moment,MomentPolicy $p):JsonResponse {
        [$user,$m,$context]=$this->scope($r,$moment,$p);$d=$r->validate(['reaction_code'=>['required','in:like,love,haha,wow,sad,angry']]);
        $where=['moment_id'=>$moment,'user_id'=>$user,'context_id'=>$context];
        if(DB::table('moment_reactions')->where($where)->exists()){
            DB::table('moment_reactions')->where($where)->update([
                'reaction_code'=>$d['reaction_code'],'deleted_at'=>null,'updated_at'=>now()]);
        }else{
            DB::table('moment_reactions')->insert($where+['id'=>(string)Str::uuid7(),
                'reaction_code'=>$d['reaction_code'],'deleted_at'=>null,
                'created_at'=>now(),'updated_at'=>now()]);
        }
        return $this->reply(['my_reaction'=>$d['reaction_code']]);
    }
    public function unreact(Request $r,string $moment,MomentPolicy $p):\Illuminate\Http\Response {
        [$user,$m,$context]=$this->scope($r,$moment,$p);
        DB::table('moment_reactions')->where('moment_id',$moment)->where('user_id',$user)
            ->where('context_id',$context)->update(['deleted_at'=>now(),'updated_at'=>now()]);
        return response()->noContent();
    }
    public function comments(Request $r,string $moment,MomentPolicy $p):JsonResponse {
        [$user,$m,$context]=$this->scope($r,$moment,$p);
        $d=$r->validate(['before'=>['nullable','date'],'before_id'=>['nullable','uuid'],
            'limit'=>['nullable','integer','min:1','max:30']]);
        abort_if(isset($d['before_id'])&&!isset($d['before']),422,'Comment cursor requires timestamp.');
        $q=DB::table('moment_comments')->where('moment_id',$moment)->where('context_id',$context)->whereNull('deleted_at');
        if(isset($d['before']))$q->where(function($cursor)use($d):void{
            $cursor->where('created_at','<',$d['before']);
            if(isset($d['before_id']))$cursor->orWhere(function($tie)use($d):void{
                $tie->where('created_at',$d['before'])->where('id','<',$d['before_id']);
            });
        });
        $rows=$q->orderByDesc('created_at')->orderByDesc('id')->limit($d['limit']??20)->get();
        return $this->reply(['items'=>$rows->map(fn($v)=>[
            'id'=>$v->id,'parent_id'=>$v->parent_id,'author_id'=>$v->author_id,
            'context_id'=>$v->context_id,'body_ciphertext'=>$v->body_ciphertext,
            'key_version'=>$v->key_version,'created_at'=>$v->created_at])->all(),
            'next_before'=>$rows->last()?->created_at,
            'next_before_id'=>$rows->last()?->id]);
    }
    public function comment(Request $r,string $moment,MomentPolicy $p):JsonResponse {
        [$user,$m,$context]=$this->scope($r,$moment,$p);$key=$this->key($r);
        $d=$r->validate(['body_ciphertext'=>['required','string','min:32','max:8192'],
            'key_version'=>['required','string','max:40'],'parent_id'=>['nullable','uuid'],
            'context_id'=>['nullable','string','max:64']]);
        if(isset($d['parent_id']))abort_unless(DB::table('moment_comments')->where('id',$d['parent_id'])
            ->where('moment_id',$moment)->where('context_id',$context)->whereNull('deleted_at')->exists(),404,'Reply unavailable.');
        // One request key per author within a Moment to prevent offline duplicates.
        $id=(string)Str::uuid7();
        $digest=hash('sha256',json_encode($d,JSON_THROW_ON_ERROR));
        $existing=DB::table('moment_comments')->where('moment_id',$moment)->where('author_id',$user)->where('request_key_hash',$key)->first();
        if($existing) {
            abort_unless(hash_equals($existing->request_payload_hash,$digest),409);
            return $this->reply(['comment_id'=>$existing->id],200);
        }
        DB::table('moment_comments')->insert(['id'=>$id,'moment_id'=>$moment,'author_id'=>$user,
            'parent_id'=>$d['parent_id']??null,'context_id'=>$context,'body_ciphertext'=>$d['body_ciphertext'],
            'key_version'=>$d['key_version'],'request_key_hash'=>$key,'request_payload_hash'=>$digest,
            'created_at'=>now(),'updated_at'=>now()]);
        return $this->reply(['comment_id'=>$id],201);
    }
    public function deleteComment(Request $r,string $moment,string $comment,MomentPolicy $p):\Illuminate\Http\Response {
        [$user,$m,$context]=$this->scope($r,$moment,$p);
        $row=DB::table('moment_comments')->where('id',$comment)->where('moment_id',$moment)->where('context_id',$context)->first();
        abort_unless($row && ($row->author_id===$user || $m->creator_id===$user),404);
        DB::table('moment_comments')->where('id',$comment)->update(['deleted_at'=>now(),'updated_at'=>now()]);
        return response()->noContent();
    }
    public function report(Request $r,string $moment,MomentPolicy $p,?string $comment=null):JsonResponse {
        [$user,$m,$context]=$this->scope($r,$moment,$p);$d=$r->validate([
            'reason'=>['required','in:spam,harassment,threat,scam,impersonation,nudity,violence,safety_concern,privacy,other'],
            'report_target'=>['sometimes','in:moment,comment,account'],
            'details_ciphertext'=>['nullable','string','max:8192'],
            'consent_evidence_ciphertext'=>['nullable','string','max:65535'],
            'evidence_preview_base64'=>['nullable','string','max:65535'],
            'evidence_consent'=>['required','boolean'],
        ]);
        abort_if(!empty($d['consent_evidence_ciphertext'])&&!$d['evidence_consent'],422,'Explicit evidence consent required.');
        $volunteeredEvidence=null;
        if(isset($d['evidence_preview_base64'])){
            abort_unless(config('orbit_moments.moderation_evidence_enabled')===true
                && $d['evidence_consent']===true,403,
                'Voluntary evidence review is not enabled.');
            abort_if(isset($d['consent_evidence_ciphertext']),422,'Choose one evidence type.');
            $bytes=base64_decode($d['evidence_preview_base64'],true);
            abort_unless($bytes!==false && strlen($bytes)>=4 && strlen($bytes)<=40960
                && str_starts_with($bytes,"\\xFF\\xD8")
                && str_ends_with($bytes,"\\xFF\\xD9")
                && !str_contains($bytes,"Exif\\0\\0"),422,
                'An already sanitized JPEG preview is required.');
            $volunteeredEvidence=Crypt::encryptString(base64_encode($bytes));
        }
        $target=$d['report_target']??($comment?'comment':'moment');
        if($comment && $target!=='comment')abort(422,'Report target mismatch.');
        if($target==='comment' && !$comment)abort(422,'Comment required.');
        if($target==='account' && $m->creator_id===$user)
            abort(422,'Cannot report own account.');
        if($comment)abort_unless(DB::table('moment_comments')->where('id',$comment)
            ->where('moment_id',$moment)->where('context_id',$context)->exists(),404);
        $key=$this->key($r);
        $digest=hash('sha256',json_encode(['moment_id'=>$moment,'comment_id'=>$comment,
            'report_target'=>$target,
            'context_id'=>$context,'payload'=>$d],JSON_THROW_ON_ERROR));
        return DB::transaction(function()use($user,$moment,$comment,$context,$d,$key,$digest,$target,$m,$volunteeredEvidence):JsonResponse{
            // Serialize same actor's concurrent retries to avoid duplicate user reports.
            DB::table('orbit_users')->where('id',$user)->lockForUpdate()->first();
            $old=DB::table('moment_reports')->where('reporter_id',$user)
                ->where('request_key_hash',$key)->first();
            if($old){
                abort_unless(hash_equals($old->request_payload_hash,$digest),409,'Report retry conflict.');
                return $this->reply(['report_id'=>$old->id,'received'=>true],200);
            }
            $id=(string)Str::uuid7();
            DB::table('moment_reports')->insert(['id'=>$id,'moment_id'=>$moment,'comment_id'=>$comment,
                'reporter_id'=>$user,'context_id'=>$context,'reason'=>$d['reason'],
                'report_target'=>$target,
                'reported_user_id'=>$target==='account'?$m->creator_id:null,
                'details_ciphertext'=>$d['details_ciphertext']??null,
                'consent_evidence_ciphertext'=>$volunteeredEvidence??($d['consent_evidence_ciphertext']??null),
                'evidence_consent'=>$d['evidence_consent'],'request_key_hash'=>$key,
                'request_payload_hash'=>$digest,'status'=>'pending','created_at'=>now(),'updated_at'=>now()]);
            return $this->reply(['report_id'=>$id,'received'=>true],201);
        },3);
    }
}
