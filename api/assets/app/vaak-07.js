/* VAAK app script 7. Moved out of api/ap-admin.php (was an inline <script>) so
   browsers cache and precompile it; the shell loads these files in their old order.
   Request values are in VAAK_BOOT.s07, filled by an inline script right before it. */
(function () {
  /** Replace a timeline card in place without jumping scroll to the top. */
  function replaceCardInPlace(oldEl, html) {
    const wrap = document.createElement('div');
    wrap.innerHTML = (html || '').trim();
    const neu = wrap.firstElementChild;
    if (!neu) return null;
    const beforeTop = oldEl.getBoundingClientRect().top;
    oldEl.replaceWith(neu);
    const afterTop = neu.getBoundingClientRect().top;
    const delta = afterTop - beforeTop;
    if (Math.abs(delta) > 1) {
      window.scrollBy(0, delta);
    }
    if (typeof window.novaEnhanceTweetFolds === 'function') {
      window.novaEnhanceTweetFolds(neu);
    }
    return neu;
  }

  /** Profile end marker. More items keep the scroll prompt; only an explicit end says so. */
  function profileScrollStatus(more) {
    return more ? 'Scroll for more…' : 'End of profile';
  }
  window.vaakProfileScrollStatus = profileScrollStatus;

  /**
   * First paint of a finished profile: hold the end label until the reader
   * reaches the bottom. Off-screen cards paint late (content-visibility), so
   * an early "End of profile" sat under the first few posts.
   */
  let profileEndObserver = null;
  function profileBootStatus(status, sentinel, more, scrollRoot) {
    if (profileEndObserver) {
      try { profileEndObserver.disconnect(); } catch (e) {}
      profileEndObserver = null;
    }
    if (!status) return;
    if (more || !sentinel || typeof IntersectionObserver !== 'function') {
      status.textContent = profileScrollStatus(more);
      return;
    }
    status.textContent = '';
    const io = new IntersectionObserver((entries) => {
      if (!entries.some((en) => en.isIntersecting)) return;
      if (!status.textContent) status.textContent = profileScrollStatus(false);
      io.disconnect();
      if (profileEndObserver === io) profileEndObserver = null;
    }, { root: scrollRoot || null, threshold: 0 });
    profileEndObserver = io;
    io.observe(sentinel);
  }

  // Progressive boost / empty-Create hydration: signed remote fetch while you
  // scroll, swapping each card in place (never reloads / never jumps to top).
  const boostQueue = [];
  let boostActive = 0;
  const BOOST_CONCURRENCY = 2;

  function pumpBoostHydrate() {
    while (boostActive < BOOST_CONCURRENCY && boostQueue.length) {
      const card = boostQueue.shift();
      if (!card || !card.isConnected) continue;
      const isCreate = card.dataset.createHydrate === '1';
      const isBoost = card.dataset.boostHydrate === '1' || card.dataset.boostHydrateLocal === '1';
      if (!isCreate && !isBoost) continue;
      if (card.dataset.boostHydrating === '1') continue;
      card.dataset.boostHydrating = '1';
      boostActive++;
      const eventId = card.dataset.eventId || '';
      const objectId = card.dataset.objectId || '';
      const isLocal = card.dataset.boostHydrateLocal === '1';
      const from = card.dataset.returnView || 'home';
      const statusEl = card.querySelector(isCreate ? '.create-hydrate-status' : '.boost-hydrate-status');
      if (statusEl) statusEl.textContent = isCreate ? 'Loading post…' : 'Loading boosted post…';
      const url = '?view=' + encodeURIComponent(from)
        + '&partial=1'
        + (isCreate ? '&hydrate_create=1' : '&hydrate_boost=1')
        + (!isCreate && isLocal ? '&hydrate_local_boost=1' : '')
        + '&event_id=' + encodeURIComponent(eventId)
        + '&object_id=' + encodeURIComponent(objectId)
        + '&from=' + encodeURIComponent(from);
      fetch(url, {
        credentials: 'same-origin',
        headers: { 'Accept': 'text/html' },
        cache: 'no-store'
      }).then((res) => res.ok ? res.text() : Promise.reject(new Error('HTTP ' + res.status)))
        .then((html) => {
          const neu = replaceCardInPlace(card, html);
          // If the server still cannot resolve the target, do not leave a
          // misleading perpetual loading message. The card remains usable as
          // a thin stub and reports the terminal state clearly.
          if (neu && neu.dataset.boostGone === '1') {
            // Deleted / permanently unavailable boost target — drop from Home
            // the same way empty Create shells are removed after a failed fill.
            try { neu.remove(); } catch (e) {}
            return;
          }
          if (neu && (neu.dataset.boostHydrate === '1' || neu.dataset.boostHydrateLocal === '1')) {
            const st = neu.querySelector('.boost-hydrate-status');
            if (st) {
              st.textContent = 'Boost details unavailable';
            }
            neu.dataset.boostHydrate = '0';
            neu.dataset.boostHydrateLocal = '0';
          }
          // Empty Create still unresolved — drop the stub from the timeline
          // instead of leaving “isn’t cached yet” chrome on Home.
          if (neu && neu.dataset.createHydrate === '1') {
            neu.remove();
            return;
          }
        })
        .catch(() => {
          if (isCreate) {
            // Failed create-hydrate: remove the empty shell from the feed.
            try { card.remove(); } catch (e) {}
            return;
          } else {
            const st = card.querySelector('.boost-hydrate-status');
            if (st) st.textContent = 'Couldn’t load boosted post';
            card.dataset.boostHydrate = '0';
          }
          card.dataset.boostHydrating = '0';
        })
        .finally(() => {
          boostActive--;
          pumpBoostHydrate();
        });
    }
  }

  function enqueueBoostHydrates(root) {
    const scope = root || document;
    scope.querySelectorAll(
      'article.tweet-boost[data-boost-hydrate="1"], article.tweet-boost[data-boost-hydrate-local="1"], article.tweet[data-create-hydrate="1"]'
    ).forEach((card) => {
      if (card.dataset.boostQueued === '1') return;
      card.dataset.boostQueued = '1';
      boostQueue.push(card);
    });
    pumpBoostHydrate();
  }

  window.novaEnqueueBoostHydrates = enqueueBoostHydrates;
  enqueueBoostHydrates(document.getElementById('timeline-items') || document);

  let root = document.querySelector('.feed');
  let items = document.getElementById('timeline-items');
  let sentinel = document.getElementById('timeline-sentinel');
  let status = document.getElementById('timeline-status');
  let topBtn = document.getElementById('feed-top-btn');
  let newBtn = document.getElementById('feed-new-btn');
  let feedRoot = document.querySelector('.feed');
  // Read the timeline identity before constructing the scroll API so later
  // helpers do not hit JavaScript's temporal dead zone.
  let viewName = items
    ? (items.dataset.view || 'home')
    : (new URLSearchParams(window.location.search).get('view') || 'home');
  let hasMore = items ? items.dataset.hasMore === '1' : false;
  function placeNewPostsBtn() {
    const liveFeed = document.querySelector('.feed');
    newBtn = document.getElementById('feed-new-btn');
    if (!newBtn || !liveFeed) return;
    // Keep the new-post row in normal feed flow; it must never cover the
    // sticky timeline tabs above it.
    const composeSlot = liveFeed.querySelector('#compose-inline-slot');
    if (composeSlot) liveFeed.insertBefore(newBtn, composeSlot.nextSibling);
    else if (newBtn.parentElement !== liveFeed) liveFeed.insertBefore(newBtn, liveFeed.firstChild);
  }
  placeNewPostsBtn();
  if (!root || !items || !sentinel) {
    // Search pages are intentionally not timeline-paginated, but they still
    // use the feed scroll container. Keep the same unobtrusive back-to-top
    // affordance without initializing the timeline observer.
    if (viewName === 'search' && root && topBtn) {
      const searchScrollTop = () => {
        const style = window.getComputedStyle(root);
        return (style.overflowY === 'auto' || style.overflowY === 'scroll')
          ? root.scrollTop
          : (window.scrollY || document.documentElement.scrollTop || 0);
      };
      const searchScrollToTop = () => {
        const style = window.getComputedStyle(root);
        if (style.overflowY === 'auto' || style.overflowY === 'scroll') root.scrollTo({ top: 0, behavior: 'smooth' });
        else window.scrollTo({ top: 0, behavior: 'smooth' });
      };
      const syncSearchTop = () => { topBtn.classList.toggle('show', searchScrollTop() > 280); };
      root.addEventListener('scroll', syncSearchTop, { passive: true });
      window.addEventListener('scroll', syncSearchTop, { passive: true });
      topBtn.addEventListener('click', searchScrollToTop);
      syncSearchTop();
    }
    // DMs, blog, status, and the rest of the app have no timeline yet.
    // Keep a boot function so Home can replace the center column later
    // without rebuilding the right rail.
    window.vaakBootTimeline = function (nextView) {
      if (!continueTimelineBoot()) return false;
      try { return window.vaakBootTimeline(nextView); } catch (e) { return false; }
    };
    return;
  }

  function continueTimelineBoot() {
  root = document.querySelector('.feed');
  items = document.getElementById('timeline-items');
  sentinel = document.getElementById('timeline-sentinel');
  status = document.getElementById('timeline-status');
  topBtn = document.getElementById('feed-top-btn');
  newBtn = document.getElementById('feed-new-btn');
  feedRoot = root;
  if (!root || !items || !sentinel) return false;
  viewName = items.dataset.view || viewName || 'home';
  hasMore = items.dataset.hasMore === '1';
  if (viewName === 'remote_profile' && status) {
    const rootStyle = window.getComputedStyle(root);
    profileBootStatus(status, sentinel, hasMore,
      (rootStyle.overflowY === 'auto' || rootStyle.overflowY === 'scroll') ? root : null);
  }

  // Desktop: .feed is the scroll container. Mobile: body/window scrolls and
  // .feed is overflow:visible — IntersectionObserver must use the viewport.
  function feedIsScrollContainer() {
    const style = window.getComputedStyle(root);
    const oy = style.overflowY;
    if (oy !== 'auto' && oy !== 'scroll') return false;
    return root.scrollHeight > root.clientHeight + 2;
  }
  function scrollApi() {
    if (feedIsScrollContainer()) {
      return {
        mode: 'feed',
        ioRoot: root,
        top: () => root.scrollTop,
        height: () => root.scrollHeight,
        setTop: (v, smooth) => root.scrollTo({ top: v, behavior: smooth ? 'smooth' : 'auto' }),
        onScroll: (fn) => root.addEventListener('scroll', fn, { passive: true }),
      };
    }
    return {
      mode: 'window',
      ioRoot: null,
      top: () => window.scrollY || document.documentElement.scrollTop || 0,
      height: () => document.documentElement.scrollHeight,
      setTop: (v, smooth) => window.scrollTo({ top: v, behavior: smooth ? 'smooth' : 'auto' }),
      onScroll: (fn) => window.addEventListener('scroll', fn, { passive: true }),
    };
  }
  let offset = parseInt(items.dataset.offset || '0', 10);
  let limit = parseInt(items.dataset.limit || '15', 10);
  let tlBefore = (items && items.dataset && items.dataset.before) ? String(items.dataset.before) : '';
  let notifMaxId = items.dataset.maxId || '';
  let notifFilter = items.dataset.filter || 'all';
  let bskyCursor = items.dataset.cursor || '';
  let bskyFeed = items.dataset.feed || 'following';
  let bskySource = items.dataset.source || 'home';
  let loading = false;
  let newestTs = parseInt(items.dataset.newest || '0', 10) || Math.floor(Date.now() / 1000);
  let pendingHtml = '';
  let pendingCount = 0;
  let pendingSourceCount = 0;
  let pollBusy = false;
  let timelineGeneration = 0;
  let tabSwapBusy = false;
  // Long-lived SSE requests consume PHP-FPM workers even when idle. Keep the
  // bounded polling fallback as the default; operators can opt into SSE only
  // after sizing the FPM pool with VAAK_TIMELINE_SSE=1.
  const TIMELINE_SSE_ENABLED = VAAK_BOOT.s07[0];
  // ↑ button: ignore infinite-scroll fights until the user leaves the top (or pin expires).
  let stickToTop = false;
  let stickToTopTimer = 0;
  // Soft-nav used to clear this interval and never restart it (Mentions→Home
  // left the tab silent until a hard refresh). Keep a modest cadence.
  const POLL_MS = 45000;
  const AT_TOP_PX = 120;
  const TL_TITLES = { home: 'Home', local: 'Local', feed: 'Federation feed' };
  // Must track soft-nav view changes — freezing these as const from the initial
  // page load made Mentions→Home keep fetching notifications and paint
  // "End of notifications" on the Home shell.
  let isNotifTimeline = viewName === 'mentions';
  let isBskyTimeline = viewName === 'bluesky';
  let isOutboxTimeline = viewName === 'outbox';
  function syncTimelineKindFlags() {
    const liveView = (items && items.dataset && items.dataset.view)
      ? String(items.dataset.view)
      : String(viewName || '');
    isNotifTimeline = liveView === 'mentions';
    isBskyTimeline = liveView === 'bluesky';
    isOutboxTimeline = liveView === 'outbox';
  }

  let sc = scrollApi();

  // Pull-to-refresh indicator (mobile / window scroll)
  let ptrEl = root.querySelector('.feed-ptr');
  if (!ptrEl) {
    ptrEl = document.createElement('div');
    ptrEl.className = 'feed-ptr';
    ptrEl.setAttribute('aria-hidden', 'true');
    ptrEl.textContent = 'Pull to refresh';
    root.insertBefore(ptrEl, root.firstChild);
  }

  function timelineCardKey(el) {
    if (!el || !el.getAttribute) return '';
    const timelineKey = el.getAttribute('data-timeline-key') || '';
    if (timelineKey) return 'timeline:' + timelineKey;
    const tip = el.querySelector('.tweet-thread-seg--tip') || el;
    const rid = tip.getAttribute('data-rss-item') || '';
    if (rid) return 'rss:' + rid;
    const bskyUri = tip.getAttribute('data-bsky-uri') || '';
    if (bskyUri) return 'bsky:' + bskyUri;
    const noteId = tip.getAttribute('data-note-id') || '';
    if (noteId) return noteId.replace(/\/$/, '');
    const link = tip.querySelector('a[href*="object="]');
    if (link) {
      const m = (link.getAttribute('href') || '').match(/[?&]object=([^&]+)/);
      if (m) {
        try { return decodeURIComponent(m[1]).replace(/\/$/, ''); } catch (e) {}
      }
    }
    return 'node:' + (tip.textContent || '').slice(0, 64);
  }

  function timelineCardIsBoost(el) {
    if (!el || !el.getAttribute) return false;
    const timelineKey = el.getAttribute('data-timeline-key') || '';
    if (timelineKey.indexOf('boost:') === 0) return true;
    return !!(el.classList && el.classList.contains('tweet-boost'));
  }

  // Every id a card is allowed to claim. PHP and Rust disagree about which
  // one is primary (local/Mastodon id, ActivityPub URI, announce id), so the
  // counter treats any shared alias as the same post. A boost does not claim
  // the original post's id.
  function explicitCardAliases(el) {
    const out = [];
    const add = (raw) => {
      const v = String(raw || '').trim().replace(/\/+$/, '');
      if (!v || out.indexOf(v) !== -1) return;
      out.push(v);
    };
    if (!el || !el.getAttribute) return out;
    const tip = el.querySelector('.tweet-thread-seg--tip') || el;
    const nodes = tip === el ? [el] : [el, tip];
    nodes.forEach((node) => {
      if (!node || !node.getAttribute) return;
      const timelineKey = node.getAttribute('data-timeline-key') || '';
      const boost = timelineCardIsBoost(node) || timelineCardIsBoost(el);
      if (timelineKey) add(timelineKey);
      if (boost) {
        const announce = node.getAttribute('data-announce-id') || '';
        if (announce) add('announce:' + String(announce).trim().replace(/\/+$/, ''));
        return;
      }
      add(node.getAttribute('data-note-id'));
      add(node.getAttribute('data-status-id'));
      const bsky = node.getAttribute('data-bsky-uri') || '';
      if (bsky) {
        add(bsky);
        add(bsky.indexOf('bsky:') === 0 ? bsky.slice(5) : ('bsky:' + bsky));
      }
      if (timelineKey.indexOf('bsky:') === 0) add(timelineKey.slice(5));
      // Wafrn-style Bluesky twin of a fediverse post: whichever copy lands
      // first claims both ids, so a later page or live poll drops the other.
      add(node.getAttribute('data-fedi-twin'));
      const rss = node.getAttribute('data-rss-item') || '';
      if (rss) add('rss:' + rss);
    });
    return out;
  }

  function rememberTimelineCard(map, el) {
    if (!el || !el.getAttribute) return;
    const key = timelineCardKey(el);
    if (key) map[key] = true;
    explicitCardAliases(el).forEach((id) => { map[id] = true; });
    const boost = timelineCardIsBoost(el);
    const rid = el.getAttribute('data-rss-item') || '';
    if (rid) map['rss:' + rid] = true;
    const bsky = el.getAttribute('data-bsky-uri') || '';
    if (bsky && !boost) map['bsky:' + bsky] = true;
    const note = el.getAttribute('data-note-id') || '';
    if (note && !boost) map[note.replace(/\/$/, '')] = true;
    // A boost's permalink is the original post. Counting that link made the
    // original look already-shown, and skipping it is what let a repainted
    // copy of a visible post through when its id spelling differed.
    if (boost) return;
    el.querySelectorAll('a[href*="object="], a[href*="/vaak/@"]').forEach((link) => {
      const raw = link.getAttribute('href') || '';
      const m = raw.match(/[?&]object=([^&]+)/);
      if (m) { try { map[decodeURIComponent(m[1]).replace(/\/$/, '')] = true; } catch (e) {} return; }
      try {
        const u = new URL(raw, window.location.href);
        const short = window.vaakShortStatusTarget && window.vaakShortStatusTarget(u);
        if (short && short.object) map[short.object.replace(/\/$/, '')] = true;
      } catch (e2) {}
    });
  }

  function cardIsKnown(map, el) {
    const key = timelineCardKey(el);
    if (key && map[key]) return true;
    const aliases = explicitCardAliases(el);
    for (let i = 0; i < aliases.length; i++) {
      if (map[aliases[i]]) return true;
    }
    return false;
  }

  function seenKeys() {
    const keys = {};
    const add = (scope) => {
      scope.querySelectorAll('article.tweet, .tweet-thread-seg').forEach((el) => {
        rememberTimelineCard(keys, el);
      });
    };
    add(items);
    // Poll overlap also includes cards waiting behind the New posts button.
    const pending = document.createElement('div');
    pending.innerHTML = pendingHtml;
    add(pending);
    return keys;
  }

  function isPollTimelineCard(el) {
    if (!el || !el.tagName || el.tagName !== 'ARTICLE') return false;
    if (el.classList && (el.classList.contains('tweet') || el.classList.contains('tweet-thread'))) return true;
    return !!(el.getAttribute('data-timeline-key') || el.getAttribute('data-note-id')
      || el.getAttribute('data-sort') || el.getAttribute('data-rss-item') || el.getAttribute('data-bsky-uri'));
  }
  // Newer HTML is a list of cards. A wrapper (soft-nav embed, thread shell)
  // is unwrapped once. Cards inside an article stay inside it so a quote is
  // not counted as its own post.
  function collectPollCards(root) {
    const cards = [];
    const walk = (node) => {
      Array.from(node.children || []).forEach((el) => {
        if (!el || !el.tagName) return;
        if (el.tagName === 'ARTICLE') {
          if (isPollTimelineCard(el)) cards.push(el);
          return;
        }
        walk(el);
      });
    };
    walk(root);
    return cards;
  }
  function filterNewHtml(html, opts) {
    const wrap = document.createElement('div');
    wrap.innerHTML = (html || '').trim();
    const cards = collectPollCards(wrap);
    if (opts && opts.dropKnownAncestors) {
      // An older page can carry a self-reply whose parent is already on
      // screen (ranked order). Keep the new reply, drop its repainted parent.
      const onScreen = seenKeys();
      cards.forEach((el) => {
        if (!el.classList.contains('tweet-thread')) return;
        el.querySelectorAll('.tweet-thread-seg--ancestor').forEach((ancestor) => {
          if (!cardIsKnown(onScreen, ancestor)) return;
          const rail = ancestor.nextElementSibling;
          if (rail && rail.classList.contains('tweet-thread-rail')) rail.remove();
          ancestor.remove();
        });
      });
    }
    let sortCeiling = 0;
    let everySorted = cards.length > 0;
    cards.forEach((el) => {
      const sort = parseInt(el.getAttribute('data-sort') || '0', 10) || 0;
      if (sort <= 0) everySorted = false;
      else if (sort > sortCeiling) sortCeiling = sort;
    });
    const known = seenKeys();
    // Raw selected entries and painted thread wrappers are different counts.
    // Count new source entries before folding their shared parent.
    const sourceKnown = Object.assign({}, known);
    let sourceCount = 0;
    cards.forEach((el) => {
      if (cardIsKnown(sourceKnown, el)) return;
      rememberTimelineCard(sourceKnown, el);
      sourceCount++;
    });
    const out = document.createElement('div');
    cards.forEach((el) => {
      if (cardIsKnown(known, el)) return;
      rememberTimelineCard(known, el);
      out.appendChild(el);
    });
    out.querySelectorAll('article.tweet-thread .tweet-thread-seg--ancestor').forEach((ancestor) => {
      const ancestorIds = {};
      rememberTimelineCard(ancestorIds, ancestor);
      Array.from(out.children).forEach((candidate) => {
        if (candidate.classList.contains('tweet-thread')) return;
        if (cardIsKnown(ancestorIds, candidate)) candidate.remove();
      });
    });
    return {
      html: out.innerHTML,
      count: out.children.length,
      sourceCount,
      parsed: cards.length,
      sortCeiling: everySorted ? sortCeiling : 0,
    };
  }

  function reconcileThreadAncestors(scope) {
    // A freshly returned thread owns its ancestor. Remove the already painted
    // standalone parent only when the new thread is actually inserted.
    scope.querySelectorAll('article.tweet-thread').forEach((thread) => {
      thread.querySelectorAll('.tweet-thread-seg--ancestor').forEach((ancestor) => {
        const ancestorIds = {};
        rememberTimelineCard(ancestorIds, ancestor);
        Array.from(items.children).forEach((existing) => {
          if (cardIsKnown(ancestorIds, existing)) existing.remove();
        });
      });
    });
  }

  function nearTop() {
    return sc.top() < AT_TOP_PX;
  }

  function syncPendingPostTitle() {
    window.__vaakPendingPostCount = pendingCount > 0 ? pendingCount : 0;
    if (typeof window.vaakRenderInboxTitle === 'function') {
      try { window.vaakRenderInboxTitle(); } catch (e) {}
    }
  }
  function updateNewBtn() {
    let liveBtn = document.getElementById('feed-new-btn');
    if ((!liveBtn || !liveBtn.isConnected) && pendingCount > 0) ensureNewPostsBtn();
    liveBtn = document.getElementById('feed-new-btn');
    if (liveBtn) newBtn = liveBtn;
    if (!newBtn) {
      syncPendingPostTitle();
      return;
    }
    if (pendingCount <= 0) {
      newBtn.hidden = true;
      newBtn.classList.remove('show', 'is-pinned');
      newBtn.style.top = '';
      newBtn.style.left = '';
      newBtn.style.transform = '';
      newBtn.textContent = 'New posts';
      syncPendingPostTitle();
      return;
    }
    newBtn.hidden = false;
    newBtn.classList.add('show');
    newBtn.textContent = pendingCount === 1 ? '1 new post' : (pendingCount + ' new posts');
    const pin = !nearTop();
    newBtn.classList.toggle('is-pinned', pin);
    if (!pin) {
      newBtn.style.top = '';
      newBtn.style.left = '';
      newBtn.style.transform = '';
      syncPendingPostTitle();
      return;
    }
    const feed = document.querySelector('.feed') || root;
    const bar = document.querySelector('.main > .topbar');
    const feedBox = feed ? feed.getBoundingClientRect() : { top: 0, left: 0, width: window.innerWidth };
    const barBottom = bar ? bar.getBoundingClientRect().bottom : feedBox.top;
    const top = Math.max(barBottom, feedBox.top) + 8;
    const center = feedBox.left + (feedBox.width / 2);
    newBtn.style.top = Math.round(top) + 'px';
    newBtn.style.left = Math.round(center) + 'px';
    newBtn.style.transform = 'translateX(-50%)';
    syncPendingPostTitle();
  }

  function insertPending(opts) {
    const scrollToTop = !!(opts && opts.scrollToTop);
    if (!pendingHtml || pendingCount <= 0) {
      if (scrollToTop) sc.setTop(0, true);
      return;
    }
    const html = pendingHtml;
    const count = pendingSourceCount || pendingCount;
    pendingHtml = '';
    pendingCount = 0;
    pendingSourceCount = 0;
    updateNewBtn();
    const prevHeight = sc.height();
    const prevTop = sc.top();
    const incoming = document.createElement('div');
    incoming.innerHTML = html;
    reconcileThreadAncestors(incoming);
    items.insertAdjacentHTML('afterbegin', html);
    // The inserted card is now the head. Move the poll cursor to its sort so
    // the next check does not count the post the reader already sees.
    incoming.querySelectorAll('article.tweet, article.tweet-thread').forEach((el) => {
      const sort = parseInt(el.getAttribute('data-sort') || '0', 10) || 0;
      if (sort > newestTs) {
        newestTs = sort;
        items.dataset.newest = String(newestTs);
      }
    });
    offset += count;
    items.dataset.offset = String(offset);
    if (typeof window.novaEnhanceTweetFolds === 'function') {
      window.novaEnhanceTweetFolds(items);
    }
    if (typeof window.novaEnqueueBoostHydrates === 'function') {
      window.novaEnqueueBoostHydrates(items);
    }
    if (scrollToTop || nearTop()) {
      sc.setTop(0, !!scrollToTop);
    } else {
      const delta = sc.height() - prevHeight;
      sc.setTop(prevTop + delta, false);
    }
  }

  let pollPromise = null;
  let lastTimelinePollAt = null;
  let timelinePollSuspended = false;
  let activeTimelinePollAbort = null;
  let timelineCatchUpPending = false;
  let timelinePollInvalidated = false;
  let timelineHiddenAt = document.hidden ? Date.now() : 0;
  let timelineSawHidden = !!document.hidden;
  let timelineCatchUpTimer = 0;
  let timelineCatchUpForce = false;
  let timelinePollStartedHidden = false;
  let timelinePollStartedAt = 0;
  let timelinePollCompleted = false;
  const BACKGROUND_POLL_MS = 90000;
  // Focus and visibility often arrive together. A check that just finished
  // does not need a second request; a tab that was actually away does.
  const CATCHUP_MIN_MS = 2500;
  // Spread tabs that wake together so they do not hit newer=1 on the same tick.
  const CATCHUP_JITTER_MS = 1600;
  // A fetch the browser suspended with the frozen tab is not a fresh answer.
  const STALE_HIDDEN_POLL_MS = 15000;
  function syncLiveTimelineView() {
    // Soft-nav can leave this closure's viewName behind the live timeline
    // node. Local was then requested as Home, so that tab never polled.
    const live = (items && items.dataset && items.dataset.view) ? String(items.dataset.view) : '';
    if (['home', 'local', 'feed', 'mentions', 'bluesky', 'outbox'].includes(live) && live !== viewName) {
      viewName = live;
      syncTimelineKindFlags();
    }
  }
  function timelineHeadPollView() {
    // Head polls exist for Home, Local, and Federated only. Bluesky is cursor
    // pages against the PDS, and list timelines are a one-shot render. Neither
    // has this poller; do not add a heavier query for them.
    syncLiveTimelineView();
    syncTimelineKindFlags();
    return ['home', 'local', 'feed'].includes(viewName)
      && !isNotifTimeline && !isOutboxTimeline && !isBskyTimeline;
  }
  function commitNewerCursor(filtered, headerTs) {
    // Trust per-card data-sort when every returned card has one. An X-Newest
    // ahead of those cards is a post the browser did not receive.
    let next = newestTs;
    if (filtered && filtered.sortCeiling > 0) next = Math.max(next, filtered.sortCeiling);
    else if (headerTs > next) next = headerTs;
    if (next > newestTs) {
      newestTs = next;
      if (items) items.dataset.newest = String(newestTs);
    }
  }
  function pollTimelineAutomatically(options) {
    if (timelinePollSuspended || window.__vaakNavigationPending || tabSwapBusy
        || !items || !items.isConnected || !timelineHeadPollView()) return;
    const catchUp = !!(options && options.catchUp);
    if (pollPromise || pollBusy) {
      // Any return/reconnect check must survive a poll that is already in
      // flight. The old gate required pagehide's invalidated flag, so a
      // request frozen with the tab swallowed the check on the way back.
      if (catchUp) timelineCatchUpPending = true;
      return;
    }
    const elapsed = lastTimelinePollAt === null ? Infinity : (Date.now() - lastTimelinePollAt);
    const cadence = document.hidden ? BACKGROUND_POLL_MS : POLL_MS;
    if (catchUp) {
      // A check that already succeeded this moment has the posts. Failed or
      // aborted checks (completed stays false) still run, including on return.
      if (timelinePollCompleted && elapsed + 50 < CATCHUP_MIN_MS) return;
    } else if (elapsed + 50 < cadence) {
      return;
    }
    return pollNewer();
  }
  function scheduleTimelineCatchUp(force) {
    if (!timelineHeadPollView()) return;
    if (timelineCatchUpTimer) {
      if (force) timelineCatchUpForce = true;
      return;
    }
    const wantForce = !!force || timelineCatchUpForce;
    timelineCatchUpForce = false;
    const jitter = 200 + Math.floor(Math.random() * CATCHUP_JITTER_MS);
    timelineCatchUpTimer = window.setTimeout(() => {
      timelineCatchUpTimer = 0;
      const forced = wantForce || timelineCatchUpForce;
      timelineCatchUpForce = false;
      if (document.hidden || timelinePollSuspended || window.__vaakNavigationPending || tabSwapBusy) return;
      const inFlight = !!(pollPromise || pollBusy);
      const age = inFlight && timelinePollStartedAt > 0 ? (Date.now() - timelinePollStartedAt) : 0;
      if (inFlight && age >= STALE_HIDDEN_POLL_MS && activeTimelinePollAbort) {
        timelineCatchUpPending = true;
        timelinePollInvalidated = true;
        lastTimelinePollAt = null;
        try { activeTimelinePollAbort.abort(); } catch (e) {}
        return;
      }
      pollTimelineAutomatically({ catchUp: true, afterHidden: forced });
    }, jitter);
  }
  async function pollNewer(options) {
    const force = !!(options && options.freshPost);
    while (pollPromise) {
      await pollPromise;
      if (!force) return;
    }
    pollPromise = performPollNewer(options);
    try { await pollPromise; } finally {
      pollPromise = null;
      if (timelineCatchUpPending) {
        const retry = !timelinePollCompleted || timelinePollInvalidated;
        const again = timelinePollInvalidated || timelinePollStartedHidden;
        timelineCatchUpPending = false;
        if (retry && !timelinePollSuspended && !window.__vaakNavigationPending && !tabSwapBusy) {
          pollTimelineAutomatically({ catchUp: true, afterHidden: again });
        }
      }
    }
  }

  async function performPollNewer(options) {
    const force = !!(options && options.freshPost);
    // Every standard timeline may poll, but the server scopes the query to
    // the active view (Home, Local, or Federated).
    syncLiveTimelineView();
    if (!['home', 'local', 'feed'].includes(viewName)) return;
    if (isNotifTimeline || isOutboxTimeline) return; // list views do not poll head rows.
    if (isBskyTimeline) return; // Bluesky uses cursor pages, not since= head polls.
    if (pollBusy || timelinePollSuspended || window.__vaakNavigationPending || tabSwapBusy) return;
    if (!items || !items.isConnected) return;
    const requestItems = items;
    const requestView = viewName;
    const requestGeneration = timelineGeneration;
    const publishedTs = options && options.published ? Math.floor(Date.parse(options.published) / 1000) : 0;
    const since = force && publishedTs > 0 ? Math.min(newestTs, publishedTs - 1) : newestTs;
    lastTimelinePollAt = Date.now();
    timelinePollInvalidated = false;
    timelinePollStartedHidden = !!document.hidden;
    timelinePollStartedAt = lastTimelinePollAt;
    timelinePollCompleted = false;
    pollBusy = true;
    const ac = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    activeTimelinePollAbort = ac;
    const abortTimer = ac ? window.setTimeout(() => { try { ac.abort(); } catch (e) {} }, 20000) : 0;
    try {
      const url = '?view=' + encodeURIComponent(viewName)
        + '&partial=1&newer=1&since=' + encodeURIComponent(String(since))
        + (force ? '&fresh_post=1' : '')
        + '&limit=' + encodeURIComponent(String(Math.min(24, limit)));
      const res = await fetch(url, {
        credentials: 'same-origin',
        headers: { 'Accept': 'text/html' },
        cache: 'no-store',
        signal: ac ? ac.signal : undefined
      });
      if (res.status === 401 || res.headers.get('X-VAAK-Auth') === 'required') {
        if (typeof window.vaakRedirectToLogin === 'function') window.vaakRedirectToLogin('poll');
        return;
      }
      if (!res.ok) return;
      const liveViewNow = () => (items && items.dataset && items.dataset.view) ? String(items.dataset.view) : viewName;
      if (items !== requestItems || liveViewNow() !== requestView || timelineGeneration !== requestGeneration || !requestItems.isConnected) return;
      timelinePollCompleted = true;
      const newestHdr = parseInt(res.headers.get('X-Newest') || '0', 10) || 0;
      const raw = await res.text();
      if (items !== requestItems || liveViewNow() !== requestView || timelineGeneration !== requestGeneration || !requestItems.isConnected) return;
      if (typeof window.vaakLooksLikeLoginHtml === 'function' && window.vaakLooksLikeLoginHtml(raw)) {
        window.vaakRedirectToLogin('poll-html');
        return;
      }
      // Empty, or a body with no timeline card: the cursor stays. Advancing
      // it here is what skipped the post the counter never got to show.
      if (!raw || !raw.trim()) return;
      const filtered = filterNewHtml(raw);
      if (!filtered.parsed) return;
      commitNewerCursor(filtered, newestHdr);
      if (!filtered.count) return;
      pendingHtml = filtered.html + pendingHtml;
      pendingCount += filtered.count;
      pendingSourceCount += filtered.sourceCount;
      ensureNewPostsBtn();
      updateNewBtn();
    } catch (e) {
      // A frozen or aborted hidden poll must not count as a fresh check.
      if (timelinePollStartedHidden || (e && e.name === 'AbortError')) lastTimelinePollAt = null;
    } finally {
      if (abortTimer) window.clearTimeout(abortTimer);
      if (activeTimelinePollAbort === ac) activeTimelinePollAbort = null;
      pollBusy = false;
    }
  }

  function armStickToTop() {
    stickToTop = true;
    if (stickToTopTimer) window.clearTimeout(stickToTopTimer);
    // Keep the pin long enough to outlast an in-flight loadMore + smooth scroll.
    stickToTopTimer = window.setTimeout(() => {
      stickToTop = false;
      stickToTopTimer = 0;
      // IntersectionObserver only fires on threshold crossings. If the sentinel
      // stayed visible the whole time stickToTop was armed, resume paging.
      maybeLoadMoreIfNeeded();
    }, 2000);
  }

  function sentinelNeedsMore() {
    if (!hasMore || loading || stickToTop || !sentinel) return false;
    try {
      const rect = sentinel.getBoundingClientRect();
      const vh = window.innerHeight || document.documentElement.clientHeight || 0;
      // Match the IO rootMargin (~180px): trigger when the sentinel is near
      // or below the viewport bottom.
      return rect.top < (vh + 220);
    } catch (e) {
      return false;
    }
  }

  function maybeLoadMoreIfNeeded() {
    // A parked Home column is detached. Its sentinel rect is empty, which
    // would otherwise keep paging the timeline while Notifications is open.
    if (!items || !items.isConnected) return;
    if (sentinelNeedsMore()) loadMore();
  }

  async function loadMore() {
    syncTimelineKindFlags();
    if (!items || !items.isConnected) return;
    // Soft-nav Mentions→Home can leave this boot script thinking it is still
    // on notifications; refuse to page the wrong endpoint into Home.
    if (items && items.dataset && items.dataset.view
        && String(items.dataset.view) !== String(viewName)
        && ['mentions', 'bluesky', 'outbox'].includes(String(items.dataset.view))) {
      return;
    }
    if (!hasMore || loading) return;
    // Don't keep paging while the user is explicitly going back to the top —
    // that was yanking the viewport back to the sentinel in a loop.
    if (stickToTop) return;
    loading = true;
    const skeleton = document.createElement('div');
    skeleton.className = 'timeline-skeleton';
    skeleton.setAttribute('aria-hidden', 'true');
    skeleton.innerHTML = '<div class="timeline-skeleton-row"></div><div class="timeline-skeleton-row"></div>';
    if (status && status.parentNode) status.parentNode.insertBefore(skeleton, status);
    if (status) {
      status.innerHTML = '<span class="timeline-status-loading"><span class="vaak-spinner" aria-hidden="true"></span><span>Loading…</span></span>';
    }
    try {
      // A page may render no HTML when every row was filtered or deduplicated.
      // Keep the pagination cursor independent from rendered card count (as
      // Mastodon does) and skip through a few such pages in one request.
      let attempts = 0;
      let inserted = false;
      while (hasMore && attempts < 4) {
        if (stickToTop) break;
        attempts++;
        let url;
        if (isNotifTimeline) {
          if (!notifMaxId) { hasMore = false; break; }
          url = '?view=mentions&partial=1'
            + '&notification_filter=' + encodeURIComponent(notifFilter || 'all')
            + '&notifications_max_id=' + encodeURIComponent(notifMaxId)
            + '&limit=' + encodeURIComponent(String(limit || 20));
        } else if (isBskyTimeline) {
          if (!bskyCursor && attempts > 1) { hasMore = false; break; }
          url = '?view=bluesky&partial=1'
            + '&feed=' + encodeURIComponent(bskyFeed || 'following')
            + '&source=' + encodeURIComponent(bskySource || 'home')
            + '&cursor=' + encodeURIComponent(bskyCursor || '')
            + '&limit=' + encodeURIComponent(String(limit || 30));
        } else {
          const requestOffset = offset;
          url = '?view=' + encodeURIComponent(viewName)
            + '&partial=1&offset=' + requestOffset + '&limit=' + limit;
          if (['home', 'local', 'feed'].includes(viewName) && tlBefore) {
            url += '&max_id=' + encodeURIComponent(tlBefore);
          }
          if (viewName === 'remote_profile') {
            url += '&actor=' + encodeURIComponent(items.dataset.actor || '')
              + '&tab=' + encodeURIComponent(items.dataset.tab || 'posts');
          }
        }
        const res = await fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'text/html' } });
        if (res.status === 401 || res.headers.get('X-VAAK-Auth') === 'required') {
          if (typeof window.vaakRedirectToLogin === 'function') window.vaakRedirectToLogin('loadMore');
          return;
        }
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const html = await res.text();
        if (typeof window.vaakLooksLikeLoginHtml === 'function' && window.vaakLooksLikeLoginHtml(html)) {
          window.vaakRedirectToLogin('loadMore-html');
          return;
        }
        const moreHeader = res.headers.get('X-Has-More');
        if (viewName === 'remote_profile') {
          // A missing header must not turn a profile that still has items into the end.
          if (moreHeader === '1' || moreHeader === '0') {
            hasMore = moreHeader === '1';
          }
        } else {
          hasMore = moreHeader === '1';
        }
        if (isNotifTimeline) {
          const nextMax = (res.headers.get('X-Next-Max-Id') || '').replace(/\D+/g, '');
          if (!nextMax || nextMax === notifMaxId) {
            hasMore = false;
          } else {
            notifMaxId = nextMax;
            items.dataset.maxId = notifMaxId;
          }
        } else if (isBskyTimeline) {
          const nextCur = res.headers.get('X-Next-Cursor') || '';
          const srcHdr = res.headers.get('X-BSKY-Source') || '';
          if (srcHdr) {
            bskySource = srcHdr;
            items.dataset.source = bskySource;
          }
          if (!nextCur || nextCur === bskyCursor) {
            hasMore = false;
            bskyCursor = '';
          } else {
            bskyCursor = nextCur;
          }
          items.dataset.cursor = bskyCursor;
        } else {
          const requestOffset = offset;
          const next = parseInt(res.headers.get('X-Next-Offset') || String(requestOffset), 10);
          // Guard against a broken cursor causing a tight request loop.
          offset = next > requestOffset ? next : requestOffset + limit;
          items.dataset.offset = String(offset);
          if (['home', 'local', 'feed'].includes(viewName)) {
            const nextCur = res.headers.get('X-Next-Cursor') || '';
            if (nextCur) {
              if (tlBefore && nextCur === tlBefore) {
                hasMore = false;
              } else {
                tlBefore = nextCur;
              }
            }
            items.dataset.before = tlBefore;
          }
        }
        if (html.trim()) {
          // Ranked windows can change between the initial paint and an
          // infinite-scroll request (especially when a cache is rebuilt).
          // Apply the same stable-key filter used by newer-post polling so a
          // boundary card is never appended twice.
          const filteredPage = filterNewHtml(html, { dropKnownAncestors: true });
          if (!filteredPage.count) {
            // The server returned only cards already on screen. Keep walking
            // the cursor/offset while more data exists rather than stopping
            // at the duplicate page.
            continue;
          }
          // Append-only: do NOT "restore" scroll after the await. That fought the
          // ↑ button — user scrolls up during loadMore, then we yanked them back
          // to the sentinel and IntersectionObserver looped.
          items.insertAdjacentHTML('beforeend', filteredPage.html);
          inserted = true;
          if (typeof window.novaEnhanceTweetFolds === 'function') {
            window.novaEnhanceTweetFolds(items);
          }
          if (typeof window.novaEnqueueBoostHydrates === 'function') {
            window.novaEnqueueBoostHydrates(items);
          }
          break;
        }
      }
      if (!inserted && hasMore) {
        if (status) status.textContent = 'Scroll for more…';
      }
      items.dataset.hasMore = hasMore ? '1' : '0';
      if (status) {
        if (viewName === 'remote_profile') status.textContent = profileScrollStatus(hasMore);
        else if (hasMore) status.textContent = 'Scroll for more…';
        else if (isNotifTimeline) status.textContent = 'End of notifications';
        else if (isBskyTimeline) status.textContent = 'End of Bluesky feed';
        else if (isOutboxTimeline) status.textContent = 'End of Your Posts';
        else status.textContent = 'End of timeline';
      }
    } catch (e) {
      if (status) status.textContent = 'Could not load more — try Refresh';
      hasMore = false;
    } finally {
      if (skeleton && skeleton.parentNode) skeleton.remove();
      loading = false;
      // The page we were filling may have been parked. Do not scroll the
      // window the reader is looking at now, and do not chain another fetch.
      if (!items || !items.isConnected) return;
      if (stickToTop) {
        sc.setTop(0, false);
      } else {
        // Short first pages / soft-nav: sentinel can remain intersecting after a
        // finished page with no new IO event — keep filling until content grows.
        window.requestAnimationFrame(() => maybeLoadMoreIfNeeded());
      }
    }
  }

  let io = null;
  function bindInfiniteScrollObserver() {
    if (io) {
      try { io.disconnect(); } catch (e) {}
      io = null;
    }
    if (!sentinel) return;
    sc = scrollApi();
    io = new IntersectionObserver((entries) => {
      if (stickToTop) return;
      if (entries.some((en) => en.isIntersecting)) loadMore();
    }, { root: sc.ioRoot, rootMargin: '180px', threshold: 0 });
    io.observe(sentinel);
    // Soft-nav / short feeds: sentinel may already be on screen with no crossing.
    window.requestAnimationFrame(() => maybeLoadMoreIfNeeded());
  }
  bindInfiniteScrollObserver();

  // Full-page account switch / cold shell: needs-fill must run even when
  // soft-nav boot did not (hard navigation after switch_account).
    if (items && items.dataset.needsFill === '1') {
    items.dataset.needsFill = '0';
    offset = 0;
    items.dataset.offset = '0';
    tlBefore = '';
    items.dataset.before = '';
    hasMore = true;
    items.dataset.hasMore = '1';
    setTimeout(function () { try { loadMore(); } catch (e) {} }, 0);
  }

  // Re-bind observer if layout flips (rotate / resize across the mobile breakpoint).
  window.addEventListener('resize', () => {
    const next = scrollApi();
    if (next.mode === sc.mode) {
      maybeLoadMoreIfNeeded();
      return;
    }
    sc = next;
    bindInfiniteScrollObserver();
    sc.onScroll(updateTopBtn);
    sc.onScroll(syncTimelineStreamVisibility);
    updateTopBtn();
  }, { passive: true, capture: true });

  function updateTopBtn() {
    const y = sc.top();
    if (topBtn) {
      if (y > 280) topBtn.classList.add('show');
      else topBtn.classList.remove('show');
    }
    // User scrolled away from the top on purpose — drop the pin.
    if (stickToTop && y > NEAR_TOP_PX + 80) {
      stickToTop = false;
      if (stickToTopTimer) {
        window.clearTimeout(stickToTopTimer);
        stickToTopTimer = 0;
      }
    }
    updateNewBtn();
  }
  sc.onScroll(updateTopBtn);
  updateTopBtn();
  function bindTopBtn() {
    topBtn = document.getElementById('feed-top-btn');
    if (!topBtn || topBtn.dataset.vaakBound === '1') return;
    topBtn.dataset.vaakBound = '1';
    topBtn.addEventListener('click', () => {
      armStickToTop();
      insertPending({ scrollToTop: true });
      // Instant first so an in-flight loadMore can't win the race against smooth.
      sc.setTop(0, false);
      window.requestAnimationFrame(() => {
        if (stickToTop) sc.setTop(0, false);
      });
    });
  }
  function bindNewPostsBtn() {
    placeNewPostsBtn();
    if (!newBtn || newBtn.dataset.vaakBound === '1') return;
    newBtn.dataset.vaakBound = '1';
    newBtn.addEventListener('click', () => {
      armStickToTop();
      insertPending({ scrollToTop: true });
    });
  }
  function ensureNewPostsBtn() {
    if (!timelineHeadPollView()) return;
    let live = document.getElementById('feed-new-btn');
    if (!live || !live.isConnected) {
      live = document.createElement('button');
      live.type = 'button';
      live.id = 'feed-new-btn';
      live.className = 'feed-new-btn';
      if (live.classList && live.classList.add) live.classList.add('feed-new-btn');
      live.hidden = true;
      live.textContent = 'New posts';
      const liveFeed = document.querySelector('.feed');
      if (liveFeed) liveFeed.appendChild(live);
      else if (document.body) document.body.appendChild(live);
    }
    newBtn = live;
    placeNewPostsBtn();
    bindNewPostsBtn();
  }
  bindTopBtn();
  ensureNewPostsBtn();

  // Bluesky: first paint is Following-only; saved-feed samples load after paint
  // (mirrors Home deferred boost hydrate — keep TTFB / first paint snappy).
  async function loadBskyMergeSamples() {
    if (!isBskyTimeline) return;
    if (items.dataset.mergePending !== '1') return;
    if ((bskyFeed || 'following') !== 'following' && bskyFeed !== 'timeline') return;
    items.dataset.mergePending = '0';
    try {
      const url = '?view=bluesky&partial=1&merge=1'
        + '&feed=' + encodeURIComponent(bskyFeed || 'following')
        + '&limit=' + encodeURIComponent(String(Math.min(20, limit || 15)));
      const res = await fetch(url, {
        credentials: 'same-origin',
        headers: { 'Accept': 'text/html' },
        cache: 'no-store'
      });
      if (!res.ok) return;
      const html = await res.text();
      if (!html || !html.trim()) return;
      // Only swap the head if the reader is still near the top — avoid scroll jump.
      if (!nearTop()) return;
      const prevCursor = bskyCursor;
      items.innerHTML = html;
      if (typeof window.novaSyncDeferredTrendsPolicy === 'function') window.novaSyncDeferredTrendsPolicy(res);
      const nextCur = res.headers.get('X-Next-Cursor') || prevCursor || '';
      if (nextCur) {
        bskyCursor = nextCur;
        items.dataset.cursor = bskyCursor;
        hasMore = true;
        items.dataset.hasMore = '1';
        if (status) status.textContent = 'Scroll for more…';
      }
      if (typeof window.novaEnhanceTweetFolds === 'function') {
        window.novaEnhanceTweetFolds(items);
      }
    } catch (e) {
      // quiet — Following head already painted
    }
  }
  if (isBskyTimeline && items.dataset.mergePending === '1') {
    const scheduleMerge = () => {
      if (typeof window.requestIdleCallback === 'function') {
        window.requestIdleCallback(() => { loadBskyMergeSamples(); }, { timeout: 2500 });
      } else {
        window.setTimeout(() => { loadBskyMergeSamples(); }, 400);
      }
    };
    if (document.readyState === 'complete') scheduleMerge();
    else window.addEventListener('load', scheduleMerge, { once: true });
  }

  // Pull-to-refresh (mobile): rubber-band pull, settle animation, soft poll when possible.
  let ptrStartY = 0;
  let ptrDy = 0;
  let ptrPull = 0;
  let ptrTracking = false;
  let ptrDragging = false;
  let ptrArmed = false;
  let ptrBusy = false;
  let ptrWasArmed = false;
  const PTR_READY = 64;
  const PTR_MAX = 120;
  const PTR_HOLD = 52; // height while “Refreshing…”

  function ptrLabel(text, spinning) {
    if (!ptrEl) return;
    if (spinning) {
      ptrEl.innerHTML = '<span class="feed-ptr__spinner" aria-hidden="true"></span><span>' + text + '</span>';
    } else {
      ptrEl.textContent = text;
    }
  }

  function ptrRubber(dy) {
    // Ease toward PTR_MAX so the end of the pull feels soft, not clamped.
    const t = Math.max(0, dy);
    return PTR_MAX * (1 - Math.exp(-t / (PTR_MAX * 1.15)));
  }

  function ptrResetInstant() {
    ptrTracking = false;
    ptrDragging = false;
    ptrArmed = false;
    ptrWasArmed = false;
    ptrDy = 0;
    ptrPull = 0;
    if (!ptrEl || ptrBusy) return;
    ptrEl.classList.remove('show', 'ready', 'settling', 'refreshing');
    ptrEl.style.height = '0px';
    ptrLabel('Pull to refresh', false);
  }

  function ptrCollapse(then) {
    if (!ptrEl) {
      if (typeof then === 'function') then();
      return;
    }
    ptrEl.classList.remove('ready', 'refreshing');
    ptrEl.classList.add('settling', 'show');
    // Force style flush so the transition runs from current height → 0.
    void ptrEl.offsetHeight;
    ptrEl.style.height = '0px';
    window.setTimeout(() => {
      if (!ptrBusy) {
        ptrEl.classList.remove('show', 'settling');
        ptrLabel('Pull to refresh', false);
      }
      if (typeof then === 'function') then();
    }, 300);
  }

  function ptrCanStart(ev) {
    if (ptrBusy) return false;
    const target = ev && ev.target;
    if (target && target.closest && target.closest('textarea, input, select, button, a, .compose-modal')) return false;
    return nearTop() && !document.body.classList.contains('mobile-nav-open');
  }

  document.addEventListener('touchstart', (ev) => {
    if (!ev.touches || !ev.touches[0] || !ptrCanStart(ev)) {
      ptrTracking = false;
      return;
    }
    ptrTracking = true;
    ptrDragging = false;
    ptrArmed = false;
    ptrWasArmed = false;
    ptrStartY = ev.touches[0].clientY;
    ptrDy = 0;
    ptrPull = 0;
    if (ptrEl) ptrEl.classList.remove('settling', 'refreshing');
  }, { passive: true, capture: true });

  document.addEventListener('touchmove', (ev) => {
    if (!ptrTracking || ptrBusy || !ev.touches || !ev.touches[0]) return;
    if (sc.top() > 6) {
      if (ptrDragging) ptrCollapse();
      ptrTracking = false;
      ptrDragging = false;
      return;
    }
    ptrDy = ev.touches[0].clientY - ptrStartY;
    if (ptrDy < 10 && !ptrDragging) return;
    // Only claim the gesture once we're clearly pulling down at the top.
    if (!ptrDragging && ptrDy >= 10) ptrDragging = true;
    if (!ptrDragging) return;
    ev.preventDefault();
    ptrPull = ptrRubber(ptrDy);
    const nowArmed = ptrPull >= PTR_READY;
    if (nowArmed && !ptrWasArmed) {
      window.vaakHaptic && window.vaakHaptic(12);
    }
    ptrWasArmed = nowArmed;
    ptrArmed = nowArmed;
    if (ptrEl) {
      ptrEl.style.height = ptrPull.toFixed(1) + 'px';
      ptrEl.classList.add('show');
      ptrEl.classList.toggle('ready', ptrArmed);
      ptrLabel(ptrArmed ? 'Release to refresh' : 'Pull to refresh', false);
    }
  }, { passive: false, capture: true });

  document.addEventListener('touchend', () => {
    if (!ptrTracking) return;
    const doRefresh = ptrDragging && ptrArmed && !ptrBusy;
    ptrTracking = false;
    ptrDragging = false;
    ptrArmed = false;
    if (!doRefresh) {
      ptrCollapse();
      return;
    }
    ptrBusy = true;
    if (ptrEl) {
      ptrEl.classList.remove('ready', 'settling');
      ptrEl.classList.add('show', 'refreshing');
      ptrEl.style.height = PTR_HOLD + 'px';
      ptrLabel('Refreshing…', true);
    }
    window.vaakHaptic && window.vaakHaptic(18);
    // Soft refresh when the live poller is available; otherwise hard reload.
    const soft = typeof pollNewer === 'function';
    const finish = () => {
      ptrBusy = false;
      ptrCollapse();
    };
    if (soft) {
      Promise.resolve()
        .then(() => pollNewer())
        .then(() => {
          if (pendingCount > 0 && typeof insertPending === 'function') {
            insertPending({ scrollToTop: true });
          } else if (nearTop()) {
            sc.setTop(0, true);
          }
        })
        .catch(() => {})
        .then(() => window.setTimeout(finish, 180));
    } else {
      const u = new URL(window.location.href);
      u.searchParams.set('view', viewName);
      u.searchParams.set('_r', String(Date.now()));
      window.location.href = u.pathname + u.search;
    }
  }, { passive: true });
  document.addEventListener('touchcancel', () => {
    if (ptrBusy) return;
    ptrTracking = false;
    ptrDragging = false;
    ptrArmed = false;
    ptrCollapse();
  }, { passive: true });

  // Auto-hydrate: use a bounded SSE stream for near-real-time updates. The
  // established timestamp poll remains the fallback for older browsers,
  // proxies that buffer SSE, and transient stream failures.
  let timelineStream = null;
  let streamReconnectTimer = 0;
  let streamFallbackTimer = 0;
  function stopTimelineStream() {
    if (timelineStream) {
      timelineStream.close();
      timelineStream = null;
    }
    if (streamReconnectTimer) {
      window.clearTimeout(streamReconnectTimer);
      streamReconnectTimer = 0;
    }
  }
  // Soft-nav Mentions→Home / tab swaps clear the timer; always re-arm for
  // standard timelines so live head polls do not die until a hard refresh.
  function stopTimelinePollTimer() {
    if (streamFallbackTimer) {
      window.clearTimeout(streamFallbackTimer);
      window.clearInterval(streamFallbackTimer);
      streamFallbackTimer = 0;
    }
  }
  function ensureTimelinePollTimer(reset) {
    if (!timelineHeadPollView()
        || timelinePollSuspended || window.__vaakNavigationPending || tabSwapBusy
        || !items || !items.isConnected) {
      stopTimelinePollTimer();
      return;
    }
    if (streamFallbackTimer && !reset) return;
    stopTimelinePollTimer();
    // One chained timeout, not setInterval. Background tabs throttle interval
    // callbacks, and the old 45s interval plus a 90s gate could skip the
    // hidden check entirely until the reader had been looking again.
    const delay = document.hidden ? BACKGROUND_POLL_MS : POLL_MS;
    streamFallbackTimer = window.setTimeout(() => {
      streamFallbackTimer = 0;
      pollTimelineAutomatically();
      ensureTimelinePollTimer(false);
    }, delay);
  }
  function scheduleStreamReconnect() {
    if (streamReconnectTimer || document.hidden || window.__vaakNavigationPending) return;
    streamReconnectTimer = window.setTimeout(() => {
      streamReconnectTimer = 0;
      startTimelineStream();
    }, 1200);
  }
  function startTimelineStream() {
    if (!TIMELINE_SSE_ENABLED || !['home', 'local', 'feed'].includes(viewName)) return;
    if (!window.EventSource || document.hidden || window.__vaakNavigationPending || !nearTop() || isNotifTimeline || isOutboxTimeline || isBskyTimeline || timelineStream) return;
    const url = '?view=' + encodeURIComponent(viewName)
      + '&partial=1&stream=1&since=' + encodeURIComponent(String(newestTs))
      + '&limit=' + encodeURIComponent(String(Math.min(24, limit)));
    try {
      const source = new EventSource(url, { withCredentials: true });
      timelineStream = source;
      source.addEventListener('posts', (event) => {
        try {
          const payload = JSON.parse(event.data || '{}');
          const html = String(payload.html || '');
          const headerTs = parseInt(payload.newest || '0', 10) || 0;
          if (!html.trim()) return;
          const filtered = filterNewHtml(html);
          if (!filtered.parsed) return;
          commitNewerCursor(filtered, headerTs);
          if (!filtered.count) return;
          pendingHtml = filtered.html + pendingHtml;
          pendingCount += filtered.count;
          pendingSourceCount += filtered.sourceCount;
          ensureNewPostsBtn();
          updateNewBtn();
        } catch (e) {}
      });
      source.addEventListener('close', () => {
        if (timelineStream !== source) return;
        stopTimelineStream();
        scheduleStreamReconnect();
      });
      source.onerror = () => {
        if (timelineStream !== source) return;
        stopTimelineStream();
        ensureTimelinePollTimer();
        scheduleStreamReconnect();
      };
      // SSE is optional near-real-time; keep the timestamp poll armed so Bluesky
      // ingest (no PG wake-up) and soft-nav rebinds cannot leave the tab silent.
      ensureTimelinePollTimer();
    } catch (e) {
      timelineStream = null;
      ensureTimelinePollTimer();
    }
  }
  // Full-page navigation must release the SSE connection immediately.  The
  // browser may keep the old document alive briefly while the next timeline
  // is fetched, otherwise the old stream can occupy a PHP-FPM worker.
  window.addEventListener('pagehide', () => {
    timelinePollSuspended = true;
    timelineGeneration++;
    window.__vaakNavigationPending = true;
    timelineCatchUpPending = false;
    timelineCatchUpForce = false;
    if (timelineCatchUpTimer) {
      window.clearTimeout(timelineCatchUpTimer);
      timelineCatchUpTimer = 0;
    }
    if (pollPromise || pollBusy) {
      lastTimelinePollAt = null;
      timelinePollInvalidated = true;
    }
    if (activeTimelinePollAbort) { try { activeTimelinePollAbort.abort(); } catch (e) {} }
    stopTimelineStream();
    stopTimelinePollTimer();
  });
  window.addEventListener('pageshow', (event) => {
    // A normal load also fires pageshow. Resume only for bfcache, or when
    // pagehide suspended this same document and the browser brought it back
    // without persisted=true (Safari tab/app switches).
    if (!event.persisted && !timelinePollSuspended) return;
    timelinePollSuspended = false;
    window.__vaakNavigationPending = false;
    timelineHiddenAt = 0;
    ensureTimelinePollTimer(true);
    scheduleTimelineCatchUp(true);
    syncTimelineStreamVisibility();
  });
  window.addEventListener('focus', () => {
    if (document.hidden) return;
    // First paint focuses the window before any poll. That is not a return.
    if (lastTimelinePollAt === null && !timelineSawHidden && !timelinePollSuspended) return;
    const elapsed = lastTimelinePollAt === null ? Infinity : (Date.now() - lastTimelinePollAt);
    if (timelinePollCompleted && elapsed < POLL_MS && !timelineSawHidden) return;
    scheduleTimelineCatchUp(elapsed >= BACKGROUND_POLL_MS || timelineSawHidden);
  });
  window.addEventListener('online', () => {
    if (document.hidden || !timelinePollStartedAt) return;
    const elapsed = lastTimelinePollAt === null ? Infinity : (Date.now() - lastTimelinePollAt);
    if (timelinePollCompleted && elapsed < POLL_MS) return;
    scheduleTimelineCatchUp(true);
  });
  window.addEventListener('resume', () => {
    timelinePollSuspended = false;
    if (!tabSwapBusy) window.__vaakNavigationPending = false;
    ensureTimelinePollTimer(true);
    scheduleTimelineCatchUp(true);
  });
  if (['home', 'local', 'feed'].includes(viewName)) {
    // Keep a bounded timestamp poll even when SSE is enabled. Bluesky
    // ingestion may not emit the same PostgreSQL wake-up notification as an
    // ActivityPub event, and this guarantees the New N posts marker remains
    // reliable without delaying the initial paint.
    ensureTimelinePollTimer();
    if (TIMELINE_SSE_ENABLED && window.EventSource && !isNotifTimeline && !isOutboxTimeline && !isBskyTimeline) {
      startTimelineStream();
    }
  }
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      const hiddenFor = timelineHiddenAt > 0 ? (Date.now() - timelineHiddenAt) : 0;
      const wasSuspended = !!timelinePollSuspended;
      if (wasSuspended || timelineSawHidden) {
        timelinePollSuspended = false;
        if (!tabSwapBusy) window.__vaakNavigationPending = false;
      }
      timelineHiddenAt = 0;
      const away = timelineSawHidden;
      timelineSawHidden = false;
      ensureTimelinePollTimer(hiddenFor > 0 || wasSuspended);
      if (away || wasSuspended) scheduleTimelineCatchUp(hiddenFor >= 8000 || wasSuspended);
      syncTimelineStreamVisibility();
    } else {
      timelineSawHidden = true;
      if (!timelineHiddenAt) timelineHiddenAt = Date.now();
      stopTimelineStream();
      ensureTimelinePollTimer(true);
    }
  });
  function syncTimelineStreamVisibility() {
    if (document.hidden || !nearTop()) {
      stopTimelineStream();
      return;
    }
    startTimelineStream();
  }
  sc.onScroll(syncTimelineStreamVisibility);
  setTimeout(() => { pollTimelineAutomatically(); syncTimelineStreamVisibility(); }, 5000);
  window.novaPollTimeline = pollNewer;
  window.novaInsertPendingTimeline = insertPending;
  window.novaRefreshTimelinePreservingPosition = async function (options) {
    if (!items || !items.isConnected || !['home', 'local', 'feed'].includes(viewName)) return false;
    await pollNewer(options);
    // insertPending keeps the currently visible content anchored by the exact
    // height added above it whenever the reader is away from the feed head.
    insertPending({ scrollToTop: false });
    return true;
  };

  function loadDeferredSuggestions() {
    const slot = document.getElementById('home-suggestions-slot');
    if (!slot || slot.dataset.deferred !== '1') return;
    slot.dataset.deferred = '0';
    fetch('?ajax=home_suggestions', {
      credentials: 'same-origin',
      headers: { 'Accept': 'text/html' },
      cache: 'no-store'
    }).then((res) => res.ok ? res.text() : '')
      .then((html) => {
        if (!html || !html.trim()) {
          slot.remove();
          return;
        }
        slot.hidden = false;
        slot.outerHTML = html;
      })
      .catch(() => { try { slot.remove(); } catch (e) {} });
  }

  async function swapTimelineView(nextView, push) {
    if (tabSwapBusy) return false;
    nextView = String(nextView || '');
    if (!['home', 'local', 'feed'].includes(nextView)) {
      window.location.href = '?view=' + encodeURIComponent(nextView);
      return false;
    }
    // Soft-nav to Mentions/Favourites/etc replaces section.main and leaves this
    // closure holding a detached #timeline-items. Writing there looks like the
    // Home drawer link "does nothing" — especially on mobile after Notifications.
    items = document.getElementById('timeline-items');
    status = document.getElementById('timeline-status');
    sentinel = document.getElementById('timeline-sentinel');
    newBtn = document.getElementById('feed-new-btn');
    topBtn = document.getElementById('feed-top-btn');
    if (!items || !items.isConnected) return false;
    const liveView = String(items.dataset.view || '');
    if (!['home', 'local', 'feed'].includes(liveView)) return false;
    viewName = liveView;
    syncTimelineKindFlags();
    if (nextView === viewName && !push) return true;
    window.__vaakNavigationPending = true;
    // The old view's stream/fallback poll must not keep running while the
    // replacement timeline is being rendered.
    stopTimelineStream();
    stopTimelinePollTimer();
    tabSwapBusy = true;
    if (typeof window.vaakShowLoading === 'function') window.vaakShowLoading('Loading…');
    // Navigation progress is shown by the detached global loading indicator;
    // keep the timeline status area free of a second loader.
    if (status) status.textContent = '';
    items.classList.add('timeline-swapping');
    let sidebarInstalled = false;
    try {
      const url = '?view=' + encodeURIComponent(nextView)
        + '&partial=1&offset=0&limit=' + encodeURIComponent(String(limit || 15));
      const res = await fetch(url, {
        credentials: 'same-origin',
        headers: { 'Accept': 'text/html' },
        cache: 'no-store'
      });
      if (res.status === 401 || res.headers.get('X-VAAK-Auth') === 'required') {
        if (typeof window.novaInvalidateDeferredTrends === 'function') window.novaInvalidateDeferredTrends();
        if (typeof window.vaakRedirectToLogin === 'function') window.vaakRedirectToLogin('tabSwap');
        return false;
      }
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const html = await res.text();
      if (typeof window.vaakLooksLikeLoginHtml === 'function' && window.vaakLooksLikeLoginHtml(html)) {
        if (typeof window.novaInvalidateDeferredTrends === 'function') window.novaInvalidateDeferredTrends();
        window.vaakRedirectToLogin('tabSwap-html');
        return false;
      }
      hasMore = res.headers.get('X-Has-More') === '1';
      const nextOff = parseInt(res.headers.get('X-Next-Offset') || String(limit), 10);
      offset = nextOff > 0 ? nextOff : limit;
      timelineGeneration++;
      viewName = nextView;
      syncTimelineKindFlags();
      pendingHtml = '';
      pendingCount = 0;
      pendingSourceCount = 0;
      updateNewBtn();
      items.innerHTML = html;
      if (typeof window.novaSyncDeferredTrendsPolicy === 'function') window.novaSyncDeferredTrendsPolicy(res);
      items.dataset.view = nextView;
      items.dataset.offset = String(offset);
      items.dataset.hasMore = hasMore ? '1' : '0';
      tlBefore = res.headers.get('X-Next-Cursor') || '';
      items.dataset.before = tlBefore;
      // The server's watermark is authoritative.  Resetting this to the
      // browser clock after a soft navigation made the Home poller skip the
      // posts that arrived while another page was open.
      const newestHdr = parseInt(res.headers.get('X-Newest') || '0', 10);
      const firstSort = items.querySelector('article.tweet[data-sort], article.tweet');
      const firstSortTs = firstSort ? parseInt(firstSort.getAttribute('data-sort') || '0', 10) : 0;
      newestTs = newestHdr > 0 ? newestHdr : (firstSortTs > 0 ? firstSortTs : newestTs);
      items.dataset.newest = String(newestTs);
      if (status) {
        status.textContent = hasMore ? 'Scroll for more…' : (html.trim() ? 'End of timeline' : '');
      }
      // Empty first page with more ranked rows: fill via loadMore (stale/filtered
      // cache head) instead of flashing the permanent empty-state copy.
      if (!html.trim() && hasMore && typeof loadMore === 'function') {
        items.dataset.needsFill = '0';
        offset = 0;
        items.dataset.offset = '0';
        tlBefore = '';
        items.dataset.before = '';
        items.dataset.hasMore = '1';
        if (status) status.textContent = 'Loading timeline…';
        setTimeout(function () { try { loadMore(); } catch (e) {} }, 0);
      } else if (!html.trim() && items.dataset.needsFill !== '1') {
        // Empty-state hint when the partial returns nothing.
        const empty = document.createElement('div');
        empty.className = 'empty';
        if (nextView === 'local') {
          empty.textContent = 'No local posts yet. When anyone on this instance posts, it shows up here.';
        } else if (nextView === 'feed') {
          empty.textContent = 'No federation events yet.';
        } else {
          empty.innerHTML = 'Nothing here yet. Follow people, <a href="?view=tags">follow hashtags</a>, or hit ＋ to post.';
        }
        items.appendChild(empty);
      }
      if (nextView === 'home') {
        const slot = document.createElement('div');
        slot.id = 'home-suggestions-slot';
        slot.className = 'home-suggestions-slot';
        slot.dataset.deferred = '1';
        slot.hidden = true;
        items.appendChild(slot);
        loadDeferredSuggestions();
      }
      document.querySelectorAll('.timeline-tabs a').forEach((a) => {
        const href = a.getAttribute('href') || '';
        const m = href.match(/[?&]view=([a-z_]+)/);
        const v = m ? m[1] : '';
        a.classList.toggle('active', v === nextView);
      });
      if (typeof window.vaakSyncBskyChips === 'function') window.vaakSyncBskyChips(nextView);
      document.querySelectorAll('#admin-rail-left a[href*="view="]').forEach((a) => {
        const href = a.getAttribute('href') || '';
        if (!/[?&]view=(home|local|feed)(?:&|$)/.test(href)) return;
        const m = href.match(/[?&]view=([a-z_]+)/);
        const current = (nextView === 'local' || nextView === 'feed') ? 'home' : nextView;
        a.classList.toggle('active', (m ? m[1] : '') === current);
      });
      const h1 = document.querySelector('.main > .topbar h1');
      if (h1) h1.textContent = TL_TITLES[nextView] || nextView;
      const refresh = document.querySelector('.topbar-actions a.btn[title="Reload this view"]');
      if (refresh) {
        refresh.setAttribute('href', '?view=' + encodeURIComponent(nextView));
      }
      if (typeof window.novaEnhanceTweetFolds === 'function') {
        window.novaEnhanceTweetFolds(items);
      }
      if (typeof window.novaEnqueueBoostHydrates === 'function') {
        window.novaEnqueueBoostHydrates(items);
      }
      sc.setTop(0, false);
      if (push !== false) {
        const href = (typeof window.vaakSoftViewHref === 'function')
          ? window.vaakSoftViewHref(nextView)
          : '/vaak/?view=' + encodeURIComponent(nextView);
        history.pushState({ vaakTl: nextView }, '', href);
      }
      if (items.isConnected && document.getElementById('timeline-items') === items
          && typeof window.vaakSetCurrentViewTitle === 'function') window.vaakSetCurrentViewTitle(nextView);
      if (items.isConnected && document.getElementById('timeline-items') === items
          && typeof window.novaSetDeferredTrendsView === 'function') window.novaSetDeferredTrendsView(nextView);
      sidebarInstalled = true;
      return true;
    } catch (e) {
      window.location.href = '?view=' + encodeURIComponent(nextView);
      return false;
    } finally {
      if (items && items.classList) items.classList.remove('timeline-swapping');
      tabSwapBusy = false;
      loading = false;
      pollBusy = false;
      window.__vaakNavigationPending = false;
      bindTopBtn();
      ensureNewPostsBtn();
      ensureTimelinePollTimer();
      syncTimelineStreamVisibility();
      if (sidebarInstalled && items && items.isConnected && document.getElementById('timeline-items') === items
          && items.dataset.view === nextView && typeof window.novaLoadDeferredTrends === 'function') {
        window.novaLoadDeferredTrends(false);
      }
      // Timeline tabs are swapped in-place, so the shared capture-phase
      // navigation handler has no full-page `pageshow` event to clear its
      // indicator.  Hide it when the partial swap (or its error path) ends.
      if (typeof window.vaakHideLoading === 'function') window.vaakHideLoading();
    }
  }

  window.vaakRestoreTimelinePosition = async function (position) {
    const restoreItems = items;
    const generation = timelineGeneration;
    const target = Math.max(0, Number(position.offset) || 0);
    while (hasMore && offset < target && items === restoreItems && items.isConnected
        && generation === timelineGeneration) {
      if (loading) { await new Promise(resolve => setTimeout(resolve, 30)); continue; }
      const previous = offset;
      await loadMore();
      if (offset <= previous) break;
    }
    return items === restoreItems && items.isConnected && generation === timelineGeneration;
  };
  window.vaakSwapTimelineView = swapTimelineView;
  window.vaakBootTimeline = function (nextView) {
    // Soft-nav landed on a timeline page while this boot script is still alive.
    // Rebind to the new DOM nodes from the shell response.
    nextView = String(nextView || viewName || 'home');
    try { stopTimelineStream(); } catch (e) {}
    stopTimelinePollTimer();
    // Cancel any in-flight Mentions infinite-scroll work before rebinding.
    if (typeof window.vaakAbortNotifScroll === 'function') {
      try { window.vaakAbortNotifScroll(); } catch (e) {}
    }
    items = document.getElementById('timeline-items');
    status = document.getElementById('timeline-status');
    sentinel = document.getElementById('timeline-sentinel');
    if (!items) return false;
    timelineGeneration++;
    viewName = items.dataset.view || nextView;
    syncTimelineKindFlags();
    offset = parseInt(items.dataset.offset || '0', 10) || 0;
    limit = parseInt(items.dataset.limit || '15', 10) || 15;
    tlBefore = items.dataset.before ? String(items.dataset.before) : '';
    hasMore = items.dataset.hasMore === '1';
    if (viewName === 'remote_profile' && status) {
      const feedEl = document.querySelector('.feed');
      const feedStyle = feedEl ? window.getComputedStyle(feedEl) : null;
      profileBootStatus(status, sentinel, hasMore,
        feedStyle && (feedStyle.overflowY === 'auto' || feedStyle.overflowY === 'scroll') ? feedEl : null);
    }
    newestTs = parseInt(items.dataset.newest || '0', 10) || Math.floor(Date.now() / 1000);
    loading = false;
    pollBusy = false;
    stickToTop = false;
    if (stickToTopTimer) {
      window.clearTimeout(stickToTopTimer);
      stickToTopTimer = 0;
    }
    pendingHtml = '';
    pendingCount = 0;
    pendingSourceCount = 0;
    notifMaxId = items.dataset.maxId || '';
    notifFilter = items.dataset.filter || 'all';
    bskyCursor = items.dataset.cursor || '';
    bskyFeed = items.dataset.feed || 'following';
    bskySource = items.dataset.source || 'home';
    if (isBskyTimeline && items.dataset.mergePending === '1') {
      setTimeout(function () { try { loadBskyMergeSamples(); } catch (e) {} }, 400);
    }
    if (status && !isNotifTimeline
        && /end of notifications/i.test(String(status.textContent || ''))) {
      status.textContent = hasMore ? 'Scroll for more…' : 'Loading…';
    }
    if (typeof window.novaEnhanceTweetFolds === 'function') window.novaEnhanceTweetFolds(items);
    if (typeof window.novaEnqueueBoostHydrates === 'function') window.novaEnqueueBoostHydrates(items);
    // Critical: soft-nav replaces #timeline-sentinel. Re-observe the live node
    // or infinite scroll stalls on the static "Scroll for more…" label.
    bindInfiniteScrollObserver();
    // Soft-nav skeleton / empty first paint: always attempt one fill from
    // offset 0, even when the shell said hasMore=0 (short ranked cache).
    if (items.dataset.needsFill === '1' && typeof loadMore === 'function') {
      items.dataset.needsFill = '0';
      offset = 0;
      items.dataset.offset = '0';
      tlBefore = '';
      items.dataset.before = '';
      hasMore = true;
      items.dataset.hasMore = '1';
      setTimeout(function () { try { loadMore(); } catch (e) {} }, 0);
    }
    try { bindTopBtn(); } catch (e) {}
    try { ensureNewPostsBtn(); } catch (e) {}
    try { ensureTimelinePollTimer(); } catch (e) {}
    try { syncTimelineStreamVisibility(); } catch (e) {}
    // The initial shell already contains the current head.  Do not issue an
    // extra newer-post request immediately after soft-nav: the normal bounded
    // poll/SSE path below will pick up new content without making Home repaint
    // a second time while the user is arriving.
    if (typeof window.vaakBindFeedTopBtn === 'function') {
      window.vaakBindFeedTopBtn(document.querySelector('section.main'));
    }
    return true;
  };
  // Home → Notifications detaches this column instead of destroying it.
  // Stop the head poll and SSE so a parked timeline does not hold a worker.
  // Resume re-arms the same poll; it does not clear the new-posts pill or
  // refetch the cards already on screen.
  window.vaakPauseTimelineForPark = function () {
    try { stopTimelineStream(); } catch (e) {}
    stopTimelinePollTimer();
    if (timelineCatchUpTimer) {
      window.clearTimeout(timelineCatchUpTimer);
      timelineCatchUpTimer = 0;
    }
    timelineCatchUpPending = false;
    if (activeTimelinePollAbort) {
      try { activeTimelinePollAbort.abort(); } catch (e) {}
    }
    // Copy the held posts. A later Local/Federated boot clears this closure,
    // and the pill would come back empty if the copy lived only here.
    return {
      pendingHtml: pendingHtml,
      pendingCount: pendingCount,
      pendingSourceCount: pendingSourceCount,
      newestTs: newestTs
    };
  };
  window.vaakResumeParkedTimeline = function (snap) {
    items = document.getElementById('timeline-items');
    status = document.getElementById('timeline-status');
    sentinel = document.getElementById('timeline-sentinel');
    newBtn = document.getElementById('feed-new-btn');
    topBtn = document.getElementById('feed-top-btn');
    if (!items || !items.isConnected) return false;
    const live = String(items.dataset.view || '');
    if (!['home', 'local', 'feed'].includes(live)) return false;
    viewName = live;
    syncTimelineKindFlags();
    offset = parseInt(items.dataset.offset || '0', 10) || 0;
    limit = parseInt(items.dataset.limit || '15', 10) || 15;
    tlBefore = items.dataset.before ? String(items.dataset.before) : '';
    hasMore = items.dataset.hasMore === '1';
    const paintedNewest = parseInt(items.dataset.newest || '0', 10) || 0;
    if (paintedNewest > 0) newestTs = paintedNewest;
    if (snap && typeof snap === 'object') {
      pendingHtml = String(snap.pendingHtml || '');
      pendingCount = Number(snap.pendingCount) || 0;
      pendingSourceCount = Number(snap.pendingSourceCount) || 0;
      const snappedNewest = Number(snap.newestTs) || 0;
      if (snappedNewest > 0) newestTs = snappedNewest;
    }
    window.__vaakNavigationPending = false;
    try { bindInfiniteScrollObserver(); } catch (e) {}
    try { bindTopBtn(); } catch (e) {}
    try { ensureNewPostsBtn(); } catch (e) {}
    try { updateNewBtn(); } catch (e) {}
    try { ensureTimelinePollTimer(); } catch (e) {}
    try { syncTimelineStreamVisibility(); } catch (e) {}
    return true;
  };
  document.querySelectorAll('.timeline-tabs a').forEach((a) => {
    a.addEventListener('click', (ev) => {
      if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey || a.target === '_blank') return;
      const href = a.getAttribute('href') || '';
      const m = href.match(/[?&]view=([a-z_]+)/);
      const next = m ? m[1] : '';
      if (!['home', 'local', 'feed'].includes(next)) return;
      ev.preventDefault();
      swapTimelineView(next, true);
    });
  });
  window.addEventListener('popstate', () => {
    const u = new URL(window.location.href);
    // Back onto a pretty profile URL needs a full load (shell is remote_profile).
    if (typeof window.vaakIsPrettyProfilePath === 'function' && window.vaakIsPrettyProfilePath(u.pathname)) {
      // The later soft-nav listener loads the profile column. Reloading here
      // would rebuild the right rail and race that fetch.
      if (typeof window.vaakSoftNavProfile === 'function') return;
      window.location.reload();
      return;
    }
    // A short permalink has no ?view=. The later listener loads the post.
    if (typeof window.vaakShortStatusTarget === 'function' && window.vaakShortStatusTarget(u)) return;
    const next = u.searchParams.get('view') || 'home';
    // Only swap in place when Home/Local/Federated is the live column. Back
    // from Bluesky or Your posts belongs to the soft-nav popstate handler,
    // which restores the parked column; swapping here too raced it.
    const liveItems = document.getElementById('timeline-items');
    const liveView = liveItems ? String(liveItems.dataset.view || '') : '';
    if (!['home', 'local', 'feed'].includes(liveView)) return;
    if (['home', 'local', 'feed'].includes(next) && next !== viewName) {
      swapTimelineView(next, false);
    }
  });

  // After first paint: Home suggestions (trends load via global script below).
  if ('requestIdleCallback' in window) {
    requestIdleCallback(() => { loadDeferredSuggestions(); }, { timeout: 2500 });
  } else {
    setTimeout(() => { loadDeferredSuggestions(); }, 400);
  }
  return true;
  }
  if (document.getElementById('timeline-items') && document.getElementById('timeline-sentinel')) {
    continueTimelineBoot();
  }
})();
