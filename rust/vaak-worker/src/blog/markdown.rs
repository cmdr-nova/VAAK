//! The blog's Markdown dialect, rendered to safe HTML.
//!
//! Every character of input is escaped; the only markup that comes out is
//! the fixed set of tags below. Links and images must use `https://`.
//!
//! Blocks: `#`/`##`/`###` headings (h2-h4, as before; `####`+ map to h5/h6),
//! paragraphs (one per line, as the original renderer did), `-`/`*`/`+`
//! bullet lists, `1.`/`1)` numbered lists, `>` quotes (which hold any other
//! block), fenced code (```), and `---`/`***`/`___` dividers. A line that is
//! only an image becomes a figure with its description as caption.
//!
//! Inline: `**bold**`/`__bold__`, `*italic*`/`_italic_`, `~~strike~~`,
//! `` `code` ``, `[text](https://…)`, `![description](https://…)`, bare
//! `https://` addresses, and `\` escapes. Formatting also works inside
//! headings and list items, which the original renderer dropped.

pub(crate) struct Rendered {
    pub(crate) html: String,
    pub(crate) words: i64,
    pub(crate) minutes: i64,
}

pub(crate) fn render(md: &str) -> Rendered {
    let lines: Vec<&str> = md.trim().lines().collect();
    let mut out = Vec::new();
    blocks(&lines, &mut out, 0);
    let words = word_count(md);
    Rendered { html: out.join("\n"), words, minutes: minutes(words) }
}

pub(crate) fn word_count(md: &str) -> i64 {
    let mut in_fence = false;
    let mut n = 0i64;
    for line in md.lines() {
        if line.trim_start().starts_with("```") {
            in_fence = !in_fence;
            continue;
        }
        if in_fence {
            continue;
        }
        n += line
            .split_whitespace()
            .filter(|w| !w.starts_with("](") && !w.starts_with("https://") && !w.starts_with("![") && w.chars().any(char::is_alphanumeric))
            .count() as i64;
    }
    n
}

pub(crate) fn minutes(words: i64) -> i64 {
    ((words + 219) / 220).max(1)
}

fn esc(s: &str) -> String {
    super::esc(s)
}

enum ListKind {
    Bullet,
    Number(u64),
}

fn list_item(line: &str) -> Option<(ListKind, &str)> {
    let t = line.trim_start();
    let indent = line.len() - t.len();
    if indent > 3 {
        return None;
    }
    let mut chars = t.char_indices();
    let (_, first) = chars.next()?;
    if matches!(first, '-' | '*' | '+') {
        let rest = &t[1..];
        if rest.starts_with(' ') || rest.starts_with('\t') {
            let body = rest.trim_start();
            if !body.is_empty() && !is_rule(t) {
                return Some((ListKind::Bullet, body));
            }
        }
        return None;
    }
    let digits: String = t.chars().take_while(char::is_ascii_digit).collect();
    if digits.is_empty() || digits.len() > 9 {
        return None;
    }
    let rest = &t[digits.len()..];
    let rest = rest.strip_prefix('.').or_else(|| rest.strip_prefix(')'))?;
    if !(rest.starts_with(' ') || rest.starts_with('\t')) {
        return None;
    }
    let body = rest.trim_start();
    if body.is_empty() {
        return None;
    }
    Some((ListKind::Number(digits.parse().unwrap_or(1)), body))
}

fn is_rule(line: &str) -> bool {
    let t: String = line.chars().filter(|c| !c.is_whitespace()).collect();
    t.len() >= 3 && (t.chars().all(|c| c == '-') || t.chars().all(|c| c == '*') || t.chars().all(|c| c == '_'))
}

fn heading(line: &str) -> Option<(usize, &str)> {
    let t = line.trim_start();
    if line.len() - t.len() > 3 {
        return None;
    }
    let hashes = t.chars().take_while(|&c| c == '#').count();
    if hashes == 0 || hashes > 6 {
        return None;
    }
    let rest = &t[hashes..];
    if !(rest.starts_with(' ') || rest.starts_with('\t')) {
        return None;
    }
    let text = rest.trim().trim_end_matches('#').trim_end();
    if text.is_empty() {
        return None;
    }
    // The post title is the page's h2/h1, so # starts at h2 (as before).
    Some(((hashes + 1).min(6), text))
}

