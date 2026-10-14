//! Canonical Library card port, developed against the actual PHP painter.
//! This is not the lean timeline renderer. Missing branches return errors so
//! they cannot silently discard a feature during differential development.

// Immutable compiled patterns only; request text, source facts and results
// remain uncached. Each callsite retains exactly its original regex literal.
macro_rules! static_regex { ($pattern:literal) => {{
 static REGEX: std::sync::OnceLock<regex::Regex> = std::sync::OnceLock::new();
 REGEX.get_or_init(||regex::Regex::new($pattern).unwrap())
 }}; }

use anyhow::{bail, Result};
use serde_json::Value;
use super::actions::{esc, query, Counts, FediActionContext, ModerationContext};

/// Internal facts resolved from server-owned profile/interaction/poll caches.
/// No Serialize/Deserialize implementation: never a browser permission payload.
#[derive(Clone)]
pub(crate) struct CardFacts {
    pub bsky: Option<super::bsky::BskyFacts>,
    pub owner_id: i64,
    pub owner_actor: String,
    pub return_view: String,
    pub auto_unblur_sensitive: bool,
    pub resolved_avatar: String,
    pub csrf: String,
    pub favourited: bool,
    pub bookmarked: bool,
    pub boosted: bool,
    pub own: Option<super::own::OwnFacts>,
    pub current_action_counts: Option<Counts>,
    pub rss_row: Option<Value>,
    pub rss_quote_row: Option<Value>,
    pub rss_quoted: bool,
    pub emoji_by_actor: std::collections::HashMap<String,std::collections::HashMap<String,String>>,
    pub reply_mentions: Vec<String>,
    pub moderation: ModerationContext,
    pub poll_row: Option<Value>,
    pub ask_row: Option<Value>,
    pub now: chrono::DateTime<chrono::Utc>,
    pub lazy_media: bool,
}

fn string<'a>(v: &'a Value, key: &str) -> &'a str { v.get(key).and_then(Value::as_str).unwrap_or("") }
pub(crate) fn scalar_string(v: &Value, key: &str) -> String {
    match v.get(key).unwrap_or(&Value::Null) {
        Value::String(value) => value.clone(), Value::Number(value) => value.to_string(),
        Value::Bool(value) => if *value { "1".into() } else { String::new() }, _ => String::new(),
    }
}
fn action_counts(status:&Value,facts:&CardFacts)->Counts {
    let mut counts=Counts { favourites:integer(status,"favourites_count").max(0),reblogs:integer(status,"reblogs_count").max(0),quotes:integer(status,"quotes_count").max(0),bookmarks:integer(status,"bookmarks_count").max(0) };
    if let Some(post)=status.get("bsky_post_view").or_else(||status.get("bsky_post")) {
        counts.favourites=counts.favourites.max(integer(post,"likeCount"));
        counts.reblogs=counts.reblogs.max(integer(post,"repostCount"));
        counts.quotes=counts.quotes.max(integer(post,"quoteCount"));
    }
    if let Some(current)=facts.current_action_counts.as_ref(){
        counts.favourites=counts.favourites.max(current.favourites);
        counts.reblogs=counts.reblogs.max(current.reblogs);
        counts.quotes=counts.quotes.max(current.quotes);
        counts.bookmarks=counts.bookmarks.max(current.bookmarks);
    }
    counts
}

pub(crate) fn integer(v: &Value, key: &str) -> i64 {
    match v.get(key).unwrap_or(&Value::Null) {
        Value::Bool(value) => i64::from(*value),
        Value::Number(value) => value.as_i64().unwrap_or_else(|| value.as_f64().unwrap_or(0.0) as i64),
        Value::String(value) => static_regex!(r"^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?")
            .find(value.trim_start()).and_then(|number| number.as_str().parse::<f64>().ok()).unwrap_or(0.0) as i64,
        Value::Array(value) => i64::from(!value.is_empty()),
        Value::Object(value) => i64::from(!value.is_empty()),
        Value::Null => 0,
    }
}
pub(crate) fn boolean(v: &Value, key: &str) -> bool {
    match v.get(key).unwrap_or(&Value::Null) {
        Value::Bool(value) => *value,
        Value::Number(value) => value.as_f64().unwrap_or(0.0) != 0.0,
        Value::String(value) => !value.is_empty() && value != "0",
        Value::Array(value) => !value.is_empty(),
        Value::Object(value) => !value.is_empty(),
        Value::Null => false,
    }
}
/// Exact PHP trim character set for canonical CW predicates.
pub(crate) fn php_trim(text:&str)->&str { text.trim_matches([' ', '\t', '\n', '\r', '\0', '\u{b}']) }
fn plain(content: &str) -> String {
    super::body::html_to_plain(content)
}
fn body_text(plain: &str, view: &str, mentions: &[Value], owner_actor:&str) -> Result<String> {
    let repaired=super::body::repair_known_text(plain,owner_actor);
    let plain=repaired.as_str();
    if plain.contains('@') { super::body::linked_text_mentions(plain,view,mentions,owner_actor) }
    else { super::body::linked_text(plain, view) }
}

pub(crate) fn linked_body_html(text:&str,status:&Value,facts:&CardFacts)->Result<String>{
    let actor=account_actor_ref(&status["account"]);
    let emoji_map=facts.emoji_by_actor.get(&actor).cloned().unwrap_or_default();
    linked_body_presentation(text,status,&facts.return_view,&facts.owner_actor,&emoji_map)
}

pub(crate) fn linked_body_presentation(text:&str,status:&Value,view:&str,owner_actor:&str,emoji_map:&std::collections::HashMap<String,String>)->Result<String>{
    Ok(super::body::facets(&super::body::emojis(&body_text(text,view,status.get("mentions").and_then(Value::as_array).map(Vec::as_slice).unwrap_or(&[]),owner_actor)?,emoji_map,false),status))
}
pub(crate) fn account_actor_ref(account:&Value)->String {
    let uri=string(account,"uri").trim_end_matches('/');let url=string(account,"url").trim_end_matches('/');
    if uri.starts_with("https://")&&!static_regex!(r"/@[^/]+$").is_match(uri){return uri.into();}
    if url.starts_with("https://") {
        if let Some(captures)=static_regex!(r"^(https://[^/]+)/@([^/]+)$").captures(url){
            let raw=captures[2].as_bytes();let mut bytes=Vec::new();let mut index=0;
            while index<raw.len(){if raw[index]==b'%'&&index+2<raw.len(){if let Some(byte)=std::str::from_utf8(&raw[index+1..index+3]).ok().and_then(|hex|u8::from_str_radix(hex,16).ok()){bytes.push(byte);index+=3;continue;}}bytes.push(raw[index]);index+=1;}
            let mut name=String::new();for byte in bytes{if byte.is_ascii_alphanumeric()||b"-._~".contains(&byte){name.push(byte as char);}else{name.push_str(&format!("%{byte:02X}"));}}
            return format!("{}/users/{name}",&captures[1]);
        }
        return url.into();
    }
    if !uri.is_empty(){uri.into()}else{url.into()}
}

pub(crate) fn profile_href(actor: &str, view: &str) -> String {
    if let Some(key) = actor.strip_prefix("https://mkultra.monster/users/").filter(|key| !key.contains('/')) {
        if view.is_empty()||view=="home"{format!("/vaak/users/{}",query(key))}else{format!("/vaak/users/{}?from={}", query(key), query(view))}
    } else { format!("/vaak/?view=remote_profile&actor={}&from={}", query(actor), query(view)) }
}
// Ask labels use admin_profile_app_href(actor, "home"). Keep its strict
// local-key predicate and PHP http_build_query (RFC 1738) remote encoding
// separate from the other painters' existing RFC 3986 links.
fn ask_profile_href(actor: &str) -> String {
    let actor = php_trim(actor).trim_end_matches('/');
    if let Some(key) = actor.strip_prefix("https://mkultra.monster/users/")
        .filter(|key| !key.is_empty() && key.bytes().all(|byte| byte.is_ascii_alphanumeric() || byte == b'_')) {
        profile_href(&format!("https://mkultra.monster/users/{}", key.to_ascii_lowercase()), "home")
    } else {
        let actor = query(actor).replace("%20", "+").replace('~', "%7E");
        format!("/vaak/?view=remote_profile&actor={actor}&from=home")
    }
}
fn status_href(uri: &str, view: &str) -> String {
    let translated=uri.strip_prefix("at://").and_then(|rest| rest.split_once("/app.bsky.feed.post/"))
        .map(|(did,key)|format!("https://bsky.app/profile/{did}/post/{key}"));
    format!("?view=status&object={}&from={}", query(translated.as_deref().unwrap_or(uri)), query(view))
}

