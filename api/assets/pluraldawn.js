/**
 * Show a Pluraldawn member on a post when exactly one indicator matches.
 * The account address and the stored post stay as they are.
 */
(function () {
  'use strict';

  function boundaryBefore(text, index) {
    if (index <= 0) return true;
    return /[^\p{L}\p{N}]/u.test(text.charAt(index - 1));
  }

  function boundaryAfter(text, index) {
    if (index >= text.length) return true;
    return /[^\p{L}\p{N}]/u.test(text.charAt(index));
  }

  function indicatorAt(text, indicator, index) {
    if (!indicator || text.slice(index, index + indicator.length) !== indicator) return false;
    var shortcode = indicator.charAt(0) === ':' && indicator.charAt(indicator.length - 1) === ':' && indicator.indexOf(' ') === -1;
    if (shortcode) return true;
    return boundaryBefore(text, index) && boundaryAfter(text, index + indicator.length);
  }

  function stripMentions(text) {
    return text.replace(/^(?:@[^\s@]+(?:@[^\s@]+)?\s+)+/u, '');
  }

  function edgeHit(text, indicators, edge, mention) {
    var source = edge === 'start'
      ? (mention ? stripMentions(text.replace(/^\s+/u, '')) : text.replace(/^\s+/u, ''))
      : text.replace(/\s+$/u, '');
    for (var i = 0; i < indicators.length; i++) {
      var indicator = indicators[i];
      var index = edge === 'start' ? 0 : source.length - indicator.length;
      if (index >= 0 && indicatorAt(source, indicator, index)) return indicator;
    }
    return '';
  }

  /**
   * A member matches when one of their indicators sits at the start of a
   * paragraph (after a reply mention on the first paragraph) or at the end
   * of a paragraph. Two matching members leave the post unchanged.
   * @returns {{member:object,removals:{index:number,edge:string,indicator:string}[]}|null}
   */
  function matchMembers(paragraphs, members) {
    if (!paragraphs.length || !members || !members.length) return null;
    var hits = [];
    for (var i = 0; i < members.length; i++) {
      var member = members[i];
      var removals = [];
      for (var p = 0; p < paragraphs.length; p++) {
        var paragraph = paragraphs[p];
        var start = edgeHit(paragraph, member.indicators || [], 'start', p === 0);
        var end = edgeHit(paragraph, member.indicators || [], 'end', false);
        var only = paragraph.replace(/^\s+|\s+$/u, '');
        if (start) removals.push({ index: p, edge: 'start', indicator: start });
        if (end && !(start && only === start)) removals.push({ index: p, edge: 'end', indicator: end });
      }
      if (removals.length) hits.push({ member: member, removals: removals });
    }
    if (hits.length !== 1) return null;
    return hits[0];
  }

  function safeAvatar(url) {
    if (!url || url === 'emoji' || url.indexOf('https://') !== 0) return '';
    var host = '';
    try { host = new URL(url).hostname.toLowerCase(); } catch (e) { return ''; }
    var allowed = {
      'files.y2k.diy': 1,
      'pool.jortage.com': 1,
      'blob.jortage.com': 1,
      'us.pool.jortage.com': 1,
      'us.blob.jortage.com': 1,
      'cn.pool.jortage.com': 1,
      'cn.blob.jortage.com': 1,
      'cdn.pluralkit.me': 1,
      'cdn.plural.gg': 1,
      'scratchupload.xyz': 1,
      'scratchupload.org': 1
    };
    if (url === 'https://exa.y2k.diy/junk/noise.png') return url;
    return allowed[host] ? url : '';
  }

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { matchMembers: matchMembers, safeAvatar: safeAvatar };
  }
  if (typeof document === 'undefined') return;

  var known = new Map();
  var flight = new Set();
  var attempts = new Map();
  var timer = 0;

  function paragraphText(node) {
    var text = '';
    node.childNodes.forEach(function (child) {
      if (child.nodeType === 3) {
        text += child.nodeValue || '';
      } else if (child.nodeName === 'IMG') {
        var alt = child.getAttribute('alt') || '';
        text += alt;
      } else if (child.nodeName === 'BR') {
        text += '\n';
      } else {
        text += paragraphText(child);
      }
    });
    return text;
  }

  function paragraphsOf(body) {
    var ps = body.querySelectorAll(':scope > p');
    var nodes = ps.length ? Array.prototype.filter.call(ps, function (p) {
      return (p.textContent || '').trim() !== '' || p.querySelector('img');
    }) : [body];
    return nodes.map(paragraphText);
  }

  function bodyOf(article) {
    return article.querySelector(':scope > .feed-body, :scope > .body.feed-body')
      || article.querySelector(':scope > .cw-gate .feed-body');
  }

  function applyFont(who, font) {
    who.style.fontVariant = font === 'smallcaps' ? 'small-caps' : '';
    who.style.fontSize = font === 'small' ? '85%' : '';
    who.style.fontFamily = font === 'monospace' ? 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace' : '';
  }

  function removeToken(root, indicator, where) {
    if (!indicator || !root) return;
    var kids = Array.prototype.slice.call(root.childNodes);
    if (where === 'prefix') {
      var i = 0;
      while (i < kids.length) {
        var skip = kids[i];
        if (skip.nodeType === 3 && /^\s*$/.test(skip.nodeValue || '')) { i++; continue; }
        if (skip.nodeType === 3 && /^@[^\s@]+(?:@[^\s@]+)?\s*$/.test((skip.nodeValue || '').trim())) { i++; continue; }
        if (skip.nodeType === 1 && (skip.classList.contains('h-card') || skip.classList.contains('mention') || skip.querySelector('a.mention, a.u-url'))) { i++; continue; }
        break;
      }
      var target = kids[i];
      if (!target) return;
      if (target.nodeName === 'IMG' && (target.getAttribute('alt') || '') === indicator) {
        var next = target.nextSibling;
        target.remove();
        if (next && next.nodeType === 3) next.nodeValue = (next.nodeValue || '').replace(/^\s/, '');
        return;
      }
      if (target.nodeType === 3) {
        var value = target.nodeValue || '';
        var head = (value.match(/^\s*/) || [''])[0];
        var rest = value.slice(head.length);
        var mentioned = rest.match(/^(?:@[^\s@]+(?:@[^\s@]+)?\s+)+/);
        if (mentioned) {
          head += mentioned[0];
          rest = rest.slice(mentioned[0].length);
        }
        if (indicatorAt(rest, indicator, 0)) {
          target.nodeValue = head + rest.slice(indicator.length).replace(/^\s/, '');
        }
      }
      return;
    }
    for (var k = kids.length - 1; k >= 0; k--) {
      var end = kids[k];
      if (end.nodeType === 3 && (end.nodeValue || '').trim() === '') continue;
      if (end.nodeName === 'IMG' && (end.getAttribute('alt') || '') === indicator) {
        var prev = end.previousSibling;
        end.remove();
        if (prev && prev.nodeType === 3) prev.nodeValue = (prev.nodeValue || '').replace(/\s$/, '');
        return;
      }
      if (end.nodeType === 3) {
        var raw = end.nodeValue || '';
        var trimmed = raw.replace(/\s+$/, '');
        var at = trimmed.length - indicator.length;
        if (at >= 0 && indicatorAt(trimmed, indicator, at)) {
          end.nodeValue = trimmed.slice(0, at).replace(/\s$/, '') + raw.slice(trimmed.length);
        }
      }
      return;
    }
  }

  function apply(article, members) {
    if (!article || article.dataset.pluraldawn === 'done' || article.dataset.pluraldawn === 'skip') return;
    var body = bodyOf(article);
    var who = article.querySelector(':scope > .tweet-hd .who');
    var handle = article.querySelector(':scope > .tweet-hd .tweet-hd-main a.meta:not(.tweet-time), :scope > .tweet-hd .tweet-hd-main span.meta:not(.tweet-time)');
    var img = article.querySelector(':scope > .tweet-hd img.tweet-av');
    if (!body || !who) {
      article.dataset.pluraldawn = 'skip';
      return;
    }
    var hit = matchMembers(paragraphsOf(body), members || []);
    article.dataset.pluraldawn = 'done';
    if (!hit) return;
    var member = hit.member;
    var marker = (hit.removals[0] && hit.removals[0].indicator) || '';
    if (!who.dataset.pluraldawnBase) who.dataset.pluraldawnBase = who.textContent || '';
    who.textContent = member.name;
    applyFont(who, member.font);
    if (handle) {
      if (!handle.dataset.pluraldawnBase) handle.dataset.pluraldawnBase = handle.textContent || '';
      var base = handle.dataset.pluraldawnBase;
      if (base.indexOf('/' + member.id) === -1) {
        handle.textContent = base.replace(/@\S+/, function (acct) { return acct + '/' + member.id; });
      }
    }
    var avatar = safeAvatar(member.avatar);
    if (!avatar && member.avatar === 'emoji' && marker) {
      var emoji = null;
      body.querySelectorAll('img').forEach(function (candidate) {
        if (!emoji && (candidate.getAttribute('alt') || '') === marker && (candidate.getAttribute('src') || '').indexOf('https://') === 0) {
          emoji = candidate;
        }
      });
      if (emoji) avatar = emoji.getAttribute('src') || '';
    }
    if (img && avatar.indexOf('https://') === 0) img.src = avatar;
    var paraNodes = body.querySelectorAll(':scope > p');
    var paraList = paraNodes.length ? Array.prototype.slice.call(paraNodes) : [body];
    hit.removals.forEach(function (removal) {
      var el = paraList[removal.index] || paraList[0];
      removeToken(el, removal.indicator, removal.edge === 'start' ? 'prefix' : 'suffix');
    });
  }

  function flush() {
    var need = [];
    known.forEach(function (members, actor) {
      document.querySelectorAll('article.tweet[data-pluraldawn="wait"]').forEach(function (article) {
        if ((article.dataset.pluraldawnActor || '') === actor) apply(article, members);
      });
    });
    document.querySelectorAll('article.tweet[data-pluraldawn="wait"]').forEach(function (article) {
      var actor = article.dataset.pluraldawnActor || '';
      if (!actor || known.has(actor) || flight.has(actor)) return;
      if ((attempts.get(actor) || 0) >= 3) {
        article.dataset.pluraldawn = 'skip';
        return;
      }
      if (need.indexOf(actor) === -1) need.push(actor);
    });
    if (!need.length) return;
    need.slice(0, 24).forEach(function (actor) { flight.add(actor); attempts.set(actor, (attempts.get(actor) || 0) + 1); });
    fetch('/api/ap-pluraldawn.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ actors: need.slice(0, 24) })
    }).then(function (res) {
      if (!res.ok) throw new Error('status');
      return res.json();
    }).then(function (data) {
      var systems = data && data.systems || {};
      Object.keys(systems).forEach(function (actor) {
        known.set(actor, Array.isArray(systems[actor]) ? systems[actor] : []);
        flight.delete(actor);
      });
      (data && data.pending || []).forEach(function (actor) { flight.delete(actor); });
      need.slice(0, 24).forEach(function (actor) { flight.delete(actor); });
      schedule();
    }).catch(function () {
      need.slice(0, 24).forEach(function (actor) { flight.delete(actor); });
    });
  }

  function scan() {
    document.querySelectorAll('article.tweet').forEach(function (article) {
      if (article.dataset.pluraldawn) return;
      var img = article.querySelector(':scope > .tweet-hd img.tweet-av');
      var actor = img ? (img.getAttribute('data-profile-hover-actor') || '') : '';
      if (actor.indexOf('https://') !== 0) {
        article.dataset.pluraldawn = 'skip';
        return;
      }
      article.dataset.pluraldawn = 'wait';
      article.dataset.pluraldawnActor = actor.replace(/\/$/, '');
      if (known.has(article.dataset.pluraldawnActor)) apply(article, known.get(article.dataset.pluraldawnActor));
    });
    flush();
  }

  function schedule() {
    if (timer) return;
    timer = window.setTimeout(function () {
      timer = 0;
      try { scan(); } catch (e) {}
    }, 180);
  }

  var observer = new MutationObserver(function () { schedule(); });
  observer.observe(document.documentElement, { childList: true, subtree: true });
  schedule();
})();
