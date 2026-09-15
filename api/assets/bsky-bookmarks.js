(() => {
  if (document.documentElement.dataset.bskyBookmarkActionsBound === '1') return;
  document.documentElement.dataset.bskyBookmarkActionsBound = '1';

  const iconFor = { like: 'heart', repost: 'repeat', bookmark: 'bookmark-simple' };
  const names = {
    like: { on: 'Unlike', off: 'Like on Bluesky' },
    repost: { on: 'Undo boost', off: 'Boost on Bluesky' },
    bookmark: { on: 'Bookmark folders', off: 'Bookmark on Bluesky' },
  };

  function setState(btn, action, on, data) {
    btn.classList.toggle('on', on);
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    btn.title = on ? names[action].on : names[action].off;
    btn.setAttribute('aria-label', btn.title);
    btn.innerHTML = '<i class="ph' + (on && action !== 'repost' ? '-fill' : '')
      + ' ph-' + iconFor[action] + '" aria-hidden="true"></i>';
    if (action === 'bookmark') btn.setAttribute('data-bm-picker', on ? '1' : '0');
    if (data && data.status_id) btn.dataset.statusId = String(data.status_id);
    if (data && data.object_id) btn.dataset.objectId = String(data.object_id);
    if (data && data.record_uri !== undefined) btn.dataset.recordUri = String(data.record_uri || '');
    else if (!on && action !== 'bookmark') btn.dataset.recordUri = '';
  }

  function snapshot(btn) {
    return { html: btn.innerHTML, className: btn.className, title: btn.title,
      label: btn.getAttribute('aria-label'), pressed: btn.getAttribute('aria-pressed'),
      picker: btn.getAttribute('data-bm-picker'), recordUri: btn.dataset.recordUri };
  }

  function restore(btn, state) {
    btn.innerHTML = state.html;
    btn.className = state.className;
    btn.title = state.title;
    if (state.label === null) btn.removeAttribute('aria-label'); else btn.setAttribute('aria-label', state.label);
    if (state.pressed === null) btn.removeAttribute('aria-pressed'); else btn.setAttribute('aria-pressed', state.pressed);
    if (state.picker === null) btn.removeAttribute('data-bm-picker'); else btn.setAttribute('data-bm-picker', state.picker);
    if (state.recordUri === undefined) delete btn.dataset.recordUri; else btn.dataset.recordUri = state.recordUri;
  }

  async function send(btn, action, beforeState = null) {
    const body = new URLSearchParams({ action, ajax: '1', return_view: 'bookmarks' });
    for (const [key, field] of Object.entries({
      bsky_uri: 'uri', bsky_cid: 'cid', bsky_record_uri: 'recordUri',
      bsky_status_id: 'statusId', bsky_object_id: 'objectId',
    })) {
      if (btn.dataset[field]) body.set(key, btn.dataset[field]);
    }
    if (window.VAAK_CSRF) body.set('csrf', window.VAAK_CSRF);
    const response = await fetch('?view=bookmarks&ajax=1', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        ...(window.VAAK_CSRF ? { 'X-VAAK-CSRF': window.VAAK_CSRF } : {}) },
      body: body.toString(),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.ok) throw new Error(data.error || ('HTTP ' + response.status));
    if (data.queued && data.queue_id) {
      btn.dataset.queuePending = '1';
      btn.dataset.queueId = String(data.queue_id);
      btn.dataset.queueRevision = String(data.revision || '');
      watchQueue(btn, data.queue_id, data.revision, beforeState || snapshot(btn));
    }
    return data;
  }

  async function watchQueue(btn, queueId, revision, before) {
    for (let i = 0; i < 90 && btn.isConnected; i++) {
      await new Promise((resolve) => setTimeout(resolve, 2000));
      try {
        const body = new URLSearchParams({ action: 'action_queue_status', queue_id: String(queueId), ajax: '1' });
        if (window.VAAK_CSRF) body.set('csrf', window.VAAK_CSRF);
        const response = await fetch('?view=bookmarks&ajax=1', {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
          body: body.toString(),
        });
        const queue = (await response.json()).queue;
        if (!queue || Number(queue.revision) < Number(btn.dataset.queueRevision || revision)) continue;
        if (queue.status === 'succeeded') {
          if (Number(queue.revision) === Number(btn.dataset.queueRevision || revision)) {
            delete btn.dataset.queuePending;
            delete btn.dataset.queueId;
          }
          return;
        }
        if (queue.status === 'failed') {
          if (Number(queue.revision) === Number(btn.dataset.queueRevision || revision)) {
            restore(btn, before);
            delete btn.dataset.queuePending;
            delete btn.dataset.queueId;
            if (window.apAdminToast) window.apAdminToast(queue.last_error || 'Action failed after retrying.', true);
          }
          return;
        }
      } catch (e) { /* next status poll can reconcile the durable action */ }
    }
  }

  function removeCard(btn) {
    const card = btn.closest('article.tweet');
    if (!card) return;
    card.style.transition = 'opacity .2s ease';
    card.style.opacity = '0';
    setTimeout(() => card.remove(), 220);
  }

  async function removeBookmark(btn) {
    const before = snapshot(btn);
    setState(btn, 'bookmark', false);
    try {
      const data = await send(btn, 'bsky_unbookmark', before);
      setState(btn, 'bookmark', false, data);
      removeCard(btn);
      if (window.apAdminToast) window.apAdminToast('Bookmark removed.');
    } catch (error) {
      restore(btn, before);
      if (window.apAdminToast) window.apAdminToast(error.message || 'Unbookmark failed.', true);
    }
  }

  document.addEventListener('click', async (event) => {
    const btn = event.target && event.target.closest ? event.target.closest('button.bsky-action') : null;
    if (!btn) return;
    event.preventDefault();
    event.stopPropagation();
    const action = btn.dataset.bskyAction || '';
    if (!names[action]) return;
    const on = btn.classList.contains('on');

    if (action === 'bookmark' && on) {
      if (typeof window.novaOpenBookmarkFolderPicker === 'function') {
        window.novaOpenBookmarkFolderPicker(btn, btn.dataset.statusId || '', btn.dataset.objectId || btn.dataset.uri || '', null, {
          platform: 'bsky', onRemove: () => removeBookmark(btn),
        });
      }
      return;
    }
    if (btn.dataset.busy === '1' || btn.dataset.queuePending === '1') {
      if (window.apQueueRepeatedClick) window.apQueueRepeatedClick(btn);
      return;
    }

    const postAction = action === 'like' ? (on ? 'bsky_unlike' : 'bsky_like')
      : action === 'repost' ? (on ? 'bsky_unrepost' : 'bsky_repost') : 'bsky_bookmark';
    const before = snapshot(btn);
    const wantOn = !on;
    setState(btn, action, wantOn);
    btn.dataset.busy = '1';
    try {
      const data = await send(btn, postAction, before);
      const active = action === 'like' ? !!data.liked : action === 'repost' ? !!data.reposted : !!data.bookmarked;
      setState(btn, action, active, data);
      if (action === 'bookmark' && active && typeof window.novaOpenBookmarkFolderPicker === 'function') {
        window.novaOpenBookmarkFolderPicker(btn, btn.dataset.statusId || '', btn.dataset.objectId || btn.dataset.uri || '', data.folder_ids || [], {
          platform: 'bsky', onRemove: () => removeBookmark(btn),
        });
      }
    } catch (error) {
      restore(btn, before);
      if (window.apAdminToast) window.apAdminToast(error.message || 'Bluesky action failed.', true);
    } finally {
      btn.dataset.busy = '0';
    }
  }, true);
})();
