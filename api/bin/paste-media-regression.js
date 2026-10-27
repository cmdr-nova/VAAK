// Pasting an image into a composer attaches it. Plain text still pastes.
// The helper is the same one the post composer and the DM form call.
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const path = require('path');

const file = process.argv[2] || path.join(__dirname, '..', 'ap-admin.php');
const source = fs.readFileSync(file, 'utf8');
const begin = source.indexOf('/* vaak-paste-media:start */');
const end = source.indexOf('/* vaak-paste-media:end */', begin);
assert(begin > 0 && end > begin, 'paste media markers');
const js = source.slice(begin, end);

function FileShim(parts, name, opts) {
  const sourceFile = parts && parts[0];
  this.name = String(name || '');
  this.type = String((opts && opts.type) || '');
  this.size = sourceFile && typeof sourceFile.size === 'number' ? sourceFile.size : 0;
  this.lastModified = (opts && opts.lastModified) || 0;
  this._renamed = true;
}

const pending = [];
const sandbox = {
  Date,
  Math,
  String,
  RegExp,
  setTimeout(fn) { pending.push(fn); return pending.length; },
};
sandbox.window = sandbox;
sandbox.globalThis = sandbox;
sandbox.File = FileShim;
vm.createContext(sandbox);
vm.runInContext(js, sandbox);
const api = sandbox.vaakPasteMedia;
assert(api && typeof api.clipboardImageFiles === 'function', 'helper installed');

function flush() {
  const queued = pending.splice(0, pending.length);
  queued.forEach((fn) => fn());
}

function mediaFile(name, type, size) {
  return { name: name, type: type, size: size == null ? 1200 : size, lastModified: 10 };
}

function item(kind, type, asFile) {
  return {
    kind,
    type,
    getAsFile() {
      if (asFile instanceof Error) throw asFile;
      return asFile;
    },
  };
}

function clipboard(items, files) {
  return { items: items || [], files: files || [] };
}

const clock = new Date(Date.UTC(2026, 9, 27, 4, 7, 12, 842));
const stamp = '20261027-040712-842';

const textOnly = api.clipboardImageFiles(clipboard([
  item('string', 'text/plain', null),
  item('string', 'text/html', null),
]), clock);
assert.strictEqual(textOnly.sawFile, false, 'plain text is not intercepted');
assert.strictEqual(textOnly.images.length, 0, 'plain text yields no files');

const shot = mediaFile('image.png', 'image/png', 4096);
const pasted = api.clipboardImageFiles(clipboard([
  item('string', 'text/html', null),
  item('file', 'image/png', shot),
], [shot]), clock);
assert.strictEqual(pasted.sawFile, true, 'an image file is intercepted');
assert.strictEqual(pasted.images.length, 1, 'html beside the image is not a second attachment');
assert.strictEqual(pasted.rejected, 0);
assert.strictEqual(pasted.images[0].name, 'pasted-image-' + stamp + '.png');
assert.strictEqual(pasted.images[0].type, 'image/png');
assert.strictEqual(pasted.images[0].size, 4096, 'renamed file keeps its bytes');

const named = mediaFile('vacation.jpg', 'image/jpeg', 80);
const kept = api.clipboardImageFiles(clipboard([
  item('file', 'image/jpeg', named),
]), clock);
assert.strictEqual(kept.images[0], named, 'a real filename is kept');

const many = api.clipboardImageFiles(clipboard([
  item('file', 'image/png', mediaFile('image.png', 'image/png')),
  item('file', 'image/jpeg', mediaFile('image.jpg', 'image/jpeg')),
  item('file', 'image/webp', mediaFile('', 'image/webp')),
]), clock);
assert.strictEqual(many.images.length, 3, 'several images are kept');
assert.strictEqual(many.images[0].name, 'pasted-image-' + stamp + '.png');
assert.strictEqual(many.images[1].name, 'pasted-image-' + stamp + '-2.jpg');
assert.strictEqual(many.images[2].name, 'pasted-image-' + stamp + '-3.webp');

