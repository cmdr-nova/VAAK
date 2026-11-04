/* VAAK app script 4. Moved out of api/ap-admin.php (was an inline <script>) so
   browsers cache and precompile it; the shell loads these files in their old order.
*/
// Weak, privacy-bounded Home and single-post feedback for the logged-in
// account. Visible time is batched and sent occasionally. Idle tabs and
// glances under six seconds are not dwell. Nothing here is federated.
(function bindHomePassiveSignals() {
  const probe = window.__VAAK_HOME_SIGNAL_PROBE === true;
  if (!probe && (document.documentElement.dataset.ownerSignals !== '1' || !('IntersectionObserver' in window) || document.documentElement.dataset.homeSignalsBound === '1')) return;
  document.documentElement.dataset.homeSignalsBound = '1';
  const MIN_DWELL_MS = 6000;
  const MAX_DWELL_S = 300;
  const IMPRESSION_MS = 1200;
  const IDLE_MS = 180000;
  const FLUSH_MS = 20000;
  const FLUSH_AT = 8;
  const states = new WeakMap();
  const active = new Set();
  const onscreen = new Set();
  const watched = new Set();
  const queue = [];
  const requests = [];
  let flushTimer = 0;
  let lastInput = performance.now();
  let idleTimer = 0;

  function now() { return performance.now(); }
  function isTracked(card) {
    if (!card || !card.dataset || !card.dataset.rankKey || !card.dataset.rankActor) return false;
    if (card.id === 'status-focus') return true;
    const items = card.closest && card.closest('#timeline-items');
    return !!(items && items.dataset.view === 'home');
  }
  function enqueue(kind, card, dwellSeconds) {
    if (!isTracked(card) || !window.VAAK_CSRF) return;
    const seconds = dwellSeconds ? Math.min(MAX_DWELL_S, Math.max(0, Math.round(dwellSeconds))) : 0;
    if (kind === 'dwell' && seconds < 6) return;
    queue.push({
      kind: kind,
      platform: card.dataset.rankPlatform || '',
      target_key: card.dataset.rankKey || '',
      target_actor: card.dataset.rankActor || '',
      dwell_seconds: kind === 'dwell' ? seconds : 0
    });
    if (queue.length >= FLUSH_AT) flush(false);
    else if (!flushTimer) flushTimer = window.setTimeout(function () { flush(false); }, FLUSH_MS);
  }
  function flush(beacon) {
    if (flushTimer) { clearTimeout(flushTimer); flushTimer = 0; }
    if (!queue.length || !window.VAAK_CSRF) { queue.length = 0; return; }
    const url = '/vaak/?view=home&ajax=home_signal_batch';
    do {
      const events = queue.splice(0, FLUSH_AT);
      requests.push(events);
      const fd = new FormData();
      fd.set('csrf', window.VAAK_CSRF);
      fd.set('events', JSON.stringify(events));
      if (beacon && navigator.sendBeacon && navigator.sendBeacon(url, fd)) {
        continue;
      }
      fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true }).catch(function () {});
      if (queue.length) flushTimer = window.setTimeout(function () { flush(false); }, FLUSH_MS);
      return;
    } while (queue.length);
  }
  function stateFor(card) {
    let state = states.get(card);
    if (!state) {
      state = { visible: false, started: 0, total: 0, impression: false, dwell: false, click: false, timer: 0 };
      states.set(card, state);
      watched.add(card);
    }
    return state;
  }
  function userIdle() { return now() - lastInput >= IDLE_MS; }
  function start(card) {
    if (!isTracked(card) || document.hidden || userIdle()) return;
    const state = stateFor(card);
    if (state.visible || state.dwell) return;
    state.visible = true;
    state.started = now();
    active.add(card);
    if (!state.impression) {
      clearTimeout(state.timer);
      state.timer = window.setTimeout(function () {
        if (!state.visible || document.hidden || state.impression || userIdle()) return;
        state.impression = true;
        enqueue('impression', card, 0);
      }, IMPRESSION_MS);
    }
  }
  function pause(card) {
    const state = states.get(card);
    if (!state || !state.visible) return;
    state.total = Math.min(MAX_DWELL_S * 1000, state.total + Math.max(0, now() - state.started));
    state.started = 0;
    state.visible = false;
    active.delete(card);
    clearTimeout(state.timer);
  }
  function commitDwell(card) {
    const state = states.get(card);
    if (!state || state.dwell) return;
    if (state.visible) {
      state.total = Math.min(MAX_DWELL_S * 1000, state.total + Math.max(0, now() - state.started));
      state.started = now();
    }
    if (state.total < MIN_DWELL_MS) return;
    state.dwell = true;
    state.visible = false;
    state.started = 0;
    active.delete(card);
    clearTimeout(state.timer);
    enqueue('dwell', card, Math.round(state.total / 1000));
  }
  function leave(card) {
    commitDwell(card);
    pause(card);
  }
  const observer = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      const enough = entry.isIntersecting
        && entry.intersectionRect.height >= Math.min(240, entry.boundingClientRect.height * 0.5);
      if (enough) {
        onscreen.add(entry.target);
        start(entry.target);
      } else {
        onscreen.delete(entry.target);
        leave(entry.target);
      }
    });
  }, { threshold: [0, 0.25, 0.5] });
  function bind(root) {
    const scope = root && root.querySelectorAll ? root : document;
    const cards = [];
    if (scope.matches && scope.matches('article.tweet[data-rank-key], article#status-focus')) cards.push(scope);
    if (scope.querySelectorAll) {
      scope.querySelectorAll('article.tweet[data-rank-key], article#status-focus').forEach(function (card) { cards.push(card); });
    }
    cards.forEach(function (card) {
      if (isTracked(card) && !states.has(card)) {
        stateFor(card);
        observer.observe(card);
      }
    });
  }
  bind(document);
  const mutations = new MutationObserver(function (records) {
    records.forEach(function (record) {
      record.addedNodes.forEach(function (node) { if (node.nodeType === 1) bind(node); });
      record.removedNodes.forEach(function (node) {
        if (node.nodeType !== 1) return;
        if (states.has(node)) { onscreen.delete(node); leave(node); }
        if (node.querySelectorAll) node.querySelectorAll('article.tweet[data-rank-key], article#status-focus').forEach(function (card) {
          onscreen.delete(card); leave(card);
        });
      });
    });
  });
  if (document.body) mutations.observe(document.body, { childList: true, subtree: true });
  function noteInput() { lastInput = now(); if (!document.hidden) Array.from(onscreen).forEach(start); }
  ['mousemove', 'keydown', 'pointerdown', 'touchstart'].forEach(function (name) {
    window.addEventListener(name, noteInput, { passive: true, capture: true });
  });
  // Scroll only marks that someone is here. It does not send a request.
  window.addEventListener('scroll', noteInput, { passive: true, capture: true });
  function freezeIdle() {
    if (!userIdle()) return;
    Array.from(active).forEach(pause);
  }
  idleTimer = window.setInterval(freezeIdle, 15000);
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) Array.from(active).forEach(function (card) { commitDwell(card); pause(card); });
    else Array.from(onscreen).forEach(start);
  });
  window.addEventListener('pagehide', function () {
    Array.from(watched).forEach(commitDwell);
    flush(true);
  });
  document.addEventListener('click', function (event) {
    noteInput();
    const card = event.target && event.target.closest && event.target.closest('article.tweet[data-rank-key], article#status-focus');
    if (!card || !isTracked(card) || event.target.closest('.tweet-actions, form, .post-action-menu')) return;
    const meaningful = event.target.closest('a[href], .tweet-content-more, .media-thumb, video, audio');
    if (!meaningful) return;
    const state = stateFor(card);
    if (!state.click) {
      state.click = true;
      enqueue('click', card, 0);
    }
  }, true);
  if (probe) {
    window.__VAAK_HOME_SIGNAL_PROBE = {
      isTracked: isTracked,
      enqueue: enqueue,
      flush: flush,
      start: start,
      leave: leave,
      pause: pause,
      commitDwell: commitDwell,
      noteInput: noteInput,
      freezeIdle: freezeIdle,
      queue: queue,
      requests: requests,
      states: states,
      MIN_DWELL_MS: MIN_DWELL_MS,
      MAX_DWELL_S: MAX_DWELL_S,
      IDLE_MS: IDLE_MS,
      setIdleFor: function (ms) { lastInput = now() - ms; freezeIdle(); }
    };
  }
})();
