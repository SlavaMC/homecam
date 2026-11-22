@extends('layouts.app')
@section('title', 'Камеры · Видеонаблюдение')
@section('content')
    <div class="toolbar">
        <h2>Камеры</h2>
        <div class="spacer"></div>
        <a class="btn primary" href="/cameras/create">+ Добавить камеру</a>
    </div>
    <table class="list">
        <thead><tr><th></th><th>Название</th><th>Адрес</th><th>Запись</th><th>Архив</th><th>Последнее видео</th><th></th></tr></thead>
        <tbody>
        @forelse($cameras as $cam)
            @php($s = $stats['per_camera'][$cam->id] ?? null)
            <tr>
                <td><span class="dot {{ $cam->status }}"></span></td>
                <td><b>{{ $cam->name }}</b><div class="muted small">{{ $cam->slug }}</div></td>
                <td class="small">{{ $cam->rtsp_url }}</td>
                <td>
                    @if(!$cam->enabled) <span class="muted">выключена</span>
                    @elseif(!$cam->record) <span class="muted">только онлайн</span>
                    @else {{ $cam->status === 'online' ? 'идёт' : ($cam->status === 'offline' ? 'нет связи' : 'запуск…') }}
                    @endif
                    @if($cam->last_error && $cam->status !== 'online')<div class="small err-text" title="{{ $cam->last_error }}">{{ \Illuminate\Support\Str::limit($cam->last_error, 90) }}</div>@endif
                </td>
                <td class="small">
                    @if($s)
                        <span class="bytes" data-bytes="{{ $s->bytes }}"></span>, с <span data-ts="{{ $s->first }}"></span>
                    @else <span class="muted">пусто</span> @endif
                </td>
                <td class="small">@if($cam->last_segment_at)<span data-ts="{{ $cam->last_segment_at->getTimestamp() }}"></span>@endif</td>
                <td class="right"><a class="btn" href="/cameras/{{ $cam->id }}/edit">Настроить</a></td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">Камер пока нет</td></tr>
        @endforelse
        </tbody>
    </table>
    <p class="muted small">Записи: <span class="bytes" data-bytes="{{ $stats['used'] }}"></span> из лимита <span class="bytes" data-bytes="{{ $stats['limit'] }}"></span>,
        свободно на диске <span class="bytes" data-bytes="{{ $stats['disk_free'] }}"></span>. Лимит меняется в <a href="/settings">настройках</a>.</p>
@endsection
@push('scripts')
<script>document.querySelectorAll('.bytes').forEach((e) => e.textContent = fmtBytes(+e.dataset.bytes));</script>
@endpush
