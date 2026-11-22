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
