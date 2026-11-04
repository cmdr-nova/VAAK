// Home must always have one feed chip picked. A Bluesky feed opened from
// outside the pinned row (trending topic, Search > Feeds, Library > Feeds)
// used to leave every chip unpicked. Extracts the production chip painter
// from ap-admin.php and runs it against a tiny fake chip row.
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const path = require('path');

const file = process.argv[2] || path.join(__dirname, '..', 'ap-admin.php');
const source = fs.readFileSync(file, 'utf8');

// The chip script block; other scripts have their own paint().
const anchor = source.indexOf('// Home feed chips: VAAK (Home) first');
assert(anchor > 0, 'missing chip script');

function extractFunction(name) {
  const start = source.indexOf('  function ' + name + '(', anchor);
  assert(start > 0, 'missing function ' + name);
  let i = source.indexOf('{', start);
  let depth = 0;
  for (; i < source.length; i++) {
    if (source[i] === '{') depth++;
    else if (source[i] === '}' && --depth === 0) return source.slice(start, i + 1);
  }
  throw new Error('unterminated function ' + name);
}

function chip(key, attrs) {
  const a = {
    tagName: 'A', dataset: { chip: key }, attrs: Object.assign({ 'data-chip': key }, attrs || {}),
    classes: new Set(['btn', 'btn-ghost']), textContent: key, href: '', className: '', parent: null,
    classList: {
      toggle(c, on) { if (on) a.classes.add(c); else a.classes.delete(c); },
      contains(c) { return a.classes.has(c); },
    },
    setAttribute(k, v) {
      a.attrs[k] = String(v);
      if (k === 'data-chip') a.dataset.chip = String(v);
      if (k === 'data-temp-chip') a.dataset.tempChip = String(v);
    },
    removeAttribute(k) { delete a.attrs[k]; },
    remove() { if (a.parent) a.parent.children = a.parent.children.filter((c) => c !== a); },
  };
  return a;
}

function makeSlot(active, label) {
  const row = {
    children: [],
    appendChild(c) { c.parent = row; if (c.className) c.classes = new Set(c.className.split(' ')); row.children.push(c); },
    querySelectorAll(sel) {
      if (sel === 'a[data-chip]') return row.children.slice();
      if (sel === 'a[data-temp-chip]') return row.children.filter((c) => c.attrs['data-temp-chip'] === '1');
      throw new Error('unexpected selector ' + sel);
    },
  };
  ['vaak', 'following', 'at://did:plc:x/app.bsky.feed.generator/discover'].forEach((k) => row.appendChild(chip(k)));
  const slot = {
    dataset: { active, filled: '1' }, hidden: true, innerHTML: '',
    querySelector(sel) { if (sel === '.bsky-feed-tabs') return row; return null; },
    querySelectorAll(sel) { return row.querySelectorAll(sel); },
  };
  if (label) slot.dataset.activeLabel = label;
  return { slot, row };
}

const ctx = {
  chipsHtml: '<nav class="bsky-feed-tabs"></nav>',
  revealActiveChip() {},
  encodeURIComponent,
  document: { createElement: () => chip('') },
};
vm.createContext(ctx);
vm.runInContext(extractFunction('ensureActiveChip') + '\n' + extractFunction('paint'), ctx);

function picked(row) {
  return row.children.filter((c) => c.attrs['aria-current'] === 'page').map((c) => c.dataset.chip);
}

// Pinned feed: its own chip, nothing added.
let t = makeSlot('at://did:plc:x/app.bsky.feed.generator/discover');
ctx.paint(t.slot, 'bluesky');
assert.deepStrictEqual(picked(t.row), ['at://did:plc:x/app.bsky.feed.generator/discover']);
assert.strictEqual(t.row.children.length, 3);

// Feed opened from a trending topic: a chip of its own, picked, labelled.
const topic = 'at://did:plc:y/app.bsky.feed.generator/topic-cars';
t = makeSlot(topic, 'Cars');
ctx.paint(t.slot, 'bluesky');
assert.deepStrictEqual(picked(t.row), [topic]);
assert.strictEqual(t.row.children.length, 4);
const temp = t.row.children[3];
assert.strictEqual(temp.textContent, 'Cars');
assert.strictEqual(temp.attrs['data-vaak-soft-nav'], 'bluesky');
assert.ok(temp.href.indexOf(encodeURIComponent(topic)) > 0);
assert.ok(temp.classes.has('btn-primary'));

// Back to Home: the extra chip goes and VAAK is picked.
ctx.paint(t.slot, 'home');
assert.deepStrictEqual(picked(t.row), ['vaak']);
assert.strictEqual(t.row.children.length, 3);

// feed=timeline is Following.
t = makeSlot('timeline');
ctx.paint(t.slot, 'bluesky');
assert.deepStrictEqual(picked(t.row), ['following']);
assert.strictEqual(t.row.children.length, 3);

console.log('home-feed-chip-regression: ok');
