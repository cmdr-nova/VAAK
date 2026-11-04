/* VAAK app script 6. Moved out of api/ap-admin.php (was an inline <script>) so
   browsers cache and precompile it; the shell loads these files in their old order.
*/
(function () {
let stopThread = null;
window.vaakStopStatusThread = function () { if (stopThread) stopThread(); stopThread = null; };
window.vaakBootStatusThread = function () {
  window.vaakStopStatusThread();
  if (!document.querySelector('.status-back')) return false;
  // Guests get the server-painted, cache-only thread; thread partials need a session.
  if (window.vaakGuestProfile) return false;
  /** Cancel all thread hydrate/poll fetches when leaving Status (Home click, back, etc.). */
  let threadAlive = true;
  let threadAbort = new AbortController();
  const threadEvents = new AbortController();
  let replyPollTimer = null;
  let replyPollInterval = null;
  let replyPollBusy = false;
  const REPLY_POLL_MS = 45000;

  function killThreadWork() {
    if (!threadAlive) return;
    threadAlive = false;
    threadEvents.abort();
    try { threadAbort.abort(); } catch (e) {}
    if (replyPollTimer) { clearTimeout(replyPollTimer); replyPollTimer = null; }
    if (replyPollInterval) { clearInterval(replyPollInterval); replyPollInterval = null; }
    replyPollBusy = false;
  }

  stopThread = killThreadWork;
  // Full navigation away from this page — abort before the next view competes for PHP-FPM.
  window.addEventListener('pagehide', killThreadWork, { signal: threadEvents.signal });
  window.addEventListener('beforeunload', killThreadWork, { signal: threadEvents.signal });
  document.addEventListener('click', (ev) => {
    const a = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
    if (!a) return;
    if (ev.defaultPrevented || ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return;
    if (a.target && a.target !== '' && a.target !== '_self') return;
    const href = a.getAttribute('href') || '';
    if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;
    // Opening the shared composer stays within the same thread.
    if (/[?&](?:compose=|view=compose(?:&|$))/.test(href)) return;
    // Same-page status refresh / Retry — keep work alive.
    if (href.indexOf('view=status') !== -1 && href.indexOf('object=') !== -1) return;
    if (/(?:^|\/)vaak\/@[A-Za-z0-9_]+\/[A-Za-z0-9_-]+/.test(href)) return;
    killThreadWork();
  }, { capture: true, signal: threadEvents.signal });

  function threadFetch(url, init) {
    if (!threadAlive) return Promise.reject(new DOMException('Thread left', 'AbortError'));
    const opts = Object.assign({
      credentials: 'same-origin',
      headers: { 'Accept': 'text/html' },
      cache: 'no-store',
      signal: threadAbort.signal
    }, init || {});
    return fetch(url, opts);
  }

  function isAbort(err) {
    return !threadAlive || (err && (err.name === 'AbortError' || err.code === 20));
  }

  /** Keep the focused post visually stable when content inserts above it (Mastodon-style). */
  function withFocusAnchor(fn) {
    const focus = document.getElementById('status-focus')
      || document.querySelector('article.tweet-focus');
    const before = focus ? focus.getBoundingClientRect().top : null;
    fn();
    if (focus && before !== null) {
      const after = focus.getBoundingClientRect().top;
      const delta = after - before;
      if (Math.abs(delta) > 1) {
        const feed = focus.closest('.feed');
        const overflow = feed ? getComputedStyle(feed).overflowY : '';
        if (feed && (overflow === 'auto' || overflow === 'scroll')) feed.scrollTop += delta;
        else window.scrollBy(0, delta);
      }
    }
  }

  function loadThreadPart(box, threadParam, emptyMsg, failMsg) {
    if (!threadAlive) return;
    if (!box || box.dataset.needMore !== '1' || box.dataset.loading === '1') return;
    const objectUrl = box.dataset.object || '';
    const from = box.dataset.from || 'home';
    if (!objectUrl) return;
    box.dataset.loading = '1';
    const loading = box.querySelector('.status-thread-loading');
    threadFetch('?view=status&partial=1&thread=' + encodeURIComponent(threadParam)
        + '&object=' + encodeURIComponent(objectUrl)
        + '&from=' + encodeURIComponent(from))
      .then((res) => res.ok ? res.text() : Promise.reject(new Error('HTTP ' + res.status)))
      .then((html) => {
        if (!threadAlive) return;
        box.dataset.needMore = '0';
        box.dataset.loading = '0';
        if (!html || !html.trim()) {
          if (loading) loading.textContent = emptyMsg;
          return;
        }
        // Ancestors insert above the focus post — lock scroll to the focused card.
        if (threadParam === '1' || threadParam === 'ancestors') {
          withFocusAnchor(() => {
            box.innerHTML = html;
          });
        } else {
          // Replies grow below — leave scroll where it is (never jump to top).
          // Keep existing cached replies if the remote pass found nothing new.
          const tmp = document.createElement('div');
          tmp.innerHTML = html;
          const newCount = tmp.querySelectorAll('article.tweet').length;
          const oldCount = parseInt(box.dataset.seenCount || '0', 10);
          if (newCount === 0 && oldCount > 0) {
            if (loading) loading.remove();
            return;
          }
          if (newCount > 0 && newCount < oldCount) {
            if (loading) loading.remove();
            return;
          }
          mergeNewReplies(box, html);
        }
        if (typeof window.novaEnhanceTweetFolds === 'function') {
          window.novaEnhanceTweetFolds(box);
        }
      })
      .catch((err) => {
        box.dataset.loading = '0';
        if (isAbort(err)) return;
        if (loading) {
          loading.innerHTML = failMsg + ' ' + window.vaakStatusRetryHtml(objectUrl, from);
        }
      });
  }

  function ensureThreadShells(objectUrl, from) {
    let anc = document.getElementById('status-thread-ancestors');
    let desc = document.getElementById('status-thread-descendants');
    const focus = document.getElementById('status-focus')
      || document.querySelector('article.tweet-focus');
    if (!anc) {
      anc = document.createElement('div');
      anc.id = 'status-thread-ancestors';
      anc.dataset.object = objectUrl;
      anc.dataset.from = from;
      anc.dataset.needMore = '1';
      anc.innerHTML = '<div class="meta status-thread-loading" style="margin-bottom:.5rem">Loading earlier posts…</div>';
      if (focus && focus.parentNode) {
        focus.parentNode.insertBefore(anc, focus);
      }
    } else if (anc.dataset.needMore !== '1' && !anc.querySelector('article.tweet')) {
      anc.dataset.needMore = '1';
      if (!anc.querySelector('.status-thread-loading')) {
        anc.innerHTML = '<div class="meta status-thread-loading" style="margin-bottom:.5rem">Loading earlier posts…</div>';
      }
    }
    if (!desc) {
      desc = document.createElement('div');
      desc.id = 'status-thread-descendants';
      desc.dataset.object = objectUrl;
      desc.dataset.from = from;
      desc.dataset.needMore = '1';
      desc.dataset.seenCount = '0';
      desc.innerHTML = '<div class="meta status-thread-loading" style="margin-top:1rem">Loading replies…</div>';
      if (focus && focus.parentNode) {
        focus.parentNode.insertBefore(desc, focus.nextSibling);
      }
    }
    return { anc, desc };
  }

  function loadFocusThenThread() {
    if (!threadAlive) return;
    const box = document.getElementById('status-thread-focus');
    if (!box || box.dataset.needMore !== '1') {
      loadThreadPart(
        document.getElementById('status-thread-ancestors'),
        'ancestors',
        'No earlier posts found.',
        'Couldn’t load earlier posts.'
      );
      loadThreadPart(
        document.getElementById('status-thread-descendants'),
        'replies',
        'No replies found yet.',
        'Couldn’t load replies.'
      );
      return;
    }
    const objectUrl = box.dataset.object || '';
    const from = box.dataset.from || 'home';
    if (!objectUrl) return;
    box.dataset.loading = '1';
    const loading = box.querySelector('.status-thread-loading');
    threadFetch('?view=status&partial=1&thread=focus&object=' + encodeURIComponent(objectUrl)
        + '&from=' + encodeURIComponent(from))
      .then((res) => res.ok ? res.text() : Promise.reject(new Error('HTTP ' + res.status)))
      .then((html) => {
        if (!threadAlive) return;
        box.dataset.needMore = '0';
        box.dataset.loading = '0';
        if (!html || !html.trim() || !html.includes('article')) {
          if (loading) {
            loading.innerHTML = 'Couldn’t load this post. ' + window.vaakStatusRetryHtml(objectUrl, from);
          }
          return;
        }
        // Swap loading shell for the focus card (keep scroll — nothing above yet).
        const wrap = document.createElement('div');
        wrap.innerHTML = html.trim();
        const card = wrap.firstElementChild;
        if (!card) return;
        box.replaceWith(card);
        if (typeof window.novaEnhanceTweetFolds === 'function') {
          window.novaEnhanceTweetFolds(card);
        }
        // Now pull parents (source posts) + replies around the hydrated focus.
        const shells = ensureThreadShells(objectUrl, from);
        loadThreadPart(shells.anc, 'ancestors', 'No earlier posts found.', 'Couldn’t load earlier posts.');
        loadThreadPart(shells.desc, 'replies', 'No replies found yet.', 'Couldn’t load replies.');
      })
      .catch((err) => {
        box.dataset.loading = '0';
        if (isAbort(err)) return;
        if (loading) {
          loading.innerHTML = 'Couldn’t load this post. ' + window.vaakStatusRetryHtml(objectUrl, from);
        }
      });
  }

  /** Object URL key for a reply card (used to detect newly arrived replies). */
  function replyCardKey(el) {
    if (!el || !el.querySelector) return '';
    const sourceKey = el.dataset.noteId || el.dataset.objectId;
    if (sourceKey) return sourceKey;
    for (const link of el.querySelectorAll('a[href*="object="], a[href*="/vaak/@"]')) {
      try {
        const url = new URL(link.href, window.location.href);
        const short = window.vaakShortStatusTarget && window.vaakShortStatusTarget(url);
        if (short && short.object) return short.object;
        const object = url.searchParams.get('object');
        if (object) return object;
      } catch (e) {}
    }
    return el.id || '';
  }

  function mergeNewReplies(box, html) {
    const tmp = document.createElement('div');
    tmp.innerHTML = html || '';
    const incoming = Array.from(tmp.querySelectorAll('article.tweet'));
    const loading = box.querySelector('.status-thread-loading');
    if (!incoming.length) {
      if (loading) loading.remove();
      return 0;
    }
    const known = {};
    box.querySelectorAll('article.tweet').forEach((el) => {
      const k = replyCardKey(el);
      if (k) known[k] = true;
    });
    const added = [];
    incoming.forEach((el) => {
      const k = replyCardKey(el);
      if (k && known[k]) return;
      if (k) known[k] = true;
      added.push(el);
    });
    if (!added.length) {
      if (loading) loading.remove();
      const have = box.querySelectorAll('article.tweet').length;
      if (have > 0) box.dataset.seenCount = String(have);
      return 0;
    }
    box.querySelectorAll('.status-thread-loading, .empty, .status-thread-empty').forEach((el) => el.remove());
    const total = box.querySelectorAll('article.tweet').length + added.length;
    let countEl = box.querySelector('.status-thread-count');
    if (!countEl) {
      countEl = document.createElement('div');
      countEl.className = 'meta status-thread-count';
      countEl.style.cssText = 'margin:1rem 0 .5rem';
      box.insertBefore(countEl, box.firstChild);
    }
    countEl.textContent = total + ' replies';
    const frag = document.createDocumentFragment();
    added.forEach((el) => frag.appendChild(el));
    box.appendChild(frag);
    box.dataset.seenCount = String(total);
    if (typeof window.novaEnhanceTweetFolds === 'function') {
      window.novaEnhanceTweetFolds(box);
    }
    return added.length;
  }

  function pollThreadReplies() {
    if (!threadAlive || replyPollBusy) return;
    if (document.visibilityState === 'hidden') return;
    const box = document.getElementById('status-thread-descendants');
    if (!box) return;
    // Wait out the one-shot hydrate (needMore / loading flags).
    if (box.dataset.loading === '1' || box.dataset.needMore === '1') return;
    const focusBusy = document.getElementById('status-thread-focus');
    if (focusBusy && focusBusy.dataset.loading === '1') return;
    const objectUrl = box.dataset.object || '';
    const from = box.dataset.from || 'home';
    if (!objectUrl) return;
    replyPollBusy = true;
    threadFetch('?view=status&partial=1&thread=replies'
        + '&object=' + encodeURIComponent(objectUrl)
        + '&from=' + encodeURIComponent(from)
        + '&poll=1')
      .then((res) => res.ok ? res.text() : Promise.reject(new Error('HTTP ' + res.status)))
      .then((html) => {
        if (!threadAlive) return;
        mergeNewReplies(box, html);
      })
      .catch((err) => { if (!isAbort(err)) { /* quiet */ } })
      .finally(() => { replyPollBusy = false; });
  }

  window.vaakHydrateEditedThreadPost = async function (noteId) {
    if (!threadAlive || !noteId) return false;
    const old = Array.from(document.querySelectorAll('section.main article.tweet'))
      .find(card => replyCardKey(card) === noteId);
    if (!old) return false;
    const res = await threadFetch('/vaak/?view=status&partial=1&thread=focus&object=' + encodeURIComponent(noteId));
    if (!res.ok || !threadAlive || !old.isConnected) return false;
    const tmp = document.createElement('div');
    tmp.innerHTML = await res.text();
    const card = tmp.querySelector('article.tweet');
    if (!card || !threadAlive || !old.isConnected) return false;
    withFocusAnchor(() => old.replaceWith(card));
    if (typeof window.novaEnhanceTweetFolds === 'function') window.novaEnhanceTweetFolds(card);
    return true;
  };
  window.vaakHydratePublishedThreadReply = async function (noteId) {
    if (!threadAlive) return false;
    const box = document.getElementById('status-thread-descendants');
    if (!box || !box.dataset.object || !noteId) return false;
    const res = await threadFetch('/vaak/?view=status&partial=1&thread=published&object='
      + encodeURIComponent(box.dataset.object) + '&note_id=' + encodeURIComponent(noteId)
      + '&from=' + encodeURIComponent(box.dataset.from || 'home'));
    if (!res.ok || !threadAlive || !box.isConnected) return false;
    const html = await res.text();
    if (!threadAlive || !box.isConnected) return false;
    withFocusAnchor(() => mergeNewReplies(box, html));
    return true;
  };
  loadFocusThenThread();
  // Live replies: wait for first hydrate, then quiet origin rechecks.
  replyPollTimer = setTimeout(pollThreadReplies, 20000);
  replyPollInterval = setInterval(pollThreadReplies, REPLY_POLL_MS);
  document.addEventListener('visibilitychange', () => {
    if (!threadAlive) return;
    if (document.visibilityState === 'hidden') {
      // Don't let a background tab keep a long remote context request running.
      try { threadAbort.abort(); } catch (e) {}
      threadAbort = new AbortController();
      replyPollBusy = false;
      return;
    }
    pollThreadReplies();
  }, { signal: threadEvents.signal });
  return true;
};
window.vaakBootStatusThread();
})();