/// Cache-independent branches of the production ap_remote_object_web_url.
/// Ordinary AS2 pretty URLs do not override canonical object IDs. Numeric
/// Threads actors require current cached profile facts and remain PHP-owned.
fn remote_object_web_url(object: &str, hint: Option<&Value>) -> Result<String> {
    let object = php_trim(object);
    let mut candidates = Vec::new();
    match hint {
        Some(Value::String(value)) if value.starts_with("https://") => candidates.push(value.as_str()),
        Some(Value::Array(values)) => for value in values {
            if let Some(value) = value.as_str().or_else(|| value.get("href").and_then(Value::as_str)).filter(|value| value.starts_with("https://")) { candidates.push(value); }
        },
        _ => {}
    }
    if object.starts_with("https://") && !object.is_empty() { candidates.push(object); }
    let redirect = static_regex!(r"(?i)^https://bsky\.brid\.gy/r/(https://bsky\.app/.+)$");
    let embedded = static_regex!(r"(?i)at://(did:[^/]+)/app\.bsky\.feed\.post/([A-Za-z0-9]+)");
    let threads = static_regex!(r"(?i)^https://(?:www\.)?threads\.(?:net|com)/@([^/?#]+)");
    let numeric_threads = static_regex!(r"(?i)^https://(?:www\.)?threads\.net/ap/users/(\d+)");
    for candidate in candidates {
        if let Some(capture) = redirect.captures(candidate) { return Ok(capture[1].to_owned()); }
        if candidate.starts_with("https://bsky.app/") { return Ok(candidate.to_owned()); }
        if let Some(capture) = embedded.captures(candidate) { return Ok(format!("https://bsky.app/profile/{}/post/{}", &capture[1], query(&capture[2]))); }
        if let Some(capture) = threads.captures(candidate) {
            // PHP rawurldecode/rawurlencode preserve arbitrary escaped bytes.
            // A lossy UTF-8 conversion would change malformed remote handles.
            let input = capture[1].as_bytes(); let mut decoded = Vec::new(); let mut offset = 0;
            while offset < input.len() {
                if input[offset] == b'%' && offset + 2 < input.len() {
                    if let Ok(hex) = std::str::from_utf8(&input[offset + 1..offset + 3]) {
                        if let Ok(byte) = u8::from_str_radix(hex,16) { decoded.push(byte); offset += 3; continue; }
                    }
                }
                decoded.push(input[offset]); offset += 1;
            }
            let encoded = decoded.iter().map(|byte| if byte.is_ascii_alphanumeric() || b"-_.~".contains(byte) { (*byte as char).to_string() } else { format!("%{byte:02X}") }).collect::<String>();
            return Ok(format!("https://www.threads.com/@{encoded}"));
        }
        if numeric_threads.is_match(candidate) { bail!("Threads object web URL requires current cached profile facts"); }
    }
    Ok(object.to_owned())
}

pub(crate) fn remote_object_href(object: &str, hint: Option<&Value>) -> Result<String> {
    let mapped = remote_object_web_url(object, hint)?;
    if !mapped.is_empty() { return Ok(mapped); }
    // admin_remote_object_href's fallback executes only when its genuine
    // helper returned empty, and accepts a plain HTTPS string hint.
    if let Some(value) = hint.and_then(Value::as_str).filter(|value| value.starts_with("https://")) {
        let host = reqwest::Url::parse(value).ok().and_then(|url| url.host_str().map(str::to_owned)).unwrap_or_default().to_ascii_lowercase();
        if host != "brid.gy" && !host.ends_with(".brid.gy") { return Ok(value.to_owned()); }
    }
    Ok(String::new())
}
pub(crate) fn relative_time(iso: &str, now: chrono::DateTime<chrono::Utc>) -> String {
    if iso.is_empty() { return String::new(); }
    let timestamp = chrono::DateTime::parse_from_rfc3339(iso).map(|date| date.timestamp())
        .or_else(|_| chrono::DateTime::parse_from_rfc2822(iso).map(|date| date.timestamp()))
        .or_else(|_| chrono::NaiveDateTime::parse_from_str(iso, "%Y-%m-%d %H:%M:%S").map(|date| date.and_utc().timestamp()));
    let Ok(timestamp) = timestamp else { return iso.to_owned(); };
    let seconds = now.timestamp() - timestamp;
    if seconds < 60 { format!("{seconds}s") } else if seconds < 3600 { format!("{}m", seconds / 60) } else if seconds < 86400 { format!("{}h", seconds / 3600) } else { format!("{}d", seconds / 86400) }
}

pub(crate) fn cw_gate(spoiler: &str, sensitive: bool, inner: &str, auto_unblur: bool) -> String {
    let spoiler = php_trim(spoiler);
    let mut inner = php_trim(inner).to_owned();
    if inner.is_empty() {
        if !sensitive && spoiler.is_empty() { return String::new(); }
        inner = "<div class=\"meta\">(sensitive media — open post on remote if nothing appears)</div>".into();
    }
    let wrapped = format!("<div class=\"tweet-content\">{inner}</div>");
    if (!sensitive || auto_unblur) && spoiler.is_empty() { return wrapped; }
    let label = if spoiler.is_empty() { "Sensitive content" } else { spoiler };
    format!("<details class=\"cw-gate\"><summary><span class=\"cw-label\">CW</span><span class=\"cw-text\">{}</span><span class=\"cw-hint\"></span></summary><div class=\"cw-body\">{wrapped}</div></details>", esc(label))
}

fn media_kind(url: &str, mime: &str, kind: &str) -> bool {
    let path = reqwest::Url::parse(url).ok().map(|u| u.path().to_owned()).unwrap_or_default();
    let extension = path.rsplit('.').next().unwrap_or("").to_ascii_lowercase();
    if kind == "video" { mime.starts_with("video/") || matches!(mime, "application/x-mpegurl" | "application/vnd.apple.mpegurl") || matches!(extension.as_str(), "mp4" | "webm" | "mov" | "m4v" | "m3u8") }
    else { mime.starts_with("audio/") || matches!(extension.as_str(), "mp3" | "m4a" | "aac" | "ogg" | "oga" | "wav" | "flac" | "webm") }
}
fn image_poster(url: &str) -> bool {
    if !url.starts_with("https://") || media_kind(url, "", "video") || media_kind(url, "", "audio") { return false; }
    let path = reqwest::Url::parse(url).ok().map(|u| u.path().to_owned()).unwrap_or_default();
    matches!(path.rsplit('.').next().unwrap_or("").to_ascii_lowercase().as_str(), "png" | "jpg" | "jpeg" | "gif" | "webp" | "avif") || url.contains("cdn.bsky.app/") || url.contains("/img/") || url.contains("thumbnail")
}

pub(crate) fn media_row(media: &[Value], lazy: bool) -> String {media_row_mode(media,lazy,false)}
fn media_row_mode(media: &[Value], lazy: bool, silent:bool) -> String {
    let mut cells = Vec::new();
    for att in media {
        let url = att.get("url").or_else(|| att.get("preview_url")).and_then(Value::as_str).unwrap_or("");
        if !url.starts_with("https://") { continue; }
        let preview = att.get("preview_url").or_else(|| att.get("poster")).or_else(|| att.get("thumbnail")).and_then(Value::as_str).unwrap_or("");
        let mime = string(att, "mediaType").to_ascii_lowercase();
        let kind = string(att, "type").to_ascii_lowercase();
        let mime = if mime.is_empty() && kind == "audio" { "audio/*".into() } else { mime };
        if media_kind(url, &mime, "video") {
            let mut poster = if image_poster(preview) { preview.to_owned() } else { String::new() };
            if poster.is_empty() {
                let re = static_regex!(r"(?i)^(https://.+)/original/([^/?#]+)\.(mp4|m4v|mov|webm)([?#].*)?$");
                if let Some(c) = re.captures(url) { poster = format!("{}/small/{}.png", &c[1], &c[2]); }
            }
            let hls = url.split(['?', '#']).next().unwrap_or("").to_ascii_lowercase().ends_with(".m3u8") || matches!(mime.as_str(), "application/x-mpegurl" | "application/vnd.apple.mpegurl");
            let source = if hls { format!(" data-hls-src=\"{}\"{}", esc(url), if lazy { " preload=\"none\"" } else { "" }) } else if lazy { format!(" data-src=\"{}\" preload=\"none\"", esc(url)) } else { format!(" src=\"{}\"", esc(url)) };
            cells.push(format!("<video class=\"media-video\" data-media-warm-url=\"{}\"{source} controls loop playsinline{}{}{} referrerpolicy=\"no-referrer\"></video>", esc(if poster.is_empty() { url } else { &poster }), if lazy { "" } else { " preload=\"metadata\"" }, if silent { " muted" } else { "" }, if poster.is_empty() { String::new() } else { format!(" poster=\"{}\"", esc(&poster)) }));
        } else if media_kind(url, &mime, "audio") {
            cells.push(format!("<div class=\"media-audio-card\" role=\"group\" aria-label=\"Audio post\"><img class=\"media-audio-art\" src=\"/api/assets/audio-post-default.jpg\" alt=\"\" loading=\"lazy\" decoding=\"async\"><audio class=\"media-audio\" src=\"{}\" controls preload=\"metadata\"></audio></div>", esc(url)));
        } else {
            cells.push(format!("<button type=\"button\" class=\"media-lightbox-trigger\" data-full=\"{}\" title=\"View image\"><img src=\"{}\" data-media-warm-url=\"{}\" alt=\"\" loading=\"lazy\" referrerpolicy=\"no-referrer\" decoding=\"async\"></button>", esc(url), esc(if lazy && image_poster(preview) { preview } else { url }), esc(if preview.is_empty() { url } else { preview })));
        }
        if cells.len() == 4 { break; }
    }
    if cells.is_empty() { String::new() } else { format!("<div class=\"media-row media-count-{}\">{}</div>", cells.len(), cells.join("")) }
}