fn fence_open(line: &str) -> Option<String> {
    let t = line.trim_start();
    if line.len() - t.len() > 3 {
        return None;
    }
    let rest = t.strip_prefix("```")?;
    let lang: String = rest.trim().chars().take_while(|c| c.is_ascii_alphanumeric() || matches!(c, '+' | '-' | '#')).take(20).collect();
    Some(lang.to_ascii_lowercase())
}

fn quote_line(line: &str) -> Option<&str> {
    let t = line.trim_start();
    if line.len() - t.len() > 3 {
        return None;
    }
    let rest = t.strip_prefix('>')?;
    Some(rest.strip_prefix(' ').unwrap_or(rest))
}

fn blocks(lines: &[&str], out: &mut Vec<String>, depth: usize) {
    let mut i = 0;
    while i < lines.len() {
        let line = lines[i].trim_end();
        if line.trim().is_empty() {
            i += 1;
            continue;
        }
        if let Some(lang) = fence_open(line) {
            let mut code = Vec::new();
            i += 1;
            while i < lines.len() && !lines[i].trim_start().starts_with("```") {
                code.push(lines[i]);
                i += 1;
            }
            i += 1; // closing fence (or end of input)
            let class = if lang.is_empty() { String::new() } else { format!(" class=\"language-{}\"", esc(&lang)) };
            out.push(format!("<pre><code{class}>{}</code></pre>", esc(&code.join("\n"))));
            continue;
        }
        if depth < 4 && quote_line(line).is_some() {
            let mut inner = Vec::new();
            while i < lines.len() {
                match quote_line(lines[i]) {
                    Some(rest) => inner.push(rest),
                    None => break,
                }
                i += 1;
            }
            let mut body = Vec::new();
            blocks(&inner, &mut body, depth + 1);
            out.push(format!("<blockquote>{}</blockquote>", body.join("\n")));
            continue;
        }
        if let Some((level, text)) = heading(line) {
            out.push(format!("<h{level}>{}</h{level}>", inline(text)));
            i += 1;
            continue;
        }
        if is_rule(line) {
            out.push("<hr>".into());
            i += 1;
            continue;
        }
        if let Some((kind, _)) = list_item(line) {
            let numbered = matches!(kind, ListKind::Number(_));
            let start = if let ListKind::Number(n) = kind { n } else { 1 };
            let mut items: Vec<String> = Vec::new();
            while i < lines.len() {
                let l = lines[i].trim_end();
                match list_item(l) {
                    Some((k, body)) if matches!(k, ListKind::Number(_)) == numbered => {
                        items.push(body.to_string());
                        i += 1;
                    }
                    Some(_) => break,
                    None => {
                        // An indented line continues the previous item.
                        let cont = l.trim_start();
                        if !cont.is_empty() && l.len() - cont.len() >= 2 && !items.is_empty() && fence_open(l).is_none() {
                            let last = items.last_mut().expect("items");
                            last.push(' ');
                            last.push_str(cont);
                            i += 1;
                        } else {
                            break;
                        }
                    }
                }
            }
            let lis: String = items.iter().map(|t| format!("<li>{}</li>", inline(t))).collect();
            if numbered {
                let attr = if start != 1 { format!(" start=\"{start}\"") } else { String::new() };
                out.push(format!("<ol{attr}>{lis}</ol>"));
            } else {
                out.push(format!("<ul>{lis}</ul>"));
            }
            continue;
        }
        if let Some(fig) = figure(line.trim()) {
            out.push(fig);
            i += 1;
            continue;
        }
        out.push(format!("<p>{}</p>", inline(line.trim())));
        i += 1;
    }
}

/// A line that is exactly one image.
fn figure(line: &str) -> Option<String> {
    let chars: Vec<char> = line.chars().collect();
    if chars.first() != Some(&'!') || chars.get(1) != Some(&'[') {
        return None;
    }
    let (alt, url, end) = link_parts(&chars, 1)?;
    if end != chars.len() {
        return None;
    }
    let caption = if alt.trim().is_empty() { String::new() } else { format!("<figcaption>{}</figcaption>", esc(alt.trim())) };
    Some(format!("<figure>{}{caption}</figure>", img(&alt, &url)))
}

