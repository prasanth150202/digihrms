// ── HRMS shared confirm modal — replaces all native confirm() calls ──
// Usage (sync anchor/form guard):  onclick="return hConfirmSync(event, 'Are you sure?')"
//                                  onsubmit="return hConfirmSync(event, 'Delete this?')"
// Usage (async, returns Promise):  if (!await hConfirm('Delete?')) return;

(function () {

  // Inject modal markup + styles once
  function _ensureModal() {
    if (document.getElementById('hc-overlay')) return;
    const style = document.createElement('style');
    style.textContent = `
      #hc-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:99999;display:flex;align-items:center;justify-content:center;padding:16px;animation:hc-in .12s ease}
      @keyframes hc-in{from{opacity:0}to{opacity:1}}
      #hc-box{background:var(--card-bg,#fff);border-radius:14px;box-shadow:0 8px 40px rgba(0,0,0,.22);max-width:400px;width:100%;overflow:hidden;animation:hc-up .14s ease}
      @keyframes hc-up{from{transform:translateY(8px);opacity:0}to{transform:translateY(0);opacity:1}}
      #hc-title{padding:18px 20px 0;font-size:15px;font-weight:700;color:var(--text-primary,#111)}
      #hc-msg{padding:10px 20px 18px;font-size:13.5px;color:var(--text-secondary,#555);line-height:1.5}
      #hc-footer{padding:12px 20px;display:flex;justify-content:flex-end;gap:8px;border-top:1px solid var(--border-color,#e5e7eb)}
      #hc-cancel{padding:7px 18px;border-radius:8px;border:1px solid var(--border-color,#d1d5db);background:transparent;font-size:13px;font-weight:600;cursor:pointer;color:var(--text-primary,#111)}
      #hc-cancel:hover{background:var(--primary-bg,#f3f4f6)}
      #hc-ok{padding:7px 18px;border-radius:8px;border:none;font-size:13px;font-weight:700;cursor:pointer;color:#fff;background:#ef4444}
      #hc-ok:hover{filter:brightness(.92)}
      #hc-ok.safe{background:#2563eb}
      .hc-spinner{display:inline-block;width:10px;height:10px;border:2px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:hc-spin .5s linear infinite;margin-right:6px;vertical-align:middle}
      @keyframes hc-spin{to{transform:rotate(360deg)}}
    `;
    document.head.appendChild(style);
    const el = document.createElement('div');
    el.id = 'hc-overlay';
    el.style.display = 'none';
    el.innerHTML = `<div id="hc-box"><div id="hc-title"></div><div id="hc-msg"></div><div id="hc-footer"><button id="hc-cancel">Cancel</button><button id="hc-ok">OK</button></div></div>`;
    document.body.appendChild(el);
    el.addEventListener('click', function (e) { if (e.target === el) _resolve(false); });
    document.getElementById('hc-cancel').addEventListener('click', function () { _resolve(false); });
    document.getElementById('hc-ok').addEventListener('click', function () { _resolve(true); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && document.getElementById('hc-overlay').style.display !== 'none') _resolve(false);
    });
  }

  var _pendingResolve = null;
  var _pendingAction  = null; // form submit / anchor href to execute on confirm

  function _resolve(ok) {
    const overlay = document.getElementById('hc-overlay');
    if (overlay) overlay.style.display = 'none';
    if (_pendingResolve) { _pendingResolve(ok); _pendingResolve = null; }
    if (ok && _pendingAction) { _pendingAction(); }
    _pendingAction = null;
  }

  function _open(msg, title, okLabel, safe) {
    _ensureModal();
    document.getElementById('hc-title').textContent = title || 'Confirm';
    document.getElementById('hc-msg').textContent   = msg;
    const ok = document.getElementById('hc-ok');
    ok.textContent = okLabel || 'OK';
    ok.className   = safe ? 'safe' : '';
    document.getElementById('hc-overlay').style.display = 'flex';
    ok.focus();
    return new Promise(function (resolve) { _pendingResolve = resolve; });
  }

  // Async version — use with await
  window.hConfirm = function (msg, opts) {
    opts = opts || {};
    return _open(msg, opts.title, opts.ok, opts.safe);
  };

  // Sync intercept for onclick="return hConfirmSync(event, msg)" and onsubmit="return hConfirmSync(event, msg)"
  // Prevents default, opens modal, then re-fires the original action on confirm
  window.hConfirmSync = function (event, msg, opts) {
    event.preventDefault();
    event.stopImmediatePropagation();
    opts = opts || {};
    var target = event.currentTarget || event.target;
    var action;
    if (target.tagName === 'FORM' || target.form) {
      var form = target.tagName === 'FORM' ? target : target.form;
      action = function () { form.submit(); };
    } else if (target.href && target.href !== '#' && !target.href.endsWith('#')) {
      var href = target.href;
      action = function () { window.location.href = href; };
    } else if (target.closest('a[href]')) {
      var href2 = target.closest('a[href]').href;
      action = function () { window.location.href = href2; };
    } else {
      action = null;
    }
    _pendingAction = action;
    _open(msg, opts.title, opts.ok || 'Confirm', opts.safe);
    return false;
  };

  // ── AJAX confirm helper ─────────────────────────────────────────
  // Usage on any element:
  //   data-hc-msg="Are you sure?"
  //   data-hc-url="?action=delete&id=123"        (GET) or data-hc-form="#myForm" (POST)
  //   data-hc-method="POST"                       optional, default GET
  //   data-hc-target="#row-123"                   element to remove on success
  //   data-hc-reload="1"                          reload page on success instead
  //
  // Wire up at DOM ready — looks for [data-hc-msg] on buttons/links

  document.addEventListener('DOMContentLoaded', function () {
    document.addEventListener('click', function (e) {
      var el = e.target.closest('[data-hc-msg]');
      if (!el) return;
      e.preventDefault();
      e.stopImmediatePropagation();
      var msg     = el.dataset.hcMsg;
      var url     = el.dataset.hcUrl || el.href;
      var method  = (el.dataset.hcMethod || 'GET').toUpperCase();
      var reload  = el.dataset.hcReload === '1';
      var formSel = el.dataset.hcForm;

      // Capture target BEFORE opening modal (DOM is intact at this point)
      var targetEl = el.dataset.hcTarget ? document.querySelector(el.dataset.hcTarget) : null;

      _open(msg, el.dataset.hcTitle || 'Confirm', el.dataset.hcOk || 'Confirm', el.dataset.hcSafe === '1')
        .then(function (ok) {
          if (!ok) return;
          var origHtml = el.innerHTML;
          el.disabled = true;
          el.innerHTML = '<span class="hc-spinner"></span>';

          var fetchOpts = { credentials: 'same-origin' };
          var fetchUrl  = url;

          if (formSel) {
            var form = document.querySelector(formSel);
            if (form) {
              fetchOpts.method = 'POST';
              fetchOpts.body   = new FormData(form);
              fetchUrl = form.action || window.location.href;
            }
          } else if (method === 'POST') {
            fetchOpts.method = 'POST';
            // Send _ajax flag in body; keep action/task_id in the URL query string
            // so PHP can read action from $_GET and _ajax flag from $_POST
            fetchOpts.headers = { 'Content-Type': 'application/x-www-form-urlencoded' };
            fetchOpts.body    = '_ajax=1';
          } else {
            fetchOpts.method = 'GET';
          }

          fetch(fetchUrl, fetchOpts)
            .then(function (r) {
              if (!r.ok) throw new Error('Server error ' + r.status);
              return r.json();
            })
            .then(function (d) {
              if (!d || !d.ok) throw new Error(d && d.error ? d.error : 'Action failed');
              if (reload) { window.location.reload(); return; }
              if (targetEl) {
                // Remove all elements sharing this id (card + table row)
                var sel = el.dataset.hcTarget;
                var all = sel ? document.querySelectorAll(sel) : [targetEl];
                all.forEach(function(node) {
                  node.style.transition = 'opacity .2s';
                  node.style.opacity = '0';
                  setTimeout(function () { node.remove(); }, 280);
                });
              } else {
                window.location.reload();
              }
            })
            .catch(function (err) {
              el.disabled = false;
              el.innerHTML = origHtml;
              alert('Error: ' + err.message);
            });
        });
    });
  });

})();
