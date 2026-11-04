/* VAAK app script 15. Moved out of api/ap-admin.php (was an inline <script>) so
   browsers cache and precompile it; the shell loads these files in their old order.
   Request values are in VAAK_BOOT.s15, filled by an inline script right before it. */
(function () {
  const modal = document.getElementById('compose-modal');
  const fab = document.getElementById('compose-fab');
  const closeBtn = document.getElementById('compose-modal-close');
  const input = document.getElementById('compose-media-input');
  const previews = document.getElementById('compose-media-previews');
  const form = document.getElementById('compose-form');
  (function bindComposerPressFeedback() {
    const pressSel = '.compose-fab, .compose-tool, .compose-inline-panel .btn, .compose-modal__panel .btn, .compose-modal__close, .alt-modal .btn';
    const down = (e) => {
      if (e.pointerType === 'mouse' && e.button !== 0) return;
      const el = e.target && e.target.closest ? e.target.closest(pressSel) : null;
      if (!el || el.disabled || el.getAttribute('aria-disabled') === 'true') return;
      el.classList.add('is-pressed');
      if (window.vaakHaptic) {
        window.vaakHaptic(el.classList.contains('btn-primary') ? 10 : 6);
      }
    };
    const up = () => {
      document.querySelectorAll(pressSel).forEach((el) => el.classList.remove('is-pressed'));
    };
    document.addEventListener('pointerdown', down, { capture: true, passive: true });
    document.addEventListener('pointerup', up, { capture: true, passive: true });
    document.addEventListener('pointercancel', up, { capture: true, passive: true });
  })();
  const altModal = document.getElementById('alt-modal');
  const altText = document.getElementById('alt-modal-text');
  const altPreview = document.getElementById('alt-modal-preview');
  const altCount = document.getElementById('alt-modal-count');
  const altSave = document.getElementById('alt-modal-save');
  const altCancel = document.getElementById('alt-modal-cancel');
  const altClose = document.getElementById('alt-modal-close');
  const altAiBtn = document.getElementById('alt-modal-ai');
  const altAiStatus = document.getElementById('alt-modal-ai-status');
  const MAX = 4;
  // Same caps as ap_media_ingest_upload, shared with paste so the picker,
  // drag-and-drop, and clipboard all reject before a wasted upload.
  function mediaLimitError(file) {
    return window.vaakPasteMedia.mediaLimitError(file);
  }
  const COMPOSE_LS_KEY = 'vaak-compose-draft-v1';
  const COMPOSE_VISIBILITY_KEY = 'vaak-compose-visibility-v1:' + VAAK_BOOT.s15[0];
  const COMPOSE_HAS_CONTEXT = VAAK_BOOT.s15[1];
  function bindComposeVisibilityPreference() {
    if (!form) return;
    const visibility = form.querySelector('select[name="visibility"]');
    if (!visibility || visibility.dataset.preferenceBound === '1') return;
    visibility.dataset.preferenceBound = '1';
    if (!COMPOSE_HAS_CONTEXT) {
      try {
        const stored = localStorage.getItem(COMPOSE_VISIBILITY_KEY);
        if (stored && Array.from(visibility.options).some((option) => option.value === stored && !option.disabled)) {
          visibility.value = stored;
        }
      } catch (e) {}
    }
    visibility.addEventListener('change', () => {
      try { localStorage.setItem(COMPOSE_VISIBILITY_KEY, visibility.value || 'public'); } catch (e) {}
    });
  }
  bindComposeVisibilityPreference();
  const timelineFeed = document.querySelector('.feed');
  const timelineItems = document.getElementById('timeline-items');
  // Home / Local / Federated share one composer panel: inline at top of feed,
  // or pop-out modal for reply/quote/edit. Must be moved back to the slot on close.
  // Recompute from the live timeline node — soft-nav can leave the page on
  // outbox/favourites while this script (and a stale const) still thinks it is Home.
  function supportsInlineComposerNow() {
    const items = document.getElementById('timeline-items') || timelineItems;
    const feed = document.querySelector('.feed') || timelineFeed;
    return !!(modal && feed && items
      && ['home', 'local', 'feed'].includes(items.dataset.view || ''));
  }
  const supportsInlineComposer = supportsInlineComposerNow();
  function getComposePanel() {
    return document.querySelector('.compose-modal__panel');
  }
  function isComposerInline() {
    const panel = getComposePanel();
    return !!(panel && panel.classList.contains('compose-inline-panel'));
  }
  function composeIdentityLabel() {
    const user = String(window.VAAK_USERNAME || '').trim().replace(/^@+/, '');
    if (user && user.toLowerCase() !== 'guest') return '@' + user;
    const handle = String(window.VAAK_HANDLE || '').trim();
    if (handle) return handle.charAt(0) === '@' ? handle : ('@' + handle);
    const key = String(window.VAAK_ACTOR_KEY || '').trim().replace(/^@+/, '');
    if (key && key !== '__guest__') return '@' + key;
    return '';
  }
  function ensureComposeAsLine(composeForm) {
    if (!composeForm) return null;
    let asMeta = composeForm.querySelector('.compose-as-line');
    const label = composeIdentityLabel();
    if (!asMeta) {
      asMeta = document.createElement('div');
      asMeta.className = 'meta compose-as-line';
      asMeta.style.marginBottom = '.5rem';
      const firstField = composeForm.querySelector('input[name="spoiler_text"], .compose-textarea-wrap, textarea[name="content"]');
      if (firstField) composeForm.insertBefore(asMeta, firstField);
      else composeForm.insertBefore(asMeta, composeForm.firstChild);
    }
    // Keep non-bold siblings (inline Audience select) when repairing the label.
    let bold = asMeta.querySelector(':scope > b');
    if (!bold) bold = asMeta.querySelector('b');
    if (!bold) {
      const keep = Array.prototype.slice.call(asMeta.childNodes).filter((n) => {
        return !(n.nodeType === 1 && String(n.tagName || '').toLowerCase() === 'b');
      });
      asMeta.textContent = '';
      asMeta.appendChild(document.createTextNode('As '));
      bold = document.createElement('b');
      bold.style.color = 'var(--primary)';
      asMeta.appendChild(bold);
      keep.forEach((n) => {
        if (n.nodeType === 3 && !String(n.textContent || '').trim()) return;
        if (n.nodeType === 3 && /^As\s*$/.test(String(n.textContent || ''))) return;
        asMeta.appendChild(n);
      });
    }
    const cur = (bold.textContent || '').trim();
    if (label !== '') {
      bold.textContent = label;
    } else if (cur === '' || cur === '@') {
      bold.textContent = '@';
    }
    return asMeta;
  }
  function applyComposerToolbarChrome(panel) {
    if (!panel) return;
    const composeForm = panel.querySelector('#compose-form');
    const composeActions = composeForm && composeForm.querySelector('.composer-actions');
    if (!composeForm || !composeActions) return;
    panel.classList.add('compose-tools-ready');

    // Keep the audience selector beside "As @handle" in both the inline feed
    // composer and the pop-out composer. Previously this only ran for the
    // Home/Local/Federated inline panel, leaving profile-launched pop-outs with
    // Audience below the text box.
    {
      const asMeta = ensureComposeAsLine(composeForm);
      const visibility = composeForm.querySelector('#compose-visibility');
      const visibilityWrap = visibility && visibility.closest('label');
      const isEditable = visibilityWrap && getComputedStyle(visibilityWrap).display !== 'none';
      if (asMeta && visibilityWrap && isEditable && !visibilityWrap.classList.contains('compose-shared-visibility')) {
        visibilityWrap.classList.add('compose-shared-visibility');
        visibilityWrap.childNodes.forEach((node) => {
          if (node.nodeType === Node.TEXT_NODE) node.textContent = '';
        });
        asMeta.appendChild(document.createTextNode(' '));
        asMeta.appendChild(visibilityWrap);
        if (panel.classList.contains('compose-inline-panel')) {
          visibilityWrap.classList.add('compose-inline-visibility');
        }
      }
      if (panel.classList.contains('compose-inline-panel')) {
      // Manual object/actor targets belong to reply/quote pop-outs, not the
      // top-of-feed composer — hide (don't remove) so Reply can reopen them.
      ['#compose-in-reply-to', '#compose-to-actor'].forEach((sel) => {
        const field = composeForm.querySelector(sel);
        if (field) {
          field.dataset.inlineHidden = '1';
          field.style.display = 'none';
        }
      });
      }
    }

    if (composeForm.querySelector('.compose-inline-tools')) return;
    const tools = document.createElement('div');
    tools.className = 'compose-inline-tools';
    composeForm.insertBefore(tools, composeActions);
    const emojiWrap = composeForm.querySelector('.compose-emoji-wrap');
    if (emojiWrap) {
      const emojiBtn = emojiWrap.querySelector('#compose-emoji-toggle');
      if (emojiBtn) {
        emojiBtn.className = 'compose-tool';
        emojiBtn.innerHTML = '<i class="ph ph-smiley" aria-hidden="true"></i>';
        emojiBtn.title = 'Add emoji';
        emojiBtn.setAttribute('aria-label', 'Add emoji');
      }
      tools.appendChild(emojiWrap);
    }
    const sensitiveLabel = composeForm.querySelector('.composer-check');
    if (sensitiveLabel) {
      const sensitiveInput = sensitiveLabel.querySelector('input[name="sensitive"]');
      const sensitiveBtn = document.createElement('button');
      sensitiveBtn.type = 'button';
      sensitiveBtn.className = 'compose-tool';
      sensitiveBtn.innerHTML = '<i class="ph ph-eye-slash" aria-hidden="true"></i>';
      sensitiveBtn.title = 'Mark as sensitive';
      sensitiveBtn.setAttribute('aria-label', 'Mark as sensitive');
      sensitiveBtn.setAttribute('aria-pressed', sensitiveInput && sensitiveInput.checked ? 'true' : 'false');
      sensitiveBtn.addEventListener('click', () => {
        if (!sensitiveInput) return;
        sensitiveInput.checked = !sensitiveInput.checked;
        sensitiveBtn.setAttribute('aria-pressed', sensitiveInput.checked ? 'true' : 'false');
      });
      sensitiveLabel.innerHTML = '';
      if (sensitiveInput) sensitiveLabel.appendChild(sensitiveInput);
      sensitiveLabel.appendChild(sensitiveBtn);
      tools.appendChild(sensitiveLabel);
    }
    const mediaInputForToolbar = composeForm.querySelector('#compose-media-input');
    const mediaLabel = mediaInputForToolbar && mediaInputForToolbar.closest('label');
    if (mediaLabel) {
      mediaLabel.classList.add('compose-media-tool');
      const mediaInput = mediaInputForToolbar;
      const mediaBtn = document.createElement('button');
      mediaBtn.type = 'button';
      mediaBtn.className = 'compose-tool';
      mediaBtn.innerHTML = '<i class="ph ph-image" aria-hidden="true"></i>';
      mediaBtn.title = 'Add media, or paste an image';
      mediaBtn.setAttribute('aria-label', 'Add media, or paste an image');
      mediaBtn.addEventListener('click', () => { if (mediaInput) mediaInput.click(); });
      mediaLabel.insertBefore(mediaBtn, mediaInput);
      tools.appendChild(mediaLabel);
    }
    const pollBtn = document.createElement('button');
    pollBtn.type = 'button';
    pollBtn.className = 'compose-tool';
    pollBtn.id = 'compose-poll-toggle';
    pollBtn.innerHTML = '<i class="ph ph-chart-bar" aria-hidden="true"></i>';
    pollBtn.title = 'Create poll';
    pollBtn.setAttribute('aria-label', 'Create poll');
    pollBtn.setAttribute('aria-pressed', 'false');
    pollBtn.addEventListener('click', () => {
      if (typeof window.vaakToggleComposePoll === 'function') {
        window.vaakToggleComposePoll();
      }
    });
    tools.appendChild(pollBtn);
    if (typeof window.vaakSyncComposePollChrome === 'function') {
      window.vaakSyncComposePollChrome();
    }
    const recordBtnInline = composeForm.querySelector('#compose-record-btn');
    if (recordBtnInline) {
      recordBtnInline.className = 'compose-tool';
      recordBtnInline.title = 'Record audio (up to 60 seconds)';
      tools.appendChild(recordBtnInline);
    }
  }
  // Back-compat alias used elsewhere in this script.
  function applyInlineComposerChrome(panel) {
    applyComposerToolbarChrome(panel);
  }
  function placeComposerInline() {
    const canInline = supportsInlineComposerNow();
    if (!canInline || !modal) return;
    const liveItems = document.getElementById('timeline-items');
    const liveFeed = document.querySelector('.feed');
    if (!liveFeed || !liveItems) return;
    const panel = getComposePanel();
    if (!panel) return;
    panel.classList.add('compose-inline-panel');
    panel.setAttribute('role', 'region');
    panel.removeAttribute('aria-modal');
    const composeSlot = document.getElementById('compose-inline-slot');
    const newPostsRow = document.getElementById('feed-new-btn');
    if (composeSlot) {
      if (panel.parentElement !== composeSlot) {
        composeSlot.replaceChildren(panel);
      }
      composeSlot.style.marginBottom = '0';
      composeSlot.removeAttribute('aria-label');
    } else if (timelineFeed && panel.parentElement !== timelineFeed) {
      timelineFeed.insertBefore(panel, newPostsRow || timelineItems);
    }
    applyComposerToolbarChrome(panel);
    modal.hidden = true;
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    if (fab) fab.removeAttribute('aria-hidden');
  }
  /** Move the live compose panel into #compose-modal before section.main is wiped. */
  window.vaakParkComposerForSoftNav = function () {
    const panel = getComposePanel();
    const modalShell = document.getElementById('compose-modal');
    if (!panel || !modalShell) return false;
    panel.classList.remove('compose-inline-panel');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    if (panel.parentElement !== modalShell) {
      modalShell.appendChild(panel);
    }
    return true;
  };
  window.vaakAdoptComposerAfterSoftNav = function () {
    const panel = getComposePanel();
    if (!panel) return false;
    const slot = document.getElementById('compose-inline-slot');
    const items = document.getElementById('timeline-items');
    const view = items ? (items.dataset.view || '') : '';
    if (slot && ['home', 'local', 'feed'].includes(view)) {
      placeComposerInline();
      const form = panel.querySelector('#compose-form');
      if (form) ensureComposeAsLine(form);
      return true;
    }
    if (panel.classList.contains('compose-inline-panel') || panel.parentElement !== modal) {
      placeComposerInModal();
    }
    return true;
  };

  function placeComposerInModal() {
    if (!modal) return;
    const panel = getComposePanel();
    if (!panel) return;
    panel.classList.remove('compose-inline-panel');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    if (panel.parentElement !== modal) {
      modal.appendChild(panel);
    }
    // Same icon toolbar as the top-of-feed composer (CSS is no longer
    // scoped only to .compose-inline-panel).
    applyComposerToolbarChrome(panel);
    const composeForm = panel.querySelector('#compose-form');
    if (composeForm) ensureComposeAsLine(composeForm);
    modal.hidden = false;
    if (fab) fab.removeAttribute('aria-hidden');
  }
  // Initial placement: keep pop-out when opened as reply/quote/edit; otherwise inline.
  if (supportsInlineComposer && modal && !modal.classList.contains('open')) {
    placeComposerInline();
  } else if (modal) {
    // Deep-link / auto-open reply: still use the shared icon toolbar.
    applyComposerToolbarChrome(getComposePanel());
  }
  // Shared 2000-char counter for inline + pop-out composers (same textarea).
  // Setting textarea.value after a post does not fire input, so the count
  // stayed on the previous post until the next keystroke. Refresh from the
  // live field whenever text is applied in code.
  function vaakUpdateComposeCharCount() {
    const composeText = document.getElementById('compose-content');
    const count = document.getElementById('compose-char-count');
    if (!composeText || !count) return;
    count.textContent = (composeText.value || '').length + ' / 2,000';
  }
  window.vaakUpdateComposeCharCount = vaakUpdateComposeCharCount;
  (function bindComposeCharCount() {
    const composeText = document.getElementById('compose-content');
    if (!composeText) return;
    vaakUpdateComposeCharCount();
    composeText.addEventListener('input', vaakUpdateComposeCharCount);
  })();
  let files = [];
  let alts = [];
  let altEditIdx = -1;
  let altAiBusy = false;
  const recordBtn = document.getElementById('compose-record-btn');
  let mediaRecorder = null;
  let recordStream = null;
  let recordChunks = [];
  let recordTimer = null;
  let recordStartedAt = 0;

  function stopAudioRecording() {
    if (recordTimer) {
      clearInterval(recordTimer);
      recordTimer = null;
    }
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
      mediaRecorder.stop();
    }
    if (recordStream) {
      recordStream.getTracks().forEach((track) => track.stop());
      recordStream = null;
    }
  }

  function updateRecordButton(elapsedMs) {
    if (!recordBtn) return;
    const seconds = Math.min(60, Math.floor((elapsedMs || 0) / 1000));
    const label = mediaRecorder && mediaRecorder.state === 'recording'
      ? ('Stop recording (' + seconds + '/60)')
      : 'Record audio';
    recordBtn.innerHTML = mediaRecorder && mediaRecorder.state === 'recording'
      ? '<i class="ph ph-stop-circle" aria-hidden="true"></i> <span>' + label + '</span>'
      : '<i class="ph ph-microphone" aria-hidden="true"></i>';
    recordBtn.classList.toggle('is-recording', !!(mediaRecorder && mediaRecorder.state === 'recording'));
    recordBtn.setAttribute('aria-label', label);
  }

  if (recordBtn) {
    recordBtn.addEventListener('click', async () => {
      if (mediaRecorder && mediaRecorder.state === 'recording') {
        stopAudioRecording();
        return;
      }
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || typeof MediaRecorder === 'undefined') {
        if (window.apAdminToast) window.apAdminToast('Audio recording is not supported by this browser.', true);
        return;
      }
      if (files.length >= MAX) {
        if (window.apAdminToast) window.apAdminToast('Remove an attachment before recording audio.', true);
        return;
      }
      try {
        recordStream = await navigator.mediaDevices.getUserMedia({
          audio: {
            echoCancellation: true,
            noiseSuppression: true,
            autoGainControl: true,
            channelCount: 1,
            sampleRate: 48000,
            sampleSize: 16
          }
        });
        const preferred = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4'].find((type) => MediaRecorder.isTypeSupported(type));
        // Explicit bitrate keeps browser-default Opus encoders from choosing a
        // very low voice bitrate (which sounds metallic/garbled after upload).
        // Keep mono capture for speech, but ask for a full-band 48 kHz stream
        // where the browser honors these constraints.
        const recorderOptions = { audioBitsPerSecond: 128000 };
        if (preferred) recorderOptions.mimeType = preferred;
        mediaRecorder = new MediaRecorder(recordStream, recorderOptions);
        recordChunks = [];
        recordStartedAt = Date.now();
        mediaRecorder.addEventListener('dataavailable', (event) => {
          if (event.data && event.data.size) recordChunks.push(event.data);
        });
        mediaRecorder.addEventListener('stop', () => {
          const mime = mediaRecorder && mediaRecorder.mimeType ? mediaRecorder.mimeType : (preferred || 'audio/webm');
          const duration = Date.now() - recordStartedAt;
          const blob = new Blob(recordChunks, { type: mime });
          recordChunks = [];
          const oldRecorder = mediaRecorder;
          mediaRecorder = null;
          updateRecordButton(0);
          if (duration > 60500) {
            if (window.apAdminToast) window.apAdminToast('Recordings must be one minute or shorter.', true);
            return;
          }
          const ext = mime.includes('mp4') ? 'm4a' : 'webm';
          files.push(new File([blob], 'voice-note-' + Date.now() + '.' + ext, { type: mime }));
          alts.push('');
          syncInput();
          render();
          void oldRecorder;
        });
        updateRecordButton(0);
        recordBtn.disabled = true;
        recordBtn.innerHTML = '<i class="ph ph-spinner-gap ph-spin" aria-hidden="true"></i>';
        // Give Safari/desktop input devices a short warm-up before the first
        // encoded sample. This avoids clipping the first spoken syllable.
        await new Promise((resolve) => setTimeout(resolve, 400));
        if (!mediaRecorder || !recordStream) return;
        mediaRecorder.start();
        recordBtn.disabled = false;
        updateRecordButton(0);
        recordTimer = setInterval(() => {
          const elapsed = Date.now() - recordStartedAt;
          updateRecordButton(elapsed);
          if (elapsed >= 60000) stopAudioRecording();
        }, 250);
      } catch (e) {
        if (recordStream) recordStream.getTracks().forEach((track) => track.stop());
        recordStream = null;
        mediaRecorder = null;
        if (window.apAdminToast) window.apAdminToast('Microphone permission is required to record audio.', true);
      }
    });
  }

  const draftIdField = document.getElementById('compose-draft-id');
  const draftMediaField = document.getElementById('compose-draft-media-ids');
  const draftBtn = document.getElementById('compose-draft-btn');
  let draftSaveBusy = false;
  let skipDraftOnClose = false;

  function composePollPanel() {
    const form = (typeof activeComposeForm === 'function' && activeComposeForm())
      || document.getElementById('compose-form');
    return form ? form.querySelector('#compose-poll-panel') : document.getElementById('compose-poll-panel');
  }
  function composePollFieldsDisabled(disabled) {
    const panel = composePollPanel();
    if (!panel) return;
    panel.querySelectorAll('input, select').forEach((el) => {
      el.disabled = !!disabled;
    });
  }
  function composePollIsEditOrQuote() {
    const form = (typeof activeComposeForm === 'function' && activeComposeForm())
      || document.getElementById('compose-form');
    if (!form) return false;
    const action = form.querySelector('#compose-action');
    if (action && String(action.value || '') === 'edit_status') return true;
    const noteId = form.querySelector('#compose-note-id');
    if (noteId && String(noteId.value || '').trim()) return true;
    const quote = form.querySelector('input[name="quote_object"]');
    if (quote && String(quote.value || '').trim()) return true;
    return false;
  }
  function setComposePollOpen(open) {
    const panel = composePollPanel();
    const form = (typeof activeComposeForm === 'function' && activeComposeForm())
      || document.getElementById('compose-form');
    const toggle = form
      ? form.querySelector('#compose-poll-toggle')
      : document.getElementById('compose-poll-toggle');
    const draft = form
      ? form.querySelector('#compose-draft-btn')
      : document.getElementById('compose-draft-btn');
    const queue = form
      ? form.querySelector('#compose-queue-btn')
      : document.getElementById('compose-queue-btn');
    if (!panel) return;
    if (open) {
      panel.hidden = false;
      composePollFieldsDisabled(false);
      if (toggle) toggle.setAttribute('aria-pressed', 'true');
      if (draft) draft.disabled = true;
      if (queue) queue.disabled = true;
    } else {
      panel.hidden = true;
      composePollFieldsDisabled(true);
      if (toggle) toggle.setAttribute('aria-pressed', 'false');
      if (draft) draft.disabled = false;
      if (queue) queue.disabled = false;
    }
  }
  window.vaakToggleComposePoll = function () {
    if (composePollIsEditOrQuote()) {
      if (window.apAdminToast) window.apAdminToast('Polls are not available while editing or quoting.', true);
      return;
    }
    const panel = composePollPanel();
    if (!panel) return;
    setComposePollOpen(!!panel.hidden);
  };
  // The "Nostr" crosspost box (admin_render_compose_panel) only shows for a
  // new public post: not for edits, replies, quotes or other visibilities.
  function composeNostrEligible(form) {
    if (!form || composePollIsEditOrQuote()) return false;
    const replyTo = form.querySelector('input[name="in_reply_to"]');
    if (replyTo && String(replyTo.value || '').trim()) return false;
    const vis = form.querySelector('select[name="visibility"], input[name="visibility"]');
    return !vis || String(vis.value || 'public') === 'public';
  }
  window.vaakComposeNostrWanted = function (form) {
    const box = form ? form.querySelector('#compose-nostr-box') : null;
    return !!(box && box.checked && composeNostrEligible(form));
  };
  window.vaakSyncComposeNostr = function () {
    const form = (typeof activeComposeForm === 'function' && activeComposeForm())
      || document.getElementById('compose-form');
    const wrap = form ? form.querySelector('#compose-nostr') : null;
    if (wrap) wrap.hidden = !composeNostrEligible(form);
  };
  document.addEventListener('input', (ev) => {
    if (ev.target && ev.target.closest && ev.target.closest('#compose-form')) window.vaakSyncComposeNostr();
  });
  document.addEventListener('change', (ev) => {
    if (ev.target && ev.target.closest && ev.target.closest('#compose-form')) window.vaakSyncComposeNostr();
  });
  window.vaakSyncComposePollChrome = function () {
    window.vaakSyncComposeNostr();
    const form = (typeof activeComposeForm === 'function' && activeComposeForm())
      || document.getElementById('compose-form');
    const toggle = form
      ? form.querySelector('#compose-poll-toggle')
      : document.getElementById('compose-poll-toggle');
    const blocked = composePollIsEditOrQuote();
    if (toggle) {
      toggle.style.display = blocked ? 'none' : '';
      toggle.disabled = blocked;
    }
    if (blocked) {
      setComposePollOpen(false);
    }
  };
  (function bindComposePollControls() {
    const form = document.getElementById('compose-form');
    if (!form) return;
    const addBtn = form.querySelector('#compose-poll-add');
    if (addBtn) {
      addBtn.addEventListener('click', () => {
        const wrap = form.querySelector('#compose-poll-options');
        if (!wrap) return;
        const inputs = wrap.querySelectorAll('input[name="poll[options][]"]');
        if (inputs.length >= 4) {
          if (window.apAdminToast) window.apAdminToast('Polls allow up to 4 options.', true);
          return;
        }
        const inp = document.createElement('input');
        inp.type = 'text';
        inp.name = 'poll[options][]';
        inp.maxLength = 50;
        inp.placeholder = 'Option ' + (inputs.length + 1);
        inp.disabled = !!form.querySelector('#compose-poll-panel[hidden]');
        wrap.appendChild(inp);
        if (inputs.length + 1 >= 4) addBtn.disabled = true;
      });
    }
    window.vaakSyncComposePollChrome();
  })();

  function clampComposeTextarea() {
    const ta = document.getElementById('compose-content');
    const wrap = ta && ta.closest ? ta.closest('.compose-textarea-wrap') : null;
    if (!ta || !wrap) return;
    const max = wrap.clientHeight;
    if (max < 48) return;
    // Native resize can set an inline height past the flex slot — snap it back.
    const h = ta.offsetHeight;
    if (h > max) {
      ta.style.height = max + 'px';
    }
  }
  let composeScrollCap = null;
  function autoGrowComposeTextarea() {
    const ta = document.getElementById('compose-content');
    if (!ta) return;
    const inline = !!ta.closest('.compose-inline-panel');
    const value = ta.value || '';
    const empty = !value.trim();
    // Inline: grow with content up to ~8 lines, then scroll.
    // Modal: grow to 240px, and start scrolling once past ~400 characters.
    const maxGrow = inline ? Math.round(13 * 16) : 240; // 13rem ≈ 8 lines
    if (inline && empty) {
      composeScrollCap = null;
      ta.style.height = '96px';
      ta.style.overflowY = 'hidden';
      return;
    }
    ta.style.height = '0px';
    const contentHeight = ta.scrollHeight;
    if (inline) {
      composeScrollCap = null;
      const next = Math.min(Math.max(contentHeight, 96), maxGrow);
      ta.style.height = next + 'px';
      ta.style.overflowY = contentHeight > maxGrow ? 'auto' : 'hidden';
      return;
    }
    const shouldScrollByLength = value.length >= 400;
    if (!shouldScrollByLength) composeScrollCap = null;
    if (shouldScrollByLength && composeScrollCap === null) {
      composeScrollCap = Math.max(96, Math.min(contentHeight - 1, maxGrow));
    }
    const max = composeScrollCap === null ? maxGrow : composeScrollCap;
    const next = Math.min(Math.max(contentHeight, 96), max);
    ta.style.height = next + 'px';
    ta.style.overflowY = shouldScrollByLength || contentHeight > max ? 'auto' : 'hidden';
  }
  function saveComposeLocalBackup() {
    if (composeMode === 'edit_status') return;
    try {
      const ta = document.getElementById('compose-content');
      const spoiler = form.querySelector('input[name="spoiler_text"]');
      const vis = form.querySelector('select[name="visibility"], input[name="visibility"]');
      const sens = form.querySelector('input[name="sensitive"]');
      const replyTo = document.getElementById('compose-in-reply-to');
      const payload = {
        at: Date.now(),
        content: (ta && ta.value) || '',
        spoiler: (spoiler && spoiler.value) || '',
        visibility: (vis && vis.value) || 'public',
        sensitive: !!(sens && sens.checked),
        reply_to: (replyTo && replyTo.value) || '',
        draft_id: (draftIdField && draftIdField.value) || '',
        draft_media_ids: (draftMediaField && draftMediaField.value) || '',
      };
      if (!(payload.content.trim() || payload.spoiler.trim() || payload.draft_media_ids)) {
        localStorage.removeItem(COMPOSE_LS_KEY);
        return;
      }
      localStorage.setItem(COMPOSE_LS_KEY, JSON.stringify(payload));
    } catch (e) {}
  }
  function restoreComposeLocalBackup() {
    if (composeMode === 'edit_status') return;
    try {
      const raw = localStorage.getItem(COMPOSE_LS_KEY);
      if (!raw) return;
      const payload = JSON.parse(raw);
      if (!payload || !payload.at || (Date.now() - Number(payload.at)) > 7 * 24 * 3600 * 1000) {
        localStorage.removeItem(COMPOSE_LS_KEY);
        return;
      }
      const ta = document.getElementById('compose-content');
      if (ta && !(ta.value || '').trim() && payload.content) ta.value = String(payload.content);
      vaakUpdateComposeCharCount();
      const spoiler = form.querySelector('input[name="spoiler_text"]');
      if (spoiler && !(spoiler.value || '').trim() && payload.spoiler) spoiler.value = String(payload.spoiler);
      if (draftIdField && !draftIdField.value && payload.draft_id) draftIdField.value = String(payload.draft_id);
      if (draftMediaField && !draftMediaField.value && payload.draft_media_ids) {
        draftMediaField.value = String(payload.draft_media_ids);
      }
    } catch (e) {}
  }
  setInterval(() => {
    if (modal && modal.classList.contains('open') && composeHasDraftableContent()) {
      saveComposeLocalBackup();
    }
  }, 20000);
  window.addEventListener('pagehide', () => { saveComposeLocalBackup(); });
  window.addEventListener('beforeunload', (e) => {
    const liveForm = document.getElementById('compose-form');
    if (liveForm && liveForm.dataset.busy === '1') return;
    if (!composeHasDraftableContent()) return;
    if (draftIdField && draftIdField.value) return;
    saveComposeLocalBackup();
    e.preventDefault();
    e.returnValue = '';
  });
  let composeModalTransitionToken = 0;
  function openModal(opts) {
    opts = opts || {};
    // Keep timeline scroll locked while the pop-out opens (focus would otherwise
    // jump the page, and a full navigation used to reset to the top entirely).
    const feedEl = document.querySelector('.feed');
    const savedWindowY = window.scrollY || document.documentElement.scrollTop || 0;
    const savedFeedTop = feedEl ? feedEl.scrollTop : 0;
    if (!opts.skipBackup) {
      restoreComposeLocalBackup();
    }
    // Reply/quote/edit pop-out needs the panel back inside the modal shell.
    if (supportsInlineComposerNow() && isComposerInline()) {
      placeComposerInModal();
    } else if (!supportsInlineComposerNow() && isComposerInline()) {
      // Profile / Mentions / etc.: panel may still be parked inline after soft-nav.
      placeComposerInModal();
    }
    const liveForm = document.getElementById('compose-form');
    if (liveForm) {
      try { liveForm.setAttribute('action', window.vaakComposeActionUrl()); } catch (e) {}
      ensureComposeAsLine(liveForm);
    }
    const transitionToken = ++composeModalTransitionToken;
    modal.hidden = false;
    modal.inert = false;
    modal.setAttribute('aria-hidden', 'false');
    if (!modal.classList.contains('open')) {
      requestAnimationFrame(() => {
        if (transitionToken === composeModalTransitionToken && !modal.hidden) {
          modal.classList.add('open');
        }
      });
    }
    const restoreScroll = () => {
      try { window.scrollTo(0, savedWindowY); } catch (e) {}
      if (feedEl) feedEl.scrollTop = savedFeedTop;
    };
    restoreScroll();
    if (typeof window.vaakSyncComposeNostr === 'function') window.vaakSyncComposeNostr();
    const ta = document.getElementById('compose-content');
    if (ta) {
      requestAnimationFrame(() => {
        if (typeof window.vaakSyncComposeNostr === 'function') window.vaakSyncComposeNostr();
        restoreScroll();
        clampComposeTextarea();
        autoGrowComposeTextarea();
        vaakUpdateComposeCharCount();
        if (!opts.skipFocus) {
          try { ta.focus({ preventScroll: true }); } catch (e) { ta.focus(); }
        }
        restoreScroll();
      });
    }
  }

  function ensureComposeTargetFields() {
    if (!form) return { replyTo: null, toActor: null };
    let replyTo = document.getElementById('compose-in-reply-to');
    let toActor = document.getElementById('compose-to-actor');
    const actions = form.querySelector('.composer-actions');
    if (!replyTo) {
      replyTo = document.createElement('input');
      replyTo.name = 'in_reply_to';
      replyTo.id = 'compose-in-reply-to';
      replyTo.placeholder = 'Reply-to object URL (optional)';
      if (actions) form.insertBefore(replyTo, actions);
      else form.appendChild(replyTo);
    }
    if (!toActor) {
      toActor = document.createElement('input');
      toActor.name = 'to_actor';
      toActor.id = 'compose-to-actor';
      toActor.placeholder = 'to actor URL (optional)';
      if (actions) form.insertBefore(toActor, actions);
      else form.appendChild(toActor);
    }
    return { replyTo: replyTo, toActor: toActor };
  }

  function clearQuoteChrome() {
    if (!form) return;
    const quoteField = form.querySelector('input[name="quote_object"]');
    if (quoteField) quoteField.remove();
    const qb = form.querySelector('.quote-block');
    if (qb) qb.remove();
  }

  /* vaak-reply-preview:start */
  function vaakReplyPreviewAvatar(src) {
    const s = String(src || '').trim();
    // Allow only an https URL that names a host. Rejecting javascript: and
    // leaving data: or vbscript: in an img src is not enough, and a scheme
    // with no host is not an image address.
    if (!s || /[\s<>"'\\]/.test(s)) return '';
    let parsed;
    try { parsed = new URL(s); } catch (e) { return ''; }
    if (parsed.protocol !== 'https:' || !parsed.host) return '';
    return parsed.href;
  }
  function vaakReplyPreviewSnippet(text) {
    let t = String(text || '').replace(/\s+/g, ' ').trim();
    if (/^\((?:quote|media|poll|attachment|boost|no text preview|quoted post unavailable|quoted post)\)$/i.test(t)) return '';
    if (t.length > 180) t = t.slice(0, 179).replace(/\s+$/, '') + '…';
    return t;
  }
  function vaakReplyPreviewText(node) {
    return String((node && node.textContent) || '').replace(/\s+/g, ' ').trim();
  }
  function vaakReplyPreviewOwned(root, node) {
    if (!node || !root || !node.closest) return false;
    if (node.closest('article.tweet') !== root) return false;
    const quote = node.closest('.quote-block');
    if (quote && quote.closest('article.tweet') === root) return false;
    return true;
  }
  function vaakReplyPreviewHeader(article) {
    if (!article || !article.querySelectorAll) return null;
    const headers = article.querySelectorAll('.tweet-hd');
    for (let i = 0; i < headers.length; i++) {
      if (vaakReplyPreviewOwned(article, headers[i])) return headers[i];
    }
    return null;
  }
  function vaakReplyPreviewHandle(hd) {
    const metas = hd.querySelectorAll ? hd.querySelectorAll('.meta') : [];
    for (let i = 0; i < metas.length; i++) {
      const found = vaakReplyPreviewText(metas[i]).match(/@\S+/);
      if (found) return found[0];
    }
    return '';
  }
  function vaakReplyPreviewUsable(preview) {
    if (!preview) return false;
    return !!(preview.name || preview.handle || preview.snippet || preview.media || preview.cw || preview.quote);
  }
  function vaakReplyPreviewTargetLabel(url) {
    const raw = String(url || '').trim();
    const bsky = raw.match(/^https:\/\/bsky\.app\/profile\/([^/]+)\/post\//i);
    if (bsky) {
      try { return '@' + decodeURIComponent(bsky[1]); } catch (e) { return '@' + bsky[1]; }
    }
    const users = raw.match(/\/users\/([^/]+)\//i);
    if (users) {
      try { return '@' + decodeURIComponent(users[1]); } catch (e) { return '@' + users[1]; }
    }
    return '';
  }
  // Author, avatar, handle, and a short snippet from the card that owns this
  // Reply or Quote link. Boosts use the boosted post's header. Nested quote
  // text stays out of the snippet. Headerless notification embeds borrow the
  // parent notif header.
  function vaakReplyPreviewFromCard(anchor) {
    if (!anchor || !anchor.closest) return null;
    const content = anchor.closest('article.tweet');
    if (!content) return null;
    let headerRoot = content;
    if (!vaakReplyPreviewHeader(headerRoot) && content.closest('.notif-status-embed')) {
      let parent = content.parentElement;
      while (parent) {
        const up = parent.closest ? parent.closest('article.tweet') : null;
        if (!up) break;
        if (vaakReplyPreviewHeader(up)) { headerRoot = up; break; }
        parent = up.parentElement;
      }
    }
    const hd = vaakReplyPreviewHeader(headerRoot);
    if (!hd) return null;
    const who = hd.querySelector ? hd.querySelector('.who') : null;
    const name = vaakReplyPreviewText(who);
    const handle = vaakReplyPreviewHandle(hd);
    const img = hd.querySelector ? (hd.querySelector('img.tweet-av') || hd.querySelector('img')) : null;
    const avatar = vaakReplyPreviewAvatar(img && (img.getAttribute ? (img.getAttribute('src') || '') : ''));
    let snippet = '';
    const bodies = content.querySelectorAll ? content.querySelectorAll('.body, .notification-post') : [];
    for (let i = 0; i < bodies.length; i++) {
      if (!vaakReplyPreviewOwned(content, bodies[i])) continue;
      snippet = vaakReplyPreviewSnippet(vaakReplyPreviewText(bodies[i]));
      if (snippet) break;
    }
    const cwNodes = content.querySelectorAll ? content.querySelectorAll('details.cw-gate') : [];
    let cw = false;
    for (let i = 0; i < cwNodes.length; i++) {
      if (vaakReplyPreviewOwned(content, cwNodes[i])) { cw = true; break; }
    }
    if (!snippet && cw) {
      const cwText = content.querySelectorAll ? content.querySelectorAll('.cw-text') : [];
      for (let i = 0; i < cwText.length; i++) {
        if (!vaakReplyPreviewOwned(content, cwText[i])) continue;
        snippet = vaakReplyPreviewSnippet(vaakReplyPreviewText(cwText[i]));
        if (snippet) break;
      }
    }
    const mediaNodes = content.querySelectorAll ? content.querySelectorAll('.media-row, video, audio') : [];
    let media = false;
    for (let i = 0; i < mediaNodes.length; i++) {
      if (vaakReplyPreviewOwned(content, mediaNodes[i])) { media = true; break; }
    }
    const quotes = content.querySelectorAll ? content.querySelectorAll('.quote-block') : [];
    let quote = false;
    for (let i = 0; i < quotes.length; i++) {
      if (quotes[i].closest && quotes[i].closest('article.tweet') === content) { quote = true; break; }
    }
    const preview = { name: name, handle: handle, avatar: avatar, snippet: snippet, media: media, cw: cw, quote: quote };
    return vaakReplyPreviewUsable(preview) ? preview : null;
  }
  function vaakPaintComposeReplyContext(form, label, replyUrl, preview) {
    if (!form || !document.createElement) return;
    const old = form.querySelector ? form.querySelector('.quote-block') : null;
    if (old && old.remove) old.remove();
    const qb = document.createElement('div');
    qb.className = 'quote-block compose-reply-context';
    if (qb.style) qb.style.marginBottom = '.75rem';
    const lab = document.createElement('span');
    lab.className = 'qt-label';
    lab.textContent = label || 'Replying to';
    qb.appendChild(lab);
    if (vaakReplyPreviewUsable(preview)) {
      const row = document.createElement('div');
      row.className = 'compose-reply-preview';
      const img = document.createElement('img');
      img.className = 'tweet-av';
      img.setAttribute('src', vaakReplyPreviewAvatar(preview.avatar) || 'https://mkultra.monster/img/avatar/default.webp');
      img.setAttribute('alt', '');
      img.setAttribute('width', '32');
      img.setAttribute('height', '32');
      img.setAttribute('referrerpolicy', 'no-referrer');
      row.appendChild(img);
      const main = document.createElement('div');
      main.className = 'compose-reply-preview__main';
      const name = String(preview.name || '').replace(/\s+/g, ' ').trim();
      let handle = String(preview.handle || '').replace(/\s+/g, ' ').trim();
      if (handle && handle.charAt(0) !== '@') handle = '@' + handle;
      if (name || handle) {
        const who = document.createElement('div');
        who.className = 'compose-reply-preview__who';
        if (name) {
          const nameEl = document.createElement('span');
          nameEl.className = 'who';
          nameEl.textContent = name;
          who.appendChild(nameEl);
        }
        if (handle && handle.replace(/^@+/, '').toLowerCase() !== name.toLowerCase()) {
          const handleEl = document.createElement('span');
          handleEl.className = 'meta';
          handleEl.textContent = handle;
          who.appendChild(handleEl);
        }
        main.appendChild(who);
      }
      const snippet = String(preview.snippet || '').trim();
      if (snippet) {
        const text = document.createElement('div');
        text.className = 'compose-reply-preview__text';
        text.textContent = snippet;
        main.appendChild(text);
      }
      const notes = [];
      if (preview.cw) notes.push('Content warning');
      if (preview.media) notes.push('Media');
      if (preview.quote) notes.push('Quote');
      if (notes.length) {
        const noteRow = document.createElement('div');
        noteRow.className = 'compose-reply-preview__notes';
        notes.forEach((note) => {
          const tag = document.createElement('span');
          tag.className = 'tag';
          tag.textContent = note;
          noteRow.appendChild(tag);
        });
        main.appendChild(noteRow);
      }
      row.appendChild(main);
      qb.appendChild(row);
    } else if (label === 'Replying to your post' || label === 'Quoting') {
      const br = document.createElement('br');
      qb.appendChild(br);
      const mono = document.createElement('span');
      mono.className = 'mono';
      mono.textContent = replyUrl || '';
      qb.appendChild(mono);
    } else {
      const strong = document.createElement('strong');
      strong.textContent = ' ' + (vaakReplyPreviewTargetLabel(replyUrl) || 'the original post');
      qb.appendChild(strong);
    }
    const asMeta = form.querySelector ? (form.querySelector('.compose-as-line') || form.querySelector('.meta')) : null;
    if (asMeta && asMeta.parentNode) asMeta.parentNode.insertBefore(qb, asMeta.nextSibling);
    else if (form.insertBefore) form.insertBefore(qb, form.firstChild || null);
    else if (form.appendChild) form.appendChild(qb);
  }
  /* vaak-reply-preview:end */

  function isOwnNoteUrl(url) {
    const m = String(url || '').trim().match(/^https:\/\/mkultra\.monster\/users\/([^/]+)\/notes\//i);
    const self = String(window.VAAK_ACTOR_KEY || '').trim().replace(/^@+/, '').toLowerCase();
    return !!(m && self && self !== '__guest__' && m[1].toLowerCase() === self);
  }

  function applyReplyQuoteChrome(opts) {
    opts = opts || {};
    if (typeof window.__apResetComposeChrome === 'function') {
      try { window.__apResetComposeChrome(); } catch (e) {}
    }
    composeMode = 'reply';
    if (actionField) actionField.value = 'reply';
    if (noteIdField) noteIdField.value = '';
    if (draftIdField) draftIdField.value = '';
    if (returnField) {
      returnField.value = opts.returnView
        || (timelineItems && timelineItems.dataset.view)
        || VAAK_BOOT.s15[2];
    }
    const retHidden = form.querySelector('input[name="compose_return_view"]');
    if (retHidden && returnField) retHidden.value = returnField.value;

    clearQuoteChrome();
    const fields = ensureComposeTargetFields();
    const replyTo = fields.replyTo;
    const toActor = fields.toActor;
    const title = document.getElementById('compose-modal-title');
    const submitBtn = document.getElementById('compose-submit-btn');
    const ta = document.getElementById('compose-content');
    const spoilerInput = form.querySelector('input[name="spoiler_text"]');
    const sensInput = form.querySelector('input[name="sensitive"]');
    const quoteObject = (opts.quoteObject || '').trim();
    const replyUrl = (opts.replyTo || '').trim();
    const toUrl = (opts.toActor || '').trim();
    // Inherit parent CW into the composer (author may still clear before send).
    const inheritCw = String(opts.cw || '').trim();
    const inheritSensitive = !!opts.sensitive || inheritCw !== '';
    if (!quoteObject) {
      if (spoilerInput) spoilerInput.value = inheritCw;
      if (sensInput) {
        sensInput.checked = inheritSensitive;
        const sensBtn = form.querySelector('.composer-check .compose-tool[aria-pressed]');
        if (sensBtn) sensBtn.setAttribute('aria-pressed', inheritSensitive ? 'true' : 'false');
      }
    }
    // mention may be "a@host" or "a@host,b@host" (from reply links).
    // Collapse Bluesky aliases: @alice.bsky.social vs @alice.bsky.social@bsky.app.
    const mentionRaw = String(opts.mention || '').trim();
    const mentionParts = [];
    const mentionSeen = {};
    mentionRaw.split(/[,\s]+/).forEach((part) => {
      let p = String(part || '').trim();
      if (!p) return;
      if (p.charAt(0) !== '@') p = '@' + p;
      // Local accounts are mentioned by bare username in the composer, on
      // either local host alias.
      p = p.replace(/^(@[A-Za-z0-9_]+)@(?:www\.)?(?:mkultra|vaak)\.monster$/i, '$1');
      let key = p.slice(1).toLowerCase();
      // Never mention yourself when a parent post already tagged you.
      const selfKey = String(window.VAAK_ACTOR_KEY || '').trim().replace(/^@+/, '').toLowerCase();
      if (selfKey && key === selfKey) return;
      const bskyAlias = key.match(/^(.+\.[a-z0-9.-]+)@bsky\.app$/i);
      if (bskyAlias) {
        key = bskyAlias[1].toLowerCase();
        p = '@' + key;
      }
      if (!key || mentionSeen[key]) return;
      mentionSeen[key] = true;
      mentionParts.push(p);
    });
    // Peer local cards pass only to=<actor>; seed that author like a remote one.
    if (!mentionParts.length && !quoteObject && !isOwnNoteUrl(replyUrl)) {
      const peer = toUrl.match(/^https:\/\/mkultra\.monster\/users\/([A-Za-z0-9_]+)\/?$/i);
      const self = String(window.VAAK_ACTOR_KEY || '').trim().replace(/^@+/, '').toLowerCase();
      if (peer && peer[1].toLowerCase() !== self) mentionParts.push('@' + peer[1]);
    }
    const mentionSeed = mentionParts.join(' ');
    if (ta) {
      delete ta.dataset.autoMention;
    }

    if (quoteObject) {
      if (ta) {
        delete ta.dataset.requiredReplyMention;
        delete ta.dataset.replyParticipantCount;
      }
      if (replyTo) {
        replyTo.value = '';
        replyTo.style.display = 'none';
        replyTo.readOnly = false;
      }
      if (toActor) {
        toActor.value = '';
        if (toActor.type !== 'hidden') toActor.style.display = 'none';
      }
      if (ta) ta.value = '';
      const qHidden = document.createElement('input');
      qHidden.type = 'hidden';
      qHidden.name = 'quote_object';
      qHidden.value = quoteObject;
      form.insertBefore(qHidden, form.firstChild);
      // Same card summary as a reply. A missing card keeps the quoted URL.
      vaakPaintComposeReplyContext(form, 'Quoting', quoteObject, opts.preview || null);
      if (title) title.textContent = 'Quote';
      if (submitBtn) submitBtn.textContent = 'Quote';
      if (ta) ta.placeholder = 'Add commentary (optional)…';
      if (typeof setComposePollOpen === 'function') setComposePollOpen(false);
      if (typeof window.vaakSyncComposePollChrome === 'function') window.vaakSyncComposePollChrome();
    } else {
      // Only the signed-in account's own notes are a thread continuation.
      // Other local accounts (e.g. shana_flux) are ordinary replies that need
      // the @mention seed and reply chrome, same as remote authors.
      const isSelf = isOwnNoteUrl(replyUrl);
      if (replyTo) {
        replyTo.value = replyUrl;
        replyTo.style.display = '';
        replyTo.readOnly = !!isSelf;
        delete replyTo.dataset.inlineHidden;
      }
      if (toActor) {
        toActor.value = isSelf ? '' : toUrl;
        if (toActor.type !== 'hidden') {
          toActor.style.display = isSelf ? 'none' : '';
        }
        delete toActor.dataset.inlineHidden;
      }
      if (ta) {
        // Prefill @author (+ other mentions) with a trailing space, including
        // when the post is your own. You stay out of the seed; everyone else
        // that post mentioned stays, and you can delete them before sending.
        if (mentionSeed) {
          ta.value = mentionSeed + ' ';
          ta.dataset.autoMention = mentionSeed;
          const caret = mentionSeed.length + 1;
          setTimeout(() => {
            try { ta.setSelectionRange(caret, caret); } catch (e) {}
          }, 40);
        } else {
          ta.value = '';
        }
        // The first seeded handle is the parent author only when this is not
        // your own post. Continuing your own thread must keep followers-only
        // available, so that guard stays off here.
        if (!isSelf && mentionParts.length >= 2) {
          ta.dataset.requiredReplyMention = mentionParts[0];
          ta.dataset.replyParticipantCount = String(mentionParts.length);
        } else {
          delete ta.dataset.requiredReplyMention;
          delete ta.dataset.replyParticipantCount;
        }
      }
      if (title) title.textContent = isSelf ? 'Continue thread' : 'Reply';
      if (submitBtn) submitBtn.textContent = 'Reply';
      if (ta) ta.placeholder = isSelf ? 'Add to the thread…' : 'Write your reply…';
      // Summary from the card already on the page. A missing card keeps the
      // handle (or your post's URL) that the composer used before.
      vaakPaintComposeReplyContext(
        form,
        isSelf ? 'Replying to your post' : 'Replying to',
        replyUrl,
        opts.preview || null
      );
    }
    vaakUpdateComposeCharCount();
    bindReplyPrivacyGuard();
  }

  function bindReplyPrivacyGuard() {
    const ta = document.getElementById('compose-content');
    if (ta && ta.dataset.privacyGuardBound !== '1') {
      ta.dataset.privacyGuardBound = '1';
      ta.addEventListener('input', syncReplyPrivacyGuard);
    }
    syncReplyPrivacyGuard();
  }

  function syncReplyPrivacyGuard() {
    const ta = document.getElementById('compose-content');
    const select = document.getElementById('compose-visibility');
    if (!ta || !select) return;
    const required = String(ta.dataset.requiredReplyMention || '').trim().toLowerCase();
    const participants = parseInt(ta.dataset.replyParticipantCount || '0', 10) || 0;
    const text = String(ta.value || '').toLowerCase();
    const authorPresent = required !== '' && new RegExp('(^|\\s)' + required.replace(/[.*+?^${}()|[\\]\\\\]/g, '\\\\$&') + '(?=\\s|$)').test(text);
    const replyTo = String(document.getElementById('compose-in-reply-to')?.value || '').trim();
    const repliesToOtherPost = replyTo !== '' && !isOwnNoteUrl(replyTo);
    // Followers-only replies are only valid for the author's own posts. Keep
    // this restriction visible even when a modal lacks participant metadata;
    // the publish endpoint enforces the same rule for bypassing clients.
    const blocked = repliesToOtherPost || (participants >= 2 && !authorPresent);
    const privateOption = select.querySelector('option[value="private"]');
    if (privateOption) {
      privateOption.disabled = blocked;
      privateOption.textContent = 'Followers-only';
      privateOption.title = blocked
        ? 'Followers-only replies are available only on your own posts.'
        : '';
    }
    if (blocked && select.value === 'private') select.value = 'public';
  }

  // Server-rendered compose already ran the local lookup. If that missed but
  // the same post is on this page, fill the summary from the card.
  function vaakUpgradeServerReplyPreview() {
    if (!form || (form.querySelector && form.querySelector('.compose-reply-preview'))) return;
    const quoteField = form.querySelector ? form.querySelector('input[name="quote_object"]') : null;
    const quoteUri = quoteField && quoteField.value ? String(quoteField.value).trim() : '';
    if (quoteUri) {
      vaakFillComposePreviewFromPage(quoteUri, 'a[href*="quote_object="]', 'quote_object', 'Quoting');
      return;
    }
    const replyToEl = document.getElementById('compose-in-reply-to');
    const uri = replyToEl && replyToEl.value ? String(replyToEl.value).trim() : '';
    if (!uri) return;
    vaakFillComposePreviewFromPage(uri, 'a[href*="reply_to="]', 'reply_to', isOwnNoteUrl(uri) ? 'Replying to your post' : 'Replying to');
  }
  function vaakFillComposePreviewFromPage(uri, selector, param, label) {
    if (!uri || !document.querySelectorAll) return;
    const want = uri.replace(/\/+$/, '');
    const links = document.querySelectorAll(selector);
    for (let i = 0; i < links.length; i++) {
      const link = links[i];
      let parsed;
      try { parsed = new URL(link.getAttribute('href') || '', window.location.href); } catch (e) { continue; }
      const target = String(parsed.searchParams.get(param) || '').trim().replace(/\/+$/, '');
      if (target !== want) continue;
      const preview = vaakReplyPreviewFromCard(link);
      if (!preview) continue;
      vaakPaintComposeReplyContext(form, label, uri, preview);
      break;
    }
  }

  // The inline composer is rendered server-side and may never pass through
  // applyReplyQuoteChrome(), so bind its privacy guard on the initial page.
  document.addEventListener('DOMContentLoaded', function () {
    bindReplyPrivacyGuard();
    vaakUpgradeServerReplyPreview();
  });
  if (document.readyState !== 'loading') {
    bindReplyPrivacyGuard();
    vaakUpgradeServerReplyPreview();
  }

  function openComposeFromTimelineLink(url, sourceEl) {
    let parsed;
    try { parsed = new URL(url, window.location.href); } catch (e) { return false; }
    const sp = parsed.searchParams;
    if (!sp.has('compose') && parsed.searchParams.get('view') !== 'compose') return false;
    // Full navigation still needed for edit/draft resume (media + server prefills).
    if (sp.get('edit_note') || sp.get('draft_id')) return false;
    const replyTo = (sp.get('reply_to') || '').trim();
    const quoteObject = (sp.get('quote_object') || '').trim();
    const toActor = (sp.get('to') || '').trim();
    const mention = (sp.get('mention') || '').trim();
    const cw = (sp.get('cw') || '').trim();
    const sensitive = sp.get('sensitive') === '1' || cw !== '';
    if (!replyTo && !quoteObject) return false;
    applyReplyQuoteChrome({
      replyTo: replyTo,
      quoteObject: quoteObject,
      toActor: toActor,
      mention: mention,
      cw: cw,
      sensitive: sensitive,
      preview: sourceEl && (replyTo || quoteObject) ? vaakReplyPreviewFromCard(sourceEl) : null,
      returnView: (sp.get('view') === 'compose' ? null : sp.get('view')) || null,
    });
    // Don't restore a leftover local draft over a fresh reply/quote target.
    openModal({ skipBackup: true });
    return true;
  }

  // Intercept Reply/Quote compose links so the feed does not reload/scroll to top.
  document.addEventListener('click', (ev) => {
    if (ev.defaultPrevented) return;
    if (ev.button !== 0) return;
    if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return;
    const a = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
    if (!a) return;
    const href = a.getAttribute('href') || '';
    if (!href || href.charAt(0) === '#') return;
    if (!/[?&]compose=/.test(href) && !/[?&]view=compose(?:&|$)/.test(href)) return;
    if (!/[?&](?:reply_to|quote_object)=/.test(href)) return;
    if (openComposeFromTimelineLink(href, a)) {
      ev.preventDefault();
      ev.stopPropagation();
    }
  }, true);
  function composeHasDraftableContent() {
    if (composeMode === 'edit_status') return false;
    const ta = document.getElementById('compose-content');
    let text = ((ta && ta.value) || '').trim();
    // Ignore the auto-inserted @handle seed if the user never typed more.
    const autoMention = (ta && ta.dataset.autoMention) ? String(ta.dataset.autoMention).trim() : '';
    if (autoMention && (text === autoMention || text === autoMention + ' ')) {
      text = '';
    }
    // A reply containing only participant handles is not authored content;
    // abandoning it should not create a draft merely because one handle was
    // removed from the initial mention seed.
    if (text !== '' && /^\s*(?:@[\w.!#$%&'*+\-/=?^`{|}~]+(?:@[\w.-]+)?\s*)+$/u.test(text)) {
      text = '';
    }
    const existingMedia = ((draftMediaField && draftMediaField.value) || '').trim();
    // Reply/quote targets and inherited CW are composer context, not authored
    // content — closing after opening Reply on a CW'd post (and changing your
    // mind) must not create a draft. Require text and/or media.
    return !!(text || existingMedia || (files && files.length));
  }
  function updateDraftsBadge(count) {
    const badge = document.getElementById('nav-drafts-badge');
    if (!badge) return;
    const n = parseInt(count, 10) || 0;
    if (n > 0) {
      badge.hidden = false;
      badge.textContent = n > 99 ? '99+' : String(n);
    } else {
      badge.hidden = true;
      badge.textContent = '';
    }
  }
  async function saveComposeDraft(opts) {
    opts = opts || {};
    const silent = !!opts.silent;
    if (composeMode === 'edit_status') {
      return { ok: false, skipped: true };
    }
    if (!composeHasDraftableContent()) {
      return { ok: false, skipped: true };
    }
    if (draftSaveBusy) {
      return { ok: false, busy: true };
    }
    draftSaveBusy = true;
    if (draftBtn) draftBtn.disabled = true;
    try {
      syncInput();
      const fd = new FormData(form);
      fd.set('ajax', '1');
      fd.set('action', 'save_draft');
      // Escape/close uses fetch FormData — not a form submit — so inject CSRF explicitly
      if (window.vaakCsrfApply) window.vaakCsrfApply(fd);
      else if (window.VAAK_CSRF) fd.set('csrf', window.VAAK_CSRF);
      if (draftIdField && draftIdField.value) {
        fd.set('draft_id', draftIdField.value);
      }
      if (draftMediaField) {
        fd.set('draft_media_ids', draftMediaField.value || '');
      }
      const res = await fetch((typeof window.vaakComposeActionUrl === 'function' ? window.vaakComposeActionUrl() : '/vaak/?view=outbox'), {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });
      const data = await res.json().catch(() => null);
      if (!data || !data.ok) {
        if (!silent && window.apAdminToast) {
          window.apAdminToast((data && data.error) || 'Could not save draft.', true);
        }
        return { ok: false, error: (data && data.error) || 'save failed' };
      }
      if (draftIdField && data.draft_id) {
        draftIdField.value = String(data.draft_id);
      }
      if (draftMediaField && Array.isArray(data.media_ids)) {
        draftMediaField.value = data.media_ids.join(',');
      }
      // Clear freshly uploaded FileList — they’re now on the draft as media_ids
      files = [];
      alts = [];
      syncInput();
      render();
      if (typeof data.drafts_count === 'number') {
        updateDraftsBadge(data.drafts_count);
      }
      if (!silent && window.apAdminToast) {
        window.apAdminToast(data.notice || 'Draft saved.');
      }
      return { ok: true, data: data };
    } catch (e) {
      if (!silent && window.apAdminToast) {
        window.apAdminToast('Network error saving draft.', true);
      }
      return { ok: false, error: 'network' };
    } finally {
      draftSaveBusy = false;
      if (draftBtn) draftBtn.disabled = false;
    }
  }
  function clearComposeFieldsAfterClose() {
    const ta = document.getElementById('compose-content');
    if (ta) {
      ta.value = '';
      delete ta.dataset.autoMention;
    }
    vaakUpdateComposeCharCount();
    const spoiler = form.querySelector('input[name="spoiler_text"]');
    if (spoiler) spoiler.value = '';
    const sens = form.querySelector('input[name="sensitive"]');
    if (sens) sens.checked = false;
    files = [];
    alts = [];
    syncInput();
    render();
    if (draftIdField) draftIdField.value = '';
    if (draftMediaField) draftMediaField.value = '';
    const replyTo = document.getElementById('compose-in-reply-to');
    const toActor = document.getElementById('compose-to-actor');
    if (replyTo) { replyTo.value = ''; replyTo.readOnly = false; replyTo.style.display = ''; }
    if (toActor) { toActor.value = ''; if (toActor.type !== 'hidden') toActor.style.display = ''; }
    const quoteField = form.querySelector('input[name="quote_object"]');
    if (quoteField) quoteField.remove();
    const qb = form.querySelector('.quote-block');
    if (qb) qb.remove();
    try {
      const beforeClose = new URL(window.location.href);
      const noteForProfile = beforeClose.searchParams.get('edit_note')
        || beforeClose.searchParams.get('reply_to')
        || beforeClose.searchParams.get('quote_object')
        || '';
      const href = (typeof window.vaakAppHistoryUrl === 'function')
        ? window.vaakAppHistoryUrl((u) => {
            ['compose', 'quote_object', 'quote_status_id', 'reply_to', 'to', 'mention', 'cw', 'sensitive', 'edit_note', 'draft_id'].forEach((k) => u.searchParams.delete(k));
          })
        : (() => {
            const u = new URL(window.location.href);
            ['compose', 'quote_object', 'quote_status_id', 'reply_to', 'to', 'mention', 'cw', 'sensitive', 'edit_note', 'draft_id'].forEach((k) => u.searchParams.delete(k));
            return u.pathname + u.search + u.hash;
          })();
      const stripped = new URL(href, window.location.origin);
      const actorLeft = (stripped.searchParams.get('actor') || '').replace(/\/$/, '');
      const bareProfile = (stripped.pathname === '/vaak' || stripped.pathname === '/vaak/' || stripped.pathname === '/vaak/index.php')
        && (stripped.searchParams.get('view') || '') === 'remote_profile'
        && !/^https:\/\//i.test(actorLeft);
      const profileKey = String(noteForProfile).match(/^https:\/\/mkultra\.monster\/users\/([A-Za-z0-9_]+)\//i);
      if (bareProfile && profileKey) {
        window.location.assign('/vaak/users/' + profileKey[1]);
        return;
      }
      window.history.replaceState(Object.assign({}, window.history.state || {}), '', href);
    } catch (e) {}
  }
  async function closeModal() {
    if (mediaRecorder && mediaRecorder.state === 'recording') stopAudioRecording();
    if (altModal && altModal.classList.contains('open')) {
      closeAltEditor(false);
      return;
    }
    const ep = document.getElementById('compose-emoji-picker');
    const et = document.getElementById('compose-emoji-toggle');
    if (ep && !ep.hidden) {
      ep.hidden = true;
      if (et) et.setAttribute('aria-expanded', 'false');
    }
    if (!skipDraftOnClose && composeHasDraftableContent()) {
      const saved = await saveComposeDraft({ silent: false });
      // Never wipe the composer after a failed draft — mobile users lose videos that way.
      if (saved && saved.ok) {
        try { localStorage.removeItem(COMPOSE_LS_KEY); } catch (e) {}
      } else if (!(saved && saved.skipped)) {
        return;
      }
    }
    skipDraftOnClose = false;
    const transitionToken = ++composeModalTransitionToken;
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    modal.inert = true;
    const finishClose = () => {
      if (transitionToken !== composeModalTransitionToken || modal.classList.contains('open')) return;
      if (typeof window.__apResetComposeChrome === 'function') {
        try { window.__apResetComposeChrome(); } catch (e) {}
      }
      clearComposeFieldsAfterClose();
      // Rehome the shared composer after the exit animation finishes.
      if (supportsInlineComposerNow()) placeComposerInline();
      else modal.hidden = true;
    };
    const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reducedMotion) {
      finishClose();
    } else {
      const onFadeOut = (event) => {
        if (event.target === modal && event.propertyName === 'opacity') {
          modal.removeEventListener('transitionend', onFadeOut);
          finishClose();
        }
      };
      modal.addEventListener('transitionend', onFadeOut);
      window.setTimeout(() => {
        modal.removeEventListener('transitionend', onFadeOut);
        finishClose();
      }, 260);
    }
  }

  // Unicode emoji picker for compose
  (function initEmojiPicker() {
    const toggle = document.getElementById('compose-emoji-toggle');
    const picker = document.getElementById('compose-emoji-picker');
    const ta = document.getElementById('compose-content');
    if (!toggle || !picker || !ta) return;
    const EMOJIS = [
      '😀','😁','😂','🤣','😅','😊','😍','🥰','😘','😜','🤔','😏','😎','🥺','😢','😭',
      '😤','🤬','🤯','😴','🫡','👀','✨','🔥','💯','❤️','🧡','💛','💚','💙','💜','🖤',
      '🤍','💔','💕','💖','⭐','🌟','⚡','🌙','☀️','🌈','🌸','🍀','🐱','🐶','🦊','🐻',
      '🐼','🐸','🦄','🐝','🐛','🐢','🐙','🐡','🦷','🍪','🍩','🍕','☕','🍺','🎉','🎊',
      '🦷','🦇','💀','👻','👽','🤖','👾','🎃','😈','👿','💋','🖕','👍','👎','👏','🙌',
      '🤝','✌️','🤞','🤟','🤘','👌','🤙','🙏','💪','🧠','💬','📢','🔔','📌','📎','✏️'
    ];
    // unique preserve order
    const seen = {};
    const list = [];
    EMOJIS.forEach((e) => { if (!seen[e]) { seen[e] = 1; list.push(e); } });
    const names = {
      '😀':'grinning smile', '😂':'joy laugh', '😊':'smile', '😍':'heart eyes',
      '🥰':'love', '😎':'sunglasses cool', '😢':'cry sad', '😭':'sob',
      '😡':'angry', '🤔':'thinking', '😴':'sleep', '👀':'eyes', '✨':'sparkles',
      '🔥':'fire', '💯':'hundred', '❤️':'heart', '⭐':'star', '🌈':'rainbow',
      '🐱':'cat', '🐶':'dog', '🦄':'unicorn', '🍕':'pizza', '☕':'coffee',
      '🎉':'party', '🎊':'confetti', '💀':'skull', '👻':'ghost', '👽':'alien',
      '🤖':'robot', '👍':'thumbs up', '👎':'thumbs down', '👏':'clap',
      '🙏':'pray', '💪':'strong', '💬':'speech', '📌':'pin'
    };
    picker.innerHTML = '';
    const search = document.createElement('input');
    search.type = 'search';
    search.className = 'compose-emoji-search';
    search.placeholder = 'Search emoji';
    search.setAttribute('aria-label', 'Search emoji');
    picker.appendChild(search);
    const grid = document.createElement('div');
    grid.style.display = 'contents';
    picker.appendChild(grid);
    const buttons = [];
    list.forEach((emo) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.setAttribute('role', 'option');
      b.title = emo;
      b.dataset.search = (emo + ' ' + (names[emo] || '')).toLowerCase();
      b.textContent = emo;
      b.addEventListener('click', () => {
        const start = ta.selectionStart ?? ta.value.length;
        const end = ta.selectionEnd ?? ta.value.length;
        const before = ta.value.slice(0, start);
        const after = ta.value.slice(end);
        ta.value = before + emo + after;
        const pos = start + emo.length;
        ta.focus();
        try { ta.setSelectionRange(pos, pos); } catch (_) {}
        ta.dispatchEvent(new Event('input', { bubbles: true }));
        // Selecting an emoji completes the picker action; collapse it so the
        // composer stays compact and the next tap returns to the text field.
        picker.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
      });
      grid.appendChild(b);
      buttons.push(b);
    });
    search.addEventListener('input', () => {
      const q = search.value.trim().toLowerCase();
      buttons.forEach((b) => { b.hidden = !!q && !b.dataset.search.includes(q); });
    });
    toggle.addEventListener('click', (event) => {
      // Stop the same click from bubbling to the document "click outside"
      // closer — especially when the hit target is the inner <i> icon.
      event.stopPropagation();
      const open = picker.hidden;
      picker.hidden = !open;
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) {
        // Defer focus so this click cannot be treated as an outside dismiss.
        setTimeout(() => { try { search.focus(); } catch (_) {} }, 0);
      }
    });
    document.addEventListener('click', (event) => {
      if (picker.hidden) return;
      const t = event.target;
      if (!(t instanceof Node)) return;
      // toggle.contains covers clicks on the Phosphor <i> inside the button.
      if (picker.contains(t) || toggle.contains(t)) return;
      picker.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
    });
    document.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape' || picker.hidden) return;
      picker.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
      toggle.focus();
    });
  })();
  function updateAltCount() {
    if (!altCount || !altText) return;
    altCount.textContent = (altText.value || '').length + ' / 1500';
  }
  function setAltAiStatus(msg, isErr) {
    if (!altAiStatus) return;
    altAiStatus.textContent = msg || '';
    altAiStatus.style.color = isErr ? 'var(--danger)' : 'var(--muted)';
  }
  function openAltEditor(idx) {
    if (!altModal || !altText || !altPreview || idx < 0 || idx >= files.length) return;
    altEditIdx = idx;
    const file = files[idx];
    const url = URL.createObjectURL(file);
    altPreview.innerHTML = '';
    const isVideo = file.type.startsWith('video/');
    const isAudio = file.type.startsWith('audio/');
    if (isVideo) {
      const v = document.createElement('video');
      v.className = 'alt-modal__preview';
      v.src = url;
      v.controls = true;
      v.muted = true;
      altPreview.appendChild(v);
    } else if (isAudio) {
      const a = document.createElement('audio');
      a.className = 'alt-modal__preview';
      a.src = url;
      a.controls = true;
      altPreview.appendChild(a);
    } else {
      const img = document.createElement('img');
      img.className = 'alt-modal__preview';
      img.src = url;
      img.alt = '';
      altPreview.appendChild(img);
    }
    altText.value = alts[idx] || '';
    updateAltCount();
    setAltAiStatus('');
    if (altAiBtn) {
      altAiBtn.style.display = (isVideo || isAudio) ? 'none' : '';
      altAiBtn.disabled = false;
    }
    altModal.classList.add('open');
    altModal.setAttribute('aria-hidden', 'false');
    setTimeout(() => altText.focus(), 40);
  }
  function closeAltEditor(save) {
    if (!altModal) return;
    if (altAiBusy) return;
    if (save && altEditIdx >= 0) {
      alts[altEditIdx] = (altText.value || '').trim();
    }
    altEditIdx = -1;
    altModal.classList.remove('open');
    altModal.setAttribute('aria-hidden', 'true');
    altPreview.innerHTML = '';
    setAltAiStatus('');
    render();
  }
  async function generateAltWithAi() {
    if (altAiBusy || altEditIdx < 0 || altEditIdx >= files.length) return;
    const file = files[altEditIdx];
    if (!file || !file.type.startsWith('image/')) {
      setAltAiStatus('AI describe works on images only (not video).', true);
      return;
    }
    altAiBusy = true;
    if (altAiBtn) altAiBtn.disabled = true;
    setAltAiStatus('Generating with AI…');
    try {
      const fd = new FormData();
      fd.set('action', 'ai_alt_text');
      fd.set('ajax', '1');
      if (window.vaakCsrfApply) window.vaakCsrfApply(fd);
      else if (window.VAAK_CSRF) fd.set('csrf', window.VAAK_CSRF);
      fd.append('image', file, file.name || 'image.jpg');
      const res = await fetch('?view=home&op=ai_alt_text', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await res.json().catch(() => null);
      if (!data || !data.ok || !data.alt) {
        setAltAiStatus((data && data.error) || 'AI describe failed.', true);
        return;
      }
      if (altText) {
        altText.value = data.alt;
        updateAltCount();
        altText.focus();
      }
      setAltAiStatus('Draft ready — edit if needed, then Save.');
    } catch (e) {
      setAltAiStatus('Network error — try again.', true);
    } finally {
      altAiBusy = false;
      if (altAiBtn) altAiBtn.disabled = false;
    }
  }
  if (fab) fab.addEventListener('click', () => openModal());
  if (closeBtn) closeBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
  // Keep the growing textarea inside its flex slot (avoids modal/form scrollbars)
  (function bindComposeResizeClamp() {
    const ta = document.getElementById('compose-content');
    if (!ta) return;
    // Pointer release also fires after a touch/mouse scroll. Re-running the
    // grow routine there briefly collapses the field to 0px and restores its
    // height, which resets scrollTop and makes it impossible to scroll back
    // through a long draft on phones and desktop. Pointer release only needs
    // the safety clamp; input/resize observers handle actual growth.
    const clampSoon = () => requestAnimationFrame(clampComposeTextarea);
    ta.addEventListener('input', autoGrowComposeTextarea);
    ta.addEventListener('mouseup', clampSoon);
    ta.addEventListener('pointerup', clampSoon);
    ta.addEventListener('blur', autoGrowComposeTextarea);
    window.addEventListener('resize', () => {
      clampComposeTextarea();
      autoGrowComposeTextarea();
    });
    if (typeof ResizeObserver !== 'undefined') {
      let clamping = false;
      new ResizeObserver(() => {
        if (clamping) return;
        clamping = true;
        clampComposeTextarea();
        autoGrowComposeTextarea();
        clamping = false;
      }).observe(ta);
    }
    autoGrowComposeTextarea();
  })();
  if (altSave) altSave.addEventListener('click', () => closeAltEditor(true));
  if (altCancel) altCancel.addEventListener('click', () => closeAltEditor(false));
  if (altClose) altClose.addEventListener('click', () => closeAltEditor(false));
  if (altAiBtn) altAiBtn.addEventListener('click', () => { generateAltWithAi(); });
  if (altModal) {
    altModal.addEventListener('click', (e) => { if (e.target === altModal) closeAltEditor(false); });
  }
  if (altText) altText.addEventListener('input', updateAltCount);
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (altModal && altModal.classList.contains('open')) {
      e.stopPropagation();
      closeAltEditor(false);
      return;
    }
    if (modal.classList.contains('open')) closeModal();
  });
  function syncInput() {
    const dt = new DataTransfer();
    files.forEach((f) => dt.items.add(f));
    input.files = dt.files;
  }
  function altButtonLabel(text) {
    const t = (text || '').trim();
    if (!t) return 'Add alt text';
    return t.length > 18 ? ('Alt: ' + t.slice(0, 16) + '…') : ('Alt: ' + t);
  }
  function render() {
    previews.innerHTML = '';
    while (alts.length < files.length) alts.push('');
    if (alts.length > files.length) alts.length = files.length;
    files.forEach((file, idx) => {
      const card = document.createElement('div');
      card.className = 'compose-media-card';
      const url = URL.createObjectURL(file);
      if (file.type.startsWith('video/')) {
        card.innerHTML = '<video src="' + url + '" muted></video>';
      } else if (file.type.startsWith('audio/')) {
        card.innerHTML = '<audio src="' + url + '" controls></audio>';
      } else {
        card.innerHTML = '<img src="' + url + '" alt="">';
      }
      const hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = 'media_alt[' + idx + ']';
      hidden.value = alts[idx] || '';
      const altBtn = document.createElement('button');
      altBtn.type = 'button';
      altBtn.className = 'alt-btn' + ((alts[idx] || '').trim() ? ' has-alt' : '');
      altBtn.textContent = altButtonLabel(alts[idx]);
      altBtn.title = (alts[idx] || '').trim() || 'Write alt text';
      altBtn.addEventListener('click', () => openAltEditor(idx));
      const rm = document.createElement('button');
      rm.type = 'button';
      rm.className = 'rm';
      rm.textContent = 'Remove';
      rm.addEventListener('click', () => {
        files.splice(idx, 1);
        alts.splice(idx, 1);
        syncInput();
        render();
      });
      card.appendChild(hidden);
      card.appendChild(altBtn);
      card.appendChild(rm);
      previews.appendChild(card);
    });
    const hint = document.getElementById('compose-media-hint');
    if (hint) hint.textContent = files.length ? (files.length + ' / ' + MAX) : '';
  }
  function composeMediaLive() {
    let live = document.getElementById('compose-media-live');
    if (live || !form) return live;
    live = document.createElement('div');
    live.id = 'compose-media-live';
    live.className = 'compose-media-live';
    live.setAttribute('role', 'status');
    live.setAttribute('aria-live', 'polite');
    live.setAttribute('aria-atomic', 'true');
    form.appendChild(live);
    return live;
  }
  function addComposeFiles(picked, opts) {
    const api = window.vaakPasteMedia;
    if (!api || !api.filterMediaFiles) {
      if (window.apAdminToast) window.apAdminToast('Could not attach media.', true);
      return { accepted: [], errors: ['Could not attach media.'] };
    }
    // Edits keep the attachments already on the post. The file control is hidden.
    if (composeMode === 'edit_status') {
      if (window.apAdminToast) window.apAdminToast(api.pasteEditMessage, true);
      api.announceLive(composeMediaLive(), api.pasteEditMessage);
      return { accepted: [], errors: [] };
    }
    const existingCount = ((draftMediaField && draftMediaField.value) || '')
      .split(/[\s,]+/).filter((x) => parseInt(x, 10) > 0).length;
    const filtered = api.filterMediaFiles(files.length + existingCount, picked, MAX);
    filtered.errors.forEach((msg) => {
      if (window.apAdminToast) window.apAdminToast(msg, true);
    });
    filtered.accepted.forEach((f) => {
      files.push(f);
      alts.push('');
    });
    syncInput();
    render();
    if (!opts || !opts.silent) {
      const parts = [];
      const notice = api.attachmentNotice(filtered.accepted);
      if (notice) parts.push(notice);
      if (filtered.errors.length) parts.push(filtered.errors[filtered.errors.length - 1]);
      api.announceLive(composeMediaLive(), parts.join(' '));
    }
    return filtered;
  }
  input.addEventListener('change', () => {
    addComposeFiles(Array.from(input.files || []));
    // Clear the native input so the same file can be re-picked; we own `files`.
    try { input.value = ''; } catch (e) {}
    syncInput();
  });
  // Accept files dragged from the desktop using the same validation and
  // preview path as the media picker. Prevent the browser from navigating to
  // the dropped file when the pointer leaves a child element.
  let composeDragDepth = 0;
  const hasDraggedFiles = (event) => {
    const types = event && event.dataTransfer && event.dataTransfer.types;
    return !!(types && Array.from(types).includes('Files'));
  };
  form.addEventListener('dragenter', (event) => {
    if (!hasDraggedFiles(event)) return;
    event.preventDefault();
    composeDragDepth += 1;
    form.classList.add('is-dragover');
  });
  form.addEventListener('dragover', (event) => {
    if (!hasDraggedFiles(event)) return;
    event.preventDefault();
    if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy';
    form.classList.add('is-dragover');
  });
  form.addEventListener('dragleave', (event) => {
    if (!hasDraggedFiles(event)) return;
    event.preventDefault();
    composeDragDepth = Math.max(0, composeDragDepth - 1);
    if (!composeDragDepth) form.classList.remove('is-dragover');
  });
  form.addEventListener('drop', (event) => {
    if (!hasDraggedFiles(event)) return;
    event.preventDefault();
    composeDragDepth = 0;
    form.classList.remove('is-dragover');
    addComposeFiles(Array.from((event.dataTransfer && event.dataTransfer.files) || []));
  });
  // Screenshot paste (Ctrl/Cmd+V) joins the same file list as the picker.
  // Plain text has no file items, so it still lands in the field. An image
  // file is intercepted so the browser does not also insert its filename.
  form.addEventListener('paste', (event) => {
    const api = window.vaakPasteMedia;
    if (!api || !event.clipboardData) return;
    let clip = null;
    try { clip = api.clipboardImageFiles(event.clipboardData); } catch (e) { return; }
    if (!clip || !clip.sawFile) return;
    event.preventDefault();
    if (composeMode === 'edit_status') {
      if (window.apAdminToast) window.apAdminToast(api.pasteEditMessage, true);
      api.announceLive(composeMediaLive(), api.pasteEditMessage);
      return;
    }
    const parts = [];
    if (clip.rejected) {
      if (window.apAdminToast) window.apAdminToast(api.pasteTypeMessage, true);
      parts.push(api.pasteTypeMessage);
    }
    if (clip.unreadable && !clip.images.length) {
      if (window.apAdminToast) window.apAdminToast(api.pasteReadMessage, true);
      parts.push(api.pasteReadMessage);
    }
    const added = clip.images.length
      ? addComposeFiles(clip.images, { silent: true })
      : { accepted: [], errors: [] };
    const notice = api.attachmentNotice(added.accepted || []);
    if (notice) parts.unshift(notice);
    (added.errors || []).forEach((msg) => {
      if (parts.indexOf(msg) === -1) parts.push(msg);
    });
    api.announceLive(composeMediaLive(), parts.join(' '));
  });
  // Prefer the active compose panel — Home has both inline + modal forms with
  // duplicate IDs; document.getElementById() would stick to the wrong one.
  function activeComposeForm() {
    const panel = typeof getComposePanel === 'function' ? getComposePanel() : document.querySelector('.compose-modal__panel');
    if (panel) {
      const f = panel.querySelector('#compose-form') || panel.querySelector('form.composer');
      if (f) return f;
    }
    return document.getElementById('compose-form');
  }
  function composeField(sel, form) {
    const root = form || activeComposeForm();
    return root ? root.querySelector(sel) : document.querySelector(sel);
  }
  let actionField = composeField('#compose-action');
  let returnField = composeField('#compose-return-view');
  let noteIdField = composeField('#compose-note-id');
  // Derive from the live form field — $composeIsEdit is scoped inside
  // admin_render_compose_panel() and is not available in this page script.
  let composeMode = (
    (actionField && actionField.value === 'edit_status')
    || (noteIdField && String(noteIdField.value || '').includes('/notes/'))
  ) ? 'edit_status' : 'reply'; // reply | queue_post | edit_status
  const queueBtn = composeField('#compose-queue-btn') || document.getElementById('compose-queue-btn');
  const mediaInput = composeField('#compose-media-input') || document.getElementById('compose-media-input');
  const mediaHint = composeField('#compose-media-hint') || document.getElementById('compose-media-hint');
  const submitProgress = composeField('#compose-submit-progress') || document.getElementById('compose-submit-progress');
  const submitProgressBar = composeField('#compose-submit-progress-bar') || document.getElementById('compose-submit-progress-bar');
  const submitProgressLabel = composeField('#compose-submit-progress-label') || document.getElementById('compose-submit-progress-label');
  function refreshComposeFieldRefs(form) {
    actionField = composeField('#compose-action', form);
    returnField = composeField('#compose-return-view', form);
    noteIdField = composeField('#compose-note-id', form);
  }
  const postAudio = new Audio('/api/assets/post.wav?v=1');
  postAudio.preload = 'auto';
  postAudio.volume = 0.9;
  postAudio.setAttribute('playsinline', '');
  let postAudioUnlocked = false;
  function unlockPostAudio() {
    if (postAudioUnlocked) return;
    try {
      postAudio.muted = true;
      postAudio.currentTime = 0;
      const p = postAudio.play();
      if (p && typeof p.then === 'function') p.then(() => {
        postAudio.pause(); postAudio.currentTime = 0; postAudio.muted = false; postAudioUnlocked = true;
      }).catch(() => { postAudio.muted = false; });
    } catch (e) { postAudio.muted = false; }
  }
  function playPostAudio() {
    try {
      postAudio.currentTime = 0;
      const p = postAudio.play();
      if (p && typeof p.catch === 'function') p.catch(() => {});
    } catch (e) {}
  }
  const visibilityWrap = document.getElementById('compose-visibility')
    ? document.getElementById('compose-visibility').closest('label')
    : null;
  function setSubmitProgress(state, percent) {
    if (!submitProgress) return;
    const active = state !== 'hidden';
    submitProgress.classList.toggle('is-visible', active);
    submitProgress.classList.toggle('is-indeterminate', state === 'finalizing' || state === 'posting' || state === 'processing');
    if (submitProgressBar) {
      submitProgressBar.value = Math.max(0, Math.min(100, Number(percent) || 0));
    }
    if (submitProgressLabel) {
      submitProgressLabel.textContent = state === 'uploading'
        ? ('Uploading media… ' + Math.round(Number(percent) || 0) + '%')
        : (state === 'processing'
          ? 'Processing video on server…'
          : (state === 'finalizing' ? 'Media uploaded — posting…' : (state === 'posting' ? 'Posting…' : '')));
    }
  }
  async function uploadOneComposeFile(file, altText, onProgress) {
    const fd = new FormData();
    fd.set('action', 'upload_media');
    fd.set('ajax', '1');
    fd.set('file', file, file.name || 'upload.bin');
    if (altText) fd.set('description', altText);
    if (window.vaakCsrfApply) window.vaakCsrfApply(fd);
    else if (window.VAAK_CSRF) fd.set('csrf', window.VAAK_CSRF);
    const postUrl = (typeof window.vaakComposeActionUrl === 'function')
      ? window.vaakComposeActionUrl()
      : '/vaak/?view=outbox';
    return submitComposeRequest(postUrl, fd, onProgress);
  }
  async function preUploadPendingComposeFiles() {
    if (!files.length) return { ok: true };
    const existing = ((draftMediaField && draftMediaField.value) || '')
      .split(/[\s,]+/).map((x) => parseInt(x, 10)).filter((n) => n > 0);
    if (existing.length + files.length > MAX) {
      return { ok: false, error: 'Too many media attachments (max ' + MAX + ').' };
    }
    const ids = existing.slice();
    for (let i = 0; i < files.length; i++) {
      const f = files[i];
      const lim = mediaLimitError(f);
      if (lim) return { ok: false, error: lim };
      setSubmitProgress('uploading', Math.round((i / Math.max(files.length, 1)) * 100));
      const data = await uploadOneComposeFile(f, alts[i] || '', (pct) => {
        const base = (i / Math.max(files.length, 1)) * 100;
        const slice = (1 / Math.max(files.length, 1)) * 100;
        setSubmitProgress('uploading', base + (pct / 100) * slice);
      });
      if (!data || !data.ok || !data.media_id) {
        return { ok: false, error: (data && data.error) || 'Media upload failed.' };
      }
      ids.push(Number(data.media_id));
      setSubmitProgress('processing', 100);
    }
    if (draftMediaField) draftMediaField.value = ids.join(',');
    files = [];
    alts = [];
    syncInput();
    render();
    return { ok: true, media_ids: ids };
  }
  function submitComposeRequest(url, fd, onProgress) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      const postUrl = url || (typeof window.vaakComposeActionUrl === 'function'
        ? window.vaakComposeActionUrl()
        : '/vaak/?view=outbox');
      xhr.open('POST', postUrl, true);
      xhr.withCredentials = true;
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.upload.addEventListener('progress', (event) => {
        if (event.lengthComputable && typeof onProgress === 'function') {
          onProgress((event.loaded / event.total) * 100);
        }
      });
      xhr.addEventListener('load', () => {
        let data = null;
        const raw = String(xhr.responseText || '');
        try { data = JSON.parse(raw); } catch (_) {}
        if (xhr.status >= 200 && xhr.status < 300) {
          if (data && typeof data === 'object') {
            resolve(data);
            return;
          }
          // Non-JSON 2xx is usually an HTML redirect/shell (e.g. legacy outbox GET).
          const looksHtml = /^\s*</.test(raw);
          resolve({
            ok: false,
            error: looksHtml
              ? 'Compose hit a page instead of the API — refresh and try again.'
              : (raw.trim() === '' ? 'Empty compose response.' : 'Invalid compose response.'),
          });
          return;
        }
        const fallback = (data && data.error)
          ? data
          : { ok: false, error: 'Upload failed (HTTP ' + xhr.status + ').' };
        resolve(fallback);
      });
      xhr.addEventListener('error', () => reject(new Error('network')));
      xhr.addEventListener('timeout', () => reject(new Error('timeout')));
      xhr.timeout = 0;
      xhr.send(fd);
    });
  }
  // PHP-prefilled edit: keep mode sticky so AJAX save hits edit_status
  if (composeMode === 'edit_status' && actionField) {
    actionField.value = 'edit_status';
  }

  function resetComposeChrome() {
    composeMode = 'reply';
    if (actionField) actionField.value = 'reply';
    const title = document.getElementById('compose-modal-title');
    const submitBtn = document.getElementById('compose-submit-btn');
    if (title) title.textContent = 'Compose';
    if (submitBtn) submitBtn.textContent = 'Post';
    if (noteIdField) noteIdField.value = '';
    if (queueBtn) queueBtn.style.display = '';
    if (draftBtn) draftBtn.style.display = '';
    if (mediaInput) {
      const lab = mediaInput.closest('label');
      if (lab) lab.style.display = '';
    }
    if (mediaHint) {
      mediaHint.style.display = '';
      mediaHint.textContent = '';
    }
    if (visibilityWrap) visibilityWrap.style.display = '';
    // Quote mode lives in the hidden quote_object field and the quote card.
    // Drop both before the poll sync below, which reads that field.
    clearQuoteChrome();
    const replyTo = document.getElementById('compose-in-reply-to');
    const toActor = document.getElementById('compose-to-actor');
    const ta = document.getElementById('compose-content');
    if (ta) {
      // The composer panel is reused between replies and new posts. Do not
      // carry reply-participant privacy state or quote/reply/edit wording
      // into the next composition.
      delete ta.dataset.requiredReplyMention;
      delete ta.dataset.replyParticipantCount;
      ta.placeholder = "What's happening?";
    }
    if (replyTo) {
      replyTo.style.display = '';
      if (replyTo.parentElement) replyTo.parentElement.style.display = '';
    }
    if (toActor && toActor.tagName === 'INPUT' && toActor.type !== 'hidden') {
      toActor.style.display = '';
      if (toActor.parentElement) toActor.parentElement.style.display = '';
    }
    if (typeof setComposePollOpen === 'function') setComposePollOpen(false);
    if (typeof window.vaakSyncComposePollChrome === 'function') window.vaakSyncComposePollChrome();
    syncReplyPrivacyGuard();
  }
  window.__apResetComposeChrome = resetComposeChrome;

  function b64ToUtf8(b64) {
    try {
      const bin = atob(b64 || '');
      const bytes = Uint8Array.from(bin, (c) => c.charCodeAt(0));
      return new TextDecoder('utf-8').decode(bytes);
    } catch (e) {
      try { return decodeURIComponent(escape(atob(b64 || ''))); } catch (e2) { return ''; }
    }
  }

  function applyEditChrome(noteId, returnView) {
    // Move into the modal panel first so field refs target the form we submit.
    if (isComposerInline()) {
      placeComposerInModal();
    }
    const liveForm = activeComposeForm();
    refreshComposeFieldRefs(liveForm);
    resetComposeChrome();
    refreshComposeFieldRefs(liveForm);
    composeMode = 'edit_status';
    if (actionField) actionField.value = 'edit_status';
    if (noteIdField) noteIdField.value = noteId || '';
    if (returnField) returnField.value = returnView || 'outbox';
    const panelRoot = (liveForm && liveForm.closest('.compose-modal__panel')) || getComposePanel() || document;
    const title = panelRoot.querySelector('#compose-modal-title') || document.getElementById('compose-modal-title');
    const submitBtn = composeField('#compose-submit-btn', liveForm) || document.getElementById('compose-submit-btn');
    if (title) title.textContent = 'Edit post';
    if (submitBtn) submitBtn.textContent = 'Save edit';
    // Keep media as-is on edit (API keeps existing attachments); hide new uploads for now
    const qBtn = composeField('#compose-queue-btn', liveForm) || queueBtn;
    const dBtn = composeField('#compose-draft-btn', liveForm) || draftBtn;
    if (qBtn) qBtn.style.display = 'none';
    if (dBtn) dBtn.style.display = 'none';
    if (draftIdField) draftIdField.value = '';
    if (draftMediaField) draftMediaField.value = '';
    const mInput = composeField('#compose-media-input', liveForm) || mediaInput;
    const mHint = composeField('#compose-media-hint', liveForm) || mediaHint;
    if (mInput) {
      const lab = mInput.closest('label');
      if (lab) lab.style.display = 'none';
    }
    if (mHint) {
      mHint.style.display = '';
      mHint.textContent = 'Media stays attached; text/CW edit only for now';
    }
    const vis = composeField('#compose-visibility', liveForm);
    const visWrap = vis ? vis.closest('label') : visibilityWrap;
    if (visWrap) visWrap.style.display = 'none';
    const replyTo = composeField('#compose-in-reply-to', liveForm);
    if (replyTo) {
      replyTo.value = '';
      // The field is a direct child of #compose-form. Hiding that parent
      // removes the whole composer and leaves only the "Edit post" heading.
      const wrap = replyTo.parentElement;
      const wrapIsForm = !!(wrap && (wrap === liveForm || wrap.id === 'compose-form' || wrap.classList.contains('composer')));
      if (wrap && !wrapIsForm) wrap.style.display = 'none';
      else replyTo.style.display = 'none';
    }
    const toActor = composeField('#compose-to-actor', liveForm);
    if (toActor && toActor.type !== 'hidden') {
      toActor.value = '';
      toActor.style.display = 'none';
    }
    if (typeof setComposePollOpen === 'function') setComposePollOpen(false);
    if (typeof window.vaakSyncComposePollChrome === 'function') window.vaakSyncComposePollChrome();
  }

  function fillEditFields(content, spoilerText, sensitive) {
    // Always target the live panel form. Module-level `form` can point at a
    // detached Home inline node after soft-nav, leaving the visible modal empty.
    const liveForm = activeComposeForm() || form;
    const ta = (liveForm && liveForm.querySelector('#compose-content'))
      || document.getElementById('compose-content');
    if (ta) {
      ta.value = content || '';
      ta.placeholder = 'Edit your post…';
      vaakUpdateComposeCharCount();
      try { autoGrowComposeTextarea(); } catch (e) {}
    }
    if (!liveForm) return;
    const spoiler = liveForm.querySelector('input[name="spoiler_text"]');
    if (spoiler) spoiler.value = spoilerText || '';
    const sens = liveForm.querySelector('input[name="sensitive"]');
    if (sens) {
      sens.checked = !!sensitive;
      const sensBtn = liveForm.querySelector('.composer-check .compose-tool[aria-pressed]');
      if (sensBtn) sensBtn.setAttribute('aria-pressed', sensitive ? 'true' : 'false');
    }
  }

  function openEditComposer(btn) {
    const noteId = btn.getAttribute('data-note-id') || '';
    const returnView = btn.getAttribute('data-return-view') || 'outbox';
    const fallbackHref = btn.getAttribute('href') || '';
    const goFullPageEdit = () => {
      if (!fallbackHref) return;
      let dest = fallbackHref;
      try {
        const url = new URL(fallbackHref, window.location.href);
        const view = (url.searchParams.get('view') || '').replace(/[^a-z_]/g, '');
        if (view === 'remote_profile') {
          const note = url.searchParams.get('edit_note') || noteId || '';
          const actorMatch = String(note).match(/^(https:\/\/mkultra\.monster\/users\/[A-Za-z0-9_]+)\//i);
          const key = actorMatch && actorMatch[1].match(/\/users\/([A-Za-z0-9_]+)$/i);
          if (key) {
            url.pathname = '/vaak/users/' + key[1];
            url.searchParams.set('actor', actorMatch[1]);
          }
        }
        dest = url.pathname + url.search;
      } catch (e) {}
      window.location.assign(dest);
    };
    let panel = getComposePanel();
    // Timeline pages render one shared composer inline. Move that actual panel
    // into the modal before changing its controls; otherwise a modal shell can
    // open while the editable form remains hidden in the feed slot.
    if (!panel) {
      goFullPageEdit();
      return;
    }
    if (isComposerInline()) {
      placeComposerInModal();
      panel = getComposePanel() || panel;
    }
    // Focused/thread layouts can omit the inline slot. Do not leave an empty
    // modal shell if the shared panel was not actually attached with a form.
    if (!panel.querySelector('#compose-form') || !panel.querySelector('#compose-content')) {
      goFullPageEdit();
      return;
    }
    // A focused post can leave the shared panel carrying inline-composer
    // presentation styles. Normalize the panel/form before opening so the
    // modal never collapses to just its heading.
    panel.classList.remove('compose-inline-panel');
    panel.classList.add('compose-tools-ready');
    panel.style.display = 'flex';
    panel.style.visibility = 'visible';
    const editForm = panel.querySelector('#compose-form');
    if (editForm) {
      editForm.style.display = 'flex';
      editForm.style.visibility = 'visible';
      editForm.style.opacity = '1';
    }
    applyEditChrome(noteId, returnView);
    // Instant fallback from data attrs while we fetch fresh text from DB
    let content = '';
    const b64 = btn.getAttribute('data-content-b64');
    if (b64) content = b64ToUtf8(b64);
    else content = btn.getAttribute('data-content') || '';
    let spoilerText = '';
    const sb64 = btn.getAttribute('data-spoiler-b64');
    if (sb64) spoilerText = b64ToUtf8(sb64);
    else spoilerText = btn.getAttribute('data-spoiler') || '';
    const sensitive = btn.getAttribute('data-sensitive') === '1';
    // Set edit mode before openModal so local draft restore cannot clobber fields.
    composeMode = 'edit_status';
    fillEditFields(content, spoilerText, sensitive);
    openModal({ skipBackup: true });
    // Re-assert chrome after openModal/placeComposerInModal so title/submit
    // never stick on Compose/Post from a prior reset.
    applyEditChrome(noteId, returnView);
    fillEditFields(content, spoilerText, sensitive);
    const ta = (editForm && editForm.querySelector('#compose-content'))
      || document.getElementById('compose-content');
    if (ta) setTimeout(() => { try { ta.focus({ preventScroll: true }); } catch (e) { ta.focus(); } }, 50);

    if (!noteId) {
      if (!(content || '').trim()) goFullPageEdit();
      return;
    }
    // Authoritative load: masto_statuses.content_text (blank lines intact)
    // Absolute: on /vaak/users/{key} a query-only ?op= hits the profile document.
    fetch('/vaak/?op=edit_draft&note_id=' + encodeURIComponent(noteId), {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      cache: 'no-store'
    }).then((res) => res.json()).then((data) => {
      if (composeMode !== 'edit_status') return;
      if (noteIdField && noteIdField.value !== noteId) return;
      if (data && data.ok) {
        // Keep the button's immediate text fallback when an older/media-only
        // row has no content_text; never turn a usable edit form blank.
        if (String(data.content || '') !== '' || content === '') {
          fillEditFields(data.content || '', data.spoiler_text || '', !!data.sensitive);
        }
        return;
      }
      // In-place hydrate failed and we had no attribute text — use the PHP
      // ?edit_note= composer so the user is never left with an empty Edit box.
      if (!(content || '').trim()) goFullPageEdit();
    }).catch(() => {
      if (composeMode === 'edit_status' && !(content || '').trim()) goFullPageEdit();
    });
  }

  document.addEventListener('click', (ev) => {
    const btn = ev.target && ev.target.closest ? ev.target.closest('.js-edit-post') : null;
    if (!btn) return;
    ev.preventDefault();
    // The shared navigation listener runs in capture phase and briefly shows
    // the page-loading pill for every same-origin anchor. Edit navigates to
    // the server-rendered composer, so clear that indicator immediately.
    if (typeof window.vaakHideLoading === 'function') window.vaakHideLoading();
    // Canonical Edit path (restored from 03e05dc / Sep 22): full-page
    // ?edit_note= so PHP prefills edit_status chrome + body. In-place
    // openEditComposer keeps regressing to an empty Compose/Post shell after
    // soft-nav composer adoption (seen again in 0.3.36–0.3.38).
    const href = btn.getAttribute('href') || '';
    let hrefView = (btn.getAttribute('data-return-view') || '').replace(/[^a-z_]/g, '');
    try {
      const parsedView = new URL(href, window.location.href).searchParams.get('view') || '';
      if (parsedView) hrefView = parsedView.replace(/[^a-z_]/g, '');
    } catch (e) {}
    // Profile already has the pop-out. Loading ?edit_note= refreshes the
    // profile and opens an empty composer over it.
    if (hrefView === 'remote_profile' && modal) {
      openEditComposer(btn);
      return;
    }
    if (href) {
      window.location.assign(href);
      return;
    }
    if (modal && typeof openEditComposer === 'function') {
      openEditComposer(btn);
    }
  });

  if (queueBtn) {
    queueBtn.addEventListener('click', () => {
      composeMode = 'queue_post';
      if (actionField) actionField.value = 'queue_post';
      // Queueing is an inline action. Preserve the current timeline/media
      // view instead of navigating to the queue dashboard.
      const composeReturn = form.querySelector('input[name="compose_return_view"]');
      if (returnField) returnField.value = composeReturn ? composeReturn.value : VAAK_BOOT.s15[3];
      form.requestSubmit();
    });
  }
  if (draftBtn) {
    draftBtn.addEventListener('click', async () => {
      const wasInline = isComposerInline();
      const res = await saveComposeDraft({ silent: false });
      if (res && res.ok) {
        if (wasInline) {
          // Inline Save draft is a handoff: keep the audience preference, but
          // clear the composer so the user can immediately start another post.
          clearComposeFieldsAfterClose();
          try { localStorage.removeItem(COMPOSE_LS_KEY); } catch (e) {}
        } else {
          // Keep the modal open with draft id set so further edits update the same draft.
          const title = document.getElementById('compose-modal-title');
          if (title && draftIdField && draftIdField.value) {
            title.textContent = 'Edit draft';
          }
        }
      }
    });
  }

  // Replies must not rebuild the page they were posted from. These two
  // helpers are the only decision the submit handler uses, and the regression
  // harness extracts them by the markers below.
  /* vaak-compose-followup:start */
  function vaakNormalizePostRef(value) {
    let raw = String(value || '').trim();
    if (!raw) return '';
    try { raw = decodeURIComponent(raw); } catch (e) {}
    return raw.replace(/\/+$/, '');
  }
  function vaakPostRefsMatch(left, right) {
    const a = vaakNormalizePostRef(left);
    const b = vaakNormalizePostRef(right);
    return a !== '' && a === b;
  }
  function vaakOpenThreadContainsPost(replyTo) {
    const want = vaakNormalizePostRef(replyTo);
    if (!want) return false;
    const threadOpen = !!(document.querySelector('.status-back')
      || document.getElementById('status-thread-descendants')
      || document.getElementById('status-focus'));
    if (!threadOpen) return false;
    const roots = [];
    const pushRoot = (node) => { if (node && roots.indexOf(node) < 0) roots.push(node); };
    pushRoot(document.getElementById('status-thread-ancestors'));
    pushRoot(document.getElementById('status-thread-descendants'));
    pushRoot(document.getElementById('status-focus'));
    pushRoot(document.querySelector('article.tweet-focus'));
    pushRoot(document.querySelector('article#status-focus'));
    if (document.querySelector('.status-back')) pushRoot(document.querySelector('section.main'));
    try {
      const focusObject = new URL(window.location.href).searchParams.get('object') || '';
      if (vaakPostRefsMatch(focusObject, want)) return true;
    } catch (e) {}
    for (let i = 0; i < roots.length; i++) {
      const root = roots[i];
      const objectAttr = root.getAttribute && (root.getAttribute('data-object') || root.getAttribute('data-note-id') || root.getAttribute('data-object-id') || root.getAttribute('data-bsky-uri'));
      if (vaakPostRefsMatch(objectAttr, want)) return true;
      if (!root.querySelectorAll) continue;
      const nodes = root.querySelectorAll('[data-note-id],[data-object-id],[data-bsky-uri],[data-object],a[href*="reply_to="],a[href*="object="]');
      for (let n = 0; n < nodes.length; n++) {
        const el = nodes[n];
        if (vaakPostRefsMatch(el.getAttribute('data-note-id'), want)
          || vaakPostRefsMatch(el.getAttribute('data-object-id'), want)
          || vaakPostRefsMatch(el.getAttribute('data-bsky-uri'), want)
          || vaakPostRefsMatch(el.getAttribute('data-object'), want)) return true;
        const href = el.getAttribute('href') || '';
        if (!href) continue;
        try {
          const url = new URL(href, window.location.href);
          if (vaakPostRefsMatch(url.searchParams.get('reply_to'), want)
            || vaakPostRefsMatch(url.searchParams.get('object'), want)) return true;
        } catch (e) {}
      }
    }
    return false;
  }
  function vaakDecideComposeFollowup(state) {
    const mode = String((state && state.mode) || '');
    if (mode === 'queue_post') return 'stay';
    const isReply = !!(state && state.isReply) && mode !== 'edit_status';
    if (isReply) {
      if (state.threadContainsParent && state.noteId) return 'hydrate-thread';
      return 'stay';
    }
    if (state && state.statusPage) {
      if (mode === 'edit_status' && state.noteId) return 'hydrate-edit';
      if (mode !== 'edit_status' && state.noteId) return 'hydrate-thread';
      return 'stay';
    }
    if (mode !== 'edit_status' && state && state.poller) {
      if (state.preservingRefresh === false) return 'poll-insert';
      return 'refresh-timeline';
    }
    return 'reload';
  }
  window.vaakOpenThreadContainsPost = vaakOpenThreadContainsPost;
  window.vaakDecideComposeFollowup = vaakDecideComposeFollowup;
  /* vaak-compose-followup:end */

  document.addEventListener('submit', async (ev) => {
    const submitForm = ev.target;
    if (!(submitForm instanceof HTMLFormElement) || submitForm.id !== 'compose-form') return;
    ev.preventDefault();
    ev.stopPropagation();
    if (typeof window.vaakHideLoading === 'function') window.vaakHideLoading();
    const form = submitForm;
    refreshComposeFieldRefs(form);
    syncInput();
    if (form.dataset.busy === '1') return;
    form.dataset.busy = '1';
    const submitBtn = form.querySelector('#compose-submit-btn') || form.querySelector('button[type="submit"]');
    unlockPostAudio();
    const formAction = form.querySelector('#compose-action');
    const formNoteId = form.querySelector('#compose-note-id');
    const formReturn = form.querySelector('#compose-return-view');
    // Authoritative edit signal: the submitted form's own note_id / action.
    // Never trust module-level getElementById refs (duplicate inline+modal IDs).
    const editingNoteId = String((formNoteId && formNoteId.value) || (noteIdField && noteIdField.value) || '').trim();
    const isOwnNoteEdit = editingNoteId !== '' && editingNoteId.indexOf('/notes/') !== -1;
    let mode = composeMode;
    if (isOwnNoteEdit) mode = 'edit_status';
    else if (formAction && formAction.value === 'edit_status') mode = 'edit_status';
    else if (formAction && formAction.value === 'queue_post') mode = 'queue_post';
    const actionName = mode === 'queue_post'
      ? 'queue_post'
      : (mode === 'edit_status' || isOwnNoteEdit ? 'edit_status' : 'reply');
    composeMode = mode === 'queue_post' ? 'queue_post' : (actionName === 'edit_status' ? 'edit_status' : 'reply');
    if (formAction) formAction.value = actionName;
    if (actionField) actionField.value = actionName;
    if (formNoteId && isOwnNoteEdit) formNoteId.value = editingNoteId;
    if (noteIdField && isOwnNoteEdit) noteIdField.value = editingNoteId;
    if (formReturn && mode !== 'queue_post') {
      formReturn.value = VAAK_BOOT.s15[4];
    } else if (returnField && mode !== 'queue_post') {
      returnField.value = VAAK_BOOT.s15[5];
    }
    if (submitBtn) submitBtn.disabled = true;
    if (queueBtn) queueBtn.disabled = true;
    if (draftBtn) draftBtn.disabled = true;
    try {
      // Pre-upload local files so the publish POST stays small (avoids mobile
      // “session expired” when PHP empties a huge multipart body).
      if (files.length && actionName !== 'edit_status') {
        const up = await preUploadPendingComposeFiles();
        if (!up.ok) {
          if (window.apAdminToast) window.apAdminToast(up.error || 'Media upload failed.', true);
          else alert(up.error || 'Media upload failed.');
          return;
        }
      }
      const fd = new FormData(form);
      fd.set('ajax', '1');
      fd.set('action', actionName);
      if (isOwnNoteEdit) fd.set('note_id', editingNoteId);
      if (window.vaakCsrfApply) window.vaakCsrfApply(fd);
      else if (window.VAAK_CSRF) fd.set('csrf', window.VAAK_CSRF);
      // Drop any leftover file inputs — media already lives in draft_media_ids.
      fd.delete('media[]');
      if (draftMediaField) fd.set('draft_media_ids', draftMediaField.value || '');
      const hadMedia = !!(((draftMediaField && draftMediaField.value) || '').trim());
      setSubmitProgress(hadMedia ? 'finalizing' : 'posting', hadMedia ? 100 : 0);
      const postUrl = (typeof window.vaakComposeActionUrl === 'function')
        ? window.vaakComposeActionUrl()
        : '/vaak/?view=outbox';
      const data = await submitComposeRequest(postUrl, fd, null);
      setSubmitProgress(hadMedia ? 'finalizing' : 'posting', 100);
      if (!data || !data.ok) {
        const failMsg = actionName === 'queue_post' ? 'Queue failed.' : (actionName === 'edit_status' ? 'Edit failed.' : 'Post failed.');
        if (window.apAdminToast) {
          window.apAdminToast((data && (data.error || data.notice)) || failMsg, true);
        } else {
          alert((data && data.error) || failMsg);
        }
        // Keep text + already-uploaded media_ids for retry.
        return;
      }
      // The server has accepted the post (or handed it to the durable
      // publication queue). Do not make the user wait for timeline hydration
      // or federation delivery before dismissing the page-level save marker.
      if (typeof window.vaakHideLoading === 'function') window.vaakHideLoading();
      const replyFieldLive = form.querySelector('input[name="in_reply_to"]');
      const submittedReplyTo = String((replyFieldLive && replyFieldLive.value) || '').trim();
      const submittedIsReply = submittedReplyTo !== '' && actionName !== 'edit_status' && mode !== 'queue_post';
      if (window.apAdminToast) {
        const okMsg = actionName === 'queue_post'
          ? 'Added to queue.'
          : (actionName === 'edit_status' ? 'Post updated.' : (data.kind === 'quote' ? 'Quote posted.' : 'Posted.'));
        window.apAdminToast(data.success_toast || okMsg);
        // Bluesky rate-limit: fedi post succeeded; mirror was queued for later.
        if (data.toast) {
          setTimeout(() => window.apAdminToast(String(data.toast)), 350);
        }
      }
      if (mode === 'reply') playPostAudio();
      let nostrCrosspost = null;
      const reloadAfterNostr = () => Promise.resolve(nostrCrosspost).finally(() => window.location.reload());
      // "Nostr" box: sign the same post as a Nostr note via the browser's
      // signer extension. Runs in the background; the post is already in.
      // A page reload below would kill it, so reloads wait for it first.
      if (actionName === 'reply' && !submittedIsReply && data.kind !== 'quote' && data.note_id
          && typeof window.vaakComposeNostrWanted === 'function' && window.vaakComposeNostrWanted(form)
          && typeof window.vaakNostrCrosspost === 'function') {
        nostrCrosspost = Promise.resolve(window.vaakNostrCrosspost(String(data.note_id))).catch(() => null);
      }
      // The box starts unticked for every post.
      form.querySelectorAll('#compose-nostr-box').forEach((box) => { box.checked = false; });
      // Posted/queued — don't re-save as draft on close
      skipDraftOnClose = true;
      // A completed post is no longer a recoverable composer draft. Remove the
      // local backup before the shared composer is opened again.
      try { localStorage.removeItem(COMPOSE_LS_KEY); } catch (e) {}
      if (draftIdField) draftIdField.value = '';
      if (draftMediaField) draftMediaField.value = '';
      await closeModal();
      resetComposeChrome();
      // Clear compose state for next open (strip quote prefills via soft reset)
      const ta = document.getElementById('compose-content');
      if (ta) ta.value = '';
      vaakUpdateComposeCharCount();
      const spoiler = form.querySelector('input[name="spoiler_text"]');
      if (spoiler) spoiler.value = '';
      const sens = form.querySelector('input[name="sensitive"]');
      if (sens) sens.checked = false;
      files = [];
      alts = [];
      syncInput();
      render();
      const quoteField = form.querySelector('input[name="quote_object"]');
      if (quoteField) {
        // After a successful quote, drop quote UI so next compose is a normal post
        quoteField.remove();
        const title = document.getElementById('compose-modal-title');
        if (title) title.textContent = 'Compose';
        if (submitBtn) submitBtn.textContent = 'Post';
        const qb = form.querySelector('.quote-block');
        if (qb) qb.remove();
      }
      const replyTo = document.getElementById('compose-in-reply-to');
      const toActor = document.getElementById('compose-to-actor');
      if (replyTo) { replyTo.value = ''; replyTo.style.display = ''; }
      if (toActor) { toActor.value = ''; if (toActor.type !== 'hidden') toActor.style.display = ''; }
      // Drop compose/quote/reply query flags so refresh doesn't reopen the modal
      try {
        const href = (typeof window.vaakAppHistoryUrl === 'function')
          ? window.vaakAppHistoryUrl((u) => {
              ['compose', 'quote_object', 'quote_status_id', 'reply_to', 'to', 'cw', 'sensitive', 'edit_note', 'draft_id'].forEach((k) => u.searchParams.delete(k));
            })
          : (() => {
              const u = new URL(window.location.href);
              ['compose', 'quote_object', 'quote_status_id', 'reply_to', 'to', 'cw', 'sensitive', 'edit_note', 'draft_id'].forEach((k) => u.searchParams.delete(k));
              return u.pathname + u.search + u.hash;
            })();
        window.history.replaceState({}, '', href);
      } catch (e) {}
      const followup = vaakDecideComposeFollowup({
        mode: mode,
        isReply: submittedIsReply,
        threadContainsParent: submittedIsReply && vaakOpenThreadContainsPost(submittedReplyTo),
        noteId: mode === 'edit_status' ? editingNoteId : String((data && data.note_id) || ''),
        statusPage: !!document.querySelector('.status-back'),
        poller: typeof window.novaPollTimeline === 'function',
        preservingRefresh: typeof window.novaRefreshTimelinePreservingPosition === 'function',
      });
      if (followup === 'stay') {
        // Reply, queued post, or a thread fragment that does not belong here.
        // Leave the list, scroll position, and expanded rows alone.
        return;
      }
      if (followup === 'hydrate-thread') {
        // One published-reply fragment, and only when this open thread already
        // shows the parent. A miss must not rebuild the page.
        if (data.note_id && typeof window.vaakHydratePublishedThreadReply === 'function') {
          Promise.resolve(window.vaakHydratePublishedThreadReply(data.note_id)).catch(() => {});
        }
        return;
      }
      if (followup === 'hydrate-edit') {
        if (typeof window.vaakHydrateEditedThreadPost === 'function') {
          Promise.resolve(window.vaakHydrateEditedThreadPost(editingNoteId)).catch(() => {});
        }
        return;
      }
      // Original posts and quotes still join the live Home/Local/Federated
      // head. Other surfaces keep their previous reload when that insert
      // cannot run. Replies never reach this branch.
      if (followup === 'refresh-timeline') {
        Promise.resolve(window.novaRefreshTimelinePreservingPosition({ freshPost: true, published: data.published }))
          .then((refreshed) => { if (refreshed === false) reloadAfterNostr(); }).catch(() => {});
        return;
      }
      if (followup === 'poll-insert') {
        Promise.resolve(window.novaPollTimeline()).then(() => {
          if (typeof window.novaInsertPendingTimeline === 'function') {
            window.novaInsertPendingTimeline({ scrollToTop: false });
          }
        }).catch(() => {});
        return;
      }
      reloadAfterNostr();
    } catch (err) {
      if (window.apAdminToast) window.apAdminToast('Upload or network error — try again.', true);
      else alert('Upload or network error — try again.');
    } finally {
      // Do not reset composeMode here: failures leave the modal open so the
      // user can retry. Resetting to reply made a failed edit retry publish a
      // brand-new post. Successful submissions reset via resetComposeChrome().
      form.dataset.busy = '0';
      if (submitBtn) submitBtn.disabled = false;
      if (queueBtn) queueBtn.disabled = false;
      if (draftBtn) draftBtn.disabled = false;
      setSubmitProgress('hidden', 0);
      // Composer submits are handled in-place (including publication queue
      // handoff), so no full-page `pageshow` event is guaranteed to clear the
      // shared capture-phase Saving… indicator.
      if (typeof window.vaakHideLoading === 'function') window.vaakHideLoading();
    }
  }, true);
})();
