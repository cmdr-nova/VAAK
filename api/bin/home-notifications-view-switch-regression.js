// Home -> Notifications -> Home must reuse the painted columns.
// Extracts the production soft-nav controller and drives it with a small DOM.
// The first Notifications open still performs the shell request and the cached
// fill, but those start together. The way back paints the same Home nodes,
// scroll position, and new-posts pill without another timeline request.
const fs = require('fs');
const vm = require('vm');
const path = require('path');

const file = process.argv[2] || path.join(__dirname, '..', 'ap-admin.php');
const source = fs.readFileSync(file, 'utf8');
const marker = source.indexOf('// Soft-nav for primary left-rail');
const start = source.indexOf('(function () {', marker);
const end = source.indexOf('\n})();\n</script>', start);
if (marker < 0 || start < 0 || end < start) {
  console.error('soft-nav controller markers missing');
  process.exit(1);
}
const js = source.slice(start, end + '\n})();'.length);

const checks = [];
function check(ok, label) {
  if (!ok) {
    const error = new Error(label);
    error.checks = checks.slice();
    throw error;
  }
  checks.push(label);
}

function createEl(tag) {
  const el = {
    tagName: String(tag || 'div').toUpperCase(),
    id: '',
    nodeType: 1,
    childNodes: [],
    parentNode: null,
    parentElement: null,
    style: {},
    scrollTop: 0,
    textContent: '',
    className: '',
  };
  const classes = new Set();
  const attrs = {};
  el.classList = {
    add(...names) { names.forEach((name) => name && classes.add(name)); },
    remove(...names) { names.forEach((name) => classes.delete(name)); },
    toggle(name, force) {
      const has = classes.has(name);
      const next = force === undefined ? !has : !!force;
      if (next) classes.add(name); else classes.delete(name);
      return next;
    },
    contains(name) { return classes.has(name); },
  };
  el.getAttribute = (key) => (Object.prototype.hasOwnProperty.call(attrs, key) ? attrs[key] : null);
  el.setAttribute = (key, value) => {
    attrs[key] = String(value);
    if (key === 'id') el.id = String(value);
    if (key === 'class') {
      classes.clear();
      String(value).split(/\s+/).filter(Boolean).forEach((name) => classes.add(name));
      el.className = String(value);
    }
  };
  el.removeAttribute = (key) => { delete attrs[key]; if (key === 'id') el.id = ''; };
  el.hasAttribute = (key) => Object.prototype.hasOwnProperty.call(attrs, key);
  Object.defineProperty(el, 'hidden', {
    get() { return el.hasAttribute('hidden'); },
    set(value) { if (value) el.setAttribute('hidden', ''); else el.removeAttribute('hidden'); },
  });
  el.dataset = new Proxy({}, {
    get(_target, key) {
      if (typeof key !== 'string') return undefined;
      const attr = 'data-' + key.replace(/[A-Z]/g, (ch) => '-' + ch.toLowerCase());
      const value = el.getAttribute(attr);
      return value == null ? undefined : value;
    },
    set(_target, key, value) {
      const attr = 'data-' + key.replace(/[A-Z]/g, (ch) => '-' + ch.toLowerCase());
      el.setAttribute(attr, value == null ? '' : String(value));
      return true;
    },
  });
  Object.defineProperty(el, 'children', {
    get() { return el.childNodes.filter((node) => node.nodeType === 1); },
  });
  Object.defineProperty(el, 'firstChild', {
    get() { return el.childNodes[0] || null; },
  });
  Object.defineProperty(el, 'isConnected', {
    get() {
      let node = el;
      while (node) {
        if (node === document) return true;
        node = node.parentNode;
      }
      return false;
    },
  });
  el.appendChild = (child) => {
    if (!child) return child;
    if (child.parentNode && child.parentNode.removeChild) child.parentNode.removeChild(child);
    child.parentNode = el;
    child.parentElement = el.nodeType === 1 ? el : null;
    el.childNodes.push(child);
    return child;
  };
  el.removeChild = (child) => {
    const index = el.childNodes.indexOf(child);
    if (index >= 0) el.childNodes.splice(index, 1);
    if (child) {
      child.parentNode = null;
      child.parentElement = null;
    }
    return child;
  };
  el.insertBefore = (child, before) => {
    if (child.parentNode && child.parentNode.removeChild) child.parentNode.removeChild(child);
    child.parentNode = el;
    child.parentElement = el;
    const index = before ? el.childNodes.indexOf(before) : -1;
    if (index < 0) el.childNodes.push(child);
    else el.childNodes.splice(index, 0, child);
    return child;
  };
  el.remove = () => { if (el.parentNode) el.parentNode.removeChild(el); };
  Object.defineProperty(el, 'textContent', {
    get() {
      if (el.nodeType === 3) return el._text || '';
      return el.childNodes.map((node) => node.textContent || '').join('');
    },
    set(value) {
      el.childNodes = [];
      el._text = String(value);
      if (el.nodeType === 1 && el._text) {
        const text = createEl('#text');
        text.nodeType = 3;
        text._text = el._text;
        el.appendChild(text);
      }
    },
  });
  Object.defineProperty(el, 'innerHTML', {
    get() { return el.childNodes.map((node) => node.nodeType === 3 ? node.textContent : '').join(''); },
    set(value) {
      el.childNodes = [];
      parseInto(el, String(value || ''));
    },
  });
  const elListeners = new Map();
  el.addEventListener = (type, fn) => {
    const list = elListeners.get(type) || [];
    list.push(fn);
    elListeners.set(type, list);
  };
  el.removeEventListener = (type, fn) => {
    elListeners.set(type, (elListeners.get(type) || []).filter((item) => item !== fn));
  };
  el.dispatchEvent = (event) => {
    (elListeners.get(event.type) || []).forEach((fn) => fn(event));
  };
  el.querySelector = (selector) => queryAll(el, selector)[0] || null;
  el.querySelectorAll = (selector) => queryAll(el, selector);
  el.getBoundingClientRect = () => ({ top: 0, right: 0, bottom: 0, left: 0, width: 0, height: 0 });
  el.scrollTo = (arg) => {
    if (arg && typeof arg === 'object') el.scrollTop = Number(arg.top) || 0;
    else el.scrollTop = Number(arg) || 0;
  };
  return el;
}

