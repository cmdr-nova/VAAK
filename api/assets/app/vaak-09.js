/* VAAK app script 9. Moved out of api/ap-admin.php (was an inline <script>) so
   browsers cache and precompile it; the shell loads these files in their old order.
*/
// Soft-nav for primary left-rail / timeline destinations. Keeps rails + CSS;
// swaps section.main. Falls back to full navigation when a shell or boot is missing.
(function () {
  const SOFT_VIEWS = new Set(['home', 'local', 'feed', 'bluesky', 'mentions', 'outbox', 'favourites', 'bookmarks', 'following', 'followers', 'search', 'status', 'bsky_feeds']);
  const TL_TITLES = {
    home: 'Home', local: 'Local', feed: 'Federation feed',
    mentions: 'Notifications', outbox: 'Your posts',
    favourites: 'Favourites', bookmarks: 'Bookmarks',
    following: 'Following', followers: 'Followers', search: 'Search', status: 'Post',
    bluesky: 'Home', bsky_feeds: 'Feeds'
  };
  let busy = false;
  let leaving = false;
  let queuedNavigation = null;
  // Mobile drawer: pointerup starts soft-nav; ignore the synthetic click that
  // often lands on Mentions content after the rail slides away.
  let softNavTouchGuard = 0;

  // hardNav sets leaving=true before assign; if the browser ignores a same-URL
  // navigation, soft-nav would permanently no-op. Clear on resume / pageshow.
  window.addEventListener('pageshow', () => { leaving = false; });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') leaving = false;
  });

  function hardNav(view, filter, extra) {
    leaving = true;
    if (typeof window.vaakCloseMobileNav === 'function') window.vaakCloseMobileNav();
    window.__vaakNavigationPending = true;
    if (typeof window.vaakShowLoading === 'function') window.vaakShowLoading('Loading…');
    // Absolute /vaak/ path: relative ?view= on /users/{key} would reload the profile
    // when <base> is missing; with <base href="/vaak/"> it works, but stay explicit.
    let url;
    if (view === 'status' && typeof window.vaakStatusHref === 'function') {
      url = window.vaakStatusHref((extra && extra.object) || '', (extra && extra.from) || 'home', extra || {});
    } else {
      url = '/vaak/?view=' + encodeURIComponent(view);
      if (view === 'mentions' && filter && filter !== 'all') {
        url += '&notification_filter=' + encodeURIComponent(filter);
      }
      if (extra && typeof extra === 'object') {
        Object.keys(extra).forEach((k) => {
          if (extra[k] != null && extra[k] !== '') url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(String(extra[k]));
        });
      }
    }
    // Same view in the address bar (common after Mentions soft-nav left a
    // stale home URL, or hardNav retry) needs a real navigation. ?_r= busts
    // the browser URL identity; server still serves warm ranked (SWR, 0.6.68).
    // Ops true rebuild: append &_force=1 manually.
    try {
      const cur = new URL(window.location.href);
      const onPretty = typeof window.vaakIsPrettyProfilePath === 'function'
        && window.vaakIsPrettyProfilePath(cur.pathname);
      const curView = cur.searchParams.get('view') || (onPretty ? '' : 'home');
      if (curView === view || onPretty) {
        url += '&_r=' + String(Date.now());
      }
    } catch (e) {}
    window.location.assign(url);
  }

  function bootLibraryView(view, main) {
    if (view === 'favourites') {
      const net = (new URL(window.location.href)).searchParams.get('network') || 'fedi';
      if (net === 'bsky' && typeof window.vaakBootFavouritesBsky === 'function') {
        window.vaakBootFavouritesBsky(main);
      } else if (typeof window.vaakBootFavouritesFedi === 'function') {
        window.vaakBootFavouritesFedi(main);
      }
    } else if (view === 'bookmarks' && typeof window.vaakBootBookmarks === 'function') {
      window.vaakBootBookmarks(main);
    } else if ((view === 'followers' || view === 'following') && typeof window.vaakBootFollowList === 'function') {
      window.vaakBootFollowList(main);
    }
  }

  function updateChrome(view) {
    // DMs and Blog full pages hide both rails. Soft-nav only swaps the column,
    // so those classes have to come off or Home keeps a single empty column.
    document.body.classList.remove('dm-fullscreen', 'dm-peer-open', 'blog-fullscreen');
    if (view === 'dms') document.body.classList.add('dm-fullscreen');
    else if (view === 'blog') document.body.classList.add('blog-fullscreen');
    if (typeof window.vaakSetCurrentViewTitle === 'function') window.vaakSetCurrentViewTitle(view);
    if (typeof window.vaakSyncBskyChips === 'function') window.vaakSyncBskyChips(view);
    const mobileTitle = document.querySelector('.mobile-topbar__title');
    if (mobileTitle) mobileTitle.innerHTML = '<span>VAAK</span> · ' + (TL_TITLES[view] || view);
    document.querySelectorAll('#admin-rail-left a[href*="view="]').forEach((a) => {
      const href = a.getAttribute('href') || '';
      const m = href.match(/[?&]view=([a-z_]+)/);
      const linkView = m ? m[1] : '';
      const current = (view === 'local' || view === 'feed' || view === 'bluesky') ? 'home' : view;
      a.classList.toggle('active', linkView === current);
    });
    if (typeof window.vaakSyncNavSelection === 'function') window.vaakSyncNavSelection();
    const trends = document.getElementById('trends-sidebar');
    if (trends) {
      trends.dataset.from = view;
      if (typeof window.novaSetDeferredTrendsView === 'function') window.novaSetDeferredTrendsView(view);
      else if (typeof window.novaLoadDeferredTrends === 'function') window.novaLoadDeferredTrends(false);
    }
    if (view === 'mentions') {
      const badge = document.getElementById('notif-badge');
      if (badge) { badge.hidden = true; badge.textContent = ''; }
    }
  }

  function setStatusLoading(statusEl) {
    if (!statusEl) return;
    statusEl.innerHTML = '<span class="timeline-status-loading"><span class="vaak-spinner" aria-hidden="true"></span><span>Loading…</span></span>';
  }

  function insertScrollSkeleton(statusEl) {
    if (!statusEl || !statusEl.parentNode) return null;
    const skeleton = document.createElement('div');
    skeleton.className = 'timeline-skeleton';
    skeleton.setAttribute('aria-hidden', 'true');
    skeleton.innerHTML = '<div class="timeline-skeleton-row"></div><div class="timeline-skeleton-row"></div>';
    statusEl.parentNode.insertBefore(skeleton, statusEl);
    return skeleton;
  }

  function bindFeedTopBtn(main) {
    const topBtn = document.getElementById('feed-top-btn');
    const feed = (main && main.querySelector('.feed')) || document.querySelector('section.main > .feed');
    if (!topBtn) return;
    const scrollTop = () => {
      if (feed) {
        const style = window.getComputedStyle(feed);
        if (style.overflowY === 'auto' || style.overflowY === 'scroll') return feed.scrollTop;
      }
      return window.scrollY || document.documentElement.scrollTop || 0;
    };
    const scrollToTop = () => {
      if (feed) {
        const style = window.getComputedStyle(feed);
        if (style.overflowY === 'auto' || style.overflowY === 'scroll') {
          feed.scrollTo({ top: 0, behavior: 'smooth' });
          return;
        }
      }
      window.scrollTo({ top: 0, behavior: 'smooth' });
    };
    const sync = () => { topBtn.classList.toggle('show', scrollTop() > 280); };
    if (topBtn.dataset.bound === '1') { sync(); return; }
    topBtn.dataset.bound = '1';
    if (feed) feed.addEventListener('scroll', sync, { passive: true });
    window.addEventListener('scroll', sync, { passive: true });
    topBtn.addEventListener('click', scrollToTop);
    sync();
  }

  function bindNotifScroll(main, prefetch) {
    const items = document.getElementById('timeline-items');
    const sentinel = document.getElementById('timeline-sentinel');
    const status = document.getElementById('timeline-status');
    const feed = main.querySelector('.feed') || document.querySelector('section.main > .feed');
    if (!items || !sentinel) {
      if (prefetch && prefetch.abort) {
        try { prefetch.abort.abort(); } catch (e) {}
      }
      return;
    }
    let loading = false;
    let hasMore = items.dataset.hasMore === '1';
    let maxId = items.dataset.maxId || '';
    let initialPending = items.dataset.initialPending === '1';
    const filter = items.dataset.filter || 'all';
    const limit = parseInt(items.dataset.limit || '30', 10) || 30;
    let aborted = false;
    let fetchAbort = prefetch && prefetch.abort ? prefetch.abort : null;
    const stillOnMentions = () => {
      const live = document.getElementById('timeline-items');
      return !aborted && live === items && live && live.dataset.view === 'mentions';
    };
    window.vaakAbortNotifScroll = function () {
      aborted = true;
      try { if (fetchAbort) fetchAbort.abort(); } catch (e) {}
      fetchAbort = null;
      try { io.disconnect(); } catch (e) {}
    };
    async function loadInitial() {
      if (!initialPending || loading || aborted) {
        // A restored column already has its first page. Drop a fill that
        // this binder is not going to paint.
        if (!initialPending && prefetch && prefetch.abort && !aborted) {
          try { prefetch.abort.abort(); } catch (e) {}
        }
        return;
      }
      initialPending = false;
      try { items.dataset.initialPending = '0'; } catch (e) {}
      loading = true;
      const skeleton = insertScrollSkeleton(status);
      setStatusLoading(status);
      try {
        const firstUrl = '?view=mentions&partial=1'
          + '&notification_filter=' + encodeURIComponent(filter)
          + '&limit=' + encodeURIComponent(String(limit));
        let firstRes;
        if (prefetch && prefetch.response) {
          firstRes = await prefetch.response;
        } else {
          fetchAbort = (typeof AbortController !== 'undefined') ? new AbortController() : null;
          firstRes = await fetch(firstUrl, {
            credentials: 'same-origin',
            headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store',
            signal: fetchAbort ? fetchAbort.signal : undefined
          });
        }
        if (!stillOnMentions()) return;
        if (!firstRes.ok) throw new Error('HTTP ' + firstRes.status);
        const firstHtml = await firstRes.text();
        if (!stillOnMentions()) return;
        if (firstHtml.trim()) {
          const tmp = document.createElement('div');
          tmp.innerHTML = firstHtml;
          while (tmp.firstChild) items.appendChild(tmp.firstChild);
          if (typeof window.novaEnhanceTweetFolds === 'function') window.novaEnhanceTweetFolds(items);
        }
        hasMore = firstRes.headers.get('X-Has-More') === '1';
        maxId = firstRes.headers.get('X-Next-Max-Id') || '';
        items.dataset.hasMore = hasMore ? '1' : '0';
        items.dataset.maxId = maxId;
        if (!firstHtml.trim()) {
          items.innerHTML = '<div class="empty">No notifications yet.</div>';
          hasMore = false;
          items.dataset.hasMore = '0';
        }
        if (status) status.textContent = hasMore ? 'Scroll for more…' : 'End of notifications';
      } catch (e) {
        if (e && e.name === 'AbortError') return;
        if (!stillOnMentions()) return;
        if (status) status.textContent = 'Could not load notifications — try Refresh';
        hasMore = false;
        items.dataset.hasMore = '0';
      } finally {
        fetchAbort = null;
        if (skeleton && skeleton.parentNode) skeleton.parentNode.removeChild(skeleton);
        loading = false;
      }
    }
    loadInitial();
    const io = new IntersectionObserver(async (entries) => {
      if (!stillOnMentions()) return;
      if (!entries.some((e) => e.isIntersecting) || loading || !hasMore || !maxId) return;
      loading = true;
      const skeleton = insertScrollSkeleton(status);
      setStatusLoading(status);
      try {
        const moreUrl = '?view=mentions&partial=1'
          + '&notification_filter=' + encodeURIComponent(filter)
          + '&notifications_max_id=' + encodeURIComponent(maxId)
          + '&limit=' + encodeURIComponent(String(limit));
        fetchAbort = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        const moreRes = await fetch(moreUrl, {
          credentials: 'same-origin',
          headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
          cache: 'no-store',
          signal: fetchAbort ? fetchAbort.signal : undefined
        });
        if (!stillOnMentions()) return;
        if (!moreRes.ok) throw new Error('HTTP ' + moreRes.status);
        const moreHtml = await moreRes.text();
        if (!stillOnMentions()) return;
        hasMore = moreRes.headers.get('X-Has-More') === '1';
        maxId = moreRes.headers.get('X-Next-Max-Id') || '';
        items.dataset.hasMore = hasMore ? '1' : '0';
        items.dataset.maxId = maxId;
        if (moreHtml.trim()) {
          const tmp = document.createElement('div');
          tmp.innerHTML = moreHtml;
          while (tmp.firstChild) items.appendChild(tmp.firstChild);
          if (typeof window.novaEnhanceTweetFolds === 'function') window.novaEnhanceTweetFolds(items);
        }
        if (status) status.textContent = hasMore ? 'Scroll for more…' : 'End of notifications';
      } catch (e) {
        if (e && e.name === 'AbortError') return;
        if (!stillOnMentions()) return;
        if (status) status.textContent = 'Could not load more';
        hasMore = false;
      } finally {
        fetchAbort = null;
        if (skeleton && skeleton.parentNode) skeleton.parentNode.removeChild(skeleton);
        loading = false;
      }
    }, { root: feed || null, rootMargin: '400px 0px', threshold: 0 });
    io.observe(sentinel);
  }
  window.bindNotifScroll = bindNotifScroll;
  // Hard Mentions page paints an empty shell (data-initial-pending=1); fill async
  // the same way soft-nav does so Accept/Deny PRG returns stay fast.
  (function bootMentionsShell() {
    try {
      const items = document.getElementById('timeline-items');
      if (!items || items.dataset.view !== 'mentions' || items.dataset.initialPending !== '1') return;
      const main = document.querySelector('section.main') || document.body;
      bindNotifScroll(main);
    } catch (e) {}
  })();

  function bindGenericScroll(view, main) {
    const items = document.getElementById('timeline-items');
    const sentinel = document.getElementById('timeline-sentinel');
    const status = document.getElementById('timeline-status');
    const feed = main.querySelector('.feed');
    if (!items || !sentinel) return;
    let loading = false;
    let hasMore = items.dataset.hasMore === '1';
    let offset = parseInt(items.dataset.offset || '0', 10) || 0;
    const limit = parseInt(items.dataset.limit || '20', 10) || 20;
    const io = new IntersectionObserver(async (entries) => {
      if (!entries.some((e) => e.isIntersecting) || loading || !hasMore) return;
      loading = true;
      const skeleton = insertScrollSkeleton(status);
      setStatusLoading(status);
      try {
        const moreUrl = '?view=' + encodeURIComponent(view)
          + '&partial=1&offset=' + encodeURIComponent(String(offset))
          + '&limit=' + encodeURIComponent(String(limit));
        const moreRes = await fetch(moreUrl, {
          credentials: 'same-origin',
          headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
          cache: 'no-store'
        });
        if (!moreRes.ok) throw new Error('HTTP ' + moreRes.status);
        const moreHtml = await moreRes.text();
        hasMore = moreRes.headers.get('X-Has-More') === '1';
        const nextOff = parseInt(moreRes.headers.get('X-Next-Offset') || String(offset + limit), 10);
        offset = nextOff > 0 ? nextOff : (offset + limit);
        items.dataset.hasMore = hasMore ? '1' : '0';
        items.dataset.offset = String(offset);
        if (moreHtml.trim()) {
          const tmp = document.createElement('div');
          tmp.innerHTML = moreHtml;
          while (tmp.firstChild) items.appendChild(tmp.firstChild);
        }
        if (status) status.textContent = hasMore ? 'Scroll for more…' : 'End';
      } catch (e) {
        if (status) status.textContent = 'Could not load more';
        hasMore = false;
      } finally {
        if (skeleton && skeleton.parentNode) skeleton.parentNode.removeChild(skeleton);
        loading = false;
      }
    }, { root: feed || null, rootMargin: '400px 0px', threshold: 0 });
    io.observe(sentinel);
  }

  function statusViewFromAnchor(link) {
    if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self')) return '';
    try {
      const u = new URL(link.href, window.location.href);
      if (u.origin !== window.location.origin) return '';
      if (typeof window.vaakShortStatusTarget === 'function' && window.vaakShortStatusTarget(u)) return 'status';
      if (!['/vaak/', '/vaak'].includes(u.pathname)) return '';
      return u.searchParams.get('view') === 'status' && u.searchParams.get('object') ? 'status' : '';
    } catch (e) { return ''; }
  }

  function softNavExtrasFromLink(link) {
    const extra = {};
    if (!link) return extra;
    const linkUrl = new URL(link.href, window.location.href);
    const view = link.getAttribute('data-vaak-soft-nav') || statusViewFromAnchor(link);
    if (view === 'status') {
      const short = typeof window.vaakShortStatusTarget === 'function' ? window.vaakShortStatusTarget(linkUrl) : null;
      if (short) {
        extra.object = short.object;
        extra.from = short.from;
        if (short.refresh_thread) extra.refresh_thread = '1';
      } else {
        extra.object = linkUrl.searchParams.get('object') || '';
        extra.from = linkUrl.searchParams.get('from') || 'home';
        if (linkUrl.searchParams.get('refresh_thread')) extra.refresh_thread = '1';
      }
    }
    if (view === 'favourites') {
      extra.network = link.getAttribute('data-fav-network')
        || linkUrl.searchParams.get('network')
        || 'fedi';
    }
    if (view === 'following' || view === 'followers') {
      extra.network = link.getAttribute('data-follow-network')
        || linkUrl.searchParams.get('network')
        || 'all';
      const lim = linkUrl.searchParams.get('limit');
      if (lim) extra.limit = lim;
    }
    if (view === 'bookmarks') {
      const folder = link.getAttribute('data-bm-folder')
        || linkUrl.searchParams.get('folder');
      if (folder) extra.folder = folder;
    }
    if (view === 'bluesky') {
      extra.feed = link.getAttribute('data-bsky-feed')
        || linkUrl.searchParams.get('feed')
        || 'following';
    }
    if (view === 'search') {
      extra.q = link.getAttribute('data-search-q') || linkUrl.searchParams.get('q') || '';
      extra.type = link.getAttribute('data-search-type') || linkUrl.searchParams.get('type') || '';
      if (linkUrl.searchParams.get('resolve') || !extra.q) extra.resolve = '1';
    }
    return extra;
  }

  // Hashtags and trend rows link to Search without data-vaak-soft-nav.
  // A normal click would reload the document and paint the right rail again.
  function searchExtraFromAnchor(link) {
    if (!link || link.target === '_blank' || link.hasAttribute('download')) return null;
    let url;
    try { url = new URL(link.href, window.location.href); } catch (e) { return null; }
    if (url.origin !== window.location.origin) return null;
    const path = url.pathname.replace(/\/+$/, '');
    if (path !== '/vaak' && !path.endsWith('/vaak/index.php')) return null;
    const view = (url.searchParams.get('view') || '').replace(/[^a-z_]/g, '');
    if (view !== 'search') return null;
    const q = (url.searchParams.get('q') || '').trim();
    if (/^https:\/\//i.test(q)) return null;
    const extra = {};
    if (q) extra.q = q;
    const type = (url.searchParams.get('type') || '').replace(/[^a-z]/g, '');
    if (type) extra.type = type;
    if (url.searchParams.get('resolve')) extra.resolve = '1';
    return extra;
  }

  function profileTargetFromAnchor(link) {
    if (!link || link.target === '_blank' || link.hasAttribute('download')) return null;
    let url;
    try { url = new URL(link.href, window.location.href); } catch (e) { return null; }
    if (url.origin !== window.location.origin) return null;
    if (/\/notes\//.test(url.pathname)) return null;
    // Reply, quote, and edit on a profile card are ?view=remote_profile&compose=1.
    // On /vaak/users/{key} those query-only hrefs resolve back onto the profile,
    // and soft-nav would reload this profile and drop the composer params.
    const rawHref = (link.getAttribute && link.getAttribute('href')) || '';
    if (/[?&](?:compose|reply_to|quote_object|edit_note|draft_id)=/.test(rawHref)
        || url.searchParams.has('compose')
        || (url.searchParams.get('reply_to') || '') !== ''
        || (url.searchParams.get('quote_object') || '') !== ''
        || (url.searchParams.get('edit_note') || '') !== ''
        || (url.searchParams.get('draft_id') || '') !== '') {
      return null;
    }
    // ?view=home (and Settings, Notices, …) on a pretty profile URL is an app
    // navigation. Browsers that ignore <base> for "?…" links still resolve the
    // path to /vaak/users/{key}; treating that as "open this profile" traps
    // every rail click on the profile.
    const explicitView = (url.searchParams.get('view') || '').replace(/[^a-z_]/g, '');
    if (explicitView && explicitView !== 'remote_profile') return null;
    const pathMatch = url.pathname.match(/^(?:\/vaak)?\/users\/([A-Za-z0-9_]+)\/?$/);
    let actor = '';
    if (pathMatch) {
      actor = 'https://mkultra.monster/users/' + pathMatch[1];
    } else if ((url.pathname === '/vaak' || url.pathname === '/vaak/' || url.pathname === '/vaak/index.php')
        && (url.searchParams.get('view') || '') === 'remote_profile') {
      actor = (url.searchParams.get('actor') || '').replace(/\/$/, '');
      if (!/^https:\/\/[^\s/?#]+\/.+/i.test(actor)) return null;
    } else {
      return null;
    }
    return {
      actor: actor,
      tab: url.searchParams.get('tab') || '',
      from: url.searchParams.get('from') || '',
      post: url.searchParams.get('post') || '',
      historyHref: url.pathname + url.search
    };
  }

  function stopProfileScroll() {
    if (typeof window.vaakAbortProfileScroll === 'function') {
      try { window.vaakAbortProfileScroll(); } catch (e) {}
    }
  }

  function bindProfileScrollFallback(main) {
    const items = main.querySelector('#timeline-items');
    const sentinel = main.querySelector('#timeline-sentinel');
    const status = main.querySelector('#timeline-status');
    const feed = main.querySelector('.feed');
    if (!items || items.dataset.view !== 'remote_profile' || !sentinel) return;
    let loading = false;
    let hasMore = items.dataset.hasMore === '1';
    let offset = parseInt(items.dataset.offset || '0', 10) || 0;
    const limit = parseInt(items.dataset.limit || '12', 10) || 12;
    const actor = items.dataset.actor || '';
    const tab = items.dataset.tab || 'posts';
    let io = null;
    // Boosts pages can paint nothing when every original is uncached. The
    // sentinel then stays in view without a new IO event, so keep walking.
    let emptyRun = 0;
    const abort = () => {
      try { if (io) io.disconnect(); } catch (e) {}
      io = null;
      hasMore = false;
    };
    window.vaakAbortProfileScroll = abort;
    const loadMore = async () => {
      if (!hasMore || loading || !items.isConnected) return;
      loading = true;
      if (status) status.textContent = 'Loading…';
      try {
        const requestOffset = offset;
        const res = await fetch('?view=remote_profile&partial=1&offset=' + encodeURIComponent(String(requestOffset))
          + '&limit=' + encodeURIComponent(String(limit))
          + '&actor=' + encodeURIComponent(actor)
          + '&tab=' + encodeURIComponent(tab), {
          credentials: 'same-origin',
          headers: { 'Accept': 'text/html' },
          cache: 'no-store'
        });
        if (!items.isConnected) return;
        if (res.status === 401 || res.headers.get('X-VAAK-Auth') === 'required') {
          if (!window.vaakGuestProfile && typeof window.vaakRedirectToLogin === 'function') {
            window.vaakRedirectToLogin('profile-scroll');
          }
          return;
        }
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const html = await res.text();
        const moreHeader = res.headers.get('X-Has-More');
        if (moreHeader === '1' || moreHeader === '0') {
          hasMore = moreHeader === '1';
        }
        const next = parseInt(res.headers.get('X-Next-Offset') || String(requestOffset), 10);
        offset = next > requestOffset ? next : requestOffset + limit;
        items.dataset.offset = String(offset);
        items.dataset.hasMore = hasMore ? '1' : '0';
        const painted = html.replace(/<!--[\s\S]*?-->/g, '').trim() !== '';
        if (painted) {
          items.insertAdjacentHTML('beforeend', html);
          if (typeof window.novaEnhanceTweetFolds === 'function') window.novaEnhanceTweetFolds(items);
          if (typeof window.novaEnqueueBoostHydrates === 'function') window.novaEnqueueBoostHydrates(items);
          emptyRun = 0;
        } else {
          emptyRun++;
        }
        if (status) {
          status.textContent = (typeof window.vaakProfileScrollStatus === 'function')
            ? window.vaakProfileScrollStatus(hasMore)
            : (hasMore ? 'Scroll for more…' : 'End of profile');
        }
      } catch (e) {
        hasMore = false;
        if (status) status.textContent = 'Could not load more';
      } finally {
        loading = false;
      }
      if (hasMore && items.isConnected && emptyRun < 8) {
        const box = sentinel.getBoundingClientRect();
        const view = root ? root.getBoundingClientRect() : { top: 0, bottom: window.innerHeight };
        if (box.top < view.bottom + 180 && box.bottom > view.top - 180) {
          window.requestAnimationFrame(() => loadMore());
        }
      }
    };
    const style = feed ? window.getComputedStyle(feed) : null;
    const root = (style && (style.overflowY === 'auto' || style.overflowY === 'scroll')) ? feed : null;
    io = new IntersectionObserver((entries) => {
      if (entries.some((en) => en.isIntersecting)) loadMore();
    }, { root: root, rootMargin: '180px', threshold: 0 });
    io.observe(sentinel);
  }

  // The soft controller restores fresh canonical content itself. Browser auto
  // restoration otherwise races the newly installed column on popstate.
  if ('scrollRestoration' in history) history.scrollRestoration = 'manual';

  function positionCardKey(card) {
    if (!card) return '';
    if (card.dataset.noteId || card.dataset.objectId) return card.dataset.noteId || card.dataset.objectId;
    // Canonical cards expose their source in their own actions, while embedded
    // quotes/body/author links can refer to an entirely different post.
    for (const link of card.querySelectorAll('.tweet-actions a[href]')) {
      if (link.closest('article.tweet') !== card || link.closest('.quote-post, .tweet-quote')) continue;
      try {
        const url = new URL(link.getAttribute('href'), location.href);
        if (url.origin !== location.origin) continue;
        const short = typeof window.vaakShortStatusTarget === 'function' ? window.vaakShortStatusTarget(url) : null;
        if (short && short.object) return short.object;
        if (url.searchParams.get('view') === 'status' && url.searchParams.get('object')) return url.searchParams.get('object');
      } catch (e) {}
    }
    return card.id || '';
  }

  function saveViewPosition() {
    const feed = document.querySelector('section.main .feed');
    const items = document.getElementById('timeline-items');
    const style = feed ? getComputedStyle(feed) : null;
    const inFeed = style && ['auto', 'scroll'].includes(style.overflowY);
    const bounds = inFeed ? feed.getBoundingClientRect() : { top: 0 };
    const anchor = items ? Array.from(items.children).find(el => el.getBoundingClientRect().bottom > bounds.top) : null;
    const position = { top: feed ? feed.scrollTop : 0, window: window.scrollY || 0,
      offset: items ? Number(items.dataset.offset) || 0 : 0,
      anchor: positionCardKey(anchor),
      anchorTop: anchor ? anchor.getBoundingClientRect().top - bounds.top : 0 };
    history.replaceState(Object.assign({}, history.state || {}, { vaakPosition: position }), '', location.href);
  }

  async function restoreViewPosition(main, position) {
    if (!position) return;
    if (main.querySelector('#timeline-items') && typeof window.vaakRestoreTimelinePosition === 'function') {
      // A profile fallback can have its own loader while the generic timeline
      // closure still points to a detached view. Do not discard saved offsets
      // merely because that loader cannot restore this installed column.
      await window.vaakRestoreTimelinePosition(position);
    }
    await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
    if (!main.isConnected || document.querySelector('section.main') !== main) return;
    const feed = main.querySelector('.feed');
    const inFeed = feed && ['auto', 'scroll'].includes(getComputedStyle(feed).overflowY);
    const anchor = position.anchor ? Array.from(main.querySelectorAll('#timeline-items > *'))
      .find(el => positionCardKey(el) === position.anchor) : null;
    if (feed) feed.scrollTop = Math.max(0, Number(position.top) || 0);
    window.scrollTo(0, Math.max(0, Number(position.window) || 0));
    if (anchor) {
      const delta = anchor.getBoundingClientRect().top - (inFeed ? feed.getBoundingClientRect().top : 0)
        - (Number(position.anchorTop) || 0);
      if (inFeed) feed.scrollTop += delta;
      else window.scrollBy(0, delta);
    }
  }

  function parkComposer() {
    if (typeof window.vaakParkComposerForSoftNav === 'function') {
      try { window.vaakParkComposerForSoftNav(); } catch (e) {}
      return;
    }
    const livePanelEarly = document.querySelector('.compose-modal__panel');
    const modalShellEarly = document.getElementById('compose-modal');
    if (livePanelEarly && modalShellEarly) {
      livePanelEarly.classList.remove('compose-inline-panel');
      if (livePanelEarly.parentElement !== modalShellEarly) modalShellEarly.appendChild(livePanelEarly);
    }
  }

  function drainQueuedNavigation() {
    if (leaving || !queuedNavigation) return;
    const next = queuedNavigation;
    queuedNavigation = null;
    window.setTimeout(() => {
      if (next.profile) softNavProfile(next.profile, next.push !== false);
      else softNavTo(next.view, next.push, next.filter, next.extra);
    }, 0);
  }

  async function softNavProfile(targetOrHref, push) {
    const restorePosition = push === false && history.state ? history.state.vaakPosition : null;
    let target = targetOrHref;
    if (typeof targetOrHref === 'string') {
      const stub = document.createElement('a');
      stub.href = targetOrHref;
      target = profileTargetFromAnchor(stub);
    }
    if (!target || !target.actor) {
      if (typeof targetOrHref === 'string' && targetOrHref) window.location.assign(targetOrHref);
      return;
    }
    if (leaving) leaving = false;
    if (busy) {
      queuedNavigation = { profile: target, push: push !== false };
      return;
    }
    const main = document.querySelector('section.main');
    if (!main) {
      window.location.assign(target.historyHref || '/vaak/');
      return;
    }
    if (push !== false) saveViewPosition();
    busy = true;
    window.__vaakNavigationPending = true;
    if (typeof window.vaakCloseMobileNav === 'function') window.vaakCloseMobileNav();
    if (typeof window.vaakShowLoading === 'function') window.vaakShowLoading('Loading…');
    stopProfileScroll();
    if (typeof window.vaakAbortNotifScroll === 'function') {
      try { window.vaakAbortNotifScroll(); } catch (e) {}
    }
    parkComposer();
    if (typeof window.vaakStopStatusThread === 'function') window.vaakStopStatusThread();
    try { main.innerHTML = '<div class="vaak-soft-loading" role="status" aria-live="polite" aria-busy="true"></div>'; } catch (e) {}
    try {
      let url = '?view=remote_profile&partial=1&shell=1&actor=' + encodeURIComponent(target.actor);
      if (target.tab) url += '&tab=' + encodeURIComponent(target.tab);
      if (target.from) url += '&from=' + encodeURIComponent(target.from);
      if (target.post) url += '&post=' + encodeURIComponent(target.post);
      const res = await fetch(url, {
        credentials: 'same-origin',
        headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
        cache: 'no-store'
      });
      const goFull = () => {
        leaving = true;
        window.location.assign(target.historyHref || '/vaak/');
      };
      if (res.status === 401 || res.headers.get('X-VAAK-Auth') === 'required') {
        if (window.vaakGuestProfile) { goFull(); return; }
        leaving = true;
        if (typeof window.novaInvalidateDeferredTrends === 'function') window.novaInvalidateDeferredTrends();
        if (typeof window.vaakRedirectToLogin === 'function') window.vaakRedirectToLogin('profile');
        else goFull();
        return;
      }
      if (!res.ok || res.headers.get('X-VAAK-View') !== 'remote_profile') throw new Error('profile-shell');
      const html = await res.text();
      if (!html.trim()) throw new Error('empty-profile-shell');
      if (typeof window.vaakLooksLikeLoginHtml === 'function' && window.vaakLooksLikeLoginHtml(html)) {
        if (window.vaakGuestProfile) { goFull(); return; }
        leaving = true;
        if (typeof window.novaInvalidateDeferredTrends === 'function') window.novaInvalidateDeferredTrends();
        if (typeof window.vaakRedirectToLogin === 'function') window.vaakRedirectToLogin('profile-html');
        else goFull();
        return;
      }
      parkComposer();
      main.innerHTML = html;
      if (typeof window.novaSyncDeferredTrendsPolicy === 'function') window.novaSyncDeferredTrendsPolicy(res);
      document.body.classList.remove('dm-fullscreen', 'dm-peer-open', 'blog-fullscreen');
      const h1 = main.querySelector('.topbar h1');
      const title = h1 ? String(h1.textContent || '').trim() : 'Profile';
      if (typeof window.vaakSetCurrentViewTitle === 'function') window.vaakSetCurrentViewTitle('remote_profile', title || 'Profile');
      const mobileTitle = document.querySelector('.mobile-topbar__title');
      if (mobileTitle) mobileTitle.textContent = 'VAAK · ' + (title || 'Profile');
      document.querySelectorAll('#admin-rail-left a.active').forEach((a) => a.classList.remove('active'));
      if (typeof window.vaakSyncNavSelection === 'function') window.vaakSyncNavSelection();
      const feedEl = main.querySelector('.feed');
      if (feedEl) { try { feedEl.scrollTop = 0; } catch (e) {} }
      if (typeof window.vaakSilencePinnedMedia === 'function') {
        try { window.vaakSilencePinnedMedia(main); } catch (e) {}
      }
      if (typeof window.novaEnhanceTweetFolds === 'function') {
        const foldRoot = main.querySelector('#timeline-items') || feedEl;
        if (foldRoot) window.novaEnhanceTweetFolds(foldRoot);
      }
      if (typeof window.vaakAdoptComposerAfterSoftNav === 'function') {
        try { window.vaakAdoptComposerAfterSoftNav(); } catch (e) {}
      }
      if (push !== false && target.historyHref) {
        history.pushState({ vaakSoft: 'remote_profile' }, '', target.historyHref);
      }
      let booted = false;
      if (typeof window.vaakBootTimeline === 'function') {
        try { booted = window.vaakBootTimeline('remote_profile') !== false; } catch (e) { booted = false; }
      }
      if (!booted && typeof window.vaakBootTimeline !== 'function'
          && main.querySelector('#timeline-items[data-view="remote_profile"]')) {
        bindProfileScrollFallback(main);
      }
      bindFeedTopBtn(main);
      await restoreViewPosition(main, restorePosition);
      if (main.isConnected && document.querySelector('section.main') === main
          && typeof window.novaSetDeferredTrendsView === 'function') window.novaSetDeferredTrendsView('remote_profile');
      if (typeof window.vaakHideLoading === 'function') window.vaakHideLoading();
      window.__vaakNavigationPending = false;
    } catch (e) {
      leaving = true;
      window.location.assign(target.historyHref || '/vaak/');
    } finally {
      busy = false;
      if (!leaving) {
        window.__vaakNavigationPending = false;
        if (typeof window.vaakHideLoading === 'function') window.vaakHideLoading();
        drainQueuedNavigation();
        if (typeof window.novaLoadDeferredTrends === 'function') window.novaLoadDeferredTrends(false);
      }
    }
  }
  window.vaakSoftNavProfile = softNavProfile;

  // Painted Home / Local / Federated / Notifications columns, kept off-document
  // so a round trip does not re-render them. PHP and Rust cards stay as the
  // nodes that were painted; this map does not rewrite either painter.
  // Hover prefetch is intentionally absent: those responses are Cache-Control
  // no-store, so a prefetch would be another PHP/Rust hit rather than a cache read.
  const parkedColumns = new Map();

  function columnParkKey(view, filter) {
    if (view === 'home' || view === 'local' || view === 'feed') return 'timeline:' + view;
    // Bluesky feeds park per feed so flipping between chips is instant.
    if (view === 'bluesky') return 'bsky:' + String(filter || 'following');
    if (view === 'mentions') {
      const name = String(filter || 'all');
      return 'mentions:' + (name === '' ? 'all' : name);
    }
    return '';
  }

  function mentionsParkIsFresh() {
    const badge = document.getElementById('notif-badge');
    if (!badge || badge.hidden) return true;
    const label = String(badge.textContent || '').replace(/\s+/g, '');
    return label === '' || label === '0';
  }

  function discardMentionsParks() {
    Array.from(parkedColumns.keys()).forEach((key) => {
      if (key.indexOf('mentions:') === 0) parkedColumns.delete(key);
    });
  }

  function liveColumnState() {
    const main = document.querySelector('section.main');
    if (!main || main.querySelector('.vaak-soft-loading, [data-soft-loading]')) return null;
    const items = main.querySelector('#timeline-items');
    if (!items) return null;
    const view = String(items.dataset.view || '');
    const filter = String(items.dataset.filter || 'all') || 'all';
    if (view === 'home' || view === 'local' || view === 'feed') {
      if (items.dataset.needsFill === '1' && !items.querySelector('article')) return null;
      return { main: main, view: view, filter: filter, key: columnParkKey(view, filter) };
    }
    if (view === 'mentions') {
      if (items.dataset.initialPending === '1') return null;
      if (!items.querySelector('article, .empty')) return null;
      return { main: main, view: view, filter: filter, key: columnParkKey(view, filter) };
    }
    if (view === 'bluesky') {
      if (!items.querySelector('article')) return null;
      const feedKey = String(items.dataset.feed || 'following') || 'following';
      return { main: main, view: view, filter: feedKey, key: columnParkKey(view, feedKey) };
    }
    return null;
  }

  function readColumnScroll(main) {
    const feed = main.querySelector('.feed');
    let feedTop = 0;
    if (feed) {
      try { feedTop = feed.scrollTop || 0; } catch (e) {}
    }
    return {
      feed: feedTop,
      window: window.scrollY || document.documentElement.scrollTop || 0
    };
  }

  function applyColumnScroll(main, saved) {
    if (!saved) return;
    const feed = main.querySelector('.feed');
    if (feed) {
      try { feed.scrollTop = Math.max(0, Number(saved.feed) || 0); } catch (e) {}
    }
    try { window.scrollTo(0, Math.max(0, Number(saved.window) || 0)); } catch (e) {}
  }

  function parkLiveColumn(timelineSnap) {
    const state = liveColumnState();
    if (!state || !state.key) return false;
    const scroll = readColumnScroll(state.main);
    const holder = document.createElement('div');
    while (state.main.firstChild) holder.appendChild(state.main.firstChild);
    if (typeof window.vaakSilencePinnedMedia === 'function') {
      try { window.vaakSilencePinnedMedia(holder); } catch (e) {}
    }
    parkedColumns.set(state.key, {
      holder: holder,
      scroll: scroll,
      view: state.view,
      filter: state.filter,
      timelineSnap: timelineSnap || null,
      parkedAt: Date.now()
    });
    if (state.view === 'bluesky') {
      // Keep the three most recent Bluesky feeds; Map order is insert order.
      const bsky = Array.from(parkedColumns.keys()).filter((k) => k.indexOf('bsky:') === 0);
      while (bsky.length > 3) parkedColumns.delete(bsky.shift());
    }
    return true;
  }

  function releaseLiveColumn() {
    const main = document.querySelector('section.main');
    const items = main ? main.querySelector('#timeline-items') : null;
    const view = items ? String(items.dataset.view || '') : '';
    if (view === 'mentions' && typeof window.vaakAbortNotifScroll === 'function') {
      try { window.vaakAbortNotifScroll(); } catch (e) {}
    }
    let timelineSnap = null;
    if ((view === 'home' || view === 'local' || view === 'feed')
        && typeof window.vaakPauseTimelineForPark === 'function') {
      try { timelineSnap = window.vaakPauseTimelineForPark() || null; } catch (e) {}
    }
    parkComposer();
    return parkLiveColumn(timelineSnap);
  }

  // Parked columns are detached DOM. A like/boost/bookmark made in another
  // view (vaakSyncInteractionState, vaak-03.js) updates their cards too, so
  // Home shows it when it is restored.
  window.vaakParkedColumnRoots = function () {
    return Array.from(parkedColumns.values()).map((p) => p && p.holder).filter(Boolean);
  };

  function takeParkedColumn(view, filter) {
    const key = columnParkKey(view, filter);
    if (!key) return null;
    if (view === 'mentions' && !mentionsParkIsFresh()) {
      discardMentionsParks();
      return null;
    }
    const parked = parkedColumns.get(key);
    if (!parked) return null;
    parkedColumns.delete(key);
    // A parked Bluesky feed older than its page cache would look frozen.
    if (view === 'bluesky' && Date.now() - (parked.parkedAt || 0) > 600000) return null;
    return parked;
  }

  function pushSoftHistory(view, filter, extra) {
    extra = extra && typeof extra === 'object' ? extra : {};
    const href = (typeof window.vaakSoftViewHref === 'function')
      ? window.vaakSoftViewHref(view, filter, extra)
      : '/vaak/?view=' + encodeURIComponent(view);
    history.pushState({ vaakSoft: view, filter: filter || '', extra: extra }, '', href);
  }

  function restoreParkedColumn(view, push, filter, extra) {
    const parkFilter = view === 'bluesky' ? String((extra && extra.feed) || 'following') : filter;
    const parked = takeParkedColumn(view, parkFilter);
    if (!parked) return false;
    const main = document.querySelector('section.main');
    if (!main) {
      parkedColumns.set(columnParkKey(view, parkFilter), parked);
      return false;
    }
    if (push !== false) saveViewPosition();
    releaseLiveColumn();
    while (main.firstChild) main.removeChild(main.firstChild);
    while (parked.holder.firstChild) main.appendChild(parked.holder.firstChild);
    updateChrome(view);
    if (push !== false) pushSoftHistory(view, filter, extra);
    applyColumnScroll(main, parked.scroll);
    if (typeof window.vaakAdoptComposerAfterSoftNav === 'function') {
      try { window.vaakAdoptComposerAfterSoftNav(); } catch (e) {}
    }
    bindFeedTopBtn(main);
    if (view === 'home' || view === 'local' || view === 'feed') {
      if (typeof window.vaakResumeParkedTimeline === 'function') {
        try { window.vaakResumeParkedTimeline(parked.timelineSnap || null); } catch (e) {}
      }
    } else if (view === 'mentions') {
      bindNotifScroll(main);
    } else if (view === 'bluesky' && typeof window.vaakBootTimeline === 'function') {
      try { window.vaakBootTimeline('bluesky'); } catch (e) {}
    }
    if (typeof window.vaakHideLoading === 'function') window.vaakHideLoading();
    window.__vaakNavigationPending = false;
    const savedScroll = parked.scroll;
    requestAnimationFrame(() => {
      if (!main.isConnected || document.querySelector('section.main') !== main) return;
      applyColumnScroll(main, savedScroll);
    });
    return true;
  }

  // Top bar + chip row copy with the target chip already picked and a post
  // skeleton under it. null when the live column has no visible chip row.
  function feedChipChrome(main, view, extra) {
    const slot = main.querySelector('[data-bsky-home-chips]');
    const topbar = main.querySelector(':scope > .topbar');
    if (!slot || slot.hidden || !topbar) return null;
    const frag = document.createElement('div');
    frag.appendChild(topbar.cloneNode(true));
    const feed = document.createElement('div');
    feed.className = 'feed timeline-feed';
    const chips = slot.cloneNode(true);
    const active = view === 'home' ? 'vaak' : String((extra && extra.feed) || 'following');
    chips.dataset.active = active;
    chips.querySelectorAll('a[data-chip]').forEach((a) => {
      const on = a.dataset.chip === active;
      a.classList.toggle('btn-primary', on);
      a.classList.toggle('btn-ghost', !on);
      if (on) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
    });
    feed.appendChild(chips);
    // Keep the row where the viewer left it while the posts load.
    const oldRow = slot.querySelector('.bsky-feed-tabs');
    const newRow = chips.querySelector('.bsky-feed-tabs');
    const keepLeft = oldRow ? oldRow.scrollLeft : 0;
    if (newRow && keepLeft > 0) {
      const apply = () => { newRow.scrollLeft = keepLeft; };
      if (typeof requestAnimationFrame === 'function') requestAnimationFrame(apply); else apply();
    }
    const skeleton = document.createElement('div');
    skeleton.className = 'timeline-skeleton';
    skeleton.setAttribute('data-soft-loading', '1');
    skeleton.setAttribute('role', 'status');
    skeleton.setAttribute('aria-busy', 'true');
    skeleton.innerHTML = '<div class="timeline-skeleton-row"></div><div class="timeline-skeleton-row"></div><div class="timeline-skeleton-row"></div>';
    feed.appendChild(skeleton);
    frag.appendChild(feed);
    return frag;
  }

  async function softNavTo(view, push, filter, extra) {
    view = String(view || '');
    filter = filter == null ? '' : String(filter);
    extra = extra && typeof extra === 'object' ? extra : {};
    const restorePosition = push === false && history.state ? history.state.vaakPosition : null;
    if (!SOFT_VIEWS.has(view)) return;
    stopProfileScroll();
    // Stuck leaving (hardNav same-URL no-op) used to make every later Home tap
    // a silent preventDefault with no navigation — bad on the mobile drawer.
    if (leaving) leaving = false;
    if (busy) {
      // Preserve the latest user intent instead of silently dropping a click
      // while the previous shell is still loading (which left Notifications'
      // status text visible over the old page until a second click).
      queuedNavigation = { view, push, filter, extra };
      return;
    }
    if (typeof window.vaakCloseMobileNav === 'function') window.vaakCloseMobileNav();

    // Home ↔ Notifications (and the other parked columns) reuse the nodes
    // already on screen, including scroll position and the new-posts pill.
    if (restoreParkedColumn(view, push, filter, extra)) return;

    if (['home', 'local', 'feed'].includes(view) && typeof window.vaakSwapTimelineView === 'function') {
      const cur = new URL(window.location.href).searchParams.get('view') || '';
      const live = document.getElementById('timeline-items');
      const liveView = (live && live.isConnected) ? String(live.dataset.view || '') : '';
      // Require BOTH the URL and the live DOM to already be a timeline view.
      // After Mentions soft-nav the URL can still say home while #timeline-items
      // is Mentions (or a detached Home node) — in-place swap then no-ops.
      if (['home', 'local', 'feed'].includes(cur) && ['home', 'local', 'feed'].includes(liveView)) {
        if (typeof window.vaakShowLoading === 'function') window.vaakShowLoading('Loading…');
        const swapped = await window.vaakSwapTimelineView(view, push !== false);
        if (swapped !== false) {
          updateChrome(view);
          bindFeedTopBtn(document.querySelector('section.main'));
          return;
        }
        // In-place was the right mode; swap failed (busy / mid-flight detach).
        // Prefer hardNav over racing a shell replace against an in-flight swap,
        // which previously left Local clicks bouncing back to Home.
        hardNav(view, filter, extra);
        return;
      }
    }

    const main = document.querySelector('section.main');
    if (!main) { hardNav(view, filter, extra); return; }

    if (push !== false) saveViewPosition();
    busy = true;
    window.__vaakNavigationPending = true;
    // Always abort prior Mentions scroll work — including Mentions→Mentions
    // filter swaps — so loadInitial cannot append twice onto a live shell.
    if (typeof window.vaakAbortNotifScroll === 'function') {
      try { window.vaakAbortNotifScroll(); } catch (e) {}
    }
    const loadingLabel = view === 'search' ? 'Searching…' : 'Loading…';
    if (typeof window.vaakShowLoading === 'function') window.vaakShowLoading(loadingLabel);
    // Switching Home feed chips (VAAK ↔ a Bluesky feed): keep the top bar and
    // the chip row on screen and only blank the posts, so the switch reads as
    // a tab change, not a page reload. Copied before the column is parked.
    const chipSwitch = (view === 'bluesky' || view === 'home') ? feedChipChrome(main, view, extra) : null;
    // Move the composer out, then detach a painted timeline or notifications
    // column before the spinner replaces section.main. innerHTML used to
    // destroy the only compose panel and the Home cards we can show again.
    releaseLiveColumn();
    if (typeof window.vaakStopStatusThread === 'function') window.vaakStopStatusThread();
    // Replace main immediately so slow shells show a spinner instead of stale content.
    try {
      if (chipSwitch) {
        main.innerHTML = '';
        main.appendChild(chipSwitch);
        while (chipSwitch.firstChild) main.insertBefore(chipSwitch.firstChild, chipSwitch);
        chipSwitch.remove();
      } else {
        // Keep the shell visually empty while the detached global indicator
        // communicates progress; avoid two simultaneous Loading… messages.
        main.innerHTML = '<div class="vaak-soft-loading" role="status" aria-live="polite" aria-busy="true"></div>';
      }
    } catch (e) {}
    let mentionsPrefetch = null;
    let mentionsPrefetchHeld = false;
    try {
      let url = '/vaak/?view=' + encodeURIComponent(view) + '&partial=1&shell=1&limit='
        + encodeURIComponent(view === 'mentions' ? '12' : (view === 'outbox' ? '20' : '15'));
      if (view === 'status') {
        url += '&object=' + encodeURIComponent(extra.object || '') + '&from=' + encodeURIComponent(extra.from || 'home');
        if (extra.refresh_thread) url += '&refresh_thread=1';
      }
      if (view === 'mentions' && filter) url += '&notification_filter=' + encodeURIComponent(filter);
      if (view === 'favourites' && extra.network) url += '&network=' + encodeURIComponent(extra.network);
      if ((view === 'following' || view === 'followers') && extra.network) {
        url += '&network=' + encodeURIComponent(extra.network);
        if (extra.limit) url += '&limit=' + encodeURIComponent(String(extra.limit));
      }
      if (view === 'bookmarks' && extra.folder) url += '&folder=' + encodeURIComponent(extra.folder);
      if (view === 'bluesky' && extra.feed) url += '&feed=' + encodeURIComponent(extra.feed);
      if (view === 'search') {
        if (extra.q) url += '&q=' + encodeURIComponent(extra.q);
        if (extra.type) url += '&type=' + encodeURIComponent(extra.type);
        if (extra.resolve) url += '&resolve=1';
      }
      // The notifications shell is an empty frame. Start the first-page fill
      // now so it can paint from the warm Redis/Axum list without waiting for
      // that frame to come back first. Same two requests as before, overlapped.
      if (view === 'mentions') {
        const fillAbort = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        const fillUrl = '?view=mentions&partial=1'
          + '&notification_filter=' + encodeURIComponent(filter || 'all')
          + '&limit=12';
        const pendingFill = fetch(fillUrl, {
          credentials: 'same-origin',
          headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
          cache: 'no-store',
          signal: fillAbort ? fillAbort.signal : undefined
        });
        pendingFill.catch(() => {});
        mentionsPrefetch = { abort: fillAbort, response: pendingFill };
      }
      const res = await fetch(url, {
        credentials: 'same-origin',
        headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
        cache: 'no-store'
      });
      if (res.status === 401 || res.headers.get('X-VAAK-Auth') === 'required') {
        leaving = true;
        if (typeof window.novaInvalidateDeferredTrends === 'function') window.novaInvalidateDeferredTrends();
        if (typeof window.vaakRedirectToLogin === 'function') window.vaakRedirectToLogin('softNav');
        else hardNav(view, filter, extra);
        return;
      }
      // A bare @user mention link that names a local account: the server
      // answers with that profile instead of a Search page.
      if (view === 'search' && res.headers.get('X-VAAK-Profile')) {
        const stub = document.createElement('a');
        stub.href = res.headers.get('X-VAAK-Profile');
        const here = (new URL(window.location.href).searchParams.get('view') || '').replace(/[^a-z_]/g, '');
        if (here && here !== 'search' && SOFT_VIEWS.has(here)) {
          const withFrom = new URL(stub.href);
          withFrom.searchParams.set('from', here);
          stub.href = withFrom.href;
        }
        const target = profileTargetFromAnchor(stub);
        if (target) {
          queuedNavigation = { profile: target, push: push !== false };
          return;
        }
      }
      if (view === 'status' && res.headers.get('X-VAAK-Redirect')) {
        const target = new URL(res.headers.get('X-VAAK-Redirect'), location.href);
        if (!['https:', 'http:'].includes(target.protocol)) throw new Error('invalid-post-redirect');
        leaving = true;
        location.assign(target.href);
        return;
      }
      if (view === 'status' && res.status === 404) {
        const reason = res.headers.get('X-VAAK-Status-Reason') || '';
        const object = String((extra && extra.object) || '');
        const esc = (value) => String(value).replace(/[&<>"']/g, (ch) => ({
          '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[ch]));
        let home = '';
        const raw = object.trim();
        const decoded = (() => { try { return decodeURIComponent(raw); } catch (e) { return raw; } })();
        const embedded = decoded.match(/at:\/\/(did:[^/\s?#]+)\/app\.bsky\.feed\.post\/([A-Za-z0-9._:~-]+)/);
        if (embedded && embedded[2] !== '.' && embedded[2] !== '..') {
          home = 'https://bsky.app/profile/' + embedded[1] + '/post/' + encodeURIComponent(embedded[2]);
        } else if (/^https:\/\//i.test(raw)) {
          try {
            const parsed = new URL(raw);
            if (parsed.protocol === 'https:' && parsed.host !== location.host) home = parsed.href;
          } catch (e) {}
        }
        let message = 'This post is unavailable.';
        if (reason === 'hidden') message = 'This post is unavailable or hidden.';
        else if (reason === 'missing') message = 'This post was deleted or is no longer on this instance.';
        let html = '<div class="empty">' + message;
        if (reason !== 'hidden' && reason !== 'missing' && home) {
          html += '<div style="margin-top:.75rem"><a href="' + esc(home) + '" target="_blank" rel="noopener noreferrer">Open on its home server</a></div>';
        }
        main.innerHTML = html + '</div>';
        updateChrome(view);
        // The unavailable notice is this post's page; name it in the address bar.
        if (push !== false) pushSoftHistory(view, filter, extra);
        return;
      }
      if (!res.ok) throw new Error('HTTP ' + res.status);
      if (res.headers.get('X-VAAK-View') !== view) throw new Error('missing-shell-header');
      const html = await res.text();
      if (typeof window.vaakLooksLikeLoginHtml === 'function' && window.vaakLooksLikeLoginHtml(html)) {
        leaving = true;
        if (typeof window.novaInvalidateDeferredTrends === 'function') window.novaInvalidateDeferredTrends();
        if (typeof window.vaakRedirectToLogin === 'function') window.vaakRedirectToLogin('softNav-html');
        else hardNav(view, filter, extra);
        return;
      }
      if (!html.trim()) throw new Error('empty-shell');
      // Safety park (panel should already be outside main after the early park).
      if (typeof window.vaakParkComposerForSoftNav === 'function') {
        try { window.vaakParkComposerForSoftNav(); } catch (e) {}
      }
      main.innerHTML = html;
      if (typeof window.novaSyncDeferredTrendsPolicy === 'function') window.novaSyncDeferredTrendsPolicy(res);
      const loadedItems = main.querySelector('#timeline-items');
      if (loadedItems && loadedItems.dataset.view && loadedItems.dataset.view !== view) {
        throw new Error('shell-view-mismatch');
      }
      if (typeof window.vaakSilencePinnedMedia === 'function') {
        try { window.vaakSilencePinnedMedia(main); } catch (e) {}
      }
      if (typeof window.vaakAdoptComposerAfterSoftNav === 'function') {
        window.vaakAdoptComposerAfterSoftNav();
      }
      const feedEl = main.querySelector('.feed');
      if (feedEl) { try { feedEl.scrollTop = 0; } catch (e) {} }
      updateChrome(view);
      if (push !== false) pushSoftHistory(view, filter, extra);
      if (typeof window.novaEnhanceTweetFolds === 'function') {
        const foldRoot = document.getElementById('timeline-items') || main.querySelector('.feed');
        if (foldRoot) window.novaEnhanceTweetFolds(foldRoot);
      }
      bindFeedTopBtn(main);
      if (view === 'status') {
        if (typeof window.vaakBootStatusThread !== 'function' || !window.vaakBootStatusThread()) throw new Error('status-boot');
      } else if (view === 'mentions') {
        bindNotifScroll(main, mentionsPrefetch);
        mentionsPrefetchHeld = true;
      } else if (view === 'phyrian') {
        if (typeof window.vaakBootPhyrianSync === 'function') window.vaakBootPhyrianSync(main);
      } else if (['favourites', 'bookmarks'].includes(view)) {
        bootLibraryView(view, main);
      } else if (['following', 'followers', 'search'].includes(view)) {
        bindFeedTopBtn(main);
        if (view === 'search') {
          if (typeof window.vaakBootSearch === 'function') window.vaakBootSearch(main);
        } else {
          bootLibraryView(view, main);
        }
      } else if (view === 'bluesky' && !main.querySelector('#timeline-items')) {
        // Empty feed / error notice: nothing to scroll, the chips still work.
        bindFeedTopBtn(main);
      } else if (['home', 'local', 'feed', 'outbox', 'bluesky'].includes(view)) {
        if (typeof window.vaakBootTimeline === 'function') {
          const ok = window.vaakBootTimeline(view);
          if (!ok && ['home', 'local', 'feed', 'bluesky'].includes(view)) { hardNav(view, filter, extra); return; }
          bindFeedTopBtn(main);
        } else if (['home', 'local', 'feed', 'bluesky'].includes(view)) {
          hardNav(view, filter, extra); return;
        } else {
          bindGenericScroll(view, main);
        }
      }
      await restoreViewPosition(main, restorePosition);
      if (typeof window.vaakHideLoading === 'function') window.vaakHideLoading();
      window.__vaakNavigationPending = false;
    } catch (e) {
      hardNav(view, filter, extra); return;
    } finally {
      if (mentionsPrefetch && !mentionsPrefetchHeld && mentionsPrefetch.abort) {
        try { mentionsPrefetch.abort.abort(); } catch (err) {}
      }
      busy = false;
      if (!leaving) {
        window.__vaakNavigationPending = false;
        if (typeof window.vaakHideLoading === 'function') window.vaakHideLoading();
        drainQueuedNavigation();
        if (typeof window.novaLoadDeferredTrends === 'function') window.novaLoadDeferredTrends(false);
      }
    }
  }

  // Search forms are GET documents. Submitting one used to rebuild both rails
  // and paint trends before the results. Soft-nav swaps the center column and
  // leaves the rail alone, the same way Home and the library pages do.
  // A pasted https post link still full-loads so the permalink redirect can run.
  document.addEventListener('submit', (ev) => {
    const form = ev.target;
    if (!form || !form.querySelector) return;
    const method = String(form.getAttribute('method') || 'get').toLowerCase();
    if (method !== 'get') return;
    const viewInput = form.querySelector('input[name="view"]');
    if (!viewInput || String(viewInput.value || '') !== 'search') return;
    if (ev.defaultPrevented || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return;
    const qInput = form.querySelector('input[name="q"]');
    const q = qInput ? String(qInput.value || '').trim() : '';
    if (/^https:\/\//i.test(q)) return;
    ev.preventDefault();
    const typeEl = form.querySelector('input[name="type"]:checked, input[type="hidden"][name="type"]');
    const resolveEl = form.querySelector('input[name="resolve"]');
    const extra = {};
    if (q) extra.q = q;
    if (typeEl && typeEl.value) extra.type = String(typeEl.value);
    if (resolveEl && resolveEl.checked) extra.resolve = '1';
    const railQ = document.querySelector('.rail-right input[name="q"]');
    if (railQ && railQ !== qInput) railQ.value = q;
    softNavTo('search', true, '', extra);
  }, true);

  document.addEventListener('click', (ev) => {
    if (ev.defaultPrevented || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey || ev.button !== 0) return;
    // Soft-nav shells need a session; guests follow links as full page loads
    // (a shared post or public profile then opens in the guest view).
    if (window.vaakGuestProfile) return;
    const link = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
    if (!link || link.target === '_blank') return;
    const view = link.getAttribute('data-vaak-soft-nav') || statusViewFromAnchor(link);
    const searchExtra = view ? null : searchExtraFromAnchor(link);
    const profile = (!window.vaakGuestProfile && !SOFT_VIEWS.has(view) && !searchExtra) ? profileTargetFromAnchor(link) : null;
    if (!SOFT_VIEWS.has(view) && !profile && !searchExtra) return;
    // Mobile drawer already started soft-nav on pointerup — swallow the click
    // that would otherwise hit Mentions content behind the closing rail.
    if (Date.now() < softNavTouchGuard) {
      ev.preventDefault();
      ev.stopPropagation();
      if (typeof ev.stopImmediatePropagation === 'function') ev.stopImmediatePropagation();
      return;
    }
    ev.preventDefault();
    ev.stopPropagation();
    if (typeof ev.stopImmediatePropagation === 'function') ev.stopImmediatePropagation();
    if (SOFT_VIEWS.has(view)) softNavTo(view, true, link.getAttribute('data-notif-filter') || '', softNavExtrasFromLink(link));
    else if (searchExtra) softNavTo('search', true, '', searchExtra);
    else softNavProfile(profile, true);
  }, true);

  // Mobile drawer: kick soft-nav on pointerup while the rail is still open.
  // Waiting for click lets the drawer close first; the synthetic click then
  // lands on the Mentions feed and Home appears to do nothing.
  document.addEventListener('pointerup', (ev) => {
    if (ev.pointerType === 'mouse' || ev.button !== 0) return;
    if (window.vaakGuestProfile) return;
    const rail = document.getElementById('admin-rail-left');
    if (!rail || !rail.classList.contains('mobile-open')) return;
    const link = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
    if (!link || link.target === '_blank' || !rail.contains(link)) return;
    const view = link.getAttribute('data-vaak-soft-nav') || statusViewFromAnchor(link);
    const profile = (!window.vaakGuestProfile && !SOFT_VIEWS.has(view)) ? profileTargetFromAnchor(link) : null;
    if (!SOFT_VIEWS.has(view) && !profile) return;
    softNavTouchGuard = Date.now() + 800;
    ev.preventDefault();
    ev.stopPropagation();
    if (typeof ev.stopImmediatePropagation === 'function') ev.stopImmediatePropagation();
    if (SOFT_VIEWS.has(view)) softNavTo(view, true, link.getAttribute('data-notif-filter') || '', softNavExtrasFromLink(link));
    else softNavProfile(profile, true);
  }, true);

  window.addEventListener('popstate', () => {
    const u = new URL(window.location.href);
    const prettyProfile = typeof window.vaakIsPrettyProfilePath === 'function'
      && window.vaakIsPrettyProfilePath(u.pathname);
    const poppedView = (u.searchParams.get('view') || '').replace(/[^a-z_]/g, '');
    if (prettyProfile && poppedView && poppedView !== 'remote_profile') {
      window.location.assign('/vaak/' + u.search);
      return;
    }
    const profileQuery = poppedView === 'remote_profile';
    if (prettyProfile || profileQuery) {
      if (window.vaakGuestProfile) {
        if (prettyProfile) window.location.reload();
        return;
      }
      softNavProfile(u.href, false);
      return;
    }
    const shortStatus = typeof window.vaakShortStatusTarget === 'function' ? window.vaakShortStatusTarget(u) : null;
    if (shortStatus) {
      const extra = { object: shortStatus.object, from: shortStatus.from };
      if (shortStatus.refresh_thread) extra.refresh_thread = '1';
      softNavTo('status', false, '', extra);
      return;
    }
    const view = u.searchParams.get('view') || 'home';
    // Back to DMs, Notices, a post, and the other full-page views. The forward
    // trip swapped only the center column, so this has to load that document.
    if (!SOFT_VIEWS.has(view)) {
      // The DM screen swaps its own workspace between conversations.
      if (view === 'dms' && typeof window.vaakDmLive === 'function' && window.vaakDmLive()) return;
      window.location.reload();
      return;
    }
    const extra = {};
    if (view === 'status') {
      extra.object = u.searchParams.get('object') || '';
      extra.from = u.searchParams.get('from') || 'home';
    }
    if (view === 'favourites') extra.network = u.searchParams.get('network') || 'fedi';
    if (view === 'following' || view === 'followers') {
      extra.network = u.searchParams.get('network') || 'all';
      if (u.searchParams.get('limit')) extra.limit = u.searchParams.get('limit');
    }
    if (view === 'bookmarks' && u.searchParams.get('folder')) extra.folder = u.searchParams.get('folder');
    if (view === 'bluesky') extra.feed = u.searchParams.get('feed') || 'following';
    if (view === 'search') {
      extra.q = u.searchParams.get('q') || '';
      extra.type = u.searchParams.get('type') || '';
      if (u.searchParams.get('resolve') || !extra.q) extra.resolve = '1';
    }
    softNavTo(view, false, u.searchParams.get('notification_filter') || '', extra);
  });

  if (document.getElementById('feed-top-btn')) {
    bindFeedTopBtn(document.querySelector('section.main'));
  }

  window.vaakSoftNavTo = softNavTo;
  window.vaakBindFeedTopBtn = bindFeedTopBtn;
})();
