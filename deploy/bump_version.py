#!/usr/bin/env python3
"""Bump the VAAK version (Semantic Versioning).

api/ap-version.php is the only version source. This script copies that
number into the live README lines and adds the release's notes to the top
of CHANGELOG.md.

Versions follow SemVer (https://semver.org): MAJOR.MINOR.PATCH with no
leading zeros. A deploy picks the bump level:

    patch  0.10.3 -> 0.10.4
    minor  0.10.3 -> 0.11.0
    major  0.10.3 -> 1.0.0

Releases up to 0.9.83 used a two-digit deploy counter (0.9.05). Those
numbers still parse so old commits and rollbacks keep working, but every
new number is written without leading zeros.
"""

from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

REPO_URL = "https://github.com/cmdr-nova/VAAK"
LEVELS = ("patch", "minor", "major")

# A version this script writes: strict SemVer core, no leading zeros.
VERSION_RE = re.compile(r"^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$")
# A version this script reads: also accepts the old two-digit counter (0.9.05).
READ_RE = re.compile(r"^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(\d+)$")
DATE_RE = re.compile(r"^\d{4}-\d{2}-\d{2}$")
PHP_VERSION_RE = re.compile(r"(const VAAK_VERSION = ')([^']+)(';)", re.M)
PHP_DATE_RE = re.compile(r"(const VAAK_VERSION_DATE = ')([^']+)(';)", re.M)
PHP_CHANNEL_RE = re.compile(r"const VAAK_CHANNEL = '([^']+)';")

V = r"\d+\.\d+\.\d+"
CURRENT_RE = re.compile(
    rf"^\*\*Current release:\*\* (\w+) \*\*({V})\*\* · (\d{{4}}-\d{{2}}-\d{{2}})$",
    re.M,
)
NOTES_RE = re.compile(
    rf"^Notes: \[v({V})\]\({re.escape(REPO_URL)}/releases/tag/v({V})\) · \[CHANGELOG\]\(CHANGELOG\.md\)\.$",
    re.M,
)
STATUS_RE = re.compile(
    rf"^(Active \w+\. \*\*)({V})(\*\* is the deployed version in `api/ap-version\.php`\.)",
    re.M,
)
RUST_RE = re.compile(
    rf"^Deployed release: \[\*\*(\w+) ({V}) · (\d{{4}}-\d{{2}}-\d{{2}})\*\*\]\({re.escape(REPO_URL)}/releases/tag/v({V})\)\.$",
    re.M,
)
# One release section in CHANGELOG.md starts with this heading.
SECTION_RE = re.compile(rf"^## \[({V})\]\({re.escape(REPO_URL)}/releases/tag/v({V})\) - (\d{{4}}-\d{{2}}-\d{{2}})$", re.M)


class VersionError(ValueError):
    """The version text is not a VAAK version."""


def _parts(current: str) -> tuple[int, int, int]:
    match = READ_RE.fullmatch(current)
    if match is None:
        raise VersionError(f"version {current!r} must look like 0.10.3")
    return int(match.group(1)), int(match.group(2)), int(match.group(3))


def require_semver(version: str) -> str:
    if VERSION_RE.fullmatch(version) is None:
        raise VersionError(f"version {version!r} is not MAJOR.MINOR.PATCH without leading zeros")
    return version


def bump(current: str, level: str) -> str:
    """Return the SemVer bump of current at level (patch, minor, major)."""
    major, minor, patch = _parts(current)
    if level == "patch":
        return f"{major}.{minor}.{patch + 1}"
    if level == "minor":
        return f"{major}.{minor + 1}.0"
    if level == "major":
        return f"{major + 1}.0.0"
    raise VersionError(f"bump level {level!r} must be one of {', '.join(LEVELS)}")


def next_version(current: str) -> str:
    """The patch bump. Kept for callers that only need the smallest step."""
    return bump(current, "patch")


def bump_level(parent: str, child: str) -> str | None:
    """The level that turns parent into child, or None if no single bump does."""
    for level in LEVELS:
        if bump(parent, level) == child:
            return level
    return None


def parse_version_file(text: str) -> tuple[str, str, str]:
    """Return version, channel, and date from the text of api/ap-version.php."""
    version = _one(PHP_VERSION_RE, text, "VAAK_VERSION")
    channel = _one(PHP_CHANNEL_RE, text, "VAAK_CHANNEL")
    date = _one(PHP_DATE_RE, text, "VAAK_VERSION_DATE")
    _parts(version)
    if not DATE_RE.fullmatch(date):
        raise VersionError(f"VAAK_VERSION_DATE {date!r} is not YYYY-MM-DD")
    if channel == "":
        raise VersionError("VAAK_CHANNEL is empty")
    return version, channel, date


