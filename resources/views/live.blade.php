@extends('layouts.app')
@section('title', 'Онлайн · Видеонаблюдение')
@section('main-class', 'wide')
@section('content')
    <div class="toolbar">
        <h2>Онлайн</h2>
        <div class="spacer"></div>
        <label class="muted">Сетка
            <select id="cols">
                <option value="0">авто</option><option value="1">1</option><option value="2">2</option>
                <option value="3">3</option><option value="4">4</option>
            </select>
        </label>
    </div>
    @if($cameras->isEmpty())
        <p class="muted">Камер нет. @auth <a href="/cameras/create">Добавить камеру</a> @endauth</p>
    @endif
    <div class="grid" id="grid">
        @foreach($cameras as $cam)
            <div class="tile" data-id="{{ $cam->id }}">
                <div class="tile-head">
                    <span class="dot {{ $cam->status }}" title="{{ ['online' => 'запись идёт', 'offline' => 'нет связи', 'unknown' => 'не записывается'][$cam->status] ?? '' }}"></span>
                    <span class="name">{{ $cam->name }}</span>
                    <span class="spacer"></span>
                    <button class="icon" data-full title="На весь экран">⛶</button>
                </div>
                <video-stream class="player" data-src="/live/api/ws?src={{ $cam->streamName() }}"></video-stream>
            </div>
        @endforeach
    </div>
@endsection
@push('scripts')
<script type="module" src="/js/live.js?v={{ filemtime(public_path('js/live.js')) }}"></script>
@endpush
