/* VAAK app script 21. Moved out of api/ap-admin.php (was an inline <script>) so
   browsers cache and precompile it; the shell loads these files in their old order.
*/
(function () {
  if (!window.matchMedia || !window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;
  const card = document.createElement('aside');
  card.className = 'profile-hover-card'; card.hidden = true; card.setAttribute('role', 'dialog');
  card.setAttribute('aria-live', 'polite');
  card.innerHTML = '<div class="profile-hover-head"><span class="profile-hover-spinner" aria-hidden="true"></span><span>Loading profile…</span></div>';
  document.body.appendChild(card);
  let showTimer = 0, hideTimer = 0, requestSeq = 0, anchor = null;
  const SHOW_DELAY_MS = 280;
  const HIDE_DELAY_MS = 110;
  const CARD_GAP_PX = 6;
  function hideNow() {
    clearTimeout(showTimer); clearTimeout(hideTimer);
    card.hidden = true; anchor = null; requestSeq++;
  }
  function position() {
    if (!anchor || card.hidden) return;
    const r = anchor.getBoundingClientRect(), w = card.offsetWidth, h = card.offsetHeight, pad = 12;
    let left = r.right + CARD_GAP_PX;
    if (left + w > innerWidth - pad) left = Math.max(pad, r.left - w - CARD_GAP_PX);
    let top = Math.max(pad, Math.min(r.top, innerHeight - h - pad));
    card.style.left = left + 'px'; card.style.top = top + 'px';
  }
  function node(tag, cls, value) {
    const el = document.createElement(tag); if (cls) el.className = cls;
    if (value !== undefined) el.textContent = value; return el;
  }
  function isInsideHoverUi(node) {
    if (!node || !(node instanceof Element)) return false;
    return !!(node.closest('.profile-hover-card') || node.closest('[data-profile-hover-actor]'));
  }
  function render(data) {
    card.replaceChildren();
    const head = node('div', 'profile-hover-head');
    if (data.avatar && /^https:\/\//i.test(data.avatar)) {
      const img = node('img', 'profile-hover-avatar'); img.src = data.avatar; img.alt = ''; img.referrerPolicy = 'no-referrer'; head.appendChild(img);
    }
    const names = node('div', '');
    names.appendChild(node('div', 'profile-hover-name', data.display_name || data.handle || 'Account'));
    if (data.handle) names.appendChild(node('div', 'meta', data.handle));
    head.appendChild(names); card.appendChild(head);
    const bio = String(data.bio || '');
    if (bio) card.appendChild(node('div', 'profile-hover-bio', bio.length > 220 ? bio.slice(0, 220) + '…' : bio));
    const foot = node('div', 'profile-hover-foot');
    let relation = data.following && data.follows_you ? 'mutual' : data.following ? 'following' : data.follows_you ? 'follows you' : '';
    if (data.platform === 'nostr') relation = data.following ? 'following on Nostr' : 'on Nostr';
    foot.appendChild(node('span', 'meta', relation));
    const actions = node('span', '');
    if (data.platform === 'nostr') {
      // Nostr: open on Nostr in a new tab; Follow is signed by the viewer's
      // signer like the card menu's Follow (data-nostr-act, Nostr card script).
      if (data.profile_url) {
        const link = node('a', 'btn btn-ghost', 'View on Nostr');
        link.href = data.profile_url; link.target = '_blank'; link.rel = 'noopener noreferrer';
        actions.appendChild(link);
      }
      if (data.can_follow && !data.own && /^[0-9a-f]{64}$/.test(String(data.nostr_pubkey || ''))) {
        const button = node('button', 'btn btn-primary', data.following ? 'Unfollow' : 'Follow');
        button.type = 'button';
        button.setAttribute('data-nostr-act', data.following ? 'unfollow' : 'follow');
        button.setAttribute('data-nostr-target', data.nostr_pubkey);
        button.setAttribute('data-nostr-follow-label', 'Follow');
        button.setAttribute('data-nostr-unfollow-label', 'Unfollow');
        button.setAttribute('data-nostr-name', data.display_name || data.handle || '');
        actions.appendChild(button);
      }
      foot.appendChild(actions); card.appendChild(foot); position();
      return;
    }
    if (data.profile_url) { const link = node('a', 'btn btn-ghost', 'View profile'); link.href = data.profile_url; actions.appendChild(link); }
    if (data.can_follow && !data.own) {
      const form = document.createElement('form'); form.method = 'post'; form.action = window.location.pathname + (window.location.search || '');
      form.style.display = 'inline';
      const add = (name, value) => { const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; form.appendChild(input); };
      add('action', data.following ? 'unfollow_remote' : 'follow_remote'); add('actor_id', data.actor); add('return_view', 'home');
      if (window.VAAK_CSRF) add('csrf', window.VAAK_CSRF);
      const button = node('button', 'btn btn-primary', data.following ? 'Unfollow' : (data.follows_you ? 'Follow back' : 'Follow'));
      button.type = 'submit'; form.appendChild(button); actions.appendChild(form);
    }
    foot.appendChild(actions); card.appendChild(foot); position();
  }
  async function load(actor, seq, source, label) {
    try {
      const fd = new FormData(); fd.set('action', 'profile_hover_data'); fd.set('actor', actor);
      if (source) fd.set('source', source);
      if (label) fd.set('label', label);
      if (window.VAAK_CSRF) fd.set('csrf', window.VAAK_CSRF);
      // Hover lookups must not inherit a profile/status query string. Those
      // routes can trigger a fragment renderer before the POST action is
      // dispatched, which made Boosts-tab previews intermittently return the
      // generic unavailable card. Use the stable app endpoint instead.
      const res = await fetch('/vaak/?view=home', {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      });
      if (!res.ok) throw new Error('unavailable');
      const data = await res.json();
      if (seq !== requestSeq || !card.isConnected) return;
      if (data && data.ok) render(data);
      else card.replaceChildren(node('div', 'meta', (data && data.error) || 'Profile preview unavailable'));
    } catch (_) {
      if (seq === requestSeq) card.replaceChildren(node('div', 'meta', 'Profile preview unavailable'));
    }
  }
  function show(el) {
    const actor = el && el.dataset.profileHoverActor;
    if (!actor || !/^https:\/\//i.test(actor)) return;
    clearTimeout(hideTimer); clearTimeout(showTimer);
    showTimer = setTimeout(() => {
      anchor = el; const seq = ++requestSeq;
      card.hidden = false; card.style.left = '12px'; card.style.top = '12px';
      card.innerHTML = '<div class="profile-hover-head"><span class="profile-hover-spinner" aria-hidden="true"></span><span>Loading profile…</span></div>';
      position(); load(actor, seq, el.dataset.profileHoverSource || '', el.dataset.profileHoverLabel || '');
    }, SHOW_DELAY_MS);
  }
  function scheduleHide() {
    clearTimeout(showTimer); clearTimeout(hideTimer);
    hideTimer = setTimeout(hideNow, HIDE_DELAY_MS);
  }
  document.addEventListener('pointerover', ev => {
    const el = ev.target.closest && ev.target.closest('[data-profile-hover-actor]');
    if (el && !el.contains(ev.relatedTarget)) show(el);
  });
  document.addEventListener('pointerout', ev => {
    const el = ev.target.closest && ev.target.closest('[data-profile-hover-actor]');
    if (el && !isInsideHoverUi(ev.relatedTarget)) scheduleHide();
  });
  card.addEventListener('pointerenter', () => clearTimeout(hideTimer));
  card.addEventListener('pointerleave', (ev) => {
    if (!isInsideHoverUi(ev.relatedTarget)) scheduleHide();
  });
  document.addEventListener('pointerdown', (ev) => {
    if (card.hidden) return;
    if (isInsideHoverUi(ev.target)) return;
    hideNow();
  }, true);
  document.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape' && !card.hidden) hideNow();
  });
  window.addEventListener('scroll', () => { if (!card.hidden) position(); }, true);
  window.addEventListener('resize', () => { if (!card.hidden) position(); });
})();
