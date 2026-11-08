//! Blog composer v2: the Markdown renderer every blog surface uses, and the
//! native Blog page (editor + post lists).
//!
//! PHP owns sessions, CSRF, uploads and every write (publishing federates a
//! teaser through the PHP outbox). It calls two loopback routes guarded by the
//! admin-read token:
//! - `/internal/blog/render`: Markdown to safe HTML, plus word count and
//!   reading time. PHP stores the HTML in `vaak_blog_posts.body_html`, so the
//!   profile pages show it without rendering again.
//! - `/internal/blog/editor`: the Blog page body for one owner.
//!
//! With `VAAK_BLOG_NATIVE` unset in PHP, neither route is called and the
//! original PHP Blog page and renderers stay in charge.
use anyhow::{bail, Result};
use axum::{body::Bytes, http::{HeaderMap, StatusCode}, response::{IntoResponse, Response}};
use serde::Deserialize;
use serde_json::{json, Value};

pub(crate) mod markdown;

/// HTML-escape text for element content and double- or single-quoted attributes.
pub(crate) fn esc(s: &str) -> String {
    let mut out = String::with_capacity(s.len() + 8);
    for c in s.chars() {
        match c {
            '&' => out.push_str("&amp;"),
            '<' => out.push_str("&lt;"),
            '>' => out.push_str("&gt;"),
            '"' => out.push_str("&quot;"),
            '\'' => out.push_str("&#039;"),
            _ => out.push(c),
        }
    }
    out
}

