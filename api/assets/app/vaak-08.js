/* VAAK app script 8. Moved out of api/ap-admin.php (was an inline <script>) so
   browsers cache and precompile it; the shell loads these files in their old order.
*/
// Home feed chips: VAAK (Home) first, then the viewer's pinned Bluesky feeds.
// The row is fetched once per page load after paint and reused for every
// soft-nav; viewers without a Bluesky account get nothing and see nothing.
(function () {
  let chipsHtml = null;
  let chipsNone = false;
  let pending = null;
  function liveView() {
    const items = document.querySelector('section.main #timeline-items');
    if (items && items.dataset.view) return String(items.dataset.view);
    try { return new URL(window.location.href).searchParams.get('view') || 'home'; } catch (e) { return 'home'; }
  }
  function paint(slot, view) {
    if (view === 'home') slot.dataset.active = 'vaak';
    if ((view !== 'home' && view !== 'bluesky') || !chipsHtml) { slot.hidden = true; return; }
    if (slot.dataset.filled !== '1') {
      slot.innerHTML = chipsHtml;
      slot.dataset.filled = '1';
    }
    let active = slot.dataset.active || 'vaak';
    if (active === 'timeline') active = 'following';
    if (view === 'bluesky' && active === 'vaak') active = 'following';
    ensureActiveChip(slot, active);
    slot.querySelectorAll('a[data-chip]').forEach((a) => {
      const on = a.dataset.chip === active;
      a.classList.toggle('btn-primary', on);
      a.classList.toggle('btn-ghost', !on);
      if (on) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
    });
    slot.hidden = false;
    revealActiveChip(slot);
  }
  // A Bluesky feed opened from outside the pinned row (a trending topic,
  // Search > Feeds, Library > Feeds, or a pin newer than the cached row) has
  // no chip, which left Home with nothing picked. Give it a chip of its own
  // at the end of the row with a ✕ inside the pill, plus a note under the
  // row saying it is not pinned and a link back to the feed the viewer came
  // from. The ✕ is its own link joined onto the chip (a link inside a link
  // is not valid HTML). All of it goes again once another chip is live.
  // Last pinned chip (or VAAK) the viewer had open: where ✕ goes back to.
  let lastPinnedChip = 'vaak';
  function ensureActiveChip(slot, active) {
    const row = slot.querySelector('.bsky-feed-tabs');
    if (!row) return;
    row.querySelectorAll('[data-temp-pill]').forEach((p) => {
      if (p.dataset.tempFor !== active) p.remove();
    });
    const note = slot.querySelector('[data-temp-feed-note]');
    if (note && note.dataset.tempFor !== active) note.remove();
    const pinned = Array.prototype.filter.call(row.querySelectorAll('a[data-chip]'), (a) => a.dataset.tempChip !== '1');
    const pinnedKeys = pinned.map((a) => a.dataset.chip);
    if (active === 'vaak' || pinnedKeys.indexOf(active) >= 0) {
      lastPinnedChip = active;
      return;
    }
    const has = Array.prototype.some.call(row.querySelectorAll('a[data-temp-chip]'), (a) => a.dataset.chip === active);
    if (has) return;
    const label = slot.dataset.activeLabel || (active === 'following' ? 'Following' : 'Feed');
    const back = pinnedKeys.indexOf(lastPinnedChip) >= 0 ? lastPinnedChip : 'vaak';
    const backChip = pinned[pinnedKeys.indexOf(back)] || null;
    const backLabel = backChip ? String(backChip.textContent || '').trim() || 'VAAK' : 'VAAK';
    const backHref = (typeof window.vaakSoftViewHref === 'function')
      ? (back === 'vaak' ? window.vaakSoftViewHref('home') : window.vaakSoftViewHref('bluesky', '', { feed: back }))
      : (back === 'vaak' ? '?view=home' : '?view=bluesky&feed=' + encodeURIComponent(back));
    const backLink = (cls, attrs) => {
      const b = document.createElement('a');
      b.className = cls;
      b.href = backHref;
      b.setAttribute('data-vaak-soft-nav', back === 'vaak' ? 'home' : 'bluesky');
      if (back !== 'vaak') b.setAttribute('data-bsky-feed', back);
      Object.keys(attrs).forEach((k) => b.setAttribute(k, attrs[k]));
      return b;
    };

    const a = document.createElement('a');
    a.className = 'btn btn-ghost';
    a.href = (typeof window.vaakSoftViewHref === 'function')
      ? window.vaakSoftViewHref('bluesky', '', { feed: active })
      : '?view=bluesky&feed=' + encodeURIComponent(active);
    a.setAttribute('data-vaak-soft-nav', 'bluesky');
    a.setAttribute('data-bsky-feed', active);
    a.setAttribute('data-chip', active);
    a.setAttribute('data-temp-chip', '1');
    a.setAttribute('title', 'Temporary feed, not pinned');
    a.textContent = label;
    const pill = document.createElement('span');
    pill.className = 'temp-feed-pill';
    pill.setAttribute('data-temp-pill', '1');
    pill.dataset.tempFor = active;
    pill.appendChild(a);
    row.appendChild(pill);

    const x = backLink('temp-feed-close', {
      'data-temp-close': '1',
      'aria-label': 'Close temporary feed, back to ' + backLabel,
      title: 'Close, back to ' + backLabel,
    });
    x.textContent = '✕';
    pill.appendChild(x);

    const n = document.createElement('p');
    n.className = 'temp-feed-note';
    n.setAttribute('data-temp-feed-note', '1');
    n.setAttribute('role', 'status');
    n.dataset.tempFor = active;
    const txt = document.createElement('span');
    txt.textContent = 'You’re viewing “' + label + '”, a temporary feed. It isn’t pinned and goes away when you switch feeds.';
    n.appendChild(txt);
    const bl = backLink('temp-feed-back', {});
    bl.textContent = '← Back to ' + backLabel;
    n.appendChild(bl);
    if (row.nextSibling) slot.insertBefore(n, row.nextSibling); else slot.appendChild(n);
  }
  // Each switch paints a fresh chip row scrolled to the start, so a feed far
  // down a long row ended up selected but off screen. Bring it into view
  // (row only: the page itself never scrolls for this).
  function revealActiveChip(slot) {
    const row = slot.querySelector('.bsky-feed-tabs');
    const chip = row && row.querySelector('a[aria-current="page"]');
    if (!row || !chip) return;
    const fit = () => {
      if (row.scrollWidth <= row.clientWidth + 1) return;
      if (lastRowLeft > 0 && row.scrollLeft === 0) row.scrollLeft = lastRowLeft;
      const left = chip.offsetLeft - row.offsetLeft;
      const right = left + chip.offsetWidth;
      if (left >= row.scrollLeft && right <= row.scrollLeft + row.clientWidth) return;
      row.scrollLeft = Math.max(0, left - (row.clientWidth - chip.offsetWidth) / 2);
    };
    if (typeof requestAnimationFrame === 'function') requestAnimationFrame(fit); else fit();
  }
  // Where the row was scrolled when a chip was tapped, so the next row opens
  // at the same spot instead of jumping back to VAAK.
  let lastRowLeft = 0;
  document.addEventListener('click', (ev) => {
    const a = ev.target && ev.target.closest ? ev.target.closest('.bsky-feed-tabs a[data-chip]') : null;
    const row = a && a.closest('.bsky-feed-tabs');
    if (row) lastRowLeft = row.scrollLeft;
  }, true);
  window.vaakSyncBskyChips = function (viewHint) {
    const slots = document.querySelectorAll('section.main [data-bsky-home-chips]');
    if (!slots.length) return;
    const view = String(viewHint || liveView());
    slots.forEach((slot) => paint(slot, view));
    if (chipsHtml !== null || chipsNone || pending) return;
    pending = fetch('?view=bluesky&partial=1&chips=1', {
      credentials: 'same-origin',
      headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
      cache: 'no-store'
    }).then((res) => (res.ok ? res.text() : ''))
      .then((text) => {
        const t = String(text || '').trim();
        if (t.indexOf('<nav') === 0) chipsHtml = t; else chipsNone = true;
      })
      .catch(() => { chipsNone = true; })
      .finally(() => { pending = null; if (chipsHtml) window.vaakSyncBskyChips(); });
  };
  // Library > Feeds edits change the pinned set: fetch the row again next time.
  window.vaakResetBskyChips = function () {
    chipsHtml = null;
    chipsNone = false;
    document.querySelectorAll('[data-bsky-home-chips]').forEach((slot) => { delete slot.dataset.filled; });
  };
  const boot = () => { try { window.vaakSyncBskyChips(); } catch (e) {} };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
  else boot();
})();