function parseInto(parent, html) {
  const re = /<\/?([a-zA-Z0-9:-]+)([^>]*)>|([^<]+)/g;
  const stack = [parent];
  let match;
  while ((match = re.exec(html))) {
    if (match[3]) {
      if (!match[3].trim()) continue;
      const text = createEl('#text');
      text.nodeType = 3;
      text._text = match[3];
      stack[stack.length - 1].appendChild(text);
      continue;
    }
    const tag = match[1].toLowerCase();
    if (html[match.index + 1] === '/') {
      for (let i = stack.length - 1; i > 0; i -= 1) {
        if (stack[i].tagName.toLowerCase() === tag) {
          stack.length = i;
          break;
        }
      }
      continue;
    }
    const node = createEl(tag);
    const attrRe = /([:@a-zA-Z0-9_-]+)(?:\s*=\s*"([^"]*)"|\s*=\s*'([^']*)')?/g;
    let attr;
    while ((attr = attrRe.exec(match[2] || ''))) {
      if (attr[1] === '/') continue;
      node.setAttribute(attr[1], attr[2] != null ? attr[2] : (attr[3] != null ? attr[3] : ''));
    }
    stack[stack.length - 1].appendChild(node);
    if (!match[0].endsWith('/>') && !/^(br|img|input|hr|meta|link)$/.test(tag)) stack.push(node);
  }
}

function descendants(node) {
  const out = [];
  node.childNodes.forEach((child) => {
    if (child.nodeType !== 1) return;
    out.push(child);
    descendants(child).forEach((item) => out.push(item));
  });
  return out;
}