/// Presentation-only status aliases; moderation identity remains case-sensitive.
fn status_candidates(value:&str)->std::collections::HashSet<String>{
    let value=value.trim().trim_end_matches('/');let mut candidates=vec![value.to_owned()];
    if let Some(parent)=value.strip_suffix("/activity"){candidates.push(parent.trim_end_matches('/').to_owned());}
    if let Some(cap)=static_regex!(r"^(https://[^/]+)/@([^/]+)/([A-Za-z0-9_-]+)$").captures(value){
        let user=query(&cap[2]);for prefix in ["users","ap/users"]{for kind in ["statuses","posts","notes"]{candidates.push(format!("{}/{}/{}/{}/{}",&cap[1],prefix,user,kind,&cap[3]));}}
        candidates.push(format!("{}/notes/{}",&cap[1],&cap[3]));
    }
    if let Some(cap)=static_regex!(r"^(https://[^/]+)/(?:ap/)?users/([^/]+)/(?:statuses|posts|notes)/([A-Za-z0-9_-]+)$").captures(value){
        let raw=cap[2].as_bytes();let mut bytes=Vec::new();let mut i=0;
        while i<raw.len(){if raw[i]==b'%'&&i+2<raw.len(){if let Ok(n)=u8::from_str_radix(std::str::from_utf8(&raw[i+1..i+3]).unwrap_or(""),16){bytes.push(n);i+=3;continue;}}bytes.push(raw[i]);i+=1;}
        candidates.push(format!("{}/@{}/{}",&cap[1],String::from_utf8_lossy(&bytes),&cap[3]));
        for prefix in ["users","ap/users"]{for kind in ["statuses","posts","notes"]{candidates.push(format!("{}/{}/{}/{}/{}",&cap[1],prefix,&cap[2],kind,&cap[3]));}}
        candidates.push(format!("{}/notes/{}",&cap[1],&cap[3]));
    }
    if let Some(cap)=static_regex!(r"(?i)^(https://[^\s]+/statuses/[^/]+)/QuotePost/?$").captures(value){candidates.push(cap[1].to_owned());}
    candidates.into_iter().filter(|v|v.starts_with("https://")).map(|v|v.trim_end_matches('/').to_lowercase()).collect()
}
pub(crate) fn status_urls_equivalent(a:&str,b:&str)->bool {
    let a=a.trim().trim_end_matches('/');let b=b.trim().trim_end_matches('/');
    if !a.starts_with("https://")||!b.starts_with("https://"){return false;}
    if a.eq_ignore_ascii_case(b){return true;}
    if let (Ok(a_url),Ok(b_url))=(reqwest::Url::parse(a),reqwest::Url::parse(b)){
        if a_url.host_str()==b_url.host_str(){let id=static_regex!(r"/@[^/]+/([0-9]+)$|/(?:statuses|posts|notes)/([0-9]+)$");
            let extract=|value:&str|id.captures(value).and_then(|cap|cap.get(1).or_else(||cap.get(2)).map(|m|m.as_str().to_owned()));
            if let (Some(a),Some(b))=(extract(a),extract(b)){if a==b{return true;}}
        }
    }
    !status_candidates(a).is_disjoint(&status_candidates(b))
}

fn quote_card(status: &Value, facts: &CardFacts) -> Result<String> {quote_card_mode(status,facts,false)}
fn quote_card_mode(status: &Value, facts: &CardFacts,silent:bool) -> Result<String> {
    if let Some(row)=facts.rss_quote_row.as_ref() {
        // Source adapter binds this row to the current quoted mirror and owner.
        super::rss::normalize(row,facts.owner_id)?;
        let title=php_trim(string(row,"title"));let summary=php_trim(string(row,"summary_text"));
        let mut text=title.to_owned();if !summary.is_empty(){if !text.is_empty(){text.push_str("\n\n");}text.push_str(&super::rss::trim_width(summary,if title.is_empty(){280}else{240}));}
        let feed=super::rss::clean_feed_title(string(row,"feed_title"));let article=php_trim(string(row,"url"));
        let image=php_trim(string(row,"image_url"));let image=if image.to_ascii_lowercase().starts_with("https://"){image}else{""};
        let mut html=format!("<div class=\"quote-block\"><span class=\"qt-label\">Quoted</span><div class=\"meta\" style=\"margin-top:.3rem\">{}</div>",esc(&feed));
        if !text.is_empty(){html.push_str(&format!("<div style=\"margin-top:.25rem;white-space:pre-wrap\">{}</div>",body_text(&text,&facts.return_view,&[],&facts.owner_actor)?));}
        if !article.is_empty(){let preview=link_preview_mode(&serde_json::json!({"status":"ok","url":article,"title":string(row,"title"),"description":summary,"image":if image.is_empty(){Value::Null}else{Value::String(image.into())},"provider_name":feed}),false)?;
            if !preview.is_empty(){html.push_str(&format!("<div class=\"quote-link-card\" data-quote-chrome=\"1\" style=\"margin-top:.45rem;pointer-events:none\">{preview}</div>"));}}
        if article.starts_with("https://"){html.push_str(&format!("<div class=\"meta\" style=\"margin-top:.35rem\"><a href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer\">Open article</a></div>",esc(article)));} else {let note=string(status,"url");if note.starts_with("https://"){html.push_str(&format!("<div class=\"meta\" style=\"margin-top:.35rem\"><a href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer\">Open original</a></div>",esc(note)));}}
        html.push_str("</div>");return Ok(html);
    }
    let uri = status.get("uri").or_else(|| status.get("url")).and_then(Value::as_str).unwrap_or("");
    let acct = status.get("account").map(|account| string(account, "acct")).unwrap_or("");
    let view = &facts.return_view;
    let media = status.get("media_attachments").and_then(Value::as_array).cloned().unwrap_or_default();
    let text = super::body::scrub_as2(&plain(string(status, "content")));
    // Supplied quotes keep the openable in-app chrome. Cold, deleted, and
    // Bluesky previews set an open label or external flag and follow the
    // shared PHP footer (in-app label, then "Open original" when external).
    let href = if uri.is_empty() || uri == "https://bsky.app/" { String::new() } else { status_href(uri, view) };
    let openable = !href.is_empty() && href != "#";
    let mut html = if openable {
        format!("<div class=\"quote-block quote-block--openable\" data-href=\"{}\" role=\"link\" tabindex=\"0\" aria-label=\"Open quoted post\"><span class=\"qt-label\">Quoted</span>", esc(&href))
    } else {
        "<div class=\"quote-block\"><span class=\"qt-label\">Quoted</span>".to_owned()
    };
    if !acct.is_empty() { html.push_str(&format!("<div class=\"meta\" style=\"margin-top:.3rem\">{}</div>", esc(&format!("@{}", acct.trim_start_matches('@'))))); }
    if !text.is_empty() { html.push_str(&format!("<div style=\"margin-top:.25rem;white-space:pre-wrap\">{}</div>", body_text(&text, view, status.get("mentions").and_then(Value::as_array).map(Vec::as_slice).unwrap_or(&[]), &facts.owner_actor)?)); }
    if !media.is_empty() {
        let normalized = media.iter().filter(|item|item.is_object()).map(|item| {
            let mut item = item.clone();
            if item.get("url").is_some_and(Value::is_object) { item["url"]=item["url"].get("href").or_else(||item["url"].get("url")).cloned().unwrap_or(Value::Null); }
            if string(&item,"preview_url").is_empty() { if let Some(preview)=item.get("preview").or_else(||item.get("thumbnail")).and_then(Value::as_str).filter(|preview|preview.starts_with("https://")) { item["preview_url"]=Value::String(preview.into()); } }
            if string(&item,"mediaType").is_empty() {
                let mime = match string(&item,"type").to_ascii_lowercase().as_str() { "video"|"gifv"=>"video/mp4", "audio"=>"audio/*", "image"=>"image/jpeg", _=>"" };
                item["mediaType"] = Value::String(mime.into());
            }
            item
        }).collect::<Vec<_>>();
        let row = media_row_mode(&normalized, facts.lazy_media,silent);
        if !row.is_empty() {
            let row = format!("<div class=\"quote-media\" style=\"margin-top:.45rem\">{row}</div>");
            if boolean(status,"sensitive") || !php_trim(string(status,"spoiler_text")).is_empty() {
                html.push_str(&cw_gate(if php_trim(string(status,"spoiler_text")).is_empty() { "Sensitive content" } else { string(status,"spoiler_text") }, true, &row, facts.auto_unblur_sensitive));
            } else { html.push_str(&row); }
        }
    } else if let Some(card) = status.get("card").filter(|card| card.is_object()) {
        let mut card=card.clone();card["status"]=Value::String("ok".into());
        let preview = link_preview_mode(&card, false)?;
        if !preview.is_empty() { html.push_str(&format!("<div class=\"quote-link-card\" data-quote-chrome=\"1\" style=\"margin-top:.45rem;pointer-events:none\">{preview}</div>")); }
    }
    let open_label = status.get("vaak_quote_open_label").and_then(Value::as_str).map(php_trim).filter(|label| !label.is_empty());
    let open_external = status.get("vaak_quote_open_external").and_then(Value::as_bool).unwrap_or(false);
    if !uri.is_empty() && uri != "https://bsky.app/" {
        let label = open_label.unwrap_or_else(|| "Open quoted".into());
        if !open_external && label == "Open quoted" && openable {
            html.push_str(&format!("<div class=\"meta\" style=\"margin-top:.35rem\"><a href=\"{}\">Open quoted</a></div>", esc(&href)));
        } else {
            html.push_str("<div class=\"meta\" style=\"margin-top:.35rem\">");
            if openable { html.push_str(&format!("<a href=\"{}\">{}</a>", esc(&href), esc(&label))); }
            if open_external && uri.starts_with("https://") {
                if openable { html.push_str(" · "); }
                html.push_str(&format!("<a href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer\">Open original</a>", esc(uri)));
            } else if !openable {
                html.push_str(&format!("<a href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer\">{}</a>", esc(uri), esc(&label)));
            }
            html.push_str("</div>");
        }
    } else if text.is_empty() && acct.is_empty() && media.is_empty() && !status.get("card").is_some_and(Value::is_object) {
        html.push_str("<div class=\"meta\" style=\"margin-top:.3rem\">Quoted post unavailable</div>");
    }
    html.push_str("</div>"); Ok(html)
}

