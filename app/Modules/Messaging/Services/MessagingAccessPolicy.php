<?php
namespace App\Modules\Messaging\Services;

use App\Models\OrbitDevice;
use App\Models\OrbitUser;
use App\Modules\Circle\Services\CircleAccessPolicy;
use App\Modules\Circle\Services\CircleConsentPolicy;
use App\Modules\Social\Services\SocialRelationPolicy;
use Illuminate\Support\Facades\DB;

final class MessagingAccessPolicy
{
    public function __construct(
        private readonly SocialRelationPolicy $social,
        private readonly CircleAccessPolicy $circles
    ) {}

    public function assertSession(string $user, string $device): void
    {
        abort_unless(OrbitUser::query()->whereKey($user)->where('status','active')
            ->whereNull('deletion_requested_at')->exists(),403,'Active account required.');
        abort_unless(OrbitDevice::query()->whereKey($device)->where('user_id',$user)
            ->where('trust_state','trusted')->whereNotNull('public_key')
            ->whereNull('revoked_at')->exists(),403,'Trusted device required.');
    }

    public function canDirect(string $first, string $second): bool
    {
        return $first !== $second
            && $this->social->canInteract($first,$second,'message')
            && $this->social->canInteract($second,$first,'message');
    }

    public function assertDirect(string $first, string $second): void
    {
        abort_unless($this->canDirect($first,$second),404,'Conversation unavailable.');
    }

    public function circleSenderAllowed(string $circle, string $sender): bool
    {
        if (config('orbit_circle.consumer_enabled.message') !== true ||
            !$this->circles->isMember($circle,$sender)) return false;
        $member=DB::table('circle_members')->where('circle_id',$circle)
            ->where('user_id',$sender)->whereNull('removed_at')->first();
        if(!$member || $member->role === 'restricted') return false;
        $raw=DB::table('orbit_user_preferences')->where('user_id',$sender)->value('privacy');
        $privacy=$raw ? json_decode((string)$raw,true) : null;
        if(!is_array($privacy) || ($privacy['message_eligibility'] ?? 'Nobody') !== 'My Circle') return false;
        $grant=DB::table('circle_permissions')->where('circle_id',$circle)
            ->where('subject_user_id',$sender)->where('scope_key','user:'.$sender)
            ->where('permission_key','message')->first();
        return $grant !== null
            && app(CircleConsentPolicy::class)->consentCurrentlyValid($grant,'message');
    }

    public function assertCircle(string $circle, string $sender): void
    {
        abort_unless($this->circleSenderAllowed($circle,$sender),
            404,'Circle messages require current membership, privacy and reviewed consent.');
    }

    public function canCirclePair(string $circle,string $sender,string $recipient): bool
    {
        return $sender !== $recipient && $this->circleSenderAllowed($circle,$sender)
            && $this->circles->canAccessProtected($circle,$sender,$recipient,'message')
            && $this->circles->canAccessProtected($circle,$recipient,$sender,'message');
    }

    public function canDiscoverKeys(string $viewer,string $recipient): bool
    {
        if($viewer===$recipient)return true;
        if($this->canDirect($viewer,$recipient))return true;
        if(config('orbit_circle.consumer_enabled.message')!==true)return false;
        $ids=DB::table('circle_members as a')->join('circle_members as b',
            'a.circle_id','=','b.circle_id')
            ->where('a.user_id',$viewer)->where('b.user_id',$recipient)
            ->whereNull('a.removed_at')->whereNull('b.removed_at')
            ->limit(100)->pluck('a.circle_id');
        foreach($ids as $circle) {
            if($this->canCirclePair((string)$circle,$viewer,$recipient))return true;
        }
        return false;
    }

    public function assertConversation(object $conversation,string $user): void
    {
        if($conversation->kind==='direct') {
            $other=DB::table('messaging_members')->where('conversation_id',$conversation->id)
                ->where('user_id','!=',$user)->value('user_id');
            $member=DB::table('messaging_members')->where('conversation_id',$conversation->id)
                ->where('user_id',$user)->exists();
            abort_unless($member && $other && $this->canDirect($user,(string)$other),
                404,'Conversation unavailable.');
            return;
        }
        abort_unless($conversation->kind==='circle' && $conversation->circle_id
            && $this->circleSenderAllowed((string)$conversation->circle_id,$user),
            404,'Conversation unavailable.');
    }

    public function recipients(object $conversation,string $sender): array
    {
        $this->assertConversation($conversation,$sender);
        if($conversation->kind==='direct') {
            return DB::table('messaging_members')->where('conversation_id',$conversation->id)
                ->where('user_id','!=',$sender)->pluck('user_id')->map(strval(...))->all();
        }
        $ids=DB::table('circle_members')->where('circle_id',$conversation->circle_id)
            ->whereNull('removed_at')->where('user_id','!=',$sender)
            ->limit(201)->pluck('user_id');
        abort_if(count($ids)>200,422,'Circle group size exceeds the encrypted messaging limit.');
        $result=[];
        foreach($ids as $id) {
            if($this->canCirclePair((string)$conversation->circle_id,$sender,(string)$id)) {
                $result[]=(string)$id;
            }
        }
        return $result;
    }

    public function readReceiptsAllowed(string $viewer): bool
    {
        $raw=DB::table('orbit_user_preferences')->where('user_id',$viewer)->value('privacy');
        $privacy=$raw?json_decode((string)$raw,true):null;
        return is_array($privacy) && ($privacy['read_receipts_enabled']??false)===true;
    }
}