function matchSimple(node, simple) {
  if (!node || node.nodeType !== 1) return false;
  let rest = simple.trim();
  if (!rest) return false;
  const attrs = [];
  rest = rest.replace(/\[([^\]]+)\]/g, (_all, body) => { attrs.push(body); return ''; });
  const classes = [];
  rest = rest.replace(/\.([A-Za-z0-9_-]+)/g, (_all, name) => { classes.push(name); return ''; });
  let id = '';
  rest = rest.replace(/#([A-Za-z0-9_-]+)/g, (_all, name) => { id = name; return ''; });
  const tag = rest.trim().toLowerCase();
  if (tag && node.tagName.toLowerCase() !== tag) return false;
  if (id && node.id !== id) return false;
  if (classes.some((name) => !node.classList.contains(name))) return false;
  return attrs.every((body) => {
    const contains = body.match(/^([^\*\~\|\^\$=]+)\*=(?:"([^"]*)"|([^\s\]]+))$/);
    if (contains) {
      const value = node.getAttribute(contains[1]) || '';
      return value.includes(contains[2] != null ? contains[2] : contains[3]);
    }
    const equals = body.match(/^([^=]+)=(?:"([^"]*)"|([^\s\]]+))$/);
    if (equals) return node.getAttribute(equals[1]) === (equals[2] != null ? equals[2] : equals[3]);
    return node.hasAttribute(body);
  });
}

function queryAll(root, selector) {
  const groups = String(selector).split(',').map((part) => part.trim()).filter(Boolean);
  const found = [];
  groups.forEach((group) => {
    const tokens = [];
    group.split(/\s*(>|\s)\s*/).filter((part) => part && part !== ' ').forEach((part) => {
      if (part === '>') tokens.push({ child: true });
      else tokens.push({ simple: part });
    });
    let current = [root];
    let child = false;
    tokens.forEach((token) => {
      if (token.child) { child = true; return; }
      const next = [];
      current.forEach((node) => {
        const pool = child ? (node.children || []) : descendants(node);
        pool.forEach((el) => {
          if (matchSimple(el, token.simple) && !next.includes(el)) next.push(el);
        });
      });
      current = next;
      child = false;
    });
    current.forEach((el) => { if (!found.includes(el)) found.push(el); });
  });
  return found;
}

function shellHtml(filter) {
  return '<div class="topbar"><h1>Notifications</h1></div>'
    + '<div class="feed timeline-feed"><nav class="notification-tabs"></nav>'
    + '<div id="timeline-items" data-view="mentions" data-filter="' + filter + '" data-limit="12" data-max-id="" data-has-more="1" data-initial-pending="1" data-offset="0" data-newest="0"></div>'
    + '<div id="timeline-status" class="meta">Loading</div>'
    + '<div id="timeline-sentinel"></div></div>'
    + '<button type="button" class="feed-top-btn" id="feed-top-btn"></button>';
}

function responseFor(url) {
  const parsed = new URL(url, 'https://mkultra.monster/vaak/');
  const view = parsed.searchParams.get('view') || '';
  const headers = { 'x-vaak-view': view };
  let html = '<div class="feed"></div>';
  if (view === 'mentions' && parsed.searchParams.get('shell') === '1') {
    html = shellHtml(parsed.searchParams.get('notification_filter') || 'all');
  } else if (view === 'mentions' && parsed.searchParams.get('notifications_max_id')) {
    headers['x-has-more'] = '0';
    headers['x-next-max-id'] = '';
    html = '<article class="tweet tweet-notif" data-note-id="notif-older">older</article>';
  } else if (view === 'mentions') {
    headers['x-has-more'] = '1';
    headers['x-next-max-id'] = '55';
    const filter = parsed.searchParams.get('notification_filter') || 'all';
    html = '<article class="tweet tweet-notif" data-note-id="notif-' + filter + '">cached ' + filter + '</article>';
  }
  return {
    ok: true,
    status: 200,
    headers: { get(name) { return headers[String(name).toLowerCase()] || null; } },
    text: async () => html,
  };
}

