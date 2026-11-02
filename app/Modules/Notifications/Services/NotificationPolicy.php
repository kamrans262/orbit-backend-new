<?php

namespace App\Modules\Notifications\Services;

use App\Modules\CheckIn\Services\CheckInAccessPolicy;
use App\Modules\Messaging\Services\MessagingAccessPolicy;
use App\Modules\Moments\Services\MomentPolicy;
use App\Modules\Ping\Services\PingAccessPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class NotificationPolicy
{
    public function installed(): bool
    {
        return Schema::hasTable('notification_events') &&
            Schema::hasTable('notification_inbox') &&
            Schema::hasTable('notification_device_tokens') &&
            Schema::hasTable('notification_jobs') &&
            Schema::hasTable('notification_controls');
    }

    public function assertReady(): void
    {
        abort_unless(config('orbit_notifications.enabled') === true, 503, 'Notifications unavailable.');
        abort_unless($this->installed(), 503, 'Notification schema not provisioned.');
    }

    public function actor(Request $request): array
    {
        $this->assertReady();
        $session = $request->attributes->get('orbit_session');
        abort_unless($session, 401);
        $user = (string) $session->user_id;
        $device = (string) $session->device_id;
        $active = DB::table('orbit_users')->where('id', $user)
            ->where('status', 'active')->whereNull('deletion_requested_at')->exists();
        $trusted = DB::table('devices')->where('id', $device)->where('user_id', $user)
            ->where('trust_state', 'trusted')->whereNotNull('approved_at')
            ->whereNull('revoked_at')->exists();
        abort_unless($active && $trusted, 403, 'Trusted active session required.');
        return [$user, $device];
    }

    public function enabled(string $key, bool $default = true): bool
    {
        if (!$this->installed()) return false;
        $value = DB::table('notification_controls')->where('key', $key)->value('enabled');
        return $value === null ? $default : (bool) $value;
    }

    public function preference(string $user, string $key, bool $default = false): bool
    {
        $raw = DB::table('orbit_user_preferences')->where('user_id', $user)->value('notifications');
        $pref = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($pref) ? (($pref[$key] ?? $default) === true) : $default;
    }

    public function categoryEnabled(string $user, string $category, bool $push = false): bool
    {
        if (!$this->enabled('channel.'.($push?'push':'in_app')))return false;
        if (!in_array($category, config('orbit_notifications.categories', []), true)
            || !$this->enabled('category.'.$category)) return false;
        if ($push && !$this->preference($user, 'push_notifications_enabled')) return false;
        return match ($category) {
            'moments' => $this->preference($user, 'moments_notifications_enabled'),
            'pings' => $this->preference($user, 'ping_notifications_enabled'),
            'circles', 'check_ins' => $this->preference($user, 'circle_updates_enabled'),
            // Existing seven-key M3 preferences have no separate message or
            // friend switches; a new preference must be designed in Stage 3.
            'messages', 'friends', 'security' => true,
            default => false,
        };
    }

    public function targetAllowed(string $category, string $target, string $user,
                                  ?string $context = null): bool
    {
        if (!$this->categoryEnabled($user, $category)) return false;
        if ($context && str_starts_with($context, 'circle:')) {
            $circle = substr($context, 7);
            if (DB::table('notification_context_mutes')
                ->where('user_id', $user)->where('context_type', 'circle')
                ->where('context_id', $circle)->where('category', $category)->exists()) return false;
        }
        if ($category === 'moments' && $context && str_starts_with($context,'circle:')
            && \Illuminate\Support\Facades\Schema::hasTable('moment_notification_muted_circles')
            && DB::table('moment_notification_muted_circles')
                ->where('user_id',$user)->where('circle_id',substr($context,7))->exists())
            return false;
        if ($category === 'moments') {
            $notice = DB::table('moments')->where('id', $target)->first();
            return $notice !== null
                && app(MomentPolicy::class)->context($notice, $user, $context) !== null;
        }
        if ($category === 'pings') {
            $row = DB::table('pings')->where('id', $target)
                ->where('recipient_id', $user)->first();
            return $row !== null && app(PingAccessPolicy::class)->allows(
                (string) $row->sender_id, $user, (string) $row->context_type,
                $row->context_id ? (string) $row->context_id : null);
        }
        if ($category === 'check_ins') {
            $row = DB::table('checkins')->where('id', $target)->first();
            return $row !== null && $row->creator_id !== $user
                && app(CheckInAccessPolicy::class)->canRead($row, $user);
        }
        if ($category === 'messages') {
            $row = DB::table('messaging_messages')->where('id', $target)
                ->whereNull('redacted_at')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at','>',now()))
                ->first();
            if (!$row || $row->sender_id === $user) return false;
            $conversation = DB::table('messaging_conversations')
                ->where('id', $row->conversation_id)->first();
            if (!$conversation) return false;
            $hasEnvelope = DB::table('messaging_envelopes as e')
                ->join('devices as d', 'd.id', '=', 'e.recipient_device_id')
                ->where('e.message_id', $target)->where('d.user_id', $user)
                ->whereNull('d.revoked_at')->exists();
            if (!$hasEnvelope) return false;
            $access = app(MessagingAccessPolicy::class);
            if ($conversation->kind === 'direct') {
                return $access->canDirect($user, (string) $row->sender_id)
                    && DB::table('messaging_members')->where('conversation_id', $row->conversation_id)
                    ->where('user_id', $user)->whereNull('left_at')->exists();
            }
            return $conversation->kind === 'circle' && $conversation->circle_id
                && $access->canCirclePair((string) $conversation->circle_id,
                    (string) $row->sender_id, $user);
        }
        // Other categories are available only when an audited producer/policy
        // is added, never inferred from a guessed client supplied object ID.
        return false;
    }

    public function canView(object $notice, string $user): bool
    {
        if ($notice->recipient_user_id !== $user || $notice->invalidated_at
            || ($notice->expires_at && now()->gte($notice->expires_at))) return false;
        $template = DB::table('notification_templates')
            ->where('key', $notice->template_key)->first();
        return $template && $template->enabled
            && $this->targetAllowed((string) $notice->category,
                (string) $notice->target_id, $user, $notice->context_id);
    }
}
