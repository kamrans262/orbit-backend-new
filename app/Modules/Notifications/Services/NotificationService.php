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
                            ?string $context = null, ?string $expiresAt = null): int
    {
        if (config('orbit_notifications.enabled') !== true || !$this->policy->installed()) return 0;
        if (!in_array($category, config('orbit_notifications.categories', []), true)
            || !Str::isUuid($sourceId) || !Str::isUuid($targetId)
            || !preg_match('/^[a-z_]{2,32}$/D', $sourceType)) return 0;
        $template = [
            'moments'=>'moments.available', 'pings'=>'pings.received',
            'check_ins'=>'check_ins.available', 'messages'=>'messages.available',
            'friends'=>'friends.updated', 'circles'=>'circles.updated',
        ][$category] ?? null;
        if ($template === null) return 0; // No unreviewed producer or broadcast.
        if (!DB::table('notification_templates')->where('key', $template)
            ->where('enabled', true)->exists()) return 0;
        $created = 0;
        // One event is created once, with an idempotent per-owner inbox entry.
        DB::transaction(function () use ($sourceType, $sourceId, $category, $type,
            $targetId, $recipients, $context, $expiresAt, $template, &$created): void {
            $eventId = (string) Str::uuid();
            DB::table('notification_events')->insertOrIgnore([
                'id'=>$eventId, 'source_type'=>$sourceType, 'source_id'=>$sourceId,
                'category'=>$category, 'type'=>$type, 'target_id'=>$targetId,
                'created_at'=>now(),
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
                    'created_at'=>now(), 'expires_at'=>$expiresAt,
                ]);
            }
        }, 3);
        return $created;
    }

    public function page(string $owner, int $limit, ?string $cursor = null, ?string $category = null): array
    {
        $this->policy->assertReady();
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
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')
            ->limit($limit * 5 + 1)->get();
        $out=[];$last=null;$more=false;
        foreach ($rows as $row) {
            $last=$row;
            if ($this->policy->canView($row, $owner)) $out[] = [
                'id'=>$row->id,'category'=>$row->category,'type'=>$row->type,
                'template_key'=>$row->template_key,'created_at'=>Carbon::parse($row->created_at)->toIso8601String(),
                'read'=>$row->read_at !== null,
                'title'=>(string)DB::table('notification_templates')->where('key',$row->template_key)->value('title'),
                'body'=>(string)DB::table('notification_templates')->where('key',$row->template_key)->value('body'),
                'target'=>['kind'=>$row->category,'opaque_id'=>$row->target_id],
            ];
            if (count($out)>=$limit) { $more=true;break; }
        }
        $next = $more && $last ? rtrim(strtr(base64_encode($last->created_at.'|'.$last->id), '+/', '-_'), '=') : null;
        return ['items'=>$out,'next_cursor'=>$next];
    }

    public function unread(string $user): int
    {
        $this->policy->assertReady();
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
        DB::table('notification_inbox')->where('id',$notice)
            ->where('recipient_user_id',$user)->whereNull('read_at')->update(['read_at'=>now()]);
    }

    public function readAll(string $user, ?string $category=null): int
    {
        $this->policy->assertReady();
        $query=DB::table('notification_inbox')->where('recipient_user_id',$user)
            ->whereNull('read_at')->whereNull('invalidated_at');
        if($category!==null)$query->where('category',$category);
        $updated=0;
        // Bounded operation; no unsafe, unbounded server-wide bulk updates.
        foreach($query->orderByDesc('created_at')->limit(500)->get() as $row){
            if($this->policy->canView($row,$user)) $updated += DB::table('notification_inbox')
                ->where('id',$row->id)->where('recipient_user_id',$user)
                ->whereNull('read_at')->update(['read_at'=>now()]);
        }
        return $updated;
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
