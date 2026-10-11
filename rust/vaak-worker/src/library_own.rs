//! Own-note actions preserve PHP targets and CSRF using current owner DB facts.
use anyhow::{bail, Result};
use super::{actions::{esc,query,Counts,compact_count},card::CardFacts};

#[derive(Debug, Clone)]
pub(crate) struct OwnFacts {
    pub local_id: i64,
    pub status_id: String,
    pub plain: String,
    pub spoiler: String,
    pub sensitive: bool,
    pub visibility: String,
    pub pinned: bool,
    pub counts: Counts,
}
fn hidden(name: &str, value: &str) -> String { format!("<input type=\"hidden\" name=\"{name}\" value=\"{}\">",esc(value)) }
fn count(n: i64) -> String { let value=compact_count(n); if value.is_empty(){String::new()}else{format!("<span class=\"action-count\" aria-hidden=\"true\">{value}</span>")} }
fn b64(bytes: &[u8]) -> String {
    let alphabet=b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/"; let mut output=String::new();
    for chunk in bytes.chunks(3) {
        let first=chunk[0] as u32; let second=chunk.get(1).copied().unwrap_or(0) as u32; let third=chunk.get(2).copied().unwrap_or(0) as u32;
        let value=(first<<16)|(second<<8)|third;
        for index in 0..4 { if index>chunk.len(){output.push('=');}else{output.push(alphabet[((value>>(18-6*index))&63) as usize] as char);} }
    }
    output
}
pub(super) fn render(note: &str, actor: &str, facts: &CardFacts, own: &OwnFacts) -> Result<String> {
    render_core(note,actor,facts,own,"")
}

