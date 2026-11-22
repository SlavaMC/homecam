@php($isUser = auth()->check())
@php($share = $isUser ? null : \App\Services\Access::share())
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Видеонаблюдение')</title>
    <link rel="stylesheet" href="/css/app.css?v={{ filemtime(public_path('css/app.css')) }}">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><circle cx='12' cy='12' r='9' fill='%23e5484d'/></svg>">
</head>
<body>
<header class="top">
    <a class="brand" href="/">● Видеонаблюдение</a>
    <nav>
        @if($isUser || $share?->allow_live)
            <a href="/" @class(['on' => request()->is('/')])>Онлайн</a>
        @endif
        @if($isUser || $share?->allow_history)
            <a href="/history" @class(['on' => request()->is('history')])>Архив</a>
            <a href="/exports" @class(['on' => request()->is('exports')])>Экспорт</a>
        @endif
        @if($isUser)
            <a href="/cameras" @class(['on' => request()->is('cameras*')])>Камеры</a>
            <a href="/shares" @class(['on' => request()->is('shares')])>Ссылки</a>
            <a href="/settings" @class(['on' => request()->is('settings')])>Настройки</a>
        @endif
    </nav>
    <div class="who">
        @if($isUser)
            <span class="muted">{{ auth()->user()->login }}</span>
            <form method="post" action="/logout" class="inline">@csrf<button class="link">Выйти</button></form>
        @elseif($share)
            <span class="muted">Гостевой доступ до {{ $share->expires_at->format('d.m.Y H:i') }}</span>
        @endif
    </div>
</header>
<main class="@yield('main-class')">
    @if(session('ok'))<div class="flash ok">{{ session('ok') }}</div>@endif
    @if(session('error'))<div class="flash err">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="flash err">{{ $errors->first() }}</div>@endif
    @yield('content')
</main>
<script src="/js/common.js?v={{ filemtime(public_path('js/common.js')) }}"></script>
@stack('scripts')
</body>
</html>
