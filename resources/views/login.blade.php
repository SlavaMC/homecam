<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Вход · Видеонаблюдение</title>
    <link rel="stylesheet" href="/css/app.css?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body class="center">
<form method="post" action="/login" class="card login">
    @csrf
    <h1>● Видеонаблюдение</h1>
    @if($errors->any())<div class="flash err">{{ $errors->first() }}</div>@endif
    <label>Логин<input name="login" value="{{ old('login') }}" autofocus required autocomplete="username"></label>
    <label>Пароль<input type="password" name="password" required autocomplete="current-password"></label>
    <label class="check"><input type="checkbox" name="remember" value="1" checked> Запомнить меня</label>
    <button class="primary">Войти</button>
</form>
</body>
</html>