fn q(s: &str) -> String {
    let mut out = String::new();
    for b in s.bytes() {
        if b.is_ascii_alphanumeric() || matches!(b, b'-' | b'_' | b'.' | b'~') {
            out.push(b as char);
        } else {
            out.push_str(&format!("%{b:02X}"));
        }
    }
    out
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct RenderRequest {
    protocol_version: u8,
    markdown: String,
}

pub(crate) async fn render_route(headers: HeaderMap, bytes: Bytes) -> Response {
    if !crate::admin_read::token_ok(&headers) {
        return StatusCode::UNAUTHORIZED.into_response();
    }
    let Ok(req) = serde_json::from_slice::<RenderRequest>(&bytes) else {
        return StatusCode::BAD_REQUEST.into_response();
    };
    if req.protocol_version != 1 || req.markdown.chars().count() > 200_000 {
        return StatusCode::BAD_REQUEST.into_response();
    }
    let out = markdown::render(&req.markdown);
    (StatusCode::OK, axum::Json(json!({"protocol_version": 1, "html": out.html, "words": out.words, "minutes": out.minutes}))).into_response()
}

/// Form values PHP sends back after a failed save, so nothing typed is lost.
#[derive(Deserialize, Default, Clone)]
#[serde(deny_unknown_fields, default)]
struct Submitted {
    title: String,
    subtitle: String,
    category: String,
    body: String,
    tags: String,
    summary: String,
    slug: String,
    cover_alt: String,
    when: String,
    schedule_at: String,
    teaser_visibility: String,
    teaser_cw: bool,
    teaser_bsky: bool,
    teaser_nostr: bool,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct EditorRequest {
    protocol_version: u8,
    owner_id: i64,
    actor_key: String,
    csrf: String,
    #[serde(default)]
    edit_slug: String,
    #[serde(default)]
    submitted: Option<Submitted>,
    nostr_available: bool,
    #[serde(default)]
    nostr_note_id: String,
}

pub(crate) async fn editor_route(cfg: &crate::config::Config, headers: HeaderMap, bytes: Bytes) -> Response {
    if !crate::admin_read::token_ok(&headers) {
        return StatusCode::UNAUTHORIZED.into_response();
    }
    let req: EditorRequest = match serde_json::from_slice(&bytes) {
        Ok(r) => r,
        Err(_) => return StatusCode::BAD_REQUEST.into_response(),
    };
    match editor(cfg, &req).await {
        Ok(html) => (StatusCode::OK, axum::Json(json!({"protocol_version": 1, "html": html}))).into_response(),
        Err(e) => {
            tracing::info!(error = %e, "native blog editor fell back to PHP");
            (StatusCode::UNPROCESSABLE_ENTITY, axum::Json(json!({"fallback": true}))).into_response()
        }
    }
}

const ROW_SQL: &str = "SELECT slug, title, category, tags_json, body_markdown, excerpt, status, \
 COALESCE(published_at,'') AS published_at, created_at, updated_at, subtitle, summary, cover_url, cover_alt, \
 COALESCE(scheduled_at,'') AS scheduled_at, teaser_visibility, teaser_cw::int AS teaser_cw, teaser_bsky::int AS teaser_bsky, \
 teaser_nostr::int AS teaser_nostr, last_error \
 FROM vaak_blog_posts WHERE owner_user_id = $1 AND actor_key = $2 \
 ORDER BY CASE status WHEN 'scheduled' THEN 0 WHEN 'publishing' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END, \
 COALESCE(scheduled_at, published_at, updated_at) DESC, id DESC LIMIT 300";

#[derive(Clone, Debug, Default)]
pub(crate) struct Row {
    slug: String,
    title: String,
    category: String,
    tags: Vec<String>,
    body: String,
    excerpt: String,
    status: String,
    published_at: String,
    updated_at: String,
    subtitle: String,
    summary: String,
    cover_url: String,
    cover_alt: String,
    scheduled_at: String,
    teaser_visibility: String,
    teaser_cw: bool,
    teaser_bsky: bool,
    teaser_nostr: bool,
    last_error: String,
}

fn tags_from_json(raw: &str) -> Vec<String> {
    serde_json::from_str::<Value>(raw)
        .ok()
        .and_then(|v| v.as_array().cloned())
        .unwrap_or_default()
        .into_iter()
        .filter_map(|v| v.as_str().map(str::to_owned))
        .filter(|t| !t.is_empty())
        .collect()
}

fn row_from(r: &tokio_postgres::Row) -> Row {
    let s = |name: &str| r.try_get::<_, Option<String>>(name).ok().flatten().unwrap_or_default();
    let b = |name: &str| r.try_get::<_, i32>(name).map(|v| v != 0).unwrap_or(true);
    Row {
        slug: s("slug"),
        title: s("title"),
        category: s("category"),
        tags: tags_from_json(&s("tags_json")),
        body: s("body_markdown"),
        excerpt: s("excerpt"),
        status: s("status"),
        published_at: s("published_at"),
        updated_at: s("updated_at"),
        subtitle: s("subtitle"),
        summary: s("summary"),
        cover_url: s("cover_url"),
        cover_alt: s("cover_alt"),
        scheduled_at: s("scheduled_at"),
        teaser_visibility: {
            let v = s("teaser_visibility");
            if v.is_empty() { "public".into() } else { v }
        },
        teaser_cw: b("teaser_cw"),
        teaser_bsky: b("teaser_bsky"),
        teaser_nostr: r.try_get::<_, i32>("teaser_nostr").map(|v| v != 0).unwrap_or(false),
        last_error: s("last_error"),
    }
}

async fn editor(cfg: &crate::config::Config, req: &EditorRequest) -> Result<String> {
    if req.protocol_version != 1 || req.owner_id < 1 || req.csrf.is_empty() || req.csrf.len() > 4096 {
        bail!("blog editor envelope");
    }
    if req.actor_key.is_empty() || req.actor_key.len() > 80 || !req.actor_key.bytes().all(|b| b.is_ascii_lowercase() || b.is_ascii_digit() || b == b'_') {
        bail!("blog editor actor");
    }
    if req.edit_slug.len() > 256 || req.nostr_note_id.len() > 512 {
        bail!("blog editor selector");
    }
    let db = crate::db::connect(&cfg.database_url).await?;
    let rows: Vec<Row> = db.query(ROW_SQL, &[&req.owner_id, &req.actor_key]).await?.iter().map(row_from).collect();
    let bsky: bool = db
        .query_one("SELECT EXISTS(SELECT 1 FROM bsky_sessions WHERE owner_user_id = $1)", &[&req.owner_id])
        .await
        .map(|r| r.get(0))
        .unwrap_or(false);
    let editing = if req.edit_slug.is_empty() { None } else { rows.iter().find(|r| r.slug == req.edit_slug).cloned() };
    if !req.edit_slug.is_empty() && editing.is_none() && req.submitted.is_none() {
        // A stale link: show a fresh editor rather than someone else's slug.
        return Ok(page(req, &rows, None, bsky, Some("That post was not found. Starting a new one.")));
    }
    Ok(page(req, &rows, editing.as_ref(), bsky, None))
}

fn date_label(iso: &str) -> String {
    chrono::DateTime::parse_from_rfc3339(iso)
        .map(|d| d.with_timezone(&chrono::Utc).format("%b %-d, %Y").to_string())
        .unwrap_or_else(|_| iso.chars().take(10).collect())
}

fn time_tag(iso: &str) -> String {
    if iso.is_empty() {
        return String::new();
    }
    format!("<time datetime=\"{}\" data-blog-local-time>{}</time>", esc(iso), esc(&date_label(iso)))
}

fn status_label(status: &str) -> &'static str {
    match status {
        "published" => "Published",
        "scheduled" | "publishing" => "Scheduled",
        _ => "Draft",
    }
}

fn checked(on: bool) -> &'static str {
    if on { " checked" } else { "" }
}

fn selected(on: bool) -> &'static str {
    if on { " selected" } else { "" }
}