fn img(alt: &str, url: &str) -> String {
    format!("<img src=\"{}\" alt=\"{}\" loading=\"lazy\" decoding=\"async\">", esc(url), esc(alt.trim()))
}

fn safe_url(raw: &str) -> Option<String> {
    let url = raw.trim();
    let url = url.strip_prefix('<').and_then(|u| u.strip_suffix('>')).unwrap_or(url);
    if !url.starts_with("https://") || url.len() <= 8 || url.len() > 4000 {
        return None;
    }
    if url.chars().any(|c| c.is_whitespace() || c.is_control() || matches!(c, '"' | '<' | '>' | '`' | '\\')) {
        return None;
    }
    Some(url.to_string())
}

/// `[text](url "title")` starting at `chars[at] == '['`: text, url, index after `)`.
fn link_parts(chars: &[char], at: usize) -> Option<(String, String, usize)> {
    if chars.get(at) != Some(&'[') {
        return None;
    }
    let mut depth = 0usize;
    let mut j = at;
    let close = loop {
        let c = *chars.get(j)?;
        match c {
            '\\' => j += 1,
            '[' => depth += 1,
            ']' => {
                depth -= 1;
                if depth == 0 {
                    break j;
                }
            }
            _ => {}
        }
        j += 1;
    };
    if chars.get(close + 1) != Some(&'(') {
        return None;
    }
    let mut k = close + 2;
    let mut paren = 0usize;
    let end = loop {
        let c = *chars.get(k)?;
        match c {
            '(' => paren += 1,
            ')' if paren == 0 => break k,
            ')' => paren -= 1,
            _ => {}
        }
        k += 1;
    };
    let inside: String = chars[close + 2..end].iter().collect();
    let target = inside.trim();
    // Drop an optional "title".
    let target = match target.find(" \"") {
        Some(p) if target.ends_with('"') => &target[..p],
        _ => target,
    };
    let url = safe_url(target)?;
    let text: String = chars[at + 1..close].iter().collect();
    Some((text, url, end + 1))
}

fn is_word(c: Option<&char>) -> bool {
    c.is_some_and(|c| c.is_alphanumeric())
}

/// Find a closing delimiter run that is not preceded by whitespace.
fn find_close(chars: &[char], from: usize, delim: &[char]) -> Option<usize> {
    let mut j = from;
    while j + delim.len() <= chars.len() {
        if chars[j] == '\\' {
            j += 2;
            continue;
        }
        if chars[j] == '`' {
            // Skip code spans so `*` inside code never closes emphasis.
            if let Some(end) = chars[j + 1..].iter().position(|&c| c == '`') {
                j += end + 2;
                continue;
            }
        }
        if chars[j..j + delim.len()] == *delim && j > from && !chars[j - 1].is_whitespace() {
            let single = delim.len() == 1;
            let next_same = chars.get(j + 1) == Some(&delim[0]);
            if !single || !next_same {
                return Some(j);
            }
            j += 2;
            continue;
        }
        j += 1;
    }
    None
}

pub(crate) fn inline(text: &str) -> String {
    inline_chars(&text.chars().collect::<Vec<_>>(), true, 0)
}

