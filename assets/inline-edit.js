/**
 * inline-edit.js — Excel-style click-to-edit for HRMS tables
 *
 * Usage: add these data attributes to any <td>:
 *   data-editable="text|date|select"
 *   data-table="employees|leaves"
 *   data-id="123"
 *   data-field="name"
 *   data-value="current value"
 *   data-options="opt1,opt2,opt3"   (for select type only)
 *   data-readonly="1"               (skip editing)
 */

(function () {
  'use strict';

  let _active = null; // currently open editor

  // ── Init ─────────────────────────────────────────────────────────

  function init() {
    document.addEventListener('click', onCellClick);
    document.addEventListener('keydown', onKeyDown);

    // Make table-responsive containers scrollable with visual hint
    document.querySelectorAll('.table-responsive').forEach(wrap => {
      if (!wrap.classList.contains('ie-scroll-wrap')) {
        wrap.classList.add('ie-scroll-wrap');
        updateScrollHint(wrap);
        wrap.addEventListener('scroll', () => updateScrollHint(wrap));
      }
    });
  }

  // ── Scroll hint ───────────────────────────────────────────────────

  function updateScrollHint(wrap) {
    const canScrollRight = wrap.scrollWidth > wrap.clientWidth + wrap.scrollLeft + 2;
    wrap.classList.toggle('ie-has-more', canScrollRight);
  }

  // ── Click handler ─────────────────────────────────────────────────

  function onCellClick(e) {
    const td = e.target.closest('td[data-editable]');
    if (!td) { commitActive(); return; }
    if (td.dataset.readonly === '1') return;
    if (_active && _active.td === td) return; // already editing this cell
    commitActive();
    openEditor(td);
  }

  function onKeyDown(e) {
    if (!_active) return;
    if (e.key === 'Enter' && _active.type !== 'textarea') {
      e.preventDefault();
      commitActive();
    }
    if (e.key === 'Escape') {
      cancelActive();
    }
    if (e.key === 'Tab') {
      e.preventDefault();
      const dir = e.shiftKey ? -1 : 1;
      commitActive(() => focusNext(dir));
    }
  }

  // ── Open editor ───────────────────────────────────────────────────

  function openEditor(td) {
    const type    = td.dataset.editable;
    const value   = td.dataset.value ?? td.innerText.trim();
    const options = td.dataset.options ? td.dataset.options.split(',') : [];

    td.classList.add('ie-editing');

    let input;
    if (type === 'select') {
      input = document.createElement('select');
      input.className = 'ie-input';
      options.forEach(opt => {
        const o = document.createElement('option');
        o.value = opt.trim();
        o.textContent = opt.trim();
        if (opt.trim() === value) o.selected = true;
        input.appendChild(o);
      });
      input.addEventListener('change', () => commitActive());
    } else if (type === 'date') {
      input = document.createElement('input');
      input.type = 'date';
      input.className = 'ie-input';
      // Convert "12 Jan 2024" → "2024-01-12" if needed
      input.value = toISODate(value);
    } else {
      input = document.createElement('input');
      input.type = 'text';
      input.className = 'ie-input';
      input.value = value;
    }

    // Store original display content to restore on cancel
    const originalHTML = td.innerHTML;
    td.innerHTML = '';
    td.appendChild(input);

    _active = { td, input, type, originalHTML, value };

    input.focus();
    if (input.type === 'text') input.select();

    input.addEventListener('blur', e => {
      // Delay so Tab/click handlers can intercept
      setTimeout(() => { if (_active && _active.input === e.target) commitActive(); }, 120);
    });
  }

  // ── Commit ────────────────────────────────────────────────────────

  function commitActive(afterCb) {
    if (!_active) { afterCb && afterCb(); return; }
    const { td, input, originalHTML, value } = _active;
    const newValue = input.value.trim();
    _active = null;

    if (newValue === value) {
      // No change — restore
      td.innerHTML = originalHTML;
      td.classList.remove('ie-editing');
      afterCb && afterCb();
      return;
    }

    // Optimistic update
    td.innerHTML = formatDisplay(td.dataset.editable, newValue);
    td.dataset.value = newValue;
    td.classList.remove('ie-editing');
    td.classList.add('ie-saving');

    save(td, newValue)
      .then(res => {
        td.classList.remove('ie-saving');
        td.classList.add('ie-saved');

        // If days were recalculated, update the days cell
        if (res.days !== undefined) {
          const row  = td.closest('tr');
          const daysTd = row?.querySelector('[data-field="days_display"]');
          if (daysTd) daysTd.textContent = res.days + (res.days === 1 ? ' day' : ' days');
        }

        setTimeout(() => td.classList.remove('ie-saved'), 1400);
        afterCb && afterCb();
      })
      .catch(err => {
        td.classList.remove('ie-saving');
        td.classList.add('ie-error');
        td.title = err.message || 'Save failed';
        setTimeout(() => {
          td.classList.remove('ie-error');
          td.innerHTML = originalHTML;
          td.dataset.value = value;
          td.removeAttribute('title');
        }, 2000);
        afterCb && afterCb();
      });
  }

  function cancelActive() {
    if (!_active) return;
    const { td, originalHTML } = _active;
    _active = null;
    td.innerHTML = originalHTML;
    td.classList.remove('ie-editing');
  }

  // ── Save via API ──────────────────────────────────────────────────

  async function save(td, value) {
    const res = await fetch('api_inline_edit.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({
        table: td.dataset.table,
        id:    td.dataset.id,
        field: td.dataset.field,
        value: value,
      }),
    });
    const data = await res.json();
    if (!res.ok || data.error) throw new Error(data.error || 'Save failed');
    return data;
  }

  // ── Tab navigation ────────────────────────────────────────────────

  function focusNext(dir) {
    const all = Array.from(document.querySelectorAll('td[data-editable]:not([data-readonly="1"])'));
    if (!all.length) return;
    const lastTd = document.querySelector('td[data-editable].ie-last-active') || all[0];
    const idx    = all.indexOf(lastTd);
    const next   = all[(idx + dir + all.length) % all.length];
    if (next) { next.classList.add('ie-last-active'); openEditor(next); }
    lastTd.classList.remove('ie-last-active');
  }

  // ── Helpers ───────────────────────────────────────────────────────

  function toISODate(str) {
    if (!str || /^\d{4}-\d{2}-\d{2}$/.test(str)) return str || '';
    const d = new Date(str);
    if (isNaN(d)) return '';
    return d.toISOString().slice(0, 10);
  }

  function formatDisplay(type, value) {
    if (type === 'date' && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
      return new Date(value).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    }
    return escHtml(value);
  }

  function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  // ── Boot ──────────────────────────────────────────────────────────
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
