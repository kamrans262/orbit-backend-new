<?php

namespace App\Modules\Notifications\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NotificationService
{
    public function __construct(private readonly NotificationPolicy $policy) {}

    public function publish(string $sourceType, string $sourceId, string $category,
                            string $type, string $targetId, array $recipients,
                            ?string $context = null, ?string $expiresAt = null,
                            ?string $occurredAt = null): int
    {
        if (config('orbit_notifications.enabled') !== true || !$this->policy->installed()) return 0;
        if (!in_array($category, config('orbit_notifications.categories', []), true)
            || !Str::isUuid($sourceId) || !Str::isUuid($targetId)
            || !preg_match('/^[a-z_]{2,32}$/D', $sourceType)) return 0;
        $template = [
            'moments'=>'moments.available', 'pings'=>'pings.received',
            'check_ins'=>'check_ins.available', 'messages'=>'messages.available',
            'friends'=>'friends.updated', 'circles'=>'circles.updated',
            'security'=>'security.notice',
        ][$category] ?? null;
        if ($template === null) return 0; // No unreviewed producer or broadcast.
        if (!DB::table('notification_templates')->where('key', $template)
            ->where('enabled', true)->exists()) return 0;
        $created = 0;
        // Preserve the actual source event time. Projected backfill must not
        // look new, nor become eligible for a new-device push months later.
        $eventTime = $occurredAt !== null
            ? Carbon::parse($occurredAt)->toDateTimeString() : now();
        DB::transaction(function () use ($sourceType, $sourceId, $category, $type,
            $targetId, $recipients, $context, $expiresAt, $eventTime,
            $template, &$created): void {
            $eventId = (string) Str::uuid();
            DB::table('notification_events')->insertOrIgnore([
                'id'=>$eventId, 'source_type'=>$sourceType, 'source_id'=>$sourceId,
                'category'=>$category, 'type'=>$type, 'target_id'=>$targetId,
                'created_at'=>$eventTime,
            ]);
            $eventId = (string) DB::table('notification_events')
                ->where('source_type', $sourceType)->where('source_id', $sourceId)->value('id');
            foreach (array_slice(array_unique(array_map('strval', $recipients)), 0, 1000) as $user) {
                if (!Str::isUuid($user)
                    || !$this->policy->targetAllowed($category, $targetId, $user, $context, $type))
                    continue;
                $created += DB::table('notification_inbox')->insertOrIgnore([
                    'id'=>(string) Str::uuid(), 'event_id'=>$eventId,
                    'recipient_user_id'=>$user, 'category'=>$category, 'type'=>$type,
                    'target_id'=>$targetId, 'context_id'=>$context, 'template_key'=>$template,
                    'created_at'=>$eventTime, 'expires_at'=>$expiresAt,
                    'read_at'=>$sourceType==='moment_notice'
                        ? DB::table('moment_notification_inbox')->where('id',$sourceId)
                            ->where('recipient_user_id',$user)->value('read_at')
                        : null,
                ]);
            }
        }, 3);
        return $created;
    }