def read_version(root: Path) -> tuple[str, str, str]:
    text = (root / "api" / "ap-version.php").read_text(encoding="utf-8")
    return parse_version_file(text)


def version_less(left: str, right: str) -> bool:
    """True when left is an earlier version than right."""
    return _parts(left) < _parts(right)


def _one(pattern: re.Pattern[str], text: str, label: str) -> str:
    found = pattern.findall(text)
    if len(found) != 1:
        raise VersionError(f"{label} must appear exactly once in api/ap-version.php")
    item = found[0]
    return item[1] if isinstance(item, tuple) else item


def release_url(version: str) -> str:
    return f"{REPO_URL}/releases/tag/v{version}"


def normalize_notes(body: str) -> str:
    """Turn GitHub's generated release notes into a CHANGELOG section body.

    Drops HTML comments and the "What's Changed" heading, and moves any
    other level-2 heading down to level 3 so a section never contains a
    line that looks like the next release heading.
    """
    body = re.sub(r"<!--.*?-->", "", body, flags=re.S)
    lines: list[str] = []
    for line in body.replace("\r\n", "\n").split("\n"):
        stripped = line.rstrip()
        if re.fullmatch(r"#{1,2} What's Changed", stripped):
            continue
        if re.match(r"^#{1,2} ", stripped):
            stripped = "### " + stripped.lstrip("#").lstrip()
        lines.append(stripped)
    text = "\n".join(lines).strip("\n")
    text = re.sub(r"\n{3,}", "\n\n", text)
    return text or "No pull requests were labeled for this release."


def section_text(version: str, date: str, notes: str) -> str:
    return f"## [{version}]({release_url(version)}) - {date}\n\n{normalize_notes(notes)}\n\n"


def _split_changelog(text: str) -> tuple[str, str]:
    """Split CHANGELOG.md into the intro and the release sections."""
    match = SECTION_RE.search(text)
    if match is None:
        return (text.rstrip("\n") + "\n\n" if text.strip() else ""), ""
    return text[: match.start()], text[match.start() :]


def changelog_notes(text: str, version: str) -> str | None:
    """Body of the section for version in CHANGELOG text, or None."""
    matches = list(SECTION_RE.finditer(text))
    for index, match in enumerate(matches):
        if match.group(1) != version:
            continue
        end = matches[index + 1].start() if index + 1 < len(matches) else len(text)
        return text[match.end() : end].strip("\n")
    return None


def changelog_date(text: str, version: str) -> str | None:
    for match in SECTION_RE.finditer(text):
        if match.group(1) == version:
            return match.group(3)
    return None


def apply_version(root: Path, version: str, date: str, notes: str | None = None) -> str:
    """Write version and date through every live surface. Return the version.

    When the version changes, notes become a new section at the top of
    CHANGELOG.md. Existing sections are never rewritten.
    """
    require_semver(version)
    if not DATE_RE.fullmatch(date):
        raise VersionError(f"date {date!r} is not YYYY-MM-DD")
    current, channel, _old_date = read_version(root)
    php_path = root / "api" / "ap-version.php"
    php = php_path.read_text(encoding="utf-8")
    php, n_version = PHP_VERSION_RE.subn(rf"\g<1>{version}\g<3>", php, count=1)
    php, n_date = PHP_DATE_RE.subn(rf"\g<1>{date}\g<3>", php, count=1)
    if n_version != 1 or n_date != 1:
        raise VersionError("could not update api/ap-version.php constants")

    changelog = root / "CHANGELOG.md"
    log = changelog.read_text(encoding="utf-8") if changelog.exists() else ""
    if version != current:
        if changelog_notes(log, version) is not None:
            raise VersionError(f"CHANGELOG.md already has {version}; refusing to rewrite a release")
        intro, rest = _split_changelog(log)
        log = intro + section_text(version, date, notes or "") + rest

    readme = root / "README.md"
    rust = root / "rust" / "README.md"
    readme_text = readme.read_text(encoding="utf-8")
    readme_text = _replace_exact(readme_text, readme, CURRENT_RE, f"**Current release:** {channel} **{version}** · {date}")
    readme_text = _replace_exact(
        readme_text, readme, NOTES_RE, f"Notes: [v{version}]({release_url(version)}) · [CHANGELOG](CHANGELOG.md)."
    )
    readme_text = _replace_group(readme_text, readme, STATUS_RE, version)
    rust_text = _replace_exact(
        rust.read_text(encoding="utf-8"),
        rust,
        RUST_RE,
        f"Deployed release: [**{channel} {version} · {date}**]({release_url(version)}).",
    )

    php_path.write_text(php, encoding="utf-8")
    readme.write_text(readme_text, encoding="utf-8")
    rust.write_text(rust_text, encoding="utf-8")
    if version != current:
        changelog.write_text(log, encoding="utf-8")
    return version


