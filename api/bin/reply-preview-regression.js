// Reply composer summaries are read from the card already on the page.
// Mention seeding, self-thread rules, and the followers-only guard stay put.
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const path = require('path');

const file = process.argv[2] || path.join(__dirname, '..', 'ap-admin.php');
const source = fs.readFileSync(file, 'utf8');
const begin = source.indexOf('/* vaak-reply-preview:start */');
const end = source.indexOf('/* vaak-reply-preview:end */', begin);
assert(begin > 0 && end > begin, 'reply preview markers');
const js = source.slice(begin, end);

function createEl(tag) {
  const el = {
    tagName: String(tag || 'div').toUpperCase(),
    id: '',
    children: [],
    parentElement: null,
    parentNode: null,
    textContent: '',
    style: {},
    _attr: {},
  };
  el.classList = {
    _set: new Set(),
    add(...names) { names.forEach((name) => name && el.classList._set.add(name)); },
    contains(name) { return el.classList._set.has(name); },
  };
  Object.defineProperty(el, 'className', {
    get() { return [...el.classList._set].join(' '); },
    set(value) {
      el.classList._set = new Set(String(value || '').split(/\s+/).filter(Boolean));
      el._attr.class = el.className;
    },
  });
  el.getAttribute = (key) => (Object.prototype.hasOwnProperty.call(el._attr, key) ? el._attr[key] : null);
  el.setAttribute = (key, value) => {
    el._attr[key] = String(value);
    if (key === 'id') el.id = String(value);
    if (key === 'class') el.className = String(value);
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
    child.parentNode = el;
    el.children.push(child);
    return child;
  };
  el.insertBefore = (child, before) => {
    child.parentElement = el;
    child.parentNode = el;
    const at = el.children.indexOf(before);
    if (at < 0) el.children.push(child);
    else el.children.splice(at, 0, child);
    return child;
  };
  el.remove = () => {
    const parent = el.parentElement;
    if (!parent) return;
    parent.children = parent.children.filter((child) => child !== el);
    el.parentElement = null;
    el.parentNode = null;
  };
  el.closest = (sel) => {
    let node = el;
    while (node) {
      if (match(node, sel)) return node;
      node = node.parentElement;
    }
    return null;
  };
  return el;
}

function textOf(node) {
  if (!node) return '';
  if (node.textContent) return node.textContent;
  return (node.children || []).map(textOf).join('');
}

const checks = [];
function check(ok, label) {
  assert.ok(ok, label);
  checks.push(label);
}

const ctx = vm.createContext({
  document: { createElement: createEl },
  decodeURIComponent,
  String,
  RegExp,
});
vm.runInContext(js, ctx);
const fromCard = ctx.vaakReplyPreviewFromCard;
const paint = ctx.vaakPaintComposeReplyContext;
const labelOf = ctx.vaakReplyPreviewTargetLabel;
assert.strictEqual(typeof fromCard, 'function');
assert.strictEqual(typeof paint, 'function');

function article(className) {
  const node = createEl('article');
  node.className = className || 'tweet';
  return node;
}
function el(tag, className, text) {
  const node = createEl(tag);
  if (className) node.className = className;
  if (text != null) node.textContent = text;
  return node;
}
function header(name, handle, avatar) {
  const hd = el('div', 'tweet-hd');
  const img = el('img', 'tweet-av');
  img.setAttribute('src', avatar);
  hd.appendChild(img);
  const main = el('div', 'tweet-hd-main tweet-hd-main--fedi');
  main.appendChild(el('a', 'who', name));
  main.appendChild(el('a', 'meta', ' ' + handle));
  main.appendChild(el('span', 'meta', ' · 2h'));
  hd.appendChild(main);
  return hd;
}
function replyLink(href) {
  const a = el('a', 'icon-btn');
  a.setAttribute('href', href);
  return a;
}

const remoteUrl = 'https://remote.example/users/ada/statuses/9';
const card = article('tweet');
card.appendChild(header('Ada Lovelace', '@ada@remote.example', 'https://cdn.example/ada.webp'));
const body = el('div', 'body feed-body', 'Hello there');
card.appendChild(body);
const actions = el('div', 'tweet-actions');
const link = replyLink('?view=home&compose=1&reply_to=' + encodeURIComponent(remoteUrl));
actions.appendChild(link);
card.appendChild(actions);
const remote = fromCard(link);
check(remote && remote.name === 'Ada Lovelace', 'timeline card name');
check(remote.handle === '@ada@remote.example', 'timeline card handle');
check(remote.avatar === 'https://cdn.example/ada.webp', 'timeline card avatar');
check(remote.snippet === 'Hello there', 'timeline snippet is the card text');