/// The editor's working values: a failed submission wins over the stored row.
struct Values {
    title: String,
    subtitle: String,
    category: String,
    body: String,
    tags: String,
    summary: String,
    slug: String,
    cover_url: String,
    cover_alt: String,
    when: String,
    schedule_at: String,
    visibility: String,
    cw: bool,
    bsky: bool,
    nostr: bool,
}

fn values(editing: Option<&Row>, submitted: Option<&Submitted>) -> Values {
    let row = editing.cloned().unwrap_or_default();
    if let Some(s) = submitted {
        return Values {
            title: s.title.clone(),
            subtitle: s.subtitle.clone(),
            category: s.category.clone(),
            body: s.body.clone(),
            tags: s.tags.clone(),
            summary: s.summary.clone(),
            slug: if s.slug.is_empty() { row.slug.clone() } else { s.slug.clone() },
            cover_url: row.cover_url.clone(),
            cover_alt: s.cover_alt.clone(),
            when: s.when.clone(),
            schedule_at: s.schedule_at.clone(),
            visibility: if s.teaser_visibility.is_empty() { "public".into() } else { s.teaser_visibility.clone() },
            cw: s.teaser_cw,
            bsky: s.teaser_bsky,
            nostr: s.teaser_nostr,
        };
    }
    let fresh = editing.is_none();
    Values {
        title: row.title.clone(),
        subtitle: row.subtitle.clone(),
        category: row.category.clone(),
        body: row.body.clone(),
        tags: row.tags.iter().map(|t| format!("#{t}")).collect::<Vec<_>>().join(" "),
        summary: row.summary.clone(),
        slug: row.slug.clone(),
        cover_url: row.cover_url.clone(),
        cover_alt: row.cover_alt.clone(),
        when: if row.status == "scheduled" { "schedule".into() } else { "now".into() },
        schedule_at: row.scheduled_at.clone(),
        visibility: if fresh { "public".into() } else { row.teaser_visibility.clone() },
        cw: fresh || row.teaser_cw,
        bsky: fresh || row.teaser_bsky,
        nostr: !fresh && row.teaser_nostr,
    }
}

