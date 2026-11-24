/* Synchronised playback of recordings of several cameras over one time interval. */
(function () {
    const $ = (id) => document.getElementById(id);
    const fromEl = $('from'), toEl = $('to'), grid = $('grid'), bar = $('bar'), canvas = $('timeline');
    const playBtn = $('play'), speedEl = $('speed'), clockEl = $('clock'), selEl = $('sel');
    const LIMIT = window.HISTORY_LIMIT || {};

    let range = null;          // {from, to} in ms
    let players = [];
    let T = 0;                 // current wall-clock position, ms
    let playing = false;
    let master = null;
    let lastTick = performance.now();
    let mark = {in: null, out: null};
    let motion = [];           // all events of shown cameras: {cam, s, e, peak} in ms, sorted by start

    // ---------- inputs ----------
    const pad = (n) => String(n).padStart(2, '0');
    const toInput = (ms) => {
        const d = new Date(ms);
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
    };
    const fromInput = (v) => v ? new Date(v).getTime() : NaN;
    const fmtTime = (ms) => { const d = new Date(ms); return `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`; };
    const fmtFull = (ms) => { const d = new Date(ms); return `${pad(d.getDate())}.${pad(d.getMonth() + 1)} ${fmtTime(ms)}`; };

    function setQuick(q) {
        const now = Date.now();
        let a, b;
        if (q === 'today') { const d = new Date(); d.setHours(0, 0, 0, 0); a = d.getTime(); b = now; }
        else if (q === 'yesterday') { const d = new Date(); d.setHours(0, 0, 0, 0); b = d.getTime(); a = b - 86400e3; }
        else { b = now; a = now - (+q) * 1000; }
        fromEl.value = toInput(a); toEl.value = toInput(b);
    }
    document.querySelectorAll('[data-quick]').forEach((b) => b.addEventListener('click', () => { setQuick(b.dataset.quick); load(); }));
    $('show').addEventListener('click', () => load());

    function selectedCams() {
        return [...document.querySelectorAll('#camList input:checked')].map((i) => ({id: +i.value, name: i.dataset.name}));
    }

    // ---------- player ----------
    class Player {
        constructor(cam, from, to) {
            this.cam = cam;
            this.frags = [];
            this.el = document.createElement('div');
            this.el.className = 'tile';
            this.el.innerHTML = `<div class="tile-head"><span class="name"></span><span class="spacer"></span><span class="muted small state"></span><button class="icon" title="На весь экран">⛶</button></div>
                <div class="player"><video muted playsinline></video><div class="overlay">загрузка…</div></div>`;
            this.el.querySelector('.name').textContent = cam.name;
            this.video = this.el.querySelector('video');
            this.overlay = this.el.querySelector('.overlay');
            this.el.querySelector('button').onclick = () => {
                if (document.fullscreenElement) document.exitFullscreen(); else this.el.requestFullscreen?.();
            };
            const url = `/api/history/${cam.id}.m3u8?from=${Math.floor(from / 1000)}&to=${Math.ceil(to / 1000)}`;
            if (window.Hls && Hls.isSupported()) {
                this.hls = new Hls({maxBufferLength: 20, maxMaxBufferLength: 40, backBufferLength: 30, startPosition: 0, autoStartLoad: true});
                this.hls.on(Hls.Events.LEVEL_LOADED, (_e, data) => {
                    this.frags = data.details.fragments.map((f) => ({start: f.start, dur: f.duration, pdt: f.programDateTime}));
                    this.ready = true;
                    drawTimeline();
                    if (!this.frags.length) this.show('нет записи за этот интервал');
                });
                this.hls.on(Hls.Events.FRAG_CHANGED, (_e, data) => {
                    // hls.js refines fragment start after parsing; keep our map in sync
                    const f = data.frag, m = this.frags[f.sn];
                    if (m) m.start = f.start;
                });
                this.hls.on(Hls.Events.ERROR, (_e, data) => {
                    if (!data.fatal) return;
                    if (data.type === Hls.ErrorTypes.MEDIA_ERROR) this.hls.recoverMediaError();
                    else this.show('ошибка загрузки');
                });
                this.hls.loadSource(url);
                this.hls.attachMedia(this.video);
            } else {
                // Safari without MSE: native HLS, single offset mapping
                this.native = true;
                this.video.src = url;
                fetch(url).then((r) => r.text()).then((txt) => {
                    let t = 0, pdt = null;
                    for (const line of txt.split('\n')) {
                        if (line.startsWith('#EXT-X-PROGRAM-DATE-TIME:')) pdt = Date.parse(line.slice(25));
                        else if (line.startsWith('#EXTINF:')) { const d = parseFloat(line.slice(8)); this.frags.push({start: t, dur: d, pdt}); t += d; }
                    }
                    this.ready = true;
                    drawTimeline();
                    if (!this.frags.length) this.show('нет записи за этот интервал');
                });
            }
        }
        show(text) { this.overlay.textContent = text || ''; this.overlay.hidden = !text; }
        fragAtWall(ms) {
            let lo = 0, hi = this.frags.length - 1;
            while (lo <= hi) {
                const mid = (lo + hi) >> 1, f = this.frags[mid];
                if (ms < f.pdt) hi = mid - 1;
                else if (ms >= f.pdt + f.dur * 1000) lo = mid + 1;
                else return f;
            }
            return null;
        }
        nextStartAfter(ms) {
            for (const f of this.frags) if (f.pdt > ms) return f.pdt;
            return null;
        }
        wallAtCurrent() {
            const ct = this.video.currentTime;
            let lo = 0, hi = this.frags.length - 1;
            while (lo <= hi) {
                const mid = (lo + hi) >> 1, f = this.frags[mid];
                if (ct < f.start) hi = mid - 1;
                else if (ct >= f.start + f.dur) lo = mid + 1;
                else return f.pdt + (ct - f.start) * 1000;
            }
            return null;
        }
        covers(ms) { return !!this.fragAtWall(ms); }
        destroy() { this.hls?.destroy(); this.video.removeAttribute('src'); this.el.remove(); }
    }

    // ---------- loading ----------
    function load() {
        let a = fromInput(fromEl.value), b = fromInput(toEl.value);
        if (!(a < b)) { alert('Укажите правильный интервал'); return; }
        if (b - a > 7 * 86400e3) { alert('Интервал не больше 7 суток'); return; }
        if (LIMIT.from) a = Math.max(a, LIMIT.from * 1000);
        if (LIMIT.to) b = Math.min(b, LIMIT.to * 1000);
        const cams = selectedCams();
        if (!cams.length) { alert('Выберите камеры'); return; }

        players.forEach((p) => p.destroy());
        range = {from: a, to: b};
        T = a;
        master = null;
        mark = {in: null, out: null};
        players = cams.map((c) => new Player(c, a, b));
        players.forEach((p) => grid.appendChild(p.el));
        motion = [];
        api(`/api/motion?from=${Math.floor(a / 1000)}&to=${Math.ceil(b / 1000)}`).then((r) => {
            const ids = new Set(cams.map((c) => c.id));
            for (const [cam, list] of Object.entries(r.cameras || {})) {
                if (!ids.has(+cam)) continue;
                for (const [s, e, peak] of list) motion.push({cam: +cam, s: s * 1000, e: e * 1000, peak});
            }
            motion.sort((x, y) => x.s - y.s);
            players.forEach((p) => { p.motion = motion.filter((m) => m.cam === p.cam.id); });
            $('prevMotion').disabled = $('nextMotion').disabled = !motion.length;
            drawTimeline();
        }).catch(() => {});
        grid.dataset.count = players.length;
        bar.hidden = false;
        $('timelineWrap').hidden = false;
        setPlaying(false);
        updateSel();
        const q = new URLSearchParams({from: Math.floor(a / 1000), to: Math.floor(b / 1000), cams: cams.map((c) => c.id).join(',')});
        history.replaceState(null, '', '?' + q);
        // start from the first recorded moment once playlists are loaded
        const wait = setInterval(() => {
            if (players.every((p) => p.ready)) {
                clearInterval(wait);
                const first = Math.min(...players.map((p) => p.frags[0]?.pdt ?? Infinity));
                if (isFinite(first)) seek(Math.max(a, first));
                setPlaying(true);
            }
        }, 200);
    }

    // ---------- transport ----------
    function setPlaying(v) {
        playing = v;
        playBtn.textContent = v ? '❚❚' : '▶';
        if (!v) players.forEach((p) => p.video.pause());
    }
    function seek(ms) {
        if (!range) return;
        T = Math.min(Math.max(ms, range.from), range.to);
        master = null;
        players.forEach((p) => { p.forceSeek = true; });
        sync(true);
    }
    playBtn.addEventListener('click', () => setPlaying(!playing));
    $('back').addEventListener('click', () => seek(T - 10e3));
    $('fwd').addEventListener('click', () => seek(T + 10e3));
    speedEl.addEventListener('change', () => { master = null; });
    document.addEventListener('keydown', (e) => {
        if (!range || ['INPUT', 'SELECT', 'TEXTAREA'].includes(e.target.tagName)) return;
        if (e.code === 'Space') { e.preventDefault(); setPlaying(!playing); }
        if (e.code === 'ArrowLeft') seek(T - 10e3);
        if (e.code === 'ArrowRight') seek(T + 10e3);
    });

    function sync(fromSeek) {
        if (!range) return;
        const now = performance.now();
        const dt = now - lastTick;
        lastTick = now;
        const speed = +speedEl.value;

        if (playing && !fromSeek) {
            if (master && (!master.covers(T) || master.video.paused || master.video.readyState < 3)) master = null;
            if (!master) master = players.find((p) => p.covers(T) && !p.video.paused && p.video.readyState >= 3) || null;
            const w = master ? master.wallAtCurrent() : null;
            if (w !== null && Math.abs(w - T) < 5000 * speed) T = w;
            else T += dt * speed;
            if ($('onlyMotion').checked && motion.length && !motion.some((m) => T >= m.s - 2000 && T <= m.e + 1000)) {
                const nx = motion.find((m) => m.s - 2000 > T);
                if (nx) { T = nx.s - 2000; players.forEach((p) => { p.forceSeek = true; }); }
                else { setPlaying(false); }
            }
            if (!players.some((p) => p.covers(T))) {
                const next = Math.min(...players.map((p) => p.nextStartAfter(T) ?? Infinity));
                if (isFinite(next)) { T = next; players.forEach((p) => { p.forceSeek = true; }); }
            }
            if (T >= range.to) { T = range.to; setPlaying(false); }
        }

        for (const p of players) {
            if (!p.ready) continue;
            const f = p.fragAtWall(T);
            if (!f) {
                if (!p.video.paused) p.video.pause();
                if (p.frags.length) p.show('нет записи');
                continue;
            }
            const desired = f.start + (T - f.pdt) / 1000;
            const drift = Math.abs(p.video.currentTime - desired);
            const tolerance = p === master ? Infinity : Math.max(1.0, speed * 0.75);
            if (p.forceSeek || drift > tolerance) {
                p.forceSeek = false;
                p.video.currentTime = desired;
            }
            if (p.video.playbackRate !== speed) p.video.playbackRate = speed;
            if (playing && p.video.paused) p.video.play().catch(() => {});
            if (!playing && !p.video.paused) p.video.pause();
            p.show(p.video.readyState < 2 ? 'загрузка…' : '');
            p.el.querySelector('.state').textContent = fmtTime(T);
        }
        clockEl.textContent = fmtFull(T);
        drawTimeline();
    }
    setInterval(() => sync(false), 250);

    // ---------- timeline ----------
    const ROW = 12, HEAD = 18;
    function drawTimeline() {
        if (!range) return;
        const dpr = window.devicePixelRatio || 1;
        const w = canvas.clientWidth, h = HEAD + players.length * ROW + 6;
        if (canvas.width !== w * dpr || canvas.height !== h * dpr) {
            canvas.width = w * dpr; canvas.height = h * dpr; canvas.style.height = h + 'px';
        }
        const c = canvas.getContext('2d');
        c.setTransform(dpr, 0, 0, dpr, 0, 0);
        c.clearRect(0, 0, w, h);
        const span = range.to - range.from;
        const x = (ms) => (ms - range.from) / span * w;
        const css = getComputedStyle(document.body);
        // ticks
        c.fillStyle = css.getPropertyValue('--muted').trim() || '#888';
        c.font = '11px system-ui, sans-serif';
        const steps = [60e3, 300e3, 600e3, 1800e3, 3600e3, 3 * 3600e3, 6 * 3600e3, 12 * 3600e3, 86400e3];
        const step = steps.find((s) => span / s <= w / 70) || 86400e3;
        const tz = new Date().getTimezoneOffset() * 60e3;
        for (let t = Math.ceil((range.from - tz) / step) * step + tz; t < range.to; t += step) {
            const px = x(t);
            c.fillRect(px, HEAD - 4, 1, h);
            c.fillText(step >= 86400e3 ? fmtFull(t).slice(0, 5) : fmtTime(t).slice(0, 5), px + 3, 11);
        }
        // export selection
        if (mark.in !== null || mark.out !== null) {
            const a = mark.in ?? range.from, b = mark.out ?? range.to;
            c.fillStyle = 'rgba(245, 165, 36, .25)';
            c.fillRect(x(a), HEAD - 4, Math.max(2, x(b) - x(a)), h);
        }
        // coverage
        players.forEach((p, i) => {
            const y = HEAD + i * ROW;
            c.fillStyle = 'rgba(127,127,127,.18)';
            c.fillRect(0, y, w, ROW - 3);
            c.fillStyle = css.getPropertyValue('--accent').trim() || '#3b82f6';
            for (const f of p.frags) c.fillRect(x(f.pdt), y, Math.max(1, f.dur * 1000 / span * w + 0.5), ROW - 3);
        });
        // motion events
        c.fillStyle = 'rgba(245, 140, 20, .95)';
        players.forEach((p, i) => {
            const y = HEAD + i * ROW;
            for (const m of p.motion || []) c.fillRect(x(m.s), y + 2, Math.max(2, x(m.e) - x(m.s)), ROW - 7);
        });
        // cursor
        c.fillStyle = '#e5484d';
        c.fillRect(x(T) - 1, 0, 2, h);
    }
    window.addEventListener('resize', drawTimeline);
    let dragging = false;
    const seekFromEvent = (e) => {
        const r = canvas.getBoundingClientRect();
        seek(range.from + (e.clientX - r.left) / r.width * (range.to - range.from));
    };
    canvas.addEventListener('pointerdown', (e) => { if (!range) return; dragging = true; canvas.setPointerCapture(e.pointerId); seekFromEvent(e); });
    canvas.addEventListener('pointermove', (e) => { if (dragging) seekFromEvent(e); });
    canvas.addEventListener('pointerup', () => { dragging = false; });

    // ---------- shift the interval by its own length ----------
    function shiftRange(dir) {
        let a = fromInput(fromEl.value), b = fromInput(toEl.value);
        if (!(a < b)) return;
        const span = b - a;
        a += dir * span; b += dir * span;
        const now = Date.now();
        if (b > now) { b = now; a = now - span; }
        if (LIMIT.from && a < LIMIT.from * 1000) { a = LIMIT.from * 1000; b = a + span; }
        if (LIMIT.to && b > LIMIT.to * 1000) { b = LIMIT.to * 1000; a = b - span; }
        fromEl.value = toInput(a); toEl.value = toInput(b);
        load();
    }
    $('prevRange').addEventListener('click', () => shiftRange(-1));
    $('nextRange').addEventListener('click', () => shiftRange(1));

    // ---------- motion navigation ----------
    $('nextMotion').addEventListener('click', () => {
        const m = motion.find((ev) => ev.s - 2000 > T + 500);
        if (m) seek(m.s - 2000);
    });
    $('prevMotion').addEventListener('click', () => {
        const list = motion.filter((ev) => ev.s - 2000 < T - 3000);
        if (list.length) seek(list[list.length - 1].s - 2000);
    });

    // ---------- export ----------
    function updateSel() {
        const a = mark.in ?? range?.from, b = mark.out ?? range?.to;
        selEl.textContent = range ? `${fmtFull(a)} — ${fmtFull(b)}` : '';
    }
    $('markIn').addEventListener('click', () => { mark.in = T; if (mark.out !== null && mark.out <= T) mark.out = null; updateSel(); drawTimeline(); });
    $('markOut').addEventListener('click', () => { mark.out = T; if (mark.in !== null && mark.in >= T) mark.in = null; updateSel(); drawTimeline(); });
    $('export').addEventListener('click', async () => {
        const a = Math.floor((mark.in ?? range.from) / 1000), b = Math.ceil((mark.out ?? range.to) / 1000);
        if (b - a > 86400) { alert('Фрагмент для экспорта не длиннее 24 часов. Отметьте начало и конец.'); return; }
        const cams = players.map((p) => p.cam.id);
        if (!confirm(`Собрать видео ${fmtFull(a * 1000)} — ${fmtFull(b * 1000)} по ${cams.length} камер(ам)?`)) return;
        try {
            await api('/api/exports', {method: 'POST', body: {from: a, to: b, cameras: cams}});
            location.href = '/exports';
        } catch (e) { alert(e.message); }
    });

    // ---------- initial state ----------
    const q = new URLSearchParams(location.search);
    if (q.get('from') && q.get('to')) {
        fromEl.value = toInput(+q.get('from') * 1000);
        toEl.value = toInput(+q.get('to') * 1000);
        if (q.get('cams')) {
            const ids = q.get('cams').split(',');
            document.querySelectorAll('#camList input').forEach((i) => { i.checked = ids.includes(i.value); });
        }
        load();
    } else {
        setQuick(3600);
        if (LIMIT.from || LIMIT.to) {
            if (LIMIT.from) fromEl.value = toInput(LIMIT.from * 1000);
            if (LIMIT.to) toEl.value = toInput(Math.min(LIMIT.to * 1000, Date.now()));
        }
    }
})();
