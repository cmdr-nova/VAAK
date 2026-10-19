// The address bar must name the page on screen after every soft navigation.
// Soft-nav used to edit the current URL, so a profile's actor/tab/post (or the
// pretty /vaak/users/{key} path) rode along onto Home, Notifications, a post,
// and Search. This extracts the production URL builder and the two pushState
// sites and checks the address comes from the destination alone.
const fs = require('fs');
const vm = require('vm');
const path = require('path');

const file = process.argv[2] || path.join(__dirname, '..', 'ap-admin.php');
const source = fs.readFileSync(file, 'utf8');

const checks = [];
function check(ok, label) {
  if (!ok) {
    console.error(JSON.stringify({ file, passed: false, failed: label, checks }, null, 2));
    process.exit(1);
  }
  checks.push(label);
}

const start = source.indexOf('window.vaakSoftViewHref = function');
check(start >= 0, 'vaakSoftViewHref is defined');
const end = source.indexOf('\n  };\n', start);
check(end > start, 'vaakSoftViewHref body found');
const builderJs = source.slice(start, end + '\n  };'.length);

// Run it with the browser sitting on a profile, so any leak from the current
// location would show up in the result.
const win = {
  location: { href: 'https://mkultra.monster/vaak/users/valerie?tab=media&post=abc#x' },
};
vm.runInNewContext(builderJs, { window: win, URLSearchParams, URL });
const href = win.vaakSoftViewHref;

function parse(h) {
  const u = new URL(h, 'https://mkultra.monster/');
  return { path: u.pathname, params: Object.fromEntries(u.searchParams.entries()), hash: u.hash };
}
function same(h, wantParams, label) {
  const got = parse(h);
  const ok = got.path === '/vaak/' && got.hash === ''
    && JSON.stringify(got.params) === JSON.stringify(wantParams);
  if (!ok) console.error('got', h, 'want', wantParams);
  check(ok, label);
}

same(href('home'), { view: 'home' }, 'Home after a profile is just ?view=home');
same(href('local', '', {}), { view: 'local' }, 'Local is just ?view=local');
same(href('mentions', 'all', {}), { view: 'mentions' }, 'Notifications "all" carries no filter');
same(href('mentions', 'follows', {}), { view: 'mentions', notification_filter: 'follows' },
  'a Notifications filter is kept');
same(href('status', '', { object: 'https://ex.social/@a/1', from: 'remote_profile' }),
  { view: 'status', object: 'https://ex.social/@a/1', from: 'remote_profile' },
  'a post names its object and origin, nothing from the profile');
same(href('status', '', { object: 'x', from: 'home', refresh_thread: '1' }),
  { view: 'status', object: 'x', from: 'home' }, 'refresh_thread never reaches the address bar');
same(href('search', '', { q: 'cats', type: 'accounts', resolve: '1' }),
  { view: 'search', q: 'cats', type: 'accounts', resolve: '1' }, 'Search keeps its query');
same(href('favourites', '', { network: 'bsky' }), { view: 'favourites', network: 'bsky' },
  'Favourites keeps its network');
same(href('following', '', { network: 'all', limit: '40' }),
  { view: 'following', network: 'all', limit: '40' }, 'Following keeps network and limit');
same(href('bookmarks', '', { folder: '3', network: 'bsky' }), { view: 'bookmarks', folder: '3' },
  'Bookmarks keeps its folder and drops unrelated extras');
same(href('outbox'), { view: 'outbox' }, 'Your posts leaves the pretty profile path');

// Both pushState sites must use the builder rather than editing location.
const pushStart = source.indexOf('  function pushSoftHistory(view, filter, extra) {');
const pushEnd = source.indexOf('\n  }\n', pushStart);
const pushBody = source.slice(pushStart, pushEnd);
check(pushStart >= 0 && /vaakSoftViewHref\(view, filter, extra\)/.test(pushBody)
  && !/vaakAppHistoryUrl|location\.href/.test(pushBody),
  'soft-nav history is built from the destination');

const tlPush = source.indexOf("history.pushState({ vaakTl: nextView }, '', href);");
const tlBody = source.slice(Math.max(0, tlPush - 400), tlPush);
check(tlPush >= 0 && /vaakSoftViewHref\(nextView\)/.test(tlBody) && !/vaakAppHistoryUrl/.test(tlBody),
  'Home/Local/Federated tab swaps build their own address');

const notFound = source.indexOf("main.innerHTML = html + '</div>';");
const notFoundBody = source.slice(notFound, source.indexOf('return;', notFound));
check(notFound >= 0 && /pushSoftHistory\(view, filter, extra\)/.test(notFoundBody),
  'an unavailable post still updates the address bar');

console.log(JSON.stringify({ file, passed: true, checks }, null, 2));
