<?php
namespace App\Modules\Location\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** An owner-reviewed M6 avatar envelope bound to exactly one M10 person share. */
final class LocationAvatarGrantService
{
    public function attach(string $owner,string $shareId,string $mediaId,string $grantId): array
    {
        abort_unless(config('orbit_location.enabled')===true
            && config('orbit_media.enabled')===true,404);
        abort_unless(Schema::hasColumn('location_shares','avatar_grant_id'),503);
        return DB::transaction(function() use ($owner,$shareId,$mediaId,$grantId): array {
            $share=DB::table('location_shares')->where('id',$shareId)
                ->where('owner_id',$owner)->where('audience_type','person')
                ->lockForUpdate()->first();
            abort_unless($share && $share->status==='active'
                && $share->revoked_at===null
                && ($share->expires_at===null || $share->expires_at>now())
                && app(LocationPolicy::class)->mayCreate($owner,'person',$share->audience_id),
                403,'Location sharing is not authorized.');
            $avatar=DB::table('orbit_users')->where('id',$owner)->value('avatar_media_id');
            abort_unless($avatar===$mediaId,404,'Current avatar unavailable.');
            $media=DB::table('media_objects')->where('id',$mediaId)
                ->where('owner_user_id',$owner)->where('purpose','avatar')
                ->where('state','ready')->whereNull('deleted_at')->first();
            abort_unless($media,404);
            $grant=DB::table('media_audience_grants')->where('id',$grantId)
                ->where('media_id',$mediaId)->where('issuer_user_id',$owner)
                ->where('recipient_user_id',$share->audience_id)
                ->where('audience_kind','selected_friends')->whereNull('revoked_at')->first();
            abort_unless($grant && ($grant->expires_at===null
                || $grant->expires_at>now()),403,'Avatar consent is unavailable.');
            if ($share->expires_at !== null) {
                // A timed M10 grant must never leave a longer-lived M6 avatar grant.
                abort_unless($grant->expires_at!==null
                    && $grant->expires_at <= $share->expires_at,403);
            }
            abort_unless(DB::table('media_device_key_envelopes as e')
                ->join('devices as d','d.id','=','e.recipient_device_id')
                ->where('e.grant_id',$grantId)->where('e.media_id',$mediaId)
                ->where('e.variant','original')->whereNull('e.revoked_at')
                ->where('d.user_id',$share->audience_id)->where('d.trust_state','trusted')
                ->whereNull('d.revoked_at')->exists(),403);
            if ($share->avatar_grant_id && $share->avatar_grant_id!==$grantId) {
                $this->revokeFor($share);
            }
            DB::table('location_shares')->where('id',$shareId)->update([
                'avatar_media_id'=>$mediaId,'avatar_grant_id'=>$grantId,
                'updated_at'=>now(),
            ]);
            return ['avatar_shared'=>true];
        },3);
    }

    public function revokeFor(object $share): void
    {
        if(!Schema::hasColumn('location_shares','avatar_grant_id') ||
            !$share->avatar_grant_id)return;
        DB::table('media_audience_grants')
            ->where('id',$share->avatar_grant_id)
            ->where('media_id',$share->avatar_media_id)
            ->where('issuer_user_id',$share->owner_id)
            ->update(['revoked_at'=>now(),'updated_at'=>now()]);
        DB::table('media_device_key_envelopes')
            ->where('grant_id',$share->avatar_grant_id)
            ->update(['revoked_at'=>now(),'updated_at'=>now()]);
        DB::table('location_shares')->where('id',$share->id)->update([
            'avatar_grant_id'=>null,'avatar_media_id'=>null,'updated_at'=>now(),
        ]);
    }
}
