<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin verification · Orbit</title><link rel="stylesheet" href="{{ asset('assets/orbit-admin/admin.css') }}">
</head><body class="auth-body"><main class="auth-panel">
  <div class="auth-mark">O</div><p class="eyebrow">SECOND FACTOR</p><h1>Verify your identity</h1>
  <p class="auth-help">Enter the six-digit one-time code from your registered authenticator app.</p>
  @if($errors->any())<div class="notice danger" role="alert">{{ $errors->first() }}</div>@endif
  <form method="post" action="{{ route('orbit.admin.mfa.verify') }}">@csrf
    <label>Authenticator code<input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" minlength="6" required autocomplete="one-time-code" autofocus></label>
    <button type="submit" class="btn primary full">Verify and enter →</button>
  </form><p class="auth-foot">Admin sessions automatically expire after 15 minutes of inactivity.</p>
</main></body></html>
