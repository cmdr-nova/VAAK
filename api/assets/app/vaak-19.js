/* VAAK app script 19. Moved out of api/ap-admin.php (was an inline <script>) so
   browsers cache and precompile it; the shell loads these files in their old order.
*/
// Follow/unfollow forms use the same durable queue as timeline reactions.
(function () {
  document.addEventListener('submit', async (ev) => {
    const form = ev.target;
    if (!(form instanceof HTMLFormElement)) return;
    const actionInput = form.querySelector('input[name="action"]');
    const button = form.querySelector('button[type="submit"]');
    if (!actionInput || !button || !['follow_remote', 'unfollow_remote'].includes(actionInput.value)) return;
    // An inline confirm('Unfollow this account?') that was cancelled.
    if (ev.defaultPrevented) return;
    ev.preventDefault();
    // The inline confirm belongs to the painted action. This handler flips the
    // form between follow and unfollow, so take the confirm over from here:
    // a flipped "Follow" must not ask "Unfollow?", and a re-followed button must.
    if (form.hasAttribute('onsubmit')) {
      form.removeAttribute('onsubmit');
    } else if (actionInput.value === 'unfollow_remote'
      && !window.confirm(button.textContent.trim() === 'Requested' ? 'Cancel this follow request?' : 'Unfollow this account?')) {
      return;
    }
    // Background queue — never leave the page-level Saving… pill up.
    if (typeof window.vaakHideLoading === 'function') window.vaakHideLoading();
    if (form.dataset.queueBusy === '1' || form.dataset.queuePending === '1') {
      if (window.apQueueRepeatedClick) window.apQueueRepeatedClick(form);
      return;
    }
    const before = { action: actionInput.value, label: button.innerHTML, title: button.title };
    const want = actionInput.value === 'follow_remote';
    const lockedFollow = want && form.dataset.followLocked === '1';
    form.dataset.queueBusy = '1';
    button.disabled = false;
    // Flip the button immediately (same idea as optimistic likes).
    // A private account stays "Requested" until they approve. The next click cancels.
    actionInput.value = want ? 'unfollow_remote' : 'follow_remote';
    button.innerHTML = want ? (lockedFollow ? 'Requested' : 'Following') : 'Follow';
    button.title = want
      ? (lockedFollow ? 'Requested — they approve new followers' : 'Following — click to unfollow')
      : 'Follow';
    button.classList.toggle('btn-primary', !want);
    button.classList.toggle('btn-following', want);
    const relState = document.getElementById('remote-profile-rel-state');
    const relBefore = relState ? { html: relState.innerHTML, following: relState.dataset.following } : null;
    if (relState) {
      const followedBy = relState.dataset.followedBy === '1';
      relState.dataset.following = (want && !lockedFollow) ? '1' : '0';
      // Button already says Follow/Following — only keep mutuals / follows-you tags.
      relState.innerHTML = (want && !lockedFollow)
        ? (followedBy ? '<span class="tag" title="You follow each other">mutuals</span>' : '')
        : (followedBy ? '<span class="tag" title="They follow you">follows you</span>' : '');
    }
    const fd = new FormData(form); fd.set('ajax', '1');
    // Queue still needs the original desired action.
    fd.set('action', before.action);
    if (window.VAAK_CSRF) fd.set('csrf', window.VAAK_CSRF);
    try {
      const res = await fetch(form.getAttribute('action') || window.location.href, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
      const data = await res.json().catch(() => null);
      if (!data || !data.ok || !data.queued) throw new Error((data && data.error) || 'Could not queue follow action.');
      if (want) {
        const actorField = form.querySelector('input[name="actor_id"]');
        document.dispatchEvent(new CustomEvent('vaak:account-followed', { detail: { actor: actorField ? String(actorField.value || '') : '' } }));
      }
      if (want && data.requested) {
        button.innerHTML = 'Requested';
        button.title = 'Requested — they approve new followers';
        actionInput.value = 'unfollow_remote';
        button.classList.add('btn-following');
        button.classList.remove('btn-primary');
        if (relState) {
          relState.dataset.following = '0';
          const followedBy = relState.dataset.followedBy === '1';
          relState.innerHTML = followedBy ? '<span class="tag" title="This account follows you">follows you</span>' : '';
        }
      }
      form.dataset.queuePending = '1'; form.dataset.queueId = String(data.queue_id); form.dataset.queueRevision = String(data.revision || '');
      (async () => {
        for (let n = 0; n < 90; n++) {
          await new Promise((resolve) => setTimeout(resolve, 2000));
          const sf = new FormData(); sf.set('action', 'action_queue_status'); sf.set('queue_id', String(data.queue_id)); sf.set('ajax', '1');
          if (window.VAAK_CSRF) sf.set('csrf', window.VAAK_CSRF);
          try {
            const sr = await fetch(window.location.pathname + (window.location.search || ''), { method: 'POST', body: sf, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            const sd = await sr.json(); const q = sd && sd.queue;
            if (!q || Number(q.revision) < Number(form.dataset.queueRevision || data.revision)) continue;
            if (q.status === 'succeeded') { if (Number(q.revision) === Number(form.dataset.queueRevision || data.revision)) { delete form.dataset.queuePending; if (window.apQueueRepeatedClickReset) window.apQueueRepeatedClickReset(form); } return; }
            if (q.status === 'failed' && Number(q.revision) === Number(form.dataset.queueRevision || data.revision)) {
              if (form.isConnected) { actionInput.value = before.action; button.innerHTML = before.label; button.title = before.title; }
              if (relState && relBefore) { relState.innerHTML = relBefore.html; relState.dataset.following = relBefore.following; }
              delete form.dataset.queuePending;
              if (window.apQueueRepeatedClickReset) window.apQueueRepeatedClickReset(form);
              window.apAdminToast(q.last_error || 'Follow action failed after retrying.', true); return;
            }
          } catch (e) { /* retain queued state on transient polling errors */ }
        }
      })();
    } catch (e) {
      actionInput.value = before.action; button.innerHTML = before.label; button.title = before.title;
      if (relState && relBefore) { relState.innerHTML = relBefore.html; relState.dataset.following = relBefore.following; }
      window.apAdminToast((e && e.message) || 'Follow action failed.', true);
    } finally {
      form.dataset.queueBusy = '0';
      button.disabled = false;
      if (typeof window.vaakHideLoading === 'function') window.vaakHideLoading();
    }
  });
})();
