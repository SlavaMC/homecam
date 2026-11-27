@extends('layouts.app')
@section('title', ($camera->exists ? 'Камера ' . $camera->name : 'Новая камера') . ' · Видеонаблюдение')
@section('content')
    <div class="toolbar"><h2>{{ $camera->exists ? 'Камера «' . $camera->name . '»' : 'Новая камера' }}</h2></div>
    <form method="post" action="{{ $camera->exists ? '/cameras/' . $camera->id : '/cameras' }}" class="card form" id="camForm">
        @csrf
        @if($camera->exists) @method('PUT') <input type="hidden" name="id" value="{{ $camera->id }}"> @endif
        <label>Название<input name="name" value="{{ old('name', $camera->name) }}" required maxlength="100"></label>
        <label>Адрес потока (RTSP)
            <input name="rtsp_url" value="{{ old('rtsp_url', $camera->rtsp_url) }}" required placeholder="rtsp://192.168.1.10:554/stream1">
            <span class="hint">Логин и пароль в адресе не пишите, для них есть поля ниже.</span>
        </label>
        <div class="row">
            <label>Логин<input name="username" value="{{ old('username', $camera->username) }}" autocomplete="off"></label>
            <label>Пароль<input type="password" name="password" autocomplete="new-password" placeholder="{{ $camera->exists && $camera->password ? 'не менять' : '' }}"></label>
        </div>
        <div class="row">
            <label>Транспорт
                <select name="transport">
                    <option value="tcp" @selected(old('transport', $camera->transport) === 'tcp')>TCP (надёжнее)</option>
                    <option value="udp" @selected(old('transport', $camera->transport) === 'udp')>UDP</option>
                </select>
            </label>
            <label>Длина ролика, сек
                <input type="number" name="segment_seconds" min="10" max="600" value="{{ old('segment_seconds', $camera->segment_seconds ?: 60) }}">
            </label>
            <label>Порядок<input type="number" name="sort" min="0" value="{{ old('sort', $camera->sort) }}"></label>
        </div>
        <label class="check"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $camera->enabled))> Камера включена</label>
        <label class="check"><input type="checkbox" name="record" value="1" @checked(old('record', $camera->record))> Вести непрерывную запись</label>
        <label class="check"><input type="checkbox" name="audio" value="1" @checked(old('audio', $camera->audio))> Записывать звук (перекодируется в AAC)</label>

        <h3 class="sep">Детекция движения</h3>
        <p class="muted small">Сила движения — какой процент выбранной зоны изменился между соседними ключевыми кадрами (раз в 1–5 с).
            Проход человека вблизи обычно даёт 3–15%, машина вдалеке 1–3%, шум и снег до 0,5%.</p>
        <label class="check"><input type="checkbox" name="motion_enabled" value="1" @checked(old('motion_enabled', $camera->motion_enabled))> Искать движение в записи</label>
        <div class="row">
            <label>Минимальная сила для события, %
                <input type="number" name="motion_threshold" min="0.05" max="100" step="0.05" value="{{ old('motion_threshold', $camera->motion_threshold) }}">
            </label>
        </div>
        <div class="mask-block">
            <div class="mask-head"><b>Зона детекции</b> <span class="muted small">выделите мышью прямоугольники, где искать движение; повторное выделение по закрашенному стирает. Пусто — весь кадр.</span></div>
            @if($camera->exists)
                <div class="mask-editor" data-input="motion_mask" data-color="47,111,222">
                    <img src="/cameras/{{ $camera->id }}/snapshot.jpg" alt="">
                    <canvas></canvas>
                </div>
                <div class="actions small"><button type="button" data-mask-all>Весь кадр</button><button type="button" data-mask-none>Очистить</button></div>
            @else
                <p class="muted small">Зоны можно нарисовать после сохранения камеры и первой записи.</p>
            @endif
            <input type="hidden" name="motion_mask" value="{{ old('motion_mask', $camera->motion_mask) }}">
        </div>

        <h3 class="sep">Тепловая карта движений</h3>
        <p class="muted small">Для каждой клетки сетки 32×24 считается, сколько раз в ней было движение. Карта показывается в архиве кнопкой на плитке камеры.</p>
        <label class="check"><input type="checkbox" name="heatmap_enabled" value="1" @checked(old('heatmap_enabled', $camera->heatmap_enabled ?? true))> Записывать тепловую карту</label>
        <div class="row">
            <label>Минимальная сила движения в клетке, % её площади
                <input type="number" name="heatmap_threshold" min="1" max="100" step="1" value="{{ old('heatmap_threshold', $camera->heatmap_threshold ?? 25) }}">
                <span class="hint">Меньше — чувствительнее (и больше шума от снега и листвы). По умолчанию 25%.</span>
            </label>
        </div>

        <h3 class="sep">Оповещение в Telegram о движении</h3>
        <label class="check"><input type="checkbox" name="notify_enabled" value="1" @checked(old('notify_enabled', $camera->notify_enabled))> Присылать снимок в Telegram при движении</label>
        <div class="row">
            <label>Минимальная сила в зоне оповещения, %
                <input type="number" name="notify_threshold" min="0.05" max="100" step="0.05" value="{{ old('notify_threshold', $camera->notify_threshold) }}">
            </label>
            <label>Не чаще одного раза в, мин
                <input type="number" name="notify_cooldown_min" min="0" max="1440" step="0.5" value="{{ old('notify_cooldown_min', round(($camera->notify_cooldown ?? 300) / 60, 1)) }}">
            </label>
            <label>Снимок через, сек после начала движения
                <input type="number" name="notify_snapshot_delay" min="0" max="30" step="0.5" value="{{ old('notify_snapshot_delay', $camera->notify_snapshot_delay ?? 2) }}">
            </label>
        </div>
        <div class="mask-block">
            <div class="mask-head"><b>Зона оповещения</b> <span class="muted small">например, только дверь или калитка. Пусто — весь кадр.</span></div>
            @if($camera->exists)
                <div class="mask-editor" data-input="notify_mask" data-color="229,72,77">
                    <img src="/cameras/{{ $camera->id }}/snapshot.jpg" alt="">
                    <canvas></canvas>
                </div>
                <div class="actions small"><button type="button" data-mask-all>Весь кадр</button><button type="button" data-mask-none>Очистить</button></div>
            @endif
            <input type="hidden" name="notify_mask" value="{{ old('notify_mask', $camera->notify_mask) }}">
        </div>
        @if(!empty($events) && $events->count())
            <p class="small"><b>Самые сильные движения за сутки</b> (помогает подобрать пороги):
                @foreach($events as $ev)
                    <a href="/history?from={{ $ev->start_ts - 20 }}&to={{ $ev->start_ts + 280 }}&cams={{ $camera->id }}" class="evlink"><span data-ts="{{ $ev->start_ts }}"></span> — {{ round($ev->peak, 1) }}%@if($ev->notify_peak) (зона оповещения {{ round($ev->notify_peak, 1) }}%)@endif</a>@if(!$loop->last), @endif
                @endforeach
            </p>
        @endif
        <div class="actions">
            <button class="primary">Сохранить</button>
            <button type="button" id="test">Проверить подключение</button>
            <span id="testResult" class="small"></span>
            <div class="spacer"></div>
            <a href="/cameras" class="btn">Отмена</a>
        </div>
    </form>

    @if($camera->exists)
        <form method="post" action="/cameras/{{ $camera->id }}" class="card form danger" data-confirm="Удалить камеру «{{ $camera->name }}»?">
            @csrf @method('DELETE')
            <h3>Удаление камеры</h3>
            <label class="check"><input type="checkbox" name="delete_files" value="1"> Удалить и все записи этой камеры с диска</label>
            <div class="actions"><button class="danger">Удалить камеру</button></div>
        </form>
    @endif
@endsection
@push('scripts')
<script src="/js/mask.js?v={{ filemtime(public_path('js/mask.js')) }}"></script>
<script>
document.getElementById('test').addEventListener('click', async () => {
    const out = document.getElementById('testResult');
    out.textContent = 'Подключаюсь…';
    out.className = 'small';
    const fd = new FormData(document.getElementById('camForm'));
    fd.delete('_method');
    try {
        const r = await api('/cameras/test', {method: 'POST', body: fd});
        out.textContent = r.ok ? '✓ Камера отвечает: ' + r.streams.join(', ') : '✕ ' + r.error;
        out.className = 'small ' + (r.ok ? 'ok-text' : 'err-text');
    } catch (e) { out.textContent = '✕ ' + e.message; out.className = 'small err-text'; }
});
</script>
@endpush