pub(crate) fn render_direct(note:&str,actor:&str,facts:&CardFacts,own:&OwnFacts,from:&str)->Result<String>{render_core(note,actor,facts,own,from)}
fn render_core(note:&str,actor:&str,facts:&CardFacts,own:&OwnFacts,from:&str)->Result<String>{
    if facts.owner_id < 1 || actor.trim_end_matches('/') != facts.owner_actor.trim_end_matches('/')
        || !note.starts_with(&format!("{}/notes/",facts.owner_actor.trim_end_matches('/'))) {
        bail!("Own Library actions require the current authenticated note owner");
    }
    let view=&facts.return_view; let mut action_url=format!("?view={}",query(view));
    if view=="status"{action_url.push_str(&format!("&object={}",query(note)));if !from.is_empty(){action_url.push_str(&format!("&from={}",query(from)));}}
    let numeric=!own.status_id.is_empty() && own.status_id.bytes().all(|byte|byte.is_ascii_digit());
    let can_share=!matches!(own.visibility.as_str(),"private"|"direct"|"followers");
    let cw=super::actions::reply_suffix(&[],&own.spoiler,own.sensitive);
    let mut html=format!("<div class=\"tweet-actions\"><a class=\"icon-btn\" href=\"?view={}&amp;compose=1&amp;reply_to={}{}\" title=\"Reply\" aria-label=\"Reply\"><i class=\"ph ph-arrow-bend-up-left\" aria-hidden=\"true\"></i></a>",esc(view),query(note),cw);
    let form = |action: &str, target_actor: bool| -> String {
        let mut output=format!("<form method=\"post\" action=\"{}\" style=\"display:inline\">",esc(&action_url));
        if !facts.csrf.is_empty(){output.push_str(&hidden("csrf",&facts.csrf));}
        output.push_str(&hidden("action",action));output.push_str(&hidden("return_view",view));output.push_str(&hidden("status_id",&own.status_id));output.push_str(&hidden("object_id",note));
        if target_actor && !actor.is_empty(){output.push_str(&hidden("target_actor",actor));} output
    };
    let label = |name: &str,n:i64| -> String { if n>0{format!("{name} ({n})")}else{name.into()} };
    if can_share && numeric {
        let name=if facts.boosted{"Undo boost"}else{"Boost"}; let n=own.counts.reblogs;
        html.push_str(&form(if facts.boosted{"unreblog_status"}else{"reblog_status"},true));
        html.push_str(&format!("<button class=\"icon-btn{}\" type=\"submit\" data-own-eng=\"1\" data-eng-count=\"{n}\" title=\"{name}\" aria-label=\"{}\"><i class=\"ph ph-repeat\" aria-hidden=\"true\"></i>{}</button></form>",if facts.boosted{" on"}else{""},label(name,n),count(n)));
    }
    if can_share {
        let n=own.counts.quotes;
        html.push_str(&format!("<a class=\"icon-btn\" href=\"?view={}&amp;compose=1&amp;quote_object={}{}\" data-own-eng=\"1\" data-eng-count=\"{n}\" title=\"Quote\" aria-label=\"{}\"><i class=\"ph ph-quotes\" aria-hidden=\"true\"></i>{}</a>",esc(view),query(note),if own.status_id.is_empty(){String::new()}else{format!("&amp;quote_status_id={}",query(&own.status_id))},label("Quote",n),count(n)));
    }
    if numeric {
        for (action,on,n,name,icon,target,picker) in [
            (if facts.favourited{"unfavourite_status"}else{"favourite_status"},facts.favourited,own.counts.favourites,if facts.favourited{"Unlike"}else{"Like"},"heart",true,false),
            (if facts.bookmarked{"unbookmark_status"}else{"bookmark_status"},facts.bookmarked,own.counts.bookmarks,if facts.bookmarked{"Bookmark folders"}else{"Bookmark"},"bookmark-simple",false,true)] {
            html.push_str(&form(action,target));
            html.push_str(&format!("<button class=\"icon-btn{}\" type=\"submit\" data-own-eng=\"1\" data-eng-count=\"{n}\"{} title=\"{name}\" aria-label=\"{}\"><i class=\"ph{} ph-{icon}\" aria-hidden=\"true\"></i>{}</button></form>",if on{" on"}else{""},if picker{format!(" data-bm-picker=\"{}\"",if on{"1"}else{"0"})}else{String::new()},label(name,n),if on{"-fill"}else{""},count(n)));
        }
    }
    if own.local_id>0 {
        html.push_str(&format!("<form method=\"post\" action=\"{}\" style=\"display:inline\" data-vaak-ajax-delete=\"1\">{}{}{}{}{}<button class=\"icon-btn\" type=\"submit\" title=\"Delete post\" aria-label=\"Delete post\" style=\"color:var(--danger)\"><i class=\"ph ph-trash\" aria-hidden=\"true\"></i></button></form>",esc(&action_url),hidden("csrf",&facts.csrf),hidden("action","delete_status"),hidden("return_view",view),hidden("local_id",&own.local_id.to_string()),hidden("note_id",note)));
    }
    let mut edit_href=if view=="status"{format!("?view=status&object={}&from=home&compose=1&edit_note={}",query(note),query(note))}else{format!("?view={}&compose=1&edit_note={}",query(view),query(note))};
    if view=="remote_profile" {edit_href.push_str(&format!("&actor={}&from=profile",query(actor.trim_end_matches('/'))));}
    html.push_str(&format!("<details class=\"post-action-menu\"><summary class=\"icon-btn\" title=\"More actions\" aria-label=\"More actions\">⋯</summary><div class=\"post-action-menu__body\"><a class=\"menu-action\" href=\"{}\">Open</a><a class=\"menu-action js-edit-post\" href=\"{}\" data-note-id=\"{}\" data-return-view=\"{}\" data-content-b64=\"{}\" data-spoiler-b64=\"{}\" data-sensitive=\"{}\">Edit</a>",esc(&format!("?view=status&object={}&from={}",query(note),query(view))),esc(&edit_href),esc(note),esc(view),b64(own.plain.as_bytes()),b64(own.spoiler.as_bytes()),if own.sensitive{"1"}else{"0"}));
    if own.local_id>0 && own.visibility!="direct" {
        html.push_str(&format!("<form method=\"post\" action=\"{}\" style=\"display:inline\">{}{}{}{}<button class=\"menu-action{}\" type=\"submit\" title=\"{}\">{}</button></form>",esc(&action_url),hidden("csrf",&facts.csrf),hidden("action",if own.pinned{"unpin_status"}else{"pin_status"}),hidden("return_view",view),format!("{}{}",hidden("note_id",note),if from.is_empty(){String::new()}else{hidden("from",from)}),if own.pinned{" on"}else{""},if own.pinned{"Remove this post from your profile pins"}else{"Pin on your HTML profile (up to 5; Bluesky uses the newest)"},if own.pinned{"Unpin from profile"}else{"Pin to profile"}));
    }
    html.push_str(&format!("<a class=\"menu-action\" href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer\">Note</a></div></details></div>",esc(note)));
    Ok(html)
}
