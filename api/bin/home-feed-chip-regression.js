// Home must always have one feed chip picked. A Bluesky feed opened from
// outside the pinned row (trending topic, Search > Feeds, Library > Feeds)
// used to leave every chip unpicked; it now gets a chip marked Temporary,
// a ✕ and a note with a link back to the feed the viewer came from.
// Extracts the production chip painter from ap-admin.php and runs it
// against a tiny fake chip row.
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

const camel = (k) => k.replace(/^data-/, '').replace(/-([a-z])/g, (_, c) => c.toUpperCase());

function el(tag) {
  const e = {
    tagName: String(tag || 'a').toUpperCase(), dataset: {}, attrs: {}, children: [], parent: null,
    classes: new Set(), href: '', _text: '',
    get className() { return Array.from(e.classes).join(' '); },
    set className(v) { e.classes = new Set(String(v).split(/\s+/).filter(Boolean)); },
    get textContent() { return e.children.length ? e.children.map((c) => c.textContent).join('') : e._text; },
    set textContent(v) { e.children = []; e._text = String(v); },
    get nextSibling() {
      if (!e.parent) return null;
      const i = e.parent.children.indexOf(e);
      return e.parent.children[i + 1] || null;
    },
    classList: {
      toggle(c, on) { if (on) e.classes.add(c); else e.classes.delete(c); },
      contains(c) { return e.classes.has(c); },
    },
    setAttribute(k, v) {
      e.attrs[k] = String(v);
      if (k.indexOf('data-') === 0) e.dataset[camel(k)] = String(v);
    },
    getAttribute(k) { return k in e.attrs ? e.attrs[k] : null; },
    removeAttribute(k) { delete e.attrs[k]; },
    appendChild(c) { c.parent = e; e.children.push(c); return c; },
    insertBefore(c, ref) {
      c.parent = e;
      const i = e.children.indexOf(ref);
      if (i < 0) e.children.push(c); else e.children.splice(i, 0, c);
      return c;
    },
    remove() { if (e.parent) e.parent.children = e.parent.children.filter((c) => c !== e); },
    all() { return e.children.reduce((acc, c) => acc.concat([c], c.all ? c.all() : []), []); },
    matches(sel) {
      return sel.split(',').map((s) => s.trim()).some((s) => {
        const m = s.match(/^(a)?(?:\.([\w-]+))?(?:\[([\w-]+)\])?$/);
        if (!m) throw new Error('unexpected selector ' + s);
        if (m[1] && e.tagName !== 'A') return false;
        if (m[2] && !e.classes.has(m[2])) return false;
        if (m[3] && !(m[3] in e.attrs)) return false;
        return true;
      });
    },
    querySelectorAll(sel) { return e.all().filter((c) => c.matches && c.matches(sel)); },
    querySelector(sel) { return e.querySelectorAll(sel)[0] || null; },
  };
  e.dataset = new Proxy({}, {
    set(t, k, v) { t[k] = String(v); e.attrs['data-' + String(k).replace(/[A-Z]/g, (c) => '-' + c.toLowerCase())] = String(v); return true; },
  });
  return e;
}

function chip(key) {
  const a = el('a');
  a.className = 'btn btn-ghost';
  a.setAttribute('data-chip', key);
  a.textContent = key === 'vaak' ? 'VAAK' : key === 'following' ? 'Following' : 'Discover';
  return a;
}

const DISCOVER = 'at://did:plc:x/app.bsky.feed.generator/discover';

function makeSlot(active, label) {
  const slot = el('div');
  const row = el('nav');
  row.className = 'bsky-feed-tabs';
  slot.appendChild(row);
  ['vaak', 'following', DISCOVER].forEach((k) => row.appendChild(chip(k)));
  slot.dataset.active = active;
  slot.dataset.filled = '1';
  slot.hidden = true;
  if (label) slot.dataset.activeLabel = label;
  return { slot, row };
}

