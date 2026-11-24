<?php

namespace App\Modules\Config\Services;

use App\Models\AdminUser;
use Illuminate\Support\Facades\Route;

/**
 * Existing Admin routes only. Presentation and post-MFA landing share this
 * registry; route middleware and module policies remain authoritative.
 */
final class AdminNavigation
{
    public static function groups(?AdminUser $user): array
    {
        $groups = [];
        $allowed = [];
        foreach (self::definitions() as [$title,$icon,$entries]) {
            $children = [];
            foreach ($entries as [$label,$route,$permission,$patterns]) {
                $name = 'orbit.admin.'.$route;
                $allowed[$permission] ??= $user?->canPerform($permission) ?? false;
                if (!$allowed[$permission] || !Route::has($name)) continue;
                $children[] = ['title'=>$label, 'route'=>$name,
                    'patterns'=>array_map(fn ($pattern) => 'orbit.admin.'.$pattern, $patterns)];
            }
            if ($children === []) continue;
            $groups[] = ['title'=>$title,'icon'=>$icon,'children'=>$children,
                'patterns'=>array_values(array_unique(array_merge(...array_column($children,'patterns'))))];
        }
        return $groups;
    }

    public static function workspace(?AdminUser $user, string $prefix): array
    {
        foreach (self::groups($user) as $group) {
            foreach ($group['children'] as $link) {
                if (str_starts_with($link['route'], 'orbit.admin.'.$prefix.'.')) {
                    return $group['children'];
                }
            }
        }
        return [];
    }

    public static function firstRoute(?AdminUser $user): ?string
    {
        return self::groups($user)[0]['children'][0]['route'] ?? null;
    }

