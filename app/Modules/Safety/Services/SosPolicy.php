<?php

namespace App\Modules\Safety\Services;

use App\Modules\Circle\Services\CircleAccessPolicy;
use App\Modules\Circle\Services\CircleSosRoutingPolicy;
use App\Modules\Social\Services\SocialGraphService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SosPolicy
{
    public function __construct(
        private readonly CircleAccessPolicy $circles,
        private readonly CircleSosRoutingPolicy $circleRouting,
        private readonly SocialGraphService $social,
    ) {}

    public function installed(): bool
    {
        foreach (['sos_incidents','sos_trusted_contacts','sos_recipients','sos_delivery_jobs',
            'sos_dispatch_attempts','sos_audit_events','sos_admin_audits','sos_controls'] as $table) {
            if (!Schema::hasTable($table)) return false;
        }
        return true;
    }

    public function assertReady(): void
    {
        abort_unless(config('orbit_sos.enabled') === true && $this->installed(),
            503, 'SOS service unavailable. Use your local emergency services if you are in immediate danger.');
    }

    public function actor(Request $request): string
    {
        $this->assertReady();
        $session = $request->attributes->get('orbit_session');
        abort_unless($session, 401);
        $user = (string)$session->user_id;
        abort_unless($this->active($user)
            && DB::table('devices')->where('id',(string)$session->device_id)
                ->where('user_id',$user)->where('trust_state','trusted')
                ->whereNotNull('approved_at')->whereNull('revoked_at')->exists(),
            403, 'Active trusted device required.');
        return $user;
    }

    public function active(string $user): bool
    {
        return DB::table('orbit_users')->where('id',$user)->where('status','active')
            ->whereNull('deletion_requested_at')->exists();
    }

    public function control(string $key, string $default = '0'): string
    {
        if (!$this->installed()) return $default;
        return (string)(DB::table('sos_controls')->where('key',$key)->value('value') ?? $default);
    }

    public function trusted(string $owner, string $recipient): bool
    {
        return $owner !== $recipient && $this->active($owner) && $this->active($recipient)
            && $this->social->isFriend($owner,$recipient)
            && DB::table('sos_trusted_contacts')->where('owner_id',$owner)
                ->where('recipient_id',$recipient)->where('state','accepted')->exists();
    }

    public function circleEligible(string $circle, string $owner, string $recipient): bool
    {
        return $owner !== $recipient && $this->active($owner) && $this->active($recipient)
            && !$this->social->isBlockedEitherWay($owner,$recipient)
            && $this->circles->isMember($circle,$owner)
            && $this->circles->isMember($circle,$recipient)
            && DB::table('circle_members')->where('circle_id',$circle)
                ->where('user_id',$recipient)->where('role','!=','restricted')
                ->whereNull('removed_at')->exists();
    }

    /** Circle defaults are derived server-side, never from a client-supplied roster. */
    public function defaultCircleResponders(string $circle, string $owner): array
    {
        abort_unless($this->circles->isMember($circle,$owner),404,'Circle unavailable.');
        $routing=$this->circleRouting->defaultResponders($circle,$owner);
        return array_values(array_filter($routing['recipient_user_ids'],
            fn(string $user)=>$this->circleEligible($circle,$owner,$user)));
    }

    public function recipientEligible(object $incident, object $recipient): bool
    {
        if ($recipient->state === 'suppressed' || !$this->active((string)$incident->initiator_id))return false;
        return $recipient->source==='trusted'
            ? $this->trusted((string)$incident->initiator_id,(string)$recipient->user_id)
            : ($recipient->source==='circle' && $incident->circle_id !== null
                && $this->circleEligible((string)$incident->circle_id,
                    (string)$incident->initiator_id,(string)$recipient->user_id));
    }

    public function canView(object $incident, string $actor): bool
    {
        if (!$this->active($actor))return false;
        if ($incident->initiator_id===$actor)return true;
        $recipient=DB::table('sos_recipients')->where('incident_id',$incident->id)
            ->where('user_id',$actor)->first();
        return $recipient !== null && $this->recipientEligible($incident,$recipient);
    }

    public function requireIncident(string $id, string $actor): object
    {
        $row=DB::table('sos_incidents')->where('id',$id)->first();
        abort_unless($row && $this->canView($row,$actor),404,'Incident unavailable.');
        return $row;
    }
}
