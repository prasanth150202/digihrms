</div><!-- /main -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="assets/inline-edit.js"></script>

<!-- ── Global Toast & Confirm UI ─────────────────────────────────────────── -->
<style>
#hrms-toast-wrap {
    position: fixed; bottom: 24px; right: 24px; z-index: 9999;
    display: flex; flex-direction: column; gap: 10px; pointer-events: none;
}
.hrms-toast {
    display: flex; align-items: flex-start; gap: 12px;
    min-width: 280px; max-width: 380px;
    background: var(--card-bg, #fff); border-radius: 12px;
    border: 1px solid var(--card-bdr, #e2e8f0);
    box-shadow: 0 8px 32px rgba(0,0,0,.18);
    padding: 14px 16px; pointer-events: all;
    animation: toast-in .22s cubic-bezier(.34,1.56,.64,1);
    transition: opacity .3s, transform .3s;
}
.hrms-toast.hiding { opacity:0; transform: translateX(30px); }
.hrms-toast-icon { font-size: 18px; flex-shrink: 0; margin-top: 1px; }
.hrms-toast-body { flex: 1; }
.hrms-toast-title { font-size: 13px; font-weight: 700; color: var(--text-primary, #1a202c); margin-bottom: 2px; }
.hrms-toast-msg   { font-size: 12px; color: var(--text-secondary, #64748b); line-height: 1.4; }
.hrms-toast-close { background:none; border:none; color:var(--text-muted,#94a3b8); cursor:pointer; font-size:16px; padding:0; flex-shrink:0; }
.hrms-toast.toast-success .hrms-toast-icon { color: #16a34a; }
.hrms-toast.toast-error   .hrms-toast-icon { color: #ef4444; }
.hrms-toast.toast-warning .hrms-toast-icon { color: #f59e0b; }
.hrms-toast.toast-info    .hrms-toast-icon { color: #3b82f6; }
@keyframes toast-in { from { opacity:0; transform:translateX(30px); } to { opacity:1; transform:translateX(0); } }

/* Confirm dialog */
#hrms-confirm-backdrop {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.45); z-index: 10000;
    align-items: center; justify-content: center;
}
#hrms-confirm-backdrop.show { display: flex; }
#hrms-confirm-box {
    background: var(--card-bg, #fff); border-radius: 14px;
    padding: 28px 28px 20px; max-width: 360px; width: 90%;
    box-shadow: 0 20px 60px rgba(0,0,0,.25);
    animation: toast-in .2s ease;
}
#hrms-confirm-icon { font-size: 2rem; margin-bottom: 10px; }
#hrms-confirm-title { font-size: 15px; font-weight: 700; color: var(--text-primary,#1a202c); margin-bottom: 6px; }
#hrms-confirm-msg   { font-size: 13px; color: var(--text-secondary,#64748b); line-height: 1.5; margin-bottom: 20px; }
#hrms-confirm-actions { display: flex; gap: 10px; justify-content: flex-end; }
#hrms-confirm-ok     { padding: 8px 20px; border-radius: 8px; border: none; font-weight: 700; font-size: 13px; cursor: pointer; }
#hrms-confirm-cancel { padding: 8px 20px; border-radius: 8px; border: 1px solid var(--card-bdr,#e2e8f0); background: transparent; color: var(--text-secondary,#64748b); font-weight: 600; font-size: 13px; cursor: pointer; }

/* Info/alert dialog */
#hrms-alert-backdrop {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.45); z-index: 10000;
    align-items: center; justify-content: center;
}
#hrms-alert-backdrop.show { display: flex; }
#hrms-alert-box {
    background: var(--card-bg,#fff); border-radius: 14px;
    padding: 28px 28px 20px; max-width: 360px; width: 90%;
    box-shadow: 0 20px 60px rgba(0,0,0,.25);
    animation: toast-in .2s ease;
}
#hrms-alert-icon  { font-size: 2rem; margin-bottom: 10px; }
#hrms-alert-title { font-size: 15px; font-weight: 700; color: var(--text-primary,#1a202c); margin-bottom: 6px; }
#hrms-alert-msg   { font-size: 13px; color: var(--text-secondary,#64748b); line-height: 1.5; margin-bottom: 20px; }
#hrms-alert-ok    { padding: 8px 24px; border-radius: 8px; border: none; font-weight: 700; font-size: 13px; cursor: pointer; float: right; }
</style>

<div id="hrms-toast-wrap"></div>

<div id="hrms-confirm-backdrop">
    <div id="hrms-confirm-box">
        <div id="hrms-confirm-icon"></div>
        <div id="hrms-confirm-title"></div>
        <div id="hrms-confirm-msg"></div>
        <div id="hrms-confirm-actions">
            <button id="hrms-confirm-cancel">Cancel</button>
            <button id="hrms-confirm-ok">Confirm</button>
        </div>
    </div>
</div>

<div id="hrms-alert-backdrop">
    <div id="hrms-alert-box">
        <div id="hrms-alert-icon"></div>
        <div id="hrms-alert-title"></div>
        <div id="hrms-alert-msg"></div>
        <button id="hrms-alert-ok">OK</button>
    </div>
</div>

<script>
// ── Toast ─────────────────────────────────────────────────────────────────
function showToast(msg, type = 'info', title = '', duration = 4000) {
    const icons = { success:'bi-check-circle-fill', error:'bi-x-circle-fill', warning:'bi-exclamation-triangle-fill', info:'bi-info-circle-fill' };
    const titles = { success: title||'Success', error: title||'Error', warning: title||'Warning', info: title||'Info' };
    const wrap = document.getElementById('hrms-toast-wrap');
    const t = document.createElement('div');
    t.className = `hrms-toast toast-${type}`;
    t.innerHTML = `
        <i class="bi ${icons[type]||icons.info} hrms-toast-icon"></i>
        <div class="hrms-toast-body">
            <div class="hrms-toast-title">${titles[type]}</div>
            <div class="hrms-toast-msg">${msg}</div>
        </div>
        <button class="hrms-toast-close" onclick="this.closest('.hrms-toast').remove()"><i class="bi bi-x"></i></button>`;
    wrap.appendChild(t);
    if (duration > 0) setTimeout(() => { t.classList.add('hiding'); setTimeout(() => t.remove(), 320); }, duration);
}

// ── Alert (replaces alert()) ──────────────────────────────────────────────
function showAlert(msg, type = 'info', title = '') {
    const icons = { success:'bi-check-circle-fill', error:'bi-x-circle-fill', warning:'bi-exclamation-triangle-fill', info:'bi-info-circle-fill' };
    const colors = { success:'#16a34a', error:'#ef4444', warning:'#f59e0b', info:'#3b82f6' };
    const btnColors = { success:'#16a34a', error:'#ef4444', warning:'#f59e0b', info:'#3b82f6' };
    const defaultTitles = { success:'Success', error:'Error', warning:'Warning', info:'Info' };
    document.getElementById('hrms-alert-icon').innerHTML  = `<i class="bi ${icons[type]||icons.info}" style="color:${colors[type]||colors.info};"></i>`;
    document.getElementById('hrms-alert-title').textContent = title || defaultTitles[type] || 'Notice';
    document.getElementById('hrms-alert-msg').innerHTML   = msg;
    document.getElementById('hrms-alert-ok').style.cssText = `background:${btnColors[type]||btnColors.info};color:#fff;`;
    document.getElementById('hrms-alert-backdrop').classList.add('show');
    return new Promise(resolve => {
        document.getElementById('hrms-alert-ok').onclick = () => {
            document.getElementById('hrms-alert-backdrop').classList.remove('show');
            resolve();
        };
    });
}

// ── Confirm (replaces confirm()) ──────────────────────────────────────────
function showConfirm(msg, { title='Are you sure?', okText='Confirm', okColor='#ef4444', icon='⚠️' } = {}) {
    document.getElementById('hrms-confirm-icon').textContent  = icon;
    document.getElementById('hrms-confirm-title').textContent = title;
    document.getElementById('hrms-confirm-msg').innerHTML     = msg;
    document.getElementById('hrms-confirm-ok').textContent    = okText;
    document.getElementById('hrms-confirm-ok').style.cssText  = `background:${okColor};color:#fff;`;
    document.getElementById('hrms-confirm-backdrop').classList.add('show');
    return new Promise(resolve => {
        document.getElementById('hrms-confirm-ok').onclick = () => {
            document.getElementById('hrms-confirm-backdrop').classList.remove('show');
            resolve(true);
        };
        document.getElementById('hrms-confirm-cancel').onclick = () => {
            document.getElementById('hrms-confirm-backdrop').classList.remove('show');
            resolve(false);
        };
    });
}
</script>
</body>
</html>
