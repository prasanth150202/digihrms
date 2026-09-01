<?php
/**
 * DigiHRMS AI Copilot (BETA) — floating chat widget.
 * Included from footer.php, only when current_user_is_beta() is true.
 */
if (!function_exists('current_user_is_beta')) {
    require_once __DIR__ . '/ai_agent_helper.php';
}
if (!current_user_is_beta()) return;
$__cfg_ok = aiagent_configured();
?>
<script>window.HRMS_CSRF = <?= json_encode(csrf_token()) ?>;</script>
<style>
#aic-launch{position:fixed;bottom:24px;right:24px;z-index:9990;width:52px;height:52px;border-radius:50%;
 border:none;cursor:pointer;background:linear-gradient(135deg,#2563eb,#1e3a5f);color:#fff;font-size:22px;
 box-shadow:0 8px 26px rgba(37,99,235,.4);display:flex;align-items:center;justify-content:center;transition:transform .15s}
#aic-launch:hover{transform:scale(1.07)}
#aic-panel{position:fixed;bottom:24px;right:24px;z-index:9991;width:min(420px,calc(100vw - 32px));
 height:min(640px,calc(100vh - 48px));background:var(--card-bg,#fff);border:1px solid var(--card-bdr,#e2e8f0);
 border-radius:16px;box-shadow:0 24px 70px rgba(0,0,0,.28);display:none;flex-direction:column;overflow:hidden}
