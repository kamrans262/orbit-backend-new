@php
  // Single extensible navigation registry. New modules add another group;
  // visibility is governed by the same RBAC permissions as its routes.
  $navUser = auth()->guard('admin')->user();
  $navGroups = [];

  if ($navUser?->canPerform('config.manage')) {
    $navGroups[] = [
      'title' => 'Configurations', 'icon' => 'settings',
      'patterns' => ['orbit.admin.dashboard','orbit.admin.flags.*','orbit.admin.config.*','orbit.admin.rollback','orbit.admin.location.*','orbit.admin.ping.*'],
      'children' => [
        ['title'=>'Overview','route'=>'orbit.admin.dashboard','patterns'=>['orbit.admin.dashboard']],
        ['title'=>'Feature Flags','route'=>'orbit.admin.flags.index','patterns'=>['orbit.admin.flags.*']],
        ['title'=>'Remote Config','route'=>'orbit.admin.config.index','patterns'=>['orbit.admin.config.*']],
        // Home and Presence are diagnostic-only and retain permission-protected URLs.
        ['title'=>'Location Sharing','route'=>'orbit.admin.location.index',
          'patterns'=>['orbit.admin.location.*']],
        ['title'=>'Ping Operations','route'=>'orbit.admin.ping.index',
          'patterns'=>['orbit.admin.ping.*']],
      ],
    ];
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
