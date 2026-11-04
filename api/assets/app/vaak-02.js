/* VAAK app script 2. Moved out of api/ap-admin.php (was an inline <script>) so
   browsers cache and precompile it; the shell loads these files in their old order.
*/
        window.vaakDmBoot = function () {
          // DM screen: chat grouping, live thread, send without a page load,
          // conversation search, and the New message picker. Only runs on this
          // screen, so nothing here polls once you leave it.
          const workspace = document.getElementById('dm-workspace');
          if (!workspace) return;
          const peer = workspace.getAttribute('data-dm-peer') || '';
          // Bluesky conversation id when a Bluesky thread is open; its cursor is a rev string.
          const bskyConvo = workspace.getAttribute('data-dm-bsky') || '';
          const bskyOn = workspace.getAttribute('data-dm-bsky-on') === '1';
          const thread = document.getElementById('dm-thread');
          const endMarker = document.getElementById('dm-message-last');
          const coarse = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
          const toast = function (msg, isErr) { if (window.apAdminToast) window.apAdminToast(msg, !!isErr); };

          // ---- in-place navigation ----
          // Opening a conversation, New message, and the request/menu actions
          // swap #dm-workspace instead of loading the whole page. Each swap
          // tears down this controller (timers, document listeners) and boots
          // a fresh one on the new markup.
          if (typeof window.vaakDmTeardown === 'function') window.vaakDmTeardown();
          const life = typeof AbortController === 'function' ? new AbortController() : null;
          const on = life ? { signal: life.signal } : undefined;
          let alive = true;
          window.vaakDmTeardown = function () {
            alive = false;
            if (life) life.abort();
            [listTimer, pollTimer, bskyListTimer].forEach(function (t) { if (t) window.clearTimeout(t); });
          };
          window.vaakDmLive = function () {
            const ws = document.getElementById('dm-workspace');
            return !!ws && ws.getClientRects().length > 0;
          };
          function dmUrl(href) {
            try {
              const u = new URL(href, window.location.href);
              return u.origin === window.location.origin && u.searchParams.get('view') === 'dms' ? u : null;
            } catch (e) { return null; }
          }
          function workspaceHref(ws) {
            const b = ws.getAttribute('data-dm-bsky') || '';
            const p = ws.getAttribute('data-dm-peer') || '';
            const u = new URL(window.location.href);
            u.search = '?view=dms' + (b ? '&bsky=' + encodeURIComponent(b) : (p ? '&peer=' + encodeURIComponent(p) : ''));
            return u.href;
          }
          function swapIn(html, href, push) {
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const next = doc.getElementById('dm-workspace');
            const live = document.getElementById('dm-workspace');
            if (!next || !live) return false;
            const target = href || workspaceHref(next);
            window.vaakDmTeardown();
            live.replaceWith(document.adoptNode(next));
            doc.querySelectorAll('.flash').forEach(function (flash) {
              const text = (flash.querySelector('span') || flash).textContent.trim();
              if (text) toast(text, flash.classList.contains('err'));
            });
            if (doc.title) document.title = doc.title;
            if (doc.body) document.body.classList.toggle('dm-peer-open', doc.body.classList.contains('dm-peer-open'));
            if (push) {
              if (target !== window.location.href) window.history.pushState({ vaakDm: 1 }, '', target);
            } else if (href === null) {
              window.history.replaceState({ vaakDm: 1 }, '', target);
            }
            if (typeof window.vaakDmMediaBoot === 'function') window.vaakDmMediaBoot();
            window.vaakDmBoot();
            return true;
          }
          // href: where the address bar should point (null = derive from the new workspace).
          function go(url, init, href, push) {
            const seq = (window.__vaakDmNavSeq = (window.__vaakDmNavSeq || 0) + 1);
            workspace.classList.add('is-navigating');
            workspace.setAttribute('aria-busy', 'true');
            const isPost = !!(init && init.method === 'POST');
            return fetch(url, Object.assign({ credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'text/html' } }, init || {}))
              .then(function (res) {
                if (!res.ok && !isPost) throw new Error('HTTP ' + res.status);
                return res.text();
              })
              .then(function (html) {
                if (seq !== window.__vaakDmNavSeq) return;
                if (!swapIn(html, href, push)) {
                  if (!isPost) window.location.assign(url);
                  else window.location.reload();
                }
              })
              .catch(function () {
                if (seq !== window.__vaakDmNavSeq) return;
                if (!isPost) { window.location.assign(url); return; }
                workspace.classList.remove('is-navigating');
                workspace.removeAttribute('aria-busy');
                toast('Network error. That did not go through.', true);
              });
          }
          window.vaakDmGo = function (url, push) { return go(url, null, url, push !== false); };
          if (life) {
            document.addEventListener('click', function (event) {
              if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
              const link = event.target.closest && event.target.closest('a[href]');
              if (!link || !link.closest('#dm-workspace') || link.target === '_blank' || link.hasAttribute('download')) return;
              const u = dmUrl(link.getAttribute('href') || '');
              if (!u) return;
              event.preventDefault();
              // The shared link listener may already have shown Loading….
              window.__vaakNavigationPending = false;
              if (typeof window.vaakHideLoading === 'function') window.vaakHideLoading();
              go(u.href, null, u.href, true);
            }, { capture: true, signal: life.signal });
            document.addEventListener('submit', function (event) {
              const form = event.target;
              if (event.defaultPrevented || !(form instanceof HTMLFormElement) || !form.hasAttribute('data-dm-action') || !form.closest('#dm-workspace')) return;
              event.preventDefault();
              if (typeof window.vaakHideLoading === 'function') window.vaakHideLoading();
              const fd = new FormData(form);
              if (event.submitter && event.submitter.name) fd.set(event.submitter.name, event.submitter.value);
              if (typeof window.vaakCsrfApply === 'function') window.vaakCsrfApply(fd);
              go(form.getAttribute('action') || '?view=dms', {
                method: 'POST', body: fd, credentials: 'same-origin', cache: 'no-store',
                headers: { 'Accept': 'text/html', 'X-VAAK-CSRF': window.VAAK_CSRF || '' },
              }, null, true);
            }, life ? { signal: life.signal } : false);
            if (!window.__vaakDmPopBound) {
              window.__vaakDmPopBound = true;
              window.addEventListener('popstate', function () {
                const u = dmUrl(window.location.href);
                if (u && window.vaakDmLive && window.vaakDmLive() && typeof window.vaakDmGo === 'function') {
                  window.vaakDmGo(u.href, false);
                }
              });
            }
          }

          // ---- grouping, day separators, local times ----
          function dayKey(d) { return d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate(); }
          function dayLabel(d) {
            const now = new Date();
            const y = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
            if (dayKey(d) === dayKey(now)) return 'Today';
            if (dayKey(d) === dayKey(y)) return 'Yesterday';
            const opts = { weekday: 'short', month: 'short', day: 'numeric' };
            if (d.getFullYear() !== now.getFullYear()) opts.year = 'numeric';
            return d.toLocaleDateString(undefined, opts);
          }
          function regroup() {
            if (!thread) return;
            thread.querySelectorAll('.dm-day').forEach(function (el) { el.remove(); });
            const rows = Array.from(thread.querySelectorAll('.dm-msg'));
            let prevDay = '';
            rows.forEach(function (row, i) {
              const ts = row.getAttribute('data-dm-ts');
              const d = ts ? new Date(ts) : null;
              const valid = d && !isNaN(d.getTime());
              const key = valid ? dayKey(d) : prevDay;
              if (valid && key !== prevDay) {
                const sep = document.createElement('div');
                sep.className = 'dm-day';
                sep.setAttribute('role', 'separator');
                sep.textContent = dayLabel(d);
                row.parentNode.insertBefore(sep, row);
              }
              const time = row.querySelector('.dm-msg-meta time');
              if (time && valid && !time.dataset.local) {
                time.textContent = d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
                time.title = d.toLocaleString();
                time.dataset.local = '1';
              }
              const prev = rows[i - 1];
              const next = rows[i + 1];
              const dir = row.getAttribute('data-dm-dir');
              const sameAsPrev = prev && prev.getAttribute('data-dm-dir') === dir
                && (!valid || dayKey(new Date(prev.getAttribute('data-dm-ts') || 0)) === key);
              const sameAsNext = next && next.getAttribute('data-dm-dir') === dir
                && (!valid || dayKey(new Date(next.getAttribute('data-dm-ts') || 0)) === key);
              row.classList.toggle('is-grouped-prev', !!sameAsPrev);
              row.classList.toggle('is-grouped-next', !!sameAsNext);
              prevDay = key;
            });
          }

          // ---- scroll to latest ----
          let stickToBottom = true;
          function nearBottom() {
            return !thread || (thread.scrollHeight - thread.scrollTop - thread.clientHeight) < 80;
          }
          function focusLatestDm() {
            if (!thread || !stickToBottom) return;
            thread.scrollTop = thread.scrollHeight;
          }
          function scheduleFocus() {
            requestAnimationFrame(function () {
              focusLatestDm();
              requestAnimationFrame(focusLatestDm);
            });
          }
          const jump = document.createElement('button');
          jump.type = 'button';
          jump.className = 'btn btn-primary dm-jump';
          jump.textContent = '↓ New message';
          jump.hidden = true;
          jump.addEventListener('click', function () {
            stickToBottom = true;
            jump.hidden = true;
            focusLatestDm();
          });
          if (thread) {
            thread.parentNode.insertBefore(jump, thread.nextSibling);
            thread.addEventListener('scroll', function () {
              stickToBottom = nearBottom();
              if (stickToBottom) jump.hidden = true;
            }, { passive: true });
            regroup();
            [0, 50, 150, 400, 900].forEach(function (delay) { window.setTimeout(scheduleFocus, delay); });
            window.addEventListener('load', scheduleFocus, life ? { once: true, signal: life.signal } : { once: true });
            thread.addEventListener('load', function () { if (stickToBottom) focusLatestDm(); }, true);
          }

          // ---- live thread ----
          let lastId = thread ? (parseInt(thread.getAttribute('data-dm-last-id'), 10) || 0) : 0;
          let lastRev = thread ? (thread.getAttribute('data-dm-last-rev') || '') : '';
          let fetching = null;
          const cssId = function (v) { return (window.CSS && CSS.escape) ? CSS.escape(v) : String(v).replace(/["\\]/g, '\\$&'); };
          function appendRows(html) {
            if (!thread || !endMarker || !html) return { added: 0, incoming: 0 };
            const tpl = document.createElement('template');
            tpl.innerHTML = html;
            let added = 0;
            let incoming = 0;
            let lastInObject = '';
            Array.from(tpl.content.querySelectorAll('.dm-msg')).forEach(function (row) {
              const id = row.getAttribute('data-dm-id');
              if (id && thread.querySelector('.dm-msg[data-dm-id="' + cssId(id) + '"]')) return;
              thread.insertBefore(row, endMarker);
              added += 1;
              if (row.getAttribute('data-dm-dir') === 'in') {
                incoming += 1;
                lastInObject = row.getAttribute('data-dm-object') || lastInObject;
              }
            });
            if (added) {
              const empty = thread.querySelector('.dm-thread-empty');
              if (empty) empty.remove();
              regroup();
            }
            if (lastInObject) {
              const form = document.querySelector('form[data-dm-form="reply"]');
              if (form) {
                let field = form.querySelector('input[name="in_reply_to"]');
                if (!field) {
                  field = document.createElement('input');
                  field.type = 'hidden';
                  field.name = 'in_reply_to';
                  form.appendChild(field);
                }
                field.value = lastInObject;
                field.defaultValue = lastInObject;
              }
            }
            return { added: added, incoming: incoming };
          }
          function fetchNew() {
            if (!thread || (!peer && !bskyConvo)) return Promise.resolve();
            if (fetching) return fetching;
            const wasNear = nearBottom();
            const read = document.visibilityState === 'visible' ? '1' : '0';
            const url = bskyConvo
              ? '?ajax=bsky_dm_thread&convo=' + encodeURIComponent(bskyConvo) + '&after=' + encodeURIComponent(lastRev) + '&read=' + read
              : '?ajax=dm_thread&peer=' + encodeURIComponent(peer) + '&after=' + lastId + '&read=' + read;
            fetching = fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, cache: 'no-store' })
              .then(function (res) { return res.ok ? res.json() : null; })
              .then(function (data) {
                if (!data || !data.ok) return;
                const next = parseInt(data.last_id, 10) || 0;
                if (next > lastId) lastId = next;
                if (typeof data.last_rev === 'string' && data.last_rev > lastRev) lastRev = data.last_rev;
                if (data.reactions && typeof data.reactions === 'object') applyReactions(data.reactions);
                const result = appendRows(data.html || '');
                if (!result.added) return;
                if (wasNear) {
                  stickToBottom = true;
                  scheduleFocus();
                } else if (result.incoming) {
                  jump.hidden = false;
                }
                if (result.incoming && read === '1' && typeof window.vaakPollNotif === 'function') window.vaakPollNotif();
              })
              .catch(function () {})
              .finally(function () { fetching = null; });
            return fetching;
          }
          let listTimer = 0;
          function refreshList() {
            const list = document.getElementById('dm-conversation-list');
            if (!list) return;
            if (listTimer) window.clearTimeout(listTimer);
            listTimer = window.setTimeout(function () {
              fetch('?ajax=dm_list&peer=' + encodeURIComponent(peer) + '&bsky=' + encodeURIComponent(bskyConvo), { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, cache: 'no-store' })
                .then(function (res) { return res.ok ? res.json() : null; })
                .then(function (data) {
                  if (!alive || !data || !data.ok || typeof data.html !== 'string') return;
                  list.innerHTML = data.html;
                  applyFilter();
                  if (data.bsky_scope === false && !document.getElementById('dm-bsky-scope')) {
                    const note = document.createElement('div');
                    note.className = 'dm-bsky-scope meta';
                    note.id = 'dm-bsky-scope';
                    note.setAttribute('role', 'note');
                    note.textContent = 'Your Bluesky connection can’t read DMs. Reconnect Bluesky in ATmosphere with an app password that allows DMs.';
                    list.parentNode.insertBefore(note, list);
                  }
                  const badge = document.getElementById('dm-sidebar-unread');
                  if (badge) {
                    const n = parseInt(data.unread, 10) || 0;
                    badge.hidden = n < 1;
                    badge.textContent = n > 99 ? '99+' : String(n);
                  }
                })
                .catch(function () {});
            }, 400);
          }
          window.addEventListener('vaak:dm-activity', function () {
            fetchNew().then(refreshList);
          }, on);
          let pollTimer = 0;
          function schedulePoll() {
            if (pollTimer) window.clearTimeout(pollTimer);
            pollTimer = 0;
            if (!alive || (!peer && !bskyConvo) || document.visibilityState !== 'visible') return;
            pollTimer = window.setTimeout(function () {
              fetchNew().then(schedulePoll);
            }, 15000);
          }
          // Bluesky has no push into VAAK, so while this screen is visible the
          // list asks every 30 s (the server refreshes from Bluesky at most every 4 s).
          let bskyListTimer = 0;
          function scheduleBskyList() {
            if (bskyListTimer) window.clearTimeout(bskyListTimer);
            bskyListTimer = 0;
            if (!alive || !bskyOn || document.visibilityState !== 'visible') return;
            bskyListTimer = window.setTimeout(function () {
              refreshList();
              scheduleBskyList();
            }, 30000);
          }
          if (bskyOn) {
            refreshList();
            scheduleBskyList();
            document.addEventListener('visibilitychange', scheduleBskyList, on);
          }
          document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
              fetchNew().then(refreshList);
            }
            schedulePoll();
          }, on);
          schedulePoll();

          // ---- Bluesky reactions and conversation actions ----
          function applyReactions(map) {
            if (!thread) return;
            thread.querySelectorAll('.dm-reactions[data-dm-react-for]').forEach(function (slot) {
              const id = slot.getAttribute('data-dm-react-for');
              const html = Object.prototype.hasOwnProperty.call(map, id) ? String(map[id] || '') : '';
              if (slot.innerHTML !== html) slot.innerHTML = html;
            });
          }
          function bskyAction(fields) {
            const fd = new FormData();
            fd.set('action', 'bsky_dm_action');
            fd.set('convo', bskyConvo);
            Object.keys(fields).forEach(function (k) { fd.set(k, fields[k]); });
            if (typeof window.vaakCsrfApply === 'function') window.vaakCsrfApply(fd);
            return fetch('?view=dms&bsky=' + encodeURIComponent(bskyConvo), {
              method: 'POST', body: fd, credentials: 'same-origin',
              headers: { 'Accept': 'application/json', 'X-VAAK-DM': '1', 'X-VAAK-CSRF': window.VAAK_CSRF || '' },
            }).then(function (res) { return res.json().catch(function () { return { ok: false }; }); });
          }
          const QUICK_REACTIONS = ['❤️', '😂', '👍', '😮', '😢', '🔥'];
          let reactPop = null;
          function closeReactPop() {
            if (reactPop) { reactPop.remove(); reactPop = null; }
          }
          function sendReaction(messageId, value, remove) {
            closeReactPop();
            bskyAction({ op: remove ? 'unreact' : 'react', message: messageId, value: value }).then(function (data) {
              if (!data || !data.ok) { toast((data && data.error) || 'Could not react to that message.', true); return; }
              fetchNew();
            }).catch(function () { toast('Network error, reaction not sent.', true); });
          }
          if (thread && bskyConvo) {
            thread.addEventListener('click', function (event) {
              const opener = event.target.closest('[data-dm-react-open]');
              if (opener) {
                event.preventDefault();
                const id = opener.getAttribute('data-dm-react-open');
                const wasOpen = reactPop && reactPop.getAttribute('data-for') === id;
                closeReactPop();
                if (wasOpen) return;
                reactPop = document.createElement('div');
                reactPop.className = 'dm-react-pop';
                reactPop.setAttribute('role', 'toolbar');
                reactPop.setAttribute('aria-label', 'React');
                reactPop.setAttribute('data-for', id);
                QUICK_REACTIONS.forEach(function (emoji) {
                  const b = document.createElement('button');
                  b.type = 'button';
                  b.textContent = emoji;
                  b.setAttribute('aria-label', 'React ' + emoji);
                  b.addEventListener('click', function () {
                    const mine = thread.querySelector('.dm-reactions[data-dm-react-for="' + cssId(id) + '"] .dm-reaction.is-mine[data-dm-react="' + cssId(emoji) + '"]');
                    sendReaction(id, emoji, !!mine);
                  });
                  reactPop.appendChild(b);
                });
                opener.parentNode.appendChild(reactPop);
                const first = reactPop.querySelector('button');
                if (first) first.focus();
                return;
              }
              const chip = event.target.closest('.dm-reaction[data-dm-react]');
              if (chip) {
                const slot = chip.closest('[data-dm-react-for]');
                if (slot) sendReaction(slot.getAttribute('data-dm-react-for'), chip.getAttribute('data-dm-react'), chip.classList.contains('is-mine'));
              }
            });
            document.addEventListener('click', function (event) {
              if (reactPop && !event.target.closest('.dm-react-pop, [data-dm-react-open]')) closeReactPop();
            }, on);
            document.addEventListener('keydown', function (event) {
              if (event.key === 'Escape') closeReactPop();
            }, on);
          }
          // Leave/decline need a second tap; nothing here opens a browser dialog.
          document.querySelectorAll('form[data-dm-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
              const button = form.querySelector('button[type="submit"]');
              if (form.dataset.dmArmed === '1') return;
              event.preventDefault();
              form.dataset.dmArmed = '1';
              if (button) {
                button.dataset.label = button.textContent;
                button.textContent = form.getAttribute('data-dm-confirm');
                button.classList.add('is-armed');
              }
              window.setTimeout(function () {
                form.dataset.dmArmed = '0';
                if (button && button.dataset.label) { button.textContent = button.dataset.label; button.classList.remove('is-armed'); }
              }, 4000);
            });
          });

          // ---- conversation search ----
          const filterInput = document.getElementById('dm-conversation-filter');
          function applyFilter() {
            const list = document.getElementById('dm-conversation-list');
            const empty = document.getElementById('dm-filter-empty');
            if (!list || !filterInput) return;
            const q = filterInput.value.trim().toLowerCase();
            let shown = 0;
            list.querySelectorAll('.dm-conversation-row').forEach(function (row) {
              const hay = row.getAttribute('data-dm-search') || row.textContent.toLowerCase();
              const hit = !q || hay.indexOf(q) !== -1;
              row.hidden = !hit;
              if (hit) shown += 1;
            });
            if (empty) empty.hidden = shown > 0 || !q;
          }
          if (filterInput) filterInput.addEventListener('input', applyFilter);

          // ---- composers ----
          document.querySelectorAll('form[data-dm-form]').forEach(function (form) {
            const kind = form.getAttribute('data-dm-form');
            const textarea = form.querySelector('textarea[name="content"]');
            const extras = form.querySelector('[data-dm-extras]');
            const toggle = form.querySelector('[data-dm-extras-toggle]');
            const status = form.querySelector('.dm-form-status');
            const statusDefault = status ? status.textContent : '';
            const submit = form.querySelector('button[type="submit"]');
            const enterHint = form.querySelector('.dm-enter-hint');
            function setExtras(open) {
              if (!extras || !toggle) return;
              extras.hidden = !open;
              toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
              toggle.classList.toggle('is-active', open);
            }
            if (extras && toggle) {
              toggle.hidden = false;
              setExtras(false);
              toggle.addEventListener('click', function () { setExtras(extras.hidden); });
              form.addEventListener('vaak:dm-files', function (event) {
                if (event.detail && event.detail.count > 0) setExtras(true);
              });
            }
            function autosize() {
              if (!textarea || kind !== 'reply') return;
              textarea.style.height = 'auto';
              textarea.style.height = Math.min(textarea.scrollHeight + 2, 180) + 'px';
            }
            if (textarea) {
              textarea.addEventListener('input', autosize);
              if (!coarse && enterHint) enterHint.hidden = false;
              textarea.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' || event.shiftKey || event.isComposing || coarse || kind !== 'reply') return;
                event.preventDefault();
                if (typeof form.requestSubmit === 'function') form.requestSubmit();
                else form.dispatchEvent(new Event('submit', { cancelable: true }));
              });
            }
            function setStatus(text, isErr) {
              if (!status) return;
              status.textContent = text;
              status.classList.toggle('is-error', !!isErr);
            }
            form.addEventListener('submit', function (event) {
              if (typeof FormData !== 'function' || typeof fetch !== 'function') return;
              event.preventDefault();
              if (form.dataset.dmSending === '1') return;
              const fd = new FormData(form);
              const text = String(fd.get('content') || '').trim();
              const files = form.querySelector('.dm-media-input');
              const hasFiles = files && files.files && files.files.length > 0;
              if (!text && !hasFiles) {
                setStatus('Type a message or add an attachment.', true);
                if (textarea) textarea.focus();
                return;
              }
              if (typeof window.vaakCsrfApply === 'function') window.vaakCsrfApply(fd);
              form.dataset.dmSending = '1';
              if (submit) submit.disabled = true;
              setStatus(hasFiles ? 'Uploading and sending…' : 'Sending…', false);
              fetch(form.getAttribute('action') || '?view=dms', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-VAAK-DM': '1', 'X-VAAK-CSRF': window.VAAK_CSRF || '' },
              })
                .then(function (res) {
                  return res.json().catch(function () { return { ok: false, error: 'Could not send (HTTP ' + res.status + ').' }; });
                })
                .then(function (data) {
                  if (!data || !data.ok) {
                    const msg = (data && data.error) || 'Could not send that message.';
                    setStatus(msg, true);
                    toast(msg, true);
                    return;
                  }
                  if (kind === 'new') {
                    const nextHref = data.href || ('?view=dms&peer=' + encodeURIComponent(data.peer || ''));
                    if (life) window.vaakDmGo(new URL(nextHref, window.location.href).href, true);
                    else window.location.assign(nextHref);
                    return;
                  }
                  form.reset();
                  setExtras(false);
                  autosize();
                  setStatus(data.delivered ? statusDefault : 'Saved here, but their server did not accept it. Try again later if they do not reply.', !data.delivered);
                  stickToBottom = true;
                  fetchNew().then(refreshList);
                  if (textarea && !coarse) textarea.focus();
                })
                .catch(function () {
                  setStatus('Network error. Your message was not sent.', true);
                })
                .finally(function () {
                  form.dataset.dmSending = '0';
                  if (submit) submit.disabled = false;
                  // The composer reports progress itself; never leave the
                  // page-level Saving… pill up after an in-place send.
                  if (typeof window.vaakHideLoading === 'function') window.vaakHideLoading();
                });
            });
          });

          // ---- New message picker ----
          const to = document.getElementById('dm-to');
          const pickList = document.getElementById('dm-picker-list');
          if (to && pickList) {
            let pickTimer = 0;
            let pickSeq = 0;
            let active = -1;
            let people = [];
            function closePicker() {
              pickList.hidden = true;
              to.setAttribute('aria-expanded', 'false');
              to.removeAttribute('aria-activedescendant');
              active = -1;
            }
            function choose(i) {
              const p = people[i];
              if (!p) return;
              to.value = p.handle || p.actor_id;
              to.dataset.actor = p.actor_id;
              closePicker();
              const content = document.getElementById('dm-new-content');
              if (content) content.focus();
            }
            function paint() {
              pickList.replaceChildren();
              people.forEach(function (p, i) {
                const li = document.createElement('li');
                li.id = 'dm-pick-' + i;
                li.className = 'dm-pick' + (i === active ? ' is-active' : '');
                li.setAttribute('role', 'option');
                li.setAttribute('aria-selected', i === active ? 'true' : 'false');
                const av = document.createElement('span');
                av.className = 'dm-pick-av';
                av.innerHTML = p.avatar || '';
                const copy = document.createElement('span');
                copy.className = 'dm-pick-copy';
                const name = document.createElement('span');
                name.className = 'dm-pick-name';
                name.textContent = p.name || p.handle;
                const handle = document.createElement('span');
                handle.className = 'meta';
                handle.textContent = (p.handle || p.actor_id) + (p.local ? ' · on VAAK' : (p.network === 'bluesky' ? ' · Bluesky' : ''));
                copy.appendChild(name);
                copy.appendChild(handle);
                li.appendChild(av);
                li.appendChild(copy);
                li.addEventListener('mousedown', function (event) { event.preventDefault(); choose(i); });
                pickList.appendChild(li);
              });
              const open = people.length > 0;
              pickList.hidden = !open;
              to.setAttribute('aria-expanded', open ? 'true' : 'false');
              if (active >= 0) to.setAttribute('aria-activedescendant', 'dm-pick-' + active);
              else to.removeAttribute('aria-activedescendant');
            }
            to.addEventListener('input', function () {
              delete to.dataset.actor;
              const q = to.value.trim();
              if (pickTimer) window.clearTimeout(pickTimer);
              if (q.replace(/^@/, '').length < 2 || /^https:\/\//.test(q)) { people = []; closePicker(); return; }
              const seq = ++pickSeq;
              pickTimer = window.setTimeout(function () {
                fetch('?ajax=dm_people&q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, cache: 'no-store' })
                  .then(function (res) { return res.ok ? res.json() : null; })
                  .then(function (data) {
                    if (seq !== pickSeq || !data || !Array.isArray(data.people)) return;
                    people = data.people;
                    active = people.length ? 0 : -1;
                    paint();
                  })
                  .catch(function () {});
              }, 250);
            });
            to.addEventListener('keydown', function (event) {
              if (pickList.hidden || !people.length) return;
              if (event.key === 'ArrowDown') { event.preventDefault(); active = (active + 1) % people.length; paint(); }
              else if (event.key === 'ArrowUp') { event.preventDefault(); active = (active - 1 + people.length) % people.length; paint(); }
              else if (event.key === 'Enter' && active >= 0) { event.preventDefault(); choose(active); }
              else if (event.key === 'Escape') { closePicker(); }
            });
            to.addEventListener('blur', function () { window.setTimeout(closePicker, 120); });
            // Send the exact account picked, not just the visible handle.
            const newForm = to.form;
            if (newForm) {
              newForm.addEventListener('formdata', function (event) {
                if (to.dataset.actor) event.formData.set('to', to.dataset.actor);
              });
            }
            if (workspace.classList.contains('is-compose-open') || window.matchMedia('(min-width: 761px)').matches) {
              if (!to.value) to.focus();
            }
          }
        };
        window.vaakDmBoot();
