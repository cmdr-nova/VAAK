// Lifecycle of the Home / Local / Federated "N new posts" poller.
// Extracts the production boot script from ap-admin.php and drives it with a
// fake clock. Background tabs are modeled two ways: timers frozen (Safari /
// Chrome discard the callback), and a request left in flight because its
// abort timer was frozen too. Returning must show the indicator without
// inserting those posts until the button is clicked.
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');

const file = process.argv[2] || require('path').join(__dirname, '..', 'ap-admin.php');
const source = fs.readFileSync(file, 'utf8');
const start = source.indexOf('(function () {\n  /** Replace a timeline card in place');
const end = source.indexOf('\n})();\n</script>', start);
assert(start > 0 && end > start, 'timeline boot script markers');
let js = source.slice(start, end + '\n})();'.length);
js = js.replace(/<\?=[\s\S]*?\?>/g, 'false');

function createEl(tag) {
  const el = {
    tagName: String(tag || 'div').toUpperCase(),
    id: '',
    hidden: false,
    textContent: '',
    style: {},
    dataset: {},
    children: [],
    parentElement: null,
    isConnected: true,
    _html: '',
    _on: {},
  };
  el.classList = {
    add(...names) { names.forEach((n) => n && el.classList._set.add(n)); },
    remove(...names) { names.forEach((n) => el.classList._set.delete(n)); },
    toggle(name, force) {
      const has = el.classList._set.has(name);
      const next = force === undefined ? !has : !!force;
      if (next) el.classList._set.add(name); else el.classList._set.delete(name);
      return next;
    },
    contains(name) { return el.classList._set.has(name); },
    _set: new Set(),
  };
  el.getAttribute = (key) => {
    if (key === 'id') return el.id || null;
    if (key === 'class') return [...el.classList._set].join(' ') || null;
    return Object.prototype.hasOwnProperty.call(el._attr || {}, key) ? el._attr[key] : null;
  };
  el._attr = {};
  el.setAttribute = (key, value) => {
    el._attr[key] = String(value);
    if (key === 'id') el.id = String(value);
    if (key === 'class') {
      el.classList._set = new Set(String(value).split(/\s+/).filter(Boolean));
    }
    if (key.startsWith('data-')) {
      const camel = key.slice(5).replace(/-([a-z])/g, (_, c) => c.toUpperCase());
      el.dataset[camel] = String(value);
    }
  };
  el.hasAttribute = (key) => el.getAttribute(key) != null;
  const matchOne = (node, sel) => {
    if (!node || !sel) return false;
    if (sel.includes('>')) {
      const bits = sel.split('>').map((s) => s.trim());
      if (bits.length === 2) {
        return matchOne(node, bits[1]) && node.parentElement && matchOne(node.parentElement, bits[0]);
      }
    }
    let rest = sel;
    const attrs = [];
    rest = rest.replace(/\[([^\]]+)\]/g, (_, body) => { attrs.push(body); return ''; });
    const classes = [];
    rest = rest.replace(/\.([A-Za-z0-9_-]+)/g, (_, c) => { classes.push(c); return ''; });
    let id = '';
    rest = rest.replace(/#([A-Za-z0-9_-]+)/g, (_, i) => { id = i; return ''; });
    const tagName = rest.trim().toLowerCase();
    if (tagName && node.tagName.toLowerCase() !== tagName) return false;
    if (id && node.id !== id) return false;
    if (classes.some((c) => !node.classList.contains(c))) return false;
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
    if (child.parentElement) child.remove();
    child.parentElement = el;
    el.children.push(child);
    return child;
  };
  el.insertBefore = (child, before) => {
    if (child.parentElement) child.remove();
    child.parentElement = el;
    const at = before ? el.children.indexOf(before) : -1;
    if (at < 0) el.children.push(child);
    else el.children.splice(at, 0, child);
    return child;
  };
  el.remove = () => {
    const parent = el.parentElement;
    if (!parent) return;
    parent.children = parent.children.filter((child) => child !== el);
    el.parentElement = null;
  };
  Object.defineProperty(el, 'firstChild', { get() { return el.children[0] || null; } });
  const serialize = (node) => {
    const attrs = Object.entries(node._attr || {}).map(([key, value]) => ' ' + key + '="' + value + '"').join('');
    const inner = (node.children || []).map(serialize).join('');
    return '<' + node.tagName.toLowerCase() + attrs + '>' + inner + '</' + node.tagName.toLowerCase() + '>';
  };
  Object.defineProperty(el, 'innerHTML', {
    get() { return el.children.length ? el.children.map(serialize).join('') : el._html; },
    set(html) {
      el._html = String(html || '');
      el.children = parseHTML(el._html);
      el.children.forEach((child) => { child.parentElement = el; });
    },
  });
  el.insertAdjacentHTML = (where, html) => {
    const parsed = parseHTML(html);
    parsed.forEach((child) => { child.parentElement = el; });
    if (where === 'afterbegin') el.children = parsed.concat(el.children);
    else el.children = el.children.concat(parsed);
  };
  el.getBoundingClientRect = () => ({ top: 900, bottom: 980, left: 0, right: 640, width: 640, height: 80 });
  el.addEventListener = (type, fn) => {
    el._on[type] = el._on[type] || [];
    el._on[type].push(fn);
  };
  el.click = () => (el._on.click || []).forEach((fn) => fn({}));
  return el;
}

