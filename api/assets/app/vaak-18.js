/* VAAK app script 18. Moved out of api/ap-admin.php (was an inline <script>) so
   browsers cache and precompile it; the shell loads these files in their old order.
*/
// Suggested-account Follow: optimistic Following + silent background queue.
// No Saving… toast, no “queued in the background” confirmation.
(function () {
  document.addEventListener('submit', async (ev) => {
    const form = ev.target;
    if (!(form instanceof HTMLFormElement)) return;
    const actionInput = form.querySelector('input[name="action"]');
    const button = form.querySelector('button[type="submit"]');
    if (!actionInput || !button) return;
    const action = String(actionInput.value || '');
    if (action !== 'suggestion_follow' && action !== 'suggestion_dismiss') return;
    ev.preventDefault();
    if (typeof window.vaakHideLoading === 'function') window.vaakHideLoading();
    if (form.dataset.busy === '1') return;
    form.dataset.busy = '1';
    const card = form.closest('article.home-suggestion, article.tweet, article.wtf-row');
    const beforeLabel = button.innerHTML;
    const beforeDisabled = button.disabled;
    const beforeClass = button.className;
    if (action === 'suggestion_follow') {
      button.textContent = 'Following';
      button.disabled = true;
      button.classList.remove('btn-primary');
      button.classList.add('btn-following');
      button.title = 'Following';
    } else {
      button.disabled = true;
    }
    if (window.vaakHaptic) window.vaakHaptic(5);
    const fd = new FormData(form);
    fd.set('ajax', '1');
    if (window.VAAK_CSRF) fd.set('csrf', window.VAAK_CSRF);
    const fadeCard = () => {
      if (!card || !card.isConnected) return;
      card.style.transition = 'opacity .2s ease, transform .2s ease';
      card.style.opacity = '0';
      card.style.transform = 'scale(0.98)';
      setTimeout(() => {
        if (!card.isConnected) return;
        const grid = card.parentElement;
        card.remove();
        // The Who to follow card refills its three rows from its pool.
        if (card.classList.contains('wtf-row')) document.dispatchEvent(new CustomEvent('vaak:wtf-followed'));
        // Drop empty Home suggestions section once the last card is gone.
        if (grid && grid.classList.contains('home-suggestions-grid') && !grid.querySelector('article.home-suggestion')) {
          const section = grid.closest('section.home-suggestions');
          if (section) section.remove();
        }
      }, 220);
    };
    try {
      const res = await fetch(form.getAttribute('action') || window.location.href, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await res.json().catch(() => null);
      if (!data || !data.ok) {
        throw new Error((data && data.error) || (action === 'suggestion_follow' ? 'Could not follow.' : 'Could not dismiss.'));
      }
      fadeCard();
      const actorField = form.querySelector('input[name="actor_id"]');
      document.dispatchEvent(new CustomEvent('vaak:account-followed', { detail: { actor: actorField ? String(actorField.value || '') : '' } }));
    } catch (err) {
      button.innerHTML = beforeLabel;
      button.disabled = beforeDisabled;
      button.className = beforeClass;
      button.title = '';
      form.dataset.busy = '0';
      if (typeof window.apAdminToast === 'function') {
        window.apAdminToast((err && err.message) || 'Could not follow.', true);
      }
    } finally {
      if (typeof window.vaakHideLoading === 'function') window.vaakHideLoading();
    }
  });
})();