    private static function definitions(): array
    {
        return [
            ['Configurations', 'settings', [
                ['Overview', 'dashboard', 'config.manage', ['dashboard']],
                ['Feature Flags', 'flags.index', 'config.manage', ['flags.*']],
                ['Remote Config', 'config.index', 'config.manage', ['config.*', 'rollback']],
            ]],
            ['Administrators & Access', 'users', [
                ['Administrators & Roles', 'administrators.index', '*', ['administrators.*']],
            ]],
            ['Accounts', 'users', [
                ['Users & Devices', 'users.index', 'users.read', ['users.*']],
                ['Settings & Controls', 'operations.identity', 'users.read', ['operations.identity*']],
                ['Deletion Requests', 'deletions.index', 'deletions.read', ['deletions.*']],
                ['Settings Audit', 'settings.activity', 'settings.audit.read', ['settings.*']],
            ]],
            ['Friends & Blocking', 'users', [
                ['Operations', 'social.index', 'social.read', ['social.*']],
                ['Settings & Controls', 'operations.social', 'social.read', ['operations.social*']],
            ]],
            ['Circles', 'circles', [
                ['Circle Management', 'circles.index', 'circles.read', ['circles.*']],
                ['Settings & Controls', 'operations.circles', 'circles.read', ['operations.circles*']],
            ]],
            ['Encrypted Media', 'health', [
                ['Media Operations', 'media.index', 'media.read', ['media.*']],
                ['Settings & Controls', 'operations.media', 'media.read', ['operations.media*']],
            ]],
            ['Moments', 'circles', [
                ['Moment Management', 'moments.index', 'moments.read', ['moments.*']],
                ['Settings & Controls', 'operations.moments', 'moments.read', ['operations.moments*']],
            ]],
            ['Camera & Editing', 'settings', [
                ['Settings & Controls', 'camera.index', 'camera.read', ['camera.*']],
            ]],
            ['Home Dashboard', 'settings', [
                ['Home Dashboard', 'home-dashboard.index', 'config.manage', ['home-dashboard.*']],
                ['Settings & Controls', 'operations.dashboard', 'config.manage', ['operations.dashboard*']],
            ]],
            ['Presence', 'health', [
                ['Presence', 'presence.index', 'config.manage', ['presence.*']],
                ['Settings & Controls', 'operations.presence', 'config.manage', ['operations.presence*']],
            ]],
            ['Location Sharing', 'circles', [
                ['Location Sharing', 'location.index', 'config.manage', ['location.*']],
                ['Settings & Controls', 'operations.location', 'config.manage', ['operations.location*']],
            ]],
            ['Ping', 'notifications', [
                ['Ping Operations', 'ping.index', 'config.manage', ['ping.*']],
                ['Settings & Controls', 'operations.ping', 'config.manage', ['operations.ping*']],
            ]],
            ['Check-In', 'plans', [
                ['Check-In Operations', 'check-in.index', 'config.manage', ['check-in.*']],
                ['Settings & Controls', 'operations.check-in', 'config.manage', ['operations.check-in*']],
            ]],
            ['Messaging', 'messages', [
                ['Messaging Overview', 'messaging.index', 'config.manage', ['messaging.index']],
                ['Operations & Settings', 'messaging.settings', 'config.manage', ['messaging.settings', 'messaging.settings.save', 'messaging.maintenance.*']],
                ['Operational Limits', 'operations.messaging', 'config.manage', ['operations.messaging*']],
                ['User Reports', 'messaging.reports', 'messages.moderate', ['messaging.reports*']],
                ['Account Messaging Access', 'messaging.restrictions', 'messages.moderate', ['messaging.restrictions*']],
            ]],
            ['Notifications', 'notifications', [
                ['Overview', 'notifications.overview', 'notifications.read', ['notifications.overview']],
                ['Delivery Queue', 'notifications.jobs', 'notifications.read', ['notifications.jobs', 'notifications.retry']],
                ['Push Devices', 'notifications.devices', 'notifications.read', ['notifications.devices*']],
                ['Templates', 'notifications.templates', 'notifications.read', ['notifications.templates*']],
                ['Settings & Controls', 'notifications.settings', 'notifications.read', ['notifications.settings*']],
                ['Operational Limits', 'operations.notifications', 'notifications.read', ['operations.notifications*']],
                ['Audit Trail', 'notifications.audits', 'notifications.read', ['notifications.audits']],
            ]],
            ['Plans', 'plans', [
                ['Overview', 'plans.overview', 'plans.read', ['plans.overview']],
                ['Plan Directory', 'plans.directory', 'plans.read', ['plans.directory', 'plans.cancel']],
                ['Invitation & Attendance', 'plans.attendance', 'plans.read', ['plans.attendance']],
                ['Reminder Operations', 'plans.reminders', 'plans.read', ['plans.reminders', 'plans.retry', 'plans.process']],
                ['Settings', 'plans.settings', 'plans.read', ['plans.settings*']],
                ['Operational Limits', 'operations.plans', 'plans.read', ['operations.plans*']],
                ['Audit Trail', 'plans.audits', 'plans.audit', ['plans.audits']],
            ]],
            ['SOS & Safety', 'sos', [
                ['Overview', 'safety.overview', 'safety.read', ['safety.overview']],
                ['Incident Directory', 'safety.incidents', 'safety.read', ['safety.incidents']],
                ['Responders', 'safety.responders', 'safety.read', ['safety.responders']],
                ['Delivery Operations', 'safety.delivery', 'safety.read', ['safety.delivery', 'safety.retry', 'safety.run']],
                ['Settings', 'safety.settings', 'safety.read', ['safety.settings*']],
                ['Audit Trail', 'safety.audits', 'safety.audit', ['safety.audits']],
            ]],
            ['Moderation & Reporting', 'messages', [
                ['Overview', 'moderation.overview', 'moderation.read', ['moderation.overview']],
                ['Report Queue', 'moderation.queue', 'moderation.read', ['moderation.queue']],
                ['Cases & Investigations', 'moderation.cases', 'moderation.read', ['moderation.cases', 'moderation.cases.*']],
                ['Enforcement Actions', 'moderation.actions', 'moderation.read', ['moderation.actions']],
                ['Appeals', 'moderation.appeals', 'moderation.read', ['moderation.appeals', 'moderation.appeals.*']],
                ['Settings', 'moderation.settings', 'moderation.read', ['moderation.settings*', 'moderation.sync']],
                ['Audit Trail', 'moderation.audits', 'moderation.audit', ['moderation.audits']],
            ]],
            ['Activity Feed', 'notifications', [
                ['Overview', 'activity.overview', 'activity.read', ['activity.overview']],
                ['Source Controls', 'activity.sources', 'activity.read', ['activity.sources', 'activity.control']],
                ['Operational Limits', 'operations.activity', 'activity.read', ['operations.activity*']],
                ['Projection Operations', 'activity.operations', 'activity.read', ['activity.operations', 'activity.project']],
                ['Audit Trail', 'activity.audits', 'activity.audit', ['activity.audits']],
            ]],
            ['Orbit Relay', 'messages', [
                ['Overview', 'relay.overview', 'relay.read', ['relay.overview']],
                ['Settings & Controls', 'relay.settings', 'relay.read', ['relay.settings*']],
                ['Audit Trail', 'relay.audits', 'relay.audit', ['relay.audits']],
            ]],
            ['Subscriptions & Billing', 'settings', [
                ['Plans & Products', 'billing.products', 'billing.read', ['billing.products*']],
                ['Subscriptions', 'billing.subscriptions', 'billing.read', ['billing.subscriptions']],
                ['Billing Operations', 'billing.operations', 'billing.read', ['billing.operations','billing.events.*']],
            ]],
            ['Advertisements & Sponsorships', 'notifications', [
                ['Overview', 'advertising.overview', 'advertising.read', ['advertising.overview']],
                ['Sponsors', 'advertising.sponsors', 'advertising.read', ['advertising.sponsors*']],
                ['Creatives & Review', 'advertising.creatives', 'advertising.read', ['advertising.creatives*']],
                ['Campaigns', 'advertising.campaigns', 'advertising.read', ['advertising.campaigns*']],
                ['Placements & Frequency', 'advertising.placements', 'advertising.read', ['advertising.placements*']],
                ['Operational Limits', 'operations.advertising', 'advertising.read', ['operations.advertising*']],
                ['Analytics', 'advertising.analytics', 'advertising.analytics', ['advertising.analytics']],
                ['Audit Trail', 'advertising.audits', 'advertising.audit', ['advertising.audits']],
            ]],
        ];
    }
}
