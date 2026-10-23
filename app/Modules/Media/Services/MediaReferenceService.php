<?php
namespace App\Modules\Media\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Internal integration contract for M3 avatars, M7 Moments, M13 messages,
 * and M16 SOS. Consumers cannot fabricate or transfer media references via a
 * public API. Shared ciphertext is not deleted just because one reference ends.
 */
final class MediaReferenceService
{
    public const TYPES=['avatar','moment','message','sos'];

    public function attach(string $mediaId,string $owner,string $consumerType,string $consumerId):string
    {
        if(!in_array($consumerType,self::TYPES,true)
            || !Str::isUuid($consumerId)) {
            throw new InvalidArgumentException('Invalid consumer reference.');
        }
        $media=DB::table('media_objects')->where('id',$mediaId)
            ->where('owner_user_id',$owner)->where('state','ready')
            ->whereNull('deleted_at')->first();
        abort_unless($media && $media->purpose===$consumerType,404,'Media unavailable.');
        $query=DB::table('media_references')->where('media_id',$mediaId)
            ->where('consumer_type',$consumerType)->where('consumer_id',$consumerId);
        $existing=(clone $query)->first();
        if($existing) {
            $query->update(['released_at'=>null,'updated_at'=>now()]);
            return $existing->id;
        }
        $id=(string)Str::uuid7();
        DB::table('media_references')->insert([
            'id'=>$id,'media_id'=>$mediaId,'owner_user_id'=>$owner,
            'consumer_type'=>$consumerType,'consumer_id'=>$consumerId,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        return $id;
    }
    public function release(string $mediaId,string $owner,string $consumerType,string $consumerId):void
    {
        DB::table('media_references')->where('media_id',$mediaId)
            ->where('owner_user_id',$owner)->where('consumer_type',$consumerType)
            ->where('consumer_id',$consumerId)->whereNull('released_at')
            ->update(['released_at'=>now(),'updated_at'=>now()]);
        // Do NOT delete the object here: it may be referenced by a
        // different consumer. The owner and retention worker own deletion.
    }
    public function activeCount(string $mediaId):int
    {
        return DB::table('media_references')->where('media_id',$mediaId)
            ->whereNull('released_at')->count();
    }
}