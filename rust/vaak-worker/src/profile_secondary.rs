//! Native secondary profile content from private current server projections.
//! The caller must revalidate the header/selection after awaited effects.
use anyhow::Result;
use serde_json::Value;
use super::{HeaderFacts,h,q,s};
fn links(safe:&str)->String{regex::Regex::new(r"\[([^\]]+)\]\((https://[^ \t\r\n\x0b\x0c\)]+)\)").unwrap().replace_all(safe,|c:&regex::Captures|format!("<a href=\"{}\" rel=\"nofollow noopener noreferrer\" target=\"_blank\">{}</a>",h(&c[2]),h(&c[1]))).into_owned()}
pub(super) fn markdown(raw:&str)->String{
 let mut out=vec![];let mut list=false;let breaks=regex::Regex::new(r"\r\n|[\n\r\x0b\x0c\u{0085}\u{2028}\u{2029}]").unwrap();
 for line in breaks.split(super::card::php_trim(raw)){
  let line=line.trim_end_matches([' ','\t','\n','\r','\0','\u{b}']);if line.is_empty(){if list{out.push("</ul>".into());list=false;}continue}
  let mut safe=links(&h(line));safe=regex::Regex::new(r"\*\*([^*]+)\*\*").unwrap().replace_all(&safe,"<strong>$1</strong>").into_owned();
  let mut italic=String::new();let mut copied=0;let bytes=safe.as_bytes();let mut pos=0;while pos<bytes.len(){if bytes[pos]==b'*'&&(pos==0||bytes[pos-1]!=b'*'){if let Some(end)=safe[pos+1..].find('*').map(|i|pos+1+i){if end>pos+1&&(end+1==bytes.len()||bytes[end+1]!=b'*'){italic.push_str(&safe[copied..pos]);italic.push_str("<em>");italic.push_str(&safe[pos+1..end]);italic.push_str("</em>");copied=end+1;pos=end+1;continue}}}pos+=1;}italic.push_str(&safe[copied..]);safe=italic;
  let heading=regex::Regex::new(r"^(###|##|#)[ \t\r\n\x0b\x0c]+(.+)$").unwrap().captures(line);let item=regex::Regex::new(r"^[-*][ \t\r\n\x0b\x0c]+(.+)$").unwrap().captures(line);
  if let Some(c)=heading{if list{out.push("</ul>".into());list=false;}let level=c[1].len()+1;out.push(format!("<h{level}>{}</h{level}>",h(&c[2])));}
  else if let Some(c)=item{if !list{out.push("<ul>".into());list=true;}out.push(format!("<li>{}</li>",links(&h(&c[1]))));}
  else{if list{out.push("</ul>".into());list=false;}out.push(format!("<p>{safe}</p>"));}
 }if list{out.push("</ul>".into());}out.join("\n")
}
fn title(v:&Value)->&str{v.get("title").and_then(Value::as_str).unwrap_or("Untitled")}
fn published(v:&Value)->&str{v.get("published_at").filter(|v|!v.is_null()).and_then(Value::as_str).unwrap_or_else(||s(v,"created_at"))}
pub(super) fn blog(f:&HeaderFacts)->Result<String>{
 anyhow::ensure!(f.effective_tab=="blog","blog selection required");let mut out=String::new();
 if let Some(v)=&f.blog_post{
  out.push_str(&format!("<article class=\"remote-profile-blog-post\"><div class=\"meta\">Blog{}</div><h2>{}</h2><div class=\"meta\">{}</div>",if s(v,"category").is_empty(){String::new()}else{format!(" · {}",h(s(v,"category")))},h(title(v)),h(published(v))));
  let mut tags=vec![];if let Ok(values)=serde_json::from_str::<Value>(s(v,"tags_json")){for v in values.as_array().into_iter().flatten(){let raw=super::card::scalar_string(&serde_json::json!({"v":v}),"v");let raw=super::card::php_trim(&raw).trim_start_matches('#');let tag=regex::Regex::new(r"[^\p{L}\p{N}_-]+").unwrap().replace_all(raw,"").to_ascii_lowercase();if !tag.is_empty()&&!tags.contains(&tag){tags.push(tag);}}}
  if !tags.is_empty(){out.push_str(&format!("<p class=\"meta\">#{}</p>",h(&tags.join(" #"))));}
  out.push_str(&format!("<div class=\"note-body\">{}</div><p class=\"composer-actions\"><a class=\"btn btn-ghost\" href=\"{}blog\">← All blog posts</a></p></article>",markdown(s(v,"body_markdown")),h(&f.tab_href)));
 }else if f.blog_rows.is_empty(){out.push_str("<div class=\"empty\">No blog posts yet.</div>");}
 else{for v in &f.blog_rows{let href=h(&format!("{}blog&post={}",f.tab_href,q(s(v,"slug"))));out.push_str(&format!("<article class=\"remote-profile-blog-post\"><div class=\"meta\">Blog{} · {}</div><h2><a href=\"{href}\" style=\"color:inherit;text-decoration:none\">{}</a></h2>",if s(v,"category").is_empty(){String::new()}else{format!(" · {}",h(s(v,"category")))},h(published(v)),h(title(v))));if super::card::boolean(v,"excerpt"){out.push_str(&format!("<p class=\"meta\" style=\"white-space:pre-wrap\">{}</p>",h(s(v,"excerpt"))));}out.push_str(&format!("<p><a class=\"btn btn-ghost\" href=\"{href}\">Read</a></p></article>"));}}
 Ok(out)
}
#[cfg(test)]mod tests{use super::*;#[test]fn actual_php_markdown_components()->Result<()>{let script=std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/profile-secondary-components-reference.php");let output=std::process::Command::new(script.parent().unwrap().join("php-policy-test")).arg(script).output()?;anyhow::ensure!(output.status.success(),"{}",String::from_utf8_lossy(&output.stderr));let data:Value=serde_json::from_slice(&output.stdout)?;for row in data["markdown"].as_array().unwrap(){assert_eq!(markdown(s(row,"input")),s(row,"html"),"{}",s(row,"input"));}for row in data["fields"].as_array().unwrap(){assert_eq!(super::super::field_html(s(row,"input")),s(row,"html"),"field {}",s(row,"input"));}Ok(())}}