const heic = mediaFile('IMG_2044.HEIC', '', 90);
const heicClip = api.clipboardImageFiles(clipboard([
  item('file', 'application/octet-stream', heic),
]), clock);
assert.strictEqual(heicClip.images.length, 1, 'heic files are images');
assert.strictEqual(heicClip.images[0].name, 'IMG_2044.HEIC');
assert.strictEqual(heicClip.images[0].type, 'image/heic');

const mixed = api.clipboardImageFiles(clipboard([
  item('file', 'image/gif', mediaFile('image.gif', 'image/gif')),
  item('file', 'application/pdf', mediaFile('notes.pdf', 'application/pdf', 50)),
  item('file', 'video/mp4', mediaFile('clip.mp4', 'video/mp4', 50)),
]), clock);
assert.strictEqual(mixed.images.length, 1, 'only images are attached from a paste');
assert.strictEqual(mixed.rejected, 2, 'other file types are reported');

const filesOnly = api.clipboardImageFiles({
  files: [mediaFile('image.png', 'image/png', 12)],
}, clock);
assert.strictEqual(filesOnly.images.length, 1, 'files list is the fallback when items are missing');
assert.strictEqual(filesOnly.images[0].name, 'pasted-image-' + stamp + '.png');

const unreadable = api.clipboardImageFiles(clipboard([
  item('file', 'image/png', null),
]), clock);
assert.strictEqual(unreadable.sawFile, true);
assert.strictEqual(unreadable.unreadable, 1);
assert.strictEqual(unreadable.images.length, 0);

const thrown = api.clipboardImageFiles(clipboard([
  item('file', 'image/png', new Error('denied')),
]), clock);
assert.strictEqual(thrown.unreadable, 1, 'a failed file read does not throw');

assert.strictEqual(api.clipboardImageFiles(null).sawFile, false);

const png = mediaFile('pic.png', 'image/png', 10);
const big = mediaFile('big.png', 'image/png', 25 * 1024 * 1024 + 1);
const exact = mediaFile('exact.png', 'image/png', 25 * 1024 * 1024);
const pdf = mediaFile('a.pdf', 'application/pdf', 10);
const video = mediaFile('a.mp4', 'video/mp4', 100 * 1024 * 1024 + 1);
const audio = mediaFile('a.mp3', 'audio/mpeg', 20 * 1024 * 1024 + 1);
const okVideo = mediaFile('ok.mp4', 'video/mp4', 1000);
assert.strictEqual(api.mediaLimitError(png), '');
assert.strictEqual(api.mediaLimitError(exact), '', 'the size cap is inclusive');
assert.strictEqual(api.mediaLimitError(big), 'Image too large (max 25MB).');
assert.strictEqual(api.mediaLimitError(pdf), 'Unsupported media type.');
assert.strictEqual(api.mediaLimitError(video), 'Video too large (max 100MB).');
assert.strictEqual(api.mediaLimitError(audio), 'Audio too large (max 20MB).');
assert.strictEqual(api.mediaLimitError(mediaFile('shot.heic', '', 10)), '', 'heic is an image by name');
assert.strictEqual(api.mediaLimitError(okVideo), '');

const room = api.filterMediaFiles(3, [png, mediaFile('b.png', 'image/png', 10), pdf], 4);
assert.strictEqual(room.accepted.length, 1, 'the fourth slot is the last one');
assert.strictEqual(room.errors.length, 1);
assert.strictEqual(room.errors[0], 'Too many media attachments (max 4).');

const typed = api.filterMediaFiles(0, [pdf, png], 4);
assert.strictEqual(typed.accepted.length, 1);
assert.strictEqual(typed.errors.length, 1);
assert.strictEqual(typed.errors[0], 'Unsupported media type.');

const full = api.filterMediaFiles(4, [png], 4);
assert.strictEqual(full.accepted.length, 0);
assert.strictEqual(full.errors[0], 'Too many media attachments (max 4).');

assert.strictEqual(api.attachmentNotice([]), '');
assert.strictEqual(api.attachmentNotice([png]), 'Image attached.');
assert.strictEqual(api.attachmentNotice([png, mediaFile('b.png', 'image/png')]), '2 images attached.');
assert.strictEqual(api.attachmentNotice([okVideo]), 'Attachment added.');
assert.strictEqual(api.attachmentNotice([okVideo, okVideo]), '2 attachments added.');

