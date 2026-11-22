import '/vendor/video-stream.js';

const grid = document.getElementById('grid');
const cols = document.getElementById('cols');

function applyCols() {
    const v = +cols.value;
    grid.style.gridTemplateColumns = v ? `repeat(${v}, minmax(0, 1fr))` : '';
    try { localStorage.setItem('live.cols', cols.value); } catch (e) {}
}
try { cols.value = localStorage.getItem('live.cols') || '0'; } catch (e) {}
cols.addEventListener('change', applyCols);
applyCols();

for (const el of document.querySelectorAll('video-stream[data-src]')) {
    // WebRTC first (lowest delay), then MSE over websocket, MJPEG as last resort
    el.mode = 'webrtc,mse,hls,mjpeg';
    el.background = false;
    el.visibilityThreshold = 0;
    el.src = el.dataset.src;
}

grid.addEventListener('click', (ev) => {
    const btn = ev.target.closest('[data-full]');
    if (!btn) return;
    const tile = btn.closest('.tile');
    if (document.fullscreenElement) {
        document.exitFullscreen();
    } else if (tile.requestFullscreen) {
        tile.requestFullscreen();
    } else {
        tile.classList.toggle('max');
    }
});

grid.addEventListener('dblclick', (ev) => {
    const tile = ev.target.closest('.tile');
    if (tile) tile.querySelector('[data-full]').click();
});
