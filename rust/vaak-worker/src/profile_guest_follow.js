              (function () {
                var ACTOR = __NATIVE_ACTOR_JSON__;
                var toggle = document.getElementById('rp-guest-follow-toggle');
                var panel = document.getElementById('rp-guest-follow-panel');
                var form = document.getElementById('rp-guest-follow-form');
                var input = document.getElementById('rp-guest-follow-handle');
                var hint = document.getElementById('rp-guest-follow-hint');
                if (!toggle || !panel || !form || !input) return;
                function setErr(m) {
                  if (!hint) return;
                  if (m) { hint.textContent = m; hint.classList.add('is-error'); }
                  else { hint.textContent = 'Opens your instance’s follow dialog (Mastodon, Akkoma, GoToSocial, etc.).'; hint.classList.remove('is-error'); }
                }
                function parseHandle(raw) {
                  var s = String(raw || '').trim();
                  if (s.indexOf('acct:') === 0) s = s.slice(5);
                  if (s.charAt(0) !== '@') s = '@' + s;
                  var m = s.match(/^@([A-Za-z0-9_.\-]+)@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})$/);
                  return m ? { user: m[1], host: m[2].toLowerCase() } : null;
                }
                toggle.addEventListener('click', function () {
                  var open = panel.hasAttribute('hidden');
                  if (open) {
                    panel.removeAttribute('hidden');
                    toggle.setAttribute('aria-expanded', 'true');
                    setErr('');
                    setTimeout(function () { input.focus(); }, 0);
                  } else {
                    panel.setAttribute('hidden', '');
                    toggle.setAttribute('aria-expanded', 'false');
                  }
                });
                form.addEventListener('submit', function (ev) {
                  ev.preventDefault();
                  var p = parseHandle(input.value);
                  if (!p) { setErr('Use a full address like @you@mastodon.social'); input.focus(); return; }
                  setErr('');
                  window.open('https://' + p.host + '/authorize_interaction?uri=' + encodeURIComponent(ACTOR), '_blank', 'noopener,noreferrer');
                });
              })();
