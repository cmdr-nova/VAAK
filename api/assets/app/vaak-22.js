/* VAAK app script 22. Moved out of api/ap-admin.php (was an inline <script>) so
   browsers cache and precompile it; the shell loads these files in their old order.
*/
// Shared navigation feedback for full-page loads and form submissions.
(function () {
  const indicator = document.getElementById('vaak-loading-indicator');
  if (!indicator) return;
  const label = indicator.querySelector('[data-vaak-loading-label]');
  let hideTimer = 0;
  window.vaakShowLoading = function (text) {
    if (hideTimer) window.clearTimeout(hideTimer);
    if (label) label.textContent = text || 'Loading…';
    indicator.classList.add('is-visible');
    indicator.setAttribute('aria-hidden', 'false');
  };
  window.vaakHideLoading = function () {
    indicator.classList.remove('is-visible');
    indicator.setAttribute('aria-hidden', 'true');
  };
  document.addEventListener('click', function (ev) {
    if (ev.defaultPrevented || ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return;
    const link = ev.target.closest && ev.target.closest('a[href]');
    if (!link || link.hasAttribute('download')) return;
    // Soft-nav links manage their own loading state.
    if (link.getAttribute('data-vaak-soft-nav')) return;
    const href = link.getAttribute('href') || '';
    if (!href || href[0] === '#' || href.startsWith('javascript:') || href.startsWith('mailto:')) return;
    let destination;
    try { destination = new URL(href, window.location.href); } catch (e) { return; }
    if (link.target === '_blank') return;
    if (destination.origin !== window.location.origin) return;
    // Show synchronously so the spinner is visible even when navigation starts
    // before the browser gets a chance to run a zero-delay timer.
    if (!ev.defaultPrevented) {
      window.__vaakNavigationPending = true;
      window.vaakShowLoading('Loading…');
    }
  }, true);
  document.addEventListener('submit', function (ev) {
    if (ev.defaultPrevented) return;
    const form = ev.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.id === 'compose-form') return;
    if (form.getAttribute('data-ajax-submit') === '1') return;
    // Timeline interactions (like/boost/bookmark/follow) are optimistic AJAX +
    // background queue work. Never show the page-level Saving… pill for them —
    // that made users feel stuck waiting on the card.
    const actionEl = form.querySelector('input[name="action"]');
    const action = actionEl ? String(actionEl.value || '') : '';
    if ([
      'favourite_status', 'unfavourite_status',
      'bookmark_status', 'unbookmark_status',
      'reblog_status', 'unreblog_status',
      'follow_remote', 'unfollow_remote',
      'suggestion_follow', 'suggestion_dismiss',
      'rss_favourite', 'rss_bookmark',
      'poll_vote', 'action_queue_status',
      'delete_status', 'delete_bsky_post',
      'bite_remote', 'deprioritize_remote', 'undeprioritize_remote',
    ].includes(action)) {
      return;
    }
    let formAction = '';
    try { formAction = String(form.getAttribute('action') || form.action || ''); } catch (e) {}
    const isSearch = /[?&]view=search(?:&|$)/.test(formAction)
      || (String(form.method || 'get').toLowerCase() === 'get'
          && !!form.querySelector('input[name="q"][type="search"]'));
    let loadingText = 'Saving…';
    if (isSearch) {
      loadingText = 'Searching…';
    } else if (action === 'switch_account' || action === 'switch_account_add') {
      loadingText = 'Switching…';
    } else if (action === 'switch_account_remove') {
      loadingText = 'Removing…';
    }
    window.vaakShowLoading(loadingText);
  }, true);
  // The full-page placeholder (hidden by CSS once the view streamed in) has
  // done its job by the time this end-of-body script runs.
  document.querySelectorAll('.vaak-page-pending').forEach(function (el) { el.remove(); });
  window.addEventListener('pageshow', function () {
    window.__vaakNavigationPending = false;
    window.vaakHideLoading();
  });
  document.addEventListener('click', function (event) {
    const button = event.target.closest('[data-copy-value]');
    if (!button) return;
    let value = button.getAttribute('data-copy-value') || '';
    value = value.replace(/^https:\/\/(?:www\.)?vaak\.monster\//i, 'https://mkultra.monster/');
    const done = function () {
      const original = button.textContent;
      button.textContent = 'Copied';
      setTimeout(function () { button.textContent = original; }, 1400);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(value).then(done).catch(function () {});
    } else {
      const input = button.parentElement && button.parentElement.querySelector('input[readonly]');
      if (input) { input.select(); document.execCommand('copy'); done(); }
    }
  });
})();
