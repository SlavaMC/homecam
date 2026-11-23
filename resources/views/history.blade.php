@extends('layouts.app')
@section('title', 'Архив · Видеонаблюдение')
@section('main-class', 'wide')
@section('content')
    <div class="toolbar wrap">
        <h2>Архив</h2>
        <label>С <input type="datetime-local" id="from" step="1"></label>
        <label>По <input type="datetime-local" id="to" step="1"></label>
        <div class="btns">
            <button data-quick="3600">1 ч</button>
            <button data-quick="21600">6 ч</button>
            <button data-quick="today">Сегодня</button>
            <button data-quick="yesterday">Вчера</button>
        </div>
        <button class="primary" id="show">Показать</button>
    </div>
    <div class="toolbar wrap cams" id="camList">
        @foreach($cameras as $cam)
            <label class="check"><input type="checkbox" value="{{ $cam->id }}" data-name="{{ $cam->name }}" checked> {{ $cam->name }}</label>
        @endforeach
        @if($cameras->isEmpty())<span class="muted">Нет доступных камер</span>@endif
    </div>
    @if($limitFrom || $limitTo)
        <p class="muted">По этой ссылке доступен архив
            @if($limitFrom) с <span data-ts="{{ $limitFrom }}"></span>@endif
            @if($limitTo) по <span data-ts="{{ $limitTo }}"></span>@endif
        </p>
    @endif

    <div class="player-bar" id="bar" hidden>
        <button id="play" class="primary" title="Пробел">▶</button>
        <button id="back" title="← 10 с">−10с</button>
        <button id="fwd" title="→ 10 с">+10с</button>
        <select id="speed" title="Скорость">
            <option value="1">1×</option><option value="2">2×</option><option value="4">4×</option>
            <option value="8">8×</option><option value="16">16×</option>
        </select>
        <span id="clock" class="clock">--:--:--</span>
        <div class="spacer"></div>
        <button id="markIn" title="Начало экспорта = текущий момент">[ начало</button>
        <button id="markOut" title="Конец экспорта = текущий момент">конец ]</button>
        <span class="muted" id="sel"></span>
        <button id="export">Скачать фрагмент</button>
    </div>
    <div class="timeline-wrap" id="timelineWrap" hidden>
        <button class="shift" id="prevRange" title="Предыдущий интервал">◀</button>
        <canvas id="timeline" class="timeline"></canvas>
        <button class="shift" id="nextRange" title="Следующий интервал">▶</button>
    </div>
    <div class="grid" id="grid"></div>
@endsection
@push('scripts')
<script src="/vendor/hls.min.js"></script>
<script>window.HISTORY_LIMIT = {from: {{ $limitFrom ?? 'null' }}, to: {{ $limitTo ?? 'null' }}};</script>
<script src="/js/history.js?v={{ filemtime(public_path('js/history.js')) }}"></script>
@endpush
