<?php
namespace App\Modules\Media\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Media\Services\MediaObjectStore;
use App\Modules\Media\Services\MediaPolicy;
use App\Modules\Social\Services\SocialGraphService;
use App\Modules\Circle\Services\CircleAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MediaController extends Controller
{
    private function actor(Request $r): array
    {
        abort_unless(config('orbit_media.enabled') === true,404,'Media unavailable.');
        $session=$r->attributes->get('orbit_session');
        abort_unless($session,401);
        return [(string)$session->user_id,(string)$session->device_id];
    }
    private function response(array $data,int $status=200): JsonResponse
    {
        return response()->json(['data'=>$data],$status)->header('Cache-Control','private, no-store');
    }
    private function key(Request $r): string
    {
        $k=(string)$r->header('Idempotency-Key','');
        abort_unless(Str::isUuid($k),422,'A UUID Idempotency-Key is required.');
        return hash('sha256',$k);
    }
    /**
     * Quotas are enforced inside a locked-user transaction to serialize
     * competing reservations. Both live objects and in-progress sessions
     * consume budget, including encrypted derivatives.
     */
    private function ensureBudget(string $owner,int $reserve): void
    {
        $active=DB::table('media_upload_sessions')->where('owner_user_id',$owner)
            ->whereIn('state',['initiated','uploading'])
            ->where('expires_at','>',now());
        abort_if((clone $active)->count() >= (int)config('orbit_media.max_concurrent_uploads'),
            429,'Too many active uploads.');
        abort_if(DB::table('media_upload_sessions')->where('owner_user_id',$owner)
            ->where('created_at','>=',now()->subDay())->count()
            >= (int)config('orbit_media.daily_upload_limit'),429,'Daily upload quota reached.');
        $originals=(int)DB::table('media_objects')->where('owner_user_id',$owner)
            ->whereIn('state',['ready','verifying','deleting'])->sum('cipher_bytes');
        $variants=(int)DB::table('media_variants as v')
            ->join('media_objects as m','m.id','=','v.media_id')
            ->where('m.owner_user_id',$owner)
            ->whereIn('v.state',['ready','verifying'])->sum('v.cipher_bytes');
        $reserved=(int)(clone $active)->sum('declared_bytes');
        abort_if($originals+$variants+$reserved+$reserve>
            (int)config('orbit_media.per_owner_cipher_quota_bytes'),
            413,'Private storage quota exceeded.');
    }

    private function media(string $media): object
    {
        return DB::table('media_objects')->where('id',$media)->first()
            ?? abort(404,'Media unavailable.');
    }
    private function upload(Request $r,string $id): object
    {
        [$user,$device]=$this->actor($r);
        return DB::table('media_upload_sessions')->where('id',$id)
            ->where('owner_user_id',$user)->where('device_id',$device)->first()
            ?? abort(404,'Upload unavailable.');
    }
    private function uploadObjectKey(object $u): string
    {
        if($u->variant_id) {
            return DB::table('media_variants')->where('id',$u->variant_id)
                ->where('media_id',$u->media_id)->value('object_key')
                ?? abort(404,'Encrypted variant unavailable.');
        }
        return $this->media($u->media_id)->object_key;
    }
    public function initiateVariant(Request $r,string $media,MediaObjectStore $store): JsonResponse
    {
        [$actor,$device]=$this->actor($r);
        $idempotency=$this->key($r);
        $m=$this->media($media);
        abort_unless($m->owner_user_id===$actor && $m->state==='ready'
            && $m->deleted_at===null,404,'Media unavailable.');
        $d=$r->validate([
            'variant'=>['required','in:thumbnail,poster,waveform,transcoded'],
            'declared_bytes'=>['required','integer','min:1'],
            'cipher_sha256'=>['required','regex:/^[a-f0-9]{64}$/D'],
        ]);
        abort_if((int)$d['declared_bytes']>(int)config('orbit_media.limits.'.$m->kind)+262144,
            413,'Encrypted variant too large.');
        $digest=hash('sha256',json_encode(['parent'=>$media,...$d],JSON_THROW_ON_ERROR));
        $old=DB::table('media_upload_sessions')->where('owner_user_id',$actor)
            ->where('idempotency_key_hash',$idempotency)->first();
        if($old){
            abort_unless($old->device_id===$device && hash_equals($old->payload_hash,$digest),409,'Idempotency conflict.');
            return $this->response(['upload_id'=>$old->id,'media_id'=>$media,
                'variant'=>$d['variant'],'state'=>$old->state,
                'expires_at'=>$old->expires_at,'part_size_bytes'=>$store->partBytes(),
                'storage_mode'=>$store->mode()],201);
        }
        abort_if(DB::table('media_variants')->where('media_id',$media)
            ->where('variant',$d['variant'])->exists(),409,'Variant already exists.');
        $upload=(string)Str::uuid7();$variantId=(string)Str::uuid7();
        $key=config('orbit_media.path_prefix').'/'.Str::random(28).'/'.$media.'/'.$d['variant'];
        $provider=$store->initiate($key);
        try{
            DB::transaction(function()use($m,$media,$actor,$device,$upload,$variantId,$key,$provider,$d,$digest,$idempotency):void{
                DB::table('orbit_users')->where('id',$actor)->lockForUpdate()->first();
                $this->ensureBudget($actor,(int)$d['declared_bytes']);
                DB::table('media_variants')->insert([
                    'id'=>$variantId,'media_id'=>$media,'variant'=>$d['variant'],
                    'object_key'=>$key,'cipher_bytes'=>$d['declared_bytes'],
                    'cipher_sha256'=>$d['cipher_sha256'],'state'=>'initiated',
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
                DB::table('media_upload_sessions')->insert([
                    'id'=>$upload,'media_id'=>$media,'variant_id'=>$variantId,
                    'owner_user_id'=>$actor,'device_id'=>$device,
                    'idempotency_key_hash'=>$idempotency,'payload_hash'=>$digest,
                    'provider_upload_id_encrypted'=>Crypt::encryptString($provider),
                    'declared_bytes'=>$d['declared_bytes'],'state'=>'initiated',
                    'expires_at'=>now()->addHours((int)config('orbit_media.upload_session_hours')),
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            },3);
        }catch(\Throwable $e){
            try{$store->abort($key,$provider);}catch(\Throwable){}
            throw $e;
        }
        return $this->response(['upload_id'=>$upload,'media_id'=>$media,
            'variant'=>$d['variant'],'expires_at'=>now()->addHours((int)config('orbit_media.upload_session_hours'))->toIso8601String(),
            'part_size_bytes'=>config('orbit_media.part_size_bytes'),
            'max_bytes'=>$d['declared_bytes'],'storage_mode'=>'multipart'],201);
    }

    private function activeUpload(object $u): void
    {
        abort_if(now()->gte($u->expires_at),410,'Upload expired.');
        abort_unless(in_array($u->state,['initiated','uploading'],true),409,'Upload is not writable.');
    }
    private function providerId(object $u): string
    {
        abort_unless($u->provider_upload_id_encrypted,503,'Private upload unavailable.');
        return Crypt::decryptString($u->provider_upload_id_encrypted);
    }
    private function event(string $id,string $type,int $version=1): void
    {
        DB::table('media_event_outbox')->insert([
            'id'=>(string)Str::uuid7(),'media_id'=>$id,
            'event_type'=>$type,'policy_version'=>$version,'created_at'=>now(),
        ]);
    }

    public function initiate(Request $r,MediaObjectStore $store): JsonResponse
    {
        [$actor,$device]=$this->actor($r);
        $key=$this->key($r);
        $data=$r->validate([
            'purpose'=>['required','in:avatar,moment,message,sos'],
            'kind'=>['required','in:image,video,audio'],
            'declared_mime'=>['required','string','max:100'],
            'declared_bytes'=>['required','integer','min:1'],
            'declared_width'=>['nullable','integer','min:1','max:20000'],
            'declared_height'=>['nullable','integer','min:1','max:20000'],
            'declared_duration_ms'=>['nullable','integer','min:0'],
            'cipher_suite'=>['required','in:xchacha20poly1305-v1,aes256gcm-v1'],
        ]);
        $allow=['image'=>['image/jpeg','image/png','image/webp'],
            'video'=>['video/mp4'],'audio'=>['audio/mp4','audio/aac','audio/ogg']];
        abort_unless(in_array($data['declared_mime'],$allow[$data['kind']],true),415,'Unsupported media type.');
        abort_if((int)$data['declared_bytes']>(int)config('orbit_media.limits.'.$data['kind'])+262144,413,'Media too large.');
        if ($data['kind']==='video') {
            abort_if((int)($data['declared_duration_ms']??0)<1 ||
                (int)$data['declared_duration_ms']>(int)config('orbit_media.max_video_duration_ms'),422,'Video duration invalid.');
        }
        $digest=hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
        $old=DB::table('media_upload_sessions')->where('owner_user_id',$actor)
            ->where('idempotency_key_hash',$key)->first();
        if($old) {
            abort_unless(hash_equals($old->payload_hash,$digest),409,'Idempotency key used for another request.');
            abort_unless($old->device_id===$device,409,'Upload session belongs to another device.');
            return $this->response(['upload_id'=>$old->id,'media_id'=>$old->media_id,
                'state'=>$old->state,'expires_at'=>$old->expires_at,
                'part_size_bytes'=>config('orbit_media.part_size_bytes'),
                'storage_mode'=>'multipart'],201);
        }
        $media=(string)Str::uuid7(); $upload=(string)Str::uuid7();
        $objectKey=config('orbit_media.path_prefix').'/'.Str::random(28).'/'.$media;
        // Fail closed before DB insert when R2 credentials/SDK are absent.
        $providerId=$store->initiate($objectKey);
        try {
            DB::transaction(function()use($actor,$device,$data,$key,$digest,$media,$upload,$objectKey,$providerId):void{
                DB::table('orbit_users')->where('id',$actor)->lockForUpdate()->first();
                $this->ensureBudget($actor,(int)$data['declared_bytes']);
                DB::table('media_objects')->insert([
                    'id'=>$media,'owner_user_id'=>$actor,'purpose'=>$data['purpose'],
                    'kind'=>$data['kind'],'declared_mime'=>$data['declared_mime'],
                    'declared_width'=>$data['declared_width']??null,
                    'declared_height'=>$data['declared_height']??null,
                    'declared_duration_ms'=>$data['declared_duration_ms']??null,
                    'cipher_suite'=>$data['cipher_suite'],'object_key'=>$objectKey,
                    'state'=>'initiated','created_at'=>now(),'updated_at'=>now(),
                ]);
                DB::table('media_upload_sessions')->insert([
                    'id'=>$upload,'media_id'=>$media,'owner_user_id'=>$actor,
                    'device_id'=>$device,'idempotency_key_hash'=>$key,
                    'payload_hash'=>$digest,
                    'provider_upload_id_encrypted'=>Crypt::encryptString($providerId),
                    'declared_bytes'=>$data['declared_bytes'],'state'=>'initiated',
                    'expires_at'=>now()->addHours((int)config('orbit_media.upload_session_hours')),
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            },3);
        } catch (\Throwable $e) {
            try { $store->abort($objectKey,$providerId); } catch (\Throwable) {}
            throw $e;
        }
        return $this->response(['upload_id'=>$upload,'media_id'=>$media,
            'expires_at'=>now()->addHours((int)config('orbit_media.upload_session_hours'))->toIso8601String(),
            'part_size_bytes'=>config('orbit_media.part_size_bytes'),
            'max_bytes'=>$data['declared_bytes'],'storage_mode'=>'multipart'],201);
    }

    public function uploadStatus(Request $r,string $upload,MediaObjectStore $store): JsonResponse
    {
        $u=$this->upload($r,$upload);
        $parts=in_array($u->state,['initiated','uploading'],true)
            && now()->lt($u->expires_at)
            ? $store->uploadedParts($this->uploadObjectKey($u),$this->providerId($u))
            : [];
        return $this->response(['upload_id'=>$u->id,'state'=>$u->state,
            'expires_at'=>$u->expires_at,'part_size_bytes'=>config('orbit_media.part_size_bytes'),
            'uploaded_parts'=>$parts]);
    }
    public function part(Request $r,string $upload,MediaObjectStore $store): JsonResponse
    {
        $u=$this->upload($r,$upload);$this->activeUpload($u);
        $d=$r->validate([
            'part_number'=>['required','integer','min:1','max:10000'],
            'checksum_sha256'=>['required','regex:/^[a-f0-9]{64}$/D'],
            'content_length'=>['required','integer','min:1'],
        ]);
        $max=$store->partBytes();
        abort_if($d['content_length']>$max,413,'Part too large.');
        abort_if(($d['part_number']-1)*$max >= $u->declared_bytes,422,'Part out of range.');
        abort_unless($store->mode()==='multipart',409,'Use local authenticated media chunks.');
        $res=$store->signPart($this->uploadObjectKey($u),
            $this->providerId($u),(int)$d['part_number'],$d['checksum_sha256'],
            (int)$d['content_length']);
        DB::table('media_upload_sessions')->where('id',$u->id)->where('state','initiated')
            ->update(['state'=>'uploading','updated_at'=>now()]);
        return $this->response($res);
    }


    /** Binary ciphertext only; never parse, decode or inspect plaintext. */
    public function localChunk(Request $r,string $upload,int $part,MediaObjectStore $store): JsonResponse
    {
        abort_unless($store->mode()==='local_chunked',404);
        $u=$this->upload($r,$upload);
        $this->activeUpload($u);
        $chunkMax=$store->partBytes();
        abort_unless($part>=1 && $part<=10000
            && ($part-1)*$chunkMax < (int)$u->declared_bytes,422,'Part out of range.');
        abort_unless($r->header('Content-Type')==='application/octet-stream',415,
            'Encrypted binary content required.');
        $body=$r->getContent();
        abort_unless(is_string($body) && strlen($body)>0
            && strlen($body)<=$chunkMax,413,'Invalid chunk size.');
        $expected=min($chunkMax,(int)$u->declared_bytes-($part-1)*$chunkMax);
        abort_unless(strlen($body)===$expected,422,'Ciphertext chunk length mismatch.');
        $checksum=strtolower((string)$r->header('X-Orbit-Cipher-SHA256',''));
        abort_unless(preg_match('/^[0-9a-f]{64}$/D',$checksum)===1,422);
        $etag=$store->writePart($this->uploadObjectKey($u),
            $this->providerId($u),$part,$body,$checksum);
        DB::table('media_upload_sessions')->where('id',$u->id)
            ->where('state','initiated')->update(['state'=>'uploading','updated_at'=>now()]);
        return $this->response(['part_number'=>$part,'etag'=>$etag],201);
    }

    public function complete(Request $r,string $upload,MediaObjectStore $store): JsonResponse
    {
        $this->key($r);
        $u=$this->upload($r,$upload);
        $d=$r->validate([
            'total_cipher_bytes'=>['required','integer','min:1'],
            'cipher_sha256'=>['required','regex:/^[a-f0-9]{64}$/D'],
            'parts'=>['required','array','min:1','max:10000'],
            'parts.*.part_number'=>['required','integer','min:1','max:10000'],
            'parts.*.etag'=>['required','string','max:200'],
        ]);
        $digest=hash('sha256',json_encode($d,JSON_THROW_ON_ERROR));
        if(in_array($u->state,['verifying','ready'],true)) {
            abort_unless($u->complete_payload_hash!==null &&
                hash_equals($u->complete_payload_hash,$digest) &&
                hash_equals($u->complete_key_hash,$this->key($r)),409,'Finalize request conflicts.');
            return $this->response(['media_id'=>$u->media_id,'state'=>$u->state],202);
        }
        $this->activeUpload($u);
        abort_unless((int)$u->declared_bytes === (int)$d['total_cipher_bytes'],422,'Size differs from reservation.');
        $parts=$d['parts'];$seen=[];
        foreach($parts as $i=>$part) {
            abort_unless($part['part_number']===$i+1,422,'Parts must be consecutive.');
            abort_if(isset($seen[$part['part_number']]),422,'Duplicate part.');
            $seen[$part['part_number']]=true;
        }
        $object=$this->media($u->media_id);
        $key=$this->uploadObjectKey($u);
        $onServer=$store->uploadedParts($key,$this->providerId($u));
        abort_unless(count($onServer)===count($parts),409,'Uploaded part count mismatch.');
        $total=0;$max=$store->partBytes();
        foreach($onServer as $i=>$p) {
            abort_unless($p['part_number']===$parts[$i]['part_number'] &&
                trim($p['etag'],'"')===trim($parts[$i]['etag'],'"'),409,'Part integrity mismatch.');
            $total += (int)$p['bytes'];
            if($i<count($parts)-1) abort_unless((int)$p['bytes']===$max,422,'Incomplete non-final part.');
        }
        abort_unless($total===(int)$d['total_cipher_bytes'],422,'Ciphertext length mismatch.');
        $store->finish($key,$this->providerId($u),$onServer);
        DB::transaction(function()use($u,$d,$digest,$r):void{
            DB::table('media_upload_sessions')->where('id',$u->id)
                ->whereIn('state',['initiated','uploading'])->update([
                    'state'=>'verifying','actual_cipher_bytes'=>$d['total_cipher_bytes'],
                    'final_cipher_sha256'=>$d['cipher_sha256'],
                    'complete_key_hash'=>$this->key($r),'complete_payload_hash'=>$digest,
                    'completed_at'=>now(),'updated_at'=>now(),
                ]);
            if($u->variant_id) {
                DB::table('media_variants')->where('id',$u->variant_id)->update([
                    'state'=>'verifying','cipher_bytes'=>$d['total_cipher_bytes'],
                    'cipher_sha256'=>$d['cipher_sha256'],'updated_at'=>now(),
                ]);
            } else {
                DB::table('media_objects')->where('id',$u->media_id)->update([
                    'state'=>'verifying','cipher_bytes'=>$d['total_cipher_bytes'],
                    'cipher_sha256'=>$d['cipher_sha256'],'updated_at'=>now(),
                ]);
            }
        });
        // Verification worker streams private ciphertext; never mark ready before hash proof.
        return $this->response(['media_id'=>$u->media_id,'state'=>'verifying'],202);
    }
    public function abort(Request $r,string $upload,MediaObjectStore $store)
    {
        $u=$this->upload($r,$upload);
        abort_if(in_array($u->state,['verifying','ready'],true),409,'Upload already finalized.');
        if(!in_array($u->state,['aborted','expired'],true)) {
            try { $store->abort($this->uploadObjectKey($u),$this->providerId($u)); }
            catch(\Throwable $e) { abort(503,'Unable to abort private storage upload.'); }
            DB::transaction(function()use($u):void {
                DB::table('media_upload_sessions')->where('id',$u->id)->update(['state'=>'aborted','updated_at'=>now()]);
                if($u->variant_id) {
                    DB::table('media_variants')->where('id',$u->variant_id)
                        ->update(['state'=>'aborted','updated_at'=>now()]);
                } else {
                    DB::table('media_objects')->where('id',$u->media_id)
                        ->update(['state'=>'aborted','deleted_at'=>now(),'updated_at'=>now()]);
                }
            });
        }
        return response()->noContent();
    }
    public function detail(Request $r,string $media,MediaPolicy $policy): JsonResponse
    {
        [$actor]=$this->actor($r);
        $m=$this->media($media);$policy->requireRead($m,$actor);
        $variants=DB::table('media_variants')->where('media_id',$media)
            ->where('state','ready')->pluck('variant')->all();
        return $this->response(['media_id'=>$m->id,'purpose'=>$m->purpose,
            'kind'=>$m->kind,'declared_mime'=>$m->declared_mime,
            'declared_width'=>$m->declared_width,'declared_height'=>$m->declared_height,
            'declared_duration_ms'=>$m->declared_duration_ms,
            'cipher_sha256'=>$m->cipher_sha256,'cipher_bytes'=>$m->cipher_bytes,
            'variants'=>$variants,'state'=>$m->state,'ready_at'=>$m->ready_at]);
    }
    public function access(Request $r,string $media,MediaPolicy $policy,MediaObjectStore $store): JsonResponse
    {
        [$actor,$device]=$this->actor($r);
        $d=$r->validate(['variant'=>['required','in:original,thumbnail,poster,waveform,transcoded'],
            'device_id'=>['required','uuid']]);
        abort_unless($d['device_id']===$device,404,'Media unavailable.');
        $m=$this->media($media);$policy->requireRead($m,$actor);
        $variant=$d['variant'];
        $row=$variant==='original'?$m:DB::table('media_variants')->where('media_id',$media)
            ->where('variant',$variant)->where('state','ready')->first();
        abort_unless($row,404,'Media unavailable.');
        // Fail closed unless the reading device has an active encrypted key envelope.
        $env=DB::table('media_device_key_envelopes as e')
            ->join('media_audience_grants as g','g.id','=','e.grant_id')
            ->where('e.media_id',$media)->where('e.recipient_device_id',$device)
            ->where('e.variant',$variant)->whereNull('e.revoked_at')
            ->whereNull('g.revoked_at')
            ->where(fn($q)=>$q->whereNull('g.expires_at')->orWhere('g.expires_at','>',now()))
            ->first(['e.id']);
        abort_unless($env,404,'Media unavailable.');
        if($store->mode()==='local_chunked') {
            return $this->response([
                'download_mode'=>'authenticated',
                'download_path'=>'/media/'.rawurlencode($media).'/ciphertext?variant='.rawurlencode($variant),
                'sha256_ciphertext'=>$row->cipher_sha256,
                'envelope_ref'=>$env->id,
            ]);
        }
        return $this->response(['ciphertext_url'=>$store->download($row->object_key),
            'expires_at'=>now()->addSeconds((int)config('orbit_media.url_ttl_seconds'))->toIso8601String(),
            'sha256_ciphertext'=>$row->cipher_sha256,'envelope_ref'=>$env->id]);
    }

    public function localCiphertext(Request $r,string $media,MediaPolicy $policy,
        MediaObjectStore $store): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        abort_unless($store->mode()==='local_chunked',404);
        [$actor,$device]=$this->actor($r);
        $d=$r->validate(['variant'=>['required','in:original,thumbnail,poster,waveform,transcoded']]);
        $m=$this->media($media);
        $policy->requireRead($m,$actor);
        $exists=DB::table('media_device_key_envelopes as e')
            ->join('media_audience_grants as g','g.id','=','e.grant_id')
            ->where('e.media_id',$media)->where('e.recipient_device_id',$device)
            ->where('e.variant',$d['variant'])->whereNull('e.revoked_at')
            ->whereNull('g.revoked_at')
            ->where(fn($q)=>$q->whereNull('g.expires_at')->orWhere('g.expires_at','>',now()))
            ->exists();
        abort_unless($exists,404,'Media unavailable.');
        $row=$d['variant']==='original'?$m:DB::table('media_variants')
            ->where('media_id',$media)->where('variant',$d['variant'])
            ->where('state','ready')->first();
        abort_unless($row,404,'Media unavailable.');
        return response()->download($store->localPath($row->object_key),
            'orbit-ciphertext.bin',[
                'Content-Type'=>'application/octet-stream',
                'Cache-Control'=>'private, no-store, no-cache, must-revalidate',
                'Pragma'=>'no-cache',
                'X-Content-Type-Options'=>'nosniff',
            ]);
    }

    public function keyEnvelope(Request $r,string $media,MediaPolicy $policy): JsonResponse
    {
        [$actor,$device]=$this->actor($r);
        $d=$r->validate(['device_id'=>['required','uuid'],
            'variant'=>['required','in:original,thumbnail,poster,waveform,transcoded']]);
        abort_unless($d['device_id']===$device,404,'Media unavailable.');
        $policy->requireRead($this->media($media),$actor);
        $e=DB::table('media_device_key_envelopes as e')
            ->join('media_audience_grants as g','g.id','=','e.grant_id')
            ->where('e.media_id',$media)->where('e.recipient_device_id',$device)
            ->where('e.variant',$d['variant'])->whereNull('e.revoked_at')
            ->whereNull('g.revoked_at')
            ->where(fn($q)=>$q->whereNull('g.expires_at')->orWhere('g.expires_at','>',now()))
            ->first(['e.wrapped_key_ciphertext','e.key_version','e.algorithm','e.grant_id']);
        abort_unless($e,404,'Media unavailable.');
        return $this->response((array)$e);
    }
    public function grant(Request $r,string $media,MediaPolicy $policy,SocialGraphService $social,
        CircleAccessPolicy $circles): JsonResponse
    {
        [$actor,$device]=$this->actor($r);$key=$this->key($r);
        $m=$this->media($media);
        abort_unless($m->owner_user_id===$actor && $m->state==='ready' && $m->deleted_at===null,
            404,'Media unavailable.');
        $d=$r->validate([
            'audience_kind'=>['required','in:only_me,accepted_friends,selected_friends,selected_circles'],
            'target_ids'=>['present','array','max:100'],
            'target_ids.*'=>['uuid'],
            'expires_at'=>['nullable','date','after:now'],
            'key_envelopes'=>['required','array','min:1','max:300'],
            'key_envelopes.*.device_id'=>['required','uuid'],
            'key_envelopes.*.variant'=>['required','in:original,thumbnail,poster,waveform,transcoded'],
            'key_envelopes.*.key_version'=>['required','string','max:40'],
            'key_envelopes.*.algorithm'=>['required','string','max:80'],
            'key_envelopes.*.wrapped_key_ciphertext'=>['required','string','min:32','max:8192'],
        ]);
        $targets=array_unique($d['target_ids']);$kind=$d['audience_kind'];
        $recipients=[];
        if($kind==='only_me') {
            abort_unless(count($targets)===0,422,'Only Me does not accept targets.');
            $recipients[$actor]=null;
        } elseif($kind==='accepted_friends') {
            abort_unless(count($targets)===0,422,'Accepted friends audience is not a manual list.');
            $all=DB::table('friendships')->where('user_low_id',$actor)->orWhere('user_high_id',$actor)
                ->get(['user_low_id','user_high_id']);
            foreach($all as $f) {
                $id=$f->user_low_id===$actor?$f->user_high_id:$f->user_low_id;
                if($policy->recipientEligible($actor,$id,$kind,null))$recipients[$id]=null;
            }
        } elseif($kind==='selected_friends') {
            abort_unless(count($targets)>0,422,'Select recipients.');
            foreach($targets as $id) {
                abort_unless($policy->recipientEligible($actor,$id,$kind,null),422,'Recipient unavailable.');
                $recipients[$id]=null;
            }
        } else {
            abort_unless(count($targets)>0,422,'Select Circles.');
            foreach($targets as $circleId) {
                abort_unless($circles->isMember($circleId,$actor),404,'Circle unavailable.');
                $members=DB::table('circle_members')->where('circle_id',$circleId)->whereNull('removed_at')
                    ->pluck('user_id');
                foreach($members as $id) {
                    if($policy->recipientEligible($actor,$id,$kind,$circleId))
                        $recipients[$id]=$circleId;
                }
            }
        }
        abort_if(count($recipients)>100,413,'Too many recipients.');
        abort_unless(count($recipients)>0,422,'No eligible recipients.');
        $envelopes=[];
        foreach($d['key_envelopes'] as $e) {
            $owner=DB::table('devices')->where('id',$e['device_id'])
                ->whereNotNull('approved_at')->whereNull('revoked_at')
                ->where('trust_state','trusted')->value('user_id');
            abort_unless($owner && array_key_exists($owner,$recipients),422,'Device not eligible.');
            $envelopes[$owner][]=$e;
        }
        foreach(array_keys($recipients) as $id) abort_unless(isset($envelopes[$id]),422,'Recipient key envelope missing.');
        $digest=hash('sha256',json_encode($d,JSON_THROW_ON_ERROR));
        $old=DB::table('media_audience_grants')->where('media_id',$media)
            ->where('issuer_user_id',$actor)->where('request_key_hash',$key)->get();
        if($old->count()>0) {
            abort_unless($old->every(fn($g)=>hash_equals($g->request_payload_hash,$digest)),409,'Idempotency conflict.');
            return $this->response(['grant_ids'=>$old->pluck('id')->all(),
                'policy_version'=>$m->policy_version],201);
        }
        $grantIds=DB::transaction(function()use($media,$actor,$m,$recipients,$kind,$envelopes,$key,$digest,$d):array {
            $ids=[];$newVersion=$m->policy_version+1;
            foreach($recipients as $id=>$circle) {
                $g=(string)Str::uuid7();$ids[]=$g;
                DB::table('media_audience_grants')->insert([
                    'id'=>$g,'media_id'=>$media,'issuer_user_id'=>$actor,
                    'recipient_user_id'=>$id,'circle_id'=>$circle,
                    'audience_kind'=>$kind,'policy_version'=>$newVersion,
                    'request_key_hash'=>$key,'request_payload_hash'=>$digest,
                    'expires_at'=>$d['expires_at']??null,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
                foreach($envelopes[$id] as $e) {
                    DB::table('media_device_key_envelopes')->insert([
                        'id'=>(string)Str::uuid7(),'media_id'=>$media,'grant_id'=>$g,
                        'recipient_device_id'=>$e['device_id'],'variant'=>$e['variant'],
                        'key_version'=>$e['key_version'],'algorithm'=>$e['algorithm'],
                        'wrapped_key_ciphertext'=>$e['wrapped_key_ciphertext'],
                        'created_at'=>now(),'updated_at'=>now(),
                    ]);
                }
            }
            DB::table('media_objects')->where('id',$media)->update(['policy_version'=>$newVersion,'updated_at'=>now()]);
            $this->event($media,'media.grants.updated.v1',$newVersion);
            return $ids;
        },3);
        return $this->response(['grant_ids'=>$grantIds,'policy_version'=>$m->policy_version+1],201);
    }
    public function revoke(Request $r,string $media,string $grant): \Illuminate\Http\Response
    {
        [$actor]=$this->actor($r);
        $m=$this->media($media);
        abort_unless($m->owner_user_id===$actor,404,'Media unavailable.');
        DB::transaction(function()use($media,$grant,$actor):void {
            $g=DB::table('media_audience_grants')->where('id',$grant)
                ->where('media_id',$media)->where('issuer_user_id',$actor)->lockForUpdate()->first();
            abort_unless($g,404,'Grant unavailable.');
            if($g->revoked_at!==null)return;
            DB::table('media_audience_grants')->where('id',$grant)->update(['revoked_at'=>now(),'updated_at'=>now()]);
            DB::table('media_device_key_envelopes')->where('grant_id',$grant)->update(['revoked_at'=>now(),'updated_at'=>now()]);
            DB::table('media_objects')->where('id',$media)->increment('policy_version');
            $this->event($media,'media.grants.revoked.v1',$g->policy_version+1);
        });
        return response()->noContent();
    }
    public function delete(Request $r,string $media): JsonResponse
    {
        [$actor]=$this->actor($r);$this->key($r);
        $m=$this->media($media);
        abort_unless($m->owner_user_id===$actor,404,'Media unavailable.');
        if(!in_array($m->state,['deleting','deleted'],true)) {
            DB::transaction(function()use($media):void {
                DB::table('media_objects')->where('id',$media)->update([
                    'state'=>'deleting','deleted_at'=>now(),'updated_at'=>now(),
                ]);
                DB::table('media_audience_grants')->where('media_id',$media)->update(['revoked_at'=>now(),'updated_at'=>now()]);
                DB::table('media_device_key_envelopes')->where('media_id',$media)->update(['revoked_at'=>now(),'updated_at'=>now()]);
                DB::table('media_delete_jobs')->insertOrIgnore([
                    'id'=>(string)Str::uuid7(),'media_id'=>$media,
                    'reason'=>'owner_request','status'=>'pending',
                    'next_attempt_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
                ]);
                $this->event($media,'media.deleted.v1');
            });
        }
        return $this->response(['state'=>'deleting'],202);
    }
}