fn poll_options(row:&Value)->Vec<(String,i64)>{
    let options: Value = serde_json::from_str(string(row, "options_json")).unwrap_or(Value::Null);
    options.as_array().into_iter().flatten().filter_map(|opt| {
        let title = opt.get("title").or_else(|| opt.get("name")).and_then(Value::as_str).unwrap_or("").trim();
        if title.is_empty() { None } else { Some((title.into(), integer(opt, "votes_count"))) }
    }).collect()
}
pub(crate) fn poll_has_options(row:&Value)->bool{!poll_options(row).is_empty()}
pub(crate) fn poll_html(row: &Value, note: &str, facts: &CardFacts) -> String {
    poll_html_mode(row,note,facts,false)
}
pub(crate) fn poll_html_mode(row: &Value, note: &str, facts: &CardFacts, read_only:bool) -> String {
    let opts=poll_options(row);
    if opts.is_empty() { return String::new(); }
    let local_id = integer(row, "local_id");
    let expires = chrono::DateTime::parse_from_rfc3339(string(row, "expires_at")).ok();
    let expired = expires.is_some_and(|time| time <= facts.now);
    let voters: Value = serde_json::from_str(string(row, "voters_json")).unwrap_or(Value::Null);
    let voted = !facts.owner_actor.trim_end_matches('/').is_empty() && voters.as_array().into_iter().flatten().filter_map(Value::as_str).any(|v| v.trim_end_matches('/') == facts.owner_actor.trim_end_matches('/'));
    let results = read_only || expired || voted || local_id <= 0;
    let mut votes = integer(row, "votes_count");
    if votes <= 0 { votes = opts.iter().map(|(_, votes)| votes).sum(); }
    let label = if expired { "Poll · closed" } else if voted { "Poll · voted" } else { "Poll" };
    let mut html = format!("<div class=\"poll-block\" data-note-id=\"{}\"{}><span class=\"poll-label\">{label}</span>", esc(note), if local_id > 0 { format!(" data-poll-id=\"{local_id}\"") } else { String::new() });
    if !results {
        let poll_action=if facts.return_view=="status"{format!("?view=status&object={}",query(note))}else{format!("?view={}",query(&facts.return_view))};
        html.push_str(&format!("<form method=\"post\" action=\"{}\" class=\"poll-vote-form\"><input type=\"hidden\" name=\"csrf\" value=\"{}\"><input type=\"hidden\" name=\"action\" value=\"poll_vote\"><input type=\"hidden\" name=\"poll_id\" value=\"{local_id}\"><input type=\"hidden\" name=\"note_id\" value=\"{}\"><input type=\"hidden\" name=\"return_view\" value=\"{}\">", esc(&poll_action), esc(&facts.csrf), esc(note), esc(&facts.return_view)));
        for (i, (title, _)) in opts.iter().enumerate() {
            html.push_str(&format!("<label class=\"poll-opt poll-opt--vote\"><input type=\"{}\" name=\"choices[]\" value=\"{i}\"><span>{}</span></label>", if boolean(row, "multiple") { "checkbox" } else { "radio" }, esc(title)));
        }
        html.push_str("<button class=\"btn btn-ghost\" type=\"submit\" style=\"margin-top:.4rem;padding:.3rem .7rem;font-size:.82rem\">Vote</button></form>");
    } else {
        for (title, count) in &opts {
            let pct = if votes > 0 { (*count as f64 / votes as f64 * 100.0).round() as i64 } else { 0 };
            html.push_str(&format!("<div class=\"poll-opt poll-opt--result\"><div class=\"poll-opt-bar\" style=\"width:{pct}%\"></div><span class=\"poll-opt-text\">{}</span><span class=\"poll-opt-count\">{pct}% · {count}</span></div>", esc(title)));
        }
    }
    let mut meta = Vec::new();
    if results {
        meta.push(format!("{votes} vote{}", if votes == 1 { "" } else { "s" }));
        let voters = integer(row, "voters_count");
        if voters > 0 { meta.push(format!("{voters} voter{}", if voters == 1 { "" } else { "s" })); }
    }
    if let Some(time) = expires { meta.push(format!("{} {} UTC", if expired { "ended" } else { "ends" }, time.with_timezone(&chrono::Utc).format("%b %-d, %Y · %H:%M"))); }
    if !meta.is_empty() { html.push_str(&format!("<div class=\"poll-meta\">{}</div>", esc(&meta.join(" · ")))); }
    html.push_str("</div>"); html
}

fn numeric_reply_without_url(status:&Value)->bool {
    status.get("in_reply_to_id").and_then(Value::as_str).is_some_and(|id|!id.is_empty()&&id.bytes().all(|byte|byte.is_ascii_digit()))
        && ["vaak_in_reply_to_url","in_reply_to"].iter().all(|key|status.get(*key).is_none_or(|value|value.is_null()||value.as_str()==Some("")))
}
fn reply_meta(status: &Value, view: &str) -> Result<String> {
    if status.get("vaak_ask").is_some_and(Value::is_object) { return Ok(String::new()); }
    if let Some(parent)=status.get("vaak_reply_parent").filter(|v|v.is_object()) {
        let handle=string(parent,"handle").trim();
        let display=string(parent,"display").trim();
        let label=if !handle.is_empty()&&!handle.starts_with("did:"){format!("@{}",handle.trim_start_matches('@'))}else if !matches!(display,"parent post"|"Bluesky user"|"Unavailable"){display.to_owned()}else{String::new()};
        let text=string(parent,"text").trim().chars().take(120).collect::<String>();
        let url=string(parent,"url").trim();
        let uri=string(parent,"uri").trim();
        let href=if !url.is_empty()&&url!="https://bsky.app/"{status_href(url,view)}else if uri.starts_with("at://"){status_href(uri,view)}else{String::new()};
        let mut html="<div class=\"meta\" style=\"margin:.35rem 0 .5rem\">↩ reply to ".to_owned();
        if !label.is_empty(){html.push_str(&format!("<span>{}</span> ",esc(&label)));}
        let link_text=if text.is_empty(){"parent post"}else{&text};
        if !href.is_empty()&&href!="#"{html.push_str(&format!("<a href=\"{}\">{}</a>",esc(&href),esc(link_text)));}else{html.push_str(&esc(link_text));}
        html.push_str("</div>");return Ok(html);
    }
    if numeric_reply_without_url(status){return Ok(String::new())}
    if ["vaak_in_reply_to_url","in_reply_to","in_reply_to_id"].iter().any(|key|!string(status,key).is_empty()) { bail!("reply URL metadata requires current event/profile cache facts"); }
    Ok(String::new())
}

pub(crate) fn ask_html(row: &Value) -> Result<String> {
    let actor = row.get("asker_actor").or_else(||row.get("ask_actor")).and_then(Value::as_str).unwrap_or("");
    let actor = php_trim(actor).trim_end_matches('/');
    if !actor.is_empty() && row.get("asker_display").is_none() { bail!("Ask requires server-resolved canonical actor identity"); }
    let question=row.get("question").or_else(||row.get("ask_question")).and_then(Value::as_str).unwrap_or("");
    let question=php_trim(question);
    if question.is_empty() { return Ok(String::new()); }
    let handle=php_trim(string(row,"asker_handle"));
    let display=if !php_trim(string(row,"asker_display")).is_empty(){php_trim(string(row,"asker_display"))} else if !php_trim(string(row,"asker_label")).is_empty(){php_trim(string(row,"asker_label"))} else if !handle.is_empty(){handle}else{"Someone"};
    let mut label=esc(display);
    if !handle.is_empty() && !display.eq_ignore_ascii_case(handle){label.push_str(&format!(" <span class=\"ask-handle\" style=\"font-weight:500;opacity:.85\">{}</span>",esc(handle)));}
    label.push_str(" asked");
    let label=if actor.starts_with("https://"){format!("<a class=\"ask-label\" href=\"{}\" style=\"display:inline-block;font-weight:700;margin-bottom:.4rem;color:var(--primary, #ff70c7);text-decoration:none\">{label}</a>",esc(&ask_profile_href(actor)))}else{format!("<div class=\"ask-label\" style=\"font-weight:700;margin-bottom:.4rem;color:var(--primary, #ff70c7)\">{label}</div>")};
    let avatar=string(row,"asker_avatar");
    let avatar=if avatar.starts_with("https://") && (!row.get("show_avatar").is_some()||boolean(row,"show_avatar")){format!("<img class=\"ask-avatar\" src=\"{}\" alt=\"\" width=\"32\" height=\"32\" loading=\"lazy\" referrerpolicy=\"no-referrer\" style=\"border-radius:50%;object-fit:cover;flex:0 0 auto\">",esc(avatar))}else{String::new()};
    let mut html=format!("<div class=\"ask-container\" style=\"display:flex;gap:.65rem;align-items:flex-start;margin:.35rem 0 .65rem;padding:.7rem .8rem;border:1px solid var(--primary, #ff70c7);border-radius:8px;background:var(--primary-dim, rgba(255,112,199,.12))\">{avatar}<div class=\"ask-body\" style=\"min-width:0;flex:1 1 auto\">{label}<blockquote class=\"ask-text\" style=\"margin:0;padding:.15rem 0 .15rem .75rem;border-left:3px solid var(--primary, #ff70c7);white-space:pre-wrap\">{}</blockquote>",esc(question).replace('\n',"<br />\n"));
    if !php_trim(string(row,"answer")).is_empty(){html.push_str(&format!("<hr class=\"ask-divider\" style=\"margin:.7rem 0;border:0;border-top:1px solid var(--primary, #ff70c7)\"><p class=\"ask-divider-fallback\" aria-hidden=\"true\">────────────</p><div class=\"ask-answer\" style=\"white-space:pre-wrap\">{}</div>",esc(php_trim(string(row,"answer"))).replace('\n',"<br />\n")));}
    html.push_str("</div></div>");Ok(html)
}

