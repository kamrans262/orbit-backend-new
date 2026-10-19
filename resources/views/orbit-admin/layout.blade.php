<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', 'Administration') · Orbit</title>
<link rel="stylesheet" href="{{ route('orbit.admin.asset', ['file' => 'admin.css', 'v' => filemtime(public_path('assets/orbit-admin/admin.css'))]) }}">
</head>
<body>
<div class="shell" data-shell>
  <aside class="sidebar" data-sidebar aria-label="Admin navigation" id="orbit-admin-sidebar">
    <div class="sidebar-brand">
      <a href="{{ route('orbit.admin.landing') }}" class="brand">
        <img class="brand-logo" src="{{ route('orbit.admin.asset', ['file' => 'orbit-logo.png']) }}" width="44" height="44" alt="Orbit logo">
        <span>Orbit<small>ADMINISTRATION</small></span>
      </a>
    </div>
    <div class="sidebar-scroll">
      <div class="nav-caption">WORKSPACE</div>
      <nav aria-label="Main administration sections">
        @include('orbit-admin.partials.sidebar-navigation')
      </nav>
      <div class="nav-caption space-top">UPCOMING MODULES</div>
      <div class="upcoming-list" aria-label="Future modules">
        <div class="nav-item disabled"><span class="upcoming-icon" aria-hidden="true">◌</span><span>Circles</span><small>Upcoming</small></div>
        <div class="nav-item disabled"><span class="upcoming-icon" aria-hidden="true">✚</span><span>SOS operations</span><small>Upcoming</small></div>
        <div class="nav-item disabled"><span class="upcoming-icon" aria-hidden="true">▤</span><span>Billing &amp; Ads</span><small>Upcoming</small></div>
        <div class="nav-item disabled"><span class="upcoming-icon" aria-hidden="true">▦</span><span>System health</span><small>Upcoming</small></div>
      </div>
    </div>
    <div class="sidebar-bottom"><span class="online-dot"></span> {{ strtoupper(app()->environment()) }} environment <p>Role-restricted operations</p></div>
  </aside>
  <div class="sidebar-scrim" data-scrim></div>
  <div class="main">
    <header class="topbar">
      <button type="button" class="menu-trigger" aria-label="Open navigation" aria-controls="orbit-admin-sidebar" aria-expanded="false" data-menu><svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
      <div class="breadcrumb">ORBIT <span>/</span> ADMIN <span>/</span> @yield('crumb','OVERVIEW')</div>
      <div class="topbar-right"><span class="security-indicator"><i></i> SECURE SESSION</span><span class="avatar">{{ strtoupper(substr(auth()->guard('admin')->user()->name ?? 'A', 0, 1)) }}</span><span class="admin-name">{{ auth()->guard('admin')->user()->name ?? 'Admin' }}</span>
        <form action="{{ route('orbit.admin.logout') }}" method="post">@csrf<button class="link-button" type="submit">Sign out</button></form>
      </div>
    </header>
    <main class="content">
      @if(session('status'))<div class="notice success" role="status">{{ session('status') }}</div>@endif
      @if($errors->any())<div class="notice danger" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
      @yield('content')
      <footer>Orbit Administration · Role-gated controls and audited changes · Private content is not exposed</footer>
    </main>
  </div>
</div>
<script src="{{ route('orbit.admin.asset', ['file' => 'admin.js', 'v' => filemtime(public_path('assets/orbit-admin/admin.js'))]) }}" defer></script>
@stack('scripts')
</body></html>
