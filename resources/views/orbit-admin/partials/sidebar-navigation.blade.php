@php
  $navUser = auth()->guard('admin')->user();
  $navGroups = [];
  if($navUser?->canPerform('config.manage')) {
    $navGroups[] = [
      'title' => 'Configurations', 'icon' => '⚙',
      'patterns' => ['orbit.admin.dashboard', 'orbit.admin.flags.*', 'orbit.admin.config.*', 'orbit.admin.rollback'],
      'children' => [
        ['title' => 'Overview', 'route' => 'orbit.admin.dashboard', 'patterns' => ['orbit.admin.dashboard']],
        ['title' => 'Feature Flags', 'route' => 'orbit.admin.flags.index', 'patterns' => ['orbit.admin.flags.*']],
        ['title' => 'Remote Config', 'route' => 'orbit.admin.config.index', 'patterns' => ['orbit.admin.config.*']],
      ],
    ];
  }
  $accountChildren = [];
  if($navUser?->canPerform('users.read')) {
    $accountChildren[] = ['title'=>'Users & Devices', 'route'=>'orbit.admin.users.index', 'patterns'=>['orbit.admin.users.*']];
  }
  if($navUser?->canPerform('deletions.read')) {
    $accountChildren[] = ['title'=>'Deletion Requests', 'route'=>'orbit.admin.deletions.index', 'patterns'=>['orbit.admin.deletions.*']];
  }
  if($navUser?->canPerform('settings.audit.read')) {
    $accountChildren[] = ['title'=>'Settings Audit', 'route'=>'orbit.admin.settings.activity', 'patterns'=>['orbit.admin.settings.*']];
  }
  if(count($accountChildren)) {
    $navGroups[] = [
      'title'=>'Accounts', 'icon'=>'◎',
      'patterns'=>['orbit.admin.users.*','orbit.admin.deletions.*','orbit.admin.settings.*'],
      'children'=>$accountChildren,
    ];
  }
@endphp
@foreach($navGroups as $section)
<details class="nav-group" @if(request()->routeIs(...$section['patterns'])) open @endif>
  <summary class="nav-item nav-parent {{ request()->routeIs(...$section['patterns']) ? 'active' : '' }}">
    <span aria-hidden="true">{{ $section['icon'] }}</span>{{ $section['title'] }}
    <span class="nav-chevron" aria-hidden="true">⌄</span>
  </summary>
  <div class="nav-submenu" aria-label="{{ $section['title'] }} subsections">
    @foreach($section['children'] as $subsection)
      <a class="nav-subitem {{ request()->routeIs(...$subsection['patterns']) ? 'active' : '' }}"
         href="{{ route($subsection['route']) }}"
         @if(request()->routeIs(...$subsection['patterns'])) aria-current="page" @endif>
         <span class="nav-subitem-dot" aria-hidden="true"></span>{{ $subsection['title'] }}
      </a>
    @endforeach
  </div>
</details>
@endforeach