const ctx = {
  chipsHtml: '<nav class="bsky-feed-tabs"></nav>',
  revealActiveChip() {},
  encodeURIComponent,
  window: {},
  document: {
    createElement: (t) => el(t),
    createTextNode: (t) => { const n = el('#text'); n.textContent = t; return n; },
  },
};
vm.createContext(ctx);
const lastPinnedDecl = source.slice(anchor).match(/\n  let lastPinnedChip = [^\n]+\n/);
assert(lastPinnedDecl, 'missing lastPinnedChip');
vm.runInContext(lastPinnedDecl[0] + extractFunction('ensureActiveChip') + '\n' + extractFunction('paint'), ctx);

function picked(row) {
  return row.children.filter((c) => c.attrs['aria-current'] === 'page').map((c) => c.dataset.chip);
}

// Pinned feed: its own chip, nothing added, no note.
let t = makeSlot(DISCOVER);
ctx.paint(t.slot, 'bluesky');
assert.deepStrictEqual(picked(t.row), [DISCOVER]);
assert.strictEqual(t.row.children.length, 3);
assert.strictEqual(t.slot.querySelector('[data-temp-feed-note]'), null);

// Feed opened from a trending topic: a chip of its own, picked, labelled
// Temporary, a ✕ and a note, both going back to Discover (last pinned feed).
const topic = 'at://did:plc:y/app.bsky.feed.generator/topic-cars';
t = makeSlot(topic, 'Cars');
ctx.paint(t.slot, 'bluesky');
assert.deepStrictEqual(picked(t.row), [topic]);
assert.strictEqual(t.row.children.length, 5);
const temp = t.row.children[3];
assert.strictEqual(temp.attrs['data-temp-chip'], '1');
assert.strictEqual(temp.textContent, 'Temporary Cars');
assert.strictEqual(temp.attrs['data-vaak-soft-nav'], 'bluesky');
assert.ok(temp.href.indexOf(encodeURIComponent(topic)) > 0);
assert.ok(temp.classes.has('btn-primary'));
const close = t.row.children[4];
assert.strictEqual(close.attrs['data-temp-close'], '1');
assert.strictEqual(close.textContent, '✕');
assert.strictEqual(close.attrs['data-bsky-feed'], DISCOVER);
assert.ok(/Discover/.test(close.attrs['aria-label']));
const note = t.slot.querySelector('[data-temp-feed-note]');
assert.ok(note, 'temporary feed note');
assert.ok(/temporary feed/.test(note.textContent));
assert.ok(/Back to Discover/.test(note.textContent));
const back = note.querySelector('a');
assert.strictEqual(back.attrs['data-vaak-soft-nav'], 'bluesky');
assert.ok(back.href.indexOf(encodeURIComponent(DISCOVER)) > 0);

// Repaint (chip sync runs often): nothing doubles up.
ctx.paint(t.slot, 'bluesky');
assert.strictEqual(t.row.children.length, 5);
assert.strictEqual(t.slot.querySelectorAll('[data-temp-feed-note]').length, 1);

// Back to Home: the extra chip, ✕ and note go and VAAK is picked.
ctx.paint(t.slot, 'home');
assert.deepStrictEqual(picked(t.row), ['vaak']);
assert.strictEqual(t.row.children.length, 3);
assert.strictEqual(t.slot.querySelector('[data-temp-feed-note]'), null);

// Coming from VAAK, ✕ goes back to Home (view=home).
t = makeSlot(topic, 'Cars');
ctx.paint(t.slot, 'bluesky');
const closeHome = t.row.querySelector('a[data-temp-close]');
assert.strictEqual(closeHome.attrs['data-vaak-soft-nav'], 'home');
assert.ok(/view=home/.test(closeHome.href));
assert.ok(/Back to VAAK/.test(t.slot.querySelector('[data-temp-feed-note]').textContent));

// feed=timeline is Following.
t = makeSlot('timeline');
ctx.paint(t.slot, 'bluesky');
assert.deepStrictEqual(picked(t.row), ['following']);
assert.strictEqual(t.row.children.length, 3);

console.log('home-feed-chip-regression: ok');
