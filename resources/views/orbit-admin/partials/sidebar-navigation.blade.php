@php
  $navGroups = \App\Modules\Config\Services\AdminNavigation::groups(auth()->guard('admin')->user());
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
