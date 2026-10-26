<?php

namespace App\Modules\Dashboard\Services;

use App\Models\FeatureFlag;
use App\Models\OrbitDevice;
use App\Models\OrbitUser;
use App\Modules\Circle\Services\CircleAccessPolicy;
use App\Modules\Circle\Services\CircleService;
use App\Modules\Config\Services\ConfigRegistry;
use App\Modules\Moments\Services\MomentPolicy;
use App\Modules\Social\Services\SocialGraphService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * M8 composes existing privacy-controlled read models. It cannot grant access
 * to Circle, Moment or social content and never persists decrypted media.
 */
final class DashboardSummaryService
{
    public function __construct(
        private readonly SocialGraphService $social,
        private readonly CircleService $circles,
        private readonly CircleAccessPolicy $circlePolicy,
        private readonly MomentPolicy $momentPolicy,
    ) {}

    public function authorize(string $viewer, string $device): void
    {
        abort_unless(config('orbit_dashboard.enabled') === true, 404, 'Dashboard unavailable.');
        abort_unless(OrbitUser::query()->whereKey($viewer)->where('status','active')
            ->whereNull('deletion_requested_at')->exists(), 403);
        // M1 administrative kill switches/targeted rollout, when configured.
        $flag = FeatureFlag::query()->where('key','m8.dashboard')->first();
        if ($flag) {
            $platform = OrbitDevice::query()->whereKey($device)->where('user_id',$viewer)->value('platform');
            abort_unless(app(ConfigRegistry::class)->flagEnabled($flag, [
                'subject'=>$viewer, 'platform'=>$platform,
            ]), 404, 'Dashboard unavailable.');
        }
    }

    /** Per-section state is never a proxy for the existence of hidden data. */
    private function section(string $state, array $items = [], array $extra = []): array
    {
        return ['state'=>$state, 'items'=>$items, ...$extra];
    }

    private function safely(callable $read): array
    {
        try {
            $items = $read();
            return $this->section($items === [] ? 'empty' : 'ready', $items);
        } catch (\Throwable $e) {
            report($e);
            return $this->section('unavailable');
        }
    }

    private function circleItems(string $viewer): array
    {
        $limit = min(20, max(1,(int) config('orbit_dashboard.circle_limit',8)));
        // Reuse M5 listing: archived/expired/blocked memberships are filtered
        // by CircleService and its existing lifecycle and block policies.
        $rows = $this->circles->listing($viewer, 1)['items'];
        $order = DB::table('dashboard_preferences')->where('user_id',$viewer)->value('circle_order');
        $ids = is_string($order) ? json_decode($order,true) : (is_array($order) ? $order : []);
        if (!is_array($ids)) $ids=[];
        $ranks = array_flip(array_values(array_filter($ids,'is_string')));
        usort($rows, static function (array $a,array $b) use ($ranks): int {
            $left = $ranks[$a['id']] ?? PHP_INT_MAX;
            $right = $ranks[$b['id']] ?? PHP_INT_MAX;
            return ($left <=> $right) ?: strcmp((string)$a['id'],(string)$b['id']);
        });
        return array_slice(array_map(static fn (array $c): array => [
            'id'=>$c['id'], 'name'=>$c['name'], 'icon_key'=>$c['icon_key'],
            'color_value'=>$c['color_value'], 'role'=>$c['role'],
            'member_count'=>$c['member_count'], 'revision'=>$c['revision'],
            'lifecycle_state'=>$c['lifecycle_state'],
        ], $rows),0,$limit);
    }

    private function peopleItems(string $viewer): array
    {
        $limit = min(20,max(1,(int)config('orbit_dashboard.people_limit',12)));
        $pairs=DB::table('friendships')->where(function ($q) use ($viewer): void {
            $q->where('user_low_id',$viewer)->orWhere('user_high_id',$viewer);
        })->orderByDesc('created_at')->limit(100)->get(['user_low_id','user_high_id']);
        $ids=$pairs->map(static fn($f) => $f->user_low_id===$viewer
            ? $f->user_high_id : $f->user_low_id)->all();
        $people=OrbitUser::query()->whereIn('id',$ids)->where('status','active')
            ->whereNull('deletion_requested_at')->get(['id','display_name','username'])->keyBy('id');
        $result=[];
        foreach ($ids as $id) {
            if (!$people->has($id) || $this->social->isBlockedEitherWay($viewer,$id)) continue;
            $p=$people->get($id);
            // Presence unknown: do not manufacture "offline" or proximity.
            $result[]=['id'=>$id,'display_name'=>$p->display_name,
                'username'=>$p->username,'relationship'=>'friend','presence'=>null];
            if (count($result)>=$limit) break;
        }
        return $result;
    }

    private function momentsItems(string $viewer): array
    {
        if (config('orbit_moments.enabled') !== true || config('orbit_media.enabled') !== true) return [];
        $limit=min(3,max(1,(int)config('orbit_dashboard.moment_limit',3)));
        $candidates=DB::table('moments')->where('state','published')->whereNull('deleted_at')
            ->where('rail_expires_at','>',now())->where('view_expires_at','>',now())
            ->where(function ($q) use ($viewer): void {
                $q->where('creator_id',$viewer)->orWhereExists(function($sub) use ($viewer): void {
                    $sub->selectRaw('1')->from('moment_recipients as mr')
                        ->whereColumn('mr.moment_id','moments.id')
                        ->whereColumn('mr.version','moments.audience_revision')
                        ->where('mr.viewer_id',$viewer)->whereNull('mr.revoked_at');
                });
            })->orderByDesc('published_at')->orderByDesc('id')->limit($limit*8)
            ->get(['id','creator_id','state','deleted_at','published_at','rail_expires_at',
                'view_expires_at','audience_revision']);
        $items=[];
        foreach ($candidates as $moment) {
            // Same M7 policy as the real viewer, including M4 blocks,
            // M5 consent, M6 current grants and audience revisions.
            $context=$this->momentPolicy->context($moment,$viewer);
            if ($context===null) continue;
            $items[]=['id'=>$moment->id,'creator_id'=>$moment->creator_id,
                'context_id'=>$context,'published_at'=>$moment->published_at,
                'rail_expires_at'=>$moment->rail_expires_at,
                'view_expires_at'=>$moment->view_expires_at,
                'audience_revision'=>(int)$moment->audience_revision];
            if (count($items)>=$limit) break;
        }
        return $items;
    }