fn page(req: &EditorRequest, rows: &[Row], editing: Option<&Row>, bsky: bool, banner: Option<&str>) -> String {
    let v = values(editing, req.submitted.as_ref());
    let status = editing.map(|r| r.status.as_str()).unwrap_or("new");
    let published = status == "published";
    let key = &req.actor_key;
    let mut o = String::with_capacity(16_384);
    o.push_str("<div class=\"blog-studio\" data-blog-studio>");
    if !req.nostr_note_id.is_empty() {
        o.push_str(&format!("<div hidden data-blog-nostr-note=\"{}\"></div>", esc(&req.nostr_note_id)));
    }
    if let Some(msg) = banner {
        o.push_str(&format!("<div class=\"flash err\" role=\"alert\"><span>{}</span></div>", esc(msg)));
    }
    if let Some(r) = editing {
        if !r.last_error.is_empty() {
            o.push_str(&format!("<div class=\"flash err\" role=\"alert\"><span>{}</span></div>", esc(&r.last_error)));
        }
    }
    o.push_str("<form class=\"be\" method=\"post\" action=\"?view=blog\" enctype=\"multipart/form-data\" data-blog-editor");
    o.push_str(&format!(" data-blog-status=\"{}\" data-blog-autosave=\"{}\">", esc(status), if published { "0" } else { "1" }));
    o.push_str("<input type=\"hidden\" name=\"action\" value=\"blog_save\">");
    o.push_str(&format!("<input type=\"hidden\" name=\"csrf\" value=\"{}\">", esc(&req.csrf)));
    o.push_str("<input type=\"hidden\" name=\"blog_v2\" value=\"1\"><input type=\"hidden\" name=\"blog_tz_offset\" value=\"0\" data-blog-tz>");
    if let Some(r) = editing {
        o.push_str(&format!("<input type=\"hidden\" name=\"blog_slug\" value=\"{}\" data-blog-slug>", esc(&r.slug)));
    } else {
        o.push_str("<input type=\"hidden\" name=\"blog_slug\" value=\"\" data-blog-slug>");
    }

    // Top bar: where you are, state, actions.
    let crumb = match status {
        "published" => "Editing published post",
        "scheduled" | "publishing" => "Editing scheduled post",
        "draft" => "Editing draft",
        _ => "New post",
    };
    let primary = if published { "Update" } else if v.when == "schedule" { "Schedule" } else { "Publish" };
    o.push_str("<div class=\"be-top\">");
    o.push_str(&format!("<span class=\"be-crumb\"><a href=\"?view=blog\">Blog</a> / {crumb}</span>"));
    if editing.is_some() {
        let pill = status_label(status);
        o.push_str(&format!("<span class=\"be-pill be-pill-{}\">{pill}</span>", pill.to_ascii_lowercase()));
    }
    o.push_str("<span class=\"be-pill be-pill-save\" data-blog-save-state hidden></span>");
    if editing.is_some() {
        o.push_str("<a class=\"btn btn-ghost\" href=\"?view=blog\">New post</a>");
    }
    if !published {
        o.push_str("<button class=\"btn btn-ghost\" name=\"blog_publish\" value=\"0\" type=\"submit\">Save draft</button>");
    }
    o.push_str(&format!("<button class=\"btn btn-primary\" name=\"blog_publish\" value=\"1\" type=\"submit\" data-blog-primary data-blog-primary-publish=\"{}\">{primary}</button>", if published { "Update" } else { "Publish" }));
    o.push_str("</div>");

    // Writing canvas.
    o.push_str("<div class=\"be-canvas\">");
    let has_cover = !v.cover_url.is_empty();
    o.push_str(&format!("<div class=\"be-cover{}\" data-blog-cover>", if has_cover { " has-cover" } else { "" }));
    o.push_str(&format!(
        "<img class=\"be-cover-img\" data-blog-cover-img alt=\"\" src=\"{}\"{}>",
        esc(&v.cover_url),
        if has_cover { "" } else { " hidden" }
    ));
    o.push_str("<div class=\"be-cover-actions\"><label class=\"btn be-cover-btn\"><i class=\"ph ph-image\" aria-hidden=\"true\"></i> <span data-blog-cover-label>");
    o.push_str(if has_cover { "Change cover image" } else { "Add cover image" });
    o.push_str("</span><input type=\"file\" name=\"blog_cover\" accept=\"image/jpeg,image/png,image/webp,image/gif,image/heic,image/heif\" data-blog-cover-input></label>");
    o.push_str(&format!(
        "<label class=\"btn be-cover-btn be-cover-remove\"{}><input type=\"checkbox\" name=\"blog_cover_remove\" value=\"1\" data-blog-cover-remove> Remove</label>",
        if has_cover { "" } else { " hidden" }
    ));
    o.push_str("</div></div>");
    o.push_str(&format!(
        "<label class=\"sr-only\" for=\"blog-title\">Title</label><input class=\"be-title\" id=\"blog-title\" name=\"blog_title\" maxlength=\"240\" required placeholder=\"Title\" value=\"{}\" autocomplete=\"off\">",
        esc(&v.title)
    ));
    o.push_str(&format!(
        "<label class=\"sr-only\" for=\"blog-subtitle\">Subtitle</label><input class=\"be-sub\" id=\"blog-subtitle\" name=\"blog_subtitle\" maxlength=\"300\" placeholder=\"Subtitle (optional)\" value=\"{}\" autocomplete=\"off\">",
        esc(&v.subtitle)
    ));
    o.push_str(TOOLBAR);
    o.push_str(&format!(
        "<div class=\"be-split\" data-blog-panes data-mode=\"write\"><label class=\"sr-only\" for=\"blog-body\">Post content</label><textarea id=\"blog-body\" name=\"blog_body\" maxlength=\"200000\" required placeholder=\"Write your post… Markdown works too.\" aria-describedby=\"blog-format-status\">{}</textarea><div class=\"be-prev blog-post-body\" data-blog-preview aria-live=\"polite\" aria-label=\"Preview\"></div></div>",
        esc(&v.body)
    ));
    let words = markdown::word_count(&v.body);
    o.push_str(&format!(
        "<div class=\"be-foot\"><span data-blog-words>{} word{}</span><span data-blog-minutes>{} min read</span><span class=\"be-md\">Markdown</span><span class=\"blog-format-status\" id=\"blog-format-status\" role=\"status\" aria-live=\"polite\"></span></div>",
        words,
        if words == 1 { "" } else { "s" },
        markdown::minutes(words)
    ));
    o.push_str("</div>");

    // Settings panel.
    o.push_str("<aside class=\"be-side\"><section class=\"side-card\"><h3>Post settings</h3>");
    o.push_str(&format!(
        "<label for=\"blog-category\">Category</label><input id=\"blog-category\" name=\"blog_category\" maxlength=\"120\" placeholder=\"e.g. Dev log\" value=\"{}\">",
        esc(&v.category)
    ));
    o.push_str(&format!(
        "<label for=\"blog-tags\">Tags</label><div class=\"be-chips\" data-blog-chips></div><input id=\"blog-tags\" name=\"blog_tags\" maxlength=\"500\" placeholder=\"Type a tag, then space or comma\" value=\"{}\" data-blog-tags>",
        esc(&v.tags)
    ));
    let slug_hint = if v.slug.is_empty() { "made from the title".to_string() } else { v.slug.clone() };
    o.push_str(&format!(
        "<label for=\"blog-url\">URL</label><input id=\"blog-url\" name=\"blog_url_slug\" maxlength=\"80\" pattern=\"[A-Za-z0-9 -]*\" placeholder=\"made-from-the-title\" value=\"{}\"{} data-blog-url><p class=\"be-help\">mkultra.monster/users/{}?tab=blog&amp;post=<b data-blog-url-preview>{}</b>{}</p>",
        esc(&v.slug),
        if published { " readonly" } else { "" },
        esc(key),
        esc(&slug_hint),
        if published { "<br>Published URLs stay the same so links keep working." } else { "" }
    ));
    o.push_str(&format!(
        "<label for=\"blog-summary\">Summary</label><textarea id=\"blog-summary\" name=\"blog_summary\" maxlength=\"1000\" rows=\"3\" placeholder=\"Shown in lists and the timeline teaser. Leave empty to use the opening of the post.\" data-blog-summary>{}</textarea>",
        esc(&v.summary)
    ));
    o.push_str(&format!(
        "<label for=\"blog-cover-alt\">Cover image description</label><input id=\"blog-cover-alt\" name=\"blog_cover_alt\" maxlength=\"1500\" placeholder=\"Describe the cover for screen readers\" value=\"{}\">",
        esc(&v.cover_alt)
    ));
    if !published {
        o.push_str("<label for=\"blog-when\">Publish</label>");
        o.push_str(&format!(
            "<select id=\"blog-when\" name=\"blog_when\" data-blog-when><option value=\"now\"{}>Now</option><option value=\"schedule\"{}>Schedule for later</option></select>",
            selected(v.when != "schedule"),
            selected(v.when == "schedule")
        ));
        o.push_str(&format!(
            "<div class=\"be-schedule\" data-blog-schedule{}><label for=\"blog-schedule-at\">Date and time</label><input type=\"datetime-local\" id=\"blog-schedule-at\" name=\"blog_schedule_at\" value=\"{}\" data-blog-schedule-at data-blog-schedule-utc=\"{}\"></div>",
            if v.when == "schedule" { "" } else { " hidden" },
            esc(if v.schedule_at.ends_with('Z') || v.schedule_at.contains('+') { "" } else { &v.schedule_at }),
            esc(if v.schedule_at.ends_with('Z') || v.schedule_at.contains('+') { &v.schedule_at } else { "" })
        ));
    }
    o.push_str("</section>");

    o.push_str("<section class=\"side-card\"><h3>Sharing</h3>");
    let lock = if published { " disabled" } else { "" };
    if published {
        o.push_str("<p class=\"be-help\">Sharing settings are fixed once a post is published. Updating the post also updates its timeline teaser.</p>");
    }
    o.push_str(&format!("<label for=\"blog-teaser-vis\">Timeline teaser</label><select id=\"blog-teaser-vis\" name=\"blog_teaser_visibility\" data-blog-teaser-vis{lock}>"));
    for (val, label) in [("public", "Public"), ("unlisted", "Unlisted"), ("private", "Followers only"), ("none", "Don't post a teaser")] {
        o.push_str(&format!("<option value=\"{val}\"{}>{label}</option>", selected(v.visibility == val)));
    }
    o.push_str("</select>");
    o.push_str(&format!(
        "<div class=\"be-row\"><input type=\"hidden\" name=\"blog_teaser_cw\" value=\"0\"><input type=\"checkbox\" id=\"blog-teaser-cw\" name=\"blog_teaser_cw\" value=\"1\"{}{lock} data-blog-teaser-cw><label for=\"blog-teaser-cw\">Title as content warning</label></div>",
        checked(v.cw)
    ));
    if bsky {
        o.push_str(&format!(
            "<div class=\"be-row\"><input type=\"hidden\" name=\"blog_teaser_bsky\" value=\"0\"><input type=\"checkbox\" id=\"blog-teaser-bsky\" name=\"blog_teaser_bsky\" value=\"1\"{}{lock}><label for=\"blog-teaser-bsky\">Mirror teaser to Bluesky</label></div>",
            checked(v.bsky)
        ));
    } else {
        o.push_str("<input type=\"hidden\" name=\"blog_teaser_bsky\" value=\"1\">");
    }
    if req.nostr_available {
        o.push_str(&format!(
            "<div class=\"be-row\"><input type=\"hidden\" name=\"blog_teaser_nostr\" value=\"0\"><input type=\"checkbox\" id=\"blog-teaser-nostr\" name=\"blog_teaser_nostr\" value=\"1\"{}{lock}><label for=\"blog-teaser-nostr\">Also post to Nostr</label></div><p class=\"be-help\">Nostr needs your signer, so scheduled posts skip it.</p>",
            checked(v.nostr)
        ));
    }
    o.push_str("<div class=\"be-teaser\" data-blog-teaser aria-label=\"Timeline teaser preview\"><b data-blog-teaser-cw-line></b><span data-blog-teaser-text></span><span class=\"be-teaser-link\">Read the full post →</span></div>");
    o.push_str("</section></aside></form>");

    o.push_str(&lists(rows, &req.csrf, editing.map(|r| r.slug.as_str())));
    o.push_str("</div>");
    o
}

