//! Canonical text components for Library; DB-resolved mention and emoji facts
//! must be supplied by the authenticated page assembler before those branches.
// Immutable compiled patterns only; request text, source facts and results
// remain uncached. Each callsite retains exactly its original regex literal.
macro_rules! static_regex { ($pattern:literal) => {{
 static REGEX: std::sync::OnceLock<regex::Regex> = std::sync::OnceLock::new();
 REGEX.get_or_init(||{
  #[cfg(test)] { crate::library::note_static_regex_compile(); }
  regex::Regex::new($pattern).unwrap()
 })
 }}; }

use anyhow::{bail, Context, Result};
use super::actions::{esc, query};

/// Exact shared painter Ask-body precedence (ap-admin.php:17681-17726).
/// Caller-provided current Ask facts establish the component; HTML does not
/// establish source identity or audience. Preserve the original HTML for the
/// duplicate-container removal even after current own plaintext was loaded.
pub(crate) fn ask_plain_body(plain:&str,original_html:&str,answer:&str,card_nonempty:bool)->String{
    let answer=super::card::php_trim(answer);
    let mut text=if !answer.is_empty(){answer.to_owned()}else if card_nonempty&&static_regex!(r"(?iu)\basked\b").is_match(plain){String::new()}else{plain.to_owned()};
    if card_nonempty&&original_html.contains("ask-container"){
        let stripped=static_regex!(r#"<div class="ask-container\b[^>]*>[\s\S]*?<div class="ask-body\b[^>]*>[\s\S]*?</div>\s*</div>"#).replace_all(original_html,"");
        if stripped.as_ref()!=original_html{let from_html=html_to_plain(&stripped);if !from_html.is_empty(){text=from_html;}}
    }
    text
}

/// Pure legacy text repairs. Cache-backed bare-handle discovery remains a
/// separate server fact; these functions never guess an actor identity.
pub(crate) fn repair_bridgy(text: &str) -> String {
    static_regex!(r"@([A-Za-z0-9][A-Za-z0-9.-]*\.[A-Za-z]{2,})@bsky\.brid\.gy\b").replace_all(text,"@$1").into_owned()
}
pub(crate) fn repair_at_tags(text: &str) -> String {
    let text=static_regex!(r"(?i)@#([\p{L}\p{N}_]+)@(?:[a-z0-9.-]+\.[a-z]{2,})").replace_all(text,"#$1").into_owned();
    static_regex!(r"@#([\p{L}\p{N}_]+)").replace_all(&text,"#$1").into_owned()
}
pub(crate) fn unglue_mentions(text: &str, known: &[String]) -> String {
    let pattern=static_regex!(r"(^|[^\w@])(@[\w]+@(?:[a-z0-9-]+\.)+[a-z]{2,})");
    let split=|input:&str,pattern:&regex::Regex|pattern.replace_all(input,|cap:&regex::Captures|{
        let next=&input[cap.get(0).unwrap().end()..];
        if next.chars().next().is_some_and(|c| c.is_numeric() || (c.is_uppercase() && c.is_alphabetic())) {format!("{} ",&cap[0])}else{cap[0].to_owned()}
    }).into_owned();
    let mut output=split(text,&pattern);
    let mut names=std::collections::HashMap::new();
    for name in known {let name=name.trim();if !name.is_empty()&&static_regex!(r"^\w+$").is_match(name){names.insert(name.to_lowercase(),name.to_owned());}}
    let mut names=names.into_values().collect::<Vec<_>>();names.sort_by_key(|name|std::cmp::Reverse(name.len()));
    for name in names {let pattern=regex::Regex::new(&format!(r"(^|[^\w@])@({})",regex::escape(&name))).unwrap();output=split(&output,&pattern);}
    output
}
pub(crate) fn unglue_hashtags(text: &str) -> String {
    let mut output=String::new();let mut previous=None;let mut characters=text.chars().peekable();
    while let Some(character)=characters.next(){
        if character=='#'&&previous.is_some_and(|c:char|c.is_alphabetic()||matches!(c,'"'|'\''|')'|']'|'}'))&&characters.peek().is_some_and(|c|c.is_alphabetic()||c.is_numeric()||*c=='_'){output.push(' ');}
        output.push(character);previous=Some(character);
    }
    output
}

pub(crate) fn expand_url_mentions(text: &str) -> String {
    let mut hosts:std::collections::HashMap<String,Vec<String>>=std::collections::HashMap::new();
    let urls=static_regex!(r"(?i)https://([^/\s]+)(/[^\s<]*)?");
    let paths=static_regex!(r"/(?:@|users/)([^/]+)(?:/|$)");
    for url in urls.captures_iter(text) {
        if let Some(path)=paths.captures(url.get(2).map(|m|m.as_str()).unwrap_or("")){
            let raw=path[1].as_bytes();let mut decoded=Vec::new();let mut i=0;
            while i<raw.len(){if raw[i]==b'%'&&i+2<raw.len(){if let Ok(value)=u8::from_str_radix(std::str::from_utf8(&raw[i+1..i+3]).unwrap_or(""),16){decoded.push(value);i+=3;continue;}}decoded.push(raw[i]);i+=1;}
            let user=String::from_utf8(decoded).unwrap_or_default();let user=user.trim_start_matches('@');
            if !user.is_empty()&&user.bytes().all(|c|c.is_ascii_alphanumeric()||c==b'_')&&!user.bytes().all(|c|c.is_ascii_digit()) {
                let host=url[1].to_lowercase().trim_end_matches('.').to_owned();
                let candidates=hosts.entry(user.to_lowercase()).or_default();if !host.is_empty()&&!candidates.contains(&host){candidates.push(host);}
            }
        }
    }
    let pattern=static_regex!(r"(^|[^A-Za-z0-9_@/])@([A-Za-z0-9_]{2,40})");
    pattern.replace_all(text,|cap:&regex::Captures|{
        let tail=&text[cap.get(0).unwrap().end()..];
        if tail.chars().next().is_some_and(|c|c.is_ascii_alphanumeric()||c=='_'||c=='@'){return cap[0].to_owned();}
        match hosts.get(&cap[2].to_lowercase()){Some(hosts)if hosts.len()==1=>format!("{}@{}@{}",&cap[1],&cap[2],hosts[0]),_=>cap[0].to_owned()}
    }).into_owned()
}
pub(crate) fn unglue_host_mentions(text:&str,owner_actor:&str,extra:&[String])->String {
    let mut hosts=extra.iter().map(|h|h.trim().to_lowercase()).filter(|h|h.contains('.')).collect::<Vec<_>>();
    if let Ok(url)=reqwest::Url::parse(owner_actor){if let Some(host)=url.host_str(){hosts.push(host.to_lowercase());}}
    hosts.push("mkultra.monster".into());hosts.sort();hosts.dedup();hosts.sort_by_key(|h|std::cmp::Reverse(h.len()));
    let mut output=text.to_owned();
    for host in hosts{
        let pattern=regex::Regex::new(&format!(r"(^|[^A-Za-z0-9_])(@[A-Za-z0-9_]+@{})([\p{{L}}\p{{N}}][\p{{L}}\p{{N}}_-]*)",regex::escape(&host))).unwrap();
        output=pattern.replace_all(&output,|cap:&regex::Captures|{
            let tail=&output[cap.get(0).unwrap().end()..];
            if tail.chars().next().is_none_or(|c|c.is_whitespace()||c.is_ascii_punctuation()){format!("{}{} {}",&cap[1],&cap[2],&cap[3])}else{cap[0].to_owned()}
        }).into_owned();
    }
    output
}

pub(crate) fn repair_known_text(text:&str,owner_actor:&str)->String {
    let mut protected=Vec::new();
    let code=static_regex!(r"`([^`\r\n]+)`");
    let text=code.replace_all(text,|cap:&regex::Captures|{
        if super::card::php_trim(&cap[1]).is_empty(){return cap[0].to_owned();}
        let key=format!("\u{1}C{}\u{1}",protected.len());protected.push((key.clone(),cap[0].to_owned()));key
    });
    let mut text=repair_at_tags(&repair_bridgy(&text));
    text=unglue_mentions(&text,&[]);text=expand_url_mentions(&text);
    text=unglue_hashtags(&text);text=unglue_host_mentions(&text,owner_actor,&[]);
    for (key,code) in protected{text=text.replace(&key,&code);}
    text
}

fn fix_utf8(text:&str)->String {
    if !static_regex!(r"â.|Ã.|Â.|ðŸ").is_match(text){return text.into();}
    let bytes=text.chars().map(|c|if u32::from(c)<=255{c as u8}else{b'?'}).collect::<Vec<_>>();
    if let Ok(value)=String::from_utf8(bytes){if !value.is_empty()&&!static_regex!(r"â.|Ã.").is_match(&value){return value;}}
    let mut value=text.to_owned();
    for (old,new) in [("â€™","’"),("â€˜","‘"),("â€œ","“"),("â€\u{9d}","”"),("â€”","—"),("â€“","–"),("â€¦","…"),("Â","")]{value=value.replace(old,new);}
    value
}
fn strip_tags(text:&str)->String {
    static_regex!(r"(?s)<!--.*?-->|<[^>]*>").replace_all(text,"").into_owned()
}
fn attribute(attrs:&str,name:&str)->String {
    regex::Regex::new(&format!(r#"(?i)\b{}\s*=\s*["']([^"']*)["']"#,regex::escape(name))).unwrap().captures(attrs).map(|cap|cap[1].to_owned()).unwrap_or_default()
}
fn acct_from_href(href:&str,fallback:&str)->Option<String> {
    if !href.starts_with("https://"){return None;}
    let url=reqwest::Url::parse(href).ok()?;let host=url.host_str()?.to_lowercase();
    let path=static_regex!(r"/(?:@|users/)([^/]+)/?$");
    let mut user=if let Some(cap)=path.captures(url.path()){
        let raw=cap[1].as_bytes();let mut bytes=Vec::new();let mut i=0;
        while i<raw.len(){if raw[i]==b'%'&&i+2<raw.len(){if let Ok(n)=u8::from_str_radix(std::str::from_utf8(&raw[i+1..i+3]).unwrap_or(""),16){bytes.push(n);i+=3;continue;}}bytes.push(raw[i]);i+=1;}String::from_utf8_lossy(&bytes).into_owned()
    }else{let user=fallback.trim_start_matches('@');if user.matches('@').count()==1&&static_regex!(r"^[^@]+@[^@]+\.[^@]+$").is_match(user){user.to_owned()}else{user.split('@').next().unwrap_or("").to_owned()}};
    user=user.trim_start_matches('@').to_owned();if user.is_empty(){return None;}
    if (host=="brid.gy"||host.ends_with(".brid.gy"))&&user.contains('.')&&!user.to_lowercase().starts_with("did:"){Some(user)}else{Some(format!("{user}@{host}"))}
}
/// Match the canonical pure PHP HTML-to-text boundary before linkification.
pub(crate) fn html_to_plain(input:&str)->String {
    let mut html=fix_utf8(input);
    if html.contains('<'){
        html=static_regex!(r"(?is)<a\b([^>]*)>(.*?)</a>").replace_all(&html,|cap:&regex::Captures|{
            let inner=crate::home_hydrate_ranked::html_entity_decode(&strip_tags(&cap[2]));
            let inner=super::card::php_trim(&inner).to_owned();
            let href=crate::home_hydrate_ranked::html_entity_decode(&attribute(&cap[1],"href"));
            if href.is_empty(){return inner;}
            let class=attribute(&cap[1],"class").to_lowercase();let rel=attribute(&cap[1],"rel").to_lowercase();
            let path=reqwest::Url::parse(&href).ok().map(|url|url.path().to_lowercase()).unwrap_or_default();
            if class.contains("hashtag")||rel.contains("tag")||path.contains("/tags/")||inner.starts_with('#'){
                return static_regex!(r"#([\p{L}\p{N}_]+)").captures(&inner).map(|cap|format!("#{}",&cap[1])).unwrap_or_else(||if inner.is_empty(){href}else{inner});
            }
            if (class.contains("mention")||inner.starts_with('@')||class.contains("u-url"))&&href.starts_with("https://"){
                if static_regex!(r"^@[\w.-]+@[\w.-]+\.[\w.-]+$").is_match(&inner){return inner;}
                let bridgy=reqwest::Url::parse(&href).ok().and_then(|url|url.host_str().map(|host|host=="brid.gy"||host.ends_with(".brid.gy"))).unwrap_or(false);
                if bridgy&&static_regex!(r"^@[A-Za-z0-9][A-Za-z0-9.-]*\.[A-Za-z]{2,}$").is_match(&inner){return inner;}
                let fallback=inner.strip_prefix('@').unwrap_or(&inner);
                if !fallback.starts_with('#'){if let Some(acct)=acct_from_href(&href,fallback){if !acct.starts_with('#'){return format!("@{acct}");}}}
            }
            if inner.is_empty(){href}else{inner}
        }).into_owned();
        html=static_regex!(r#"(?i)<span\b[^>]*class="[^"]*\bquote-inline\b[^"]*"[^>]*>"#).replace_all(&html,"\n\n").into_owned();
        html=static_regex!(r"(?i)<\s*br\s*/?\s*>").replace_all(&html,"\n").into_owned();
        html=static_regex!(r"(?i)<\s*/\s*(?:p|div|li|tr|h[1-6]|blockquote)\s*>").replace_all(&html,"\n\n").into_owned();
        html=strip_tags(&html);
    }
    html=crate::home_hydrate_ranked::html_entity_decode(&html);
    html=static_regex!(r"[ \t]+").replace_all(&html," ").into_owned();
    html=super::card::php_trim(&static_regex!(r"\n{3,}").replace_all(&html,"\n\n")).to_owned();
    repair_at_tags(&unglue_hashtags(&expand_url_mentions(&unglue_mentions(&html,&[]))))
}

pub(crate) fn looks_like_as2(text:&str)->bool {
    let text=text.trim_start_matches(|c|matches!(c,' '|'\t'|'\n'|'\r'|'\0'|'\x0B'));
    static_regex!(r"↪\s*QT(?:Create|Announce|Update|Note|QuotePost)\b").is_match(text)
        ||text.contains("QuoteAuthorization")||text.contains("interactionPolicy")
        ||(text.starts_with(['{','['])&&(text.contains("\"@context\"")||(text.contains("\"type\"")&&text.contains("\"attributedTo\""))||text.contains("activitystreams")))
}
pub(crate) fn scrub_as2(text:&str)->String {
    let text=super::card::php_trim(text);if !looks_like_as2(text){return text.into();}
    if let Some(marker)=static_regex!(r"\n\n↪\s*QT|\n↪\s*QT").find(text){let kept=super::card::php_trim(&text[..marker.start()]);if !kept.is_empty()&&!looks_like_as2(kept){return kept.into();}}
    String::new()
}
pub(crate) fn strip_raw_quotes(input:&str,quoted:Option<&str>)->String {
    let mut text=super::card::php_trim(input).to_owned();if looks_like_as2(&text){text=scrub_as2(&text);}
    let qt=static_regex!(r"(?:^|\n)\s*(?:↪|➡|→)?\s*QT(Create|Announce|Update|Note|QuotePost|\b)");
    text=super::card::php_trim(&qt.replace_all(&text,|cap:&regex::Captures|format!("\n↪ QT{}",&cap[1]))).to_owned();
    if let Some(marker)=static_regex!(r"(?:^|\n)\s*(?:↪|➡|→)\s*QT(?:Create|Announce|Update|Note|QuotePost)?\b").find(&text){text=super::card::php_trim(&text[..marker.start()]).to_owned();}
    text=super::card::php_trim(&static_regex!(r"(?:^|\n)\s*RE:\s*https://[^\s<>]+").replace_all(&text,"")).to_owned();
    if let Some(q)=quoted.map(super::card::php_trim).filter(|q|!q.is_empty()){if text.eq_ignore_ascii_case(q)||super::card::php_trim(&text.replace(q,"")).is_empty(){text.clear();}}
    text
}

pub(crate) fn linked_text(text: &str, view: &str) -> Result<String> {
    let text = crate::home_hydrate_ranked::html_entity_decode(super::card::php_trim(text));
    let url = static_regex!(r#"(?i)^(?:https?://[^\s<]+|www\.[^\s<]+|(?:[a-z0-9-]+\.)+[a-z]{2,}/[^\s<>\"']+)"#);
    let tag = static_regex!(r"^#([\p{L}\p{N}_]{1,100})");
    let mut output = String::new();
    let mut index = 0;
    while index < text.len() {
        let rest = &text[index..];
        let previous = text[..index].chars().next_back();
        if rest.starts_with('`') {
            if let Some(end) = rest[1..].find('`') {
                let code = &rest[1..end + 1];
                if !super::card::php_trim(code).is_empty() && !code.contains(['\n', '\r']) {
                    output.push_str(&format!("<code>{}</code>", esc(code)));
                    index += end + 2;
                    continue;
                }
            }
        }
        let url_boundary = previous.is_none_or(|ch| !(ch.is_alphanumeric() || matches!(ch, '_' | '.' | '/' | ':' | '@' | '%' | '-')));
        if rest.starts_with("https://") || rest.starts_with("http://") || url_boundary {
            if let Some(found) = url.find(rest) {
                let raw = found.as_str();
                let prefix = if raw.starts_with("http://") || raw.starts_with("https://") { "" } else { "https://" };
                if !raw.contains("...") && !raw.contains('…') && !raw.contains("..") {
                    let core = raw.trim_end_matches(['.', ',', ';', ':', '!', '?', ')', ']']);
                    let (core, trail) = if core.ends_with('/') { (raw, "") } else { (core, &raw[core.len()..]) };
                    let target = format!("{prefix}{core}");
                    if is_status_url(&target) {
                        output.push_str(&format!("<a class=\"status-link\" href=\"{}\">{}</a>", esc(&super::actions::in_app_status_href(&target, view)), esc(&target)));
                    } else {
                        output.push_str(&format!("<a class=\"ext-link\" href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer\">{}</a>", esc(&target), esc(&target)));
                    }
                    output.push_str(&esc(trail));
                    index += raw.len();
                    continue;
                }
            }
        }
        if previous.is_none_or(|ch| !(ch.is_ascii_alphanumeric() || matches!(ch, '_' | '&' | '/' | '%'))) {
            if let Some(captures) = tag.captures(rest) {
                let matched = captures.get(0).unwrap().as_str();
                output.push_str(&format!("<a class=\"hashtag\" href=\"{}\">{}</a>", esc(&format!("?view=search&q={}&type=statuses", query(matched))), esc(matched)));
                index += matched.len();
                continue;
            }
        }
        if rest.starts_with('@') && previous.is_none_or(|ch|!(ch.is_ascii_alphanumeric()||ch=='_')) {
            bail!("Library mention resolution requires current server cache context");
        }
        let character = rest.chars().next().unwrap();
        output.push_str(&esc(&character.to_string()));
        index += character.len_utf8();
    }
    Ok(output)
}

/// Render fully resolved declared/cache mention targets without nested anchors.
/// Discovery and ambiguity resolution belong to the authenticated assembler.
pub(crate) fn linked_text_mentions(text:&str,view:&str,mentions:&[serde_json::Value],owner_actor:&str)->Result<String>{
    let text=super::card::php_trim(text);
    let mut list=mentions.iter().filter_map(|mention|{
        let acct=mention["acct"].as_str().unwrap_or("").trim();
        let url=mention.get("url").or_else(||mention.get("uri")).and_then(|v|v.as_str()).unwrap_or("").trim();
        if acct.is_empty()||!(url.starts_with("https://")||url.starts_with("search://")){return None;}
        let user=mention["username"].as_str().filter(|user|!user.is_empty()).unwrap_or_else(||acct.split('@').next().unwrap_or(acct));
        Some((acct.to_owned(),user.to_owned(),url.to_owned()))
    }).collect::<Vec<_>>();
    list.sort_by_key(|(acct,_,_)|std::cmp::Reverse(acct.len()));
    let mut output=String::new();let mut index=0;
    while index<text.len(){
        let rest=&text[index..];let previous=text[..index].chars().next_back();
        // Let the existing code/URL parser paint protected spans as a unit.
        if rest.starts_with('`') {if let Some(end)=rest[1..].find('`'){let code=&rest[..end+2];output.push_str(&linked_text(code,view)?);index+=end+2;continue;}}
        let url=static_regex!(r"^https?://[^\s<]+");
        if let Some(found)=url.find(rest){output.push_str(&linked_text(found.as_str(),view)?);index+=found.end();continue;}
        if rest.starts_with('@')&&previous.is_none_or(|c|!(c.is_ascii_alphanumeric()||c=='_'||c=='/')){
            let candidate=list.iter().find(|(acct,_,_)|acct.contains('@')&&rest.starts_with(&format!("@{acct}")))
                .or_else(||list.iter().find(|(_,user,url)|rest.starts_with(&format!("@{user}"))&&rest[user.len()+1..].chars().next().is_none_or(|c|!(c.is_ascii_alphanumeric()||matches!(c,'_'|'.'|'@')))&&list.iter().filter(|(_,candidate,_)|candidate.eq_ignore_ascii_case(user)).all(|(_,_,other)|other==url)));
            if let Some((acct,user,url))=candidate{
                let full=acct.contains('@')&&rest.starts_with(&format!("@{acct}"));
                let raw=if full{acct}else{user};
                let local=static_regex!(r"(?i)^https://mkultra\.monster/users/[A-Za-z0-9_]+$").is_match(url);
                let label=if full&&!local{acct}else{user};
                let href=if let Some(user)=url.strip_prefix("search://"){format!("?view=search&q={}&type=accounts&resolve=1",query(&format!("@{user}")))}else if url.trim_end_matches('/')==owner_actor.trim_end_matches('/'){"?view=outbox".into()}else if local{format!("/vaak/users/{}?from={}",query(url.rsplit('/').next().unwrap_or("")),query(view))}else{format!("/vaak/?view=remote_profile&actor={}&from={}",query(url),query(view))};
                output.push_str(&format!("<a class=\"mention\" href=\"{}\">@{}</a>",esc(&href),esc(label)));index+=raw.len()+1;continue;
            }
            bail!("canonical current cache mention target unresolved");
        }
        if rest.starts_with('@') { output.push('@');index+=1;continue; }
        // Paint ordinary spans together so hashtags and scheme-less URLs retain
        // the same boundary context as the canonical linkifier.
        let end=[rest.find('@'),rest.find('`'),rest.find("https://"),rest.find("http://")].into_iter().flatten().filter(|end|*end>0).min().unwrap_or(rest.len());
        let span=&rest[..end];
        let leading=span.len()-span.trim_start().len();
        let trailing=span.len()-span.trim_end().len();
        if leading==span.len(){output.push_str(&esc(span));}
        else {output.push_str(&esc(&span[..leading]));output.push_str(&linked_text(&span[leading..span.len()-trailing],view)?);output.push_str(&esc(&span[span.len()-trailing..]));}
        index+=end;
    }
    Ok(output)
}

/// Known server cache emoji URLs only. Display names escape each plain span;
/// body HTML uses the canonical PHP shortcode boundary grammar.
pub(crate) fn emojis(text: &str, map: &std::collections::HashMap<String,String>, display: bool) -> String {
    let pattern = static_regex!(r":([A-Za-z0-9_-]{1,80}):");
    let mut output = String::new();
    let mut index = 0;
    for captures in pattern.captures_iter(text) {
        let token = captures.get(0).unwrap();
        let before = text[..token.start()].chars().next_back();
        let after = text[token.end()..].chars().next();
        let word = |c:char| c.is_ascii_alphanumeric() || c=='_';
        if !display && (before.is_some_and(|c|word(c)||matches!(c,'/'|'-')) || after.is_some_and(|c|word(c)||c=='-')) { continue; }
        let Some(url) = map.get(&captures[1]).filter(|url|url.starts_with("https://")) else { continue; };
        let span = &text[index..token.start()];
        output.push_str(&if display { esc(span) } else { span.to_owned() });
        let alt = esc(token.as_str());
        if display { output.push_str(&format!("<img class=\"custom-emoji\" src=\"{}\" alt=\"{alt}\" title=\"{alt}\" width=\"20\" height=\"20\" loading=\"lazy\" decoding=\"async\" referrerpolicy=\"no-referrer\">",esc(url))); }
        else { output.push_str(&format!("<img class=\"custom-emoji\" src=\"{}\" alt=\"{alt}\" title=\"{alt}\" loading=\"lazy\" decoding=\"async\" referrerpolicy=\"no-referrer\" width=\"20\" height=\"20\">",esc(url))); }
        index = token.end();
    }
    output.push_str(&if display { esc(&text[index..]) } else { text[index..].to_owned() });
    output
}

/// Link facets use UTF-8 byte indices from the retained authoritative record;
/// existing anchors remain intact and shortened labels never become URLs.
pub(crate) fn facets(html: &str, status: &serde_json::Value) -> String {
    let record=status.get("bsky_post_view").or_else(||status.get("bsky_post")).and_then(|post|post.get("record")).or_else(||status.get("record"));
    let text=record.and_then(|record|record.get("text")).and_then(|v|v.as_str()).or_else(||status.get("vaak_bsky_text").and_then(|v|v.as_str())).unwrap_or("");
    let facets=record.and_then(|record|record.get("facets")).and_then(|v|v.as_array()).or_else(||status.get("vaak_bsky_facets").and_then(|v|v.as_array()));
    let mut replacements=std::collections::HashMap::new();
    for facet in facets.into_iter().flatten() {
        let start=facet["index"]["byteStart"].as_i64().unwrap_or(-1);
        let mut end=facet["index"]["byteEnd"].as_i64().unwrap_or(-1);
        if start<0 || end<=start { continue; }
        if end>text.len() as i64 {
            if start<text.len() as i64 && end-text.len() as i64<=32 { end=text.len() as i64; } else { continue; }
        }
        let Some(label)=text.get(start as usize..end as usize) else { continue; };
        if label.len()<8 || !label.contains('.') || !label.contains('/') { continue; }
        if let Some(uri)=facet["features"].as_array().into_iter().flatten().find_map(|feature| {
            let kind=feature["$type"].as_str().unwrap_or("");
            let uri=feature["uri"].as_str().unwrap_or("").trim();
            (kind.contains("#link")&&uri.starts_with("http")&&!uri.contains("...")&&!uri.contains('…')).then_some(uri)
        }) { replacements.insert(label.to_owned(),uri.to_owned()); }
    }
    let mut replacements=replacements.into_iter().collect::<Vec<_>>();
    replacements.sort_by_key(|(label,_)|std::cmp::Reverse(label.len()));
    let anchors=static_regex!(r"(?is)<a\b[^>]*>.*?</a>");
    let mut html=html.to_owned();
    for (label,uri) in replacements {
        let visible=esc(&label);
        let anchor=format!("<a class=\"ext-link\" href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer nofollow\">{visible}</a>",esc(&uri));
        let mut output=String::new();let mut index=0;
        for token in anchors.find_iter(&html) { output.push_str(&html[index..token.start()].replace(&visible,&anchor));output.push_str(token.as_str());index=token.end(); }
        output.push_str(&html[index..].replace(&visible,&anchor));html=output;
    }
    html
}

fn is_status_url(url: &str) -> bool {
    let url=url.trim().trim_end_matches('/');
    let canonical=if url.starts_with("https://") {
        reqwest::Url::parse(url).ok().is_some_and(|parsed|{
            let path=parsed.path();
            !static_regex!(r"(?i)^/(?:@|users/)[^/]+/?$").is_match(path)
                &&static_regex!(r"(?i)/(?:statuses|notes|objects|notice|post|posts|p)/|/@[^/]+/[A-Za-z0-9_-]+").is_match(path)
        })
    } else {false};
    canonical||static_regex!(r"(?i)^https://(?:bsky\.app/profile/[^/]+/post/|[^/]+/(?:users|@)[^/]+/(?:statuses|posts)/[0-9]+|[^/]+/ap/(?:users|actor)s?/[^/]+/statuses/)").is_match(url)
}

// admin_linkify_body_html (ap-admin.php:16200) with its own mention discovery.
// Raw outbox cards pass no declared mentions, so PHP discovers @user@host,
// dotted handles and bare @user itself. Discovery reads the actor cache
// through an injected resolver during admission; painting stays pure.

/// One `$byAcct` entry, kept in PHP insertion order.
#[derive(Clone,Debug,PartialEq)]
pub(crate) struct BodyMention{pub(crate) acct:String,pub(crate) username:String,pub(crate) url:String}
/// Row lookups of ap_masto_resolve_bare_username, ap_local_user_by_username and
/// ap_masto_resolve_bsky_handle_actor (first actor_id column, as stored), plus
/// ap_masto_mention_from_actor. The PHP gates around them stay in the driver.
pub(crate) trait BodyMentionResolver{
 async fn remote_username_actor(&mut self,needle:&str)->Result<Option<String>>;
 async fn local_username_actor(&mut self,needle:&str)->Result<Option<String>>;
 async fn bridgy_handle_actor(&mut self,handle:&str)->Result<Option<String>>;
 async fn any_handle_actor(&mut self,handle:&str)->Result<Option<String>>;
 async fn mention(&mut self,actor:&str)->Result<BodyMention>;
}
/// Discovered linkify input. Only `discover_mentions` constructs it.
#[derive(Clone,Debug)]
pub(crate) struct DiscoveredBody{source:String,text:String,code:Vec<(String,String)>,mentions:Vec<BodyMention>,self_actor:String}
impl DiscoveredBody{
 pub(crate) fn source(&self)->&str{&self.source}
 #[cfg(test)] pub(crate) fn mentions(&self)->&[BodyMention]{&self.mentions}
}
/// The html_entity_decode boundary admission and painter both use to pick
/// this path; other text keeps the canonical declared-mention painter.
pub(crate) fn needs_discovery(plain:&str)->bool{crate::home_hydrate_ranked::html_entity_decode(super::card::php_trim(plain)).contains('@')}
/// PCRE UCP `\w`.
fn php_word(c:char)->bool{c=='_'||{let mut b=[0;4];static_regex!(r"^[\p{L}\p{N}]$").is_match(c.encode_utf8(&mut b))}}
fn ascii_word(c:char)->bool{c.is_ascii_alphanumeric()||c=='_'}
fn char_at(text:&str,at:usize)->Option<char>{text.get(at..).and_then(|rest|rest.chars().next())}
/// preg scan of `(^|[^class])BODY`: '^' first, then one prefix character,
/// otherwise advance one character; a match resumes at its end. Returns
/// (match start, body start, body end).
fn php_prefixed(text:&str,prefix:impl Fn(char)->bool,body:impl Fn(usize)->Option<usize>)->Vec<(usize,usize,usize)>{
 let mut out=Vec::new();let mut p=0;
 if let Some(end)=body(0){out.push((0,0,end));p=end;}
 while let Some(c)=char_at(text,p){
  if prefix(c){if let Some(end)=body(p+c.len_utf8()){out.push((p,p+c.len_utf8(),end));p=end;continue;}}
  p+=c.len_utf8();
 }
 out
}
fn ascii_run(text:&str,at:usize,ok:impl Fn(u8)->bool)->usize{text.as_bytes().get(at..).map(|b|b.iter().take_while(|c|ok(**c)).count()).unwrap_or(0)}
/// `@([A-Za-z0-9_]+)@([a-z0-9.-]+\.[a-z]{2,})`: greedy host, latest dot first.
fn body_user_host(text:&str,q:usize)->Option<usize>{
 if char_at(text,q)!=Some('@'){return None}
 let user=ascii_run(text,q+1,|c|c.is_ascii_alphanumeric()||c==b'_');if user==0||char_at(text,q+1+user)!=Some('@'){return None}
 let start=q+2+user;let run=ascii_run(text,start,|c|c.is_ascii_lowercase()||c.is_ascii_digit()||c==b'.'||c==b'-');
 (start+1..start+run).rev().filter(|d|text.as_bytes()[*d]==b'.').find_map(|d|{let tld=ascii_run(text,d+1,|c|c.is_ascii_lowercase());(tld>=2).then_some(d+1+tld)})
}
/// `@([A-Za-z0-9][A-Za-z0-9.-]*\.[A-Za-z]{2,})(?![A-Za-z0-9.@])`.
fn body_dotted(text:&str,q:usize)->Option<usize>{
 if char_at(text,q)!=Some('@')||!char_at(text,q+1).is_some_and(|c|c.is_ascii_alphanumeric()){return None}
 let start=q+2;let run=ascii_run(text,start,|c|c.is_ascii_alphanumeric()||c==b'.'||c==b'-');
 (start..start+run).rev().filter(|d|text.as_bytes()[*d]==b'.').find_map(|d|{let tld=ascii_run(text,d+1,|c|c.is_ascii_alphabetic());(tld>=2&&!char_at(text,d+1+tld).is_some_and(|c|c.is_ascii_alphanumeric()||c=='.'||c=='@')).then_some(d+1+tld)})
}
/// `@([A-Za-z0-9_]{2,32})(?![A-Za-z0-9_@.])`.
fn body_bare(text:&str,q:usize)->Option<usize>{
 if char_at(text,q)!=Some('@'){return None}
 let run=ascii_run(text,q+1,|c|c.is_ascii_alphanumeric()||c==b'_');
 ((2..=32).contains(&run)&&!matches!(char_at(text,q+1+run),Some('@'|'.'))).then_some(q+1+run)
}
/// ap_masto_unglue_bare_handles token `@([A-Za-z][\w]{1,40})(?![@\w.])`.
fn body_unglue(text:&str,q:usize)->Option<usize>{
 if char_at(text,q)!=Some('@')||!char_at(text,q+1).is_some_and(|c|c.is_ascii_alphabetic()){return None}
 let (mut end,mut count)=(q+2,0);while let Some(c)=char_at(text,end).filter(|c|php_word(*c)){end+=c.len_utf8();count+=1;}
 ((1..=40).contains(&count)&&!matches!(char_at(text,end),Some('@'|'.'))).then_some(end)
}
/// `(?<!BEHIND)PATTERN` replacement; candidates come from the original subject.
fn php_lookbehind_replace(text:&str,behind:impl Fn(char)->bool,pattern:&regex::Regex,replace:impl Fn(&str)->String)->String{
 let mut out=String::new();let mut copied=0;let mut p=0;
 // A rejected candidate retries one character later, inside its own span.
 while let Some(found)=pattern.find_at(text,p){
  let start=found.start();
  if text[..start].chars().next_back().is_some_and(&behind){p=start+char_at(text,start).map_or(1,char::len_utf8);continue}
  out.push_str(&text[copied..start]);out.push_str(&replace(found.as_str()));copied=found.end();p=copied;
 }
 out.push_str(&text[copied..]);out
}
const CMDR_NOVA:&str="https://mkultra.monster/users/cmdr_nova";
async fn mention_from_actor<R:BodyMentionResolver>(resolver:&mut R,actor:&str)->Result<BodyMention>{
 let actor=super::card::php_trim(actor).trim_end_matches('/');
 if actor==CMDR_NOVA||actor.starts_with("https://mkultra.monster/users/cmdr_nova/"){return Ok(BodyMention{acct:"cmdr_nova".into(),username:"cmdr_nova".into(),url:CMDR_NOVA.into()})}
 resolver.mention(actor).await
}
/// ap_masto_resolve_bare_username with its request-static memo.
async fn resolve_bare<R:BodyMentionResolver>(resolver:&mut R,memo:&mut std::collections::HashMap<String,Option<BodyMention>>,username:&str)->Result<Option<BodyMention>>{
 let username=super::card::php_trim(username);if username.is_empty(){return Ok(None)}
 let needle=username.to_ascii_lowercase();if let Some(value)=memo.get(&needle){return Ok(value.clone())}
 let value=if needle=="cmdr_nova"{Some(mention_from_actor(resolver,CMDR_NOVA).await?)}
 else if let Some(actor)=resolver.remote_username_actor(&needle).await?{Some(mention_from_actor(resolver,&actor).await?)}
 else if static_regex!(r"^[A-Za-z0-9_]{2,32}$").is_match(username)&&static_regex!(r"^[a-z][a-z0-9_]{1,29}$").is_match(&needle){
  match resolver.local_username_actor(&needle).await?.filter(|actor|!actor.is_empty()){Some(actor)=>Some(mention_from_actor(resolver,&actor).await?),None=>None}
 }else{None};
 memo.insert(needle,value.clone());Ok(value)
}
/// ap_masto_resolve_bsky_handle_actor; `?:` turns NULL (and "0") into ''.
async fn resolve_bsky<R:BodyMentionResolver>(resolver:&mut R,handle:&str)->Result<Option<String>>{
 let handle=super::card::php_trim(handle).trim_start_matches('@').to_ascii_lowercase();
 if handle.is_empty()||!handle.contains('.')||handle.ends_with(".brid.gy"){return Ok(None)}
 let found=|actor:Option<String>|actor.filter(|a|a!="0").map(|a|a.trim_end_matches('/').to_owned()).filter(|a|a.starts_with("https://"));
 if let Some(actor)=found(resolver.bridgy_handle_actor(&handle).await?){return Ok(Some(actor))}
 Ok(found(resolver.any_handle_actor(&handle).await?))
}
/// The two cache lookups alone, for the SQL differential.
#[cfg(test)]
pub(crate) async fn lookups_for_test<R:BodyMentionResolver>(resolver:&mut R,name:&str)->Result<(Option<String>,Option<String>)>{
 let mut memo=std::collections::HashMap::new();Ok((resolve_bsky(resolver,name).await?,resolve_bare(resolver,&mut memo,name).await?.map(|m|m.url)))
}
fn by_acct_set(list:&mut Vec<(String,BodyMention)>,key:String,value:BodyMention){if let Some(old)=list.iter_mut().find(|(k,_)|*k==key){old.1=value}else{list.push((key,value))}}
/// Preprocessing and discovery half of admin_linkify_body_html. `self_key` and
/// `self_actor` are vaak_actor_key()/vaak_actor_id() of the rendering request.
pub(crate) async fn discover_mentions<R:BodyMentionResolver>(plain:&str,owner_actor:&str,self_key:&str,self_actor:&str,resolver:&mut R)->Result<DiscoveredBody>{
 let self_actor=self_actor.trim_end_matches('/').to_owned();
 let mut text=crate::home_hydrate_ranked::html_entity_decode(super::card::php_trim(plain));
 let mut code=Vec::new();
 if text.is_empty(){return Ok(DiscoveredBody{source:plain.into(),text,code,mentions:vec![],self_actor})}
 text=static_regex!(r"`([^`\r\n]+)`").replace_all(&text,|cap:&regex::Captures|{
  if super::card::php_trim(&cap[1]).is_empty(){return cap[0].to_owned();}
  let key=format!("\u{1}C{}\u{1}",code.len());code.push((key.clone(),format!("<code>{}</code>",esc(&cap[1]))));key
 }).into_owned();
 text=php_lookbehind_replace(&text,|c|php_word(c)||matches!(c,'.'|'/'|':'|'@'),static_regex!(r"(?i)www\.[^\s<]+"),|tok|format!("https://{tok}"));
 // ap_normalize_bridgy_plain_mentions also folds @handle@bsky.app.
 text=static_regex!(r"@([A-Za-z0-9][A-Za-z0-9.-]*\.[A-Za-z]{2,})@(?:bsky\.brid\.gy|bsky\.app)\b").replace_all(&text,"@$1").into_owned();
 text=repair_at_tags(&text);text=unglue_mentions(&text,&[]);text=expand_url_mentions(&text);
 text=unglue_hashtags(&text);text=unglue_host_mentions(&text,owner_actor,&[]);
 let mut memo=std::collections::HashMap::new();
 // ap_masto_unglue_bare_handles: callbacks see the original subject.
 let tokens=php_prefixed(&text,|c|!(php_word(c)||c=='@'),|q|body_unglue(&text,q));
 let mut split=String::new();let mut copied=0;
 for(_,q,end)in tokens{
  let token=&text[q+1..end];
  if resolve_bare(resolver,&mut memo,token).await?.is_some(){continue}
  let offsets=token.char_indices().map(|(i,_)|i).collect::<Vec<_>>();
  for n in (2..offsets.len()).rev(){
   let(prefix,rest)=token.split_at(offsets[n]);
   if !rest.chars().next().is_some_and(|c|c!='_'&&php_word(c))||rest.contains(['@','.','_'])||prefix.ends_with('_'){continue}
   if resolve_bare(resolver,&mut memo,prefix).await?.is_some(){split.push_str(&text[copied..q]);split.push_str(&format!("@{prefix} {rest}"));copied=end;break}
  }
 }
 split.push_str(&text[copied..]);text=split;
 let mut by_acct:Vec<(String,BodyMention)>=Vec::new();
 for(_,q,end)in php_prefixed(&text,|c|!ascii_word(c),|q|body_user_host(&text,q)){
  let(user,host)=text[q+1..end].split_once('@').context("discovered user@host shape")?;let host=host.to_ascii_lowercase();
  let full=format!("{user}@{host}");let key=full.to_ascii_lowercase();
  if by_acct.iter().any(|(k,_)|*k==key){continue}
  if (host=="bsky.brid.gy"||host.ends_with(".brid.gy"))&&user.contains('.'){continue}
  let resolved=mention_from_actor(resolver,&format!("https://{host}/users/{}",query(user))).await?;
  by_acct_set(&mut by_acct,key,BodyMention{acct:full,username:resolved.username,url:resolved.url});
 }
 for(_,q,end)in php_prefixed(&text,|c|!(ascii_word(c)||c=='@'),|q|body_dotted(&text,q)){
  let handle=text[q+1..end].to_ascii_lowercase();
  if by_acct.iter().any(|(k,m)|*k==handle||m.username.to_ascii_lowercase()==handle||m.acct.to_ascii_lowercase()==handle){continue}
  let value=match resolve_bsky(resolver,&handle).await?{
   Some(actor)=>mention_from_actor(resolver,&actor).await?,
   None=>BodyMention{acct:handle.clone(),username:handle.clone(),url:format!("https://bsky.app/profile/{}",query(&handle))},
  };
  by_acct_set(&mut by_acct,handle,value);
 }
 let self_key=self_key.to_ascii_lowercase();
 for(_,q,end)in php_prefixed(&text,|c|!(ascii_word(c)||c=='@'),|q|body_bare(&text,q)){
  let user=&text[q+1..end];let key=user.to_ascii_lowercase();
  if by_acct.iter().any(|(_,m)|m.username.to_ascii_lowercase()==key){continue}
  if key==self_key||(self_key=="cmdr_nova"&&key=="cmdr-nova"){by_acct_set(&mut by_acct,self_key.clone(),BodyMention{acct:self_key.clone(),username:self_key.clone(),url:self_actor.clone()});continue}
  if let Some(resolved)=resolve_bare(resolver,&mut memo,user).await?.filter(|m|!m.url.is_empty()&&m.url!="0"){by_acct_set(&mut by_acct,resolved.acct.to_ascii_lowercase(),resolved);continue}
  by_acct_set(&mut by_acct,format!("__bare:{key}"),BodyMention{acct:user.into(),username:user.into(),url:format!("search://{user}")});
 }
 Ok(DiscoveredBody{source:plain.into(),text,code,mentions:by_acct.into_iter().map(|(_,m)|m).collect(),self_actor})
}
/// PHP urlencode, as http_build_query writes values.
fn form_urlencode(value:&str)->String{let mut out=String::new();for b in value.bytes(){if b.is_ascii_alphanumeric()||b"-_.".contains(&b){out.push(b as char)}else if b==b' '{out.push('+')}else{out.push_str(&format!("%{b:02X}"))}}out}
/// The `$profileHref` closure: search, own outbox, then admin_profile_app_href.
fn discovered_profile_href(url:&str,view:&str,self_actor:&str)->String{
 if let Some(user)=url.strip_prefix("search://"){return format!("?view=search&q={}&type=accounts&resolve=1",query(&format!("@{user}")))}
 let own=url.trim_end_matches('/');if !own.is_empty()&&(own==self_actor||own.starts_with(&format!("{self_actor}/"))){return "?view=outbox".into()}
 let actor=super::card::php_trim(url).trim_end_matches('/');if actor.is_empty(){return "/vaak/?view=home".into()}
 if let Some(cap)=static_regex!(r"^https://mkultra\.monster/users/([A-Za-z0-9_]+)$").captures(actor){
  let path=format!("/vaak/users/{}",query(&cap[1].to_ascii_lowercase()));
  return if view!="home"{format!("{path}?from={}",form_urlencode(view))}else{path}
 }
 format!("/vaak/?view=remote_profile&actor={}&from={}",form_urlencode(actor),form_urlencode(view))
}
/// Pure painting half of admin_linkify_body_html for a discovered list.
/// Unmatched `@` tokens stay escaped text, exactly as PHP leaves them.
pub(crate) fn linked_discovered(body:&DiscoveredBody,view:&str)->Result<String>{
 if body.text.is_empty(){return Ok(String::new())}
 let view={let view=view.chars().filter(|c|c.is_ascii_lowercase()||*c=='_').collect::<String>();if view.is_empty(){"home".to_owned()}else{view}};
 let plain=php_lookbehind_replace(&body.text,|c|php_word(c)||matches!(c,'.'|'/'|':'|'@'|'%'|'-'),static_regex!(r#"(?i)(?:[a-z0-9-]+\.)+[a-z]{2,}/[^\s<>"']+"#),|tok|if tok.contains("...")||tok.contains('…')||tok.contains(".."){tok.to_owned()}else{format!("https://{tok}")});
 let mut hold:Vec<(String,String,bool)>=Vec::new();
 let protected=static_regex!(r"(?i)https?://[^\s<]+").replace_all(&plain,|cap:&regex::Captures|{
  let raw=&cap[0];let core=raw.trim_end_matches(['.',',',';',':','!','?',')',']']);
  let(raw,trail)=if core.len()<raw.len()&&!core.is_empty()&&!core.ends_with('/'){(core,&raw[core.len()..])}else{(raw,"")};
  let key=format!("\u{1}U{}\u{1}",hold.len());hold.push((key.clone(),raw.to_owned(),true));format!("{key}{trail}")
 }).into_owned();
 let mut escaped=esc(&protected);
 if !body.mentions.is_empty(){
  let mut list=body.mentions.clone();list.sort_by_key(|m|std::cmp::Reverse(m.acct.len()));
  let local_actor=static_regex!(r"(?i)^https://mkultra\.monster/users/[A-Za-z0-9_]+$");
  for m in list.iter().filter(|m|m.acct.contains('@')){
   let href=esc(&discovered_profile_href(&m.url,&view,&body.self_actor));
   let local=local_actor.is_match(&m.url);
   let label=if local{if m.username.is_empty(){m.acct.split('@').next().unwrap_or("").to_owned()}else{m.username.clone()}}else{m.acct.clone()};
   escaped=escaped.replace(&format!("@{}",esc(&m.acct)),&format!("<a class=\"mention\" href=\"{href}\">@{}</a>",esc(&label)));
  }
  escaped=static_regex!(r#"(?s)<a class="mention"[^>]*>.*?</a>"#).replace_all(&escaped,|cap:&regex::Captures|{let key=format!("\u{1}M{}\u{1}",hold.len());hold.push((key.clone(),cap[0].to_owned(),false));key}).into_owned();
  let mut by_user:Vec<(String,Option<&BodyMention>)>=Vec::new();
  for m in &list{
   let key=m.username.to_ascii_lowercase();
   match by_user.iter_mut().find(|(u,_)|*u==key){
    None=>by_user.push((key,Some(m))),
    Some((_,slot))=>if let Some(previous)=*slot{if previous.acct.contains('@')&&!m.acct.contains('@'){*slot=Some(m)}else if previous.url!=m.url{*slot=None}},
   }
  }
  for m in by_user.into_iter().filter_map(|(_,m)|m){
   let uname=esc(&m.username);
   let link=format!("<a class=\"mention\" href=\"{}\">@{uname}</a>",esc(&discovered_profile_href(&m.url,&view,&body.self_actor)));
   let needle=format!("@{uname}");let mut out=String::new();let mut copied=0;
   let hits=php_prefixed(&escaped,|c|!(ascii_word(c)||c=='/'),|q|escaped.get(q..).filter(|rest|rest.starts_with(&needle)).map(|_|q+needle.len()).filter(|end|!char_at(&escaped,*end).is_some_and(|c|ascii_word(c)||c=='.'||c=='@')));
   // preg_replace would read `$n` / `\n` references inside this replacement.
   if !hits.is_empty()&&link.contains(['$','\\']){bail!("PHP fallback: discovered mention replacement has preg references")}
   for(_,q,end)in hits{out.push_str(&escaped[copied..q]);out.push_str(&link);copied=end;}
   out.push_str(&escaped[copied..]);escaped=out;
  }
 }
 escaped=static_regex!(r"(^|[^A-Za-z0-9_&/%])#([\p{L}\p{N}_]{1,100})").replace_all(&escaped,|cap:&regex::Captures|{
  let href=format!("?view=search&q={}&type=statuses",query(&format!("#{}",&cap[2])));
  let key=format!("\u{1}H{}\u{1}",hold.len());hold.push((key.clone(),format!("<a class=\"hashtag\" href=\"{}\">#{}</a>",esc(&href),esc(&cap[2])),false));format!("{}{key}",&cap[1])
 }).into_owned();
 let mut keys=hold.iter().collect::<Vec<_>>();keys.sort_by_key(|(key,..)|std::cmp::Reverse(key.len()));
 for(key,value,url)in keys{
  let value=if *url{if is_status_url(value){format!("<a class=\"status-link\" href=\"{}\">{}</a>",esc(&super::actions::in_app_status_href(value,&view)),esc(value))}else{format!("<a class=\"ext-link\" href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer\">{}</a>",esc(value),esc(value))}}else{value.clone()};
  escaped=escaped.replace(key.as_str(),&value);
 }
 for(key,value)in &body.code{escaped=escaped.replace(key.as_str(),value);}
 Ok(escaped)
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn actual_php_link_code_and_unicode_hashtag_components_match() {
        let file = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/migration-library-component-capture.php");
        let output = std::process::Command::new(std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/php-policy-test")).arg(file).output().unwrap();
        assert!(output.status.success(), "{}", String::from_utf8_lossy(&output.stderr));
        let reference: serde_json::Value = serde_json::from_slice(&output.stdout).unwrap();
        let mut count = 0;
        for (name, fixture) in reference["links"].as_object().unwrap() {
            let text = fixture["plain"].as_str().unwrap();
            if text.contains('@') { continue; }
            assert_eq!(linked_text(text, fixture["view"].as_str().unwrap()).unwrap(), fixture["html"].as_str().unwrap(), "actual PHP body {name}");
            count += 1;
        }
        for (name,fixture) in reference["emojis"].as_object().unwrap() {
            let map = fixture["map"].as_object().unwrap().iter().map(|(key,value)|(key.clone(),value.as_str().unwrap().to_owned())).collect();
            assert_eq!(emojis(fixture["text"].as_str().unwrap(),&map,fixture["display"].as_bool().unwrap()),fixture["html"].as_str().unwrap(),"actual PHP emoji {name}");
        }
        for (name,fixture) in reference["facets"].as_object().unwrap() {
            assert_eq!(facets(fixture["input"].as_str().unwrap(),&fixture["status"]),fixture["html"].as_str().unwrap(),"actual PHP facet {name}");
        }
        for (name,fixture) in reference["mentions"].as_object().unwrap() {
            assert_eq!(linked_text_mentions(fixture["text"].as_str().unwrap(),"bookmarks",fixture["mentions"].as_array().unwrap(),"https://mkultra.monster/users/fixture").unwrap(),fixture["html"].as_str().unwrap(),"actual PHP declared mentions {name}");
        }
        for (name,fixture) in reference["dialects"].as_object().unwrap() {
            let text=fixture["text"].as_str().unwrap();
            let output=match fixture["kind"].as_str().unwrap(){
                "bridgy"=>repair_bridgy(text),"tags"=>repair_at_tags(text),
                "mentions"=>unglue_mentions(text,&fixture["known"].as_array().unwrap().iter().map(|v|v.as_str().unwrap().to_owned()).collect::<Vec<_>>()),
                "hashtags"=>unglue_hashtags(text),"expand"=>expand_url_mentions(text),"host"=>unglue_host_mentions(text,"https://mkultra.monster/users/fixture",&fixture["extra"].as_array().unwrap().iter().map(|v|v.as_str().unwrap().to_owned()).collect::<Vec<_>>()),_=>panic!("unknown actual dialect"),
            };
            assert_eq!(output,fixture["output"].as_str().unwrap(),"actual PHP dialect {name}");
        }
        for (name,fixture) in reference["dialect_html"].as_object().unwrap(){
            let repaired=repair_known_text(fixture["text"].as_str().unwrap(),"https://mkultra.monster/users/fixture");
            assert_eq!(linked_text_mentions(&repaired,"bookmarks",fixture["mentions"].as_array().unwrap(),"https://mkultra.monster/users/fixture").unwrap(),fixture["html"].as_str().unwrap(),"actual complete PHP dialect {name}");
        }
        for fixture in reference["status_links"].as_array().unwrap(){assert_eq!(linked_text(fixture["url"].as_str().unwrap(),"bookmarks").unwrap(),fixture["html"].as_str().unwrap(),"actual PHP status URL classification");}
        for fixture in reference["html_plain"].as_array().unwrap(){assert_eq!(html_to_plain(fixture["input"].as_str().unwrap()),fixture["plain"].as_str().unwrap(),"actual PHP HTML-to-text");}
        for fixture in reference["raw_quotes"].as_array().unwrap(){let input=fixture["input"].as_str().unwrap();assert_eq!(looks_like_as2(input),fixture["looks"].as_bool().unwrap(),"actual AS2 recognition");assert_eq!(scrub_as2(input),fixture["scrub"].as_str().unwrap(),"actual AS2 scrub");assert_eq!(strip_raw_quotes(input,fixture["quoted"].as_str()),fixture["plain"].as_str().unwrap(),"actual raw quote strip");}
        assert_eq!(count, 8);
        assert!(linked_text("@uncachedperson", "bookmarks").is_err());
    }
    /// The PHP fixture's rows, read with the same SQL semantics in memory.
    pub(crate) struct FixtureResolver(pub(crate) serde_json::Value);
    impl FixtureResolver {
        fn first(&self,table:&str,keep:impl Fn(&serde_json::Value)->bool,latest:bool)->Option<String>{
            let mut rows=self.0[table].as_array().unwrap().iter().filter(|row|keep(row)).collect::<Vec<_>>();
            if latest{rows.sort_by(|a,b|b["updated_at"].as_str().cmp(&a["updated_at"].as_str()));}
            rows.first().map(|row|row["actor_id"].as_str().unwrap_or("").to_owned())
        }
    }
    impl BodyMentionResolver for FixtureResolver {
        async fn remote_username_actor(&mut self,needle:&str)->Result<Option<String>>{Ok(self.first("remote_actors",|r|r["username"].as_str().map(str::to_lowercase).as_deref()==Some(needle),true))}
        async fn local_username_actor(&mut self,needle:&str)->Result<Option<String>>{Ok(self.first("ap_users",|r|r["username"].as_str().map(str::to_lowercase).as_deref()==Some(needle)&&r["disabled_at"].is_null(),false))}
        async fn bridgy_handle_actor(&mut self,handle:&str)->Result<Option<String>>{Ok(self.first("remote_actors",|r|{let host=r["host"].as_str().unwrap_or("");r["username"].as_str().map(str::to_lowercase).as_deref()==Some(handle)&&(host.ends_with(".brid.gy")||host=="brid.gy"||r["actor_id"].as_str().unwrap_or("").starts_with("https://bsky.brid.gy/"))},true))}
        async fn any_handle_actor(&mut self,handle:&str)->Result<Option<String>>{Ok(self.first("remote_actors",|r|r["username"].as_str().map(str::to_lowercase).as_deref()==Some(handle),true))}
        async fn mention(&mut self,actor:&str)->Result<BodyMention>{
            if let Some(known)=self.0["mentions"].get(actor){return Ok(BodyMention{acct:known["acct"].as_str().unwrap().into(),username:known["username"].as_str().unwrap().into(),url:known["url"].as_str().unwrap().into()})}
            let url=reqwest::Url::parse(actor).unwrap();let user=url.path().trim_end_matches('/').rsplit('/').next().unwrap().to_owned();
            Ok(BodyMention{acct:format!("{user}@{}",url.host_str().unwrap()),username:user,url:actor.into()})
        }
    }
    /// Actual admin_linkify_body_html own discovery (raw outbox cards) with its
    /// real bare/Bluesky SQL helpers on fixture rows and a mapped account helper.
    #[tokio::test]
    async fn actual_php_raw_body_mention_discovery(){
        let dir=std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin");
        let output=std::process::Command::new(dir.join("php-policy-test")).arg(dir.join("profile-raw-mention-reference.php")).output().unwrap();
        assert!(output.status.success(),"{}",String::from_utf8_lossy(&output.stderr));
        let reference:serde_json::Value=serde_json::from_slice(&output.stdout).unwrap();
        let cases=reference["cases"].as_array().unwrap();assert!(cases.len()>=40,"{}",cases.len());
        for case in cases{
            let text=case["text"].as_str().unwrap();let actor=case["self_actor"].as_str().unwrap();
            let mut resolver=FixtureResolver(reference["fixture"].clone());
            let body=discover_mentions(text,actor,case["self_key"].as_str().unwrap(),actor,&mut resolver).await.unwrap();
            assert_eq!(body.source(),text);
            assert_eq!(linked_discovered(&body,case["view"].as_str().unwrap()).unwrap(),case["html"].as_str().unwrap(),"actual PHP raw discovery {}",case["name"]);
        }
    }
}
