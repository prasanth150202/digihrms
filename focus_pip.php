<?php
// Floating focus timer for the board tab, with a picture-in-picture pop-out that keeps
// running when the tab is minimised. Included from tasks.php (?tab=board) — it reads the
// board's own .wsb-card.is-running markup, so it is not a page of its own.
if (!isset($board)) { http_response_code(404); exit; }
?>
<style>
.wsp-dock {
    position:fixed; left:16px; top:50%; transform:translateY(-50%); z-index:1055;
    width:212px; background:var(--card-bg,#fff); border:1px solid var(--card-bdr,#e6edf5);
    border-left:3px solid var(--primary,#3b82f6); border-radius:12px;
    box-shadow:0 10px 30px rgba(15,23,42,.18); font-family:var(--font,inherit);
    padding:11px 12px 10px; user-select:none;
}
[data-theme="dark"] .wsp-dock { box-shadow:0 10px 30px rgba(0,0,0,.55); }
.wsp-dock[hidden] { display:none; }
.wsp-dock.dragging { opacity:.85; cursor:grabbing; }
.wsp-hd { display:flex; align-items:center; gap:6px; margin-bottom:7px; cursor:grab; }
.wsp-hd .wsp-lbl { font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em;
    color:var(--text-muted,#94a3b8); }
.wsp-hd .wsp-btns { margin-left:auto; display:flex; gap:2px; }
.wsp-hd button { background:transparent; border:0; color:var(--text-muted,#94a3b8); padding:1px 4px;
    border-radius:6px; font-size:.78rem; line-height:1; cursor:pointer; }
.wsp-hd button:hover { background:rgba(59,130,246,.12); color:var(--primary,#3b82f6); }
.wsp-dot { width:7px; height:7px; border-radius:50%; background:var(--primary,#3b82f6);
    box-shadow:0 0 0 0 rgba(59,130,246,.55); animation:wsbPulse 1.8s infinite; flex-shrink:0; }
.wsp-clock { font-size:1.42rem; font-weight:800; letter-spacing:.01em; line-height:1.1;
    color:var(--primary,#3b82f6); font-variant-numeric:tabular-nums; }
.wsp-task { font-size:.74rem; font-weight:600; color:var(--text-primary,#0f172a); margin-top:3px;
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.wsp-sub { font-size:.66rem; color:var(--text-muted,#94a3b8); margin-top:4px; }
.wsp-dock.collapsed { width:auto; padding:7px 11px; }
.wsp-dock.collapsed .wsp-body, .wsp-dock.collapsed .wsp-lbl { display:none; }
.wsp-dock.collapsed .wsp-hd { margin-bottom:0; }
.wsp-dock.collapsed::after { content:attr(data-mini); font-size:.8rem; font-weight:800;
    color:var(--primary,#3b82f6); font-variant-numeric:tabular-nums; margin-left:6px; }
.wsp-dock.collapsed .wsp-hd { display:inline-flex; }
/* The fallback pops a <video> out, and a display:none video is refused PiP — park it off-screen. */
.wsp-off { position:fixed; left:-9999px; top:0; opacity:0; pointer-events:none; }
</style>

<div class="wsp-dock" id="wspDock" hidden data-mini="00:00:00">
    <div class="wsp-hd" id="wspGrip">
        <span class="wsp-dot"></span>
        <span class="wsp-lbl">Tracking</span>
        <span class="wsp-btns">
            <button type="button" id="wspPop" title="Pop out — keeps running when this tab is minimised">
                <i class="bi bi-pip"></i>
            </button>
            <button type="button" id="wspToggle" title="Collapse"><i class="bi bi-dash-lg"></i></button>
        </span>
    </div>
    <div class="wsp-body">
        <div class="wsp-clock" id="wspClock">00:00:00</div>
        <div class="wsp-task" id="wspTask">—</div>
        <div class="wsp-sub" id="wspSub"></div>
    </div>
</div>

<canvas id="wspCanvas" class="wsp-off" width="400" height="225"></canvas>
<video id="wspVideo" class="wsp-off" muted playsinline></video>

<script>
(function () {
    var dock = document.getElementById('wspDock');
    if (!dock) return;
    var board = document.querySelector('.wsb-board');

    var elClock = document.getElementById('wspClock'),
        elTask  = document.getElementById('wspTask'),
        elSub   = document.getElementById('wspSub'),
        canvas  = document.getElementById('wspCanvas'),
        video   = document.getElementById('wspVideo');

    var pipWin = null;      // Document PiP window, when that API is available
    var videoPip = false;   // canvas→video PiP, the fallback for Safari

    function pad(n) { return String(n).padStart(2, '0'); }
    function clock(sec) {
        return pad(Math.floor(sec / 3600)) + ':' + pad(Math.floor((sec % 3600) / 60)) + ':' + pad(sec % 60);
    }

    // The board owns the timers; read them off its cards rather than keeping a second copy.
    // card._t0 is the anchor the board sets, so both clocks always agree to the second.
    function running() {
        if (!board) return [];
        return Array.from(board.querySelectorAll('.wsb-card.is-running')).map(function (card) {
            var t = card.querySelector('.wsb-timer');
            var t0 = card._t0 !== undefined
                ? card._t0
                : Date.now() - (parseInt(t ? t.dataset.elapsed : 0, 10) || 0) * 1000;
            var link = card.querySelector('.wsb-title');
            return {
                title: link ? link.textContent.trim() : 'Task #' + card.dataset.taskId,
                secs: Math.max(0, Math.floor((Date.now() - t0) / 1000))
            };
        }).sort(function (a, b) { return b.secs - a.secs; });
    }

    function render() {
        var list = running(), lead = list[0];
        dock.hidden = !list.length && !pipWin && !videoPip;
        var time = lead ? clock(lead.secs) : '00:00:00';
        var name = lead ? lead.title : 'Nothing in In Progress';
        var sub  = list.length > 1 ? '+' + (list.length - 1) + ' more card(s) running' : (lead ? 'In Progress' : '');

        elClock.textContent = time;
        elTask.textContent = name;
        elSub.textContent = sub;
        dock.dataset.mini = time;
        if (pipWin) paintPip(time, name, sub, !!lead);
        if (videoPip) paintCanvas(time, name, sub, !!lead);
        document.title = lead ? time + ' · ' + name : baseTitle;
    }
    var baseTitle = document.title;

    // A backgrounded tab throttles setInterval to about once a minute, so the tick comes
    // from a worker (not throttled) — and from the PiP window itself, which is never hidden.
    var worker = null;
    try {
        worker = new Worker(URL.createObjectURL(new Blob(
            ['setInterval(function(){postMessage(0)},1000)'], { type: 'text/javascript' })));
        worker.onmessage = render;
    } catch (e) {
        setInterval(render, 1000);
    }
    document.addEventListener('wsb:timers', render);
    render();

    /* ── drag the dock anywhere, remember where ─────────────────────────── */
    try {
        var saved = JSON.parse(localStorage.getItem('wspDockPos') || 'null');
        if (saved) { dock.style.left = saved.x + 'px'; dock.style.top = saved.y + 'px'; dock.style.transform = 'none'; }
        if (localStorage.getItem('wspDockCollapsed') === '1') dock.classList.add('collapsed');
    } catch (e) {}

    var drag = null;
    document.getElementById('wspGrip').addEventListener('pointerdown', function (e) {
        if (e.target.closest('button')) return;
        var r = dock.getBoundingClientRect();
        drag = { dx: e.clientX - r.left, dy: e.clientY - r.top };
        dock.classList.add('dragging');
        dock.setPointerCapture(e.pointerId);
    });
    dock.addEventListener('pointermove', function (e) {
        if (!drag) return;
        var x = Math.max(4, Math.min(window.innerWidth - dock.offsetWidth - 4, e.clientX - drag.dx));
        var y = Math.max(4, Math.min(window.innerHeight - dock.offsetHeight - 4, e.clientY - drag.dy));
        dock.style.transform = 'none';
        dock.style.left = x + 'px';
        dock.style.top = y + 'px';
    });
    dock.addEventListener('pointerup', function () {
        if (!drag) return;
        drag = null;
        dock.classList.remove('dragging');
        try {
            localStorage.setItem('wspDockPos', JSON.stringify(
                { x: parseInt(dock.style.left, 10), y: parseInt(dock.style.top, 10) }));
        } catch (e) {}
    });
    document.getElementById('wspToggle').addEventListener('click', function () {
        dock.classList.toggle('collapsed');
        try { localStorage.setItem('wspDockCollapsed', dock.classList.contains('collapsed') ? '1' : '0'); } catch (e) {}
    });

    /* ── pop-out ────────────────────────────────────────────────────────── */
    var PIP_CSS = ''
      + 'html,body{margin:0;height:100%;background:#0b1220;color:#e2e8f0;'
      + 'font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;overflow:hidden;cursor:pointer}'
      + '.w{height:100%;display:flex;flex-direction:column;justify-content:center;padding:10px 16px;box-sizing:border-box}'
      + '.r{display:flex;align-items:center;gap:8px}'
      + '.d{width:9px;height:9px;border-radius:50%;background:#3b82f6;animation:p 1.8s infinite}'
      + '@keyframes p{0%{box-shadow:0 0 0 0 rgba(59,130,246,.6)}70%{box-shadow:0 0 0 9px rgba(59,130,246,0)}'
      + '100%{box-shadow:0 0 0 0 rgba(59,130,246,0)}}'
      + '.l{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#7c8db0}'
      + '.c{font-size:clamp(30px,13vw,60px);font-weight:800;line-height:1.05;color:#60a5fa;'
      + 'font-variant-numeric:tabular-nums;margin:2px 0 4px}'
      + '.t{font-size:13px;font-weight:600;color:#e2e8f0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
      + '.s{font-size:11px;color:#7c8db0;margin-top:3px}'
      + '.idle .c{color:#64748b}.idle .d{background:#64748b;animation:none}';

    function paintPip(time, name, sub, live) {
        var d = pipWin.document;
        d.body.className = live ? '' : 'idle';
        d.getElementById('c').textContent = time;
        d.getElementById('t').textContent = name;
        d.getElementById('s').textContent = sub;
    }

    async function openDocPip() {
        pipWin = await documentPictureInPicture.requestWindow({ width: 320, height: 168 });
        var d = pipWin.document;
        d.head.innerHTML = '<style>' + PIP_CSS + '</style>';
        d.body.innerHTML =
            '<div class="w"><div class="r"><span class="d"></span><span class="l">Tracking</span></div>'
          + '<div class="c" id="c">00:00:00</div><div class="t" id="t"></div><div class="s" id="s"></div></div>';
        // The PiP window is always visible, so its own interval is never throttled.
        pipWin.setInterval(render, 1000);
        d.body.addEventListener('click', function () { try { window.focus(); } catch (e) {} });
        pipWin.addEventListener('pagehide', function () { pipWin = null; render(); });
        render();
    }

    function paintCanvas(time, name, sub, live) {
        var g = canvas.getContext('2d'), W = canvas.width, H = canvas.height;
        g.fillStyle = '#0b1220'; g.fillRect(0, 0, W, H);
        g.fillStyle = live ? '#3b82f6' : '#64748b';
        g.beginPath(); g.arc(30, 40, 7, 0, Math.PI * 2); g.fill();
        g.fillStyle = '#7c8db0'; g.font = '700 15px system-ui, sans-serif';
        g.fillText('TRACKING', 46, 46);
        g.fillStyle = live ? '#60a5fa' : '#64748b'; g.font = '800 62px system-ui, sans-serif';
        g.fillText(time, 26, 122);
        g.fillStyle = '#e2e8f0'; g.font = '600 18px system-ui, sans-serif';
        g.fillText(name.length > 30 ? name.slice(0, 29) + '…' : name, 26, 160);
        g.fillStyle = '#7c8db0'; g.font = '400 14px system-ui, sans-serif';
        g.fillText(sub, 26, 186);
    }

    // Safari has no Document PiP; it will however put a <video> in a floating window, so
    // paint the same panel onto a canvas and stream that.
    async function openVideoPip() {
        var native = !!video.requestPictureInPicture, webkit = !!video.webkitSetPresentationMode;
        if (!native && !webkit) throw new Error('NotSupportedError');
        paintCanvas('00:00:00', '', '', false);
        if (!video.srcObject) video.srcObject = canvas.captureStream(1);
        await video.play();
        if (native) {
            await video.requestPictureInPicture();
            video.addEventListener('leavepictureinpicture', function () { videoPip = false; }, { once: true });
        } else {
            video.webkitSetPresentationMode('picture-in-picture');
            video.addEventListener('webkitpresentationmodechanged', function () {
                if (video.webkitPresentationMode !== 'picture-in-picture') videoPip = false;
            });
        }
        videoPip = true;
        render();
    }

    document.getElementById('wspPop').addEventListener('click', function () {
        if (pipWin) { pipWin.close(); pipWin = null; render(); return; }
        var go = window.documentPictureInPicture ? openDocPip() : openVideoPip();
        go.catch(function (err) {
            videoPip = false;
            showToast('This browser blocked the floating timer (' + (err && err.name || 'error') +
                      '). Chrome or Edge 116+ supports it.', 'warning', 'Pop-out');
        });
    });

    // A reload or a navigation would leave an orphaned PiP window behind.
    window.addEventListener('pagehide', function () { if (pipWin) pipWin.close(); });
})();
</script>
