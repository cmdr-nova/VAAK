/* VAAK app script 5. Moved out of api/ap-admin.php (was an inline <script>) so
   browsers cache and precompile it; the shell loads these files in their old order.
*/
// Bluesky like/boost/bookmark on every view that can render a Bluesky card
// (opened status, remote profile, outbox), not only timeline tabs.
(function bindBskyActions() {
  if (document.documentElement.dataset.bskyActionsBound === '1') return;
  document.documentElement.dataset.bskyActionsBound = '1';

  function setBskyIcon(btn, phName, on, titles) {
    btn.classList.toggle('on', on);
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    btn.innerHTML = '<i class="ph' + (on ? '-fill' : '') + ' ph-' + phName + '" aria-hidden="true"></i>';
    const tOn = (titles && titles.on) || '';
    const tOff = (titles && titles.off) || '';
    btn.title = on ? tOn : tOff;
    btn.setAttribute('aria-label', on ? tOn : tOff);
  }

  async function watchBskyQueue(btn, queueId, revision, snapshot, actionKind = '') {
    let tries = 0;
    while (tries++ < 90) {
      await new Promise((resolve) => setTimeout(resolve, 2000));
      const body = new URLSearchParams({ action: 'action_queue_status', queue_id: String(queueId), ajax: '1' });
      if (window.VAAK_CSRF) body.set('csrf', window.VAAK_CSRF);
      try {
        const qv = btn.dataset.returnView || 'bluesky';
        const res = await fetch('?view=' + encodeURIComponent(qv) + '&ajax=1', {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', ...(window.VAAK_CSRF ? { 'X-VAAK-CSRF': window.VAAK_CSRF } : {}) },
          body: body.toString(),
        });
        const data = await res.json();
        const q = data && data.queue;
        if (!q || Number(q.revision) < Number(btn.dataset.queueRevision || revision)) continue;
        if (q.status === 'succeeded') {
          if (Number(q.revision) === Number(btn.dataset.queueRevision || revision)) {
            delete btn.dataset.queuePending;
            delete btn.dataset.queueId;
            if (window.apQueueRepeatedClickReset) window.apQueueRepeatedClickReset(btn);
          }
          if (actionKind === 'bsky_repost' && typeof window.apAdminToast === 'function') {
            window.apAdminToast('Boosted!');
          }
          if (actionKind === 'bsky_repost'
              && typeof window.novaRefreshTimelinePreservingPosition === 'function') {
            Promise.resolve(window.novaRefreshTimelinePreservingPosition()).catch(() => {});
          }
          return;
        }
        if (q.status === 'failed' && Number(q.revision) === Number(btn.dataset.queueRevision || revision)) {
          if (btn.isConnected) restoreBskyButton(btn, snapshot);
          delete btn.dataset.queuePending;
          delete btn.dataset.queueId;
          if (window.apQueueRepeatedClickReset) window.apQueueRepeatedClickReset(btn);
          if (typeof window.apAdminToast === 'function') window.apAdminToast(q.last_error || 'Action failed after retrying.', true);
          return;
        }
      } catch (e) { /* the persisted action can be reconciled by a later poll */ }
    }
  }

  async function postBskyAction(btn, postAction, before = null) {
    const uri = btn.dataset.uri || '';
    const cid = btn.dataset.cid || '';
    const recordUri = btn.dataset.recordUri || '';
    const objectRef = btn.dataset.objectRef || '';
    const returnView = btn.dataset.returnView || '';
    const body = new URLSearchParams();
    body.set('action', postAction);
    if (uri) body.set('bsky_uri', uri);
    if (cid) body.set('bsky_cid', cid);
    if (recordUri) body.set('bsky_record_uri', recordUri);
    if (btn.dataset.statusId) body.set('bsky_status_id', btn.dataset.statusId);
    if (btn.dataset.objectId) body.set('bsky_object_id', btn.dataset.objectId);
    if (objectRef) body.set('bsky_object', objectRef);
    if (returnView) body.set('return_view', returnView);
    body.set('ajax', '1');
    if (window.VAAK_CSRF) body.set('csrf', window.VAAK_CSRF);
    const viewQ = returnView ? ('view=' + encodeURIComponent(returnView) + '&') : 'view=bluesky&';
    const res = await fetch('?' + viewQ + 'ajax=1', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        ...(window.VAAK_CSRF ? { 'X-VAAK-CSRF': window.VAAK_CSRF } : {}),
      },
      body: body.toString(),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data || !data.ok) {
      const msg = (data && data.error) ? data.error : ('HTTP ' + res.status);
      throw new Error(msg);
    }
    if (data.queued && data.queue_id) {
      btn.dataset.queuePending = '1';
      btn.dataset.queueId = String(data.queue_id);
      btn.dataset.queueRevision = String(data.revision || '');
      watchBskyQueue(btn, data.queue_id, data.revision, before || snapshotBskyButton(btn), postAction);
    }
    return data;
  }

  function applyBskyBookmarkUi(btn, on, data) {
    setBskyIcon(btn, 'bookmark-simple', on, {
      on: 'Bookmark folders',
      off: 'Bookmark on Bluesky',
    });
    btn.setAttribute('data-bm-picker', on ? '1' : '0');
    if (data && data.status_id) btn.dataset.statusId = String(data.status_id);
    if (data && data.object_id) btn.dataset.objectId = String(data.object_id);
  }

  // Used by vaakSyncInteractionState (vaak-03.js) to mirror a saved action
  // onto other copies of the same Bluesky post (Home parked under
  // Notifications, profiles, threads).
  window.vaakApplyBskyState = function (btn, kind, on) {
    if (kind === 'favourite') {
      setBskyIcon(btn, 'heart', on, { on: 'Unlike', off: 'Like on Bluesky' });
      if (!on) btn.dataset.recordUri = '';
    } else if (kind === 'reblog') {
      btn.classList.toggle('on', on);
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
      btn.title = on ? 'Undo boost' : 'Boost on Bluesky';
      btn.setAttribute('aria-label', on ? 'Undo boost' : 'Boost');
      if (!on) btn.dataset.recordUri = '';
    } else if (kind === 'bookmark') {
      applyBskyBookmarkUi(btn, on);
    }
  };

  function snapshotBskyButton(btn) {
    return {
      html: btn.innerHTML,
      className: btn.className,
      title: btn.title,
      ariaLabel: btn.getAttribute('aria-label'),
      ariaPressed: btn.getAttribute('aria-pressed'),
      recordUri: btn.dataset.recordUri,
      bmPicker: btn.getAttribute('data-bm-picker'),
    };
  }

  function restoreBskyButton(btn, state) {
    btn.innerHTML = state.html;
    btn.className = state.className;
    btn.title = state.title;
    if (state.ariaLabel === null) btn.removeAttribute('aria-label');
    else btn.setAttribute('aria-label', state.ariaLabel);
    if (state.ariaPressed === null) btn.removeAttribute('aria-pressed');
    else btn.setAttribute('aria-pressed', state.ariaPressed);
    if (state.recordUri === undefined) delete btn.dataset.recordUri;
    else btn.dataset.recordUri = state.recordUri;
    if (state.bmPicker === null) btn.removeAttribute('data-bm-picker');
    else btn.setAttribute('data-bm-picker', state.bmPicker);
  }

  document.addEventListener('click', async (ev) => {
    const btn = ev.target && ev.target.closest ? ev.target.closest('button.bsky-action') : null;
    if (!btn) return;
    ev.preventDefault();
    ev.stopPropagation();
    if (btn.dataset.busy === '1' || btn.dataset.queuePending === '1') {
      if (window.apQueueRepeatedClick) window.apQueueRepeatedClick(btn);
      return;
    }
    const action = btn.dataset.bskyAction || '';
    const card = btn.closest('article');
    let uri = btn.dataset.uri || (card && card.dataset.bskyUri) || '';
    let cid = btn.dataset.cid || (card && card.dataset.bskyCid) || '';
    const recordUri = btn.dataset.recordUri || '';
    let objectRef = btn.dataset.objectRef || (card && card.dataset.bskyObject) || '';
    const isOn = btn.classList.contains('on');
    if (!action) return;
    if (!objectRef && (uri || (card && card.dataset.bskyUri))) {
      objectRef = uri || card.dataset.bskyUri;
    }

    // Already bookmarked: open folder picker (same as federated cards).
    if (action === 'bookmark' && isOn && btn.getAttribute('data-bm-picker') === '1') {
      const sid = btn.dataset.statusId || '';
      const oid = btn.dataset.objectId || uri;
      if (sid && typeof window.novaOpenBookmarkFolderPicker === 'function') {
        window.novaOpenBookmarkFolderPicker(btn, sid, oid, null, {
          onRemove: async () => {
            const before = snapshotBskyButton(btn);
            applyBskyBookmarkUi(btn, false);
            btn.dataset.busy = '1';
            btn.disabled = true;
            try {
              const data = await postBskyAction(btn, 'bsky_unbookmark', before);
              applyBskyBookmarkUi(btn, false, data);
              if (window.vaakHaptic) window.vaakHaptic(6);
            } catch (e) {
              restoreBskyButton(btn, before);
              if (typeof window.apAdminToast === 'function') {
                window.apAdminToast((e && e.message) || 'Unbookmark failed', true);
              }
            } finally {
              btn.dataset.busy = '0';
              btn.disabled = false;
            }
          },
        });
      }
      return;
    }

    let postAction = '';
    if (action === 'like') postAction = isOn ? 'bsky_unlike' : 'bsky_like';
    else if (action === 'repost') postAction = isOn ? 'bsky_unrepost' : 'bsky_repost';
    else if (action === 'bookmark') postAction = 'bsky_bookmark';
    else return;
    // Timeline buttons have uri+cid. Notification like buttons may only have
    // objectRef (https://bsky.app/…); the server resolves the CID on click.
    if (postAction === 'bsky_like' && !(uri || objectRef)) {
      if (typeof window.apAdminToast === 'function') {
        window.apAdminToast('Couldn’t like — missing Bluesky post id. Refresh and try again.', true);
      }
      return;
    }
    if ((postAction === 'bsky_repost' || postAction === 'bsky_bookmark')
        && !((uri && cid) || objectRef)) {
      if (typeof window.apAdminToast === 'function') {
        window.apAdminToast('Couldn’t boost/bookmark — missing Bluesky post id. Refresh and try again.', true);
      }
      return;
    }
    btn.dataset.uri = uri;
    if (cid) btn.dataset.cid = cid;
    if (objectRef) btn.dataset.objectRef = objectRef;
    const before = snapshotBskyButton(btn);
    const optimisticOn = postAction === 'bsky_like' || postAction === 'bsky_repost' || postAction === 'bsky_bookmark';
    if (action === 'like') setBskyIcon(btn, 'heart', optimisticOn, { on: 'Unlike', off: 'Like on Bluesky' });
    else if (action === 'repost') {
      btn.classList.toggle('on', optimisticOn);
      btn.setAttribute('aria-pressed', optimisticOn ? 'true' : 'false');
      btn.title = optimisticOn ? 'Undo boost' : 'Boost on Bluesky';
      btn.setAttribute('aria-label', optimisticOn ? 'Undo boost' : 'Boost');
    } else if (action === 'bookmark') applyBskyBookmarkUi(btn, optimisticOn);
    if (window.vaakHaptic) window.vaakHaptic(5);
    // Background queue — never flash the page-level Saving… / Loading… pill.
    if (typeof window.vaakHideLoading === 'function') window.vaakHideLoading();
    btn.dataset.busy = '1';
    btn.disabled = false;
    try {
      const data = await postBskyAction(btn, postAction, before);
      if (typeof window.vaakSyncInteractionState === 'function') {
        const syncKind = action === 'like' ? 'favourite' : (action === 'repost' ? 'reblog' : 'bookmark');
        const syncOn = action === 'like' ? !!data.liked : (action === 'repost' ? !!data.reposted : !!data.bookmarked);
        window.vaakSyncInteractionState(syncKind, syncOn,
          [data.uri, data.object_id, uri, objectRef, btn.dataset.objectId], btn);
      }
      // Optimistic icon is enough; only toast on failure (watchBskyQueue).
      if (action === 'like') {
        const on = !!data.liked;
        setBskyIcon(btn, 'heart', on, { on: 'Unlike', off: 'Like on Bluesky' });
        if (!on) btn.dataset.recordUri = '';
        else if (data.record_uri) btn.dataset.recordUri = data.record_uri;
        if (data.uri) btn.dataset.uri = String(data.uri);
        if (data.cid) btn.dataset.cid = String(data.cid);
      } else if (action === 'repost') {
        const on = !!data.reposted;
        // Match federated boosts: color via .on, no fill variant for ph-repeat.
        btn.classList.toggle('on', on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        btn.title = on ? 'Undo boost' : 'Boost on Bluesky';
        btn.setAttribute('aria-label', on ? 'Undo boost' : 'Boost');
        if (!on) btn.dataset.recordUri = '';
        else if (data.record_uri) btn.dataset.recordUri = data.record_uri;
        if (on && !data.queued && typeof window.novaRefreshTimelinePreservingPosition === 'function') {
          Promise.resolve(window.novaRefreshTimelinePreservingPosition()).catch(() => {});
        }
      } else if (action === 'bookmark') {
        applyBskyBookmarkUi(btn, !!data.bookmarked, data);
        if (data.bookmarked && data.open_folder_picker && typeof window.novaOpenBookmarkFolderPicker === 'function') {
          window.novaOpenBookmarkFolderPicker(
            btn,
            String(data.status_id || btn.dataset.statusId || ''),
            String(data.object_id || btn.dataset.objectId || uri),
            data.folder_ids || [],
            {
              onRemove: async () => {
                btn.dataset.busy = '1';
                btn.disabled = true;
                try {
                  const removed = await postBskyAction(btn, 'bsky_unbookmark');
                  applyBskyBookmarkUi(btn, false, removed);
                } catch (e) {
                  if (typeof window.apAdminToast === 'function') {
                    window.apAdminToast((e && e.message) || 'Unbookmark failed', true);
                  }
                } finally {
                  btn.dataset.busy = '0';
                  btn.disabled = false;
                }
              },
            }
          );
        }
      }
      if (window.vaakHaptic) window.vaakHaptic(6);
    } catch (e) {
      restoreBskyButton(btn, before);
      const msg = (e && e.message) ? e.message : 'Bluesky action failed — try again';
      btn.title = msg;
      if (typeof window.apAdminToast === 'function') {
        window.apAdminToast(msg, true);
      }
      if (window.vaakHaptic) window.vaakHaptic(8);
    } finally {
      btn.dataset.busy = '0';
      btn.disabled = false;
    }
  });
})();
