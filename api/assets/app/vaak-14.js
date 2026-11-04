/* VAAK app script 14. Moved out of api/ap-admin.php (was an inline <script>) so
   browsers cache and precompile it; the shell loads these files in their old order.
*/
/* vaak-paste-media:start */
(function (root) {
  'use strict';
  var LIMITS = { image: 25 * 1024 * 1024, video: 100 * 1024 * 1024, audio: 20 * 1024 * 1024 };

  function mediaKind(file) {
    var type = String((file && file.type) || '').toLowerCase();
    if (type.indexOf('video/') === 0) return 'video';
    if (type.indexOf('audio/') === 0) return 'audio';
    if (type.indexOf('image/') === 0 || /\.(heic|heif)$/i.test(String((file && file.name) || ''))) return 'image';
    return '';
  }

  // Match ap_media_ingest_upload caps. The server still checks the bytes.
  function mediaLimitError(file) {
    var kind = mediaKind(file);
    if (!kind) return 'Unsupported media type.';
    var max = LIMITS[kind] || 0;
    if (file && file.size > max) {
      return kind.charAt(0).toUpperCase() + kind.slice(1) + ' too large (max ' + Math.round(max / (1024 * 1024)) + 'MB).';
    }
    return '';
  }

  function filterMediaFiles(existingCount, incoming, max) {
    var accepted = [];
    var errors = [];
    var start = existingCount > 0 ? existingCount : 0;
    var cap = max > 0 ? max : 0;
    var list = incoming && typeof incoming.length === 'number' ? incoming : [];
    for (var i = 0; i < list.length; i++) {
      var file = list[i];
      if (!file || typeof file.size !== 'number') continue;
      if (start + accepted.length >= cap) {
        errors.push('Too many media attachments (max ' + cap + ').');
        break;
      }
      var lim = mediaLimitError(file);
      if (lim) {
        errors.push(lim);
        continue;
      }
      accepted.push(file);
    }
    return { accepted: accepted, errors: errors };
  }

  function pasteExtension(mime, name) {
    var map = {
      'image/jpeg': 'jpg',
      'image/jpg': 'jpg',
      'image/pjpeg': 'jpg',
      'image/png': 'png',
      'image/gif': 'gif',
      'image/webp': 'webp',
      'image/avif': 'avif',
      'image/heic': 'heic',
      'image/heif': 'heif',
      'image/heic-sequence': 'heic',
      'image/heif-sequence': 'heif'
    };
    var clean = String(mime || '').split(';')[0].trim().toLowerCase();
    if (map[clean]) return map[clean];
    var fromName = String(name || '').toLowerCase().match(/\.([a-z0-9]{1,8})$/);
    if (fromName && /^(jpe?g|png|gif|webp|avif|heic|heif)$/.test(fromName[1])) {
      return fromName[1] === 'jpeg' ? 'jpg' : fromName[1];
    }
    var sub = (clean.split('/')[1] || 'png').split('+')[0].replace(/[^a-z0-9]/g, '');
    return sub || 'png';
  }

  function nameIsGeneric(name) {
    var base = String(name || '').replace(/[\u0000-\u001f\\/]/g, '').trim();
    if (!base || base === '.' || base === '..') return true;
    return /^(image|img|blob|clipboard|screenshot|pasted-image|pastedimage|unknown)(\.[a-z0-9]+)?$/i.test(base);
  }

  function pasteStamp(clock) {
    var date = clock instanceof Date ? clock : new Date(typeof clock === 'number' ? clock : Date.now());
    function p(n, w) {
      var s = String(n);
      while (s.length < w) s = '0' + s;
      return s;
    }
    return p(date.getUTCFullYear(), 4)
      + p(date.getUTCMonth() + 1, 2)
      + p(date.getUTCDate(), 2)
      + '-'
      + p(date.getUTCHours(), 2)
      + p(date.getUTCMinutes(), 2)
      + p(date.getUTCSeconds(), 2)
      + '-'
      + p(date.getUTCMilliseconds(), 3);
  }

  function pasteMime(type, file) {
    var mime = String(type || (file && file.type) || '').split(';')[0].trim().toLowerCase();
    if (mime.indexOf('image/') === 0) return mime;
    if (/\.(heic|heif)$/i.test(String((file && file.name) || ''))) return 'image/heic';
    return mime || 'image/png';
  }

  function looksLikeImage(type, file) {
    var mime = String(type || (file && file.type) || '').split(';')[0].trim().toLowerCase();
    return mime.indexOf('image/') === 0 || /\.(heic|heif)$/i.test(String((file && file.name) || ''));
  }

  function namedPasteFile(file, type, stamp, index) {
    var mime = pasteMime(type, file);
    var ext = pasteExtension(mime, file && file.name);
    var name = String((file && file.name) || '').replace(/[\u0000-\u001f\\/]/g, '').trim();
    if (nameIsGeneric(name)) {
      name = 'pasted-image-' + stamp + (index > 1 ? '-' + index : '') + '.' + ext;
    } else if (!/\.[A-Za-z0-9]{1,8}$/.test(name)) {
      name = name + '.' + ext;
    }
    if (name.length > 80) {
      var dot = name.lastIndexOf('.');
      var keepExt = dot > 0 ? name.slice(dot) : '';
      name = name.slice(0, Math.max(1, 80 - keepExt.length)) + keepExt;
    }
    if (file && name === file.name && String(file.type || '').toLowerCase() === mime) return file;
    try {
      return new File([file], name, {
        type: mime || 'image/png',
        lastModified: (file && file.lastModified) || Date.now()
      });
    } catch (e) {
      return file;
    }
  }

  function collectClipboardFiles(clipboardData) {
    var fromItems = [];
    var sawItem = false;
    var items = clipboardData && clipboardData.items;
    if (items && typeof items.length === 'number') {
      for (var i = 0; i < items.length; i++) {
        var item = items[i];
        if (!item || item.kind !== 'file') continue;
        sawItem = true;
        var file = null;
        try {
          file = typeof item.getAsFile === 'function' ? item.getAsFile() : null;
        } catch (e) {
          file = null;
        }
        fromItems.push({
          type: String((item && item.type) || (file && file.type) || '').split(';')[0].trim().toLowerCase(),
          file: file
        });
      }
    }
    var readable = false;
    for (var j = 0; j < fromItems.length; j++) {
      if (fromItems[j].file) readable = true;
    }
    if (sawItem && readable) return fromItems;
    var fromFiles = [];
    var files = clipboardData && clipboardData.files;
    if (files && typeof files.length === 'number') {
      for (var k = 0; k < files.length; k++) {
        var listed = files[k];
        if (!listed) continue;
        fromFiles.push({
          type: String(listed.type || '').split(';')[0].trim().toLowerCase(),
          file: listed
        });
      }
    }
    return fromFiles.length ? fromFiles : fromItems;
  }

  // Image files on the clipboard become attachments. String items are ignored
  // here so a normal text paste is left to the browser.
  function clipboardImageFiles(clipboardData, clock) {
    var out = { sawFile: false, images: [], rejected: 0, unreadable: 0 };
    if (!clipboardData) return out;
    var entries = collectClipboardFiles(clipboardData);
    if (!entries.length) return out;
    out.sawFile = true;
    var stamp = pasteStamp(clock);
    var n = 0;
    for (var i = 0; i < entries.length; i++) {
      var entry = entries[i];
      if (!entry.file) {
        out.unreadable += 1;
        continue;
      }
      if (!looksLikeImage(entry.type, entry.file)) {
        out.rejected += 1;
        continue;
      }
      n += 1;
      out.images.push(namedPasteFile(entry.file, entry.type, stamp, n));
    }
    return out;
  }

  function attachmentNotice(files) {
    var list = files && typeof files.length === 'number' ? files : [];
    var n = list.length;
    if (!n) return '';
    var images = true;
    for (var i = 0; i < n; i++) {
      var file = list[i];
      var type = String((file && file.type) || '').toLowerCase();
      if (type.indexOf('image/') !== 0 && !/\.(heic|heif)$/i.test(String((file && file.name) || ''))) {
        images = false;
        break;
      }
    }
    if (n === 1) return images ? 'Image attached.' : 'Attachment added.';
    return String(n) + (images ? ' images attached.' : ' attachments added.');
  }

  function announceLive(el, message) {
    if (!el || !message) return;
    var text = String(message);
    el.textContent = '';
    var write = function () { el.textContent = text; };
    if (typeof root.setTimeout === 'function') root.setTimeout(write, 30);
    else write();
  }

  root.vaakPasteMedia = {
    mediaLimitError: mediaLimitError,
    filterMediaFiles: filterMediaFiles,
    clipboardImageFiles: clipboardImageFiles,
    attachmentNotice: attachmentNotice,
    announceLive: announceLive,
    pasteTypeMessage: 'Only images can be pasted. Use the media button for other files.',
    pasteReadMessage: 'Could not read the pasted image.',
    pasteEditMessage: 'Media stays attached. You can edit the text and content warning.'
  };
})(typeof window !== 'undefined' ? window : globalThis);
/* vaak-paste-media:end */
