<?php

namespace App\Modules\Circle\Services;

use App\Models\OrbitUser;
use App\Modules\Social\Services\SocialGraphService;
use Illuminate\Support\Facades\DB;

/**
 * A Circle is NOT a universal grant to messaging, presence or location.
 * Called by future feature consumers before reading, writing, subscribing,
 * or exporting Circle-scoped private data. All unconfigured grants fail closed.
 */
final class CircleAccessPolicy
{
    public function __construct(private readonly SocialGraphService $social) {}

    public function isMember(string $circleId, string $userId): bool
    {
        $circle = DB::table('circles')->where('id',$circleId)
            ->whereNull('archived_at')->first(['owner_id','expires_at','archived_at']);
        if (!$circle || app(CircleTemporaryLifecycle::class)->state($circle) === 'archive_due'
            || $this->social->isBlockedEitherWay($userId,$circle->owner_id)) return false;
        if (!OrbitUser::query()->whereKey($userId)->where('status','active')
            ->whereNull('deletion_requested_at')->exists()) return false;
        return DB::table('circle_members')->where('circle_id',$circleId)
            ->where('user_id',$userId)->whereNull('removed_at')->exists();
    }

    /**
     * Future consumer read boundary. A stored request does not mean live
     * sharing: separate module switch + current member/role/block scope +
     * subject's global privacy + reviewed per-Circle consent must ALL pass.
     *
     * This is the only opt-in decision future Moments, location, presence,
     * message, etc. read APIs should call. It never returns actual private
     * content and never creates an FCM/WebSocket subscription.
     */
    public function canAccessProtected(string $circleId, string $viewer,
        string $subject, string $capability): bool
    {
        if($viewer===$subject ||
            !in_array($capability,CircleConsentPolicy::CAPABILITIES,true) ||
            config('orbit_circle.consumer_enabled.'.$capability) !== true) {
            return false;
        }
        if(!$this->isMember($circleId,$viewer) ||
            !$this->isMember($circleId,$subject) ||
            $this->social->isBlockedEitherWay($viewer,$subject)) {
            return false;
        }
        $roles=DB::table('circle_members')->where('circle_id',$circleId)
            ->whereIn('user_id',[$viewer,$subject])
            ->whereNull('removed_at')->pluck('role','user_id');
        if(($roles[$viewer] ?? 'restricted')==='restricted' ||
           ($roles[$subject] ?? 'restricted')==='restricted') return false;

        // Existing M3 profile settings remain global upper bounds; a Circle
        // override cannot evade Ghost or a user's Nobody audience.
        $raw=DB::table('orbit_user_preferences')
            ->where('user_id',$subject)->value('privacy');
        $privacy=$raw ? json_decode((string)$raw,true) : null;
        if(!is_array($privacy)) return false;
        $audiences=[
            'location'=>'location_visibility',
            'presence'=>'presence_visibility',
            'moments'=>'moment_default_audience',
            'check_in'=>'activity_visibility',
            'plans'=>'activity_visibility',
            'status'=>'activity_visibility',
            'ping'=>'ping_eligibility',
            'message'=>'message_eligibility',
        ];
        if(($privacy[$audiences[$capability]]??'Nobody')!=='My Circle') return false;
        if($capability==='location') {
            if(($privacy['ghost_mode_enabled']??true)!==false ||
                ($privacy['location_sharing_enabled']??false)!==true ||
                ($privacy['location_activity']??'Off')==='Off') return false;
        }
        $grant=DB::table('circle_permissions')->where('circle_id',$circleId)
            ->where('scope_key','user:'.$subject)
            ->where('subject_user_id',$subject)
            ->where('permission_key',$capability)->first();
        return $grant !== null &&
            app(CircleConsentPolicy::class)->consentCurrentlyValid($grant,$capability);
    }

    public function canSubscribe(string $circleId, string $userId): bool
    {
        // No FCM/WebSocket transport is approved/configured yet.
        return false;
    }
}
