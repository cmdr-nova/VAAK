// A like/boost/bookmark saved on one copy of a post (e.g. a mention in
// Notifications) must update every other copy, including the Home column the
// soft-nav controller parked while Notifications was open.
// Extracts vaakSyncInteractionState from api/assets/app/vaak-03.js and drives
// it with a small fake DOM.
const fs = require('fs');
const vm = require('vm');
const path = require('path');

const file = process.argv[2] || path.join(__dirname, '..', 'assets', 'app', 'vaak-03.js');
const source = fs.readFileSync(file, 'utf8');
const start = source.indexOf('  const SYNC_ACTIONS = {');
const end = source.indexOf('  function snapshotInteractButton(form) {', start);
if (start < 0 || end < start) {
  console.error('interaction sync markers missing');
  process.exit(1);
}
const js = source.slice(start, end);
const parked = fs.readFileSync(path.join(__dirname, '..', 'assets', 'app', 'vaak-09.js'), 'utf8');
if (!parked.includes('window.vaakParkedColumnRoots = function')) {
  console.error('vaak-09.js no longer exposes parked column roots');
  process.exit(1);
}
const bsky = fs.readFileSync(path.join(__dirname, '..', 'assets', 'app', 'vaak-05.js'), 'utf8');
if (!bsky.includes('window.vaakApplyBskyState = function') || !bsky.includes('window.vaakSyncInteractionState(syncKind')) {
  console.error('vaak-05.js no longer syncs Bluesky actions');
  process.exit(1);
}
if (!source.includes('window.vaakSyncInteractionState(data.kind, data.active,')) {
  console.error('vaak-03.js no longer syncs after a saved interaction');
  process.exit(1);
}

let failures = 0;
function check(ok, label) {
  if (!ok) {
    failures++;
    console.error('FAIL ' + label);
  }
}

function fedForm(action, objectId, on) {
  const btn = { classList: { on: !!on, contains(c) { return c === 'on' && this.on; } } };
  const form = { dataset: {}, btn };
  const actionInput = { value: action };
  const objectInput = { name: 'object_id', value: objectId, closest: () => form };
  form.querySelector = (sel) => sel === 'input[name="action"]' ? actionInput
    : sel === 'button[type="submit"]' ? btn
    : sel === 'input[name="object_id"]' ? objectInput : null;
  form.objectInput = objectInput;
  form.actionInput = actionInput;
  return form;
}
function bskyBtn(kind, uri, on) {
  return {
    dataset: { bskyAction: kind, uri: uri },
    classList: { on: !!on, contains(c) { return c === 'on' && this.on; } },
    closest: () => null,
  };
}
function root(forms, btns) {
  return {
    querySelectorAll(sel) {
      if (sel === 'form input[name="object_id"]') return forms.map((f) => f.objectInput);
      const m = sel.match(/data-bsky-action="(\w+)"/);
      if (m) return btns.filter((b) => b.dataset.bskyAction === m[1]);
      return [];
    },
  };
}

const applied = [];
const notifLike = fedForm('unfavourite_status', 'https://app.wafrn.net/fediverse/post/abc', true);
const homeLike = fedForm('favourite_status', 'https://app.wafrn.net/fediverse/post/abc/', false);
const homeOther = fedForm('favourite_status', 'https://example.com/notes/9', false);
const homeBoost = fedForm('reblog_status', 'https://app.wafrn.net/fediverse/post/abc', false);
const homeBskyLike = bskyBtn('like', 'at://did:plc:x/app.bsky.feed.post/1', false);
const liveRoot = root([notifLike], []);
const parkedRoot = root([homeLike, homeOther, homeBoost], [homeBskyLike]);
const window = {
  vaakParkedColumnRoots: () => [parkedRoot],
  vaakApplyBskyState: (btn, kind, on) => { applied.push(['bsky', kind, on]); btn.classList.on = on; },
};
const ctx = {
  window,
  document: liveRoot,
  applyInteractButton: (form, data, before) => {
    applied.push([form.actionInput.value, data.kind, data.active, before]);
    form.btn.classList.on = data.active;
  },
};
vm.createContext(ctx);
vm.runInContext(js, ctx);

window.vaakSyncInteractionState('favourite', true, ['https://app.wafrn.net/fediverse/post/abc'], notifLike);
check(homeLike.btn.classList.on === true, 'parked Home copy shows the like');
check(notifLike.btn.classList.on === true && applied.every((a) => a[0] !== 'unfavourite_status'), 'origin form is left alone');
check(homeOther.btn.classList.on === false, 'other posts are untouched');
check(homeBoost.btn.classList.on === false, 'a like does not flip the boost button');

window.vaakSyncInteractionState('bookmark', true, ['https://app.wafrn.net/fediverse/post/abc'], notifLike);
check(applied.length === 1, 'no bookmark form for the post means nothing changes');

window.vaakSyncInteractionState('favourite', true, ['bsky:at://did:plc:x/app.bsky.feed.post/1'], null);
check(homeBskyLike.classList.on === true, 'Bluesky copy matched by at:// URI');

const before = applied.length;
window.vaakSyncInteractionState('favourite', true, ['https://app.wafrn.net/fediverse/post/abc'], null);
check(applied.length === before, 'already-liked copies are not re-applied (counts stay put)');

window.vaakSyncInteractionState('reblog', true, ['https://app.wafrn.net/fediverse/post/abc#announce-12'], null);
check(homeBoost.btn.classList.on === true, 'boost fragment is ignored when matching');

if (failures) process.exit(1);
console.log('interaction-sync-regression ok');