const TOOLBAR: &str = concat!(
    "<div class=\"be-bar\" role=\"toolbar\" aria-label=\"Format\">",
    "<button type=\"button\" data-blog-format=\"bold\" title=\"Bold (Ctrl/Cmd+B)\" aria-label=\"Bold\"><b>B</b></button>",
    "<button type=\"button\" data-blog-format=\"italic\" title=\"Italic (Ctrl/Cmd+I)\" aria-label=\"Italic\"><i>I</i></button>",
    "<button type=\"button\" data-blog-format=\"strike\" title=\"Strikethrough\" aria-label=\"Strikethrough\"><s>S</s></button>",
    "<button type=\"button\" data-blog-format=\"code\" title=\"Inline code\" aria-label=\"Inline code\">&lt;/&gt;</button>",
    "<span class=\"be-sep\" aria-hidden=\"true\"></span>",
    "<button type=\"button\" data-blog-format=\"heading1\" title=\"Heading\" aria-label=\"Heading\">H1</button>",
    "<button type=\"button\" data-blog-format=\"heading2\" title=\"Subheading\" aria-label=\"Subheading\">H2</button>",
    "<button type=\"button\" data-blog-format=\"heading3\" title=\"Small heading\" aria-label=\"Small heading\">H3</button>",
    "<span class=\"be-sep\" aria-hidden=\"true\"></span>",
    "<button type=\"button\" data-blog-format=\"list\" title=\"Bullet list\" aria-label=\"Bullet list\"><i class=\"ph ph-list-bullets\" aria-hidden=\"true\"></i></button>",
    "<button type=\"button\" data-blog-format=\"olist\" title=\"Numbered list\" aria-label=\"Numbered list\"><i class=\"ph ph-list-numbers\" aria-hidden=\"true\"></i></button>",
    "<button type=\"button\" data-blog-format=\"quote\" title=\"Quote\" aria-label=\"Quote\"><i class=\"ph ph-quotes\" aria-hidden=\"true\"></i></button>",
    "<button type=\"button\" data-blog-format=\"rule\" title=\"Divider\" aria-label=\"Divider\"><i class=\"ph ph-minus\" aria-hidden=\"true\"></i></button>",
    "<span class=\"be-sep\" aria-hidden=\"true\"></span>",
    "<button type=\"button\" data-blog-format=\"link\" aria-expanded=\"false\" aria-controls=\"blog-link-fields\" title=\"Link (Ctrl/Cmd+K)\" aria-label=\"Link\"><i class=\"ph ph-link\" aria-hidden=\"true\"></i></button>",
    "<button type=\"button\" data-blog-format=\"image\" aria-expanded=\"false\" aria-controls=\"blog-image-fields\" title=\"Image from a link\" aria-label=\"Image\"><i class=\"ph ph-image-square\" aria-hidden=\"true\"></i></button>",
    "<button type=\"button\" data-blog-format=\"codeblock\" title=\"Code block\" aria-label=\"Code block\"><i class=\"ph ph-code-block\" aria-hidden=\"true\"></i></button>",
    "<div class=\"be-tabs\" role=\"group\" aria-label=\"Editor view\">",
    "<button type=\"button\" data-blog-mode=\"write\" aria-pressed=\"true\">Write</button>",
    "<button type=\"button\" data-blog-mode=\"split\" aria-pressed=\"false\" class=\"be-tab-split\">Split</button>",
    "<button type=\"button\" data-blog-mode=\"preview\" aria-pressed=\"false\">Preview</button>",
    "</div></div>",
    "<div class=\"blog-link-fields\" id=\"blog-link-fields\" hidden><div class=\"blog-fields\">",
    "<label>Link text<input type=\"text\" data-blog-link-text maxlength=\"2000\"></label>",
    "<label>HTTPS address<input type=\"url\" data-blog-link-url placeholder=\"https://example.org\" maxlength=\"4000\"></label></div>",
    "<button class=\"btn btn-ghost\" type=\"button\" data-blog-link-insert>Insert link</button> ",
    "<button class=\"btn btn-ghost\" type=\"button\" data-blog-link-cancel>Cancel</button></div>",
    "<div class=\"blog-link-fields\" id=\"blog-image-fields\" hidden><div class=\"blog-fields\">",
    "<label>Image description<input type=\"text\" data-blog-image-alt maxlength=\"500\"></label>",
    "<label>HTTPS image address<input type=\"url\" data-blog-image-url placeholder=\"https://…/picture.jpg\" maxlength=\"4000\"></label></div>",
    "<button class=\"btn btn-ghost\" type=\"button\" data-blog-image-insert>Insert image</button> ",
    "<button class=\"btn btn-ghost\" type=\"button\" data-blog-image-cancel>Cancel</button></div>",
);