pub(crate) fn link_preview(card: &Value) -> Result<String> { link_preview_mode(card, true) }
pub(crate) fn link_preview_mode(card: &Value, interactive: bool) -> Result<String> {
    let uri = string(card, "url");
    if uri.is_empty() || card.get("status").and_then(Value::as_str).is_some_and(|s| s != "ok") { return Ok(String::new()); }
    let youtube_id = reqwest::Url::parse(uri).ok().and_then(|url| {
        let host = url.host_str().unwrap_or("").to_ascii_lowercase();
        let raw = if matches!(host.as_str(), "youtu.be"|"www.youtu.be") { url.path().trim_start_matches('/').to_owned() }
            else if matches!(host.as_str(),"youtube.com"|"www.youtube.com") && url.path().starts_with("/shorts/") { url.path().trim_start_matches("/shorts/").to_owned() }
            else if matches!(host.as_str(),"youtube.com"|"www.youtube.com") && url.path()=="/watch" { url.query_pairs().filter(|(key,_)|key=="v").last().map(|(_,value)|value.into_owned()).unwrap_or_default() }
            else { String::new() };
        static_regex!(r"^[A-Za-z0-9_-]{6,}").find(&raw).map(|id|id.as_str().to_owned())
    });
    if let Some(id) = youtube_id {
        let image = string(card,"image");
        let thumb = if image.starts_with("https://") { image.to_owned() } else { format!("https://i.ytimg.com/vi/{}/hqdefault.jpg",query(&id)) };
        let thumb = format!("<img src=\"{}\" alt=\"\" loading=\"lazy\" referrerpolicy=\"no-referrer\"><span class=\"youtube-link-card__play-icon\" aria-hidden=\"true\">▶</span>",esc(&thumb));
        let play = if interactive { format!("<button type=\"button\" class=\"youtube-link-card__play\" data-youtube-play aria-label=\"Play YouTube video\">{thumb}</button>") } else { format!("<span class=\"youtube-link-card__thumb\" aria-hidden=\"true\">{thumb}</span>") };
        let open = if interactive { format!("<a class=\"youtube-link-card__open\" href=\"{}\" target=\"_blank\" rel=\"nofollow noopener noreferrer\">Open on YouTube</a>",esc(uri)) } else { "<span class=\"youtube-link-card__open\">YouTube</span>".into() };
        let provider = if string(card,"provider_name").is_empty() { String::new() } else { format!("<div class=\"link-card__provider\">{}</div>",esc(string(card,"provider_name"))) };
        let desc = if string(card,"description").is_empty() { String::new() } else { format!("<div class=\"link-card__desc\">{}</div>",esc(string(card,"description"))) };
        return Ok(format!("<div class=\"link-card youtube-link-card youtube-link-card--wide{}\" data-youtube-id=\"{}\" data-youtube-url=\"{}\">{play}<div class=\"link-card__body\">{provider}<div class=\"link-card__title\">{}</div>{desc}{open}</div></div>",if interactive {""} else {" link-card--static"},esc(&id),esc(uri),esc(if string(card,"title").is_empty() {"YouTube video"} else {string(card,"title")})));
    }
    let mut inner = String::new();
    let image = string(card, "image");
    if image.starts_with("https://") { inner.push_str(&format!("<div class=\"link-card__media\"><img src=\"{}\" alt=\"\" loading=\"lazy\" referrerpolicy=\"no-referrer\"></div>", esc(image))); }
    inner.push_str("<div class=\"link-card__body\">");
    let provider = string(card, "provider_name");
    if !provider.is_empty() { inner.push_str(&format!("<div class=\"link-card__provider\">{}</div>", esc(provider))); }
    inner.push_str(&format!("<div class=\"link-card__title\">{}</div>", esc(if string(card, "title").is_empty() { uri } else { string(card, "title") })));
    if !string(card, "description").is_empty() { inner.push_str(&format!("<div class=\"link-card__desc\">{}</div>", esc(string(card, "description")))); }
    inner.push_str("</div>");
    Ok(if interactive { format!("<a class=\"link-card\" href=\"{}\" target=\"_blank\" rel=\"nofollow noopener noreferrer\">{inner}</a>", esc(uri)) } else { format!("<div class=\"link-card link-card--static\" data-url=\"{}\">{inner}</div>",esc(uri)) })
}

/// Native shared-card candidate. Only final fully hydrated/admitted trees may
/// reach this function. It performs no additional quote fetch after admission.
pub(super) fn render(status: &Value, facts: &CardFacts) -> Result<String> {
    if facts.owner_id < 1 || !matches!(facts.return_view.as_str(), "bookmarks" | "favourites" | "search") { bail!("invalid admitted rendering context"); }
    render_core(status,facts,None,None)
}

