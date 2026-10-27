<?php

namespace App\Modules\Location\Services;

use App\Models\OrbitUser;
use App\Modules\Circle\Services\CircleAccessPolicy;
use App\Modules\Circle\Services\CircleConsentPolicy;
use App\Modules\Social\Services\SocialGraphService;
use Illuminate\Support\Facades\DB;

/** Deny-first source of truth for M10 reads AND writes. M9 status != location. */
final class LocationPolicy
{
    public function __construct(
        private readonly SocialGraphService $social,
        private readonly CircleAccessPolicy $circles,
    ) {}

    public function enabled(): bool
    {
        return config('orbit_location.enabled') === true;
    }

    public function ensureActive(string $user): void
    {
        abort_unless($this->enabled(),404,'Location is unavailable.');
        abort_unless(OrbitUser::query()->whereKey($user)->where('status','active')
            ->whereNull('deletion_requested_at')->exists(),403);
    }

    public function privacy(string $user): array
    {
        $raw=DB::table('orbit_user_preferences')->where('user_id',$user)->value('privacy');
        $settings=is_string($raw) ? json_decode($raw,true) : null;
        if (!is_array($settings)) $settings=[];
        return [
            'ghost'=>($settings['ghost_mode_enabled'] ?? true) !== false,
            'sharing'=>($settings['location_sharing_enabled'] ?? false) === true,
            'activity'=>$settings['location_activity'] ?? 'Off',
            'visibility'=>$settings['location_visibility'] ?? 'Nobody',
        ];
    }

    public function globalAllows(string $owner, string $audienceType): bool
    {
        if (!$this->enabled()) return false;
        $p=$this->privacy($owner);
        if ($p['ghost'] || !$p['sharing'] || $p['activity']==='Off') return false;
        return $audienceType==='circle'
            ? $p['visibility']==='My Circle'
            : in_array($p['visibility'],['Friends','Selected People'],true);
    }

    public function canOwnCircle(string $circle,string $owner): bool
    {
        return config('orbit_circle.consumer_enabled.location')===true
            && $this->circles->isMember($circle,$owner)
            && DB::table('circle_members')->where('circle_id',$circle)->where('user_id',$owner)
                ->whereNull('removed_at')->where('role','!=','restricted')->exists();
    }

    public function mayCreate(string $owner,string $type,string $target): bool
    {
        if (!$this->globalAllows($owner,$type)) return false;
        if ($type==='circle') return $this->canOwnCircle($target,$owner);
        return $type==='person' && $target!==$owner
            && OrbitUser::query()->whereKey($target)->where('status','active')
                ->whereNull('deletion_requested_at')->exists()
            && !$this->social->isBlockedEitherWay($owner,$target)
            && $this->social->isFriend($owner,$target);
    }

    public function active(object $share): bool
    {
        return $share->status==='active' && $share->revoked_at===null
            && ($share->expires_at===null || \Illuminate\Support\Carbon::parse($share->expires_at)->isFuture());
    }

    public function visibleTo(object $share,string $viewer): bool
    {
        $owner=(string)$share->owner_id;
        if (!$this->active($share) || $owner===$viewer
            || !$this->globalAllows($owner,(string)$share->audience_type)
            || !OrbitUser::query()->whereKey($viewer)->where('status','active')
                ->whereNull('deletion_requested_at')->exists()
            || $this->social->isBlockedEitherWay($viewer,$owner)) return false;

        if ($share->audience_type==='person') {
            return $share->audience_id===$viewer && $this->social->isFriend($viewer,$owner);
        }
        if ($share->audience_type!=='circle'
            || config('orbit_circle.consumer_enabled.location')!==true
            || !$this->circles->canAccessProtected((string)$share->audience_id,$viewer,$owner,'location')) {
            return false;
        }
        $grant=DB::table('circle_permissions')->where('circle_id',$share->audience_id)
            ->where('scope_key','user:'.$owner)->where('permission_key','location')
            ->where('subject_user_id',$owner)->first();
        if (!$grant || !app(CircleConsentPolicy::class)->consentCurrentlyValid($grant,'location')) return false;
        $value=json_decode((string)$grant->value_json,true);
        // A changed Circle precision may downgrade access but never escalate it.
        return is_array($value) && ($value['precision'] ?? 'hidden')!=='hidden';
    }

    public function effectivePrecision(object $share): string
    {
        if ($share->audience_type!=='circle') return (string)$share->precision;
        $row=DB::table('circle_permissions')->where('circle_id',$share->audience_id)
            ->where('scope_key','user:'.$share->owner_id)->where('permission_key','location')
            ->value('value_json');
        $grant=is_string($row) ? json_decode($row,true) : null;
        return $share->precision==='approximate' || ($grant['precision'] ?? 'hidden')!=='precise'
            ? 'approximate' : 'precise';
    }

    public function canUpload(string $owner): bool
    {
        if (!$this->enabled() || $this->privacy($owner)['ghost']) return false;
        $shares=DB::table('location_shares')->where('owner_id',$owner)->where('status','active')
            ->where(fn ($q)=>$q->whereNull('expires_at')->orWhere('expires_at','>',now()))
            ->limit((int) config('orbit_location.max_shares_per_user',60)+1)->get();
        foreach ($shares as $share) {
            if (!$this->mayCreate($owner,$share->audience_type,$share->audience_id)) continue;
            if ($share->audience_type==='circle') {
                $grant=DB::table('circle_permissions')
                    ->where('circle_id',$share->audience_id)
                    ->where('scope_key','user:'.$owner)->where('permission_key','location')
                    ->where('subject_user_id',$owner)->first();
                if (!$grant || !app(CircleConsentPolicy::class)->consentCurrentlyValid($grant,'location')) {
                    continue;
                }
            }
            return true;
        }
        return false;
    }
}
