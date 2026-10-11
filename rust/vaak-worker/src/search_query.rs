//! Canonical PHP Search query normalization; no transport or source hydration.
use std::sync::OnceLock;

fn letters_numbers(character: char) -> bool {
    static PATTERN: OnceLock<regex::Regex> = OnceLock::new();
    let mut bytes = [0; 4];
    PATTERN.get_or_init(|| regex::Regex::new(r"^[\p{L}\p{N}_]$").unwrap())
        .is_match(character.encode_utf8(&mut bytes))
}

fn property(character: char, pattern: &'static str, cache: &OnceLock<regex::Regex>) -> bool {
    let mut bytes = [0; 4];
    cache.get_or_init(|| regex::Regex::new(pattern).unwrap())
        .is_match(character.encode_utf8(&mut bytes))
}

/// PHP mb_strtolower uses Unicode lowercase and contextual final sigma. Lowercase before token filtering so combining marks cannot leak into
/// tsquery tokens. PHP request UTF8 repair remains the source boundary's owner.
pub(super) fn lowercase(value: &str) -> String {
    static CASED: OnceLock<regex::Regex> = OnceLock::new();
    static IGNORABLE: OnceLock<regex::Regex> = OnceLock::new();
    let characters: Vec<char> = value.chars().collect();
    let cased = |c| property(c, r"^\p{Cased}$", &CASED);
    let ignorable = |c| property(c, r"^\p{Case_Ignorable}$", &IGNORABLE);
    let mut output = String::new();
    for (index, character) in characters.iter().copied().enumerate() {
        if character == 'Σ' {
            let previous = characters[..index].iter().rev().copied().find(|c| !ignorable(*c));
            let next = characters[index + 1..].iter().copied().find(|c| !ignorable(*c));
            output.push(if previous.is_some_and(cased) && !next.is_some_and(cased) { 'ς' } else { 'σ' });
        } else {
            output.extend(character.to_lowercase());
        }
    }
    output
}

fn ascii_trim(value: &str) -> &str {
    value.trim_matches([' ', '\t', '\n', '\r', '\0', '\u{b}'])
}

pub(super) fn text_match(value: &str) -> Option<String> {
    let value = ascii_trim(value);
    if value.chars().count() < 2 { return None; }
    let value = value.replace(['"', '\'', '*', '(', ')', ':', '^'], " ");
    let value = lowercase(&value);
    let tokens: Vec<String> = value.split_whitespace().filter_map(|part| {
        let token: String = part.chars().filter(|character| letters_numbers(*character)).collect();
        (token.chars().count() >= 2).then(|| format!("{token}*"))
    }).take(8).collect();
    (!tokens.is_empty()).then(|| tokens.join(" AND "))
}

pub(super) fn normalized_tag(tag: &str) -> String {
    // PHP strips before lowercase in its explicit tag branch.
    let token: String = tag.chars().filter(|character| letters_numbers(*character)).collect();
    let token = lowercase(&token);
    token
}

pub(super) fn tag_match(tag: &str) -> Option<String> {
    let token = normalized_tag(tag);
    (!token.is_empty()).then(|| format!("htag_{token} OR {token}"))
}

pub(super) fn postgres_match(value: &str) -> String {
    static PREFIX: OnceLock<regex::Regex> = OnceLock::new();
    PREFIX.get_or_init(|| regex::Regex::new(r"([^\s]+)\*").unwrap()).replace_all(value, "$1:*")
        .replace(" AND ", " & ").replace(" OR ", " | ")
}

#[cfg(test)]
mod tests {
    #[test]
    fn actual_php_unicode_normalization() {
        let root = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).parent().unwrap().parent().unwrap();
        let result = std::process::Command::new(root.join("api/bin/php-policy-test"))
            .arg(root.join("api/bin/search-query-reference.php")).output().unwrap();
        assert!(result.status.success(), "{}", String::from_utf8_lossy(&result.stderr));
        let fixtures: serde_json::Value = serde_json::from_slice(&result.stdout).unwrap();
        for fixture in fixtures.as_array().unwrap() {
            let query=fixture["query"].as_str().unwrap(); let tag=fixture["tag"].as_str().unwrap();
            let matched=if tag.is_empty(){super::text_match(query)}else{super::tag_match(tag)};
            assert_eq!(serde_json::json!(matched),fixture["match"],"actual PHP query {query:?} tag {tag:?}");
            assert_eq!(serde_json::json!(matched.map(|matched|super::postgres_match(&matched))),fixture["postgres"]);
        }
    }
}