    private function smartCardItems(string $viewer): array
    {
        if (config('orbit_dashboard.smart_cards_enabled') !== true) return [];
        $suppressed=DB::table('dashboard_card_dismissals')
            ->where('user_id',$viewer)->where('card_key','friend_requests')
            ->where('dismissed_until','>',now())->exists();
        if ($suppressed) return [];
        $requests=DB::table('friend_requests')
            ->where('recipient_id',$viewer)->where('status','pending')
            ->orderByDesc('created_at')->limit(20)->pluck('sender_id');
        foreach ($requests as $sender) {
            if (!$this->social->isBlockedEitherWay($viewer,(string)$sender)
                && OrbitUser::query()->whereKey($sender)->where('status','active')
                    ->whereNull('deletion_requested_at')->exists()) {
                return [['key'=>'friend_requests','kind'=>'friend_requests',
                    'destination'=>'friends_requests']];
            }
        }
        return [];
    }

    public function summary(string $viewer): array
    {
        $user=OrbitUser::query()->whereKey($viewer)->firstOrFail(['id','display_name']);
        $sections=[
            'circles' => config('orbit_circle.enabled') === true
                ? $this->safely(fn()=> $this->circleItems($viewer)) : $this->section('disabled'),
            'people' => $this->safely(fn()=> $this->peopleItems($viewer)),
            'moments' => config('orbit_moments.enabled') === true && config('orbit_media.enabled') === true
                ? $this->safely(fn()=> $this->momentsItems($viewer)) : $this->section('disabled'),
            'plans' => $this->section('disabled'),
            'activity' => $this->section('disabled'),
            'smart_cards' => config('orbit_dashboard.smart_cards_enabled') === true
                ? $this->safely(fn()=> $this->smartCardItems($viewer)) : $this->section('disabled'),
        ];
        $graphRevision=$this->social->currentRevision($viewer);
        $circleRevisions=array_map(static fn($r)=>[$r['id'],$r['revision']],$sections['circles']['items']);
        $momentRevisions=array_map(static fn($r)=>[$r['id'],$r['audience_revision']],$sections['moments']['items']);
        $preferenceRevision=(int)(DB::table('dashboard_preferences')
            ->where('user_id',$viewer)->value('revision') ?? 0);
        $dismissVersion=(string)(DB::table('dashboard_card_dismissals')
            ->where('user_id',$viewer)->max('updated_at') ?? '');
        $revision=hash_hmac('sha256',json_encode([
            $graphRevision,$circleRevisions,$momentRevisions,
            $preferenceRevision,$dismissVersion,
            (bool)config('orbit_circle.enabled'),(bool)config('orbit_moments.enabled'),
            (bool)config('orbit_media.enabled'),
        ],JSON_THROW_ON_ERROR),(string)config('app.key'));
        return [
            'schema_version'=>1, 'generated_at'=>now()->toIso8601String(),
            'viewer_policy_revision'=>$revision,
            'capabilities'=>[
                'circles'=>config('orbit_circle.enabled')===true,
                'people'=>true,
                'moments'=>config('orbit_moments.enabled')===true && config('orbit_media.enabled')===true,
                'plans'=>false, 'presence'=>false, 'ping'=>false, 'sos'=>false,
                'location'=>false, 'activity'=>false,
                'smart_cards'=>config('orbit_dashboard.smart_cards_enabled')===true,
            ],
            'greeting'=>['display_name'=>$user->display_name],
            'sections'=>$sections,
        ];
    }

    public function dismiss(string $viewer,string $key): array
    {
        abort_unless($key === 'friend_requests'
            && config('orbit_dashboard.smart_cards_enabled')===true, 404);
        $until=now()->addHours((int) config('orbit_dashboard.dismiss_hours',24));
        DB::table('dashboard_card_dismissals')->updateOrInsert(
            ['user_id'=>$viewer,'card_key'=>$key],
            ['dismissed_until'=>$until,'updated_at'=>now(),'created_at'=>now()]
        );
        return ['key'=>$key,'dismissed_until'=>$until->toIso8601String()];
    }

    public function orderCircles(string $viewer,array $ids): array
    {
        abort_unless(config('orbit_circle.enabled')===true,404);
        foreach ($ids as $id) {
            abort_unless($this->circlePolicy->isMember($id,$viewer),422,
                'Only your currently accessible Circles can be ordered.');
        }
        DB::transaction(function() use ($viewer,$ids): void {
            $old=DB::table('dashboard_preferences')->where('user_id',$viewer)
                ->lockForUpdate()->first();
            if ($old) {
                DB::table('dashboard_preferences')->where('user_id',$viewer)->update([
                    'circle_order'=>json_encode($ids,JSON_THROW_ON_ERROR),
                    'revision'=>$old->revision+1,'updated_at'=>now(),
                ]);
            } else {
                DB::table('dashboard_preferences')->insert([
                    'user_id'=>$viewer,'circle_order'=>json_encode($ids,JSON_THROW_ON_ERROR),
                    'revision'=>1,'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
        },3);
        return ['circle_order'=>$ids];
    }
}
