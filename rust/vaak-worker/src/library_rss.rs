//! Dedicated Library RSS normalization and actions use fresh owner-joined rows.
use anyhow::{bail,Result};
use serde_json::{json,Value};
use super::{actions::{esc,query},card::CardFacts};
fn s<'a>(value:&'a Value,key:&str)->&'a str{value.get(key).and_then(Value::as_str).unwrap_or("")}
fn n(value:&Value,key:&str)->i64{value.get(key).and_then(Value::as_i64).or_else(||s(value,key).parse().ok()).unwrap_or(0)}
fn width(character:char)->usize{
    static RANGES:std::sync::OnceLock<Vec<[u32;3]>>=std::sync::OnceLock::new();
    let ranges=RANGES.get_or_init(||serde_json::from_str(include_str!("php_mb_width_ranges.json")).unwrap());
    let point=character as u32;
    ranges.iter().find(|range|point>=range[0]&&point<=range[1]).map(|range|range[2] as usize).unwrap_or(1)
}
pub(super) fn trim_width(text:&str,max:usize)->String{
    if text.chars().map(width).sum::<usize>()<=max{return text.into();}
    let mut out=String::new();let mut used=0;
    for character in text.chars(){let count=width(character);if used+count>max.saturating_sub(1){break;}out.push(character);used+=count;}
    out.push('…');out
}
fn clean_summary(text:&str)->String{
    let text=text.split_whitespace().collect::<Vec<_>>().join(" ");
    let text=regex::Regex::new(r"(?i)\s*submitted by\s+/?u/\S+.*").unwrap().replace(&text,"");
    let text=regex::Regex::new(r"(?i)\s*\[link\]\s*\[comments\]\s*$").unwrap().replace(&text,"");
    let text=text.trim();if text.to_lowercase().starts_with("submitted by")||text.to_lowercase().starts_with("[link]"){String::new()}else{text.into()}
}
pub(super) fn clean_feed_title(text:&str)->String{
    let title=super::card::php_trim(&crate::html_entities::decode(text)).to_owned();if title.is_empty(){return "RSS".into();}
    let cleaned=regex::Regex::new(r"(?i)[\s\-–—|:]*\b(?:articles?|rss|atom|feeds?|blog)\s*$").unwrap().replace(&title,"");
    let cleaned=cleaned.trim_matches(|ch:char|" \t\n\r\0\u{b}\\-–—|:".contains(ch));if cleaned.is_empty(){title}else{cleaned.into()}
}
fn image_url(text:&str)->String{
    let image=crate::html_entities::decode(text).trim().to_owned();let Ok(url)=reqwest::Url::parse(&image)else{return String::new();};
    if url.scheme()!="https"||url.host_str()==Some("external-preview.redd.it"){return String::new();}
    if let Some(capture)=regex::Regex::new(r"(?i)^https://preview\.redd\.it/([A-Za-z0-9]+)(\.[A-Za-z0-9]+)(?:\?|$)").unwrap().captures(&image){return format!("https://i.redd.it/{}{}",&capture[1],&capture[2]);}
    if url.host_str()==Some("preview.redd.it")&&url.query_pairs().any(|(key,value)|key.eq_ignore_ascii_case("width")&&value.parse::<u64>().is_ok_and(|width|width<640)){
        let mut image=regex::Regex::new(r"(?i)([?&])width=[0-9]+").unwrap().replace_all(&image,"${1}width=960").into_owned();
        for pattern in [r"(?i)([?&])height=[0-9]+",r"(?i)([?&])crop=[^&]*"]{image=regex::Regex::new(pattern).unwrap().replace_all(&image,"").into_owned();}
        image=image.replace("?&","?");image=regex::Regex::new("&&+").unwrap().replace_all(&image,"&").into_owned();return image.trim_end_matches(['?','&']).into();
    }image
}
pub(super) fn normalize(row:&Value,owner:i64)->Result<Value>{
    if owner<1||n(row,"owner_user_id")!=owner||n(row,"id")<1||n(row,"feed_id")<1{bail!("RSS source requires a fresh current-owner subscription row");}
    let id=n(row,"id");let feed=n(row,"feed_id");let sid=format!("rss:{id}");let title=super::card::php_trim(s(row,"title"));let summary=clean_summary(s(row,"summary_text"));let url=s(row,"url").trim();let image=image_url(s(row,"image_url"));let feed_title=clean_feed_title(s(row,"feed_title"));
    let host=reqwest::Url::parse(url).ok().and_then(|url|url.host_str().map(str::to_owned)).unwrap_or_default();
    let reddit=host.ends_with("reddit.com")||host.ends_with("redd.it");let image_host=reqwest::Url::parse(&image).ok().and_then(|url|url.host_str().map(str::to_owned)).unwrap_or_default();
    let reddit_image=image_host=="i.redd.it"||image_host.contains("preview.redd.it")||image_host=="i.imgur.com"||image_host.contains("redd.it");
    let length=summary.chars().count();let media_forward=!image.is_empty()&&if reddit{reddit_image&&length<72}else{(reddit_image&&length<72)||summary.is_empty()||length<=96};
    let mut pieces=Vec::new();if !title.is_empty(){pieces.push(title.to_owned());}if !summary.is_empty()&&summary!=title{pieces.push(trim_width(&summary,500));}let text=pieces.join("\n\n");
    let content=if text.is_empty(){String::new()}else{format!("<p>{}</p>",esc(&text).replace('\n',"<br>\n"))};
    let created=row.get("published_at").or_else(||row.get("ingested_at")).cloned().unwrap_or_else(||json!(chrono::Utc::now().to_rfc3339()));
    let favicon=if !host.is_empty(){format!("https://icons.duckduckgo.com/ip3/{}.ico",host.strip_prefix("www.").unwrap_or(&host))}else{String::new()};
    let avatar=if !favicon.is_empty(){favicon}else if s(row,"feed_favicon").starts_with("http://")||s(row,"feed_favicon").starts_with("https://"){s(row,"feed_favicon").into()}else{"https://mkultra.monster/img/avatar/default.jpg".into()};
    let acct=feed_title.to_lowercase().chars().filter(|ch|ch.is_ascii_alphanumeric()||"._-".contains(*ch)).take(30).collect::<String>();let acct=if acct.is_empty(){"rss".into()}else{acct};
    let media=if media_forward{vec![json!({"id":format!("{sid}-img"),"type":"image","url":image,"preview_url":image,"remote_url":image,"mediaType":"image/*","description":null,"blurhash":null})]}else{Vec::new()};
    let card=if !url.is_empty()&&media.is_empty()&&matches!(reqwest::Url::parse(url).ok().map(|url|url.scheme().to_owned()).as_deref(),Some("http"|"https")){json!({"url":url,"title":if title.is_empty(){if host.is_empty(){url}else{&host}}else{title},"description":summary,"type":"link","author_name":feed_title,"author_url":"","provider_name":host,"provider_url":if host.is_empty(){String::new()}else{format!("https://{host}/")},"html":"","width":0,"height":0,"image":if image.is_empty(){Value::Null}else{json!(image)},"embed_url":"","blurhash":null})}else{Value::Null};
    Ok(json!({"id":sid,"uri":if url.is_empty(){&sid}else{url},"url":if url.is_empty(){&sid}else{url},"created_at":created,"content":content,"sensitive":false,"spoiler_text":"","visibility":"public","source":"rss","vaak_rss_item_id":id,"vaak_rss_feed_id":feed,"media_attachments":media,"card":card,"account":{"id":format!("rss-feed:{feed}"),"username":acct,"acct":feed_title,"display_name":feed_title,"url":row.get("feed_site_url").unwrap_or(&json!(s(row,"feed_url"))),"uri":s(row,"feed_url"),"avatar":avatar,"avatar_static":avatar}}))
}
fn hidden(name:&str,value:&str)->String{format!("<input type=\"hidden\" name=\"{name}\" value=\"{}\">",esc(value))}
pub(super) fn actions(status:&Value,facts:&CardFacts)->Result<String>{
    let row=facts.rss_row.as_ref().ok_or_else(||anyhow::anyhow!("RSS action source missing"))?;let owner=n(row,"owner_user_id");let id=n(row,"id");let feed=n(row,"feed_id");if owner!=facts.owner_id||owner<1||id<1{bail!("RSS action owner mismatch");}
    let sid=format!("rss:{id}");let view=&facts.return_view;let base=format!("?view={}",query(view));let quoted=facts.rss_quoted;
    let mut html="<div class=\"tweet-actions\">".to_owned();
    for (action,key,value,on,title,label,icon,picker,pressed) in [
        ("rss_quote","item_id",id.to_string(),quoted,if quoted{"Remove quote boost (VAAK only)"}else{"Quote boost (VAAK only)"},if quoted{"Remove quote boost"}else{"Quote boost"},"quotes",false,true),
        (if facts.boosted{"unreblog_status"}else{"reblog_status"},"status_id",sid.clone(),facts.boosted,if facts.boosted{"Undo boost (VAAK only)"}else{"Boost (VAAK only)"},if facts.boosted{"Undo boost"}else{"Boost"},"repeat",false,true),
        (if facts.favourited{"unfavourite_status"}else{"favourite_status"},"status_id",sid.clone(),facts.favourited,if facts.favourited{"Unlike"}else{"Like (VAAK only)"},if facts.favourited{"Unlike"}else{"Like"},"heart",false,false),
        (if facts.bookmarked{"unbookmark_status"}else{"bookmark_status"},"status_id",sid.clone(),facts.bookmarked,if facts.bookmarked{"Bookmark folders"}else{"Bookmark"},if facts.bookmarked{"Bookmark folders"}else{"Bookmark"},"bookmark-simple",true,false)]{
        html.push_str(&format!("<form method=\"post\" action=\"{}\" style=\"display:inline\">{}{}{}{}",esc(&base),hidden("csrf",&facts.csrf),hidden("action",action),hidden("return_view",view),hidden(key,&value)));
        if key=="status_id"{html.push_str(&hidden("object_id",&sid));}
        html.push_str(&format!("<button class=\"icon-btn{}\" type=\"submit\" title=\"{title}\" aria-label=\"{label}\"{}{}><i class=\"ph{} ph-{icon}\" aria-hidden=\"true\"></i></button></form>",if on{" on"}else{""},if pressed{format!(" aria-pressed=\"{}\"",if on{"true"}else{"false"})}else{String::new()},if picker{format!(" data-bm-picker=\"{}\"",if on{"1"}else{"0"})}else{String::new()},if on&&matches!(icon,"heart"|"bookmark-simple"){"-fill"}else{""}));
    }
    let uri=s(status,"uri");let open=if uri.starts_with("http://")||uri.starts_with("https://"){format!("<a class=\"menu-action\" href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer\">Open</a>",esc(uri))}else{String::new()};
    html.push_str(&format!("<details class=\"post-action-menu\"><summary class=\"icon-btn\" title=\"More actions\" aria-label=\"More actions\">⋯</summary><div class=\"post-action-menu__body\">{open}<form method=\"post\" action=\"?view={}\" onsubmit=\"return confirm('Remove this RSS feed from your Home mix?');\">{}{}{}{}<button class=\"menu-action\" type=\"submit\" style=\"color:var(--danger)\">Remove feed</button></form></div></details></div>",esc(view),hidden("csrf",&facts.csrf),hidden("action","rss_remove_feed"),hidden("return_view",view),hidden("feed_id",&feed.to_string())));Ok(html)
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn actual_php_rss_projection_and_unicode_width_match() {
        let file=std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/migration-library-component-capture.php");
        let output=std::process::Command::new(std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/php-policy-test")).arg(file).output().unwrap();
        assert!(output.status.success(),"{}",String::from_utf8_lossy(&output.stderr));
        let reference:Value=serde_json::from_slice(&output.stdout).unwrap();
        for fixture in reference["widths"].as_array().unwrap(){let text=s(fixture,"text");assert_eq!(text.chars().map(width).sum::<usize>(),n(fixture,"width") as usize);assert_eq!(trim_width(text,500),s(fixture,"trim"));}
        for fixture in reference["rss_titles"].as_array().unwrap(){assert_eq!(clean_feed_title(s(fixture,"input")),s(fixture,"title"),"actual PHP Unicode-safe RSS title");}
        for fixture in reference["rss_summaries"].as_array().unwrap(){assert_eq!(clean_summary(s(fixture,"input")),s(fixture,"summary"),"actual PHP intentional RSS Unicode summary collapse");}
        for (name,fixture) in reference["rss"].as_object().unwrap(){
            let native=normalize(&fixture["row"],1).unwrap();let expected=&fixture["status"];
            for key in ["id","uri","url","created_at","content","source","vaak_rss_item_id","vaak_rss_feed_id","media_attachments","card"]{assert_eq!(native[key],expected[key],"actual RSS {name} field {key}");}
            for key in ["id","username","acct","display_name","uri","url","avatar","avatar_static"]{assert_eq!(native["account"][key],expected["account"][key],"actual RSS {name} account {key}");}
            assert!(normalize(&fixture["row"],2).is_err());
        }
    }
}
