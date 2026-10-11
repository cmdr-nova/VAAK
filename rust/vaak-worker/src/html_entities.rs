//! PHP ENT_QUOTES | ENT_HTML5 entity decoding. The committed HTML5 alias table
//! is generated from the HTML5 named references and verified by actual PHP;
//! aliases and multi-codepoint expansions are retained, including case.
use std::{collections::HashMap, sync::OnceLock};

pub(crate) fn decode(input: &str) -> String {
    static NAMED: OnceLock<HashMap<String, String>> = OnceLock::new();
    static TOKENS: OnceLock<regex::Regex> = OnceLock::new();
    let named = NAMED.get_or_init(|| serde_json::from_str(include_str!("html5_entities.json")).expect("verified HTML5 entity table"));
    let tokens = TOKENS.get_or_init(|| regex::Regex::new(r"&(#(?:[xX][0-9a-fA-F]+|[0-9]+)|[A-Za-z][A-Za-z0-9]*);").unwrap());
    tokens.replace_all(input, |capture: &regex::Captures| {
        let token = &capture[1];
        if let Some(numeric) = token.strip_prefix('#') {
            let codepoint = if let Some(hex) = numeric.strip_prefix('x').or_else(|| numeric.strip_prefix('X')) {
                u32::from_str_radix(hex, 16).ok()
            } else { numeric.parse::<u32>().ok() };
            if let Some(codepoint) = codepoint.filter(|point| valid_numeric(*point)) {
                if let Some(character) = char::from_u32(codepoint) { return character.to_string(); }
            }
        } else if let Some(value) = named.get(&format!("{token};")) { return value.clone(); }
        capture[0].to_owned()
    }).into_owned()
}

fn valid_numeric(point: u32) -> bool {
    (matches!(point, 9 | 10 | 12) || point >= 32)
        && !(127..=159).contains(&point) && !(0xFDD0..=0xFDEF).contains(&point)
        && point & 0xFFFF != 0xFFFE && point & 0xFFFF != 0xFFFF
        && point <= 0x10FFFF && !(0xD800..=0xDFFF).contains(&point)
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn entire_html5_alias_table_and_numeric_boundaries_match_actual_php() {
        let named: HashMap<String, String> = serde_json::from_str(include_str!("html5_entities.json")).unwrap();
        assert_eq!(named.len(), 2125);
        let mut cases: Vec<String> = named.keys().map(|name| format!("&{name}")).collect();
        for point in (0..=160).chain([0xD7FF,0xD800,0xDFFF,0xE000,0xFDD0,0xFDEF,0xFDF0,0xFFFD,0xFFFE,0xFFFF,0x1FFFE,0x1FFFF,0x10FFFD,0x10FFFF,0x110000]) {
            cases.push(format!("&#{point};")); cases.push(format!("&#x{point:X};"));
        }
        cases.extend(["&unknown;","&apos","&AMP","&#65","&#x;","&#-1;","&#999999999999999999999999999;","&amp;lt;","CAF&Eacute; &amp; tea"].map(str::to_owned));
        let mut process = std::process::Command::new(std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/php-policy-test")).args(["-r", "$cases=json_decode(stream_get_contents(STDIN),true);echo json_encode(array_map(fn($v)=>html_entity_decode($v, ENT_QUOTES|ENT_HTML5,'UTF-8'),$cases),JSON_THROW_ON_ERROR);"])
            .stdin(std::process::Stdio::piped()).stdout(std::process::Stdio::piped()).spawn().unwrap();
        use std::io::Write;
        process.stdin.take().unwrap().write_all(serde_json::to_string(&cases).unwrap().as_bytes()).unwrap();
        let output = process.wait_with_output().unwrap(); assert!(output.status.success());
        let expected: Vec<String> = serde_json::from_slice(&output.stdout).unwrap();
        for (input, expected) in cases.iter().zip(expected) { assert_eq!(decode(input), expected, "actual PHP ENT_HTML5 {input}"); }
    }
}
