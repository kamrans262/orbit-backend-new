<?php

namespace App\Modules\Plans\Services;

use App\Modules\Circle\Services\CircleAccessPolicy;
use App\Modules\Social\Services\SocialGraphService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PlanPolicy
{
    public function __construct(
        private readonly SocialGraphService $social,
        private readonly CircleAccessPolicy $circles,
    ) {}

    public function installed(): bool
    {
        foreach (['plans','plan_invitees','plan_circles',
            'plan_reminder_preferences','plan_reminder_jobs','plan_change_events',
            'plan_controls','plan_admin_audits'] as $table) {
            if (!Schema::hasTable($table)) return false;
        }
        return true;
    }

    public function assertReady(): void
    {
        abort_unless(config('orbit_plans.enabled') === true, 503, 'Plans unavailable.');
        abort_unless($this->installed(), 503, 'Plans schema not provisioned.');
    }

    public function control(string $key): bool
    {
        if (!$this->installed()) return false;
        $enabled=DB::table('plan_controls')->where('key',$key)->value('enabled');
        return $enabled===null ? false : (bool)$enabled;
    }

    public function active(string $user): bool
    {
        return DB::table('orbit_users')->where('id',$user)->where('status','active')
            ->whereNull('deletion_requested_at')->exists();
    }

    /** Revalidate persisted opt-in and offset at dispatch time, even if a
     * scheduler job was leased before the user saved new preferences. */
    public function reminderAllowed(string $user,int $offset): bool
    {
        if (!in_array($offset,config('orbit_plans.allowed_reminder_offsets',
            [0,15,60,1440]),true)) return false;
        $row=DB::table('plan_reminder_preferences')->where('user_id',$user)->first();
        if (!$row) return in_array($offset,config('orbit_plans.default_reminder_offsets',
            [15,60]),true);
        if (!(bool)$row->enabled) return false;
        $offsets=json_decode((string)$row->offsets_json,true);
        return is_array($offsets) && in_array($offset,
            array_map('intval',$offsets),true);
    }

    public function actor(Request $request): string
    {
        $this->assertReady();
        $session = $request->attributes->get('orbit_session');
        abort_unless($session,401);
        $user=(string)$session->user_id;
        abort_unless($this->active($user) &&
            DB::table('devices')->where('id',(string)$session->device_id)
                ->where('user_id',$user)->where('trust_state','trusted')
                ->whereNotNull('approved_at')->whereNull('revoked_at')->exists(),403,
            'Trusted active session required.');
        return $user;
    }

    public function allowedInvitee(string $organizer,string $candidate,array $circleIds=[]): bool
    {
        if($organizer===$candidate || !$this->active($organizer) || !$this->active($candidate)
            || $this->social->isBlockedEitherWay($organizer,$candidate)) return false;
        if($this->social->isFriend($organizer,$candidate))return true;
        foreach($circleIds as $circle) {
            if($this->circles->canAccessProtected($circle,$organizer,$candidate,'plans'))return true;
        }
        return false;
    }

    public function canViewRecord(object $plan,string $user): bool
    {
        if(!$this->active($user) || !$this->active((string)$plan->organizer_id))return false;
        if($plan->organizer_id===$user)return true;
        $invite=DB::table('plan_invitees')->where('plan_id',$plan->id)
            ->where('user_id',$user)->whereIn('state',['invited','joined','declined'])->first();
        if(!$invite)return false;
        $circles=DB::table('plan_circles')->where('plan_id',$plan->id)
            ->pluck('circle_id')->all();
        return $this->allowedInvitee((string)$plan->organizer_id,$user,$circles);
    }

    public function canView(string $planId,string $user): bool
    {
        if(!$this->installed())return false;
        $plan=DB::table('plans')->where('id',$planId)->first();
        return $plan!==null && $this->canViewRecord($plan,$user);
    }

    public function requirePlan(string $planId,string $user): object
    {
        $this->assertReady();
        $row=DB::table('plans')->where('id',$planId)->first();
        abort_unless($row && $this->canViewRecord($row,$user),404,'Plan unavailable.');
        return $row;
    }

    public function editable(object $plan,string $user): void
    {
        abort_unless($plan->organizer_id===$user,403,'Organizer permission required.');
        abort_unless($plan->status==='scheduled' && now()->lt($plan->starts_at),409,
            'Past or cancelled plans are read-only.');
    }
}
