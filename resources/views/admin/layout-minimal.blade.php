<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? __('client.instagram.title') }} — {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('assets/client-portal.css') }}">
</head>
<body class="cp-app-body">
<div class="cp-app-shell">
    <div class="cp-app" style="grid-template-columns: 1fr; max-width: 960px; margin: 0 auto;">
        <main class="cp-main" style="padding: 24px 16px;">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:16px; flex-wrap:wrap;">
                <h1 style="margin:0; font-size:22px;">{{ $title ?? __('client.instagram.title') }}</h1>
                <a href="{{ route('dashboard') }}" class="cp-btn cp-btn-secondary" style="width:auto; height:36px; padding:0 14px; text-decoration:none;">
                    ← {{ __('client.instagram.back_to_admin') }}
                </a>
            </div>
            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