const boost = article('tweet tweet-boost');
const banner = el('div', 'meta meta-row', 'Booster boosted');
boost.appendChild(banner);
boost.appendChild(header('Ada Lovelace', '@ada@remote.example', 'https://cdn.example/ada.webp'));
boost.appendChild(el('div', 'body feed-body', 'the boosted post'));
const boostActions = el('div', 'tweet-actions');
const boostLink = replyLink('?view=federated&compose=1&reply_to=' + encodeURIComponent(remoteUrl));
boostActions.appendChild(boostLink);
boost.appendChild(boostActions);
const boosted = fromCard(boostLink);
check(boosted && boosted.name === 'Ada Lovelace' && boosted.snippet === 'the boosted post', 'boost summarizes the boosted post');
check(!boosted.name.includes('Booster'), 'boost banner is not the author');

const quoteCard = article('tweet');
quoteCard.appendChild(header('Ada Lovelace', '@ada@remote.example', 'https://cdn.example/ada.webp'));
quoteCard.appendChild(el('div', 'body feed-body', 'look at this'));
const quoted = el('div', 'quote-block');
quoted.appendChild(header('Other Person', '@other@host', 'https://cdn.example/other.webp'));
quoted.appendChild(el('div', 'body feed-body', 'quoted secret'));
quoted.appendChild(el('div', 'media-row'));
quoteCard.appendChild(quoted);
const quoteActions = el('div', 'tweet-actions');
const quoteLink = replyLink('?view=local&compose=1&reply_to=' + encodeURIComponent(remoteUrl));
quoteActions.appendChild(quoteLink);
quoteCard.appendChild(quoteActions);
const quotedPreview = fromCard(quoteLink);
check(quotedPreview && quotedPreview.snippet === 'look at this', 'quote post snippet is the commentary');
check(quotedPreview.quote === true, 'quote note');
check(quotedPreview.media === false, 'quoted media is not the outer post');
check(quotedPreview.name === 'Ada Lovelace', 'quote post keeps the outer author');

const cwCard = article('tweet tweet-bsky');
cwCard.appendChild(header('Ada Sky', '@ada.bsky.social', 'https://cdn.example/bsky.webp'));
const gate = el('details', 'cw-gate');
gate.appendChild(el('span', 'cw-text', 'Sensitive content'));
const cwBody = el('div', 'cw-body');
cwBody.appendChild(el('div', 'body feed-body', 'backfilled bluesky text'));
cwBody.appendChild(el('div', 'media-row'));
gate.appendChild(cwBody);
cwCard.appendChild(gate);
const bskyActions = el('div', 'tweet-actions');
const bskyLink = replyLink('?view=home&compose=1&reply_to=' + encodeURIComponent('https://bsky.app/profile/ada.bsky.social/post/backfill1'));
bskyActions.appendChild(bskyLink);
cwCard.appendChild(bskyActions);
const bsky = fromCard(bskyLink);
check(bsky && bsky.handle === '@ada.bsky.social', 'bluesky card handle');
check(bsky.snippet === 'backfilled bluesky text', 'bluesky card snippet');
check(bsky.cw === true && bsky.media === true, 'bluesky cw and media notes');

const notif = article('tweet tweet-notif');
notif.appendChild(header('Ada Lovelace', '@ada@remote.example', 'https://cdn.example/ada.webp'));
const embed = el('div', 'notif-status-embed notif-status-embed--nohd');
const inner = article('tweet tweet-embed-nohd');
inner.appendChild(el('div', 'body feed-body', 'mention body'));
const innerActions = el('div', 'tweet-actions');
const innerLink = replyLink('?view=mentions&compose=1&reply_to=' + encodeURIComponent(remoteUrl));
innerActions.appendChild(innerLink);
inner.appendChild(innerActions);
embed.appendChild(inner);
notif.appendChild(embed);
const mention = fromCard(innerLink);
check(mention && mention.name === 'Ada Lovelace' && mention.snippet === 'mention body', 'notification embed borrows the parent header');

