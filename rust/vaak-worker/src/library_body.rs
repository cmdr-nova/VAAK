//! Canonical text components for Library; DB-resolved mention and emoji facts
//! must be supplied by the authenticated page assembler before those branches.
// Immutable compiled patterns only; request text, source facts and results
// remain uncached. Each callsite retains exactly its original regex literal.
macro_rules! static_regex { ($pattern:literal) => {{
 static REGEX: std::sync::OnceLock<regex::Regex> = std::sync::OnceLock::new();
 REGEX.get_or_init(||regex::Regex::new($pattern).unwrap())
 }}; }

use anyhow::{bail, Result};
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
                        output.push_str(&format!("<a class=\"status-link\" href=\"{}\">{}</a>", esc(&format!("?view=status&object={}&from={}", query(&target), query(view))), esc(&target)));
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
}
