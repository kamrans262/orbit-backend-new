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
        foreach (self::definitions() as [$title,$icon,$entries]) {
            $children = [];
            foreach ($entries as [$label,$route,$permission,$patterns]) {
                $name = 'orbit.admin.'.$route;
                if (!$user?->canPerform($permission) || !Route::has($name)) continue;
                $children[] = ['title'=>$label, 'route'=>$name,
                    'patterns'=>array_map(fn ($pattern) => 'orbit.admin.'.$pattern, $patterns)];
            }
            if ($children === []) continue;
            $groups[] = ['title'=>$title,'icon'=>$icon,'children'=>$children,
                'patterns'=>array_values(array_unique(array_merge(...array_column($children,'patterns'))))];
        }
        return $groups;
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
            ['Accounts', 'users', [
                ['Users & Devices', 'users.index', 'users.read', ['users.*']],
                ['Deletion Requests', 'deletions.index', 'deletions.read', ['deletions.*']],
                ['Settings Audit', 'settings.activity', 'settings.audit.read', ['settings.*']],
            ]],
            ['Friends & Blocking', 'users', [
                ['Operations', 'social.index', 'social.read', ['social.*']],
            ]],
            ['Circles', 'circles', [
                ['Circle Management', 'circles.index', 'circles.read', ['circles.*']],
            ]],
            ['Encrypted Media', 'health', [
                ['Media Operations', 'media.index', 'media.read', ['media.*']],
            ]],
            ['Moments', 'circles', [
                ['Moment Management', 'moments.index', 'moments.read', ['moments.*']],
            ]],
            ['Home Dashboard', 'settings', [
                ['Home Dashboard', 'home-dashboard.index', 'config.manage', ['home-dashboard.*']],
            ]],
            ['Presence', 'health', [
                ['Presence', 'presence.index', 'config.manage', ['presence.*']],
            ]],
            ['Location Sharing', 'circles', [
                ['Location Sharing', 'location.index', 'config.manage', ['location.*']],
            ]],
            ['Ping', 'notifications', [
                ['Ping Operations', 'ping.index', 'config.manage', ['ping.*']],
            ]],
            ['Check-In', 'plans', [
                ['Check-In Operations', 'check-in.index', 'config.manage', ['check-in.*']],
            ]],
            ['Messaging', 'messages', [
                ['Messaging Overview', 'messaging.index', 'config.manage', ['messaging.index']],
                ['Operations & Settings', 'messaging.settings', 'config.manage', ['messaging.settings', 'messaging.settings.save', 'messaging.maintenance.*']],
                ['User Reports', 'messaging.reports', 'messages.moderate', ['messaging.reports*']],
                ['Account Messaging Access', 'messaging.restrictions', 'messages.moderate', ['messaging.restrictions*']],
            ]],
            ['Notifications', 'notifications', [
                ['Overview', 'notifications.overview', 'notifications.read', ['notifications.overview']],
                ['Delivery Queue', 'notifications.jobs', 'notifications.read', ['notifications.jobs', 'notifications.retry']],
                ['Push Devices', 'notifications.devices', 'notifications.read', ['notifications.devices*']],
                ['Templates', 'notifications.templates', 'notifications.read', ['notifications.templates*']],
                ['Settings & Controls', 'notifications.settings', 'notifications.read', ['notifications.settings*']],
                ['Audit Trail', 'notifications.audits', 'notifications.read', ['notifications.audits']],
            ]],
            ['Plans', 'plans', [
                ['Overview', 'plans.overview', 'plans.read', ['plans.overview']],
                ['Plan Directory', 'plans.directory', 'plans.read', ['plans.directory', 'plans.cancel']],
                ['Invitation & Attendance', 'plans.attendance', 'plans.read', ['plans.attendance']],
                ['Reminder Operations', 'plans.reminders', 'plans.read', ['plans.reminders', 'plans.retry', 'plans.process']],
                ['Settings', 'plans.settings', 'plans.read', ['plans.settings*']],
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
                ['Projection Operations', 'activity.operations', 'activity.read', ['activity.operations', 'activity.project']],
                ['Audit Trail', 'activity.audits', 'activity.audit', ['activity.audits']],
            ]],
            ['Advertisements & Sponsorships', 'notifications', [
                ['Overview', 'advertising.overview', 'advertising.read', ['advertising.overview']],
                ['Sponsors', 'advertising.sponsors', 'advertising.read', ['advertising.sponsors*']],
                ['Creatives & Review', 'advertising.creatives', 'advertising.read', ['advertising.creatives*']],
                ['Campaigns', 'advertising.campaigns', 'advertising.read', ['advertising.campaigns*']],
                ['Placements & Frequency', 'advertising.placements', 'advertising.read', ['advertising.placements*']],
                ['Analytics', 'advertising.analytics', 'advertising.analytics', ['advertising.analytics']],
                ['Audit Trail', 'advertising.audits', 'advertising.audit', ['advertising.audits']],
            ]],
        ];
    }
}
