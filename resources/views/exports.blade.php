@extends('layouts.app')
@section('title', 'Экспорт · Видеонаблюдение')
@section('content')
    <div class="toolbar"><h2>Экспорт видео</h2></div>
    <p class="muted">Фрагмент выбирается в <a href="/history">архиве</a>: отметьте начало и конец и нажмите «Скачать фрагмент».
        Каждая камера собирается в отдельный файл MP4 без перекодирования. Готовые файлы хранятся {{ \App\Models\Setting::get('export_ttl_hours') }} ч.</p>
    <table class="list">
        <thead><tr><th>Камера</th><th>Интервал</th><th>Статус</th><th>Размер</th><th></th></tr></thead>
        <tbody id="rows"><tr><td colspan="5" class="muted">Загрузка…</td></tr></tbody>
    </table>
@endsection
@push('scripts')
<script>
(function () {
    const isUser = {{ auth()->check() ? 'true' : 'false' }};
    const st = {pending: 'в очереди', running: 'собирается…', done: 'готово', failed: 'ошибка'};
    async function refresh() {
        const rows = await api('/api/exports');
        const tb = document.getElementById('rows');
        tb.innerHTML = rows.length ? '' : '<tr><td colspan="5" class="muted">Пока ничего не экспортировано</td></tr>';
        for (const r of rows) {
            const tr = document.createElement('tr');
            tr.innerHTML = `<td></td><td>${fmtTs(r.from)} — ${fmtTs(r.to)}</td><td class="st-${r.status}">${st[r.status]}</td><td>${fmtBytes(r.size)}</td><td class="right"></td>`;
            tr.children[0].textContent = r.camera || '—';
            if (r.error) tr.children[2].title = r.error;
            const act = tr.children[4];
            if (r.url) { const a = document.createElement('a'); a.href = r.url; a.className = 'btn primary'; a.textContent = 'Скачать'; act.appendChild(a); }
            if (r.status === 'failed') { const s = document.createElement('div'); s.className = 'muted small'; s.textContent = r.error; tr.children[2].appendChild(s); }
            if (isUser) {
                const b = document.createElement('button'); b.textContent = '✕'; b.className = 'icon'; b.title = 'Удалить';
                b.onclick = async () => { await api('/api/exports/' + r.id, {method: 'DELETE'}); refresh(); };
                act.appendChild(b);
            }
            tb.appendChild(tr);
        }
        if (rows.some((r) => r.status === 'pending' || r.status === 'running')) setTimeout(refresh, 2000);
    }
    refresh();
})();
</script>
@endpush
