// After a quote is posted or the composer is closed, the shared composer must
// go back to a plain post: no quote_object field, no quote card, no quote
// placeholder, and the poll toggle visible again. Extracts the production
// reset helpers from ap-admin.php and runs them against a tiny fake form.
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const path = require('path');

const file = process.argv[2] || path.join(__dirname, '..', 'ap-admin.php');
const source = fs.readFileSync(file, 'utf8');

function extractFunction(name) {
  const start = source.indexOf('  function ' + name + '(');
  assert(start > 0, 'missing function ' + name);
  let i = source.indexOf('{', start);
  let depth = 0;
  for (; i < source.length; i++) {
    if (source[i] === '{') depth++;
    else if (source[i] === '}' && --depth === 0) return source.slice(start, i + 1);
  }
  throw new Error('unterminated function ' + name);
}

function el(tag, props) {
  const node = Object.assign({
    tagName: tag.toUpperCase(), style: {}, dataset: {}, value: '', textContent: '',
    parentElement: null, removed: false,
    remove() { node.removed = true; },
    closest() { return null; },
  }, props || {});
  return node;
}

function run() {
  const quoteField = el('input', { name: 'quote_object', value: 'https://example.com/notes/1' });
  const quoteBlock = el('div', { className: 'quote-block' });
  const pollToggle = el('button', { id: 'compose-poll-toggle', style: { display: 'none' }, disabled: true });
  const nodes = { quoteField, quoteBlock };
  const form = {
    querySelector(sel) {
      if (sel === 'input[name="quote_object"]') return quoteField.removed ? null : quoteField;
      if (sel === '.quote-block') return quoteBlock.removed ? null : quoteBlock;
      if (sel === '#compose-poll-toggle') return pollToggle;
      return null;
    },
  };
  const byId = {
    'compose-modal-title': el('h2', { textContent: 'Quote' }),
    'compose-submit-btn': el('button', { textContent: 'Quote' }),
    'compose-content': el('textarea', { placeholder: 'Add commentary (optional)…' }),
    'compose-form': form,
  };
  const ctx = {
    form,
    document: { getElementById: (id) => byId[id] || null },
    window: {},
    actionField: el('input', { value: 'reply' }),
    noteIdField: el('input', { value: '' }),
    queueBtn: null, draftBtn: null, mediaInput: null, mediaHint: null, visibilityWrap: null,
    composeMode: 'reply',
    activeComposeForm: () => form,
    setComposePollOpen() {},
    syncReplyPrivacyGuard() {},
  };
  vm.createContext(ctx);
  vm.runInContext([
    extractFunction('clearQuoteChrome'),
    extractFunction('composePollIsEditOrQuote'),
    extractFunction('resetComposeChrome'),
    'window.vaakSyncComposePollChrome = function () {'
      + ' const t = form.querySelector("#compose-poll-toggle");'
      + ' const blocked = composePollIsEditOrQuote();'
      + ' t.style.display = blocked ? "none" : ""; t.disabled = blocked; };',
    'resetComposeChrome();',
  ].join('\n'), ctx);
  return { byId, nodes, pollToggle };
}

const r = run();
assert.strictEqual(r.nodes.quoteField.removed, true, 'quote_object field removed');
assert.strictEqual(r.nodes.quoteBlock.removed, true, 'quote card removed');
assert.strictEqual(r.byId['compose-content'].placeholder, "What's happening?", 'placeholder back to a plain post');
assert.strictEqual(r.byId['compose-modal-title'].textContent, 'Compose', 'title back to Compose');
assert.strictEqual(r.byId['compose-submit-btn'].textContent, 'Post', 'submit back to Post');
assert.strictEqual(r.pollToggle.style.display, '', 'poll toggle shown again after a quote');
assert.strictEqual(r.pollToggle.disabled, false, 'poll toggle enabled again after a quote');
console.log('compose quote reset regression: ok');