    public function page(string $owner, int $limit, ?string $cursor = null, ?string $category = null): array
    {
        $this->policy->assertReady();
        $this->syncLegacyMomentReads($owner,200);
        $limit = min(max($limit, 1), (int) config('orbit_notifications.max_page', 30));
        $start = null;
        if ($cursor !== null) {
            $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
            $parts = is_string($decoded) ? explode('|', $decoded) : [];
            abort_unless(count($parts) === 2 && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/D', $parts[0])
                && Str::isUuid($parts[1]), 422, 'Invalid notification cursor.');
            $start = $parts;
        }
        $query = DB::table('notification_inbox')->where('recipient_user_id', $owner)
            ->whereNull('invalidated_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at','>',now()));
        if ($category !== null) $query->where('category', $category);
        if ($start) $query->where(fn ($q) => $q->where('created_at','<',$start[0])
            ->orWhere(fn ($t) => $t->where('created_at', $start[0])->where('id','<',$start[1])));
        // A denied/revoked record does not end pagination. Scan a bounded
        // window and resume after the last row actually inspected.
        $window = min(1000, $limit * 10 + 1);
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')
            ->limit($window)->get();
        $out=[];$last=null;$scanned=0;
        foreach ($rows as $row) {
            $last=$row;
            $scanned++;
            if ($this->policy->canView($row, $owner)) $out[] = [
                'id'=>$row->id,'category'=>$row->category,'type'=>$row->type,
                'template_key'=>$row->template_key,'created_at'=>Carbon::parse($row->created_at)->toIso8601String(),
                'read'=>$row->read_at !== null,
                'title'=>(string)DB::table('notification_templates')->where('key',$row->template_key)->value('title'),
                'body'=>(string)DB::table('notification_templates')->where('key',$row->template_key)->value('body'),
                // Only a stable, non-identifying digest of the owning context.
                // Never expose Circle IDs or conversation membership in group keys.
                'group_key'=>in_array($row->category,['messages','circles'],true)
                    && $row->context_id
                        ? hash('sha256',$row->category.'|'.$row->context_id)
                        : null,
                'target'=>['kind'=>$row->category,'opaque_id'=>$row->target_id],
            ];
            if (count($out)>=$limit) break;
        }
        $more = ($scanned < $rows->count() || $rows->count() === $window);
        $next = $more && $last ? rtrim(strtr(base64_encode($last->created_at.'|'.$last->id), '+/', '-_'), '=') : null;
        return ['items'=>$out,'next_cursor'=>$next];
    }

    public function unread(string $user): int
    {
        $this->policy->assertReady();
        $this->syncLegacyMomentReads($user,200);
        $count=0;
        DB::table('notification_inbox')->where('recipient_user_id', $user)
            ->whereNull('read_at')->whereNull('invalidated_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at','>',now()))
            ->orderBy('id')->chunkById(200, function ($rows) use ($user, &$count): void {
                foreach ($rows as $row) if ($this->policy->canView($row, $user)) $count++;
            }, 'id');
        return $count;
    }

    public function markRead(string $user, string $notice): void
    {
        $this->policy->assertReady();
        $row=DB::table('notification_inbox')->where('id',$notice)
            ->where('recipient_user_id',$user)->first();
        abort_unless($row && $this->policy->canView($row, $user), 404, 'Notification unavailable.');
        DB::transaction(function () use ($user,$notice,$row):void {
            DB::table('notification_inbox')->where('id',$notice)
                ->where('recipient_user_id',$user)->whereNull('read_at')
                ->update(['read_at'=>now()]);
            if($row->category==='moments') {
                $source=DB::table('notification_events')->where('id',$row->event_id)->first();
                if($source && $source->source_type==='moment_notice')
                    DB::table('moment_notification_inbox')->where('id',$source->source_id)
                        ->where('recipient_user_id',$user)->whereNull('read_at')
                        ->update(['read_at'=>now()]);
            }
        },3);
    }

    public function readAll(string $user, ?string $category=null): int
    {
        $this->policy->assertReady();
        $query=DB::table('notification_inbox')->where('recipient_user_id',$user)
            ->whereNull('read_at')->whereNull('invalidated_at');
        if($category!==null)$query->where('category',$category);
        $updated=0;
        // Bounded operation; no unsafe server-wide bulk updates.
        foreach($query->orderByDesc('created_at')->limit(500)->get() as $row){
            if($this->policy->canView($row,$user)) {
                $this->markRead($user,$row->id);
                $updated++;
            }
        }
        return $updated;
    }

    /**
     * Existing M7 clients may mark Moment notifications read using the old
     * endpoint. Preserve that state in the shared inbox without duplicating
     * notice records or modifying the original owner's read timestamp.
     */
    /** Aggregates only; no user, Moment, audience or read timestamp leaves Admin. */
    public function legacyMomentReadAudit(): array
    {
        $result=[
            'linked_notices'=>0,'legacy_read_pending_sync'=>0,
            'shared_read_pending_legacy'=>0,
        ];
        if(!$this->policy->installed()
            || !\Illuminate\Support\Facades\Schema::hasTable('moment_notification_inbox')){
            return $result;
        }
        $base=DB::table('notification_inbox as n')
            ->join('notification_events as e','e.id','=','n.event_id')
            ->join('moment_notification_inbox as m',function($join){
                $join->on('m.id','=','e.source_id')
                    ->on('m.recipient_user_id','=','n.recipient_user_id');
            })
            ->where('e.source_type','moment_notice');
        $result['linked_notices']=(clone $base)->count();
        $result['legacy_read_pending_sync']=(clone $base)
            ->whereNotNull('m.read_at')->whereNull('n.read_at')->count();
        $result['shared_read_pending_legacy']=(clone $base)
            ->whereNull('m.read_at')->whereNotNull('n.read_at')->count();
        return $result;
    }

    public function syncLegacyMomentReads(string $owner,int $limit=500): int
    {
        if(!$this->policy->installed())return 0;
        $rows=DB::table('notification_inbox as n')
            ->join('notification_events as e','e.id','=','n.event_id')
            ->join('moment_notification_inbox as m','m.id','=','e.source_id')
            ->where('n.recipient_user_id',$owner)
            ->where('m.recipient_user_id',$owner)
            ->where('e.source_type','moment_notice')
            ->whereNull('n.read_at')->whereNotNull('m.read_at')
            ->limit(max(1,min(500,$limit)))->get(['n.id','m.read_at']);
        $updated=0;
        foreach($rows as $row) {
            $updated+=DB::table('notification_inbox')->where('id',$row->id)
                ->where('recipient_user_id',$owner)->whereNull('read_at')
                ->update(['read_at'=>$row->read_at]);
        }
        return $updated;
    }

    /**
     * Bounded irreversible purge after a separate seven-day invalidation
     * grace. Cascading FKs remove push/email jobs and provider-attempt
     * metadata. Existing M7 inbox is never touched by this cleanup.
     */
    public function purgeInvalidated(int $limit=500): array
    {
        $stats=['inbox'=>0,'events'=>0];
        if(!$this->policy->installed())return $stats;
        $ids=DB::table('notification_inbox')
            ->whereNotNull('invalidated_at')
            ->where('invalidated_at','<',now()->subDays(7))
            ->orderBy('invalidated_at')
            ->limit(max(1,min(500,$limit)))->pluck('id');
        if($ids->isNotEmpty()) $stats['inbox']=DB::table('notification_inbox')
            ->whereIn('id',$ids)->delete();

        // Event IDs do not contain payloads, but should not become permanent
        // history after all corresponding private recipients are purged.
        $events=DB::table('notification_events as e')
            ->where('e.created_at','<',now()->subDays(30))
            ->whereNotExists(fn($q)=>$q->selectRaw('1')
                ->from('notification_inbox as n')
                ->whereColumn('n.event_id','e.id'))
            ->orderBy('e.created_at')->limit(500)->pluck('e.id');
        if($events->isNotEmpty())$stats['events']=DB::table('notification_events')
            ->whereIn('id',$events)->delete();
        return $stats;
    }

    public function invalidateExpired(int $limit=500): int
    {
        if(!$this->policy->installed())return 0;
        $ttl=max(1,(int)config('orbit_notifications.retention_days',7));
        $cutoff=now()->subDays($ttl);
        // Invalidation first, then retention cleanup, not a destructive full reset.
        $expired=DB::table('notification_inbox')
            ->whereNull('invalidated_at')
            ->where(fn($q)=>$q->where('created_at','<',$cutoff)
                ->orWhere(fn($t)=>$t->whereNotNull('expires_at')->where('expires_at','<=',now())))
            ->orderBy('id')->limit(min($limit,1000))->pluck('id');
        return $expired->isEmpty()?0:DB::table('notification_inbox')
            ->whereIn('id',$expired)->update(['invalidated_at'=>now()]);
    }
}
