<?php
namespace App\Modules\Media\Services;

use App\Models\OrbitUser;
use App\Modules\Circle\Services\CircleAccessPolicy;
use App\Modules\Social\Services\SocialGraphService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MediaPolicy
{
    public function __construct(
        private readonly SocialGraphService $social,
        private readonly CircleAccessPolicy $circles
    ) {}

    public function canRead(object $media, string $viewer): bool
    {
        if (config('orbit_media.enabled') !== true
            || $media->state !== 'ready' || $media->deleted_at !== null) return false;
        if (!OrbitUser::query()->whereKey($viewer)->where('status','active')
            ->whereNull('deletion_requested_at')->exists()) return false;
        if ($media->retention_deadline !== null && now()->greaterThanOrEqualTo($media->retention_deadline)) return false;
        // Moment media is authorized for seven days even though the purge deadline is day 30.
        if ($media->purpose === 'moment') {
            // Publication time, not upload time, owns the 7-day M7 viewer lease.
            $published = Schema::hasTable('moment_media') ? DB::table('moment_media as mm')
                ->join('moments as m','m.id','=','mm.moment_id')
                ->where('mm.media_id',$media->id)
                ->first(['m.id as moment_id','m.audience_revision','m.state',
                    'm.deleted_at','m.view_expires_at']) : null;
            if ($published) {
                if (config('orbit_moments.enabled') !== true || $published->state !== 'published'
                    || $published->deleted_at !== null || now()->gte($published->view_expires_at)) return false;
            } else {
                // M7 recipient grants may be prepared before publication.
                // While M7 is enabled, those prepared envelopes NEVER grant
                // access to unpublished/cancelled Moment ciphertext.
                if(config('orbit_moments.enabled') === true
                    && $media->owner_user_id !== $viewer) return false;
                if(now()->gte(\Illuminate\Support\Carbon::parse($media->created_at)
                    ->addDays((int)config('orbit_media.moments_view_days')))) return false;
            }
        }
        if ($media->owner_user_id === $viewer) return true;
        if ($this->social->isBlockedEitherWay($viewer,$media->owner_user_id)) return false;
        if ($media->purpose === 'sos') return false; // Separate authorized SOS responder policy in M16.
        $grants=DB::table('media_audience_grants')->where('media_id',$media->id)
            ->where('recipient_user_id',$viewer)
            ->when(isset($published) && $published!==null, function ($query)use($published,$viewer) {
                // M7 publication ACL, not all historically prepared M6 grants,
                // decides who may download a Moment ciphertext.
                $query->whereExists(function($member)use($published,$viewer) {
                    $member->selectRaw('1')->from('moment_recipients as mr')
                        ->whereColumn('mr.authorizing_grant_id','media_audience_grants.id')
                        ->where('mr.moment_id',$published->moment_id)
                        ->where('mr.viewer_id',$viewer)
                        ->where('mr.version',$published->audience_revision)
                        ->whereNull('mr.revoked_at');
                });
            })
            ->whereNull('revoked_at')
            ->where(fn($q)=>$q->whereNull('expires_at')->orWhere('expires_at','>',now()))
            ->get();
        foreach($grants as $g) {
            if(isset($published) && $published!==null && in_array($g->audience_kind,['accepted_friends','selected_friends'],true)) {
                $raw=DB::table('orbit_user_preferences')->where('user_id',$media->owner_user_id)->value('privacy');
                $privacy=is_string($raw)?json_decode($raw,true):null;
                if(!is_array($privacy) || ($privacy['moment_default_audience']??'Nobody')!=='Selected People')continue;
            }
            if ($g->audience_kind === 'selected_friends' || $g->audience_kind === 'accepted_friends') {
                if ($this->social->isFriend($media->owner_user_id,$viewer)) return true;
            }
            if(isset($published) && $published !== null && $g->audience_kind==='selected_circles'){
                $raw=DB::table('orbit_user_preferences')->where('user_id',$media->owner_user_id)->value('privacy');
                $prefs=is_string($raw)?json_decode($raw,true):null;
                if(!is_array($prefs)||($prefs['moment_default_audience']??'Nobody')!=='My Circle')continue;
            }
            if ($g->audience_kind === 'selected_circles' && $g->circle_id !== null
                && $this->circles->isMember($g->circle_id,$viewer)
                && $this->circles->isMember($g->circle_id,$media->owner_user_id)
                && $this->circles->canAccessProtected($g->circle_id,$viewer,
                    $media->owner_user_id,$media->purpose === 'message' ? 'message':'moments')) return true;
        }
        return false;
    }

    public function requireRead(object $media, string $viewer): void
    {
        abort_unless($this->canRead($media,$viewer),404,'Media unavailable.');
    }

    public function recipientEligible(string $owner, string $recipient, string $audience, ?string $circle): bool
    {
        if ($owner === $recipient
            || !OrbitUser::query()->whereKey($recipient)->where('status','active')
                ->whereNull('deletion_requested_at')->exists()
            || $this->social->isBlockedEitherWay($owner,$recipient)) return false;
        if (in_array($audience,['selected_friends','accepted_friends'],true)) {
            return $this->social->isFriend($owner,$recipient);
        }
        if ($audience === 'selected_circles' && $circle) {
            return $this->circles->isMember($circle,$owner)
                && $this->circles->isMember($circle,$recipient);
        }
        return false;
    }
}