#aic-panel.open{display:flex}
.aic-hd{display:flex;align-items:center;gap:8px;padding:12px 14px;border-bottom:1px solid var(--card-bdr,#e2e8f0);
 background:linear-gradient(135deg,#1e3a5f,#0f1729);color:#fff}
.aic-hd b{font-size:13px;font-weight:700}
.aic-hd .aic-beta{font-size:9px;font-weight:800;letter-spacing:.5px;background:rgba(255,255,255,.18);
 padding:2px 6px;border-radius:5px}
.aic-hd .aic-sp{flex:1}
.aic-hd button{background:none;border:none;color:#cbd5e1;cursor:pointer;font-size:16px;padding:2px 5px;border-radius:6px}
.aic-hd button:hover{background:rgba(255,255,255,.12);color:#fff}
#aic-body{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:12px;
 background:var(--body-bg,#f0f4f8);font-size:13px;line-height:1.5}
.aic-msg{max-width:88%;padding:9px 12px;border-radius:12px;white-space:normal;word-wrap:break-word}
.aic-msg.user{align-self:flex-end;background:#2563eb;color:#fff;border-bottom-right-radius:4px}
.aic-msg.bot{align-self:flex-start;background:var(--card-bg,#fff);border:1px solid var(--card-bdr,#e2e8f0);
 color:var(--text-primary,#0f172a);border-bottom-left-radius:4px}
.aic-msg.tool{align-self:flex-start;max-width:94%;font-size:11px;background:transparent;border:none;padding:0}
.aic-tool-box{border:1px solid var(--card-bdr,#e2e8f0);border-radius:9px;overflow:hidden;background:var(--card-bg,#fff)}
.aic-tool-hd{padding:6px 9px;font-weight:700;font-size:11px;color:var(--text-secondary,#475569);cursor:pointer;
 display:flex;gap:6px;align-items:center;background:rgba(148,163,184,.1)}
.aic-tool-bd{padding:8px 9px;border-top:1px solid var(--card-bdr,#e2e8f0);display:none}
.aic-tool-bd.show{display:block}
.aic-msg pre{background:#0f172a;color:#e2e8f0;padding:9px 11px;border-radius:8px;overflow-x:auto;font-size:11.5px;margin:6px 0}
.aic-msg code{background:rgba(148,163,184,.18);padding:1px 5px;border-radius:4px;font-size:12px}
.aic-msg pre code{background:none;padding:0}
.aic-msg table{border-collapse:collapse;margin:6px 0;font-size:11.5px;display:block;overflow-x:auto}
.aic-msg th,.aic-msg td{border:1px solid var(--card-bdr,#cbd5e1);padding:3px 7px;text-align:left}
.aic-msg th{background:rgba(148,163,184,.15)}
.aic-confirm{align-self:stretch;border:1px solid #f59e0b;border-radius:10px;background:rgba(245,158,11,.08);padding:10px}
.aic-confirm.danger{border-color:#ef4444;background:rgba(239,68,68,.08)}
.aic-confirm .lbl{font-size:11px;font-weight:800;color:#b45309;text-transform:uppercase;letter-spacing:.4px}
.aic-confirm.danger .lbl{color:#b91c1c}
.aic-confirm p{margin:5px 0;font-size:12px;color:var(--text-secondary,#475569)}
.aic-confirm pre{background:#0f172a;color:#e2e8f0;padding:8px 10px;border-radius:7px;overflow-x:auto;font-size:11px;margin:6px 0}
.aic-confirm .btns{display:flex;gap:8px;margin-top:6px}
.aic-confirm button{flex:1;padding:6px;border-radius:7px;border:none;font-weight:700;font-size:12px;cursor:pointer}
.aic-run{background:#16a34a;color:#fff}.aic-skip{background:transparent;border:1px solid var(--card-bdr,#cbd5e1)!important;color:var(--text-secondary,#475569)}
.aic-ft{border-top:1px solid var(--card-bdr,#e2e8f0);padding:10px;display:flex;gap:8px;background:var(--card-bg,#fff)}
.aic-ft textarea{flex:1;resize:none;border:1px solid var(--card-bdr,#cbd5e1);border-radius:9px;padding:8px 10px;
 font:inherit;font-size:13px;max-height:120px;background:var(--body-bg,#f8fafc);color:var(--text-primary,#0f172a)}
.aic-ft button{border:none;background:#2563eb;color:#fff;border-radius:9px;padding:0 14px;font-weight:700;cursor:pointer}
.aic-ft button:disabled{opacity:.5;cursor:default}
.aic-typing{align-self:flex-start;color:var(--text-muted,#94a3b8);font-size:12px;font-style:italic}
.aic-empty{margin:auto;text-align:center;color:var(--text-muted,#94a3b8);font-size:12px;padding:20px}
</style>

<button id="aic-launch" title="DigiHRMS Copilot (beta)"><i class="bi bi-stars"></i></button>

<div id="aic-panel" role="dialog" aria-label="DigiHRMS Copilot">
  <div class="aic-hd">
    <i class="bi bi-stars"></i><b>HRMS Copilot</b><span class="aic-beta">BETA</span>
    <span class="aic-sp"></span>
    <button id="aic-new" title="New chat"><i class="bi bi-plus-lg"></i></button>
    <button id="aic-close" title="Close"><i class="bi bi-dash-lg"></i></button>
  </div>
  <div id="aic-body"></div>
  <div class="aic-ft">
    <textarea id="aic-input" rows="1" placeholder="<?= $__cfg_ok ? 'Ask about or change anything in HRMS…' : 'Set OPENROUTER_API_KEY in .env to enable' ?>" <?= $__cfg_ok ? '' : 'disabled' ?>></textarea>
    <button id="aic-send" <?= $__cfg_ok ? '' : 'disabled' ?>>Send</button>
  </div>
</div>

<script>
(function(){
  const LS = 'hrms_aic_conv';
  const $ = s => document.querySelector(s);
  const panel = $('#aic-panel'), body = $('#aic-body'), input = $('#aic-input'), sendBtn = $('#aic-send');
  let convId = localStorage.getItem(LS) || null;
  let busy = false;

  const esc = s => (s||'').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

  // ── tiny markdown ────────────────────────────────────────────────
  function md(src){
    src = src || '';
    const blocks = [];
    src = src.replace(/```(\w*)\n?([\s\S]*?)```/g, (m,l,c)=>{ blocks.push('<pre><code>'+esc(c.replace(/\n$/,''))+'</code></pre>'); return '@@B@@'+(blocks.length-1)+'@@B@@'; });
    const lines = src.split('\n'); let out = [], i = 0;
    while(i < lines.length){
      let ln = lines[i];
      if(/^\s*\|(.+)\|\s*$/.test(ln) && /^\s*\|[\s:|-]+\|\s*$/.test(lines[i+1]||'')){
        const rows = []; while(i < lines.length && /^\s*\|(.+)\|\s*$/.test(lines[i])) rows.push(lines[i++]);
        const cells = r => r.trim().replace(/^\||\|$/g,'').split('|').map(x=>x.trim());
        let t = '<table><thead><tr>'+cells(rows[0]).map(c=>'<th>'+inl(c)+'</th>').join('')+'</tr></thead><tbody>';
        for(let k=2;k<rows.length;k++) t += '<tr>'+cells(rows[k]).map(c=>'<td>'+inl(c)+'</td>').join('')+'</tr>';
        out.push(t+'</tbody></table>'); continue;
      }
      if(/^\s*[-*]\s+/.test(ln)){
        let li = []; while(i<lines.length && /^\s*[-*]\s+/.test(lines[i])) li.push('<li>'+inl(lines[i++].replace(/^\s*[-*]\s+/,''))+'</li>');
        out.push('<ul>'+li.join('')+'</ul>'); continue;
      }
      let h = ln.match(/^(#{1,4})\s+(.*)/);
      if(h){ out.push('<b>'+inl(h[2])+'</b>'); i++; continue; }
      if(ln.trim()==='') { out.push(''); i++; continue; }
      out.push('<div>'+inl(ln)+'</div>'); i++;
    }
    let html = out.join('\n').replace(/@@B@@(\d+)@@B@@/g, (m,n)=>blocks[n]);
    return html;
    function inl(s){
      return esc(s)
        .replace(/`([^`]+)`/g,'<code>$1</code>')
        .replace(/\*\*([^*]+)\*\*/g,'<b>$1</b>')
        .replace(/\*([^*]+)\*/g,'<i>$1</i>')
        .replace(/\[([^\]]+)\]\(([^)]+)\)/g,'<a href="$2" target="_blank" rel="noopener">$1</a>');
    }
  }

  function bubble(role, html){
    const d = document.createElement('div');
    d.className = 'aic-msg ' + (role === 'user' ? 'user' : 'bot');
    d.innerHTML = role === 'user' ? esc(html) : md(html);
    body.appendChild(d); scroll();
    return d;
  }

  function toolBubble(name, content){
    const d = document.createElement('div');
    d.className = 'aic-msg tool';
    let inner;
    try { inner = JSON.stringify(JSON.parse(content), null, 1); } catch(e){ inner = content; }
    d.innerHTML = '<div class="aic-tool-box"><div class="aic-tool-hd"><i class="bi bi-wrench-adjustable"></i>'+esc(name)+
      ' <span style="flex:1"></span><i class="bi bi-chevron-down"></i></div><div class="aic-tool-bd"><pre>'+esc(inner)+'</pre></div></div>';
    d.querySelector('.aic-tool-hd').onclick = () => d.querySelector('.aic-tool-bd').classList.toggle('show');
    body.appendChild(d); scroll();
  }

  const scroll = () => { body.scrollTop = body.scrollHeight; };

  function setBusy(b){ busy = b; sendBtn.disabled = b; input.disabled = b; }

  async function render(){
    body.innerHTML = '';
    if(!convId){ body.innerHTML = '<div class="aic-empty">Ask me anything about DigiHRMS.<br>I can read and change the database directly.</div>'; return; }
    const r = await fetch('ai_agent.php?action=messages&conversation_id=' + convId).then(x=>x.json());
    if(r.error){ body.innerHTML = '<div class="aic-empty">'+esc(r.error)+'</div>'; return; }
    for(const m of (r.messages||[])){
      if(m.role === 'user') bubble('user', m.content);
      else if(m.role === 'tool') toolBubble(m.name || 'tool', m.content);
      else if(m.role === 'assistant'){
        if(m.content && m.content.trim()) bubble('bot', m.content);
        if(m.tool_calls){
          try { JSON.parse(m.tool_calls).forEach(tc => {
            toolBubble('→ ' + tc.function.name, tc.function.arguments);
          }); } catch(e){}
        }
      }
    }
    scroll();
  }

  function renderConfirm(pending){
    for(const p of pending){
      const d = document.createElement('div');
      d.className = 'aic-confirm' + (p.dangerous ? ' danger' : '');
      const label = p.dangerous ? '⚠ Destructive — review carefully'
                  : (p.label || 'Confirm action');
      const detail = p.sql || p.detail || '';
      d.innerHTML = '<div class="lbl">'+esc(label)+'</div>'+
        (p.reason ? '<p>'+esc(p.reason)+'</p>' : '')+
        (detail ? '<pre>'+esc(detail)+'</pre>' : '')+
        '<div class="btns"><button class="aic-run">'+esc(p.okText||'Run')+'</button><button class="aic-skip">Skip</button></div>';
      d.querySelector('.aic-run').onclick  = () => resume(p.tool_call_id, true, d);
      d.querySelector('.aic-skip').onclick = () => resume(p.tool_call_id, false, d);
      body.appendChild(d);
    }
    scroll();
  }

  async function resume(tcId, approved, el){
    if(busy) return;
    [...el.parentNode.querySelectorAll('.aic-confirm')].forEach(x => x.querySelectorAll('button').forEach(b=>b.disabled=true));
    setBusy(true);
    const typing = document.createElement('div'); typing.className='aic-typing'; typing.textContent='Working…';
    body.appendChild(typing); scroll();
    try{
      const r = await fetch('ai_agent.php', {
        method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':window.HRMS_CSRF},
        body: JSON.stringify({ resume:true, conversation_id:convId, approvals:{ [tcId]: approved } })
      }).then(x=>x.json());
      typing.remove();
      await handle(r);
    }catch(e){ typing.remove(); bubble('bot','⚠ '+esc(e.message)); }
    setBusy(false);
  }

  async function handle(r){
    if(r.conversation_id){ convId = String(r.conversation_id); localStorage.setItem(LS, convId); }
    await render();
    if(r.status === 'confirm') renderConfirm(r.pending);
    else if(r.error) bubble('bot', '⚠ ' + r.error);
  }

  async function send(){
    const text = input.value.trim();
    if(!text || busy) return;
    input.value=''; input.style.height='auto';
    bubble('user', text);
    setBusy(true);
    const typing = document.createElement('div'); typing.className='aic-typing'; typing.textContent='Thinking…';
    body.appendChild(typing); scroll();
    try{
      const r = await fetch('ai_agent.php', {
        method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':window.HRMS_CSRF},
        body: JSON.stringify({ message:text, conversation_id:convId })
      }).then(x=>x.json());
      typing.remove();
      await handle(r);
    }catch(e){ typing.remove(); bubble('bot','⚠ '+esc(e.message)); }
    setBusy(false);
  }

  $('#aic-launch').onclick = () => { panel.classList.add('open'); $('#aic-launch').style.display='none'; render(); input.focus(); };
  $('#aic-close').onclick  = () => { panel.classList.remove('open'); $('#aic-launch').style.display='flex'; };
  $('#aic-new').onclick    = () => { convId=null; localStorage.removeItem(LS); render(); input.focus(); };
  sendBtn.onclick = send;
  input.addEventListener('keydown', e => { if(e.key==='Enter' && !e.shiftKey){ e.preventDefault(); send(); } });
  input.addEventListener('input', () => { input.style.height='auto'; input.style.height=Math.min(input.scrollHeight,120)+'px'; });
})();
</script>