const document = createEl('#document');
document.nodeType = 9;
const documentElement = createEl('html');
const body = createEl('body');
document.appendChild(documentElement);
documentElement.appendChild(body);
document.documentElement = documentElement;
document.body = body;
const listeners = new Map();
function listen(type, fn) {
  const list = listeners.get(type) || [];
  list.push(fn);
  listeners.set(type, list);
}
document.addEventListener = listen;
document.dispatchEvent = (event) => {
  (listeners.get(event.type) || []).forEach((fn) => fn(event));
};
document.createElement = (tag) => createEl(tag);
document.getElementById = (id) => descendants(document).find((node) => node.id === id && node.isConnected) || null;
document.querySelector = (selector) => queryAll(document, selector).find((node) => node.isConnected) || null;
document.querySelectorAll = (selector) => queryAll(document, selector).filter((node) => node.isConnected);

const rail = createEl('nav');
rail.id = 'admin-rail-left';
const homeLink = createEl('a');
homeLink.setAttribute('href', '/vaak/?view=home');
homeLink.setAttribute('data-vaak-soft-nav', 'home');
homeLink.textContent = 'Home';
const notifLink = createEl('a');
notifLink.id = 'nav-notifications';
notifLink.setAttribute('href', '/vaak/?view=mentions');
notifLink.setAttribute('data-vaak-soft-nav', 'mentions');
notifLink.textContent = 'Notifications';
const badge = createEl('span');
badge.id = 'notif-badge';
badge.hidden = true;
badge.textContent = '';
notifLink.appendChild(badge);
rail.appendChild(homeLink);
rail.appendChild(notifLink);
body.appendChild(rail);
const mobileTitle = createEl('div');
mobileTitle.classList.add('mobile-topbar__title');
body.appendChild(mobileTitle);
const modal = createEl('div');
modal.id = 'compose-modal';
body.appendChild(modal);

const main = createEl('section');
main.classList.add('main');
const topbar = createEl('div');
topbar.classList.add('topbar');
const heading = createEl('h1');
heading.textContent = 'Home';
topbar.appendChild(heading);
const feed = createEl('div');
feed.classList.add('feed');
feed.classList.add('timeline-feed');
const slot = createEl('div');
slot.id = 'compose-inline-slot';
slot.classList.add('compose-inline-slot');
const panel = createEl('div');
panel.classList.add('compose-modal__panel');
const textarea = createEl('textarea');
textarea.id = 'compose-content';
textarea.textContent = 'draft reply';
panel.appendChild(textarea);
slot.appendChild(panel);
const newBtn = createEl('button');
newBtn.id = 'feed-new-btn';
newBtn.classList.add('feed-new-btn');
newBtn.hidden = false;
newBtn.textContent = '3 new posts';
const items = createEl('div');
items.id = 'timeline-items';
items.dataset.view = 'home';
items.dataset.offset = '15';
items.dataset.limit = '15';
items.dataset.hasMore = '1';
items.dataset.newest = '1000';
items.dataset.before = 'cursor-1';
const phpCard = createEl('article');
phpCard.classList.add('tweet');
phpCard.dataset.noteId = 'https://mkultra.monster/users/valerie/notes/php-1';
phpCard.textContent = 'php card';
const rustCard = createEl('article');
rustCard.classList.add('tweet');
rustCard.dataset.timelineKey = 'rust-1';
rustCard.dataset.noteId = 'rust-1';
rustCard.textContent = 'rust card';
items.appendChild(phpCard);
items.appendChild(rustCard);
const status = createEl('div');
status.id = 'timeline-status';
status.textContent = 'Scroll for more…';
const sentinel = createEl('div');
sentinel.id = 'timeline-sentinel';
const topBtn = createEl('button');
topBtn.id = 'feed-top-btn';
topBtn.classList.add('feed-top-btn');
feed.appendChild(slot);
feed.appendChild(newBtn);
feed.appendChild(items);
feed.appendChild(status);
feed.appendChild(sentinel);
main.appendChild(topbar);
main.appendChild(feed);
main.appendChild(topBtn);
body.appendChild(main);
feed.scrollTop = 640;

