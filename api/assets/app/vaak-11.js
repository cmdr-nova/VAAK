/* VAAK app script 11. Moved out of api/ap-admin.php (was an inline <script>) so
   browsers cache and precompile it; the shell loads these files in their old order.
   Request values are in VAAK_BOOT.s11, filled by an inline script right before it. */
// Cached global candidates are cheap; viewer-specific cards stay cached for a short TTL.
(function () {
  // Trending tags (with Trending posts inside it) and Who to follow.
  const MODULES = ['tags', 'follow'];
  const TTL_MS = 10 * 60 * 1000;
  const STORAGE_KEY = 'vaak.trends.modules.v2';
  const FOLLOW_SHOW = 3;
  const FOLLOW_ROTATE_MS = 60 * 1000;
  // On VAAK (default) or Across networks; the switch only renders when the
  // trends service has network data.
  const SCOPE_STORE = 'vaak.trends.scope.v1';
  function scope() {
    if (!document.getElementById('trends-scope')) return 'vaak';
    try { return localStorage.getItem(SCOPE_STORE) === 'network' ? 'network' : 'vaak'; } catch (e) { return 'vaak'; }
  }
  // Only the tags card differs by scope; Who to follow is the same in both.
  function slot(module) { return module === 'tags' && scope() === 'network' ? module + ':network' : module; }
  let generation = 0;
  let scheduled = false;
  let scheduleTicket = null;
  let mutationPending = false;
  let suspended = false;
  let active = 0;
  let pendingAbort = null;
  let resumeTimer = 0;
  const waiters = [];
  const inflight = new Map();
  const loadingHtml = VAAK_BOOT.s11[0];

  function readStore() {
    try {
      const raw = sessionStorage.getItem(STORAGE_KEY);
      const data = raw ? JSON.parse(raw) : null;
      return data && typeof data === 'object' ? data : null;
    } catch (e) { return null; }
  }
  function writeStore(data) {
    try { sessionStorage.setItem(STORAGE_KEY, JSON.stringify(data)); } catch (e) {}
  }
  function clearStore() {
    try {
      sessionStorage.removeItem(STORAGE_KEY);
      sessionStorage.removeItem('vaak.trends.html.v3');
    } catch (e) {}
  }
  try {
    sessionStorage.removeItem('vaak.trends.html.v3');
    sessionStorage.removeItem('vaak.trends.modules.v1');
  } catch (e) {}
  function storeFor(box) {
    const data = readStore();
    if (!data || String(data.owner) !== String(box.dataset.owner || '0')) return null;
    if (String(data.enabled) !== String(box.dataset.enabled || '0')) return null;
    return data;
  }
  function moduleRow(box, module) {
    const data = storeFor(box);
    return data && data.modules ? data.modules[slot(module)] : null;
  }
  function moduleFresh(box, module) {
    const row = moduleRow(box, module);
    return !!(row && row.ok && (Date.now() - row.savedAt) < TTL_MS);
  }
  function touch(box, module, html, etag) {
    const data = storeFor(box) || {
      v: 1,
      owner: String(box.dataset.owner || '0'),
      enabled: String(box.dataset.enabled || '0'),
      modules: {}
    };
    data.owner = String(box.dataset.owner || '0');
    data.enabled = String(box.dataset.enabled || '0');
    data.modules = data.modules || {};
    data.modules[slot(module)] = { html: html || '', etag: etag || '', savedAt: Date.now(), ok: true };
    writeStore(data);
  }
  function visible(box) {
    if (suspended || document.hidden || !box || !box.isConnected || box.dataset.enabled !== '1' || box.hidden) return false;
    const rect = box.getBoundingClientRect();
    return box.getClientRects().length > 0 && rect.width > 0
      && rect.bottom >= 0 && rect.top < innerHeight && rect.right > 0 && rect.left < innerWidth;
  }
  function rewriteFrom(box) {
    if (!box) return;
    const from = box.dataset.from || 'home';
    box.querySelectorAll('a[href]').forEach((link) => {
      const href = link.getAttribute('href') || '';
      if (!/[?&]from=/.test(href)) return;
      try {
        const url = new URL(href, location.href);
        url.searchParams.set('from', from);
        link.setAttribute('href', url.pathname + url.search + url.hash);
      } catch (e) {}
    });
  }
  function paintHtml(box, module, html) {
    const existing = box.querySelector('[data-trend-module="' + module + '"]');
    if (!html) {
      if (existing) existing.remove();
      return;
    }
    const holder = document.createElement('div');
    holder.innerHTML = html;
    const next = holder.querySelector('[data-trend-module="' + module + '"]') || holder.firstElementChild;
    if (!next) return;
    if (!next.getAttribute('data-trend-module')) next.setAttribute('data-trend-module', module);
    if (existing) existing.replaceWith(next);
    else if (module !== 'tags') box.appendChild(next);
    else box.insertBefore(next, box.firstChild);
    if (module === 'follow') rotateFollow(next, true);
  }
  // Who to follow: the card carries the whole pool; show a random three and
  // swap in others on a timer. No requests, just hidden flags.
  function shuffled(list) {
    const out = list.slice();
    for (let i = out.length - 1; i > 0; i -= 1) {
      const j = Math.floor(Math.random() * (i + 1));
      const t = out[i]; out[i] = out[j]; out[j] = t;
    }
    return out;
  }
  function rotateFollow(card, initial) {
    if (!card || !card.isConnected) return;
    if (!initial && (document.hidden || card.matches(':hover') || card.contains(document.activeElement)
      || card.querySelector('form[data-busy="1"]'))) return;
    const rows = Array.from(card.querySelectorAll('article.wtf-row'));
    if (rows.length <= FOLLOW_SHOW && !initial) return;
    const shown = rows.filter((row) => !row.hidden);
    const rest = shuffled(rows.filter((row) => row.hidden));
    // Prefer people not on screen right now, then top up from the current set.
    const pick = initial ? shuffled(rows).slice(0, FOLLOW_SHOW)
      : rest.slice(0, FOLLOW_SHOW).concat(shuffled(shown)).slice(0, FOLLOW_SHOW);
    rows.forEach((row) => { row.hidden = pick.indexOf(row) === -1; });
  }
  function refillFollow() {
    const card = document.querySelector('#trends-sidebar [data-trend-module="follow"]');
    if (!card) return;
    const rows = Array.from(card.querySelectorAll('article.wtf-row'));
    if (rows.length === 0) { card.remove(); return; }
    const need = FOLLOW_SHOW - rows.filter((row) => !row.hidden).length;
    shuffled(rows.filter((row) => row.hidden)).slice(0, Math.max(0, need)).forEach((row) => { row.hidden = false; });
  }
  setInterval(() => {
    const card = document.querySelector('#trends-sidebar [data-trend-module="follow"]');
    if (card && visible(document.getElementById('trends-sidebar'))) rotateFollow(card, false);
  }, FOLLOW_ROTATE_MS);
  function dropStoredFollow() {
    // The stored pool still lists who was just followed; fetch a fresh one next time.
    const data = readStore();
    if (data && data.modules && data.modules.follow) {
      delete data.modules.follow;
      writeStore(data);
    }
  }
  document.addEventListener('vaak:wtf-followed', () => {
    refillFollow();
    dropStoredFollow();
  });
  // A follow (or dismiss) anywhere: Home suggestions, Who to follow, For You,
  // or a profile/search Follow button. Take that account out of every
  // suggestion list on the page and out of the stored Who to follow pool.
  document.addEventListener('vaak:account-followed', (ev) => {
    const actor = String((ev && ev.detail && ev.detail.actor) || '').replace(/\/+$/, '');
    let removedRow = false;
    if (actor) {
      document.querySelectorAll('form input[name="action"][value="suggestion_follow"]').forEach((input) => {
        const form = input.form;
        const idInput = form && form.querySelector('input[name="actor_id"]');
        if (!idInput || String(idInput.value || '').replace(/\/+$/, '') !== actor) return;
        if (form.dataset.busy === '1') return; // the card that was clicked fades itself
        const card = form.closest('article.home-suggestion, article.wtf-row, article.tweet');
        if (!card) return;
        const grid = card.parentElement;
        if (card.classList.contains('wtf-row')) removedRow = true;
        card.remove();
        if (grid && grid.classList.contains('home-suggestions-grid') && !grid.querySelector('article.home-suggestion')) {
          const section = grid.closest('section.home-suggestions');
          if (section) section.remove();
        }
      });
    }
    if (removedRow) refillFollow();
    dropStoredFollow();
  });
  function cardStable(box, module) {
    const card = box.querySelector('[data-trend-module="' + module + '"]');
    return !!(card && !card.querySelector('.trends-skeleton, [data-trends-retry]'));
  }
  function restoreCached(box) {
    if (!box || box.dataset.enabled !== '1') return false;
    let painted = false;
    MODULES.forEach((module) => {
      if (!moduleFresh(box, module)) return;
      if (!cardStable(box, module)) paintHtml(box, module, moduleRow(box, module).html);
      painted = true;
    });
    if (painted) {
      rewriteFrom(box);
      box.dataset.deferred = '0';
      box.setAttribute('aria-busy', 'false');
    }
    return MODULES.every((module) => moduleFresh(box, module));
  }
  function showLoading(box, module) {
    if (!box || mutationPending || !visible(box)) return;
    if (module) {
      const holder = document.createElement('div');
      holder.innerHTML = loadingHtml;
      const card = holder.querySelector('[data-trend-module="' + module + '"]');
      const existing = box.querySelector('[data-trend-module="' + module + '"]');
      if (card && existing) existing.replaceWith(card);
      else if (card) box.appendChild(card);
    } else if (!box.querySelector('.trends-skeleton')) {
      box.innerHTML = loadingHtml;
    }
    box.setAttribute('aria-busy', 'true');
  }
  function retryHtml(module) {
    return '<div class="side-card" data-trend-module="' + module + '" role="status">Trending could not load. <button type="button" data-trends-retry>Retry</button></div>';
  }
  function invalidate() {
    ++generation;
    scheduled = false;
    scheduleTicket = null;
    waiters.length = 0;
    inflight.clear();
    if (pendingAbort) pendingAbort.abort();
    pendingAbort = null;
    active = 0;
    clearStore();
    const box = document.getElementById('trends-sidebar');
    if (box) {
      box.innerHTML = '';
      box.dataset.deferred = '1';
      box.setAttribute('aria-busy', 'false');
    }
  }
  function syncPolicy(res) {
    const enabled = res.headers.get('X-VAAK-Trends-Enabled');
    const owner = res.headers.get('X-VAAK-Trends-Owner');
    const box = document.getElementById('trends-sidebar');
    if (!box || mutationPending || !/^[01]$/.test(enabled || '') || !/^\d+$/.test(owner || '')) return;
    if (box.dataset.enabled !== enabled || box.dataset.owner !== owner) {
      invalidate();
      box.dataset.enabled = enabled;
      box.dataset.owner = owner;
    }
    box.hidden = enabled !== '1';
    const scopeBar = document.getElementById('trends-scope');
    if (scopeBar) scopeBar.hidden = box.hidden;
    if (box.hidden) { box.innerHTML = ''; box.setAttribute('aria-busy', 'false'); }
  }
  window.novaSyncDeferredTrendsPolicy = syncPolicy;

  function settleBusy(box) {
    if (active === 0 && waiters.length === 0 && box && box.isConnected) box.setAttribute('aria-busy', 'false');
  }
  async function runModule(job) {
    const box = document.getElementById('trends-sidebar');
    const current = generation;
    if (!box || current !== generation) return false;
    if (!job.force && moduleFresh(box, job.module)) {
      paintHtml(box, job.module, moduleRow(box, job.module).html);
      rewriteFrom(box);
      box.dataset.deferred = '0';
      return true;
    }
    const hadCard = box.querySelector('[data-trend-module="' + job.module + '"]');
    const failed = hadCard && hadCard.querySelector('[data-trends-retry]');
    if (!hadCard || failed || (hadCard.querySelector && hadCard.querySelector('.trends-skeleton'))) showLoading(box, job.module);
    box.dataset.deferred = 'loading';
    box.setAttribute('aria-busy', 'true');
    const controller = typeof AbortController === 'function' ? new AbortController() : null;
    if (controller) pendingAbort = controller;
    const row = moduleRow(box, job.module);
    const headers = { Accept: 'text/html' };
    if (!job.force && row && row.etag) headers['If-None-Match'] = row.etag;
    try {
      const url = job.module === 'follow' ? '?ajax=who_to_follow'
        : '?ajax=trends&module=' + encodeURIComponent(job.module) + '&frag=5' + (job.force ? '&fresh=1' : '')
          + (scope() === 'network' ? '&scope=network&from=' + encodeURIComponent(box.dataset.from || 'home') : '');
      const res = await fetch(url, {
        credentials: 'same-origin',
        headers: headers,
        cache: job.force ? 'reload' : 'default',
        ...(controller ? { signal: controller.signal } : {})
      });
      if (current !== generation || document.getElementById('trends-sidebar') !== box) return false;
      if (res.status === 304 && row && row.ok) {
        touch(box, job.module, row.html, row.etag);
        paintHtml(box, job.module, row.html);
        rewriteFrom(box);
        box.dataset.deferred = '0';
        return true;
      }
      if (!res.ok) throw new Error('Trending request failed');
      syncPolicy(res);
      if (current !== generation || document.getElementById('trends-sidebar') !== box || box.dataset.enabled !== '1') return false;
      const html = await res.text();
      if (current !== generation || document.getElementById('trends-sidebar') !== box) return false;
      const etag = res.headers.get('ETag') || '';
      touch(box, job.module, html, etag);
      paintHtml(box, job.module, html);
      rewriteFrom(box);
      box.dataset.cachedAge = res.headers.get('X-Vaak-Trends-Age') || '0';
      box.dataset.stale = res.headers.get('X-Vaak-Trends-Stale') === '1' ? '1' : '0';
      box.dataset.deferred = '0';
      return true;
    } catch (err) {
      if (current !== generation || document.getElementById('trends-sidebar') !== box) return false;
      if (err && err.name === 'AbortError') return false;
      if (moduleFresh(box, job.module)) return false;
      paintHtml(box, job.module, retryHtml(job.module));
      box.dataset.deferred = '1';
      return false;
    } finally {
      if (pendingAbort === controller) pendingAbort = null;
      settleBusy(box);
    }
  }
  function pump() {
    if (active >= 1) return;
    const job = waiters.shift();
    if (!job) return;
    const box = document.getElementById('trends-sidebar');
    if (mutationPending || window.__vaakNavigationPending || !visible(box) || generation !== job.generation) {
      if (inflight.get(job.module) === job.promise) inflight.delete(job.module);
      job.resolve(false);
      pump();
      return;
    }
    active += 1;
    runModule(job).then(job.resolve, job.reject).finally(() => {
      active = Math.max(0, active - 1);
      if (inflight.get(job.module) === job.promise) inflight.delete(job.module);
      settleBusy(document.getElementById('trends-sidebar'));
      pump();
    });
  }
  function enqueue(module, force) {
    if (inflight.has(module)) {
      if (force) {
        const queued = waiters.find((job) => job.module === module);
        if (queued) queued.force = true;
      }
      return inflight.get(module);
    }
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    inflight.set(module, promise);
    waiters.push({ module: module, force: force === true, generation: generation, promise: promise, resolve: resolve, reject: reject });
    pump();
    return promise;
  }
  function loadDeferredTrends(force) {
    const box = document.getElementById('trends-sidebar');
    if (!box || mutationPending || box.dataset.enabled !== '1') return;
    const allFresh = !force && MODULES.every((module) => moduleFresh(box, module));
    if (allFresh && MODULES.every((module) => cardStable(box, module))) return;
    if (!force) restoreCached(box);
    rewriteFrom(box);
    if (force) {
      MODULES.forEach((module) => {
        const card = box.querySelector('[data-trend-module="' + module + '"]');
        if (card && card.querySelector('[data-trends-retry]')) showLoading(box, module);
      });
    }
    if (!force && MODULES.every((module) => moduleFresh(box, module))) return;
    if (!visible(box)) return;
    if (scheduled && !force) return;
    scheduled = true;
    const ticket = {};
    scheduleTicket = ticket;
    const forceFlag = force === true;
    const idle = () => {
      if (scheduleTicket !== ticket) return;
      const run = () => {
        if (scheduleTicket !== ticket) return;
        scheduled = false;
        scheduleTicket = null;
        const live = document.getElementById('trends-sidebar');
        if (!live || mutationPending || window.__vaakNavigationPending || !visible(live)) return;
        const needed = forceFlag ? MODULES.slice() : MODULES.filter((module) => !moduleFresh(live, module));
        needed.forEach((module) => enqueue(module, forceFlag));
      };
      if ('requestIdleCallback' in window) requestIdleCallback(run, { timeout: 1500 });
      else setTimeout(run, 120);
    };
    const afterPaint = () => {
      if (window.requestAnimationFrame) requestAnimationFrame(() => requestAnimationFrame(idle));
      else setTimeout(idle, 0);
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', afterPaint, { once: true });
    else afterPaint();
  }
  function scheduleResume() {
    clearTimeout(resumeTimer);
    resumeTimer = setTimeout(() => {
      if (document.hidden || mutationPending) return;
      loadDeferredTrends(false);
    }, 1000);
  }

  window.novaInvalidateDeferredTrends = (loading) => {
    invalidate();
    if (loading === true) showLoading(document.getElementById('trends-sidebar'));
  };
  window.novaLoadDeferredTrends = loadDeferredTrends;
  // The notification stream sends `trends` when a network-wide list changed.
  window.novaRefreshDeferredTrends = () => {
    if (scope() !== 'network') return;
    const data = readStore();
    if (data && data.modules) {
      delete data.modules['tags:network'];
      writeStore(data);
    }
    loadDeferredTrends(false);
  };
  function paintScope() {
    const bar = document.getElementById('trends-scope');
    if (!bar) return;
    const current = scope();
    bar.querySelectorAll('[data-trends-scope]').forEach((btn) => {
      btn.setAttribute('aria-pressed', btn.getAttribute('data-trends-scope') === current ? 'true' : 'false');
    });
  }
  paintScope();
  const scopeBar = document.getElementById('trends-scope');
  if (scopeBar) {
    scopeBar.addEventListener('click', (event) => {
      const btn = event.target instanceof Element ? event.target.closest('[data-trends-scope]') : null;
      if (!btn) return;
      const next = btn.getAttribute('data-trends-scope') === 'network' ? 'network' : 'vaak';
      if (next === scope()) return;
      try { localStorage.setItem(SCOPE_STORE, next); } catch (e) {}
      paintScope();
      // Keep the other scope's cards cached; just repaint this one.
      ++generation;
      scheduled = false;
      scheduleTicket = null;
      waiters.length = 0;
      inflight.clear();
      if (pendingAbort) pendingAbort.abort();
      pendingAbort = null;
      active = 0;
      const box = document.getElementById('trends-sidebar');
      if (box) {
        if (moduleFresh(box, 'tags')) paintHtml(box, 'tags', moduleRow(box, 'tags').html);
        else showLoading(box, 'tags');
        rewriteFrom(box);
      }
      loadDeferredTrends(false);
    });
  }
  window.novaSetDeferredTrendsView = (view) => {
    const box = document.getElementById('trends-sidebar');
    if (!box) return;
    box.dataset.from = String(view || 'home');
    rewriteFrom(box);
    loadDeferredTrends(false);
  };
  window.addEventListener('pagehide', () => {
    suspended = true;
    if (pendingAbort) pendingAbort.abort();
  });
  window.addEventListener('pageshow', (event) => {
    suspended = false;
    if (event.persisted) mutationPending = false;
    loadDeferredTrends(false);
  });
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) scheduleResume();
  });
  window.addEventListener('focus', scheduleResume);
  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'post') return;
    const action = (form.querySelector('[name="action"]') || {}).value || '';
    if (/^(?:user_block_|block_|unblock$|mute_|unmute_|muted_word_|deprioritize_|undeprioritize_|downrank_|moderation_list_|list_mod_action$|user_ban$|user_unban$|switch_account|save_profile$)/.test(action)) {
      mutationPending = true;
      invalidate();
      queueMicrotask(() => {
        if (event.defaultPrevented) { mutationPending = false; loadDeferredTrends(true); }
      });
    }
  }, true);
  document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) return;
    if (event.target.closest('[data-trends-retry]')) loadDeferredTrends(true);
    const link = event.target.closest('a[href]');
    if (link && /[?&]logout=1(?:&|$)/.test(link.getAttribute('href') || '')) { mutationPending = true; invalidate(); }
  });
  window.addEventListener('resize', () => loadDeferredTrends(false), { passive: true });
  window.addEventListener('scroll', () => loadDeferredTrends(false), { passive: true });
  const trendsBox = document.getElementById('trends-sidebar');
  if (trendsBox && 'IntersectionObserver' in window) {
    const observer = new IntersectionObserver(() => loadDeferredTrends(false));
    observer.observe(trendsBox);
  }
  loadDeferredTrends(false);

  // Bookmarks are an intentionally selective hot set: warm only a few
  // already-visible preview variants during idle time. Ordinary timelines
  // remain browser-lazy, and Save-Data users are never pulled into a
  // background media request.
  const bookmarkedMediaSlot = document.getElementById('bookmarks-slot');
  if (bookmarkedMediaSlot && !navigator.connection?.saveData) {
    const warmBookmarkedMedia = () => {
      if (!bookmarkedMediaSlot.isConnected || document.getElementById('bookmarks-slot') !== bookmarkedMediaSlot) return;
      const urls = [...bookmarkedMediaSlot.querySelectorAll('[data-media-warm-url]')]
        .map((node) => node.getAttribute('data-media-warm-url') || '')
        .filter((url, index, all) => /^https:\/\//i.test(url) && all.indexOf(url) === index)
        .slice(0, 3);
      urls.forEach((url) => { const image = new Image(); image.decoding = 'async'; image.referrerPolicy = 'no-referrer'; image.src = url; });
    };
    if ('requestIdleCallback' in window) requestIdleCallback(warmBookmarkedMedia, { timeout: 2500 });
    else setTimeout(warmBookmarkedMedia, 1200);
  }
})();
