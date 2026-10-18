<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Administrator sign in · Orbit</title><link rel="stylesheet" href="{{ route('orbit.admin.asset', ['file' => 'admin.css']) }}">
</head><body class="auth-body"><main class="auth-panel">
  <div class="auth-mark">O</div><p class="eyebrow">ORBIT OPERATIONS</p><h1>Admin sign in</h1>
  <p class="auth-help">A separate, protected administrator account is required. There are no default credentials.</p>
  @if($errors->any())<div class="notice danger" role="alert">{{ $errors->first() }}</div>@endif
  <form method="post" action="{{ route('orbit.admin.password') }}">@csrf
    <label>Email address<input type="email" name="email" value="{{ old('email') }}" required maxlength="190" autocomplete="username" autofocus></label>
    <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
    <button type="submit" class="btn primary full">Continue securely →</button>
  </form><p class="auth-foot">Multi-factor verification is required before accessing Orbit Admin.</p>
</main></body></html>
