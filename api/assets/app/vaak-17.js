/* VAAK app script 17. Moved out of api/ap-admin.php (was an inline <script>) so
   browsers cache and precompile it; the shell loads these files in their old order.
*/
// Own-post Delete: capture-phase intercept so Saving… / full POST never win.
// Confirm in JS (forms use data-vaak-ajax-delete; no inline onsubmit).
(function () {
  const DEFAULT_CONFIRM =
    'Delete this post permanently? Remotes and Bluesky mirrors are removed too.';

  document.addEventListener('submit', function (ev) {
    const form = ev.target;
    if (!(form instanceof HTMLFormElement)) return;
    const actionInput = form.querySelector('input[name="action"]');
    const action = actionInput ? String(actionInput.value || '') : '';
    const marked = form.getAttribute('data-vaak-ajax-delete') === '1';
    if (!marked && action !== 'delete_status' && action !== 'delete_bsky_post') return;

    // Synchronous — must beat the capture-phase Saving… pill and native navigate.
    ev.preventDefault();
    ev.stopPropagation();
    if (typeof ev.stopImmediatePropagation === 'function') ev.stopImmediatePropagation();
    if (typeof window.vaakHideLoading === 'function') window.vaakHideLoading();

    const msg = form.getAttribute('data-vaak-delete-confirm') || DEFAULT_CONFIRM;
    if (!window.confirm(msg)) return;
    if (form.dataset.busy === '1') return;
    form.dataset.busy = '1';

    const btn = form.querySelector('button[type="submit"]');
    if (btn) btn.disabled = true;
    const card = form.closest('article.tweet');
    const restore = () => {
      form.dataset.busy = '0';
      if (btn) btn.disabled = false;
      if (!card || !card.isConnected) return;
      card.style.transition = '';
      card.style.opacity = '';
      card.style.transform = '';
      card.style.pointerEvents = '';
      card.style.maxHeight = '';
      card.style.marginTop = '';
      card.style.marginBottom = '';
      card.style.paddingTop = '';
      card.style.paddingBottom = '';
      card.style.overflow = '';
    };

    if (card) {
      const h = card.getBoundingClientRect().height;
      card.style.overflow = 'hidden';
      card.style.maxHeight = Math.max(1, Math.round(h)) + 'px';
      card.style.transition = 'opacity .28s ease, transform .28s ease, max-height .32s ease, margin .32s ease, padding .32s ease';
      card.style.opacity = '0';
      card.style.transform = 'scale(0.98)';
      card.style.pointerEvents = 'none';
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          if (!card.isConnected) return;
          card.style.maxHeight = '0px';
          card.style.marginTop = '0';
          card.style.marginBottom = '0';
          card.style.paddingTop = '0';
          card.style.paddingBottom = '0';
        });
      });
    }
    if (window.vaakHaptic) window.vaakHaptic(8);

    const fd = new FormData(form);
    fd.set('ajax', '1');
    if (typeof window.vaakCsrfApply === 'function') window.vaakCsrfApply(fd);
    else if (window.VAAK_CSRF) fd.set('csrf', window.VAAK_CSRF);

    const postUrl = form.getAttribute('action') || '/vaak/?view=home';
    fetch(postUrl, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(window.VAAK_CSRF ? { 'X-VAAK-CSRF': window.VAAK_CSRF } : {}),
      },
    })
      .then((res) => res.json().catch(() => null).then((data) => ({ res, data })))
      .then(({ res, data }) => {
        if (!data || !data.ok) {
          throw new Error((data && data.error) || (res && !res.ok ? 'Delete failed.' : 'Delete failed.'));
        }
        setTimeout(() => {
          if (card && card.isConnected) card.remove();
          form.dataset.busy = '0';
          const rv = (form.querySelector('input[name="return_view"]') || {}).value || '';
          const onStatus = (window.location.search || '').includes('view=status');
          if (onStatus && rv && typeof window.vaakSoftNavTo === 'function') {
            try { window.vaakSoftNavTo(rv, true); } catch (e) {}
          }
        }, 340);
      })
      .catch((err) => {
        restore();
        if (typeof window.apAdminToast === 'function') {
          window.apAdminToast((err && err.message) || 'Delete failed.', true);
        }
      })
      .finally(() => {
        if (typeof window.vaakHideLoading === 'function') window.vaakHideLoading();
      });
  }, true); // capture — before Saving… pill and before native submit
})();
