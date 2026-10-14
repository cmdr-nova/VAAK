// Reply posts must not reload or rebuild the page they were sent from.
// Extracts the production follow-up helpers from ap-admin.php and checks the
// decision used by the compose submit handler.
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const path = require('path');

const file = process.argv[2] || path.join(__dirname, '..', 'ap-admin.php');
const source = fs.readFileSync(file, 'utf8');
const begin = source.indexOf('/* vaak-compose-followup:start */');
const end = source.indexOf('/* vaak-compose-followup:end */', begin);
assert(begin > 0 && end > begin, 'compose follow-up markers');
const js = source.slice(begin, end);

function createEl(tag) {
  const el = {
    tagName: String(tag || 'div').toUpperCase(),
    id: '',
    children: [],
    parentElement: null,
    _attr: {},
  };
  el.classList = {
    _set: new Set(),
    add(...names) { names.forEach((name) => name && el.classList._set.add(name)); },
    contains(name) { return el.classList._set.has(name); },
  };
  el.getAttribute = (key) => (Object.prototype.hasOwnProperty.call(el._attr, key) ? el._attr[key] : null);
  el.setAttribute = (key, value) => {
    el._attr[key] = String(value);
    if (key === 'id') el.id = String(value);
    if (key === 'class') el.classList._set = new Set(String(value).split(/\s+/).filter(Boolean));
  };
  const matchOne = (node, sel) => {
    if (!node || !sel) return false;
    let rest = sel;
    const attrs = [];
    rest = rest.replace(/\[([^\]]+)\]/g, (_, body) => { attrs.push(body); return ''; });
    const classes = [];
    rest = rest.replace(/\.([A-Za-z0-9_-]+)/g, (_, cls) => { classes.push(cls); return ''; });
    let id = '';
    rest = rest.replace(/#([A-Za-z0-9_-]+)/g, (_, found) => { id = found; return ''; });
    const tagName = rest.trim().toLowerCase();
    if (tagName && node.tagName.toLowerCase() !== tagName) return false;
    if (id && node.id !== id) return false;
    if (classes.some((cls) => !node.classList.contains(cls))) return false;
    for (const attr of attrs) {
      const found = attr.match(/^([^*\s~|^$=]+)(\*=|=)?(.*)$/);
      if (!found) return false;
      const have = node.getAttribute(found[1]);
      if (have == null) return false;
      const value = (found[3] || '').replace(/^["']|["']$/g, '');
      if (found[2] === '*=' && !String(have).includes(value)) return false;
      if (found[2] === '=' && String(have) !== value) return false;
    }
    return true;
  };
  const match = (node, sel) => String(sel).split(',').some((part) => matchOne(node, part.trim()));
  el.querySelectorAll = (sel) => {
    const out = [];
    const walk = (node) => {
      (node.children || []).forEach((child) => {
        if (match(child, sel)) out.push(child);
        walk(child);
      });
    };
    walk(el);
    return out;
  };
  el.querySelector = (sel) => el.querySelectorAll(sel)[0] || null;
  el.appendChild = (child) => {
    child.parentElement = el;
    el.children.push(child);
    return child;
  };
  return el;
}

const parent = 'https://example.test/users/ada/notes/parent';
const child = 'https://example.test/users/ada/notes/child';
const bsky = 'at://did:plc:fixture/app.bsky.feed.post/replytarget';

function threadDocument() {
  const childCard = createEl('article');
  childCard.setAttribute('data-note-id', child + '/');
  const childReply = createEl('a');
  childReply.setAttribute('href', '?view=status&compose=1&reply_to=' + encodeURIComponent(child));
  childCard.appendChild(childReply);
  const descendants = createEl('div');
  descendants.setAttribute('id', 'status-thread-descendants');
  descendants.setAttribute('data-object', parent);
  descendants.appendChild(childCard);
  const back = createEl('div');
  back.setAttribute('class', 'page-back status-back');
  const main = createEl('section');
  main.setAttribute('class', 'main');
  main.appendChild(back);
  main.appendChild(descendants);
  const other = createEl('a');
  other.setAttribute('href', '?view=status&object=' + encodeURIComponent('https://example.test/users/ada/notes/elsewhere'));
  main.appendChild(other);
  const body = createEl('body');
  body.appendChild(main);
  return {
    getElementById: (id) => body.querySelector('#' + id),
    querySelector: (sel) => body.querySelector(sel),
    querySelectorAll: (sel) => body.querySelectorAll(sel),
  };
}

function listDocument() {
  const card = createEl('article');
  const reply = createEl('a');
  reply.setAttribute('href', '?view=mentions&compose=1&reply_to=' + encodeURIComponent(parent));
  card.appendChild(reply);
  const items = createEl('div');
  items.setAttribute('id', 'timeline-items');
  items.setAttribute('data-view', 'mentions');
  items.appendChild(card);
  const body = createEl('body');
  body.appendChild(items);
  return {
    getElementById: (id) => body.querySelector('#' + id),
    querySelector: (sel) => body.querySelector(sel),
    querySelectorAll: (sel) => body.querySelectorAll(sel),
  };
}

const checks = [];
function check(ok, label) {
  assert.ok(ok, label);
  checks.push(label);
}

const win = {
  location: { href: 'https://example.test/vaak/?view=status&object=' + encodeURIComponent(parent) },
};
const ctx = vm.createContext({
  window: win,
  document: threadDocument(),
  URL,
  URLSearchParams,
  decodeURIComponent,
  String,
});
vm.runInContext(js, ctx);
const decide = ctx.window.vaakDecideComposeFollowup;
const contains = ctx.window.vaakOpenThreadContainsPost;
assert.strictEqual(typeof decide, 'function');
assert.strictEqual(typeof contains, 'function');

check(contains(parent), 'open thread matches the focused parent, including its data-object');
check(contains(child), 'open thread matches a reply already shown in that thread');
check(contains(parent + '/'), 'trailing slash does not hide the parent');
check(!contains('https://example.test/users/ada/notes/missing'), 'a post outside the open thread is not treated as contained');

ctx.document = listDocument();
win.location.href = 'https://example.test/vaak/?view=mentions';
check(!contains(parent), 'a notifications card is not an open thread');

ctx.document = threadDocument();
win.location.href = 'https://example.test/vaak/?view=status&object=' + encodeURIComponent(parent);
const bskyCard = createEl('article');
bskyCard.setAttribute('data-bsky-uri', bsky);
ctx.document.getElementById('status-thread-descendants').appendChild(bskyCard);
check(contains(bsky), 'open Bluesky thread matches the at:// parent');
check(!contains('at://did:plc:fixture/app.bsky.feed.post/other'), 'a different Bluesky post is not in this thread');

// Surfaces that used to rebuild after a reply. The poller is present on the
// timeline-backed pages; preservingRefresh false models a page that only has
// the older poll+insert path. Either way a reply must stay put.
const replySurfaces = [
  ['notifications', { poller: true, preservingRefresh: true }],
  ['home', { poller: true, preservingRefresh: true }],
  ['local', { poller: true, preservingRefresh: true }],
  ['federated', { poller: true, preservingRefresh: true }],
  ['bluesky', { poller: true, preservingRefresh: true }],
  ['your posts', { poller: true, preservingRefresh: true }],
  ['profile', { poller: true, preservingRefresh: true }],
  ['search', { poller: false, preservingRefresh: false }],
  ['library favourites', { poller: false, preservingRefresh: false }],
  ['library bookmarks', { poller: false, preservingRefresh: false }],
  ['list timeline', { poller: false, preservingRefresh: false }],
  ['poll-insert only', { poller: true, preservingRefresh: false }],
];
for (const [name, flags] of replySurfaces) {
  const outcome = decide({
    mode: 'reply',
    isReply: true,
    threadContainsParent: false,
    noteId: 'https://example.test/users/me/notes/new',
    statusPage: false,
    poller: flags.poller,
    preservingRefresh: flags.preservingRefresh,
  });
  check(outcome === 'stay', name + ' reply stays on the page');
}

check(decide({
  mode: 'reply',
  isReply: true,
  threadContainsParent: true,
  noteId: 'https://example.test/users/me/notes/new',
  statusPage: true,
  poller: true,
  preservingRefresh: true,
}) === 'hydrate-thread', 'open thread that contains the parent hydrates only that reply');

check(decide({
  mode: 'reply',
  isReply: true,
  threadContainsParent: true,
  noteId: '',
  statusPage: true,
  poller: false,
  preservingRefresh: false,
}) === 'stay', 'a reply with no note id does not reload while waiting for an id');

check(decide({
  mode: 'reply',
  isReply: true,
  threadContainsParent: false,
  noteId: 'https://example.test/users/me/notes/new',
  statusPage: true,
  poller: true,
  preservingRefresh: true,
}) === 'stay', 'an open thread that does not contain the parent is left alone');

check(decide({
  mode: 'reply',
  isReply: false,
  threadContainsParent: false,
  noteId: 'https://example.test/users/me/notes/new',
  statusPage: false,
  poller: true,
  preservingRefresh: true,
}) === 'refresh-timeline', 'an original Home post still uses the timeline insert');

check(decide({
  mode: 'reply',
  isReply: false,
  threadContainsParent: false,
  noteId: 'https://example.test/users/me/notes/new',
  statusPage: false,
  poller: true,
  preservingRefresh: false,
}) === 'poll-insert', 'original posts keep the poll-and-insert fallback');

check(decide({
  mode: 'reply',
  isReply: false,
  threadContainsParent: false,
  noteId: 'https://example.test/users/me/notes/new',
  statusPage: false,
  poller: false,
  preservingRefresh: false,
}) === 'reload', 'original posts off a timeline still reload');

check(decide({
  mode: 'edit_status',
  isReply: false,
  threadContainsParent: false,
  noteId: parent,
  statusPage: true,
  poller: true,
  preservingRefresh: true,
}) === 'hydrate-edit', 'editing the open thread still replaces that card');

check(decide({
  mode: 'edit_status',
  isReply: false,
  threadContainsParent: false,
  noteId: parent,
  statusPage: false,
  poller: true,
  preservingRefresh: true,
}) === 'reload', 'editing off the thread still reloads');

check(decide({
  mode: 'queue_post',
  isReply: false,
  noteId: '',
  statusPage: false,
  poller: true,
  preservingRefresh: true,
}) === 'stay', 'queue handoff still leaves the current page in place');

check(decide({
  mode: 'reply',
  isReply: false,
  threadContainsParent: false,
  noteId: 'https://example.test/users/me/notes/quote',
  statusPage: true,
  poller: true,
  preservingRefresh: true,
}) === 'hydrate-thread', 'a quote posted from an open thread still tries the thread fragment');

const handlerAt = source.indexOf('const followup = vaakDecideComposeFollowup({');
const refreshCallAt = source.indexOf('novaRefreshTimelinePreservingPosition({ freshPost: true', handlerAt);
const reloadAt = source.indexOf('window.location.reload();', refreshCallAt);
const stayAt = source.indexOf("if (followup === 'stay')", handlerAt);
const hydrateAt = source.indexOf("if (followup === 'hydrate-thread')", handlerAt);
check(handlerAt > 0 && stayAt > handlerAt && hydrateAt > stayAt && refreshCallAt > hydrateAt && reloadAt > refreshCallAt,
  'document reload stays behind the reply stay and thread-hydrate returns');

const refreshAt = source.indexOf('function refreshTimelineAfterPublish()');
const refreshEnd = source.indexOf('async function watchInteractQueue', refreshAt);
check(refreshAt > 0 && refreshEnd > refreshAt && !source.slice(refreshAt, refreshEnd).includes('location.reload'),
  'favourite, boost, and bookmark refresh does not reload the page');

const replyBlock = source.slice(stayAt, refreshCallAt);
check(!replyBlock.includes('novaRefreshTimelinePreservingPosition')
  && !replyBlock.includes('novaPollTimeline')
  && !replyBlock.includes('location.reload'),
  'the reply and thread-hydrate returns do not poll or rebuild a timeline');

console.log(JSON.stringify({ file, checks: checks.length, passed: true, labels: checks }, null, 2));
