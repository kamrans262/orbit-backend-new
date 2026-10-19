@php
  // Single extensible navigation registry. New modules add another group;
  // visibility is governed by the same RBAC permissions as its routes.
  $navUser = auth()->guard('admin')->user();
  $navGroups = [];

  if ($navUser?->canPerform('config.manage')) {
    $navGroups[] = [
      'title' => 'Configurations', 'icon' => 'settings',
      'patterns' => ['orbit.admin.dashboard','orbit.admin.flags.*','orbit.admin.config.*','orbit.admin.rollback'],
      'children' => [
        ['title'=>'Overview','route'=>'orbit.admin.dashboard','patterns'=>['orbit.admin.dashboard']],
        ['title'=>'Feature Flags','route'=>'orbit.admin.flags.index','patterns'=>['orbit.admin.flags.*']],
        ['title'=>'Remote Config','route'=>'orbit.admin.config.index','patterns'=>['orbit.admin.config.*']],
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
  if ($accountChildren !== []) {
    $navGroups[] = [
      'title'=>'Accounts', 'icon'=>'users',
      'patterns'=>['orbit.admin.users.*','orbit.admin.deletions.*','orbit.admin.settings.*'],
      'children'=>$accountChildren,
    ];
  }
@endphp

@foreach($navGroups as $section)
  @php $sectionActive = request()->routeIs(...$section['patterns']); @endphp
  <details class="nav-group" @if($sectionActive) open @endif>
    <summary class="nav-item nav-parent {{ $sectionActive ? 'active' : '' }}">
      <span class="nav-icon" aria-hidden="true">
        @if($section['icon'] === 'settings')
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8.3a3.7 3.7 0 1 0 0 7.4 3.7 3.7 0 0 0 0-7.4Z"/><path d="M19.4 13.7a1.7 1.7 0 0 0 .33 1.88l.06.06-1.75 3.02-.08-.02a1.7 1.7 0 0 0-1.8.66L16.1 19.4H7.9l-.05-.1a1.7 1.7 0 0 0-1.8-.66l-.08.02-1.75-3.02.06-.06a1.7 1.7 0 0 0 .33-1.88l-.05-.09v-3.2l.05-.1a1.7 1.7 0 0 0-.33-1.88l-.06-.06 1.75-3.02.08.02a1.7 1.7 0 0 0 1.8-.66L7.9 4.6h8.2l.05.1a1.7 1.7 0 0 0 1.8.66l.08-.02 1.75 3.02-.06.06a1.7 1.7 0 0 0-.33 1.88l.05.09v3.2Z"/></svg>
        @else
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 20v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 20v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        @endif
      </span>
      <span class="nav-parent-label">{{ $section['title'] }}</span>
      <span class="nav-chevron" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
      </span>
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