fn lists(rows: &[Row], csrf: &str, editing: Option<&str>) -> String {
    let mut o = String::from("<section class=\"side-card blog-list\" aria-labelledby=\"blog-list-title\"><h2 id=\"blog-list-title\">Your posts</h2>");
    if rows.is_empty() {
        o.push_str("<p class=\"empty\">No posts yet. Your drafts, scheduled and published posts will show up here.</p></section>");
        return o;
    }
    for (group, label) in [("scheduled", "Scheduled"), ("draft", "Drafts"), ("published", "Published")] {
        let items: Vec<&Row> = rows
            .iter()
            .filter(|r| match group {
                "scheduled" => r.status == "scheduled" || r.status == "publishing",
                "published" => r.status == "published",
                _ => r.status != "scheduled" && r.status != "publishing" && r.status != "published",
            })
            .collect();
        if items.is_empty() {
            continue;
        }
        o.push_str(&format!("<h3>{label} <span class=\"be-count\">{}</span></h3><ul class=\"blog-rows\">", items.len()));
        for r in items {
            let pill = status_label(&r.status);
            let when = match group {
                "scheduled" => format!("Goes out {}", time_tag(&r.scheduled_at)),
                "published" => time_tag(&r.published_at),
                _ => format!("Edited {}", time_tag(&r.updated_at)),
            };
            let summary = if r.summary.is_empty() { &r.excerpt } else { &r.summary };
            let current = editing == Some(r.slug.as_str());
            o.push_str(&format!("<li class=\"blog-row{}\">", if current { " is-current" } else { "" }));
            if !r.cover_url.is_empty() {
                o.push_str(&format!("<img class=\"blog-row-cover\" src=\"{}\" alt=\"\" loading=\"lazy\">", esc(&r.cover_url)));
            }
            o.push_str("<div class=\"blog-row-main\">");
            let href = if r.status == "published" { format!("?view=blog&amp;post={}", q(&r.slug)) } else { format!("?view=blog&amp;edit={}", q(&r.slug)) };
            o.push_str(&format!(
                "<div class=\"blog-row-head\"><a class=\"blog-row-title\" href=\"{href}\">{}</a><span class=\"be-pill be-pill-{}\">{pill}</span></div>",
                esc(if r.title.is_empty() { "Untitled" } else { &r.title }),
                pill.to_ascii_lowercase()
            ));
            o.push_str(&format!(
                "<div class=\"meta\">{}{}</div>",
                if r.category.is_empty() { String::new() } else { format!("{} · ", esc(&r.category)) },
                when
            ));
            if !r.last_error.is_empty() {
                o.push_str(&format!("<div class=\"blog-row-error\">{}</div>", esc(&r.last_error)));
            }
            if !summary.is_empty() {
                o.push_str(&format!("<p class=\"blog-row-excerpt\">{}</p>", esc(summary)));
            }
            o.push_str("<div class=\"blog-row-actions\">");
            o.push_str(&format!("<a class=\"btn btn-ghost\" href=\"?view=blog&amp;edit={}\">Edit</a>", q(&r.slug)));
            if r.status == "published" {
                o.push_str(&format!("<a class=\"btn btn-ghost\" href=\"?view=blog&amp;post={}\">View</a>", q(&r.slug)));
            }
            if r.status != "publishing" {
                let what = if r.status == "published" { "this post and its timeline teaser" } else { "this draft" };
                o.push_str(&format!(
                    "<form method=\"post\" action=\"?view=blog\" data-blog-delete=\"Delete {what}? This can't be undone.\"><input type=\"hidden\" name=\"action\" value=\"blog_delete\"><input type=\"hidden\" name=\"csrf\" value=\"{}\"><input type=\"hidden\" name=\"blog_slug\" value=\"{}\"><button class=\"btn btn-ghost btn-danger\" type=\"submit\">Delete</button></form>",
                    esc(csrf),
                    esc(&r.slug)
                ));
            }
            o.push_str("</div></div></li>");
        }
        o.push_str("</ul>");
    }
    o.push_str("</section>");
    o
}