const search = article('tweet');
search.appendChild(header('Shana Flux', '@shana_flux', 'https://cdn.example/shana.webp'));
search.appendChild(el('div', 'body feed-body', 'profile and search card'));
const searchActions = el('div', 'tweet-actions');
const searchLink = replyLink('?view=search&compose=1&reply_to=' + encodeURIComponent('https://mkultra.monster/users/shana_flux/notes/peer1'));
searchActions.appendChild(searchLink);
search.appendChild(searchActions);
const searched = fromCard(searchLink);
check(searched && searched.name === 'Shana Flux' && searched.handle === '@shana_flux', 'search and profile cards');

check(fromCard(el('a', 'icon-btn')) === null, 'a reply link outside a card falls back');
const badAvatar = article('tweet');
const badHd = header('Ada', '@ada@remote.example', 'javascript:alert(1)');
badAvatar.appendChild(badHd);
badAvatar.appendChild(el('div', 'body feed-body', 'ok'));
const badActions = el('div', 'tweet-actions');
const badLink = replyLink('?view=home&compose=1&reply_to=' + encodeURIComponent(remoteUrl));
badActions.appendChild(badLink);
badAvatar.appendChild(badActions);
check(fromCard(badLink).avatar === '', 'javascript avatar urls are dropped');

const form = el('form', 'composer');
form.id = 'compose-form';
form.appendChild(el('div', 'meta compose-as-line', 'As @cmdr_nova'));
form.appendChild(el('textarea'));
paint(form, 'Replying to', remoteUrl, remote);
const painted = form.querySelector('.compose-reply-preview');
check(!!painted, 'paint builds a preview');
check(textOf(form.querySelector('.who')) === 'Ada Lovelace', 'painted name is text');
check(textOf(form.querySelector('.compose-reply-preview__text')) === remote.snippet, 'painted snippet');
check(form.querySelector('img').getAttribute('src') === 'https://cdn.example/ada.webp', 'painted avatar');
const xssPreview = { name: '<script>alert(1)</script>', handle: '@ada', avatar: 'javascript:alert(1)', snippet: '<b>nope</b>', media: true, cw: true, quote: false };
paint(form, 'Replying to', remoteUrl, xssPreview);
check(textOf(form.querySelector('.who')) === '<script>alert(1)</script>', 'painted name stays text');
check(!form.querySelector('img').getAttribute('src').startsWith('javascript:'), 'painted avatar is https');
check(textOf(form.querySelector('.tag')) === 'Content warning', 'painted cw note');

paint(form, 'Replying to', remoteUrl, null);
check(!form.querySelector('.compose-reply-preview'), 'missing preview keeps the label');
check(textOf(form.querySelector('strong')).trim() === '@ada', 'fallback handle from the users url');
check(labelOf('https://bsky.app/profile/ada.bsky.social/post/abc') === '@ada.bsky.social', 'fallback bluesky handle');
paint(form, 'Replying to your post', 'https://mkultra.monster/users/cmdr_nova/notes/own1', null);
check(textOf(form.querySelector('.mono')) === 'https://mkultra.monster/users/cmdr_nova/notes/own1', 'own fallback keeps the note url');
check(textOf(form.querySelector('.qt-label')) === 'Replying to your post', 'own label stays a thread continuation');

check(source.includes("p.replace(/^(@[A-Za-z0-9_]+)@(?:www\\.)?(?:mkultra|vaak)\\.monster$/i, '$1')"), 'local host aliases still collapse in the mention seed');
check(source.includes('if (selfKey && key === selfKey) return;'), 'a parent mention of yourself is still not seeded');
check(source.includes('const peer = toUrl.match(/^https:\\/\\/mkultra\\.monster\\/users\\/([A-Za-z0-9_]+)\\/?$/i);'), 'other local accounts still seed a bare @username');
check(source.includes('Followers-only replies are available only on your own posts.'), 'followers-only replies stay limited to your own posts');
check(source.includes('function isOwnNoteUrl(url)'), 'own-note detection is unchanged');
check(source.includes('preview: replyTo && sourceEl ? vaakReplyPreviewFromCard(sourceEl) : null'), 'reply clicks pass the card into the composer');
check(source.includes("title.textContent = isSelf ? 'Continue thread' : 'Reply'"), 'self replies still continue the thread');
check(source.includes('vaakUpgradeServerReplyPreview'), 'a full navigation can still read a card on the page');

console.log('reply preview regression: ' + checks.length + ' checks');