const assigned = [];
const location = {
  href: 'https://mkultra.monster/vaak/?view=home',
  origin: 'https://mkultra.monster',
  protocol: 'https:',
  host: 'mkultra.monster',
  pathname: '/vaak/',
  search: '?view=home',
  assign(url) { assigned.push(String(url)); },
};
function syncLocation(url) {
  const next = new URL(url, location.href);
  location.href = next.href;
  location.pathname = next.pathname;
  location.search = next.search;
}
const history = {
  state: null,
  scrollRestoration: 'auto',
  pushState(state, _title, url) {
    this.state = state;
    if (url) syncLocation(url);
  },
  replaceState(state, _title, url) {
    this.state = state;
    if (url) syncLocation(url);
  },
};
const fetches = [];
const ios = [];
let clock = 0;
function fetch(url, opts) {
  const rec = { url: String(url), start: ++clock, end: 0, opts: opts || {} };
  let reject;
  const promise = new Promise((resolve, rejectPromise) => {
    rec.resolve = (value) => { rec.end = ++clock; resolve(value); };
    reject = rejectPromise;
  });
  const signal = opts && opts.signal;
  if (signal) {
    const abort = () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' }));
    if (signal.aborted) abort();
    else signal.addEventListener('abort', abort);
  }
  fetches.push(rec);
  return promise;
}
class IntersectionObserver {
  constructor(callback, options) {
    this.callback = callback;
    this.options = options;
    this.elements = [];
    this.dead = false;
    ios.push(this);
  }
  observe(el) { this.elements.push(el); }
  disconnect() { this.dead = true; this.elements = []; }
  unobserve() {}
}
const rafs = [];
const win = {
  document,
  location,
  history,
  scrollY: 480,
  scrollTo(_x, y) { this.scrollY = y; },
  innerHeight: 800,
  innerWidth: 1200,
  getComputedStyle() { return { overflowY: 'visible' }; },
  requestAnimationFrame(fn) { rafs.push(fn); return rafs.length; },
  cancelAnimationFrame() {},
  setTimeout,
  clearTimeout,
  addEventListener: listen,
  dispatchEvent: document.dispatchEvent,
  vaakAppHistoryUrl(mutate) {
    const url = new URL(location.href);
    mutate(url);
    return url.pathname + url.search;
  },
  vaakSetCurrentViewTitle(view) { document.title = view + ' · VAAK'; },
  vaakShowLoading() {},
  vaakHideLoading() {},
  vaakSwapTimelineView: async (view) => { win.swapped.push(view); return true; },
  swapped: [],
  bootCount: 0,
  pauseCount: 0,
  resumeCount: 0,
  adoptCount: 0,
  vaakBootTimeline() { win.bootCount += 1; return true; },
  vaakPauseTimelineForPark() {
    win.pauseCount += 1;
    return { pendingHtml: '<article data-note-id="held"></article>', pendingCount: 3, pendingSourceCount: 3, newestTs: 1000 };
  },
  vaakResumeParkedTimeline(snap) {
    win.resumeCount += 1;
    win.resumeSnap = snap || null;
    return true;
  },
  vaakAdoptComposerAfterSoftNav() {
    win.adoptCount += 1;
    const liveItems = document.getElementById('timeline-items');
    const view = liveItems ? liveItems.dataset.view : '';
    const livePanel = document.querySelector('.compose-modal__panel');
    const liveSlot = document.getElementById('compose-inline-slot');
    if (livePanel && liveSlot && ['home', 'local', 'feed'].includes(view)) liveSlot.appendChild(livePanel);
  },
};
win.window = win;
document.defaultView = win;

const ctx = vm.createContext({
  window: win,
  document,
  history,
  location,
  fetch,
  AbortController,
  IntersectionObserver,
  URL,
  URLSearchParams,
  Promise,
  Number,
  String,
  Object,
  Array,
  Math,
  JSON,
  parseInt,
  encodeURIComponent,
  decodeURIComponent,
  setTimeout,
  clearTimeout,
  Map,
  Set,
  Date,
  Error,
  RegExp,
  Infinity,
  NaN,
  console,
  getComputedStyle() { return { overflowY: 'visible' }; },
  requestAnimationFrame(fn) { rafs.push(fn); return rafs.length; },
  cancelAnimationFrame() {},
});
vm.runInContext(js, ctx);

