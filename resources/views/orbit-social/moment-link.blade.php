<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Private Moment · Orbit</title>
<style>body{margin:0;background:#f8f9fc;color:#17213a;font:16px/1.5 system-ui,sans-serif;display:grid;place-items:center;min-height:100vh}main{max-width:420px;padding:36px 28px;margin:16px;background:white;border-radius:24px}h1{font-size:26px}p{color:#56607a}a{display:block;margin:14px 0;padding:12px;background:#7c3aed;color:white;border-radius:10px;text-align:center;text-decoration:none}</style>
</head><body><main>
<h1>View a Moment in Orbit</h1>
<p>Open Orbit and sign in. Only people with current permission can view this Moment.</p>
<a href="orbit://moment/{{ $moment }}">Open Orbit</a>
@foreach (['ios_install_url'=>'Install for iPhone','android_install_url'=>'Install for Android'] as $key=>$label)
    @php($url = config('orbit_social.'.$key))
    @if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME)==='https')
        <a href="{{ $url }}" rel="noreferrer">{{ $label }}</a>
    @endif
@endforeach
<p>If Orbit is not installed and no installation link is available, ask the sender for the approved Orbit installation. Reopen this link after signing in.</p>
</main></body></html>