fn render_core(status:&Value,facts:&CardFacts,direct:Option<&crate::direct_context::DirectPresentation>,prepared_preview:Option<&Option<Value>>)->Result<String>{
    render_core_mode(status,facts,direct,prepared_preview,false,false)
}
fn render_core_mode(status:&Value,facts:&CardFacts,direct:Option<&crate::direct_context::DirectPresentation>,prepared_preview:Option<&Option<Value>>,read_only:bool,silent:bool)->Result<String>{
    if status.get("media_attachments").and_then(Value::as_array).is_some_and(|media| media.iter().any(|attachment|
        attachment.get("url").and_then(Value::as_str).unwrap_or("").is_empty()
            && !attachment.get("preview_url").and_then(Value::as_str).unwrap_or("").is_empty())) {
        bail!("preview-only primary attachment requires PHP media renderer");
    }
    if status.get("post").is_some_and(Value::is_object) { return super::bsky::render(status,facts); }
    let fresh_rss = facts.rss_row.as_ref().map(|row| super::rss::normalize(row,facts.owner_id)).transpose()?;
    let status = fresh_rss.as_ref().unwrap_or(status);
    let is_rss = fresh_rss.is_some();
    let mut st = status;
    let mut boost = String::new();
    if plain(string(status, "content")).is_empty() {
        if let Some(inner) = status.get("reblog").filter(|v| v.is_object()) {
            let account = status.get("account").unwrap_or(&Value::Null);
            let actor_ref=account_actor_ref(account);
            let actor = actor_ref.as_str();
            let display = if !string(account, "display_name").is_empty() { string(account, "display_name") }
                else if !string(account, "acct").is_empty() { string(account, "acct") } else { "Someone" };
            boost = format!("<div class=\"meta meta-row\" style=\"color:var(--primary)\"><i class=\"ph ph-repeat\" aria-hidden=\"true\"></i> <a href=\"{}\" style=\"color:inherit;text-decoration:none\">{}</a> boosted{}</div>", esc(&profile_href(actor, &facts.return_view)), esc(display), if string(status, "created_at").is_empty() { String::new() } else { format!(" · {}", relative_time(string(status, "created_at"), facts.now)) });
            st = inner;
        }
    }
    let account = st.get("account").unwrap_or(&Value::Null);
    let actor_ref=account_actor_ref(account);
    let actor = actor_ref.as_str();
    let uri = st.get("uri").or_else(|| st.get("url")).and_then(Value::as_str).unwrap_or("");
    if actor.is_empty() || uri.is_empty() { bail!("canonical normalized status identity required"); }
    let is_bsky = uri.starts_with("at://") && st.get("bsky_post").is_some();
    if string(st, "id").starts_with("rss:") && !is_rss { bail!("fresh owner RSS row required"); }
    let is_local = actor.trim_end_matches('/') == facts.owner_actor.trim_end_matches('/');
    if is_local && facts.own.is_none() { bail!("own-post actions require current owner DB facts"); }
    if st.get("in_reply_to_id").is_some_and(|v| !v.is_null()) && st.get("vaak_reply_parent").is_none() {
        // The canonical PHP chrome cannot turn a numeric Mastodon parent ID
        // into a URL. With no represented parent URL/preview it emits no reply
        // context. Do not invent a parent or fetch an unrelated numeric ID.
        let numeric_only=numeric_reply_without_url(st);
        if !numeric_only {bail!("degraded/reply-parent branch not yet ported")}
    }
    let acct = if account.get("acct").is_some() { string(account, "acct") } else { "?" };
    let display = if account.get("display_name").is_some() { string(account, "display_name") } else { acct };
    let emoji_map = facts.emoji_by_actor.get(actor).cloned().unwrap_or_default();
    let display_html = super::body::emojis(display,&emoji_map,true);
    let profile = profile_href(actor, &facts.return_view);
    let avatar = format!("<img class=\"tweet-av\" src=\"{}\" alt=\"\" width=\"40\" height=\"40\"  data-profile-hover-actor=\"{}\" loading=\"lazy\" decoding=\"async\" referrerpolicy=\"no-referrer\" onerror=\"this.onerror=null;this.src='https://mkultra.monster/img/avatar/default.webp'\">", esc(&facts.resolved_avatar), esc(actor));
    let avatar = if is_bsky { format!("<img class=\"tweet-av\" src=\"{}\" alt=\"\" width=\"40\" height=\"40\" loading=\"lazy\" decoding=\"async\" referrerpolicy=\"no-referrer\" onerror=\"this.onerror=null;this.src='https://mkultra.monster/img/avatar/default.webp'\" data-profile-hover-actor=\"{}\" >",esc(string(account,"avatar")),esc(actor)) } else { avatar };
    let degraded = !is_local && !is_rss && !is_bsky && uri.starts_with("https://") && plain(string(st, "content")).is_empty()
        && st.get("media_attachments").and_then(Value::as_array).is_none_or(Vec::is_empty)
        && !st.get("quote").is_some_and(Value::is_object) && facts.poll_row.is_none() && facts.ask_row.is_none() && !st.get("vaak_ask").is_some_and(Value::is_object) && !st.get("card").is_some_and(Value::is_object);
    let boost_hydrate = degraded && string(st,"vaak_degraded_reason")=="announce_only" && integer(st,"vaak_announce_event_id")>0;
    let hydrate_attrs = if boost_hydrate { format!(" data-boost-hydrate=\"1\" data-event-id=\"{}\" data-object-id=\"{}\" data-return-view=\"{}\"",integer(st,"vaak_announce_event_id"),esc(uri),esc(&facts.return_view)) } else if degraded { format!(" data-create-hydrate=\"1\" data-object-id=\"{}\" data-return-view=\"{}\"", esc(uri), esc(&facts.return_view)) } else { String::new() };
    let rss_attrs = if is_rss { format!(" data-rss-item=\"{}\" data-timeline-key=\"rss:{}\"",integer(st,"vaak_rss_item_id"),integer(st,"vaak_rss_item_id")) } else { String::new() };
    let rank_attrs = if is_rss { format!(" data-rank-platform=\"rss\" data-rank-key=\"rss:item:{}\" data-rank-actor=\"rss:feed:{}\"",integer(st,"vaak_rss_item_id"),integer(st,"vaak_rss_feed_id")) } else { String::new() };
    let protocol_attrs=if is_bsky {super::bsky::normalized_attrs(st)}else{String::new()};
    let focused=direct.is_some_and(|options|options.focused());
    let hide_header=direct.is_some_and(|options|options.hide_header());
    let mut html = format!("<article class=\"tweet{}{}{}{}\"{}{rss_attrs}{protocol_attrs}{hydrate_attrs}{rank_attrs}>{boost}",if focused {" tweet-focus"}else{""}, if boost.is_empty()&&!boost_hydrate { "" } else { " tweet-boost" },if is_bsky {" tweet-bsky"}else{""},if hide_header {" tweet-embed-nohd"}else{""},if focused {" id=\"status-focus\""}else{""});
    if !hide_header {
    html.push_str("<div class=\"tweet-hd\">");
    if is_rss { html.push_str(&format!("<img class=\"tweet-av\" src=\"{}\" alt=\"\" width=\"40\" height=\"40\" loading=\"lazy\" decoding=\"async\" referrerpolicy=\"no-referrer\" onerror=\"this.onerror=null;this.src='https://mkultra.monster/img/avatar/default.webp'\">",esc(string(account,"avatar")))); }
    else if is_local { html.push_str(&avatar); }
    else { html.push_str(&format!("<a href=\"{}\" style=\"text-decoration:none\">{avatar}</a>",esc(&profile))); }
    html.push_str(if is_rss { "<div class=\"tweet-hd-main\"><div>" } else { "<div class=\"tweet-hd-main tweet-hd-main--fedi\"><div>" });
    if is_rss { html.push_str(&format!("<span class=\"who\">{}</span>",display_html)); }
    else if is_local {
        html.push_str(&format!("<span class=\"who\">{}</span>",display_html));
        if !acct.is_empty() { html.push_str(&format!("<span class=\"meta\"> @{}</span>",esc(acct))); }
        html.push_str("<span class=\"tag\" style=\"margin-left:.35rem\">you</span>");
    } else {
        html.push_str(&format!("<a class=\"who\" href=\"{}\" style=\"color:inherit;text-decoration:none\">{}</a><a class=\"meta\" href=\"{}\" style=\"color:var(--muted);text-decoration:none\"> @{}</a>",esc(&profile),display_html,esc(&profile),esc(acct)));
    }
    html.push_str(&format!("<span class=\"meta\"> · {}</span>",relative_time(string(st,"created_at"),facts.now)));
    if !string(st, "edited_at").is_empty() { html.push_str(&format!("<span class=\"meta\" title=\"{}\"> · edited</span>", esc(string(st, "edited_at")))); }
    if boolean(st, "vaak_from_followed_tag") { html.push_str("<span class=\"tag\" title=\"Matched a hashtag you follow\">followed tag</span>"); }
    html.push_str("</div>");
    if is_rss { if let Ok(url)=reqwest::Url::parse(uri) { if let Some(host)=url.host_str(){html.push_str(&format!("<div class=\"meta\">{}</div>",esc(host)));} } }
    if let Some(label) = match string(st, "visibility") { "private" => Some("Followers-only"), "unlisted" => Some("Silent public"), "local" => Some("Local only"), _ => None } { html.push_str(&format!("<div class=\"meta\"><span class=\"tag\" title=\"Audience\">{label}</span></div>")); }
    html.push_str("</div></div>");
    }
    html.push_str(&reply_meta(st,&facts.return_view)?);
    if !direct.is_some_and(|presentation|presentation.pending_quote_suppressed()) && direct.and_then(|presentation|presentation.pending_quote()).is_none() && !st.get("quote").and_then(|quote|quote.get("quoted_status")).is_some_and(Value::is_object) && static_regex!(r"↪\s*QT").is_match(&plain(string(st,"content"))) {
        bail!("raw QT fallback requires canonical quote source assembly before painting");
    }
    let quoted_plain=st.get("quote").and_then(|quote|quote.get("quoted_status")).filter(|quoted|quoted.is_object()).map(|quoted|plain(string(quoted,"content")));
    let mut text=super::body::strip_raw_quotes(&plain(string(st,"content")),quoted_plain.as_deref());
    if let Some(ask) = facts.ask_row.as_ref().or_else(|| st.get("vaak_ask")) {
        let answer = php_trim(ask.get("_body_answer").or_else(|| ask.get("answer")).and_then(Value::as_str).unwrap_or(""));
        if !answer.is_empty() { text = answer.to_owned(); }
    }
    if static_regex!(r"(?i)^\((?:attachment|media|poll|quote|boost)\)$").is_match(php_trim(&text)){text.clear();}
    let mut body = String::new();
    if let Some(ask) = facts.ask_row.as_ref().or_else(|| st.get("vaak_ask")) {
        // The shared PHP painter renders the answer as the post body, while
        // the standalone Ask component can render its own answer divider.
        let mut question = ask.clone();
        question["answer"] = Value::String(String::new());
        body.push_str(&ask_html(&question)?);
    }
    if !text.is_empty() { body.push_str(&format!("<div class=\"body feed-body\" style=\"white-space:pre-wrap\">{}</div>", linked_body_html(&text,st,facts)?)); }
    let media = st.get("media_attachments").and_then(Value::as_array).cloned().unwrap_or_default();
    let paint_media = st.get("quote").and_then(|quote| quote.get("quoted_status")).filter(|quoted| quoted.is_object())
        .map(|quoted| crate::quote_media::outer_attachments_for_quote(st, quoted)).unwrap_or_else(|| media.clone());
    body.push_str(&media_row_mode(&paint_media, facts.lazy_media,silent));
    if let Some(quote) = st.get("quote").filter(|quote|quote.is_object()) {
        if let Some(quoted) = quote.get("quoted_status").filter(|v| v.is_object()) { body.push_str(&quote_card_mode(quoted, facts,silent)?); }
        else if let Some(pending)=direct.and_then(|presentation|presentation.pending_quote()){body.push_str(&pending.render(facts)?);}
        else if direct.is_some_and(|presentation|presentation.pending_quote_suppressed()) {}
        else { bail!("pending/synthetic/fallback quote branch not yet ported"); }
    }
    let poll = facts.poll_row.as_ref().map(|row| poll_html_mode(row, uri, facts,read_only)).unwrap_or_default();
    body.push_str(&poll);
    if media.is_empty() && poll.is_empty() {
        let quoted=st.get("quote").and_then(|quote|quote.get("quoted_status")).filter(|quote|quote.is_object());
        let painted_url=quoted.map(|quote|if string(quote,"uri").starts_with("https://"){string(quote,"uri")}else{string(quote,"url")}).unwrap_or("");
        if let Some(preview)=prepared_preview {if let Some(card)=preview.as_ref(){body.push_str(&link_preview(card)?);}}else if let Some(presentation)=direct {if let Some(card)=presentation.root_preview(){body.push_str(&link_preview(card)?);}}else { if let Some(card) = st.get("card").filter(|card|card.is_object()&&!status_urls_equivalent(string(card,"url"),painted_url)) { let mut card=card.clone();card["status"]=Value::String("ok".into());body.push_str(&link_preview(&card)?); } }
    }
    if degraded { body = format!("<div class=\"body feed-body meta vaak-degraded\"><span class=\"{}\">{}</span><a href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer\">Open on remote</a></div>",if boost_hydrate {"boost-hydrate-status"}else{"create-hydrate-status"},if boost_hydrate {"Loading boosted post…"}else{"Loading post…"}, esc(uri)); }
    html.push_str(&cw_gate(string(st, "spoiler_text"), boolean(st, "sensitive") || !php_trim(string(st, "spoiler_text")).is_empty(), &body, facts.auto_unblur_sensitive));
    let ctx = FediActionContext { owner_id: facts.owner_id, status_id: scalar_string(st, "id"), object_id: uri.into(), actor_id: actor.into(), remote_url: remote_object_href(uri, st.get("url"))?, return_view: facts.return_view.clone(), reply_mentions: if facts.reply_mentions.is_empty() { vec![super::actions::reply_mention_handle(actor, acct, &facts.owner_actor)] } else { facts.reply_mentions.clone() }, spoiler: string(st, "spoiler_text").into(), sensitive: boolean(st, "sensitive"), favourited: facts.favourited, bookmarked: facts.bookmarked, boosted: facts.boosted, counts: action_counts(st,facts), csrf: facts.csrf.clone(), moderation: facts.moderation.clone() };
    if read_only {
        html.push_str("<div class=\"tweet-actions\">");
        if !ctx.status_id.is_empty()&&!uri.is_empty(){
            for (count,icon,label) in [(ctx.counts.quotes,"ph-quotes","Quotes"),(ctx.counts.reblogs,"ph-repeat","Boosts"),(ctx.counts.favourites,"ph-heart","Likes")] {
                if count<=0{continue}
                let compact=super::actions::compact_count(count);
                html.push_str(&format!("<span class=\"meta has-count\" aria-label=\"{}\"><i class=\"ph {icon}\" aria-hidden=\"true\"></i><span class=\"action-count\" aria-hidden=\"true\">{compact}</span></span>",esc(&format!("{label} ({compact})"))));
            }
        }
        html.push_str(&super::actions::read_only_overflow(uri,&facts.return_view,&ctx.remote_url));
        html.push_str("</div>");
    }
    else if is_bsky && facts.bsky.as_ref().is_some_and(|context|context.enabled) { html.push_str(&super::bsky::normalized_actions(st,facts,direct.map(|options|options.show_open()).unwrap_or(true))?); }
    else if is_bsky { html.push_str(&super::actions::render_fedi_actions(&ctx,facts.owner_id)?); }
    else if is_rss { html.push_str(&super::rss::actions(st,facts)?); }
    else if is_local {
        let mut own=facts.own.as_ref().unwrap().clone();
        // PHP's edit prefill falls back to the final painted plain body when
        // the current row contains an empty or exact synthetic placeholder.
        if own.plain.is_empty()||matches!(own.plain.as_str(),"(quote)"|"(media)"|"(poll)"){own.plain=text.clone();}
        if let Some(options)=direct {
            let mut action_facts=facts.clone();
            action_facts.return_view=if options.focused(){"status".into()}else if facts.return_view=="status"{"home".into()}else{facts.return_view.clone()};
            html.push_str(&super::own::render_direct(uri,actor,&action_facts,&own,if options.focused(){&facts.return_view}else{""})?);
        }else{html.push_str(&super::own::render_presentation(uri, actor, facts, &own,string(st,"visibility"))?);}
    }
    else { if let Some(options)=direct{html.push_str(&super::actions::render_direct_actions(&ctx,facts.owner_id,options.show_open(),options.profile_actor())?);}else{html.push_str(&super::actions::render_fedi_actions(&ctx, facts.owner_id)?);} }
    html.push_str("</article>"); Ok(html)
}