async function flush() {
  for (let i = 0; i < 12; i += 1) {
    await Promise.resolve();
    const batch = rafs.splice(0, rafs.length);
    batch.forEach((fn) => fn());
  }
}

async function settleFetches() {
  await flush();
  const pending = fetches.filter((rec) => !rec.end);
  pending.forEach((rec) => rec.resolve(responseFor(rec.url)));
  await flush();
}

(async () => {
  const started = Date.now();
  check(typeof win.vaakSoftNavTo === 'function', 'soft-nav controller installed');
  const beforeLocal = fetches.length;
  await win.vaakSoftNavTo('local', true, '', {});
  check(win.swapped.join(',') === 'local' && fetches.length === beforeLocal, 'Home to Local stays an in-place timeline swap');

  const homeToNotif = win.vaakSoftNavTo('mentions', true, '', {});
  await flush();
  const opened = fetches.slice();
  check(opened.length === 2, 'first Notifications open issues the shell and the fill');
  check(opened.every((rec) => !rec.end), 'both Notifications requests are in flight together');
  const shell = opened.find((rec) => rec.url.includes('shell=1'));
  const fill = opened.find((rec) => rec.url.includes('partial=1') && !rec.url.includes('shell=1'));
  check(!!shell && !!fill && fill.start <= shell.start + 1, 'the cached fill starts with the shell instead of after it');
  check(fill.url.includes('notification_filter=all') && fill.url.includes('limit=12'), 'the fill asks the existing notifications cache for the first page');
  check(shell.opts.cache === 'no-store' && fill.opts.cache === 'no-store', 'neither request is browser-cached');
  check(!phpCard.isConnected && win.pauseCount === 1, 'Home is detached and its poll is paused before the shell returns');
  shell.resolve(responseFor(shell.url));
  await flush();
  check(!fill.end, 'the shell returning does not gate the fill that is already running');
  const paintAt = Date.now();
  fill.resolve(responseFor(fill.url));
  await homeToNotif;
  await flush();
  const firstPaintMs = Date.now() - paintAt;
  const notifCard = document.querySelector('article.tweet-notif');
  check(notifCard && notifCard.dataset.noteId === 'notif-all', 'the first notifications page paints from the fill');
  check(document.getElementById('timeline-items').dataset.initialPending === '0', 'the painted page is no longer a pending shell');
  check(panel.parentNode === modal && textarea.textContent === 'draft reply', 'the reply composer is parked, not discarded');
  check(assigned.length === 0 && win.bootCount === 0, 'opening Notifications does not reload the document or reboot Home');

  const fetchesAfterOpen = fetches.length;
  const backAt = Date.now();
  await win.vaakSoftNavTo('home', true, '', {});
  await flush();
  const homeReturnMs = Date.now() - backAt;
  check(fetches.length === fetchesAfterOpen, 'back to Home does not fetch a timeline');
  check(phpCard.isConnected && rustCard.isConnected && document.getElementById('timeline-items') === items, 'Home puts the same PHP and Rust cards back');
  check(feed.scrollTop === 640 && win.scrollY === 480, 'Home restores the feed and window scroll');
  check(newBtn.isConnected && newBtn.textContent === '3 new posts' && newBtn.hidden === false, 'the new-posts pill is still waiting for a tap');
  check(sentinel.isConnected && items.dataset.before === 'cursor-1' && items.dataset.offset === '15', 'endless-scroll cursor stays on the parked Home column');
  check(win.resumeCount === 1 && win.bootCount === 0, 'Home resumes the parked timeline instead of rebooting it');
  check(win.resumeSnap && win.resumeSnap.pendingCount === 3 && win.resumeSnap.pendingHtml.includes('held'), 'Home hands the held new-posts copy back to the resumed timeline');
  check(panel.parentNode === slot && textarea.textContent === 'draft reply', 'returning Home puts the same reply draft back');
  check(location.search.includes('view=home') && !location.search.includes('_r='), 'the Home URL is the view switch, not a cache-busting reload');

  const notifBackAt = Date.now();
  await win.vaakSoftNavTo('mentions', true, '', {});
  await flush();
  const notifReturnMs = Date.now() - notifBackAt;
  check(fetches.length === fetchesAfterOpen && notifCard.isConnected, 'Notifications reopens from the painted page');
  check(homeReturnMs < 50 && notifReturnMs < 50 && firstPaintMs < 50, 'a parked switch paints without waiting on the server');

  const beforeHover = fetches.length;
  notifLink.dispatchEvent({ type: 'mouseover', button: 0 });
  notifLink.dispatchEvent({ type: 'pointerenter', pointerType: 'mouse', button: 0 });
  await flush();
  check(fetches.length === beforeHover, 'hovering Notifications does not prefetch from the server');

  syncLocation('/vaak/?view=home');
  win.dispatchEvent({ type: 'popstate' });
  await flush();
  check(fetches.length === beforeHover && phpCard.isConnected && feed.scrollTop === 640, 'Back restores the parked Home column');

  badge.hidden = false;
  badge.textContent = '2';
  const stale = win.vaakSoftNavTo('mentions', true, '', {});
  await flush();
  const staleReqs = fetches.filter((rec) => !rec.end);
  check(staleReqs.length === 2, 'a new badge discards the parked notifications page and reads the cache again');
  staleReqs.forEach((rec) => rec.resolve(responseFor(rec.url)));
  await stale;
  await flush();
  check(document.querySelector('[data-note-id="notif-all"]').isConnected, 'the refreshed notifications page paints');

  await win.vaakSoftNavTo('home', true, '', {});
  await flush();
  const fav = win.vaakSoftNavTo('mentions', true, 'favourites', {});
  await flush();
  const favReqs = fetches.filter((rec) => !rec.end);
  check(favReqs.length === 2 && favReqs.some((rec) => rec.url.includes('notification_filter=favourites')), 'a notification filter loads its own first page');
  favReqs.forEach((rec) => rec.resolve(responseFor(rec.url)));
  await fav;
  await flush();
  const favCard = document.querySelector('[data-note-id="notif-favourites"]');
  await win.vaakSoftNavTo('home', true, '', {});
  await flush();
  const beforeFavReturn = fetches.length;
  await win.vaakSoftNavTo('mentions', true, 'favourites', {});
  await flush();
  check(fetches.length === beforeFavReturn && favCard.isConnected, 'the favourites filter reopens from its painted page');
  const beforeAll = fetches.length;
  await win.vaakSoftNavTo('mentions', true, '', {});
  await flush();
  check(fetches.length === beforeAll && document.querySelector('[data-note-id="notif-all"]').isConnected, 'All stays parked while another filter is open');

  const liveIo = ios.filter((observer) => !observer.dead).pop();
  check(!!liveIo, 'notifications endless scroll is armed on the restored page');
  const beforeOlder = fetches.length;
  liveIo.callback([{ isIntersecting: true, target: sentinel }]);
  await flush();
  const older = fetches[fetches.length - 1];
  check(fetches.length === beforeOlder + 1 && older.url.includes('notifications_max_id=55'), 'scrolling notifications still requests the next page');
  older.resolve(responseFor(older.url));
  await flush();

  check(assigned.length === 0, 'replying and view switches never assign a new document');
  const timings = {
    homeReturnMs,
    notificationsReturnMs: notifReturnMs,
    parkedSwitchUnderMs: 50,
    firstOpenRequests: 2,
    fillOverlapsShell: true,
    homeReturnRequests: 0,
    notificationsReturnRequests: 0,
    hoverPrefetchRequests: 0,
    elapsedMs: Date.now() - started,
  };
  console.log(JSON.stringify({ file, checks: checks.length, passed: true, timings }, null, 2));
})().catch((error) => {
  console.error(error && error.stack ? error.stack : error);
  if (error && error.checks) console.error('passed before failure:', error.checks.length);
  process.exitCode = 1;
});
