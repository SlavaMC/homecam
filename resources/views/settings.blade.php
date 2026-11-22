@extends('layouts.app')
@section('title', 'Настройки · Видеонаблюдение')
@section('content')
    <div class="toolbar"><h2>Настройки</h2></div>

    <form method="post" action="/settings" class="card form">
        @csrf
        <h3>Хранилище и ротация</h3>
        <p class="muted small">Сейчас записи занимают <span class="bytes" data-bytes="{{ $stats['used'] }}"></span>.
            Диск: свободно <span class="bytes" data-bytes="{{ $stats['disk_free'] }}"></span> из <span class="bytes" data-bytes="{{ $stats['disk_total'] }}"></span>.
            Когда записи превышают лимит, самые старые ролики удаляются автоматически.</p>
        <div class="row">
            <label>Лимит записей, ГБ<input type="number" name="storage_limit_gb" min="1" step="0.1" value="{{ $limit }}" required></label>
            <label>Оставлять свободным на диске, ГБ<input type="number" name="min_free_gb" min="0" step="0.1" value="{{ $minFree }}" required></label>
        </div>
        <div class="row">
            <label>Камера «недоступна» после, сек<input type="number" name="offline_after" min="15" value="{{ $offlineAfter }}" required></label>
            <label>Хранить экспортированные файлы, ч<input type="number" name="export_ttl_hours" min="1" value="{{ $exportTtl }}" required></label>
        </div>
        <div class="actions"><button class="primary">Сохранить</button></div>
    </form>

    <form method="post" action="/settings/telegram-test" class="card form">
        @csrf
        <h3>Оповещения в Telegram</h3>
        @if($telegram)
            <p class="muted small">Бот настроен. Сообщения о пропадании и восстановлении камер уходят в группу.</p>
        @else
            <p class="muted small">Бот не настроен. Пропишите <code>TELEGRAM_BOT_TOKEN</code> и <code>TELEGRAM_CHAT_ID</code> в файле <code>.env</code> и перезапустите систему.</p>
        @endif
        <div class="actions"><button>Отправить тестовое сообщение</button></div>
    </form>

    <div class="card form">
        <h3>Пользователи</h3>
        <table class="list">
            @foreach($users as $u)
                <tr><td>{{ $u->login }}</td><td class="right">
                    @if($u->id !== auth()->id())
                        <form method="post" action="/settings/users/{{ $u->id }}" class="inline" data-confirm="Удалить пользователя {{ $u->login }}?">@csrf @method('DELETE')<button class="icon">✕</button></form>
                    @else <span class="muted small">это вы</span> @endif
                </td></tr>
            @endforeach
        </table>
        <form method="post" action="/settings/users" class="row">
            @csrf
            <label>Логин<input name="login" required></label>
            <label>Пароль<input type="password" name="password" required minlength="6" autocomplete="new-password"></label>
            <label>&nbsp;<button>Добавить</button></label>
        </form>
    </div>

    <form method="post" action="/settings/password" class="card form">
        @csrf
        <h3>Сменить мой пароль</h3>
        <div class="row">
            <label>Текущий пароль<input type="password" name="current" required autocomplete="current-password"></label>
            <label>Новый пароль<input type="password" name="password" required minlength="6" autocomplete="new-password"></label>
            <label>Ещё раз<input type="password" name="password_confirmation" required autocomplete="new-password"></label>
        </div>
        <div class="actions"><button>Сменить пароль</button></div>
    </form>
@endsection
@push('scripts')
<script>document.querySelectorAll('.bytes').forEach((e) => e.textContent = fmtBytes(+e.dataset.bytes));</script>
@endpush
