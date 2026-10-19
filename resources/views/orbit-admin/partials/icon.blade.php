@switch($name)
@case('settings')
<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z"/><path d="M19.4 15a1.5 1.5 0 0 0 .3 1.65l-1.8 3.1a1.5 1.5 0 0 0-1.57-.56 1.5 1.5 0 0 0-1.09 1.28h-3.59a1.5 1.5 0 0 0-1.1-1.28 1.5 1.5 0 0 0-1.57.56l-1.8-3.1A1.5 1.5 0 0 0 7.5 15a1.5 1.5 0 0 0-1.4-1H4.3v-4h1.8a1.5 1.5 0 0 0 1.4-1 1.5 1.5 0 0 0-.3-1.65l1.8-3.1a1.5 1.5 0 0 0 1.57.56 1.5 1.5 0 0 0 1.1-1.28h3.59a1.5 1.5 0 0 0 1.09 1.28 1.5 1.5 0 0 0 1.57-.56l1.8 3.1A1.5 1.5 0 0 0 19.4 9a1.5 1.5 0 0 0 1.4 1h.9v4h-.9a1.5 1.5 0 0 0-1.4 1Z"/></svg>
@break
@case('users')
<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
@break
@case('circles')
<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/><path d="M12 3v3m0 12v3M3 12h3m12 0h3"/></svg>
@break
@case('sos')
<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 3 9 16H3L12 3Z"/><path d="M12 9v4m0 3h.01"/></svg>
@break
@case('billing')
<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20m-14 5h3"/></svg>
@break
@case('health')
<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12h4l3-6 4 12 3-6h4"/></svg>
@break
@case('chevron')
<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
@break
@default
<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16v16H4z"/><path d="M9 12h6"/></svg>
@endswitch
