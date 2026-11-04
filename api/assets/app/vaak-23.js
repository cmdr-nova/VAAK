/* VAAK app script 23. Moved out of api/ap-admin.php (was an inline <script>) so
   browsers cache and precompile it; the shell loads these files in their old order.
*/
// Nostr like / reply / repost / quote / follow on Home cards. The browser's signer extension
// (NIP-07: nos2x, Alby, Nostash…) signs; VAAK never sees the private key.
// The worker builds each event (nostr_prepare) and only relays the exact
// copy the extension signed (nostr_publish). See api/ap-nostr.php.
(function () {
  if (document.documentElement.dataset.nostrActionsBound === '1') return;
  document.documentElement.dataset.nostrActionsBound = '1';

  function toast(text, ok) {
    let el = document.getElementById('nostr-toast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'nostr-toast';
      el.className = 'nostr-toast';
      el.setAttribute('role', 'status');
      el.setAttribute('aria-live', 'polite');
      document.body.appendChild(el);
    }
    el.textContent = text;
    el.classList.toggle('ok', !!ok);
    // Replay the slide-in so a second toast in a row is noticed too.
    el.hidden = true;
    void el.offsetWidth;
    el.hidden = false;
    clearTimeout(el._vaakTimer);
    el._vaakTimer = setTimeout(function () { el.hidden = true; }, 6000);
  }

  async function post(fields) {
    const body = new URLSearchParams(fields);
    body.set('ajax', '1');
    if (window.VAAK_CSRF) body.set('csrf', window.VAAK_CSRF);
    try {
      const res = await fetch('?view=home&ajax=1', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: body
      });
      const text = await res.text();
      let data = null;
      try { data = JSON.parse(text); } catch (e) {}
      if (data && typeof data === 'object') return data;
      // Not JSON: a gateway timeout or a PHP error page. Name the step and
      // the HTTP status so the cause can be found in the server logs.
      return { ok: false, error: 'VAAK did not answer (' + (fields.action || 'request') + ', HTTP ' + res.status + '). Try again.' };
    } catch (e) {}
    return { ok: false, error: 'VAAK did not answer (' + (fields.action || 'request') + ', connection dropped). Try again.' };
  }

  // No extension (an iPhone Home Screen app never loads Safari extensions,
  // and Safari on a Mac often has none): ask the worker to have the user's NIP-46 bunker sign instead. It signs
  // only the event prepared under `nonce` (nostr/bunker.rs).
  const bunkerSigner = {
    vaakBunker: true,
    async signEvent(unsigned, nonce) {
      const res = await post({ action: 'nostr_bunker_sign', nonce: nonce, content: unsigned.content || '' });
      if (res && res.ok && res.event) return res.event;
      const err = new Error((res && res.error) || 'Your Nostr bunker did not sign it.');
      err.vaakMessage = res && res.no_bunker
        ? 'To act on Nostr posts, install a signer extension such as nos2x or Alby (Nostash in Safari on iPhone), or connect a bunker in Settings > Profile.'
        : err.message;
      throw err;
    }
  };

  function signerReady() {
    const signer = window.nostr;
    if (!signer || typeof signer.signEvent !== 'function' || typeof signer.getPublicKey !== 'function') {
      return bunkerSigner;
    }
    return signer;
  }

  async function run(purpose, target, content, reason, extra) {
    const signer = signerReady();
    if (!signer) return null;
    const prepFields = { action: 'nostr_prepare', purpose: purpose, target: target };
    if (reason) prepFields.reason = reason;
    if (extra) Object.assign(prepFields, extra);
    const prep = await post(prepFields);
    if (!prep.ok) { toast(prep.error || 'Could not prepare that.'); return null; }
    return signAndPublish(signer, prep, content);
  }

  async function signAndPublish(signer, prep, content) {
    let pubkey = '';
    if (signer.vaakBunker) {
      pubkey = String(prep.pubkey || '');
    } else {
      try { pubkey = String(await signer.getPublicKey() || '').toLowerCase(); } catch (e) {}
      // An extension that is installed but has no key, is locked, or holds
      // another key can't sign this. Use the bunker when one is connected
      // (prep.bunker), before the extension shows any signing prompt.
      if ((!pubkey || pubkey !== prep.pubkey) && prep.bunker) {
        signer = bunkerSigner;
        pubkey = String(prep.pubkey || '');
      }
    }
    if (!pubkey) { toast('Your signer extension did not share its key.'); return null; }
    if (pubkey !== prep.pubkey) {
      toast('Your signer extension uses a different key than the npub linked in Settings > Profile.');
      return null;
    }
    const unsigned = {
      kind: prep.template.kind,
      created_at: Math.floor(Date.now() / 1000),
      tags: prep.template.tags,
      // A quote ends with its nostr:note1… link; the worker checks it.
      content: prep.needs_content ? content + (prep.suffix || '') : prep.template.content
    };
    let signed = null;
    let signError = '';
    try {
      if (signer.vaakBunker) toast('Asking your Nostr bunker to sign…');
      signed = await (signer.vaakBunker ? signer.signEvent(unsigned, prep.nonce) : signer.signEvent(unsigned));
    } catch (e) { signError = (e && e.vaakMessage) || ''; }
    if (!signed || typeof signed !== 'object') { toast(signError || 'Signing was cancelled.'); return null; }
    const done = await post({ action: 'nostr_publish', nonce: prep.nonce, event: JSON.stringify(signed) });
    if (!done.ok) { toast(done.error || 'No relay accepted it.'); return null; }
    return done;
  }

  // The composer's "Nostr" box: once VAAK has accepted a public, top-level
  // post, sign the same post as a kind 1 note. The publication queue may
  // still be saving it, so a `retry` answer waits and asks again.
  window.vaakNostrCrosspost = async function (noteId) {
    const signer = signerReady();
    if (!signer || !noteId) return null;
    let prep = null;
    for (let attempt = 0; attempt < 6; attempt++) {
      if (attempt > 0) await new Promise(function (r) { setTimeout(r, 1500 * attempt); });
      prep = await post({ action: 'nostr_prepare', purpose: 'post', target: noteId });
      if (prep.ok || !prep.retry) break;
    }
    if (!prep || !prep.ok) { toast((prep && prep.error) || 'Could not prepare the Nostr copy.'); return null; }
    const done = await signAndPublish(signer, prep, '');
    if (done) toast('Also posted to Nostr.');
    return done;
  };

  function sameTarget(act, target) {
    const sel = '[data-nostr-act="' + act + '"][data-nostr-target="' + (window.CSS && CSS.escape ? CSS.escape(target) : target) + '"]';
    return Array.prototype.slice.call(document.querySelectorAll(sel));
  }

  function setLiked(target, on) {
    sameTarget('like', target).forEach(function (btn) {
      btn.classList.toggle('on', on);
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
      const label = on ? 'Unlike on Nostr' : 'Like on Nostr';
      btn.title = label;
      btn.setAttribute('aria-label', label);
      const icon = document.createElement('i');
      icon.className = (on ? 'ph-fill' : 'ph') + ' ph-heart';
      icon.setAttribute('aria-hidden', 'true');
      btn.replaceChildren(icon);
    });
  }

  function setFollowing(target, on) {
    sameTarget('follow', target).concat(sameTarget('unfollow', target)).forEach(function (btn) {
      btn.setAttribute('data-nostr-act', on ? 'unfollow' : 'follow');
      // Menus that name the person (a repost names author and booster)
      // and the hover card carry their own labels.
      btn.textContent = on
        ? (btn.getAttribute('data-nostr-unfollow-label') || 'Unfollow on Nostr')
        : (btn.getAttribute('data-nostr-follow-label') || 'Follow on Nostr');
    });
  }

  function setReposted(target, on) {
    sameTarget('repost', target).forEach(function (btn) {
      btn.classList.toggle('on', on);
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
      const label = on ? 'Undo repost' : 'Repost on Nostr';
      btn.title = label;
      btn.setAttribute('aria-label', label);
    });
  }

  // Cards by an author leave the page once they're muted, hidden or
  // blocked; Settings rows go once they're undone.
  function dropAuthor(author) {
    sameTarget('mute', author).forEach(function (btn) {
      const card = btn.closest('article');
      if (card) card.remove();
    });
  }

  function dropRow(btn) {
    const row = btn.closest('[data-nostr-row]');
    if (!row) return;
    row.querySelectorAll('[data-nostr-act], [data-nostr-vaak]').length > 1 ? btn.remove() : row.remove();
  }

  const REPORT_TYPES = [['spam', 'Spam'], ['nudity', 'Nudity'], ['profanity', 'Hateful or abusive'], ['illegal', 'Illegal'], ['impersonation', 'Impersonation'], ['malware', 'Malware or scam'], ['other', 'Something else']];

  function openReport(btn, target) {
    const card = btn.closest('article');
    if (!card) return;
    const open = card.querySelector('form.nostr-report');
    if (open) { open.remove(); return; }
    const menu = btn.closest('details');
    if (menu) menu.open = false;
    const form = document.createElement('form');
    form.className = 'nostr-report';
    // In-place JSON work: keeps the page-level Saving… pill away.
    form.setAttribute('data-ajax-submit', '1');
    const pick = document.createElement('select');
    pick.setAttribute('aria-label', 'Why are you reporting this?');
    REPORT_TYPES.forEach(function (t) {
      const o = document.createElement('option');
      o.value = t[0];
      o.textContent = t[1];
      pick.appendChild(o);
    });
    const send = document.createElement('button');
    send.type = 'submit';
    send.className = 'btn btn-primary';
    send.textContent = 'Report on Nostr';
    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'btn btn-ghost';
    cancel.textContent = 'Cancel';
    cancel.addEventListener('click', function () { form.remove(); });
    form.append(pick, send, cancel);
    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      send.disabled = true;
      const res = await run('report', target, '', pick.value);
      send.disabled = false;
      if (!res) return;
      const done = document.createElement('div');
      done.className = 'meta nostr-reply-done';
      done.textContent = 'Reported on Nostr. Relays and clients that read reports will see it.';
      form.replaceWith(done);
    });
    card.appendChild(form);
    pick.focus();
  }

  // Zaps (NIP-57): pick an amount, the signer signs a zap request, the
  // worker gets an invoice from the author's Lightning wallet provider, and
  // the viewer's own wallet pays it: a WebLN extension (Alby…) right here,
  // or any wallet through the QR code / lightning: link. VAAK never touches
  // the money or a wallet key.
  const ZAP_AMOUNTS = [21, 100, 1000];
  const QR_LIB = 'https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js';
  let qrLoading = null;

  function loadQr() {
    if (window.qrcode) return Promise.resolve(true);
    if (!qrLoading) {
      qrLoading = new Promise(function (resolve) {
        const el = document.createElement('script');
        el.src = QR_LIB;
        el.async = true;
        el.onload = function () { resolve(!!window.qrcode); };
        el.onerror = function () { qrLoading = null; resolve(false); };
        document.head.appendChild(el);
      });
    }
    return qrLoading;
  }

  function satsText(n) {
    return Number(n).toLocaleString() + (Number(n) === 1 ? ' sat' : ' sats');
  }

  async function payWithWebln(invoice) {
    const wallet = window.webln;
    if (!wallet || typeof wallet.sendPayment !== 'function') return false;
    try {
      if (typeof wallet.enable === 'function') await wallet.enable();
      const paid = await wallet.sendPayment(invoice);
      return !!(paid && paid.preimage);
    } catch (e) {
      return false;
    }
  }

  function zapDone(el, sats) {
    const done = document.createElement('div');
    done.className = 'meta nostr-reply-done';
    done.textContent = '⚡ Zapped ' + satsText(sats) + '.';
    el.replaceWith(done);
  }

  // Ask VAAK whether the invoice was paid (the provider's verify URL or its
  // zap receipt on Nostr) until it is, the panel is closed, or 10 minutes
  // pass. One question at a time; a hidden tab doesn't ask.
  async function watchPaid(panel, watch, sats) {
    const until = Date.now() + 10 * 60 * 1000;
    let wait = 3000;
    while (panel.isConnected && Date.now() < until) {
      await new Promise(function (r) { setTimeout(r, wait); });
      wait = Math.min(wait + 1000, 8000);
      if (!panel.isConnected) return;
      if (document.hidden) continue;
      const res = await post({ action: 'nostr_zap_status', watch: watch });
      if (!panel.isConnected) return;
      if (res && res.ok && res.paid) { zapDone(panel, sats); return; }
      if (res && res.expired) return;
    }
  }

  async function showInvoice(form, res) {
    const invoice = String(res.invoice || '');
    const panel = document.createElement('div');
    panel.className = 'nostr-zap-pay';
    const head = document.createElement('div');
    head.className = 'meta';
    head.textContent = 'Pay ' + satsText(res.sats) + ' with any Lightning wallet. The zap shows on Nostr once it is paid'
      + (res.watch ? ', and this closes by itself.' : '.');
    const qrBox = document.createElement('div');
    qrBox.className = 'nostr-zap-qr';
    qrBox.hidden = true;
    const row = document.createElement('div');
    row.className = 'nostr-reply-row';
    const open = document.createElement('a');
    open.className = 'btn btn-primary';
    open.href = 'lightning:' + invoice;
    open.textContent = 'Open in wallet';
    const copy = document.createElement('button');
    copy.type = 'button';
    copy.className = 'btn btn-ghost';
    copy.textContent = 'Copy invoice';
    copy.addEventListener('click', async function () {
      try { await navigator.clipboard.writeText(invoice); copy.textContent = 'Copied'; } catch (e) { toast('Could not copy. Long-press the QR code or use Open in wallet.'); }
    });
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn btn-ghost';
    close.textContent = 'Close';
    close.addEventListener('click', function () { panel.remove(); });
    row.append(open, copy, close);
    panel.append(head, qrBox, row);
    form.replaceWith(panel);
    if (res.watch) watchPaid(panel, String(res.watch), res.sats);
    if (await loadQr()) {
      try {
        const qr = window.qrcode(0, 'L');
        qr.addData(('lightning:' + invoice).toUpperCase(), 'Alphanumeric');
        qr.make();
        qrBox.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
        qrBox.hidden = false;
      } catch (e) {}
    }
  }

  function openZap(btn, target) {
    const card = btn.closest('article');
    if (!card) return;
    const open = card.querySelector('form.nostr-zap, .nostr-zap-pay');
    if (open) { open.remove(); return; }
    const form = document.createElement('form');
    form.className = 'nostr-zap';
    // In-place JSON work: keeps the page-level Saving… pill away.
    form.setAttribute('data-ajax-submit', '1');
    const amounts = document.createElement('div');
    amounts.className = 'nostr-zap-row';
    const custom = document.createElement('input');
    custom.type = 'number';
    custom.min = '1';
    custom.max = '1000000';
    custom.step = '1';
    custom.inputMode = 'numeric';
    custom.value = String(ZAP_AMOUNTS[0]);
    custom.setAttribute('aria-label', 'Sats');
    const chips = ZAP_AMOUNTS.map(function (n) {
      const chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'btn btn-ghost nostr-zap-amount' + (n === ZAP_AMOUNTS[0] ? ' on' : '');
      chip.textContent = '⚡ ' + n.toLocaleString();
      chip.addEventListener('click', function () {
        custom.value = String(n);
        chips.forEach(function (c) { c.classList.toggle('on', c === chip); });
      });
      return chip;
    });
    custom.addEventListener('input', function () {
      chips.forEach(function (c, i) { c.classList.toggle('on', String(ZAP_AMOUNTS[i]) === custom.value); });
    });
    const unit = document.createElement('span');
    unit.className = 'meta';
    unit.textContent = 'sats';
    amounts.append.apply(amounts, chips.concat([custom, unit]));
    const comment = document.createElement('input');
    comment.type = 'text';
    comment.maxLength = 280;
    comment.placeholder = 'Comment (optional)';
    comment.setAttribute('aria-label', 'Zap comment');
    const row = document.createElement('div');
    row.className = 'nostr-reply-row';
    const send = document.createElement('button');
    send.type = 'submit';
    send.className = 'btn btn-primary';
    send.textContent = 'Zap';
    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'btn btn-ghost';
    cancel.textContent = 'Cancel';
    cancel.addEventListener('click', function () { form.remove(); });
    row.append(send, cancel);
    form.append(amounts, comment, row);
    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      const sats = parseInt(custom.value, 10);
      if (!(sats >= 1 && sats <= 1000000)) { custom.focus(); toast('Pick an amount from 1 to 1,000,000 sats.'); return; }
      send.disabled = true;
      const res = await run('zap', target, '', '', { zap_sats: String(sats), zap_comment: comment.value.trim() });
      send.disabled = false;
      if (!res || !res.invoice) return;
      if (await payWithWebln(String(res.invoice))) {
        zapDone(form, res.sats);
        return;
      }
      await showInvoice(form, res);
    });
    card.appendChild(form);
    custom.focus();
  }

  // Hide on VAAK and the admin Block: no signing, VAAK only.
  async function vaakOnly(btn, action, target) {
    if (action === 'nostr_block' && !window.confirm('Block this Nostr account on VAAK for everyone? Their posts stop showing for all users.')) return;
    const res = await post({ action: action, target: target });
    if (!res.ok) { toast(res.error || 'Could not save that.'); return; }
    if (action === 'nostr_hide' || action === 'nostr_block') {
      dropAuthor(target);
      toast(action === 'nostr_hide' ? 'Hidden on VAAK. Nothing was sent to Nostr.' : 'Blocked on VAAK for everyone.');
    } else {
      dropRow(btn);
      toast(action === 'nostr_unhide' ? 'Unhidden. Their posts come back on the next refresh.' : 'Unblocked.');
    }
  }

  // Reply and quote share one inline box under the card.
  function openCompose(btn, target, mode) {
    const card = btn.closest('article');
    if (!card) return;
    const open = card.querySelector('form.nostr-reply');
    if (open) {
      const same = open.dataset.mode === mode;
      open.remove();
      if (same) return;
    }
    const quote = mode === 'quote';
    const form = document.createElement('form');
    form.className = 'nostr-reply';
    // In-place JSON work: keeps the page-level Saving… pill away.
    form.setAttribute('data-ajax-submit', '1');
    form.dataset.mode = mode;
    const box = document.createElement('textarea');
    box.rows = 3;
    box.maxLength = 7900;
    box.placeholder = quote ? 'Quote on Nostr…' : 'Reply on Nostr…';
    box.setAttribute('aria-label', quote ? 'Quote on Nostr' : 'Reply on Nostr');
    const row = document.createElement('div');
    row.className = 'nostr-reply-row';
    const send = document.createElement('button');
    send.type = 'submit';
    send.className = 'btn btn-primary';
    send.textContent = quote ? 'Quote' : 'Reply';
    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'btn btn-ghost';
    cancel.textContent = 'Cancel';
    cancel.addEventListener('click', function () { form.remove(); });
    row.append(send, cancel);
    form.append(box, row);
    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      const text = box.value.trim();
      if (!text) { box.focus(); return; }
      send.disabled = true;
      const res = await run(mode, target, text);
      send.disabled = false;
      if (!res) return;
      const note = document.createElement('div');
      note.className = 'meta nostr-reply-done';
      note.append(quote ? 'Quoted on Nostr. It shows on Local and your profile after a refresh. ' : 'Replied on Nostr. ');
      if (res.note) {
        const link = document.createElement('a');
        link.href = 'https://njump.me/' + encodeURIComponent(res.note);
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.textContent = 'Open it';
        note.append(link);
      }
      form.replaceWith(note);
    });
    card.appendChild(form);
    box.focus();
  }

  // Settings > Profile: the Lightning address in your Nostr profile.
  async function saveLightning(btn) {
    const input = document.getElementById('nostr-lightning');
    if (!input || btn.dataset.nostrBusy === '1') return;
    const address = input.value.trim().toLowerCase();
    if (address && !/^[a-z0-9._+-]+@[a-z0-9.-]+\.[a-z0-9-]+$/.test(address)) {
      toast('That isn\'t a Lightning address. It looks like name@wallet.com.');
      input.focus();
      return;
    }
    btn.dataset.nostrBusy = '1';
    btn.disabled = true;
    try {
      const res = await run('lightning', '', '', '', { lightning: address });
      if (!res) return;
      input.value = address;
      if (!address) toast('Lightning address removed from your Nostr profile.');
      else if (res.zaps) toast('Saved to your Nostr profile. Zaps to your posts go to ' + address + '.');
      else toast('Saved, but this wallet doesn\'t send Nostr zap receipts, so zaps won\'t show in Notifications.');
    } finally {
      btn.dataset.nostrBusy = '';
      btn.disabled = false;
    }
  }

  // Library > Collections: follow picked members of a Nostr follow pack in
  // one signed kind 3 edit (ap_nostr_pack_html, nostr/pack.rs).
  function packPicks(form) {
    return Array.prototype.slice.call(form.querySelectorAll('input[name="nostr_pack_pick"]:checked:not(:disabled)'));
  }

  function packCount(form) {
    const n = packPicks(form).length;
    const max = parseInt(form.getAttribute('data-nostr-pack-max') || '150', 10);
    const count = form.querySelector('[data-nostr-pack-count]');
    if (count) count.textContent = String(n);
    const btn = form.querySelector('[data-nostr-pack-follow]');
    if (btn) btn.disabled = n === 0 || n > max;
    const all = form.querySelector('[data-nostr-pack-all]');
    if (all) {
      const open = form.querySelectorAll('input[name="nostr_pack_pick"]:not(:disabled)').length;
      all.checked = n > 0 && n === Math.min(open, max);
      all.indeterminate = n > 0 && !all.checked;
    }
  }

  document.addEventListener('change', function (event) {
    const form = event.target.closest && event.target.closest('form[data-nostr-pack]');
    if (!form) return;
    if (event.target.matches('[data-nostr-pack-all]')) {
      const max = parseInt(form.getAttribute('data-nostr-pack-max') || '150', 10);
      let left = event.target.checked ? max : 0;
      form.querySelectorAll('input[name="nostr_pack_pick"]:not(:disabled)').forEach(function (box) {
        box.checked = left > 0;
        if (left > 0) left--;
      });
    }
    packCount(form);
  });

  async function followPack(btn) {
    const form = btn.closest('form[data-nostr-pack]');
    if (!form || btn.dataset.nostrBusy === '1') return;
    const boxes = packPicks(form);
    if (!boxes.length) { toast('Pick at least one person to follow.'); return; }
    btn.dataset.nostrBusy = '1';
    btn.disabled = true;
    try {
      toast('Reading your current Nostr follow list…');
      const res = await run('follow_pack', form.getAttribute('data-nostr-pack') || '', '', '', {
        picks: boxes.map(function (b) { return b.value; }).join(',')
      });
      if (!res) return;
      const added = new Set((res.added || []).map(function (k) { return String(k).toLowerCase(); }));
      boxes.forEach(function (box) {
        if (!added.has(box.value)) return;
        box.checked = false;
        box.disabled = true;
        const who = box.closest('.nostr-pack-member');
        const line = who && who.querySelector('.who');
        if (line && !who.querySelector('[data-nostr-pack-tag]')) {
          const tag = document.createElement('span');
          tag.className = 'tag';
          tag.setAttribute('data-nostr-pack-tag', '1');
          tag.textContent = 'following';
          line.after(' ', tag);
        }
      });
      const n = added.size;
      toast('Followed ' + n + (n === 1 ? ' person' : ' people') + ' on Nostr. Their posts reach Home after the next Nostr sync.');
    } finally {
      btn.dataset.nostrBusy = '';
      packCount(form);
    }
  }

  document.addEventListener('click', async function (event) {
    const packBtn = event.target.closest('[data-nostr-pack-follow]');
    if (packBtn) { event.preventDefault(); await followPack(packBtn); return; }
    const lnBtn = event.target.closest('[data-nostr-lightning-save]');
    if (lnBtn) { event.preventDefault(); await saveLightning(lnBtn); return; }
    const vaakBtn = event.target.closest('[data-nostr-vaak]');
    if (vaakBtn) {
      event.preventDefault();
      const vTarget = (vaakBtn.getAttribute('data-nostr-target') || '').toLowerCase();
      if (!/^[0-9a-f]{64}$/.test(vTarget) || vaakBtn.dataset.nostrBusy === '1') return;
      vaakBtn.dataset.nostrBusy = '1';
      try { await vaakOnly(vaakBtn, vaakBtn.getAttribute('data-nostr-vaak') || '', vTarget); } finally { vaakBtn.dataset.nostrBusy = ''; }
      return;
    }
    const btn = event.target.closest('[data-nostr-act]');
    if (!btn) return;
    event.preventDefault();
    const act = btn.getAttribute('data-nostr-act') || '';
    const target = (btn.getAttribute('data-nostr-target') || '').toLowerCase();
    if (!/^[0-9a-f]{64}$/.test(target)) return;
    if (act === 'reply' || act === 'quote') { openCompose(btn, target, act); return; }
    if (act === 'report') { openReport(btn, target); return; }
    if (act === 'zap') { openZap(btn, target); return; }
    if (act === 'delete' && !window.confirm('Delete this note on Nostr? Relays are asked to drop it, but copies elsewhere may stay.')) return;
    if (btn.dataset.nostrBusy === '1') return;
    btn.dataset.nostrBusy = '1';
    // Follows wait on relays (VAAK re-reads your follow list first), so say
    // right away that it started; the done toast replaces this one.
    const who = (btn.getAttribute('data-nostr-name') || '').trim();
    if (act === 'follow' || act === 'unfollow') {
      toast((act === 'follow' ? 'Following ' : 'Unfollowing ') + (who || 'them') + ' on Nostr…');
    }
    try {
      const res = await run(act, target, '');
      if (!res) return;
      if (res.purpose === 'like' || res.purpose === 'unlike') {
        setLiked(target, res.purpose === 'like');
      } else if (res.purpose === 'follow' || res.purpose === 'unfollow') {
        setFollowing(target, res.purpose === 'follow');
        toast(res.purpose === 'follow'
          ? (who ? 'Followed ' + who + ' on Nostr.' : 'Followed on Nostr.')
          : (who ? 'Unfollowed ' + who + ' on Nostr.' : 'Unfollowed on Nostr.') + ' Their posts leave Home on the next refresh.', true);
      } else if (res.purpose === 'repost' || res.purpose === 'unrepost') {
        setReposted(target, res.purpose === 'repost');
        toast(res.purpose === 'repost' ? 'Reposted on Nostr and boosted on VAAK.' : 'Repost undone.', true);
      } else if (res.purpose === 'delete') {
        const card = btn.closest('article');
        if (card) card.remove();
        toast('Deleted on Nostr.', true);
      } else if (res.purpose === 'mute') {
        dropAuthor(target);
        toast('Muted on Nostr. Other Nostr apps that read your mute list hide them too.', true);
      } else if (res.purpose === 'unmute') {
        dropRow(btn);
        toast('Unmuted on Nostr.', true);
      }
    } finally {
      btn.dataset.nostrBusy = '';
    }
  });
})();
