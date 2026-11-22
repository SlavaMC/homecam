@extends('layouts.app')
@section('title', 'Временные ссылки · Видеонаблюдение')
@section('content')
    <div class="toolbar"><h2>Временные ссылки</h2></div>
    <p class="muted">По временной ссылке можно смотреть камеры без логина и пароля, пока не истёк срок. Ссылку можно отозвать в любой момент.</p>

    @if(session('new_link'))
        <div class="card newlink">
            <b>Новая ссылка:</b> <code>{{ session('new_link') }}</code>
            <button type="button" data-copy="{{ session('new_link') }}">Копировать</button>
        </div>
    @endif

    <form method="post" action="/shares" class="card form" id="shareForm">
        @csrf
        <h3>Создать ссылку</h3>
        <div class="row">
            <label>Название (для себя)<input name="name" value="{{ old('name') }}" placeholder="например, для соседа"></label>
            <label>Действует, часов<input type="number" name="hours" min="0.1" step="0.1" value="{{ old('hours', 24) }}" required></label>
        </div>
        <label class="check"><input type="checkbox" name="allow_live" value="1" checked> Онлайн-просмотр</label>
        <label class="check"><input type="checkbox" name="allow_history" value="1" id="allowHistory"> Просмотр архива и скачивание фрагментов</label>
        <div class="row" id="histRange" hidden>
            <label>Архив с (необязательно)<input type="datetime-local" id="hf"></label>
            <label>по (необязательно)<input type="datetime-local" id="ht"></label>
            <input type="hidden" name="history_from"><input type="hidden" name="history_to">
        </div>
        <label class="check"><input type="checkbox" name="all_cameras" value="1" id="allCams" checked> Все камеры (и те, что добавятся позже)</label>
        <div class="cams" id="camPick" hidden>
            @foreach($cameras as $cam)
                <label class="check"><input type="checkbox" name="cameras[]" value="{{ $cam->id }}"> {{ $cam->name }}</label>
            @endforeach
        </div>
        <div class="actions"><button class="primary">Создать ссылку</button></div>
    </form>

    <table class="list">
        <thead><tr><th>Ссылка</th><th>Доступ</th><th>Камеры</th><th>Действует до</th><th></th></tr></thead>
        <tbody>
        @forelse($links as $l)
            <tr @class(['expired' => !$l->isValid()])>
                <td>{{ $l->name ?: 'без названия' }}<div><button type="button" class="link small" data-copy="{{ $l->url() }}">Копировать ссылку</button></div></td>
                <td class="small">{{ collect([$l->allow_live ? 'онлайн' : null, $l->allow_history ? 'архив' : null])->filter()->join(', ') }}
                    @if($l->history_from || $l->history_to)<div class="muted">архив
                        @if($l->history_from) с <span data-ts="{{ $l->history_from }}"></span>@endif
                        @if($l->history_to) по <span data-ts="{{ $l->history_to }}"></span>@endif</div>@endif
                </td>
                <td class="small">{{ $l->camera_ids === null ? 'все' : collect($l->camera_ids)->map(fn ($id) => $cameras[$id]->name ?? '#' . $id)->join(', ') }}</td>
                <td class="small">{{ $l->expires_at->format('d.m.Y H:i') }} @if(!$l->isValid())<span class="muted">(истекла)</span>@endif</td>
                <td class="right">
                    <form method="post" action="/shares/{{ $l->id }}" class="inline" data-confirm="Отозвать ссылку?">@csrf @method('DELETE')<button class="icon" title="Отозвать">✕</button></form>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">Ссылок нет</td></tr>
        @endforelse
        </tbody>
    </table>
@endsection
@push('scripts')
<script>
const allCams = document.getElementById('allCams'), allowHistory = document.getElementById('allowHistory');
allCams.addEventListener('change', () => document.getElementById('camPick').hidden = allCams.checked);
allowHistory.addEventListener('change', () => document.getElementById('histRange').hidden = !allowHistory.checked);
document.getElementById('shareForm').addEventListener('submit', (e) => {
    const f = e.target, hf = document.getElementById('hf').value, ht = document.getElementById('ht').value;
    f.history_from.value = hf ? Math.floor(new Date(hf).getTime() / 1000) : '';
    f.history_to.value = ht ? Math.floor(new Date(ht).getTime() / 1000) : '';
});
</script>
@endpush