const live = { textContent: 'stale' };
api.announceLive(live, 'Image attached.');
assert.strictEqual(live.textContent, '', 'the live region clears so a repeat is announced');
flush();
assert.strictEqual(live.textContent, 'Image attached.');
api.announceLive(null, 'Image attached.');
api.announceLive(live, '');
assert.strictEqual(live.textContent, 'Image attached.', 'an empty notice does not wipe the last one');

assert.strictEqual(api.pasteTypeMessage, 'Only images can be pasted. Use the media button for other files.');
assert.ok(api.pasteReadMessage);
assert.ok(api.pasteEditMessage);

const helper = js;
['FileReader', 'createObjectURL', 'toDataURL', 'canvas', 'clipboard.read', 'fetch('].forEach((needle) => {
  assert(!helper.includes(needle), 'paste helper does not ' + needle);
});

const r2 = fs.readFileSync(path.join(__dirname, '..', 'ap-r2.php'), 'utf8');
assert(r2.includes('-auto-orient'), 'server image processing still strips orientation metadata');
assert(r2.includes('function ap_media_ingest_upload'), 'uploads still go through ingest');
assert(r2.includes('function ap_media_convert_to_webp'), 'webp conversion is unchanged');

function between(startNeedle, endNeedle) {
  const start = source.indexOf(startNeedle);
  const stop = source.indexOf(endNeedle, start + startNeedle.length);
  assert(start > 0 && stop > start, startNeedle);
  return source.slice(start, stop);
}

const compose = between("const modal = document.getElementById('compose-modal');", "\n</script>");
assert(compose.includes("form.addEventListener('paste'"), 'post composer listens for paste');
assert(compose.includes('api.clipboardImageFiles(event.clipboardData)'), 'post composer reads clipboard files');
assert(compose.includes('addComposeFiles(clip.images'), 'pasted images use the compose file list');
assert(compose.includes("form.addEventListener('drop'"), 'post composer still accepts a drop');
assert(compose.includes('id="compose-media-live"') || source.includes('id="compose-media-live"'), 'post composer has a live region');
const pasteAt = compose.indexOf("form.addEventListener('paste'");
const pasteBody = compose.slice(pasteAt, compose.indexOf("function activeComposeForm", pasteAt));
const guard = pasteBody.indexOf('if (!clip || !clip.sawFile) return;');
const prevent = pasteBody.indexOf('event.preventDefault()');
assert(guard > 0 && prevent > guard, 'text paste is not cancelled');
assert(pasteBody.includes("composeMode === 'edit_status'"), 'edit mode does not take new media');
assert(compose.includes("fd.set('action', 'upload_media')"), 'compose still pre-uploads through the existing action');

const dm = between("document.querySelectorAll('form.composer .dm-media-input')", '</script>');
assert(dm.includes("form.addEventListener('paste'"), 'DM composers listen for paste');
assert(dm.includes('api.clipboardImageFiles(event.clipboardData)'), 'DM paste uses the shared helper');
assert(dm.includes('addDmFiles(clip.images'), 'DM paste uses the existing file list');
assert(dm.includes("form.addEventListener('drop'"), 'DM composers accept a drop on the same path');
assert(dm.includes('dm-media-live'), 'DM attachment changes are announced');
assert(dm.includes("input.files = dt.files"), 'DM files stay on the form that already uploads them');
['upload_media', 'fetch(', 'outbox', 'activitystreams#Public', '/followers'].forEach((needle) => {
  assert(!dm.includes(needle), 'DM paste does not contain ' + needle);
});
assert(!source.includes('paste_media') && !source.includes('upload_paste'), 'no new upload action');
assert(source.includes('class="dm-media-live"'), 'both DM forms get the live region from the shared fields');
assert((source.match(/admin_dm_composer_fields_html\(\)/g) || []).length >= 2, 'new DM and reply share the fields');

console.log('paste-media-regression: ok');
