@php
  // Single extensible navigation registry. New modules add another group;
  // visibility is governed by the same RBAC permissions as its routes.
  $navUser = auth()->guard('admin')->user();
  $navGroups = [];

  if ($navUser?->canPerform('config.manage')) {
    $navGroups[] = [
      'title' => 'Configurations', 'icon' => 'settings',
      'patterns' => ['orbit.admin.dashboard','orbit.admin.flags.*','orbit.admin.config.*','orbit.admin.rollback','orbit.admin.home-dashboard.*','orbit.admin.presence.*','orbit.admin.location.*','orbit.admin.ping.*','orbit.admin.check-in.*'],
      'children' => [
        ['title'=>'Overview','route'=>'orbit.admin.dashboard','patterns'=>['orbit.admin.dashboard']],
        ['title'=>'Feature Flags','route'=>'orbit.admin.flags.index','patterns'=>['orbit.admin.flags.*']],
        ['title'=>'Remote Config','route'=>'orbit.admin.config.index','patterns'=>['orbit.admin.config.*']],
        // Admin module inspection is always available to config.manage, independently
        // of mobile runtime gates. No live data is exposed by either admin page.
        ['title'=>'Home Dashboard','route'=>'orbit.admin.home-dashboard.index',
          'patterns'=>['orbit.admin.home-dashboard.*']],
        ['title'=>'Presence & Activity','route'=>'orbit.admin.presence.index',
          'patterns'=>['orbit.admin.presence.*']],
        ['title'=>'Location Sharing','route'=>'orbit.admin.location.index',
          'patterns'=>['orbit.admin.location.*']],
        ['title'=>'Ping Operations','route'=>'orbit.admin.ping.index',
          'patterns'=>['orbit.admin.ping.*']],
        ['title'=>'Check-In Operations','route'=>'orbit.admin.check-in.index',
          'patterns'=>['orbit.admin.check-in.*']],
      ],
    ];
  }

  // Messaging is a first-class operations area, separate from general
  // configurations. Permissions match the existing Laravel route guards.
  $messagingChildren = [];
  if ($navUser?->canPerform('config.manage')) {
    $messagingChildren[] = [
      'title'=>'Messaging Overview','route'=>'orbit.admin.messaging.index',
      'patterns'=>['orbit.admin.messaging.index'],
    ];
    $messagingChildren[] = [
      'title'=>'Operations & Settings','route'=>'orbit.admin.messaging.settings',
      'patterns'=>['orbit.admin.messaging.settings','orbit.admin.messaging.settings.save',
        'orbit.admin.messaging.maintenance.*'],
    ];
  }
  if ($navUser?->canPerform('messages.moderate')) {
    $messagingChildren[] = [
      'title'=>'User Reports','route'=>'orbit.admin.messaging.reports',
      'patterns'=>['orbit.admin.messaging.reports*'],
    ];
    $messagingChildren[] = [
      'title'=>'Account Messaging Access',
      'route'=>'orbit.admin.messaging.restrictions',
      'patterns'=>['orbit.admin.messaging.restrictions*'],
    ];
  }
  if ($messagingChildren !== []) {
    $navGroups[] = [
      'title'=>'Messaging','icon'=>'messages',
      'patterns'=>['orbit.admin.messaging.*'],
      'children'=>$messagingChildren,
    ];
  }

  // Standalone Plans navigation, gated identically to admin routes.
  if ($navUser?->canPerform('plans.read')) {
    $planChildren = [
      ['title'=>'Overview','route'=>'orbit.admin.plans.overview','patterns'=>['orbit.admin.plans.overview']],
      ['title'=>'Plan Directory','route'=>'orbit.admin.plans.directory','patterns'=>['orbit.admin.plans.directory']],
      ['title'=>'Invitation & Attendance','route'=>'orbit.admin.plans.attendance','patterns'=>['orbit.admin.plans.attendance']],
      ['title'=>'Reminder Operations','route'=>'orbit.admin.plans.reminders','patterns'=>['orbit.admin.plans.reminders','orbit.admin.plans.retry']],
      ['title'=>'Settings','route'=>'orbit.admin.plans.settings','patterns'=>['orbit.admin.plans.settings*']],
    ];
    if ($navUser->canPerform('plans.audit')) {
      $planChildren[]=['title'=>'Audit Trail','route'=>'orbit.admin.plans.audits',
        'patterns'=>['orbit.admin.plans.audits']];
    }
    $navGroups[]=['title'=>'Plans','icon'=>'plans','patterns'=>['orbit.admin.plans.*'],
      'children'=>$planChildren];
  }

  // Dedicated Notifications operations group, independent from Accounts
  // and Configurations. Never show actions without notification RBAC.
  if ($navUser?->canPerform('notifications.read')) {
    $navGroups[] = [
      'title'=>'Notifications', 'icon'=>'notifications',
      'patterns'=>['orbit.admin.notifications.*'],
      'children'=>[
        ['title'=>'Overview','route'=>'orbit.admin.notifications.overview',
          'patterns'=>['orbit.admin.notifications.overview']],
        ['title'=>'Delivery Queue','route'=>'orbit.admin.notifications.jobs',
          'patterns'=>['orbit.admin.notifications.jobs','orbit.admin.notifications.retry']],
        ['title'=>'Push Devices','route'=>'orbit.admin.notifications.devices',
          'patterns'=>['orbit.admin.notifications.devices*']],
        ['title'=>'Templates','route'=>'orbit.admin.notifications.templates',
          'patterns'=>['orbit.admin.notifications.templates*']],
        ['title'=>'Settings & Controls','route'=>'orbit.admin.notifications.settings',
          'patterns'=>['orbit.admin.notifications.settings*']],
        ['title'=>'Audit Trail','route'=>'orbit.admin.notifications.audits',
          'patterns'=>['orbit.admin.notifications.audits']],
      ],
    ];
  }

  // Reuse the existing nav-group/notifications icon; Activity is not inside
  // Configurations or Accounts and receives independent server-side RBAC.
  if ($navUser?->canPerform('activity.read')) {
    $activityChildren = [
      ['title'=>'Overview','route'=>'orbit.admin.activity.overview',
        'patterns'=>['orbit.admin.activity.overview']],
      ['title'=>'Source Controls','route'=>'orbit.admin.activity.sources',
        'patterns'=>['orbit.admin.activity.sources','orbit.admin.activity.control']],
      ['title'=>'Projection Operations','route'=>'orbit.admin.activity.operations',
        'patterns'=>['orbit.admin.activity.operations','orbit.admin.activity.project']],
    ];
    if ($navUser->canPerform('activity.audit')) {
      $activityChildren[]=['title'=>'Audit Trail','route'=>'orbit.admin.activity.audits',
        'patterns'=>['orbit.admin.activity.audits']];
    }
    $navGroups[]=['title'=>'Activity Feed','icon'=>'notifications',
      'patterns'=>['orbit.admin.activity.*'],'children'=>$activityChildren];
  }

  // M17 uses the already approved sidebar and icon partial; it is separate
  // from Accounts, Messaging and Configurations, with no new visual system.
  if ($navUser?->canPerform('moderation.read')) {
    $moderationChildren = [
      ['title'=>'Overview','route'=>'orbit.admin.moderation.overview',
        'patterns'=>['orbit.admin.moderation.overview']],
      ['title'=>'Report Queue','route'=>'orbit.admin.moderation.queue',
        'patterns'=>['orbit.admin.moderation.queue']],
      ['title'=>'Cases & Investigations','route'=>'orbit.admin.moderation.cases',
        'patterns'=>['orbit.admin.moderation.cases','orbit.admin.moderation.cases.*']],
      ['title'=>'Enforcement Actions','route'=>'orbit.admin.moderation.actions',
        'patterns'=>['orbit.admin.moderation.actions']],
      ['title'=>'Appeals','route'=>'orbit.admin.moderation.appeals',
        'patterns'=>['orbit.admin.moderation.appeals','orbit.admin.moderation.appeals.*']],
      ['title'=>'Settings','route'=>'orbit.admin.moderation.settings',
        'patterns'=>['orbit.admin.moderation.settings*']],
    ];
    if ($navUser->canPerform('moderation.audit')) {
      $moderationChildren[]=['title'=>'Audit Trail','route'=>'orbit.admin.moderation.audits',
        'patterns'=>['orbit.admin.moderation.audits']];
    }
    $navGroups[]=['title'=>'Moderation & Reporting','icon'=>'messages',
      'patterns'=>['orbit.admin.moderation.*'],'children'=>$moderationChildren];
  }

  // M16 Safety: standalone first-class emergency operations, never nested
  // in Accounts/Configurations and never displaying private coordinates.
  if ($navUser?->canPerform('safety.read')) {
    $safetyChildren = [
      ['title'=>'Overview','route'=>'orbit.admin.safety.overview','patterns'=>['orbit.admin.safety.overview']],
      ['title'=>'Incident Directory','route'=>'orbit.admin.safety.incidents','patterns'=>['orbit.admin.safety.incidents']],
      ['title'=>'Responders','route'=>'orbit.admin.safety.responders','patterns'=>['orbit.admin.safety.responders']],
      ['title'=>'Delivery Operations','route'=>'orbit.admin.safety.delivery','patterns'=>['orbit.admin.safety.delivery','orbit.admin.safety.retry','orbit.admin.safety.run']],
      ['title'=>'Settings','route'=>'orbit.admin.safety.settings','patterns'=>['orbit.admin.safety.settings*']],
    ];
    if ($navUser->canPerform('safety.audit')) {
      $safetyChildren[]=['title'=>'Audit Trail','route'=>'orbit.admin.safety.audits',
        'patterns'=>['orbit.admin.safety.audits']];
    }
    $navGroups[]=['title'=>'SOS & Safety','icon'=>'sos',
      'patterns'=>['orbit.admin.safety.*'],'children'=>$safetyChildren];
  }

  $accountChildren = [];
  if ($navUser?->canPerform('users.read')) {
    $accountChildren[] = ['title'=>'Users & Devices','route'=>'orbit.admin.users.index','patterns'=>['orbit.admin.users.*']];
  }
  if ($navUser?->canPerform('deletions.read')) {
    $accountChildren[] = ['title'=>'Deletion Requests','route'=>'orbit.admin.deletions.index','patterns'=>['orbit.admin.deletions.*']];
  }
  if ($navUser?->canPerform('settings.audit.read')) {
    $accountChildren[] = ['title'=>'Settings Audit','route'=>'orbit.admin.settings.activity','patterns'=>['orbit.admin.settings.*']];
  }
  if ($navUser?->canPerform('social.read')) {
    $accountChildren[] = ['title'=>'Friends & Blocking','route'=>'orbit.admin.social.index','patterns'=>['orbit.admin.social.*']];
  }
  if (config('orbit_circle.enabled') === true && $navUser?->canPerform('circles.read')) {
    $accountChildren[] = ['title'=>'Circle Management','route'=>'orbit.admin.circles.index','patterns'=>['orbit.admin.circles.*']];
  }
  if (config('orbit_media.enabled') === true && $navUser?->canPerform('media.read')) {
    $accountChildren[] = ['title'=>'Encrypted Media Operations','route'=>'orbit.admin.media.index','patterns'=>['orbit.admin.media.*']];
  }
  if (config('orbit_moments.enabled') === true && $navUser?->canPerform('moments.read')) {
    $accountChildren[] = ['title'=>'Moment Management','route'=>'orbit.admin.moments.index','patterns'=>['orbit.admin.moments.*']];
  }
  if ($accountChildren !== []) {
    $navGroups[] = [
      'title'=>'Accounts', 'icon'=>'users',
      'patterns'=>['orbit.admin.users.*','orbit.admin.deletions.*','orbit.admin.settings.*','orbit.admin.social.*','orbit.admin.circles.*','orbit.admin.media.*','orbit.admin.moments.*'],
      'children'=>$accountChildren,
    ];
  }
@endphp

@foreach($navGroups as $section)
  @php $sectionActive = request()->routeIs(...$section['patterns']); @endphp
  <details class="nav-group" @if($sectionActive) open @endif>
    <summary class="nav-item nav-parent {{ $sectionActive ? 'active' : '' }}">
      <span class="nav-icon" aria-hidden="true">@include('orbit-admin.partials.icon', ['name'=>$section['icon']])</span>
      <span class="nav-parent-label">{{ $section['title'] }}</span>
      <span class="nav-chevron" aria-hidden="true">@include('orbit-admin.partials.icon', ['name'=>'chevron'])</span>
    </summary>
    <div class="nav-submenu" aria-label="{{ $section['title'] }} subsections">
      @foreach($section['children'] as $subsection)
        @php $childActive = request()->routeIs(...$subsection['patterns']); @endphp
        <a href="{{ route($subsection['route']) }}"
          class="nav-subitem {{ $childActive ? 'active' : '' }}"
          @if($childActive) aria-current="page" @endif>
          <span class="nav-subitem-dot" aria-hidden="true"></span>
          <span>{{ $subsection['title'] }}</span>
        </a>
      @endforeach
    </div>
  </details>
@endforeach
