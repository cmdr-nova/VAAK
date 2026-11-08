/* VAAK app script 13. Moved out of api/ap-admin.php (was an inline <script>) so
   browsers cache and precompile it; the shell loads these files in their old order.
*/
// Blog composer. Delegated handlers also work when the Blog page arrives
// through soft navigation; page setup runs whenever a new editor appears.
// Works on both the native (Rust-painted) editor and the original PHP form.
(() => {
  let linkSelection = null;
  let imageSelection = null;
  const MODE_KEY = 'vaak.blog.mode';
  function editor(target) { return target && target.closest ? target.closest('[data-blog-editor]') : null; }
  function status(form, text) {
    const el = form.querySelector('.blog-format-status');
    if (el) el.textContent = text;
  }
  function replace(form, start, end, text, selectionStart, selectionEnd) {
    const body = form.elements.blog_body;
    if (body.value.length - (end - start) + text.length > body.maxLength) {
      status(form, 'This formatting would exceed the 200,000 character limit.');
      return false;
    }
    const scroll = body.scrollTop;
    body.focus();
    body.setSelectionRange(start, end);
    // insertText preserves the textarea's native undo history where supported.
    // setRangeText keeps formatting available in engines without that command.
    const inserted = typeof document.execCommand === 'function'
      && document.execCommand('insertText', false, text);
    if (!inserted) {
      body.setRangeText(text, start, end, 'end');
      body.dispatchEvent(new Event('input', { bubbles: true }));
    }
    body.setSelectionRange(selectionStart, selectionEnd);
    body.scrollTop = scroll;
    status(form, '');
    return true;
  }
  function panel(form, kind) { return form.querySelector(kind === 'image' ? '#blog-image-fields' : '#blog-link-fields'); }
  function closePanel(form, kind) {
    const box = panel(form, kind);
    if (box) box.hidden = true;
    const btn = form.querySelector('[data-blog-format="' + kind + '"]');
    if (btn) btn.setAttribute('aria-expanded', 'false');
    if (kind === 'image') imageSelection = null; else linkSelection = null;
    form.elements.blog_body.focus();
  }
  function lineRange(body) {
    const start = body.selectionStart, end = body.selectionEnd;
    const lineStart = start === 0 ? 0 : body.value.lastIndexOf('\n', start - 1) + 1;
    const last = end > start && body.value[end - 1] === '\n' ? end - 1 : end;
    const next = body.value.indexOf('\n', last), lineEnd = next < 0 ? body.value.length : next;
    return [lineStart, lineEnd];
  }
  const LINE_PREFIX = /^(?:#{1,6} |[-*+] |\d+[.)] |> )/;
  function format(form, kind) {
    const body = form.elements.blog_body, start = body.selectionStart, end = body.selectionEnd;
    if (kind === 'link' || kind === 'image') {
      const box = panel(form, kind);
      if (!box) return;
      const saved = { form, start, end, value: body.value };
      if (kind === 'image') imageSelection = saved; else linkSelection = saved;
      box.hidden = false;
      form.querySelector('[data-blog-format="' + kind + '"]').setAttribute('aria-expanded', 'true');
      if (kind === 'link') {
        form.querySelector('[data-blog-link-text]').value = body.value.slice(start, end);
        form.querySelector('[data-blog-link-url]').value = '';
        form.querySelector('[data-blog-link-text]').focus();
      } else {
        form.querySelector('[data-blog-image-alt]').value = body.value.slice(start, end);
        form.querySelector('[data-blog-image-url]').value = '';
        form.querySelector('[data-blog-image-url]').focus();
      }
      return;
    }
    const wraps = { bold: '**', italic: '*', strike: '~~', code: '`' };
    if (wraps[kind]) {
      const marker = wraps[kind], selected = body.value.slice(start, end) || 'text';
      replace(form, start, end, marker + selected + marker, start + marker.length, start + marker.length + selected.length);
      return;
    }
    if (kind === 'codeblock') {
      const selected = body.value.slice(start, end) || 'code';
      const before = start > 0 && body.value[start - 1] !== '\n' ? '\n' : '';
      const text = before + '```\n' + selected + '\n```\n';
      const at = start + before.length + 4;
      replace(form, start, end, text, at, at + selected.length);
      return;
    }
    if (kind === 'rule') {
      const [, lineEnd] = lineRange(body);
      const text = '\n\n---\n';
      replace(form, lineEnd, lineEnd, text, lineEnd + text.length, lineEnd + text.length);
      return;
    }
    const [lineStart, lineEnd] = lineRange(body);
    let n = 0;
    const text = body.value.slice(lineStart, lineEnd).split('\n').map(line => {
      const bare = line.replace(LINE_PREFIX, '');
      if (kind === 'list') return '- ' + bare;
      if (kind === 'olist') return (++n) + '. ' + bare;
      if (kind === 'quote') return '> ' + bare;
      return '#'.repeat(Number(kind.slice(-1))) + ' ' + bare;
    }).join('\n');
    replace(form, lineStart, lineEnd, text, lineStart, lineStart + text.length);
  }
  function insertFromPanel(form, kind) {
    const saved = kind === 'image' ? imageSelection : linkSelection;
    const url = form.querySelector(kind === 'image' ? '[data-blog-image-url]' : '[data-blog-link-url]').value.trim();
    const label = form.querySelector(kind === 'image' ? '[data-blog-image-alt]' : '[data-blog-link-text]').value.trim();
    // Match the renderer: HTTPS only, no spaces, no closing bracket/parenthesis.
    if (/[\]\r\n]/.test(label) || (kind === 'link' && !label) || !/^https:\/\/[^\s)]+$/.test(url)) {
      status(form, kind === 'image'
        ? 'Enter an HTTPS image address without spaces, and a description without a closing bracket.'
        : 'Enter link text without a closing bracket, and an HTTPS address without spaces or a closing parenthesis.');
      return;
    }
    if (!saved || saved.form !== form || saved.value !== form.elements.blog_body.value) {
      status(form, 'The post changed. Place the cursor and open the panel again.');
      return;
    }
    const text = kind === 'image' ? '![' + label + '](' + url + ')' : '[' + label + '](' + url + ')';
    const selStart = saved.start + (kind === 'image' ? 2 : 1);
    if (replace(form, saved.start, saved.end, text, selStart, selStart + label.length)) {
      panel(form, kind).hidden = true;
      form.querySelector('[data-blog-format="' + kind + '"]').setAttribute('aria-expanded', 'false');
      if (kind === 'image') imageSelection = null; else linkSelection = null;
    }
  }

  // ---- Native editor extras -------------------------------------------------
  function csrf(form) { const f = form.elements.csrf; return f ? f.value : ''; }
  function post(form, fields) {
    const data = new FormData();
    data.append('csrf', csrf(form));
    Object.keys(fields).forEach(k => data.append(k, fields[k]));
    return fetch(location.pathname + '?view=blog', {
      method: 'POST', body: data, credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    }).then(r => r.json());
  }
  function countWords(text) {
    let fence = false, n = 0;
    text.split('\n').forEach(line => {
      if (/^\s*```/.test(line)) { fence = !fence; return; }
      if (fence) return;
      line.split(/\s+/).forEach(w => {
        if (w && !w.startsWith('](') && !w.startsWith('https://') && !w.startsWith('![') && /[\p{L}\p{N}]/u.test(w)) n++;
      });
    });
    return n;
  }
  function updateCounts(form) {
    const words = countWords(form.elements.blog_body.value);
    const w = form.querySelector('[data-blog-words]'), m = form.querySelector('[data-blog-minutes]');
    if (w) w.textContent = words + (words === 1 ? ' word' : ' words');
    if (m) m.textContent = Math.max(1, Math.ceil(words / 220)) + ' min read';
  }
  const previewTimers = new WeakMap();
  function refreshPreview(form, soon) {
    const box = form.querySelector('[data-blog-preview]');
    const panes = form.querySelector('[data-blog-panes]');
    if (!box || !panes || panes.dataset.mode === 'write') return;
    clearTimeout(previewTimers.get(form));
    previewTimers.set(form, setTimeout(() => {
      const md = form.elements.blog_body.value;
      if (box.dataset.rendered === md) return;
      post(form, { action: 'blog_preview', blog_body: md }).then(reply => {
        if (!reply || !reply.ok || form.elements.blog_body.value !== md) return;
        // Server-rendered from escaped Markdown (blog/markdown.rs).
        box.innerHTML = reply.html || '<p class="meta">Nothing to preview yet.</p>';
        box.dataset.rendered = md;
      }).catch(() => { status(form, 'Preview is unavailable right now.'); });
    }, soon ? 0 : 500));
  }
  function setMode(form, mode) {
    const panes = form.querySelector('[data-blog-panes]');
    if (!panes) return;
    if (mode === 'split' && window.matchMedia('(max-width: 760px)').matches) mode = 'write';
    panes.dataset.mode = mode;
    form.querySelectorAll('[data-blog-mode]').forEach(b => b.setAttribute('aria-pressed', b.dataset.blogMode === mode ? 'true' : 'false'));
    try { localStorage.setItem(MODE_KEY, mode); } catch (e) {}
    refreshPreview(form, true);
    if (mode !== 'preview') form.elements.blog_body.focus({ preventScroll: true });
  }
  function slugify(s) {
    return s.normalize('NFKD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80);
  }
  function excerpt(md, limit) {
    let t = md.replace(/^```.*$/gm, '').replace(/!\[[^\]]*\]\([^)]*\)/g, '')
      .replace(/^\s{0,3}(?:#+|>|[-*+]|\d+[.)])\s*/gm, '').replace(/^\s*(?:-{3,}|\*{3,}|_{3,})\s*$/gm, '')
      .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1').replace(/(\*\*|__|~~|`)/g, '').replace(/\s+/g, ' ').trim();
    return t.length > limit ? t.slice(0, limit - 1) + '…' : t;
  }
  function updateTeaser(form) {
    const box = form.querySelector('[data-blog-teaser]');
    if (!box) return;
    const vis = form.querySelector('[data-blog-teaser-vis]');
    const cw = form.querySelector('[data-blog-teaser-cw]');
    const title = form.elements.blog_title.value.trim() || 'Untitled';
    const summary = (form.querySelector('[data-blog-summary]') || {}).value || '';
    const text = summary.trim() || excerpt(form.elements.blog_body.value, 160);
    box.hidden = vis && vis.value === 'none';
    box.querySelector('[data-blog-teaser-cw-line]').textContent = cw && cw.checked ? 'CW: ' + title : title;
    box.querySelector('[data-blog-teaser-text]').textContent = text;
  }
  function renderChips(form) {
    const input = form.querySelector('[data-blog-tags]'), box = form.querySelector('[data-blog-chips]');
    if (!input || !box) return;
    const tags = [];
    input.value.split(/[\s,]+/).forEach(t => { t = t.replace(/^#+/, '').trim(); if (t && !tags.includes(t.toLowerCase())) tags.push(t.toLowerCase()); });
    box.replaceChildren(...tags.map(tag => {
      const chip = document.createElement('button');
      chip.type = 'button'; chip.className = 'be-chip'; chip.dataset.blogChip = tag;
      chip.setAttribute('aria-label', 'Remove tag ' + tag);
      chip.textContent = '#' + tag + ' ×';
      return chip;
    }));
  }
  function updateWhen(form) {
    const when = form.querySelector('[data-blog-when]'), box = form.querySelector('[data-blog-schedule]');
    const primary = form.querySelector('[data-blog-primary]');
    if (!when || !box || !primary) return;
    const later = when.value === 'schedule';
    box.hidden = !later;
    primary.textContent = later ? 'Schedule' : (primary.dataset.blogPrimaryPublish || 'Publish');
  }
  function localInput(date) {
    const p = n => String(n).padStart(2, '0');
    return date.getFullYear() + '-' + p(date.getMonth() + 1) + '-' + p(date.getDate()) + 'T' + p(date.getHours()) + ':' + p(date.getMinutes());
  }

  // Autosave (new posts, drafts, scheduled posts). One request at a time.
  const saveState = new WeakMap();
  function saveLabel(form, text, ok) {
    const pill = form.querySelector('[data-blog-save-state]');
    if (!pill) return;
    pill.hidden = !text;
    pill.textContent = text;
    pill.classList.toggle('is-ok', !!ok);
  }
  function autosave(form) {
    const st = saveState.get(form) || {};
    saveState.set(form, st);
    if (form.dataset.blogAutosave !== '1') { st.dirty = true; return; }
    st.dirty = true;
    clearTimeout(st.timer);
    st.timer = setTimeout(() => runAutosave(form), 4000);
    saveLabel(form, 'Unsaved changes', false);
  }
  function runAutosave(form) {
    const st = saveState.get(form);
    if (!st || st.submitting) return;
    if (st.busy) { st.again = true; return; }
    if (!form.elements.blog_title.value.trim() || !form.elements.blog_body.value.trim()) return;
    const fields = { action: 'blog_autosave' };
    new FormData(form).forEach((value, key) => {
      if (key === 'action' || key === 'csrf' || key === 'blog_cover' || key === 'blog_publish' || typeof value !== 'string') return;
      fields[key] = value; // later checkbox values win, like PHP's $_POST
    });
    st.busy = true;
    saveLabel(form, 'Saving…', false);
    post(form, fields).then(reply => {
      if (reply && reply.ok) {
        const slug = form.querySelector('[data-blog-slug]');
        if (slug && reply.slug) slug.value = reply.slug;
        st.dirty = false;
        const t = new Date();
        saveLabel(form, 'Saved ' + t.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }), true);
      } else {
        saveLabel(form, (reply && reply.error) || 'Autosave failed', false);
      }
    }).catch(() => saveLabel(form, 'Autosave failed, will retry', false)).finally(() => {
      st.busy = false;
      if (st.again) { st.again = false; runAutosave(form); }
    });
  }

  function setup(form) {
    if (form.dataset.blogReady) return;
    form.dataset.blogReady = '1';
    if (!form.querySelector('[data-blog-panes]')) return; // original PHP form
    let mode = 'write';
    try { mode = localStorage.getItem(MODE_KEY) || 'write'; } catch (e) {}
    setMode(form, ['write', 'split', 'preview'].includes(mode) ? mode : 'write');
    updateCounts(form); updateTeaser(form); renderChips(form); updateWhen(form);
    const at = form.querySelector('[data-blog-schedule-at]');
    if (at && !at.value && at.dataset.blogScheduleUtc) {
      const d = new Date(at.dataset.blogScheduleUtc);
      if (!isNaN(d)) at.value = localInput(d);
    }
    if (at) {
      const soon = new Date(Date.now() + 5 * 60000);
      at.min = localInput(soon);
    }
    document.querySelectorAll('[data-blog-local-time]').forEach(el => {
      const d = new Date(el.getAttribute('datetime'));
      if (!isNaN(d)) el.textContent = d.toLocaleString([], { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    });
    const note = document.querySelector('[data-blog-nostr-note]');
    if (note && note.dataset.blogNostrNote) {
      const id = note.dataset.blogNostrNote;
      note.removeAttribute('data-blog-nostr-note');
      let tries = 0;
      const go = () => {
        if (typeof window.vaakNostrCrosspost === 'function') { window.vaakNostrCrosspost(id); return; }
        if (++tries < 20) setTimeout(go, 250);
      };
      go();
    }
  }
  function scan() {
    document.querySelectorAll('[data-blog-editor]:not([data-blog-ready])').forEach(setup);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan); else scan();
  let scanQueued = false;
  new MutationObserver(() => {
    if (scanQueued) return;
    scanQueued = true;
    requestAnimationFrame(() => { scanQueued = false; scan(); });
  }).observe(document.documentElement, { childList: true, subtree: true });

  document.addEventListener('click', event => {
    const del = event.target.closest('form[data-blog-delete] button[type="submit"]');
    if (del && !window.confirm(del.form.dataset.blogDelete)) { event.preventDefault(); return; }
    const chip = event.target.closest('[data-blog-chip]');
    if (chip) {
      const form = editor(chip), input = form && form.querySelector('[data-blog-tags]');
      if (input) {
        input.value = input.value.split(/[\s,]+/).filter(t => t && t.replace(/^#+/, '').toLowerCase() !== chip.dataset.blogChip).join(' ');
        renderChips(form); autosave(form);
      }
      return;
    }
    const modeBtn = event.target.closest('[data-blog-mode]');
    if (modeBtn) { const form = editor(modeBtn); if (form) setMode(form, modeBtn.dataset.blogMode); return; }
    const button = event.target.closest('[data-blog-format], [data-blog-link-insert], [data-blog-link-cancel], [data-blog-image-insert], [data-blog-image-cancel]');
    if (!button) return;
    const form = editor(button); if (!form) return;
    if (button.hasAttribute('data-blog-format')) { format(form, button.dataset.blogFormat); return; }
    if (button.hasAttribute('data-blog-link-cancel')) { closePanel(form, 'link'); return; }
    if (button.hasAttribute('data-blog-image-cancel')) { closePanel(form, 'image'); return; }
    insertFromPanel(form, button.hasAttribute('data-blog-image-insert') ? 'image' : 'link');
  });
  document.addEventListener('input', event => {
    const form = editor(event.target); if (!form || !form.dataset.blogReady) return;
    const t = event.target;
    if (t === form.elements.blog_body) { updateCounts(form); refreshPreview(form, false); }
    if (t.matches('[data-blog-tags]')) renderChips(form);
    if (t.matches('[data-blog-url]')) {
      const out = form.querySelector('[data-blog-url-preview]');
      if (out) out.textContent = slugify(t.value) || 'made from the title';
    }
    updateTeaser(form);
    if (t.type !== 'file') autosave(form);
  });
  document.addEventListener('change', event => {
    const form = editor(event.target); if (!form || !form.dataset.blogReady) return;
    const t = event.target;
    if (t.matches('[data-blog-when]')) updateWhen(form);
    if (t.matches('[data-blog-cover-input]') && t.files && t.files[0]) {
      const img = form.querySelector('[data-blog-cover-img]');
      img.src = URL.createObjectURL(t.files[0]); img.hidden = false;
      form.querySelector('[data-blog-cover]').classList.add('has-cover');
      form.querySelector('[data-blog-cover-label]').textContent = 'Change cover image';
      const rm = form.querySelector('[data-blog-cover-remove]');
      if (rm) { rm.checked = false; rm.closest('label').hidden = false; }
      status(form, 'The cover uploads when you save, publish or update.');
    }
    if (t.matches('[data-blog-cover-remove]')) {
      const img = form.querySelector('[data-blog-cover-img]');
      img.hidden = t.checked;
      form.querySelector('[data-blog-cover]').classList.toggle('has-cover', !t.checked);
      if (t.checked) { const file = form.querySelector('[data-blog-cover-input]'); if (file) file.value = ''; }
    }
    updateTeaser(form);
    if (t.type !== 'file') autosave(form);
  });
  document.addEventListener('submit', event => {
    const form = event.target;
    if (!form.matches || !form.matches('[data-blog-editor]')) return;
    const st = saveState.get(form);
    if (st) { clearTimeout(st.timer); st.submitting = true; st.dirty = false; }
    const tz = form.querySelector('[data-blog-tz]'), at = form.querySelector('[data-blog-schedule-at]');
    if (tz) {
      // The offset of the chosen date, so a schedule across a DST change lands right.
      const d = at && at.value ? new Date(at.value) : new Date();
      tz.value = String(isNaN(d) ? new Date().getTimezoneOffset() : d.getTimezoneOffset());
    }
  });
  window.addEventListener('beforeunload', event => {
    const form = document.querySelector('[data-blog-editor][data-blog-ready]');
    const st = form && saveState.get(form);
    if (st && st.dirty && !st.submitting) { event.preventDefault(); event.returnValue = ''; }
  });
  document.addEventListener('keydown', event => {
    const form = editor(event.target); if (!form) return;
    if (event.key === 'Escape') {
      const link = panel(form, 'link'), image = panel(form, 'image');
      if (link && !link.hidden) { event.preventDefault(); closePanel(form, 'link'); return; }
      if (image && !image.hidden) { event.preventDefault(); closePanel(form, 'image'); return; }
    }
    if (event.target !== form.elements.blog_body || !(event.ctrlKey || event.metaKey) || event.altKey) return;
    const kind = { b: 'bold', i: 'italic', k: 'link' }[event.key.toLowerCase()];
    if (kind) { event.preventDefault(); format(form, kind); }
    if (event.key === 's' || event.key === 'S') {
      event.preventDefault();
      const st = saveState.get(form);
      if (st && form.dataset.blogAutosave === '1') { clearTimeout(st.timer); runAutosave(form); }
    }
  });
})();