#[cfg(test)]
mod tests {
    use super::*;

    fn req() -> EditorRequest {
        EditorRequest { protocol_version: 1, owner_id: 1, actor_key: "cmdr_nova".into(), csrf: "tok\"<".into(), edit_slug: String::new(), submitted: None, nostr_available: false, nostr_note_id: String::new() }
    }

    fn row(slug: &str, status: &str) -> Row {
        Row { slug: slug.into(), title: format!("<b>{slug}</b>"), status: status.into(), teaser_visibility: "public".into(), teaser_cw: true, teaser_bsky: true, published_at: "2026-11-08T05:00:00+00:00".into(), updated_at: "2026-11-08T05:00:00+00:00".into(), ..Default::default() }
    }

    #[test]
    fn page_escapes_and_keeps_the_v1_form_contract() {
        let rows = vec![row("a", "published"), row("b", "draft"), row("c", "scheduled")];
        let html = page(&req(), &rows, None, false, None);
        for field in ["name=\"action\" value=\"blog_save\"", "name=\"blog_title\"", "name=\"blog_category\"", "name=\"blog_body\"", "name=\"blog_tags\"", "name=\"blog_slug\"", "name=\"blog_publish\" value=\"1\"", "name=\"blog_publish\" value=\"0\""] {
            assert!(html.contains(field), "missing {field}");
        }
        assert!(html.contains("value=\"tok&quot;&lt;\""));
        assert!(html.contains("&lt;b&gt;a&lt;/b&gt;"));
        assert!(!html.contains("<b>a</b>"));
        assert!(html.contains(">Scheduled <span class=\"be-count\">1</span>"));
        // Without a Bluesky session the mirror flag still posts its default.
        assert!(html.contains("name=\"blog_teaser_bsky\" value=\"1\""));
    }