function parseHTML(html) {
  const out = [];
  const re = /<([a-z0-9]+)([^>]*)>([\s\S]*?)<\/\1>/gi;
  let match;
  const text = String(html || '');
  while ((match = re.exec(text))) {
    const node = createEl(match[1]);
    const attrRe = /([^\s=]+)(?:\s*=\s*"([^"]*)")?/g;
    let attr;
    while ((attr = attrRe.exec(match[2] || ''))) {
      if (attr[1].startsWith('/')) continue;
      node.setAttribute(attr[1], attr[2] == null ? '' : attr[2]);
    }
    if (match[3] && match[3].includes('<')) node.innerHTML = match[3];
    out.push(node);
  }
  return out;
}

function boot(view) {
  const feed = createEl('div');
  feed.classList.add('feed');
  const items = createEl('div');
  items.id = 'timeline-items';
  items.dataset.view = view;
  items.dataset.offset = '0';
  items.dataset.limit = '15';
  items.dataset.hasMore = '1';
  items.dataset.newest = '1000';
  const sentinel = createEl('div');
  sentinel.id = 'timeline-sentinel';
  const status = createEl('div');
  status.id = 'timeline-status';
  const newBtn = createEl('button');
  newBtn.id = 'feed-new-btn';
  newBtn.hidden = true;
  newBtn.textContent = 'New posts';
  const topBtn = createEl('button');
  topBtn.id = 'feed-top-btn';
  const body = createEl('body');
  feed.appendChild(items);
  feed.appendChild(sentinel);
  feed.appendChild(status);
  body.appendChild(feed);
  body.appendChild(newBtn);
  body.appendChild(topBtn);

  let hidden = false;
  const docListeners = new Map();
  const winListeners = new Map();
  const listen = (map) => (type, fn) => {
    const list = map.get(type) || [];
    list.push(fn);
    map.set(type, list);
  };
  const emit = (map, type, event) => {
    for (const fn of map.get(type) || []) fn(event || {});
  };
  const doc = {
    body,
    documentElement: createEl('html'),
    readyState: 'complete',
    title: 'VAAK · Home',
    addEventListener: listen(docListeners),
    emit: (type, event) => emit(docListeners, type, event),
    createElement: (tag) => createEl(tag),
    getElementById: (id) => body.querySelector('#' + id),
    querySelector: (sel) => body.querySelector(sel),
    querySelectorAll: (sel) => body.querySelectorAll(sel),
  };
  Object.defineProperty(doc, 'hidden', {
    get() { return hidden; },
    set(value) { hidden = !!value; },
  });
  Object.defineProperty(doc, 'visibilityState', {
    get() { return hidden ? 'hidden' : 'visible'; },
  });
  doc.documentElement.scrollTop = 0;
  doc.documentElement.scrollHeight = 2000;

  let now = 1700000000000;
  let serial = 0;
  const timers = new Map();
  const calls = [];
  function later(fn, ms, interval) {
    const id = ++serial;
    timers.set(id, { fn, at: now + Math.max(0, Number(ms) || 0), interval: !!interval, every: Number(ms) || 0 });
    return id;
  }
  const setTimeout = (fn, ms) => later(fn, ms, false);
  const setInterval = (fn, ms) => later(fn, ms, true);
  const clearTimeout = (id) => { timers.delete(id); };
  const clearInterval = clearTimeout;
  const win = {
    location: { href: 'https://example.test/vaak/?view=' + view, search: '?view=' + view, pathname: '/vaak/' },
    history: { pushState() {}, replaceState() {} },
    scrollY: 0,
    scrollTo() {},
    innerWidth: 1200,
    innerHeight: 800,
    getComputedStyle() { return { overflowY: 'visible' }; },
    requestAnimationFrame(fn) { return setTimeout(fn, 1000000); },
    addEventListener: listen(winListeners),
    emit: (type, event) => emit(winListeners, type, event),
    setTimeout,
    clearTimeout,
    setInterval,
    clearInterval,
    __vaakNavigationPending: false,
  };
  class FakeDate extends Date {
    constructor(...args) {
      if (args.length === 0) super(now);
      else super(...args);
    }
    static now() { return now; }
  }
  function defer() {
    let resolve;
    let reject;
    const promise = new Promise((ok, bad) => { resolve = ok; reject = bad; });
    return { promise, resolve, reject };
  }
  const fetch = (url, opts) => {
    const pending = defer();
    const call = { url: String(url), opts: opts || {}, pending };
    calls.push(call);
    const signal = opts && opts.signal;
    if (signal) {
      const abort = () => pending.reject(Object.assign(new Error('aborted'), { name: 'AbortError' }));
      if (signal.aborted) abort();
      else signal.addEventListener('abort', abort);
    }
    return pending.promise;
  };
  function IntersectionObserver() {}
  IntersectionObserver.prototype.observe = function observe() {};
  IntersectionObserver.prototype.disconnect = function disconnect() {};
  const ctx = vm.createContext({
    window: win,
    document: doc,
    fetch,
    AbortController,
    IntersectionObserver,
    FakeDate,
    Date: FakeDate,
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
    setInterval,
    clearInterval,
    URL,
    URLSearchParams,
    Infinity,
    NaN,
    Error,
    console,
  });
  // Keep Math.random stable so catch-up jitter is exactly 200ms.
  ctx.Math.random = () => 0;
  vm.runInContext(js, ctx);
  const flush = async () => { for (let n = 0; n < 40; n += 1) await Promise.resolve(); };
  async function advance(ms) {
    const till = now + ms;
    while (true) {
      let next = null;
      for (const entry of timers) {
        if (entry[1].at <= till && (!next || entry[1].at < next[1].at)) next = entry;
      }
      if (!next) break;
      now = next[1].at;
      const timer = next[1];
      timers.delete(next[0]);
      if (timer.interval) timers.set(next[0], { ...timer, at: now + timer.every });
      timer.fn();
      await flush();
    }
    now = till;
    await flush();
  }
  function elapse(ms) { now += ms; }
  function dropTimers() { timers.clear(); }
  function newer() { return calls.filter((call) => call.url.includes('newer=1')); }
  function response(noteId, newest, extra) {
    extra = extra || {};
    let body = Object.prototype.hasOwnProperty.call(extra, 'body') ? extra.body : null;
    if (body == null) {
      body = noteId
        ? '<article class="tweet" data-note-id="' + noteId + '"' + (extra.sort ? ' data-sort="' + extra.sort + '"' : '') + '></article>'
        : ' ';
    }
    return {
      ok: true,
      status: 200,
      headers: { get(name) { return String(name).toLowerCase() === 'x-newest' ? String(newest) : null; } },
      text: async () => body,
    };
  }
  win.vaakRenderInboxTitle = () => {
    const n = Math.max(0, parseInt(win.__vaakPendingPostCount, 10) || 0);
    doc.title = (n > 0 ? '(' + n + ') ' : '') + 'VAAK';
  };
  return { win, doc, items, newBtn: () => doc.getElementById('feed-new-btn'), calls, newer, response, advance, elapse, dropTimers, flush };
}

