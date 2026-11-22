window.api = async function (url, opts = {}) {
    const headers = {'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || ''};
    let body = opts.body;
    if (body && !(body instanceof FormData)) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(body); }
    const r = await fetch(url, {method: opts.method || 'GET', headers, body, credentials: 'same-origin'});
    const data = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(data.message || ('Ошибка ' + r.status));
    return data;
};

window.fmtBytes = function (n) {
    if (n === null || n === undefined) return '';
    const u = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
    let i = 0;
    while (n >= 1000 && i < u.length - 1) { n /= 1000; i++; }
    return n.toFixed(i ? 1 : 0) + ' ' + u[i];
};

window.fmtTs = function (ts) {
    const d = new Date(ts * 1000), p = (n) => String(n).padStart(2, '0');
    return `${p(d.getDate())}.${p(d.getMonth() + 1)}.${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
};

document.querySelectorAll('[data-ts]').forEach((el) => { el.textContent = fmtTs(+el.dataset.ts); });

document.querySelectorAll('[data-copy]').forEach((el) => el.addEventListener('click', () => {
    navigator.clipboard?.writeText(el.dataset.copy).then(() => { el.textContent = 'Скопировано'; });
}));

document.querySelectorAll('form[data-confirm]').forEach((f) => f.addEventListener('submit', (e) => {
    if (!confirm(f.dataset.confirm)) e.preventDefault();
}));