pub(crate) fn parity_quote_card(status: &Value, facts: &CardFacts) -> Result<String> { quote_card(status, facts) }
pub(crate) fn parity_reply_meta(status: &Value, view: &str) -> Result<String> { reply_meta(status, view) }

/// Runtime callers can only render a final hydrated/admitted owner-bound token.
/// The raw template entry remains private to Library components and fixtures.
pub(crate) fn render_admitted(status: &super::context::AdmittedStatus) -> Result<String> {
    render_core(status.status(), status.facts(),None,status.root_preview())
}
pub(crate) fn render_search_admitted(status:&crate::search_context::AdmittedSearchStatus)->Result<String>{render_core(status.status(),status.facts(),None,status.root_preview())}
pub(crate) fn render_guest_profile_admitted(status:&crate::profile_context::AdmittedGuestProfileStatus)->Result<String>{
    let facts=status.facts();
    // Shared guest proof is AP-only. Raw/imported protocol painters have a
    // separate read-only capability; never enter an authenticated early return.
    crate::library::context::require_ap_preview_protocol(status.status(),0)?;
    if facts.owner_id!=0||!facts.owner_actor.is_empty()||!facts.csrf.is_empty()||facts.own.is_some(){bail!("guest profile requires separate public presentation facts")}
    render_core_mode(status.status(),facts,None,status.root_preview(),true,false)
}
pub(crate) fn render_direct_admitted(status:&crate::direct_context::AdmittedDirectStatus)->Result<String>{render_core(status.status(),status.facts(),Some(status.presentation()),None)}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    #[ignore = "requires independent genuine production SQL/helper capture"]
    fn genuine_production_object_urls_match_actual_php() {
        let path = std::env::var("VAAK_GENUINE_URL_CAPTURE").expect("independent PHP capture path");
        let reference: Value = serde_json::from_slice(&std::fs::read(path).unwrap()).unwrap();
        assert_eq!(reference["public_constants"]["AP_REMOTE_AVATAR_FALLBACK"], "https://mkultra.monster/img/avatar/default.webp");
        let mut exact = 0;
        let mut fallback = 0;
        for fixture in reference["captures"].as_array().unwrap().iter().filter(|fixture| string(fixture, "kind") == "remote_object_web_url") {
            let source = &fixture["source"];
            let result = remote_object_web_url(string(source, "object"), source.get("url_hint"));
            if string(fixture,"case").starts_with("threads_numeric_") {
                assert!(result.is_err(), "cache-dependent Threads URL must fall back");
                fallback += 1;
            } else {
                assert_eq!(result.unwrap(), string(fixture,"php"), "actual PHP URL {} {}", string(fixture,"width"), string(fixture,"case"));
                exact += 1;
            }
        }
        assert_eq!((exact, fallback), (22, 4));
        assert_eq!(remote_object_href("",Some(&Value::String("https://ordinary.test/pretty".into()))).unwrap(),"https://ordinary.test/pretty");
    }
    #[test]
    fn native_time_and_media_match_actual_php_components() {
        let file = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/migration-library-component-capture.php");
        let output = std::process::Command::new(std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/php-policy-test")).arg(file).output().unwrap();
        assert!(output.status.success(), "{}", String::from_utf8_lossy(&output.stderr));
        let reference: Value = serde_json::from_slice(&output.stdout).unwrap();
        let now = chrono::DateTime::from_timestamp(integer(&reference, "now"), 0).unwrap();
        for fixture in reference["reply_cw"].as_array().unwrap(){assert_eq!(super::super::actions::reply_suffix(&[],string(fixture,"cw"),boolean(fixture,"sensitive")),string(fixture,"query"),"actual PHP reply CW Unicode");}
        for (name,fixture) in reference["cw_whitespace"].as_object().unwrap(){
            assert_eq!(!php_trim(string(fixture,"cw")).is_empty(),boolean(fixture,"sensitive"),"actual PHP CW predicate {name}");
            assert_eq!(cw_gate(string(fixture,"cw"),boolean(fixture,"sensitive"),"<div>Body</div>",boolean(fixture,"auto")),string(fixture,"html"),"actual PHP CW whitespace {name}");
        }
        for (name, fixture) in reference["times"].as_object().unwrap() {
            assert_eq!(relative_time(string(fixture, "input"), now), string(fixture, "html"), "actual PHP time {name}");
        }
        for (name, fixture) in reference["media"].as_object().unwrap() {
            assert_eq!(media_row(fixture["items"].as_array().unwrap(), boolean(fixture, "lazy")), string(fixture, "html"), "actual PHP media {name}");
        }
        for (name,fixture) in reference["accountrefs"].as_object().unwrap(){assert_eq!(account_actor_ref(&fixture["account"]),string(fixture,"actor"),"actual PHP account identity {name}");}
        for (name,fixture) in reference["replies"].as_object().unwrap() {
            assert_eq!(reply_meta(&fixture["status"],"bookmarks").unwrap(),string(fixture,"html"),"actual PHP reply preview {name}");
        }
        for (name,fixture) in reference["asks"].as_object().unwrap() {
            assert_eq!(ask_html(&fixture["row"]).unwrap(),string(fixture,"html"),"actual PHP Ask actor {name}");
        }
        for (name, fixture) in reference["previews"].as_object().unwrap() {
            assert_eq!(link_preview_mode(&fixture["card"],boolean(fixture,"interactive")).unwrap(),string(fixture,"html"),"actual PHP link preview {name}");
        }
        for (name,fixture) in reference["degradedcards"].as_object().unwrap().iter().chain(reference["raw_quote_cards"].as_object().unwrap().iter()) {
            let facts=CardFacts { current_action_counts:None, bsky:None,owner_id:1,owner_actor:"https://mkultra.monster/users/fixture".into(),return_view:"bookmarks".into(),auto_unblur_sensitive:false,resolved_avatar:string(fixture,"avatar").into(),csrf:"fixture-csrf-token".into(),favourited:false,bookmarked:false,boosted:false,own:None,rss_row:None,rss_quote_row:None,rss_quoted:false,emoji_by_actor:Default::default(),reply_mentions:Vec::new(),moderation:Default::default(),poll_row:None,ask_row:None,now:chrono::DateTime::from_timestamp(integer(fixture,"rendered_at"),0).unwrap(),lazy_media:false };
            if name=="qt_comment" {assert!(render(&fixture["status"],&facts).unwrap_err().to_string().contains("raw QT fallback"));continue;}
            let tokens=|s:&str|static_regex!(r"<[^>]+>|[^<]+").find_iter(s).map(|token|php_trim(token.as_str()).to_owned()).filter(|token|!token.is_empty()).collect::<Vec<_>>();
            assert_eq!(tokens(&render(&fixture["status"],&facts).unwrap()),tokens(string(fixture,"html")),"actual PHP degraded shared card {name}");
        }
        for fixture in reference["url_aliases"].as_array().unwrap(){assert_eq!(status_urls_equivalent(string(fixture,"a"),string(fixture,"b")),boolean(fixture,"equivalent"),"actual presentation status aliases");}
        for (name, fixture) in reference["quotes"].as_object().unwrap().iter().chain(reference["rss_quotes"].as_object().unwrap().iter()) {
            let facts = CardFacts { current_action_counts:None, bsky:None, owner_id:1,owner_actor:"https://mkultra.monster/users/fixture".into(),return_view:"bookmarks".into(),auto_unblur_sensitive:boolean(fixture,"auto"),resolved_avatar:String::new(),csrf:String::new(),favourited:false,bookmarked:false,boosted:false,own:None,rss_row:None,rss_quote_row:None,rss_quoted:false,emoji_by_actor:Default::default(),reply_mentions:Vec::new(),moderation:Default::default(),poll_row:None,ask_row:None,now,lazy_media:false };
            let mut facts=facts;facts.rss_quote_row=fixture.get("row").cloned();
            assert_eq!(quote_card(&fixture["status"],&facts).unwrap(),string(fixture,"html"),"actual PHP quote media/card {name}");
        }

    }
    #[test]
    fn complete_native_cards_match_actual_php_header_body_and_actions() {
        let mut tested = Vec::<String>::new();
        let mut native_captures = serde_json::Map::new();
        let mut search_captures = serde_json::Map::new();
        let mut unsupported = Vec::new(); let mut denied = Vec::new();
        for reference_file in ["migration-card-reference-capture.php", "library-card-independent-capture.php", "library-unicode-card-capture.php", "search-render-reference-capture.php"] {
        let file = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join(format!("../../api/bin/{reference_file}"));
        let output = std::process::Command::new(std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/php-policy-test")).arg(file).output().unwrap();
        assert!(output.status.success(), "{}", String::from_utf8_lossy(&output.stderr));
        let reference: Value = serde_json::from_slice(&output.stdout).unwrap();
        let tokenize = |s: &str| static_regex!(r"<[^>]+>|[^<]+").find_iter(s).map(|m| php_trim(m.as_str()).to_owned()).filter(|s| !s.is_empty()).collect::<Vec<_>>();

        for (name, capture) in reference["captures"].as_object().unwrap() {
            if reference_file == "search-render-reference-capture.php" && string(capture,"kind") != "status" { continue; }
            if !capture["expected_admitted"].as_bool().unwrap_or(true) { denied.push(name.to_owned()); continue; }
            let ctx = &capture["context"];
            let status = capture.get("normalized_status").filter(|v|v.is_object()).unwrap_or(&capture["status"]);
            let uri = string(status, "uri");
            let find_saved = |table: &str| ctx[table].as_array().unwrap().iter().any(|row| string(row, "status_id") == string(status, "id") || string(row, "object_id") == uri);
            let facts = CardFacts { current_action_counts:None, bsky:None, owner_id: integer(ctx, "owner_id"), owner_actor: string(ctx, "owner_actor").into(), return_view: string(ctx, "return_view").into(), auto_unblur_sensitive: boolean(&ctx["profile"], "auto_unblur_sensitive"), resolved_avatar: string(ctx, "resolved_author_avatar").into(), csrf: string(ctx, "csrf_token").into(), favourited: find_saved("saved_favourites"), bookmarked: find_saved("saved_bookmarks"), boosted: find_saved("saved_reblogs"), own: ctx["local_status_rows"].as_array().unwrap().iter().find(|row| string(row,"note_id") == uri).map(|row| super::super::own::OwnFacts { local_id: integer(row,"local_id"), status_id: scalar_string(status,"id"), plain: string(row,"content_text").into(), spoiler: string(row,"spoiler_text").into(), sensitive:boolean(row,"sensitive") || !string(row,"spoiler_text").is_empty(), visibility:string(row,"visibility").into(), pinned:false,counts:Counts::default() }), reply_mentions:Vec::new(), rss_row: ctx["rss_item_rows"].as_array().unwrap().iter().find(|row| integer(row,"id") == integer(status,"vaak_rss_item_id") || format!("rss:{}",integer(row,"id")) == string(status,"id")).and_then(|row| ctx["rss_feed_rows"].as_array().unwrap().iter().find(|feed|integer(feed,"id") == integer(row,"feed_id")).map(|feed|{let mut row=row.clone();row["owner_user_id"]=feed["owner_user_id"].clone();row["feed_title"]=feed["title"].clone();row["feed_favicon"]=feed["favicon_url"].clone();row["feed_url"]=feed["feed_url"].clone();row["feed_site_url"]=feed["site_url"].clone();row})),rss_quote_row:None,rss_quoted:false,emoji_by_actor:std::collections::HashMap::new(), moderation: ModerationContext::default(), poll_row: ctx["poll_rows"].as_array().unwrap().iter().find(|row| string(row, "note_id") == uri).cloned(), ask_row: ctx.get("ask_metadata").filter(|v| v.is_object()).cloned(), now: chrono::DateTime::from_timestamp(integer(ctx, "rendered_at"), 0).unwrap(), lazy_media: boolean(ctx, "lazy_media") };
            if reference_file=="search-render-reference-capture.php" && string(capture,"native_support")=="php_fallback" {assert!(render(status,&facts).is_err(),"known unresolved source must not paint partial Search card");continue;}
            let rendered=render(status,&facts);
            if let Ok(native) = &rendered {
                let actual = tokenize(&native); let expected = tokenize(string(capture, "html"));
                if actual != expected { let index = actual.iter().zip(expected.iter()).position(|(a,b)|a != b).unwrap_or(actual.len().min(expected.len()));
                    panic!("actual PHP full card {name} differs at token {index}: native={:?}, PHP={:?}",actual.get(index),expected.get(index)); }
                let mut exported = capture.clone(); exported["html"] = Value::String(native.clone());
                if reference_file == "search-render-reference-capture.php" {search_captures.insert(name.clone(),exported);} else {native_captures.insert(name.clone(), exported);}
                tested.push(name.to_owned());
            } else { if reference_file != "migration-card-reference-capture.php" { panic!("independent supported variation {name} unexpectedly unsupported: {:?}",rendered.err()); } unsupported.push(name.to_owned()); }
        }
        }
        if let Ok(path) = std::env::var("VAAK_LIBRARY_NATIVE_CAPTURE_PATH") {
            std::fs::write(path, serde_json::to_vec_pretty(&serde_json::json!({"captures":native_captures,"unsupported":unsupported,"denied":denied,"promotion_allowed":false})).unwrap()).unwrap();
        }
        assert_eq!(search_captures.len(),15,"mandatory complete Search cards");
        if let Ok(path)=std::env::var("VAAK_SEARCH_NATIVE_CAPTURE_PATH") {
            std::fs::write(path,serde_json::to_vec_pretty(&serde_json::json!({"captures":search_captures,"admission_verified":false,"promotion_allowed":false})).unwrap()).unwrap();
        }
        for name in ["plain", "cw_media", "boost", "quote", "private", "sensitive_collapsed", "sensitive_auto_unblur", "cw_auto_unblur", "saved_flags", "poll_open", "poll_voted", "poll_expired", "ask", "link_preview", "own", "rss", "bsky_normalized", "bsky_sensitive"] { assert!(tested.iter().any(|tested|tested == name), "required native case {name}"); }
    }
    #[test]
    fn custom_cw_stays_closed_when_sensitive_auto_unblur_enabled() {
        assert!(cw_gate("Custom warning", true, "payload", true).starts_with("<details"));
        assert!(cw_gate("", true, "payload", true).starts_with("<div class=\"tweet-content\""));
        assert!(cw_gate("", true, "", false).contains("sensitive media"));
    }
}

/// Pinned feed presentation is scoped to the admitted card, including quote media.
pub(crate) fn render_pinned_direct(status:&crate::direct_context::AdmittedDirectStatus)->Result<String>{render_core_mode(status.status(),status.facts(),Some(status.presentation()),None,false,true)}
pub(crate) fn render_pinned_guest(status:&crate::profile_context::AdmittedGuestProfileStatus)->Result<String>{let facts=status.facts();crate::library::context::require_ap_preview_protocol(status.status(),0)?;if facts.owner_id!=0||!facts.owner_actor.is_empty()||!facts.csrf.is_empty()||facts.own.is_some(){bail!("guest pinned card requires public facts")}render_core_mode(status.status(),facts,None,status.root_preview(),true,true)}
