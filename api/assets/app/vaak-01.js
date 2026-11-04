/* VAAK app script 1. Moved out of api/ap-admin.php (was an inline <script>) so
   browsers cache and precompile it; the shell loads these files in their old order.
*/
        window.vaakDmMediaBoot = function () {
          document.querySelectorAll('form.composer .dm-media-input').forEach(function (input) {
            const form = input.form;
            if (!form || form.dataset.dmMediaBound === '1' || typeof DataTransfer !== 'function') return;
            form.dataset.dmMediaBound = '1';
            const slot = form.querySelector('.dm-media-alts');
            const dt = new DataTransfer();
            const dmAlts = [];
            let syncing = false;
            function captureAlts() {
              if (!slot) return;
              const inputs = slot.querySelectorAll('input[name^="media_alt"]');
              for (let i = 0; i < dmAlts.length; i++) {
                if (inputs[i]) dmAlts[i] = inputs[i].value;
              }
            }
            function render() {
              if (!slot) return;
              while (dmAlts.length < dt.files.length) dmAlts.push('');
              if (dmAlts.length > dt.files.length) dmAlts.length = dt.files.length;
              slot.replaceChildren();
              Array.from(dt.files).forEach(function (file, i) {
                const row = document.createElement('div');
                row.className = 'dm-media-alt-row';
                const name = document.createElement('div');
                name.className = 'meta';
                name.textContent = file.name || 'Attachment';
                const alt = document.createElement('input');
                alt.type = 'text';
                alt.name = 'media_alt[' + i + ']';
                alt.maxLength = 1500;
                alt.placeholder = 'Describe this attachment (optional)';
                alt.autocomplete = 'off';
                alt.value = dmAlts[i] || '';
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'btn btn-ghost';
                remove.textContent = 'Remove';
                remove.addEventListener('click', function () {
                  captureAlts();
                  dmAlts.splice(i, 1);
                  const next = new DataTransfer();
                  Array.from(dt.files).forEach(function (kept, j) {
                    if (j !== i) next.items.add(kept);
                  });
                  dt.items.clear();
                  Array.from(next.files).forEach(function (kept) { dt.items.add(kept); });
                  syncing = true;
                  input.files = dt.files;
                  syncing = false;
                  render();
                });
                row.appendChild(name);
                row.appendChild(alt);
                row.appendChild(remove);
                slot.appendChild(row);
              });
              form.dispatchEvent(new CustomEvent('vaak:dm-files', { detail: { count: dt.files.length } }));
            }
            function dmLive() {
              let live = form.querySelector('.dm-media-live');
              if (live) return live;
              live = document.createElement('div');
              live.className = 'dm-media-live';
              live.setAttribute('role', 'status');
              live.setAttribute('aria-live', 'polite');
              live.setAttribute('aria-atomic', 'true');
              form.appendChild(live);
              return live;
            }
            function addDmFiles(picked, opts) {
              const api = window.vaakPasteMedia;
              let accepted = [];
              let errors = [];
              if (api && api.filterMediaFiles) {
                const filtered = api.filterMediaFiles(dt.files.length, picked, 4);
                accepted = filtered.accepted;
                errors = filtered.errors;
              } else {
                const list = picked && typeof picked.length === 'number' ? picked : [];
                for (let i = 0; i < list.length; i++) {
                  const file = list[i];
                  if (!file || typeof file.size !== 'number') continue;
                  if (dt.files.length + accepted.length >= 4) {
                    errors.push('Too many media attachments (max 4).');
                    break;
                  }
                  accepted.push(file);
                }
              }
              captureAlts();
              accepted.forEach(function (file) {
                dt.items.add(file);
                dmAlts.push('');
              });
              errors.forEach(function (msg) {
                if (window.apAdminToast) window.apAdminToast(msg, true);
              });
              syncing = true;
              input.files = dt.files;
              syncing = false;
              render();
              if (!opts || !opts.silent) {
                const parts = [];
                const notice = api && api.attachmentNotice
                  ? api.attachmentNotice(accepted)
                  : (accepted.length === 1 ? 'Attachment added.' : (accepted.length > 1 ? accepted.length + ' attachments added.' : ''));
                if (notice) parts.push(notice);
                if (errors.length) parts.push(errors[errors.length - 1]);
                if (api && api.announceLive) api.announceLive(dmLive(), parts.join(' '));
              }
              return { accepted: accepted, errors: errors };
            }
            input.addEventListener('change', function () {
              if (syncing) return;
              addDmFiles(Array.from(input.files || []));
            });
            // A message sent without a page load resets the form; drop the
            // kept file list and alt texts with it.
            form.addEventListener('reset', function () {
              dt.items.clear();
              dmAlts.length = 0;
              syncing = true;
              input.files = dt.files;
              syncing = false;
              render();
            });
            // Same clipboard rule as the post composer: images attach, text pastes through.
            form.addEventListener('paste', function (event) {
              const api = window.vaakPasteMedia;
              if (!api || !api.clipboardImageFiles || !event.clipboardData) return;
              let clip = null;
              try { clip = api.clipboardImageFiles(event.clipboardData); } catch (e) { return; }
              if (!clip || !clip.sawFile) return;
              event.preventDefault();
              const parts = [];
              if (clip.rejected) {
                if (window.apAdminToast) window.apAdminToast(api.pasteTypeMessage, true);
                parts.push(api.pasteTypeMessage);
              }
              if (clip.unreadable && !clip.images.length) {
                if (window.apAdminToast) window.apAdminToast(api.pasteReadMessage, true);
                parts.push(api.pasteReadMessage);
              }
              const added = clip.images.length ? addDmFiles(clip.images, { silent: true }) : { accepted: [], errors: [] };
              const notice = api.attachmentNotice(added.accepted || []);
              if (notice) parts.unshift(notice);
              (added.errors || []).forEach(function (msg) {
                if (parts.indexOf(msg) === -1) parts.push(msg);
              });
              api.announceLive(dmLive(), parts.join(' '));
            });
            let dragDepth = 0;
            function hasDraggedFiles(event) {
              const types = event && event.dataTransfer && event.dataTransfer.types;
              if (!types) return false;
              for (let i = 0; i < types.length; i++) {
                if (types[i] === 'Files') return true;
              }
              return false;
            }
            form.addEventListener('dragenter', function (event) {
              if (!hasDraggedFiles(event)) return;
              event.preventDefault();
              dragDepth += 1;
              form.classList.add('is-dragover');
            });
            form.addEventListener('dragover', function (event) {
              if (!hasDraggedFiles(event)) return;
              event.preventDefault();
              if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy';
              form.classList.add('is-dragover');
            });
            form.addEventListener('dragleave', function (event) {
              if (!hasDraggedFiles(event)) return;
              event.preventDefault();
              dragDepth = Math.max(0, dragDepth - 1);
              if (!dragDepth) form.classList.remove('is-dragover');
            });
            form.addEventListener('drop', function (event) {
              if (!hasDraggedFiles(event)) return;
              event.preventDefault();
              dragDepth = 0;
              form.classList.remove('is-dragover');
              addDmFiles(Array.from((event.dataTransfer && event.dataTransfer.files) || []));
            });
          });
        };
        window.vaakDmMediaBoot();
