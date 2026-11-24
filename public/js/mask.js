/* Zone editor: 32x24 grid over a camera snapshot, value stored as a string of 0/1. */
(function () {
    const GW = 32, GH = 24;
    document.querySelectorAll('.mask-editor').forEach((ed) => {
        const block = ed.closest('.mask-block');
        const input = block.querySelector(`input[name="${ed.dataset.input}"]`);
        const img = ed.querySelector('img'), canvas = ed.querySelector('canvas');
        const rgb = ed.dataset.color;
        const cells = (input.value && input.value.length === GW * GH)
            ? input.value.split('').map((c) => c === '1')
            : new Array(GW * GH).fill(false);
        const save = () => {
            const any = cells.some((c) => c), all = cells.every((c) => c);
            input.value = (!any || all) ? '' : cells.map((c) => (c ? '1' : '0')).join('');
        };
        function draw() {
            const w = canvas.clientWidth, h = canvas.clientHeight, dpr = window.devicePixelRatio || 1;
            if (!w || !h) return;
            canvas.width = w * dpr; canvas.height = h * dpr;
            const c = canvas.getContext('2d');
            c.setTransform(dpr, 0, 0, dpr, 0, 0);
            const cw = w / GW, ch = h / GH, none = !cells.some((x) => x);
            for (let i = 0; i < GW * GH; i++) {
                const x = (i % GW) * cw, y = Math.floor(i / GW) * ch;
                if (cells[i]) { c.fillStyle = `rgba(${rgb},.38)`; c.fillRect(x, y, cw, ch); }
                else if (!none) { c.fillStyle = 'rgba(0,0,0,.5)'; c.fillRect(x, y, cw, ch); }
            }
            c.strokeStyle = 'rgba(255,255,255,.12)';
            c.beginPath();
            for (let x = 1; x < GW; x++) { c.moveTo(x * cw, 0); c.lineTo(x * cw, h); }
            for (let y = 1; y < GH; y++) { c.moveTo(0, y * ch); c.lineTo(w, y * ch); }
            c.stroke();
            if (none) {
                c.fillStyle = 'rgba(0,0,0,.55)'; c.fillRect(0, 0, w, 28);
                c.fillStyle = '#fff'; c.font = '13px system-ui, sans-serif';
                c.fillText('Весь кадр. Выделите зону мышью (прямоугольником).', 10, 19);
            }
        }
        // drag = rectangle: filled if it started on an empty cell, erased otherwise
        let drag = null;
        const cellAt = (e) => {
            const r = canvas.getBoundingClientRect();
            const x = Math.min(GW - 1, Math.max(0, Math.floor((e.clientX - r.left) / r.width * GW)));
            const y = Math.min(GH - 1, Math.max(0, Math.floor((e.clientY - r.top) / r.height * GH)));
            return [x, y];
        };
        const applyRect = (base) => {
            const [x0, y0] = drag.a, [x1, y1] = drag.b;
            for (let i = 0; i < GW * GH; i++) cells[i] = base[i];
            for (let y = Math.min(y0, y1); y <= Math.max(y0, y1); y++)
                for (let x = Math.min(x0, x1); x <= Math.max(x0, x1); x++) cells[y * GW + x] = drag.value;
        };
        canvas.addEventListener('pointerdown', (e) => {
            const c = cellAt(e);
            drag = {a: c, b: c, value: !cells[c[1] * GW + c[0]], base: cells.slice()};
            canvas.setPointerCapture(e.pointerId);
            applyRect(drag.base); draw();
        });
        canvas.addEventListener('pointermove', (e) => {
            if (!drag) return;
            const c = cellAt(e);
            if (c[0] !== drag.b[0] || c[1] !== drag.b[1]) { drag.b = c; applyRect(drag.base); draw(); }
        });
        const stop = () => { if (drag) { drag = null; save(); draw(); } };
        canvas.addEventListener('pointerup', stop);
        canvas.addEventListener('pointercancel', stop);
        block.querySelector('[data-mask-all]').addEventListener('click', () => { cells.fill(true); save(); draw(); });
        block.querySelector('[data-mask-none]').addEventListener('click', () => { cells.fill(false); save(); draw(); });
        img.addEventListener('load', draw);
        img.addEventListener('error', () => { ed.innerHTML = '<p class="muted small">Снимка пока нет: камера ещё ничего не записала.</p>'; });
        window.addEventListener('resize', draw);
        if (img.complete && img.naturalWidth) draw();
    });
})();