const result = [];
function check(ok, label) {
  assert(ok, label);
  result.push(label);
}

(async () => {
  const page = boot('home');
  await page.advance(5000);
  check(page.newer().length === 1 && page.newer()[0].url.includes('since=1000') && page.newer()[0].url.includes('view=home'), 'first visible check uses the painted cursor');
  page.newer()[0].pending.resolve(page.response('', 1000));
  await page.flush();
  check(page.items.children.length === 0 && page.newBtn().hidden, 'empty check leaves the feed and hides the indicator');

  // Timers keep running, tab hidden: one gentle check at 90s, not the 45s visible rate.
  page.doc.hidden = true;
  page.doc.emit('visibilitychange');
  const beforeHidden = page.newer().length;
  await page.advance(89999);
  check(page.newer().length === beforeHidden, 'hidden tab does not poll on the visible 45s cadence');
  await page.advance(1);
  check(page.newer().length === beforeHidden + 1, 'hidden tab polls once at the 90s background cadence');
  check(page.newer().at(-1).url.includes('since=1000'), 'background check keeps the same cursor');
  page.newer().at(-1).pending.resolve(page.response('note-hidden', 1500));
  await page.flush();
  check(page.newBtn().hidden === false && page.newBtn().textContent === '1 new post', 'background check arms the new-posts indicator');
  check(page.items.children.length === 0, 'background check does not insert posts into the feed');
  check(page.doc.title === '(1) VAAK', 'pending posts prefix the tab title');

  // Return inside the duplicate window must not stampede another request.
  page.doc.hidden = false;
  page.doc.emit('visibilitychange');
  page.win.emit('focus');
  const afterBackground = page.newer().length;
  await page.advance(2000);
  check(page.newer().length === afterBackground, 'returning just after a finished check does not refetch');

  page.newBtn().click();
  check(page.items.querySelector('article.tweet') && page.items.querySelector('article.tweet').getAttribute('data-note-id') === 'note-hidden', 'clicking the indicator inserts the waiting posts');
  check(page.newBtn().hidden === true && page.doc.title === 'VAAK', 'inserting clears the indicator and the title count');

  // Frozen tab: background timer never fires. Coming back must poll on its own.
  page.doc.hidden = true;
  page.doc.emit('visibilitychange');
  page.dropTimers();
  page.elapse(5 * 60 * 1000);
  page.doc.hidden = false;
  page.doc.emit('visibilitychange');
  page.win.emit('focus');
  page.win.emit('online');
  const beforeWake = page.newer().length;
  await page.advance(199);
  check(page.newer().length === beforeWake, 'return check waits out the anti-stampede jitter');
  await page.advance(1);
  await page.flush();
  check(page.newer().length === beforeWake + 1, 'frozen background tab checks as soon as it is visible again');
  check(page.newer().at(-1).url.includes('view=home') && page.newer().at(-1).url.includes('since=1500'), 'return check continues from the cursor, it does not jump to now');
  page.newer().at(-1).pending.resolve(page.response('note-wake', 1800));
  await page.flush();
  check(page.newBtn().textContent === '1 new post' && page.items.querySelector('[data-note-id="note-wake"]') == null, 'return check shows the indicator and still does not insert');

  // Request frozen in flight: its abort timer never runs, so the old catch-up
  // saw pollBusy and did nothing. Returning must abort that stale flight and
  // check again, without inserting.
  page.doc.hidden = true;
  page.doc.emit('visibilitychange');
  page.dropTimers();
  page.elapse(10000);
  page.doc.hidden = false;
  page.doc.emit('visibilitychange');
  await page.advance(200);
  await page.flush();
  const frozen = page.newer().at(-1);
  check(frozen.opts.signal && !frozen.opts.signal.aborted, 'a return check is in flight before the tab freezes');
  page.dropTimers();
  page.elapse(60000);
  page.doc.hidden = true;
  page.doc.emit('visibilitychange');
  page.dropTimers();
  page.doc.hidden = false;
  page.doc.emit('visibilitychange');
  await page.advance(200);
  await page.flush();
  check(frozen.opts.signal.aborted, 'the frozen request is aborted');
  const replacement = page.newer().at(-1);
  check(replacement !== frozen && replacement.url.includes('since='), 'return replaces the frozen check and keeps the cursor');
  check(page.items.querySelector('[data-note-id="note-wake"]') == null, 'aborting the stale poll does not insert anything');
  replacement.pending.resolve(page.response('', 1800));
  await page.flush();

  // pagehide suspends the timer. A tab switch that does not restore via
  // bfcache still has to poll when the same document becomes visible.
  const beforeHide = page.newer().length;
  page.win.emit('pagehide');
  page.elapse(120000);
  await page.advance(5000);
  check(page.newer().length === beforeHide, 'pagehide stops the owned poll timer');
  page.doc.hidden = false;
  page.doc.emit('visibilitychange');
  await page.advance(200);
  await page.flush();
  check(page.newer().length === beforeHide + 1, 'becoming visible after pagehide resumes the poller');
  page.newer().at(-1).pending.resolve(page.response('', 1800));
  await page.flush();

  // Network reconnect after a failed check.
  const failed = page.newer().length;
  page.doc.hidden = true;
  page.doc.emit('visibilitychange');
  page.dropTimers();
  page.elapse(10000);
  page.doc.hidden = false;
  page.doc.emit('visibilitychange');
  await page.advance(200);
  const reconnect = page.newer().at(-1);
  check(page.newer().length === failed + 1, 'a visible return started a check before the failure');
  reconnect.pending.reject(Object.assign(new Error('offline'), { name: 'TypeError' }));
  await page.flush();
  const afterFail = page.newer().length;
  page.win.emit('online');
  await page.advance(200);
  await page.flush();
  check(page.newer().length === afterFail + 1, 'coming back online retries a failed new-posts check');
  page.newer().at(-1).pending.resolve(page.response('', 1800));
  await page.flush();

  async function expectView(view, allowed) {
    page.items.dataset.view = view;
    page.items.dataset.newest = '1000';
    page.win.vaakBootTimeline(view);
    page.doc.hidden = true;
    page.doc.emit('visibilitychange');
    page.dropTimers();
    page.elapse(10000);
    page.doc.hidden = false;
    page.doc.emit('visibilitychange');
    const before = page.newer().length;
    await page.advance(200);
    await page.flush();
    const added = page.newer().slice(before);
    if (allowed) {
      check(added.length === 1 && added[0].url.includes('view=' + view) && added[0].url.includes('since=1000'), view + ' timeline polls on return');
      added[0].pending.resolve(page.response('', 1000));
      await page.flush();
    } else {
      check(added.length === 0, view + ' does not use the home/local/federated head poller');
    }
  }
  await expectView('local', true);
  await expectView('feed', true);
  await expectView('bluesky', false);
  await expectView('mentions', false);
  await expectView('outbox', false);

  check(!source.includes("view === 'lists'") || !source.slice(source.indexOf('function timelineHeadPollView'), source.indexOf('function pollTimelineAutomatically')).includes("'lists'"), 'list pages are not given this head poller');

  // Response → counter. The cursor moves only after the body is a real card,
  // and an X-Newest past those cards cannot skip a post the bar never showed.
  const held = boot('home');
  await held.advance(5000);
  held.win.scrollY = 480;
  held.newer()[0].pending.resolve(held.response('post-a', 99999, { sort: 1100 }));
  await held.flush();
  check(held.items.children.length === 0, 'a returned card is held instead of inserted');
  check(held.newBtn().hidden === false && held.newBtn().textContent === '1 new post' && held.newBtn().classList.contains('show'), 'the bar counts the held card');
  check(held.newBtn().classList.contains('is-pinned'), 'the bar becomes the floating pill while scrolled down');
  check(held.items.dataset.newest === '1100', 'data-sort caps an X-Newest that is ahead of the card');

  // The boot timer is armed at t=0 and the first check is at 5s, so the
  // next eligible visible check is the timer that lands at 90s.
  await held.advance(85000);
  check(held.newer().at(-1).url.includes('since=1100'), 'the next poll starts at the card that was actually shown to the counter');
  held.newer().at(-1).pending.resolve(held.response('', 50000, {
    body: '<article class="tweet" data-note-id="post-a" data-sort="1100"></article><article class="tweet" data-note-id="post-b" data-sort="1200"></article>',
  }));
  await held.flush();
  check(held.newBtn().textContent === '2 new posts', 'a later poll adds to the count and drops the duplicate');
  check(held.items.dataset.newest === '1200', 'duplicate cards still advance the cursor to their data-sort ceiling');

  await held.advance(45000);
  held.newer().at(-1).pending.resolve(held.response('', 80000, { body: '<div class="nope">not a post</div>' }));
  await held.flush();
  check(held.items.dataset.newest === '1200' && held.newBtn().textContent === '2 new posts', 'a body with no timeline card does not move the cursor or the count');

  await held.advance(45000);
  held.newer().at(-1).pending.resolve(held.response('', 70000));
  await held.flush();
  check(held.items.dataset.newest === '1200', 'an empty body does not move the cursor');

  await held.advance(45000);
  held.newer().at(-1).pending.resolve(held.response('', 99999, {
    body: '<div class="notif-status-embed"><article class="tweet" data-note-id="wrapped" data-sort="1300"></article></div>',
  }));
  await held.flush();
  check(held.newBtn().textContent === '3 new posts' && held.items.dataset.newest === '1300', 'a wrapped card counts and its data-sort caps X-Newest');

  const timelineNode = held.items;
  held.newBtn().remove();
  await held.advance(45000);
  held.newer().at(-1).pending.resolve(held.response('post-c', 1400, { sort: 1400 }));
  await held.flush();
  check(held.newBtn() && held.newBtn().textContent === '4 new posts', 'a missing new-posts button is recreated by the poll');
  held.newBtn().click();
  const inserted = held.items.querySelectorAll('article.tweet').map((el) => el.getAttribute('data-note-id'));
  check(held.items === timelineNode && inserted.join(',') === 'post-c,wrapped,post-b,post-a', 'click inserts the held cards at the top of the same timeline');
  check(held.newBtn().hidden === true && held.items.querySelectorAll('[data-note-id="post-a"]').length === 1, 'click clears the indicator and does not duplicate a card');

  const drift = boot('home');
  await drift.advance(5000);
  drift.newer()[0].pending.resolve(drift.response('', 1000));
  await drift.flush();
  drift.items.dataset.view = 'local';
  drift.doc.hidden = true;
  drift.doc.emit('visibilitychange');
  drift.dropTimers();
  drift.elapse(10000);
  drift.doc.hidden = false;
  drift.doc.emit('visibilitychange');
  await drift.advance(200);
  await drift.flush();
  const drifted = drift.newer().slice(1);
  check(drifted.length === 1 && drifted[0].url.includes('view=local') && drifted[0].url.includes('since=1000'), 'Local is polled from the live timeline view when the boot closure still says home');
  console.log(JSON.stringify({ file, checks: result.length, passed: true, labels: result }, null, 2));
})().catch((error) => {
  console.error(error && error.stack ? error.stack : error);
  process.exitCode = 1;
});
