/* VAAK app script 10. Moved out of api/ap-admin.php (was an inline <script>) so
   browsers cache and precompile it; the shell loads these files in their old order.
*/
// Favourites, Bookmarks, Followers, Following, and Search use the timeline skeleton + spinner.
(function () {
  function feedRoot(main) {
    return (main && main.querySelector('.feed')) || document.querySelector('section.main > .feed');
  }

  function libraryObserverRoot(main) {
    const feed = feedRoot(main);
    const style = feed ? window.getComputedStyle(feed) : null;
    return feed && style && ['auto', 'scroll'].includes(style.overflowY) ? feed : null;
  }

  function paintLibraryLoading(statusEl) {
    if (!statusEl) return;
    const prev = statusEl.previousElementSibling;
    if (!prev || !prev.classList.contains('timeline-skeleton')) {
      const skeleton = document.createElement('div');
      skeleton.className = 'timeline-skeleton';
      skeleton.setAttribute('aria-hidden', 'true');
      skeleton.innerHTML = '<div class="timeline-skeleton-row"></div><div class="timeline-skeleton-row"></div>';
      if (statusEl.parentNode) statusEl.parentNode.insertBefore(skeleton, statusEl);
    }
    statusEl.innerHTML = '<span class="timeline-status-loading"><span class="vaak-spinner" aria-hidden="true"></span><span>Loading…</span></span>';
  }

  function clearLibraryLoading(statusEl, text) {
    if (!statusEl) return;
    const prev = statusEl.previousElementSibling;
    if (prev && prev.classList.contains('timeline-skeleton')) prev.remove();
    statusEl.textContent = text || '';
  }

  function armSentinel(statusEl, main, fire) {
    if (!statusEl || statusEl.dataset.observed === '1') return;
    statusEl.dataset.observed = '1';
    new IntersectionObserver((entries) => {
      if (entries.some((entry) => entry.isIntersecting)) fire();
    }, { root: libraryObserverRoot(main), rootMargin: '180px' }).observe(statusEl);
  }

  window.vaakBootFavouritesBsky = function (main) {
    const root = main || document.querySelector('section.main');
    const fragment = root ? root.querySelector('[data-bsky-favourites-fragment]') : document.querySelector('[data-bsky-favourites-fragment]');
    if (!fragment || fragment.dataset.bound === '1') return;
    fragment.dataset.bound = '1';
    let current = fragment;
    let loading = false;
    const ensureSentinel = () => {
      let sentinel = current.querySelector('[data-bsky-favourites-sentinel]');
      if (!sentinel) {
        sentinel = document.createElement('div');
        sentinel.setAttribute('data-bsky-favourites-sentinel', '1');
        sentinel.className = 'meta';
        sentinel.style.cssText = 'padding:1rem 0 2rem;text-align:center';
        current.appendChild(sentinel);
      }
      return sentinel;
    };
    const load = async (replace) => {
      if (loading || (!replace && current.dataset.hasMore !== '1' && current.dataset.loaded === '1')) return;
      loading = true;
      const status = ensureSentinel();
      paintLibraryLoading(status);
      try {
        const offset = replace ? 0 : (parseInt(current.dataset.offset || '0', 10) || 0);
        const limit = current.dataset.limit || '20';
        const res = await fetch('?ajax=favourites_bsky&offset=' + encodeURIComponent(offset) + '&limit=' + encodeURIComponent(limit), {
          credentials: 'same-origin', headers: { Accept: 'text/html' }, cache: 'no-store'
        });
        if (!res.ok) throw new Error('favourite fragment ' + res.status);
        const holder = document.createElement('div');
        holder.innerHTML = await res.text();
        const next = holder.firstElementChild;
        if (!next) return;
        clearLibraryLoading(status, '');
        if (replace) {
          current.replaceWith(next);
          current = next;
        } else {
          const cards = next.querySelectorAll('article.tweet');
          const keep = ensureSentinel();
          cards.forEach((card) => current.insertBefore(card, keep));
          current.dataset.offset = next.dataset.offset || String(offset);
          current.dataset.hasMore = next.dataset.hasMore || res.headers.get('X-Has-More') || '0';
          // A filtered page can consume source rows without painting cards.
          if (cards.length === 0 && !(parseInt(current.dataset.offset, 10) > offset)) current.dataset.hasMore = '0';
        }
        if (replace && next.querySelectorAll('article.tweet').length === 0 && !(parseInt(current.dataset.offset, 10) > offset)) current.dataset.hasMore = '0';
        current.dataset.loaded = '1';
        const sentinel = ensureSentinel();
        const hasMore = current.dataset.hasMore === '1';
        clearLibraryLoading(sentinel, hasMore ? 'Scroll for more…' : 'End of Bluesky favourites');
        if (hasMore) {
          sentinel.dataset.observed = '0';
          armSentinel(sentinel, root, () => load(false));
        }
        if (typeof window.vaakBindFeedTopBtn === 'function') window.vaakBindFeedTopBtn(document.querySelector('section.main'));
        if (typeof window.novaEnhanceTweetFolds === 'function') window.novaEnhanceTweetFolds(current);
      } catch (e) {
        const sentinel = current.querySelector('[data-bsky-favourites-sentinel]');
        if (replace) {
          clearLibraryLoading(sentinel, '');
          current.innerHTML = '<div class="empty">Bluesky favourites are still refreshing in the background.</div>';
        } else {
          clearLibraryLoading(sentinel, 'Couldn’t load more favourites.');
          current.dataset.hasMore = '0';
        }
      } finally {
        loading = false;
      }
    };
    load(true);
  };

  window.vaakBootFavouritesFedi = function (main) {
    const root = main || document.querySelector('section.main');
    const frag = root ? root.querySelector('[data-fedi-favourites-fragment]') : document.querySelector('[data-fedi-favourites-fragment]');
    if (!frag || frag.dataset.bound === '1') return;
    frag.dataset.bound = '1';
    let busy = false;
    const load = async () => {
      if (busy || frag.dataset.hasMore !== '1') return;
      busy = true;
      const sentinel = frag.querySelector('[data-fedi-favourites-sentinel]');
      paintLibraryLoading(sentinel);
      try {
        const offset = parseInt(frag.dataset.offset || '0', 10) || 0;
        const limit = frag.dataset.limit || '20';
        const ajax = frag.dataset.ajax === 'favourites_nostr' ? 'favourites_nostr' : 'favourites_fedi';
        const res = await fetch('?ajax=' + ajax + '&offset=' + encodeURIComponent(offset) + '&limit=' + encodeURIComponent(limit), {
          credentials: 'same-origin', headers: { Accept: 'text/html' }, cache: 'no-store'
        });
        if (!res.ok) throw new Error('fedi favs ' + res.status);
        const holder = document.createElement('div');
        holder.innerHTML = await res.text();
        const next = holder.firstElementChild;
        if (!next) return;
        const cards = next.querySelectorAll('article.tweet');
        clearLibraryLoading(sentinel, '');
        cards.forEach((card) => frag.insertBefore(card, sentinel));
        frag.dataset.offset = next.dataset.offset || String(offset + cards.length);
        frag.dataset.hasMore = next.dataset.hasMore || res.headers.get('X-Has-More') || '0';
        if (cards.length === 0 && !(parseInt(frag.dataset.offset, 10) > offset)) frag.dataset.hasMore = '0';
        const hasMore = frag.dataset.hasMore === '1';
        clearLibraryLoading(sentinel, hasMore ? 'Scroll for more…' : (frag.dataset.endLabel || 'End of Fediverse favourites'));
        if (hasMore && sentinel) {
          sentinel.dataset.observed = '0';
          armSentinel(sentinel, root, () => load());
        }
      } catch (e) {
        clearLibraryLoading(sentinel, 'Couldn’t load more favourites.');
        frag.dataset.hasMore = '0';
      } finally {
        busy = false;
      }
    };
    const sentinel = frag.querySelector('[data-fedi-favourites-sentinel]');
    if (frag.dataset.hasMore === '1') armSentinel(sentinel, root, () => load());
  };

  function armBookmarkMedia(root) {
    if (!root || !('IntersectionObserver' in window)) return;
    const nodes = root.querySelectorAll('video.media-video[data-src]:not([data-media-armed])');
    if (!nodes.length) return;
    const io = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        const video = entry.target;
        if (!video.getAttribute('src') && video.dataset.src) video.setAttribute('src', video.dataset.src);
        video.dataset.mediaArmed = '1';
        io.unobserve(video);
      });
    }, { root: libraryObserverRoot(root), rootMargin: '240px' });
    nodes.forEach((video) => io.observe(video));
  }

  window.vaakBootBookmarks = function (main) {
    const root = main || document.querySelector('section.main');
    const slot = root ? root.querySelector('#bookmarks-slot') : document.getElementById('bookmarks-slot');
    if (!slot || slot.dataset.bound === '1') return;
    slot.dataset.bound = '1';
    const folder = slot.getAttribute('data-folder') || '0';
    const limit = slot.getAttribute('data-limit') || '20';
    let fragment = null;
    let busy = false;
    const load = async (replace) => {
      if (busy) return;
      if (!replace && (!fragment || fragment.dataset.hasMore !== '1')) return;
      busy = true;
      let status = slot.querySelector('[data-bookmark-sentinel]');
      if (!status) {
        status = document.createElement('div');
        status.setAttribute('data-bookmark-sentinel', '1');
        status.className = 'meta';
        status.style.cssText = 'padding:1rem 0 2rem;text-align:center';
        slot.appendChild(status);
      }
      paintLibraryLoading(status);
      try {
        const offset = replace ? 0 : (parseInt(fragment.dataset.offset || '0', 10) || 0);
        const res = await fetch('?ajax=bookmarks_page'
          + '&folder=' + encodeURIComponent(folder)
          + '&offset=' + encodeURIComponent(String(offset))
          + '&limit=' + encodeURIComponent(limit), {
          credentials: 'same-origin', headers: { Accept: 'text/html' }, cache: 'no-store'
        });
        if (!res.ok) throw new Error('bookmarks ' + res.status);
        const holder = document.createElement('div');
        holder.innerHTML = await res.text();
        const next = holder.querySelector('[data-bookmark-fragment]') || holder.firstElementChild;
        if (!next) throw new Error('empty');
        clearLibraryLoading(status, '');
        if (replace) {
          slot.querySelectorAll('[data-bookmark-fragment]').forEach((node) => node.remove());
          slot.insertBefore(next, status);
          fragment = next;
        } else {
          const cards = next.querySelectorAll('article.tweet');
          cards.forEach((card) => slot.insertBefore(card, status));
          fragment.dataset.offset = next.dataset.offset || fragment.dataset.offset || '0';
          fragment.dataset.hasMore = next.dataset.hasMore || res.headers.get('X-Has-More') || '0';
          if (cards.length === 0 && !(parseInt(fragment.dataset.offset, 10) > offset)) fragment.dataset.hasMore = '0';
        }
        if (replace && next.querySelectorAll('article.tweet').length === 0 && !(parseInt(fragment.dataset.offset, 10) > offset)) fragment.dataset.hasMore = '0';
        const hasMore = !!(fragment && fragment.dataset.hasMore === '1');
        const empty = fragment && fragment.querySelector('[data-bookmark-empty]');
        clearLibraryLoading(status, empty ? '' : (hasMore ? 'Scroll for more…' : 'End of bookmarks'));
        if (hasMore) {
          status.dataset.observed = '0';
          armSentinel(status, root, () => load(false));
        }
        armBookmarkMedia(slot);
        if (typeof window.novaEnhanceTweetFolds === 'function') window.novaEnhanceTweetFolds(slot);
        if (typeof window.vaakBindFeedTopBtn === 'function') window.vaakBindFeedTopBtn(document.querySelector('section.main'));
      } catch (e) {
        clearLibraryLoading(status, 'Couldn’t load bookmarks right now.');
      } finally {
        busy = false;
      }
    };
    load(true);
  };

  // Linked OpenSim bodies: the page paints from the mirrored row, then asks
  // the grid for fresh stats once. Reload only when something changed; the
  // bridge TTL makes the reloaded page's own request a no-op, so it can't loop.
  window.vaakBootPhyrianSync = function (main) {
    const root = main || document;
    const section = root.querySelector ? root.querySelector('section.phyrian[data-phyrian-sync="1"]') : null;
    if (!section || section.dataset.phyrianSyncStarted === '1' || !window.VAAK_CSRF) return;
    section.dataset.phyrianSyncStarted = '1';
    const fd = new FormData();
    fd.set('csrf', window.VAAK_CSRF);
    fetch('/vaak/?view=phyrian&ajax=phyrian_sync', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
      .then((r) => (r.ok ? r.json() : null))
      .then((data) => {
        if (!data || !data.ok || !data.changed || !section.isConnected) return;
        window.location.reload();
      })
      .catch(() => {});
  };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => window.vaakBootPhyrianSync(document), { once: true });
  } else {
    window.vaakBootPhyrianSync(document);
  }

  // ---- Search page chrome: hint, clear, remote switch, recents, tag follow, highlight.
  // All client side; nothing here asks the server for anything new.
  const SEARCH_RECENT_KEY = 'vaak.search.recent.v1';
  function searchRecentRead() {
    try {
      const raw = JSON.parse(window.localStorage.getItem(SEARCH_RECENT_KEY) || '[]');
      return Array.isArray(raw) ? raw.filter((v) => typeof v === 'string' && v.trim()).slice(0, 8) : [];
    } catch (e) { return []; }
  }
  function searchRecentWrite(list) {
    try { window.localStorage.setItem(SEARCH_RECENT_KEY, JSON.stringify(list.slice(0, 8))); } catch (e) {}
  }
  function searchEsc(value) {
    return String(value).replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
  }
  function searchIntentHtml(raw, resolve) {
    const v = String(raw || '').trim();
    if (!v) return '';
    const chip = (label, cls) => '<span class="search-intent-chip' + (cls ? ' ' + cls : '') + '">' + label + '</span> ';
    if (v === '@') return 'Type a name, or a full handle like @user@server.';
    if (v === '#') return 'Type a tag name.';
    if (/^https?:\/\/$/i.test(v)) return 'Paste a post or profile link.';
    if (/^https?:\/\//i.test(v)) {
      return chip('link') + (resolve ? 'Post or profile link. Search opens it here, fetching it if needed.' : 'Post or profile link. Remote lookup is off, so only posts VAAK already has.');
    }
    const handle = v.match(/^@?([A-Za-z0-9_.-]+)@([A-Za-z0-9.-]+\.[A-Za-z]{2,})$/);
    if (handle) {
      return chip('handle') + (resolve
        ? 'Looks up <b>@' + searchEsc(handle[1]) + '</b> on ' + searchEsc(handle[2]) + '.'
        : 'Only people VAAK already knows. Turn on Look up remote to fetch them.');
    }
    if (v.startsWith('#')) return chip('tag') + 'Posts and tags matching ' + searchEsc(v) + '.';
    if (/^@?[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/.test(v)) return chip('Bluesky', 'is-bsky') + 'Looks like a Bluesky handle or a server name.';
    if (v.startsWith('@')) return chip('person') + 'People named ' + searchEsc(v.slice(1)) + '.';
    return 'People, tags and posts. Press Enter to search.';
  }
  function paintSearchIntent(form) {
    if (!form) return;
    const input = form.querySelector('input[name="q"]');
    const out = form.querySelector('[data-search-intent]');
    const resolve = form.querySelector('[data-search-resolve]');
    const clear = form.querySelector('[data-search-clear]');
    if (out) out.innerHTML = searchIntentHtml(input ? input.value : '', !resolve || resolve.checked);
    if (clear) clear.hidden = !(input && input.value);
  }
  function paintSearchRecent(root) {
    const box = root.querySelector('[data-search-recent]');
    const list = box ? box.querySelector('[data-search-recent-list]') : null;
    if (!box || !list) return;
    const items = searchRecentRead();
    box.hidden = items.length === 0;
    list.innerHTML = items.map((q, i) => {
      const type = q.startsWith('#') ? 'statuses' : '';
      const href = '?view=search&q=' + encodeURIComponent(q) + (type ? '&type=' + type : '') + '&resolve=1';
      return '<span class="search-chip search-chip-recent"><a href="' + searchEsc(href) + '" data-vaak-soft-nav="search" data-search-q="'
        + searchEsc(q) + '" data-search-type="' + type + '">' + searchEsc(q) + '</a>'
        + '<button type="button" class="search-chip-x" data-search-recent-remove="' + i + '" aria-label="Remove ' + searchEsc(q) + '">✕</button></span>';
    }).join('');
  }
  function highlightSearchTerms(scope) {
    const results = scope && scope.closest ? (scope.closest('[data-search-results]') || scope.querySelector('[data-search-results]')) : null;
    if (!results) return;
    const q = String(results.getAttribute('data-search-results') || '').trim();
    if (!q || /^https?:\/\//i.test(q) || /^@?[^@\s]+@[^@\s]+$/.test(q)) return;
    const words = q.replace(/^[#@]/, '').split(/\s+/).map((w) => w.replace(/^[#@]/, '')).filter((w) => w.length >= 2).slice(0, 6);
    if (!words.length) return;
    const re = new RegExp('(' + words.map((w) => w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|') + ')', 'gi');
    scope.querySelectorAll('.search-section-statuses .feed-body:not([data-search-marked])').forEach((body) => {
      body.dataset.searchMarked = '1';
      const walker = document.createTreeWalker(body, NodeFilter.SHOW_TEXT);
      const nodes = [];
      while (walker.nextNode()) nodes.push(walker.currentNode);
      nodes.slice(0, 400).forEach((node) => {
        const text = node.nodeValue;
        re.lastIndex = 0;
        if (!text || !re.test(text)) return;
        const frag = document.createDocumentFragment();
        let last = 0;
        text.replace(re, (match, _g, offset) => {
          if (offset > last) frag.appendChild(document.createTextNode(text.slice(last, offset)));
          const mark = document.createElement('mark');
          mark.className = 'search-hit';
          mark.textContent = match;
          frag.appendChild(mark);
          last = offset + match.length;
          return match;
        });
        if (last < text.length) frag.appendChild(document.createTextNode(text.slice(last)));
        node.parentNode.replaceChild(frag, node);
      });
    });
  }
  // The remote switch re-runs the same search in place: only the results and
  // the tab counts change, the box, hint and switch stay put. Anything odd
  // (profile redirect, wrong shell, network) falls back to a normal soft-nav.
  let searchRefreshSeq = 0;
  async function refreshSearchResults(form, q, resolve) {
    const typeEl = form.querySelector('input[name="type"]');
    const type = typeEl ? String(typeEl.value || '') : '';
    const extra = { q: q };
    if (type) extra.type = type;
    if (resolve) extra.resolve = '1';
    const fallback = () => {
      if (typeof window.vaakSoftNavTo === 'function') window.vaakSoftNavTo('search', true, '', extra);
      else if (typeof form.requestSubmit === 'function') form.requestSubmit();
    };
    const main = form.closest('section.main') || document.querySelector('section.main');
    const results = main ? main.querySelector('[data-search-results]') : null;
    const tabs = main ? main.querySelector('.search-tabs') : null;
    if (!results || !tabs || typeof fetch !== 'function') { fallback(); return; }
    const seq = ++searchRefreshSeq;
    results.setAttribute('aria-busy', 'true');
    results.classList.add('is-refreshing');
    const params = new URLSearchParams({ view: 'search', partial: '1', shell: '1', q: q });
    if (type) params.set('type', type);
    if (resolve) params.set('resolve', '1');
    try {
      const res = await fetch('?' + params.toString(), {
        credentials: 'same-origin',
        headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
        cache: 'no-store'
      });
      if (seq !== searchRefreshSeq) return;
      if (res.status === 401 || res.headers.get('X-VAAK-Auth') === 'required') {
        if (typeof window.vaakRedirectToLogin === 'function') window.vaakRedirectToLogin('search-remote');
        return;
      }
      if (!res.ok || res.headers.get('X-VAAK-View') !== 'search' || res.headers.get('X-VAAK-Profile')) throw new Error('shell');
      const holder = document.createElement('div');
      holder.innerHTML = await res.text();
      if (seq !== searchRefreshSeq) return;
      const nextResults = holder.querySelector('[data-search-results]');
      const nextTabs = holder.querySelector('.search-tabs');
      if (!nextResults || !nextTabs || !results.isConnected) throw new Error('shell');
      results.replaceWith(nextResults);
      tabs.replaceWith(nextTabs);
      const href = typeof window.vaakSoftViewHref === 'function'
        ? window.vaakSoftViewHref('search', '', extra)
        : '/vaak/?' + params.toString().replace(/&?(partial|shell)=1/g, '');
      history.replaceState({ vaakSoft: 'search', filter: '', extra: extra }, '', href);
      if (typeof window.novaEnhanceTweetFolds === 'function') window.novaEnhanceTweetFolds(nextResults);
      window.vaakBootSearch(main);
    } catch (e) {
      if (seq !== searchRefreshSeq) return;
      results.removeAttribute('aria-busy');
      results.classList.remove('is-refreshing');
      fallback();
    }
  }
  function bootSearchChrome(root) {
    const head = root.querySelector('.search-head');
    if (!head) return;
    paintSearchIntent(head.querySelector('form'));
    paintSearchRecent(root);
    const results = root.querySelector('[data-search-results]');
    if (results) {
      const q = String(results.getAttribute('data-search-results') || '').trim();
      if (q && !/^https?:\/\//i.test(q)) searchRecentWrite([q].concat(searchRecentRead().filter((v) => v !== q)));
      highlightSearchTerms(results);
    }
  }
  if (!window.__vaakSearchChromeBound) {
    window.__vaakSearchChromeBound = true;
    document.addEventListener('input', (ev) => {
      const input = ev.target;
      if (!input || input.name !== 'q' || !input.closest) return;
      paintSearchIntent(input.closest('.search-form'));
    });
    document.addEventListener('change', (ev) => {
      const box = ev.target;
      if (!box || !box.matches || !box.matches('[data-search-resolve]')) return;
      const form = box.closest('.search-form');
      paintSearchIntent(form);
      const input = form ? form.querySelector('input[name="q"]') : null;
      const q = input ? input.value.trim() : '';
      const resolve = !!box.checked;
      // Tabs carry the switch too, so the next tab click keeps it.
      document.querySelectorAll('.search-tabs a.search-tab').forEach((tab) => {
        try {
          const u = new URL(tab.getAttribute('href') || '', window.location.href);
          if (resolve) u.searchParams.set('resolve', '1'); else u.searchParams.delete('resolve');
          tab.setAttribute('href', u.search);
        } catch (e) {}
      });
      if (!form || !q) return;
      // A pasted link still full-loads on purpose (permalink redirect).
      if (/^https:\/\//i.test(q)) { if (typeof form.requestSubmit === 'function') form.requestSubmit(); return; }
      refreshSearchResults(form, q, resolve);
    });
    document.addEventListener('click', (ev) => {
      const t = ev.target && ev.target.closest ? ev.target : null;
      if (!t) return;
      const clear = t.closest('[data-search-clear]');
      if (clear) {
        const form = clear.closest('.search-form');
        const input = form ? form.querySelector('input[name="q"]') : null;
        if (input) { input.value = ''; input.focus(); }
        paintSearchIntent(form);
        return;
      }
      const fill = t.closest('[data-search-fill]');
      if (fill) {
        const input = document.querySelector('.search-form input[name="q"]');
        if (!input) return;
        input.value = fill.getAttribute('data-search-fill') || '';
        input.focus();
        try { input.setSelectionRange(input.value.length, input.value.length); } catch (e) {}
        paintSearchIntent(input.closest('.search-form'));
        return;
      }
      const remove = t.closest('[data-search-recent-remove]');
      if (remove) {
        ev.preventDefault();
        const list = searchRecentRead();
        list.splice(parseInt(remove.getAttribute('data-search-recent-remove'), 10) || 0, 1);
        searchRecentWrite(list);
        const root = remove.closest('.search-start');
        if (root) paintSearchRecent(root.parentNode || document);
        return;
      }
      const wipe = t.closest('[data-search-recent-clear]');
      if (wipe) {
        searchRecentWrite([]);
        const root = wipe.closest('.search-start');
        if (root) paintSearchRecent(root.parentNode || document);
      }
    });
    // Tag chips follow in place (follow_tag answers JSON when ajax=1).
    document.addEventListener('submit', async (ev) => {
      const form = ev.target;
      if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-search-tag-follow')) return;
      ev.preventDefault();
      if (form.dataset.busy === '1') return;
      form.dataset.busy = '1';
      const fd = new FormData(form);
      fd.set('ajax', '1');
      if (window.VAAK_CSRF) fd.set('csrf', window.VAAK_CSRF);
      try {
        const res = await fetch(form.getAttribute('action') || '?view=tags', { method: 'POST', body: fd, credentials: 'same-origin',
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await res.json().catch(() => null);
        if (!data || !data.ok) throw new Error((data && data.error) || 'Could not follow that tag.');
        const chip = form.closest('.search-tag');
        if (chip) chip.classList.add('is-following');
        const state = document.createElement('a');
        state.className = 'search-tag-state';
        state.href = '?view=tags';
        state.title = 'You follow this tag. Manage followed tags';
        state.textContent = 'following';
        form.replaceWith(state);
      } catch (e) {
        form.dataset.busy = '0';
        if (typeof window.apAdminToast === 'function') window.apAdminToast((e && e.message) || 'Could not follow that tag.', true);
      }
    });
  }

  window.vaakBootSearch = function (main) {
    const root = main || document.querySelector('section.main');
    if (root) bootSearchChrome(root);
    const lists = root ? root.querySelectorAll('[data-search-list]') : [];
    lists.forEach((list) => {
      if (!list || list.dataset.bound === '1') return;
      list.dataset.bound = '1';
      const sentinel = list.querySelector('[data-search-sentinel]');
      if (!sentinel || list.dataset.hasMore !== '1') return;
      let busy = false;
      const load = async () => {
        if (busy || !list.isConnected || list.dataset.hasMore !== '1') return;
        busy = true;
        paintLibraryLoading(sentinel);
        try {
          const offset = parseInt(list.dataset.offset || '0', 10) || 0;
          const limit = list.dataset.limit || '20';
          const params = new URLSearchParams();
          params.set('ajax', 'search_page');
          params.set('section', list.dataset.section || 'statuses');
          params.set('q', list.dataset.q || '');
          params.set('offset', String(offset));
          params.set('limit', String(limit));
          if (list.dataset.resolve === '1') params.set('resolve', '1');
          const res = await fetch('?' + params.toString(), {
            credentials: 'same-origin',
            headers: { Accept: 'text/html' },
            cache: 'no-store'
          });
          if (res.status === 401 || res.headers.get('X-VAAK-Auth') === 'required') {
            if (typeof window.vaakRedirectToLogin === 'function') window.vaakRedirectToLogin('search-scroll');
            return;
          }
          if (!res.ok) throw new Error('search ' + res.status);
          const holder = document.createElement('div');
          holder.innerHTML = await res.text();
          const next = holder.querySelector('[data-search-fragment]');
          if (!next) throw new Error('empty');
          const cards = next.querySelectorAll(':scope > article.tweet, :scope > article.search-account, :scope > .search-tag');
          clearLibraryLoading(sentinel, '');
          if (cards.length === 0) {
            const rawNextOffset = next.dataset.offset || '';
            const nextOffset = Number(rawNextOffset);
            if (/^\d+$/.test(rawNextOffset) && Number.isSafeInteger(nextOffset) && nextOffset > offset) {
              list.dataset.offset = rawNextOffset;
              list.dataset.hasMore = next.dataset.hasMore || res.headers.get('X-Has-More') || '0';
            } else {
              list.dataset.hasMore = '0';
            }
          } else {
            cards.forEach((card) => list.insertBefore(card, sentinel));
            list.dataset.offset = next.dataset.offset || String(offset + cards.length);
            list.dataset.hasMore = next.dataset.hasMore || res.headers.get('X-Has-More') || '0';
            if (typeof window.novaEnhanceTweetFolds === 'function') window.novaEnhanceTweetFolds(list);
            highlightSearchTerms(list);
          }
          const hasMore = list.dataset.hasMore === '1';
          clearLibraryLoading(sentinel, hasMore ? 'Scroll for more…' : 'End of results');
          if (hasMore) {
            sentinel.dataset.observed = '0';
            armSentinel(sentinel, root, () => load());
          }
        } catch (e) {
          clearLibraryLoading(sentinel, 'Couldn’t load more results.');
          list.dataset.hasMore = '0';
        } finally {
          busy = false;
        }
      };
      armSentinel(sentinel, root, () => load());
    });
  };

  window.vaakBootFollowList = function (main) {
    const root = main || document.querySelector('section.main');
    const list = root ? root.querySelector('[data-follow-list]') : document.querySelector('[data-follow-list]');
    if (!list || list.dataset.bound === '1') return;
    list.dataset.bound = '1';
    const sentinel = list.querySelector('[data-follow-sentinel]');
    if (!sentinel || list.dataset.hasMore !== '1') return;
    let busy = false;
    const view = list.dataset.view === 'following' ? 'following' : 'followers';
    const load = async () => {
      if (busy || list.dataset.hasMore !== '1') return;
      busy = true;
      paintLibraryLoading(sentinel);
      try {
        const offset = parseInt(list.dataset.offset || '0', 10) || 0;
        const limit = list.dataset.limit || '40';
        const network = list.dataset.network || 'all';
        const res = await fetch('?ajax=' + view + '_page&network=' + encodeURIComponent(network)
          + '&offset=' + encodeURIComponent(String(offset))
          + '&limit=' + encodeURIComponent(limit), {
          credentials: 'same-origin', headers: { Accept: 'text/html' }, cache: 'no-store'
        });
        if (!res.ok) throw new Error(view + ' ' + res.status);
        const holder = document.createElement('div');
        holder.innerHTML = await res.text();
        const next = holder.querySelector('[data-follow-fragment]') || holder.firstElementChild;
        if (!next) throw new Error('empty');
        const cards = next.querySelectorAll('article.tweet');
        clearLibraryLoading(sentinel, '');
        if (cards.length === 0) {
          list.dataset.hasMore = '0';
        } else {
          cards.forEach((card) => list.insertBefore(card, sentinel));
          list.dataset.offset = next.dataset.offset || String(offset + cards.length);
          list.dataset.hasMore = next.dataset.hasMore || res.headers.get('X-Has-More') || '0';
        }
        const hasMore = list.dataset.hasMore === '1';
        clearLibraryLoading(sentinel, hasMore ? 'Scroll for more…' : ('End of ' + view));
        if (hasMore) {
          sentinel.dataset.observed = '0';
          armSentinel(sentinel, root, () => load());
        }
      } catch (e) {
        clearLibraryLoading(sentinel, 'Couldn’t load more accounts.');
        list.dataset.hasMore = '0';
      } finally {
        busy = false;
      }
    };
    armSentinel(sentinel, root, () => load());
  };

  if (document.querySelector('[data-bsky-favourites-fragment]')) {
    window.vaakBootFavouritesBsky(document.querySelector('section.main'));
  } else if (document.querySelector('[data-fedi-favourites-fragment]')) {
    window.vaakBootFavouritesFedi(document.querySelector('section.main'));
  }
  if (document.getElementById('bookmarks-slot')) {
    window.vaakBootBookmarks(document.querySelector('section.main'));
  }
  if (document.querySelector('[data-follow-list]')) {
    window.vaakBootFollowList(document.querySelector('section.main'));
  }
  if (document.querySelector('[data-search-list], .search-head')) {
    window.vaakBootSearch(document.querySelector('section.main'));
  }
})();
