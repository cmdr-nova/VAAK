// Home and single-post dwell is batched. Idle time and fast scrolls do not
// become requests. The script is the one shipped in api/ap-admin.php.
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const path = require('path');

const source = fs.readFileSync(path.join(__dirname, '..', 'ap-admin.php'), 'utf8');
const probeMarker = 'const probe = window.__VAAK_HOME_SIGNAL_PROBE === true';
const probeAt = source.indexOf(probeMarker);
const start = probeAt > 0 ? source.lastIndexOf('(function ', probeAt) : -1;
const end = source.indexOf('})();\n</script>\n<script>\n// Bluesky like/boost/bookmark', start);
assert(start > 0 && end > start, 'dwell script markers');
const script = source.slice(start, end + '})();'.length);

let nowMs = 0;
const listeners = {};
const fetches = [];
const beacons = [];
const timeouts = [];

function listen(target, name, fn) {
  const key = target + ':' + name;
  if (!listeners[key]) listeners[key] = [];
  listeners[key].push(fn);
}

class FormData {
  constructor() { this.map = new Map(); }
  set(key, value) { this.map.set(key, value); }
}

class IntersectionObserver {
  constructor(callback) { this.callback = callback; }
  observe() {}
  disconnect() {}
}

class MutationObserver {
  constructor() {}
  observe() {}
}

const document = {
  hidden: false,
  documentElement: { dataset: {} },
  body: null,
  addEventListener(name, fn) { listen('document', name, fn); },
  querySelectorAll() { return []; },
};

const window = {
  VAAK_CSRF: 'csrf-test',
  __VAAK_HOME_SIGNAL_PROBE: true,
  setTimeout(fn) { timeouts.push(fn); return timeouts.length; },
  clearTimeout() {},
  setInterval() { return 1; },
  clearInterval() {},
  addEventListener(name, fn, options) { listen('window', name, fn); return options; },
};

const context = {
  window,
  document,
  FormData,
  IntersectionObserver,
  MutationObserver,
  performance: { now: () => nowMs },
  navigator: {
    sendBeacon(url, body) {
      beacons.push({ url, body });
      return true;
    },
  },
  fetch(url, init) {
    fetches.push({ url, init });
    return Promise.resolve({ ok: true });
  },
  setTimeout(fn) { timeouts.push(fn); return timeouts.length; },
  clearTimeout() {},
  setInterval() { return 1; },
  clearInterval() {},
  console,
  JSON,
  Set,
  WeakMap,
  Math,
  Array,
  Number,
  String,
  Object,
};
context.globalThis = context;
context.window.document = document;
context.window.performance = context.performance;
context.window.navigator = context.navigator;
context.window.fetch = context.fetch;
vm.createContext(context);
vm.runInContext(script, context);
const probe = context.window.__VAAK_HOME_SIGNAL_PROBE;
assert(probe && probe.MIN_DWELL_MS === 6000, 'dwell starts at six seconds');
assert(probe.MAX_DWELL_S === 300, 'dwell caps at five minutes');
assert(probe.IDLE_MS === 180000, 'an idle visible tab stops counting after three minutes');

function card(id, view) {
  return {
    id,
    dataset: {
      rankKey: 'https://example.test/users/a/notes/' + (id || view),
      rankActor: 'https://example.test/users/a',
      rankPlatform: 'fedi',
    },
    closest(selector) {
      if (selector === '#timeline-items' && view) return { dataset: { view } };
      return null;
    },
  };
}

const home = card('', 'home');
const local = card('', 'local');
const status = card('status-focus', '');
assert(probe.isTracked(home), 'home cards are measured');
assert(probe.isTracked(status), 'the open post is measured');
assert(!probe.isTracked(local), 'local and other feeds are not measured');

probe.enqueue('dwell', home, 3);
assert.strictEqual(probe.queue.length, 0, 'a glance is not queued');
probe.enqueue('dwell', home, 9999);
assert.strictEqual(probe.queue[0].dwell_seconds, 300, 'queued dwell is capped');
probe.queue.length = 0;

probe.start(home);
nowMs = 1000;
probe.leave(home);
assert.strictEqual(probe.queue.length, 0, 'one second of visibility is not dwell');
assert.strictEqual(probe.requests.length, 0, 'a fast scroll sends no request');

const paused = card('pause', 'home');
nowMs = 0;
probe.start(paused);
nowMs = 7000;
probe.setIdleFor(probe.IDLE_MS);
nowMs = 7000 + probe.IDLE_MS;
probe.leave(paused);
assert.strictEqual(probe.queue.length, 1, 'time before the idle cutoff is kept');
assert.strictEqual(probe.queue[0].kind, 'dwell');
assert.strictEqual(probe.queue[0].dwell_seconds, 7, 'idle minutes are not added to dwell');
assert.strictEqual(probe.queue[0].target_actor, 'https://example.test/users/a');
probe.queue.length = 0;

for (let i = 0; i < 8; i++) probe.enqueue('impression', home, 0);
assert.strictEqual(fetches.length, 1, 'eight measurements leave as one request');
assert.strictEqual(probe.requests[0].length, 8, 'the batch holds the eight measurements');
assert.strictEqual(fetches[0].url, '/vaak/?view=home&ajax=home_signal_batch');
const events = JSON.parse(fetches[0].init.body.map.get('events'));
assert.strictEqual(events.length, 8);
assert.ok(!Object.prototype.hasOwnProperty.call(events[0], 'scroll'));
assert.ok(!Object.prototype.hasOwnProperty.call(events[0], 'url'));

const scroll = (listeners['window:scroll'] || [])[0];
assert.strictEqual(typeof scroll, 'function', 'scroll is only an activity mark');
const before = fetches.length;
scroll();
assert.strictEqual(fetches.length, before, 'scrolling does not send a request');

const pagehide = (listeners['window:pagehide'] || [])[0];
probe.enqueue('click', status, 0);
pagehide();
assert.strictEqual(beacons.length, 1, 'leaving the page flushes the batch with one beacon');
assert.strictEqual(beacons[0].url, '/vaak/?view=home&ajax=home_signal_batch');

for (let i = 0; i < 9; i++) {
  probe.queue.push({
    kind: 'impression',
    platform: 'fedi',
    target_key: 'k' + i,
    target_actor: 'https://example.test/users/a',
    dwell_seconds: 0,
  });
}
pagehide();
assert.strictEqual(beacons.length, 3, 'leaving the page drains a longer queue in batches of eight');

console.log('home-signal-dwell-regression ok');