def _replace_exact(text: str, path: Path, pattern: re.Pattern[str], replacement: str) -> str:
    found = list(pattern.finditer(text))
    if len(found) != 1:
        raise VersionError(f"{path} must contain exactly one line matching {pattern.pattern}")
    start, end = found[0].span()
    return text[:start] + replacement + text[end:]


def _replace_group(text: str, path: Path, pattern: re.Pattern[str], version: str) -> str:
    found = list(pattern.finditer(text))
    if len(found) != 1:
        raise VersionError(f"{path} must contain exactly one status version line")
    match = found[0]
    start, end = match.span()
    return text[:start] + f"{match.group(1)}{version}{match.group(3)}" + text[end:]


def check(root: Path) -> None:
    version, channel, date = read_version(root)
    readme = (root / "README.md").read_text(encoding="utf-8")
    rust = (root / "rust" / "README.md").read_text(encoding="utf-8")
    current = CURRENT_RE.search(readme)
    notes = NOTES_RE.search(readme)
    status = STATUS_RE.search(readme)
    rust_line = RUST_RE.search(rust)
    if current is None or current.group(1) != channel or current.group(2) != version or current.group(3) != date:
        raise VersionError("README current-release line does not match api/ap-version.php")
    if notes is None or notes.group(1) != version or notes.group(2) != version:
        raise VersionError("README notes line does not match api/ap-version.php")
    if status is None or status.group(2) != version:
        raise VersionError("README status line does not match api/ap-version.php")
    if (
        rust_line is None
        or rust_line.group(1) != channel
        or rust_line.group(2) != version
        or rust_line.group(3) != date
        or rust_line.group(4) != version
    ):
        raise VersionError("rust/README.md release line does not match api/ap-version.php")
    changelog = root / "CHANGELOG.md"
    if not changelog.is_file():
        raise VersionError(f"missing {changelog}")
    log = changelog.read_text(encoding="utf-8")
    seen: set[str] = set()
    for match in SECTION_RE.finditer(log):
        if match.group(1) != match.group(2):
            raise VersionError(f"CHANGELOG.md heading for {match.group(1)} links to v{match.group(2)}")
        if match.group(1) in seen:
            raise VersionError(f"CHANGELOG.md has two sections for {match.group(1)}")
        seen.add(match.group(1))


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Bump or check the VAAK version")
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[1])
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--print-current", action="store_true")
    mode.add_argument("--print-next", choices=LEVELS, help="Print the version a bump at this level would write")
    mode.add_argument("--print-notes", metavar="VERSION", help="Print the CHANGELOG.md section body for VERSION")
    mode.add_argument("--check", action="store_true")
    mode.add_argument("--bump", choices=LEVELS, help="Write the next version at this SemVer level")
    mode.add_argument("--version", help="Write this exact version")
    parser.add_argument("--date", help="YYYY-MM-DD for --bump or --version")
    parser.add_argument("--notes-file", type=Path, help="Release notes (GitHub generated markdown) for CHANGELOG.md")
    args = parser.parse_args(argv)
    root = args.root.resolve()
    try:
        if args.print_current:
            print(read_version(root)[0])
            return 0
        if args.print_next:
            print(bump(read_version(root)[0], args.print_next))
            return 0
        if args.print_notes:
            changelog = root / "CHANGELOG.md"
            text = changelog.read_text(encoding="utf-8") if changelog.exists() else ""
            body = changelog_notes(text, args.print_notes)
            if body is None:
                raise VersionError(f"CHANGELOG.md has no section for {args.print_notes}")
            print(body)
            return 0
        if args.check:
            check(root)
            print(f"version_ok {read_version(root)[0]}")
            return 0
        if not args.date:
            raise VersionError("--date is required when writing a version")
        notes = args.notes_file.read_text(encoding="utf-8") if args.notes_file else ""
        version = bump(read_version(root)[0], args.bump) if args.bump else args.version
        written = apply_version(root, version, args.date, notes)
        check(root)
        print(written)
        return 0
    except VersionError as exc:
        print(f"version_error: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
