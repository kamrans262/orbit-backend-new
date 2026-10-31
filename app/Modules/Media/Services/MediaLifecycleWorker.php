<?php
namespace App\Modules\Media\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Idempotent, non-Firebase private-object lifecycle processor. Call from
 * cron/scheduler, never mark ready solely from a client's completion claim.
 */
final class MediaLifecycleWorker
{
    public function __construct(private readonly MediaObjectStore $store) {}

    private function emit(string $id,string $event): void
    {
        DB::table('media_event_outbox')->insert([
            'id'=>(string)Str::uuid7(),'media_id'=>$id,
            'event_type'=>$event,'created_at'=>now(),
        ]);
    }
    private function stageDelete(string $id,string $reason): void
    {
        DB::transaction(function()use($id,$reason):void {
            $row=DB::table('media_objects')->where('id',$id)->lockForUpdate()->first();
            if(!$row || $row->state==='deleted') return;
            if($row->state!=='deleting') {
                DB::table('media_objects')->where('id',$id)->update([
                    'state'=>'deleting','deleted_at'=>now(),'updated_at'=>now(),
                ]);
                DB::table('media_audience_grants')->where('media_id',$id)
                    ->whereNull('revoked_at')->update(['revoked_at'=>now(),'updated_at'=>now()]);
                DB::table('media_device_key_envelopes')->where('media_id',$id)
                    ->whereNull('revoked_at')->update(['revoked_at'=>now(),'updated_at'=>now()]);
                $this->emit($id,'media.expired.v1');
            }
            DB::table('media_delete_jobs')->insertOrIgnore([
                'id'=>(string)Str::uuid7(),'media_id'=>$id,'reason'=>$reason,
                'status'=>'pending','next_attempt_at'=>now(),
                'created_at'=>now(),'updated_at'=>now(),
            ]);
        });
    }

    /**
     * Bounded synchronous integrity check for small private owner avatars.
     * Hostinger's five-minute scheduler is too slow for an interactive
     * profile-save flow. Do not use this for Moments, large objects or S3.
     * Cryptographic AEAD decryption remains exclusively on the device.
     */
    public function verifySmallAvatar(string $uploadId): bool
    {
        if(config('orbit_media.enabled') !== true
            || $this->store->mode() !== 'local_chunked') return false;
        $u=DB::table('media_upload_sessions')->where('id',$uploadId)
            ->where('state','verifying')->whereNull('variant_id')->first();
        if(!$u || (int)$u->actual_cipher_bytes <= 0
            || (int)$u->actual_cipher_bytes > 1048576) return false;
        $m=DB::table('media_objects')->where('id',$u->media_id)
            ->where('state','verifying')->where('purpose','avatar')
            ->where('kind','image')->whereNull('deleted_at')->first();
        if(!$m || !is_string($u->final_cipher_sha256)
            || !preg_match('/^[0-9a-f]{64}$/D',$u->final_cipher_sha256)) return false;
        // Read/verify the entire private ciphertext before marking it ready.
        try {
            $valid=$this->store->verify($m->object_key,
                (int)$u->actual_cipher_bytes,(string)$u->final_cipher_sha256);
        } catch (\Throwable) {
            // Existing scheduled worker will retry. Never trust a claim.
            return false;
        }
        if(!$valid){
            $this->stageDelete($m->id,'checksum_mismatch');
            DB::table('media_upload_sessions')->where('id',$u->id)
                ->where('state','verifying')->update([
                    'state'=>'failed','updated_at'=>now(),
                ]);
            return false;
        }
        return DB::transaction(function()use($m,$u):bool{
            $updated=DB::table('media_objects')->where('id',$m->id)
                ->where('state','verifying')->update([
                    'state'=>'ready','ready_at'=>now(),'retention_deadline'=>null,
                    'updated_at'=>now(),
                ]);
            if($updated!==1)return false;
            DB::table('media_upload_sessions')->where('id',$u->id)
                ->where('state','verifying')->update([
                    'state'=>'ready','updated_at'=>now(),
                ]);
            $this->emit($m->id,'media.upload.ready.v1');
            return true;
        });
    }

