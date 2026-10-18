// "End of profile" on a finished profile waits until the reader reaches the
// bottom. Off-screen cards paint late, so an early label sat under the first
// few posts. Extracts the production helpers from ap-admin.php.
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');

const file = process.argv[2] || require('path').join(__dirname, '..', 'ap-admin.php');
const source = fs.readFileSync(file, 'utf8');
const start = source.indexOf('  function profileScrollStatus(more) {');
const end = source.indexOf('\n  }\n', source.indexOf('  function profileBootStatus('));
assert(start > 0 && end > start, 'profile status helper markers');
const js = source.slice(start, end + '\n  }\n'.length);

const observers = [];
class FakeIO {
  constructor(cb, opts) { this.cb = cb; this.opts = opts; this.targets = []; this.live = true; observers.push(this); }
  observe(el) { this.targets.push(el); }
  disconnect() { this.live = false; }
  fire(hit) { if (this.live) this.cb(this.targets.map(() => ({ isIntersecting: hit }))); }
}
const ctx = { window: {}, IntersectionObserver: FakeIO };
vm.createContext(ctx);
vm.runInContext(js + '\nthis.profileBootStatus = profileBootStatus;', ctx);

const status = { textContent: 'End of profile' };
const sentinel = {};
const root = { id: 'feed' };

ctx.profileBootStatus(status, sentinel, false, root);
assert.strictEqual(status.textContent, '', 'end label hidden on first paint');
assert.strictEqual(observers.length, 1, 'sentinel watched');
assert.strictEqual(observers[0].opts.root, root, 'scroll container used as root');
observers[0].fire(false);
assert.strictEqual(status.textContent, '', 'still hidden while bottom is off screen');
observers[0].fire(true);
assert.strictEqual(status.textContent, 'End of profile', 'label shows at the bottom');
assert.strictEqual(observers[0].live, false, 'observer released');

ctx.profileBootStatus(status, sentinel, true, null);
assert.strictEqual(status.textContent, 'Scroll for more…', 'more pages keep the scroll prompt');
assert.strictEqual(observers.length, 1, 'no observer when more pages exist');

ctx.profileBootStatus(status, sentinel, false, null);
ctx.profileBootStatus(status, sentinel, false, null);
assert.strictEqual(observers[1].live, false, 'a re-boot drops the previous observer');
assert.strictEqual(observers[2].live, true, 'latest observer stays');

const noIo = { window: {} };
vm.createContext(noIo);
vm.runInContext(js + '\nthis.profileBootStatus = profileBootStatus;', noIo);
const s2 = { textContent: '' };
noIo.profileBootStatus(s2, sentinel, false, null);
assert.strictEqual(s2.textContent, 'End of profile', 'no IntersectionObserver falls back to the label');

console.log('profile-end-label-regression: PASS');