    #[test]
    fn published_posts_lock_url_and_sharing() {
        let rows = vec![row("a", "published")];
        let html = page(&req(), &rows, rows.first(), true, None);
        assert!(html.contains("name=\"blog_url_slug\" maxlength=\"80\" pattern=\"[A-Za-z0-9 -]*\" placeholder=\"made-from-the-title\" value=\"a\" readonly"));
        assert!(html.contains("data-blog-teaser-vis disabled"));
        assert!(!html.contains("value=\"0\" type=\"submit\">Save draft"));
        assert!(html.contains(">Update</button>"));
    }

    #[test]
    fn submitted_values_win() {
        let mut r = req();
        r.submitted = Some(Submitted { title: "Typed".into(), body: "Kept body".into(), when: "schedule".into(), schedule_at: "2026-12-01T10:00".into(), teaser_visibility: "unlisted".into(), ..Default::default() });
        let html = page(&r, &[], None, false, None);
        assert!(html.contains("value=\"Typed\""));
        assert!(html.contains(">Kept body</textarea>"));
        assert!(html.contains("value=\"2026-12-01T10:00\""));
        assert!(html.contains("<option value=\"unlisted\" selected>"));
        assert!(html.contains(">Schedule</button>"));
    }

    /// Writes a sample page for browser screenshots when VAAK_BLOG_PREVIEW_OUT is set.
    #[test]
    fn sample_page_for_screenshots() {
        let Ok(dir) = std::env::var("VAAK_BLOG_PREVIEW_OUT") else { return };
        let body = "## The short version\n\nI wanted one place for **everything**: fediverse, Bluesky, Nostr, my blog.\n\n1. one inbox\n2. one timeline\n\n> Build the thing you want to use.\n\n```rust\nfn main() { println!(\"hi\"); }\n```\n\nMore at https://mkultra.monster.";
        let mut editing = Row { slug: "why-i-built-vaak".into(), title: "Why I built VAAK".into(), subtitle: "One app for fediverse, Bluesky and Nostr, and why I stopped waiting for someone else to make it.".into(), category: "Dev log".into(), tags: vec!["vaak".into(), "fediverse".into(), "rust".into()], body: body.into(), status: "draft".into(), teaser_visibility: "public".into(), teaser_cw: true, teaser_bsky: true, updated_at: "2026-11-08T06:40:00+00:00".into(), ..Default::default() };
        editing.excerpt = "I wanted one place for everything: fediverse, Bluesky, Nostr, my blog.".into();
        let mut published = row("hello-vaak", "published");
        published.title = "Hello from VAAK".into();
        published.category = "News".into();
        published.excerpt = "The first long-form post written in VAAK's own editor.".into();
        let mut scheduled = row("sunday-notes", "scheduled");
        scheduled.title = "Sunday notes".into();
        scheduled.scheduled_at = "2026-11-09T15:00:00Z".into();
        scheduled.excerpt = "Things I fixed this week.".into();
        let mut r = req();
        r.csrf = "csrf".into();
        r.nostr_available = true;
        let rows = vec![scheduled, editing.clone(), published];
        std::fs::write(format!("{dir}/page.html"), page(&r, &rows, Some(&editing), true, None)).unwrap();
        std::fs::write(format!("{dir}/body.html"), markdown::render(body).html).unwrap();
        std::fs::write(format!("{dir}/new.html"), page(&r, &rows, None, true, None)).unwrap();
    }
}