    public function run(int $limit=15): array
    {
        $limit=max(1,min($limit,100));
        $stats=['verified'=>0,'rejected'=>0,'expired_uploads'=>0,'queued_deletions'=>0,'purged'=>0,'errors'=>0];
        // Worker verifies the entire ciphertext stream; HEAD ETag is not a
        // plaintext hash or trustworthy end-to-end receipt for multipart uploads.
        $verify=DB::table('media_upload_sessions')
            ->where('state','verifying')->orderBy('completed_at')->limit($limit)->get();
        foreach($verify as $u) {
            $m=DB::table('media_objects')->where('id',$u->media_id)->first();
            if(!$m)continue;
            $variant=$u->variant_id
                ? DB::table('media_variants')->where('id',$u->variant_id)
                    ->where('media_id',$u->media_id)->first()
                : null;
            if($u->variant_id && (!$variant || $variant->state!=='verifying'))continue;
            if(!$u->variant_id && $m->state!=='verifying')continue;
            try {
                $valid=$this->store->verify($variant?->object_key??$m->object_key,
                    (int)$u->actual_cipher_bytes,(string)$u->final_cipher_sha256);
                if(!$valid) {
                    if($variant) {
                        DB::table('media_variants')->where('id',$variant->id)
                            ->update(['state'=>'failed','updated_at'=>now()]);
                    } else {
                        $this->stageDelete($m->id,'checksum_mismatch');
                    }
                    DB::table('media_upload_sessions')->where('id',$u->id)
                        ->update(['state'=>'failed','updated_at'=>now()]);
                    $stats['rejected']++;
                    continue;
                }
                $deadline=match($m->purpose) {
                    'moment'=>now()->addDays((int)config('orbit_media.moments_purge_days')),
                    'sos'=>now()->addDays((int)config('orbit_media.sos_retention_days')),
                    // Chat attachments are ciphertext-only and expire with
                    // the 30-day remote mailbox. Local E2EE text history is
                    // intentionally independent and stays on the device.
                    'message'=>now()->addDays(app(\App\Modules\Messaging\Services\MessagingAdminControls::class)->retentionDays()),
                    default=>null,
                };
                DB::transaction(function()use($m,$u,$deadline,$variant):void {
                    if($variant){
                        $affected=DB::table('media_variants')->where('id',$variant->id)
                            ->where('state','verifying')->update(['state'=>'ready','updated_at'=>now()]);
                        if($affected!==1)return;
                        DB::table('media_upload_sessions')->where('id',$u->id)
                            ->update(['state'=>'ready','updated_at'=>now()]);
                        $this->emit($m->id,'media.variant.ready.v1');
                        return;
                    }
                    $affected=DB::table('media_objects')->where('id',$m->id)
                        ->where('state','verifying')->update([
                            'state'=>'ready','ready_at'=>now(),'retention_deadline'=>$deadline,
                            'updated_at'=>now(),
                        ]);
                    if($affected!==1)return;
                    DB::table('media_upload_sessions')->where('id',$u->id)
                        ->update(['state'=>'ready','updated_at'=>now()]);
                    $this->emit($m->id,'media.upload.ready.v1');
                });
                $stats['verified']++;
            } catch(\Throwable) {
                $stats['errors']++;
            }
        }
        $expired=DB::table('media_upload_sessions')
            ->whereIn('state',['initiated','uploading'])
            ->where('expires_at','<=',now())->limit($limit)->get();
        foreach($expired as $u) {
            try {
                $m=DB::table('media_objects')->where('id',$u->media_id)->first();
                $variant=$u->variant_id
                    ? DB::table('media_variants')->where('id',$u->variant_id)->first() : null;
                if($m && $u->provider_upload_id_encrypted) {
                    $this->store->abort($variant?->object_key??$m->object_key,
                        Crypt::decryptString($u->provider_upload_id_encrypted));
                }
                DB::table('media_upload_sessions')->where('id',$u->id)->update(['state'=>'expired','updated_at'=>now()]);
                if($variant) DB::table('media_variants')->where('id',$variant->id)
                    ->update(['state'=>'aborted','updated_at'=>now()]);
                elseif($m) $this->stageDelete($m->id,'orphan_upload');
                $stats['expired_uploads']++;
            }catch(\Throwable) {$stats['errors']++;}
        }
        $due=DB::table('media_objects')->where('state','ready')
            ->whereNotNull('retention_deadline')->where('retention_deadline','<=',now())
            ->limit($limit)->pluck('id');
        foreach($due as $id) {
            $this->stageDelete($id,'retention_expired');
            $stats['queued_deletions']++;
        }
        $jobs=DB::table('media_delete_jobs')->where('status','pending')
            ->where('next_attempt_at','<=',now())->limit($limit)->get();
        foreach($jobs as $job) {
            $m=DB::table('media_objects')->where('id',$job->media_id)->first();
            if(!$m || $m->state==='deleted') {
                DB::table('media_delete_jobs')->where('id',$job->id)->update([
                    'status'=>'completed','completed_at'=>now(),'updated_at'=>now(),
                ]);
                continue;
            }
            // Deny all reads immediately but do not destroy ciphertext under legal hold.
            if($m->legal_hold_until && now()->lt($m->legal_hold_until)) continue;
            try {
                foreach(DB::table('media_variants')->where('media_id',$m->id)->pluck('object_key') as $key)
                    $this->store->delete($key);
                $this->store->delete($m->object_key);
                DB::transaction(function()use($m,$job):void {
                    DB::table('media_objects')->where('id',$m->id)
                        ->update(['state'=>'deleted','updated_at'=>now()]);
                    DB::table('media_delete_jobs')->where('id',$job->id)->update([
                        'status'=>'completed','completed_at'=>now(),'updated_at'=>now(),
                    ]);
                    $this->emit($m->id,'media.purge.completed.v1');
                });
                $stats['purged']++;
            } catch(\Throwable) {
                DB::table('media_delete_jobs')->where('id',$job->id)->update([
                    'attempts'=>$job->attempts+1,
                    'next_attempt_at'=>now()->addMinutes(min(360,2**min(8,$job->attempts+1))),
                    'updated_at'=>now(),
                ]);
                $stats['errors']++;
            }
        }
        return $stats;
    }
}