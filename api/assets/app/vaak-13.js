/* VAAK app script 13. Moved out of api/ap-admin.php (was an inline <script>) so
   browsers cache and precompile it; the shell loads these files in their old order.
*/
// Delegated handlers also work when the Blog page arrives through soft navigation.
(() => {
  let linkSelection = null;
  function editor(target) { return target.closest('[data-blog-editor]'); }
  function replace(form, start, end, text, selectionStart, selectionEnd) {
    const body = form.elements.blog_body;
    if (body.value.length - (end - start) + text.length > body.maxLength) {
      form.querySelector('.blog-format-status').textContent = 'This formatting would exceed the 200,000 character limit.';
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
    form.querySelector('.blog-format-status').textContent = '';
    return true;
  }
  function closeLink(form) {
    form.querySelector('.blog-link-fields').hidden = true;
    form.querySelector('[data-blog-format="link"]').setAttribute('aria-expanded', 'false');
    linkSelection = null;
    form.elements.blog_body.focus();
  }
  function format(form, kind) {
    const body = form.elements.blog_body, start = body.selectionStart, end = body.selectionEnd;
    if (kind === 'link') {
      linkSelection = { form, start, end, value: body.value };
      form.querySelector('.blog-link-fields').hidden = false;
      form.querySelector('[data-blog-format="link"]').setAttribute('aria-expanded', 'true');
      form.querySelector('[data-blog-link-text]').value = body.value.slice(start, end);
      form.querySelector('[data-blog-link-url]').value = '';
      form.querySelector('[data-blog-link-text]').focus();
      return;
    }
    if (kind === 'bold' || kind === 'italic') {
      const marker = kind === 'bold' ? '**' : '*', selected = body.value.slice(start, end) || 'text';
      replace(form, start, end, marker + selected + marker, start + marker.length, start + marker.length + selected.length);
      return;
    }
    const lineStart = start === 0 ? 0 : body.value.lastIndexOf('\n', start - 1) + 1;
    const last = end > start && body.value[end - 1] === '\n' ? end - 1 : end;
    const next = body.value.indexOf('\n', last), lineEnd = next < 0 ? body.value.length : next;
    const prefix = kind === 'list' ? '- ' : '#'.repeat(Number(kind.slice(-1))) + ' ';
    const text = body.value.slice(lineStart, lineEnd).split('\n').map(line => prefix + line.replace(/^(?:#{1,3} |[-*] )/, '')).join('\n');
    replace(form, lineStart, lineEnd, text, lineStart, lineStart + text.length);
  }
  document.addEventListener('click', event => {
    const button = event.target.closest('[data-blog-format], [data-blog-link-insert], [data-blog-link-cancel]');
    if (!button) return;
    const form = editor(button); if (!form) return;
    if (button.hasAttribute('data-blog-format')) { format(form, button.dataset.blogFormat); return; }
    if (button.hasAttribute('data-blog-link-cancel')) { closeLink(form); return; }
    const label = form.querySelector('[data-blog-link-text]').value.trim();
    const url = form.querySelector('[data-blog-link-url]').value.trim();
    // Match the server's intentionally small Markdown link syntax.
    if (!label || /[\]\r\n]/.test(label) || !/^https:\/\/[^\s)]+$/.test(url)) {
      form.querySelector('.blog-format-status').textContent = 'Enter link text without a closing bracket, and an HTTPS address without spaces or a closing parenthesis.';
      return;
    }
    const saved = linkSelection;
    if (!saved || saved.form !== form || saved.value !== form.elements.blog_body.value) {
      form.querySelector('.blog-format-status').textContent = 'The post changed. Select the text and open Link again.';
      return;
    }
    const text = '[' + label + '](' + url + ')';
    if (replace(form, saved.start, saved.end, text, saved.start + 1, saved.start + 1 + label.length)) {
      form.querySelector('.blog-link-fields').hidden = true;
      form.querySelector('[data-blog-format="link"]').setAttribute('aria-expanded', 'false');
      linkSelection = null;
    }
  });
  document.addEventListener('keydown', event => {
    const form = editor(event.target); if (!form) return;
    if (event.key === 'Escape' && !form.querySelector('.blog-link-fields').hidden) { event.preventDefault(); closeLink(form); return; }
    if (event.target !== form.elements.blog_body || !(event.ctrlKey || event.metaKey) || event.altKey) return;
    const kind = { b: 'bold', i: 'italic', k: 'link' }[event.key.toLowerCase()];
    if (kind) { event.preventDefault(); format(form, kind); }
  });
})();
