/* VAAK app script 20. Moved out of api/ap-admin.php (was an inline <script>) so
   browsers cache and precompile it; the shell loads these files in their old order.
   Request values are in VAAK_BOOT.s20, filled by an inline script right before it. */
// Swap a placeholder or broken timeline avatar once a background fetch lands.
// Capture-phase errors see the original URL before onerror replaces it.
(function () {
  const fallback = VAAK_BOOT.s20[0];
  const pending = new Map();
  const attempts = new Map();
  const tried = new Map();
  const resolved = new Set();
  let timer = 0;
  let flushing = false;
  function isFallback(src) {
    if (!src) return true;
    if (src === fallback) return true;
    return src.indexOf('/img/avatar/default.') !== -1 || src.indexOf('/img/avatar/local-default.') !== -1;
  }
  function actorOf(img) {
    return (img && img.dataset && img.dataset.profileHoverActor) || '';
  }
  function note(img, broken) {
    const actor = actorOf(img);
    if (!actor || !/^https:\/\//i.test(actor)) return;
    if (resolved.has(actor) && !broken) return;
    if (broken) resolved.delete(actor);
    const src = img.getAttribute('src') || '';
    if (!broken && !isFallback(src)) return;
    const prev = pending.get(actor);
    pending.set(actor, { broken: !!(prev && prev.broken) || !!broken });
    if (!timer) timer = setTimeout(flush, 250);
  }
  function scan(root) {
    if (!root || !root.querySelectorAll) return;
    root.querySelectorAll('img.tweet-av[data-profile-hover-actor]').forEach(function (img) {
      if (isFallback(img.getAttribute('src') || '')) note(img, false);
    });
  }
  function apply(actor, url) {
    const seen = tried.get(actor) || new Set();
    if (seen.has(url)) return false;
    seen.add(url);
    tried.set(actor, seen);
    let swapped = false;
    document.querySelectorAll('img.tweet-av[data-profile-hover-actor]').forEach(function (img) {
      if (img.dataset.profileHoverActor !== actor) return;
      if ((img.getAttribute('src') || '') === url) return;
      img.onerror = function () {
        this.onerror = null;
        this.src = fallback;
        note(this, true);
      };
      img.src = url;
      swapped = true;
    });
    return swapped;
  }
  function retryLater(actor, broken) {
    const n = attempts.get(actor) || 0;
    const max = broken ? 3 : 2;
    if (n >= max) return;
    const delay = n === 0 ? 1500 : (n === 1 ? 6000 : 12000);
    attempts.set(actor, n + 1);
    setTimeout(function () {
      if (resolved.has(actor)) return;
      const prev = pending.get(actor);
      pending.set(actor, { broken: broken || !!(prev && prev.broken) });
      if (!timer) timer = setTimeout(flush, 250);
    }, delay);
  }
  async function flush() {
    timer = 0;
    if (flushing || !pending.size || !window.VAAK_CSRF) return;
    flushing = true;
    const actors = [];
    const broken = [];
    pending.forEach(function (meta, actor) {
      if (actors.length + broken.length >= 40) return;
      pending.delete(actor);
      if (meta.broken) broken.push(actor);
      else actors.push(actor);
    });
    if (!actors.length && !broken.length) { flushing = false; return; }
    try {
      const fd = new FormData();
      fd.set('action', 'avatar_resolve');
      fd.set('csrf', window.VAAK_CSRF);
      actors.forEach(function (actor) { fd.append('actors[]', actor); });
      broken.forEach(function (actor) { fd.append('broken[]', actor); fd.append('actors[]', actor); });
      const res = await fetch('/vaak/?view=home', {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = res.ok ? await res.json() : null;
      const urls = data && data.avatars ? data.avatars : {};
      actors.concat(broken).forEach(function (actor) {
        const url = urls[actor];
        const wasBroken = broken.indexOf(actor) !== -1;
        if (typeof url === 'string' && /^https:\/\//i.test(url) && apply(actor, url)) {
          resolved.add(actor);
          return;
        }
        retryLater(actor, wasBroken);
      });
    } catch (_) {
      actors.concat(broken).forEach(function (actor) { retryLater(actor, broken.indexOf(actor) !== -1); });
    }
    flushing = false;
    if (pending.size && !timer) timer = setTimeout(flush, 250);
  }
  document.addEventListener('error', function (ev) {
    const img = ev.target;
    if (!(img instanceof HTMLImageElement) || !img.classList || !img.classList.contains('tweet-av')) return;
    const src = img.currentSrc || img.src || '';
    if (isFallback(src)) return;
    note(img, true);
  }, true);
  scan(document);
  if (typeof MutationObserver !== 'undefined' && document.body) {
    const mo = new MutationObserver(function (records) {
      records.forEach(function (rec) {
        rec.addedNodes.forEach(function (node) {
          if (!node || node.nodeType !== 1) return;
          if (node.matches && node.matches('img.tweet-av[data-profile-hover-actor]')) {
            if (isFallback(node.getAttribute('src') || '')) note(node, false);
          } else {
            scan(node);
          }
        });
      });
    });
    mo.observe(document.body, { childList: true, subtree: true });
  }
})();