fn inline_chars(chars: &[char], links: bool, depth: usize) -> String {
    let mut out = String::new();
    let mut i = 0;
    let deeper = |s: &[char], l: bool| if depth > 8 { esc(&s.iter().collect::<String>()) } else { inline_chars(s, l, depth + 1) };
    while i < chars.len() {
        let c = chars[i];
        if c == '\\' {
            if let Some(&n) = chars.get(i + 1) {
                if n.is_ascii_punctuation() {
                    out.push_str(&esc(&n.to_string()));
                    i += 2;
                    continue;
                }
            }
        }
        if c == '`' {
            if let Some(end) = chars[i + 1..].iter().position(|&x| x == '`') {
                let code: String = chars[i + 1..i + 1 + end].iter().collect();
                if !code.is_empty() {
                    out.push_str(&format!("<code>{}</code>", esc(&code)));
                    i += end + 2;
                    continue;
                }
            }
        }
        if c == '!' && chars.get(i + 1) == Some(&'[') {
            if let Some((alt, url, end)) = link_parts(chars, i + 1) {
                out.push_str(&img(&alt, &url));
                i = end;
                continue;
            }
        }
        if c == '[' && links {
            if let Some((label, url, end)) = link_parts(chars, i) {
                let label_chars: Vec<char> = label.chars().collect();
                out.push_str(&format!(
                    "<a href=\"{}\" rel=\"nofollow noopener noreferrer\" target=\"_blank\">{}</a>",
                    esc(&url),
                    deeper(&label_chars, false)
                ));
                i = end;
                continue;
            }
        }
        if links && c == 'h' && !is_word(i.checked_sub(1).and_then(|p| chars.get(p))) && chars[i..].starts_with(&['h', 't', 't', 'p', 's', ':', '/', '/']) {
            let mut end = i;
            while end < chars.len() && !chars[end].is_whitespace() && !matches!(chars[end], '<' | '>' | '"' | '`') {
                end += 1;
            }
            // Leave trailing punctuation (and an unmatched ")") outside the link.
            while end > i + 8 {
                let last = chars[end - 1];
                let opens = chars[i..end].iter().filter(|&&x| x == '(').count();
                let closes = chars[i..end].iter().filter(|&&x| x == ')').count();
                if matches!(last, '.' | ',' | ';' | ':' | '!' | '?' | '\'' | '*' | '_' | '~') || (last == ')' && closes > opens) {
                    end -= 1;
                } else {
                    break;
                }
            }
            let raw: String = chars[i..end].iter().collect();
            if let Some(url) = safe_url(&raw) {
                out.push_str(&format!("<a href=\"{0}\" rel=\"nofollow noopener noreferrer\" target=\"_blank\">{0}</a>", esc(&url)));
                i = end;
                continue;
            }
        }
        let mut matched = false;
        for (delim, tag) in [(&['*', '*'][..], "strong"), (&['_', '_'][..], "strong"), (&['~', '~'][..], "del")] {
            if chars[i..].starts_with(delim) {
                let opens = chars.get(i + 2).is_some_and(|c| !c.is_whitespace());
                let word_ok = delim[0] != '_' || !is_word(i.checked_sub(1).and_then(|p| chars.get(p)));
                if opens && word_ok {
                    if let Some(close) = find_close(chars, i + 2, delim) {
                        if delim[0] != '_' || !is_word(chars.get(close + 2)) {
                            out.push_str(&format!("<{tag}>{}</{tag}>", deeper(&chars[i + 2..close], links)));
                            i = close + 2;
                            matched = true;
                            break;
                        }
                    }
                }
            }
        }
        if matched {
            continue;
        }
        if (c == '*' || c == '_') && chars.get(i + 1) != Some(&c) {
            let opens = chars.get(i + 1).is_some_and(|x| !x.is_whitespace());
            let word_ok = c == '*' || !is_word(i.checked_sub(1).and_then(|p| chars.get(p)));
            if opens && word_ok {
                if let Some(close) = find_close(chars, i + 1, &[c]) {
                    if c == '*' || !is_word(chars.get(close + 1)) {
                        out.push_str(&format!("<em>{}</em>", deeper(&chars[i + 1..close], links)));
                        i = close + 1;
                        continue;
                    }
                }
            }
        }
        out.push_str(&esc(&c.to_string()));
        i += 1;
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    fn r(md: &str) -> String {
        render(md).html
    }

    #[test]
    fn original_syntax_keeps_its_output() {
        assert_eq!(r("# Title"), "<h2>Title</h2>");
        assert_eq!(r("## Sub"), "<h3>Sub</h3>");
        assert_eq!(r("### Small"), "<h4>Small</h4>");
        assert_eq!(r("one\ntwo"), "<p>one</p>\n<p>two</p>");
        assert_eq!(r("- a\n- b"), "<ul><li>a</li><li>b</li></ul>");
        assert_eq!(r("**b** and *i*"), "<p><strong>b</strong> and <em>i</em></p>");
        assert_eq!(
            r("[VAAK](https://mkultra.monster)"),
            "<p><a href=\"https://mkultra.monster\" rel=\"nofollow noopener noreferrer\" target=\"_blank\">VAAK</a></p>"
        );
    }

    #[test]
    fn formatting_inside_headings_and_lists() {
        assert_eq!(r("## A **bold** idea"), "<h3>A <strong>bold</strong> idea</h3>");
        assert_eq!(r("- *one* thing"), "<ul><li><em>one</em> thing</li></ul>");
    }

    #[test]
    fn new_blocks() {
        assert_eq!(r("1. one\n2. two"), "<ol><li>one</li><li>two</li></ol>");
        assert_eq!(r("3) three\n4) four"), "<ol start=\"3\"><li>three</li><li>four</li></ol>");
        assert_eq!(r("> quoted **text**\n> more"), "<blockquote><p>quoted <strong>text</strong></p>\n<p>more</p></blockquote>");
        assert_eq!(r("---"), "<hr>");
        assert_eq!(r("```rust\nlet a = 1 < 2;\n```"), "<pre><code class=\"language-rust\">let a = 1 &lt; 2;</code></pre>");
        assert_eq!(
            r("![A cat](https://x.test/c.jpg)"),
            "<figure><img src=\"https://x.test/c.jpg\" alt=\"A cat\" loading=\"lazy\" decoding=\"async\"><figcaption>A cat</figcaption></figure>"
        );
        assert_eq!(r("~~old~~ and `code *x*`"), "<p><del>old</del> and <code>code *x*</code></p>");
        assert_eq!(r("- a\n  continued"), "<ul><li>a continued</li></ul>");
    }

    #[test]
    fn autolinks_trim_punctuation() {
        assert_eq!(
            r("See https://mkultra.monster/a_(b)."),
            "<p>See <a href=\"https://mkultra.monster/a_(b)\" rel=\"nofollow noopener noreferrer\" target=\"_blank\">https://mkultra.monster/a_(b)</a>.</p>"
        );
    }

    #[test]
    fn hostile_input_stays_text() {
        let html = r("<script>alert(1)</script>\n[x](javascript:alert(1))\n![y](http://plain.test/a.png)\n[z](https://ok.test/\"onmouseover=\"x)");
        assert!(!html.contains("<script"));
        assert!(!html.contains("href=\"javascript"));
        assert!(!html.contains("src=\"http://"));
        assert!(!html.contains("onmouseover=\""));
        assert!(html.contains("&lt;script&gt;"));
        assert_eq!(r("snake_case_name"), "<p>snake_case_name</p>");
        assert_eq!(r("2 * 3 * 4"), "<p>2 * 3 * 4</p>");
        assert_eq!(r("\\*not italic\\*"), "<p>*not italic*</p>");
        // Unclosed fence runs to the end without panicking.
        assert_eq!(r("```\nopen"), "<pre><code>open</code></pre>");
        // Deep nesting is capped.
        let deep = "> ".repeat(40) + "x";
        assert!(r(&deep).matches("<blockquote>").count() <= 5);
    }

    #[test]
    fn counts_words() {
        let out = render("# Hi there\n\nSome **words** here. https://x.test\n```\nignored code\n```");
        assert_eq!(out.words, 5);
        assert_eq!(out.minutes, 1);
        assert_eq!(minutes(221), 2);
    }

    #[test]
    fn never_panics_on_odd_input() {
        let pieces = ["*", "**", "_", "__", "~~", "`", "[", "]", "(", ")", "!", "\\", "https://", "é", "😀", " ", "\n", "> ", "- ", "1. ", "# ", "```", "a", "<", "\""];
        let mut seed: u64 = 7;
        for _ in 0..4000 {
            let mut s = String::new();
            for _ in 0..24 {
                seed = seed.wrapping_mul(6364136223846793005).wrapping_add(1442695040888963407);
                s.push_str(pieces[(seed >> 33) as usize % pieces.len()]);
            }
            let html = render(&s).html;
            assert!(!html.contains("<script"));
        }
    }
}
