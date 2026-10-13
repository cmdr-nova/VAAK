#!/usr/bin/env python3
"""Bump the VAAK release counter.

api/ap-version.php is the only version source. This script copies that
number into the live README lines and writes a new docs/RELEASE note.
Historical release notes are not rewritten.

The counter is not semver. The last component increments by 1 and is always
two digits. 0.8.99 rolls to 0.9.00. 0.99.99 rolls to 1.0.00.
"""

from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

VERSION_RE = re.compile(r"^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(\d{2})$")
DATE_RE = re.compile(r"^\d{4}-\d{2}-\d{2}$")
PHP_VERSION_RE = re.compile(r"(const VAAK_VERSION = ')([^']+)(';)", re.M)
PHP_DATE_RE = re.compile(r"(const VAAK_VERSION_DATE = ')([^']+)(';)", re.M)
PHP_CHANNEL_RE = re.compile(r"const VAAK_CHANNEL = '([^']+)';")

CURRENT_RE = re.compile(
    r"^\*\*Current release:\*\* (\w+) \*\*(\d+\.\d+\.\d{2})\*\* · (\d{4}-\d{2}-\d{2})$",
    re.M,
)
NOTES_RE = re.compile(
    r"^Notes: \[(\d+\.\d+\.\d{2})\]\(docs/RELEASE-(\d+\.\d+\.\d{2})\.md\)\.$",
    re.M,
)
STATUS_RE = re.compile(
    r"^(Active \w+\. \*\*)(\d+\.\d+\.\d{2})(\*\* is the deployed version in `api/ap-version\.php`\.)",
    re.M,
)
RUST_RE = re.compile(
    r"^Deployed release: \[\*\*(\w+) (\d+\.\d+\.\d{2}) · (\d{4}-\d{2}-\d{2})\*\*\]\(\.\./docs/RELEASE-(\d+\.\d+\.\d{2})\.md\)\.$",
    re.M,
)


class VersionError(ValueError):
    """The version text does not match the VAAK counter."""


def next_version(current: str) -> str:
    """Return the next deploy counter value.

    Last component + 1, always two digits. When that component would become
    100, the middle component increments and the last resets to 00.
    When the middle component would become 100, the first component
    increments and the middle resets to 0 (0.99.99 → 1.0.00).
    """
    major, middle, last = _parts(current)
    last += 1
    if last == 100:
        last = 0
        middle += 1
        if middle == 100:
            middle = 0
            major += 1
    return f"{major}.{middle}.{last:02d}"


def _parts(current: str) -> tuple[int, int, int]:
    match = VERSION_RE.fullmatch(current)
    if match is None:
        raise VersionError(
            f"version {current!r} must look like 0.8.45 "
            "(last component exactly two digits, no extra leading zeros)"
        )
    return int(match.group(1)), int(match.group(2)), int(match.group(3))


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
    """True when left is an earlier counter value than right."""
    return _parts(left) < _parts(right)


def _one(pattern: re.Pattern[str], text: str, label: str) -> str:
    found = pattern.findall(text)
    if len(found) != 1:
        raise VersionError(f"{label} must appear exactly once in api/ap-version.php")
    item = found[0]
    return item[1] if isinstance(item, tuple) else item


def apply_version(root: Path, version: str, date: str, commit: str) -> str:
    """Write version and date through every live surface. Return the version."""
    _parts(version)
    if not DATE_RE.fullmatch(date):
        raise VersionError(f"date {date!r} is not YYYY-MM-DD")
    current, channel, _old_date = read_version(root)
    php_path = root / "api" / "ap-version.php"
    php = php_path.read_text(encoding="utf-8")
    php, n_version = PHP_VERSION_RE.subn(rf"\g<1>{version}\g<3>", php, count=1)
    php, n_date = PHP_DATE_RE.subn(rf"\g<1>{date}\g<3>", php, count=1)
    if n_version != 1 or n_date != 1:
        raise VersionError("could not update api/ap-version.php constants")
    php_path.write_text(php, encoding="utf-8")

    readme = root / "README.md"
    _replace_exact(readme, CURRENT_RE, f"**Current release:** {channel} **{version}** · {date}")
    _replace_exact(readme, NOTES_RE, f"Notes: [{version}](docs/RELEASE-{version}.md).")
    _replace_group(readme, STATUS_RE, version)

    rust = root / "rust" / "README.md"
    rust_line = (
        f"Deployed release: [**{channel} {version} · {date}**](../docs/RELEASE-{version}.md)."
    )
    _replace_exact(rust, RUST_RE, rust_line)

    note = root / "docs" / f"RELEASE-{version}.md"
    if note.exists() and version != current:
        raise VersionError(f"{note} already exists; refusing to overwrite a release note")
    if version != current or not note.exists():
        sha = commit.strip() or "unspecified"
        note.write_text(
            "\n".join(
                [
                    f"# VAAK {channel} {version} — {date}",
                    "",
                    f"Production deploy of `{sha}`.",
                    "",
                    "Channel, number, and date come from `api/ap-version.php`.",
                    "The login and registration page renders `vaak_version_label()` from that file.",
                    "",
                ]
            ),
            encoding="utf-8",
        )
    return version


def _replace_exact(path: Path, pattern: re.Pattern[str], replacement: str) -> None:
    text = path.read_text(encoding="utf-8")
    found = list(pattern.finditer(text))
    if len(found) != 1:
        raise VersionError(f"{path} must contain exactly one line matching {pattern.pattern}")
    start, end = found[0].span()
    path.write_text(text[:start] + replacement + text[end:], encoding="utf-8")


def _replace_group(path: Path, pattern: re.Pattern[str], version: str) -> None:
    text = path.read_text(encoding="utf-8")
    found = list(pattern.finditer(text))
    if len(found) != 1:
        raise VersionError(f"{path} must contain exactly one status version line")
    match = found[0]
    replacement = f"{match.group(1)}{version}{match.group(3)}"
    start, end = match.span()
    path.write_text(text[:start] + replacement + text[end:], encoding="utf-8")


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
    note = root / "docs" / f"RELEASE-{version}.md"
    if not note.is_file():
        raise VersionError(f"missing {note}")


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Bump or check the VAAK release counter")
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[1])
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--print-current", action="store_true")
    mode.add_argument("--print-next", action="store_true")
    mode.add_argument("--check", action="store_true")
    mode.add_argument("--increment", action="store_true")
    mode.add_argument("--version", help="Write this counter value instead of incrementing")
    parser.add_argument("--date", help="YYYY-MM-DD for --increment or --version")
    parser.add_argument("--commit", default="", help="Git SHA recorded in the new release note")
    args = parser.parse_args(argv)
    root = args.root.resolve()
    try:
        if args.print_current:
            version, _channel, _date = read_version(root)
            print(version)
            return 0
        if args.print_next:
            version, _channel, _date = read_version(root)
            print(next_version(version))
            return 0
        if args.check:
            check(root)
            version, _channel, _date = read_version(root)
            print(f"version_ok {version}")
            return 0
        if not args.date:
            raise VersionError("--date is required when writing a version")
        if args.increment:
            version, _channel, _date = read_version(root)
            version = next_version(version)
        else:
            version = args.version
        written = apply_version(root, version, args.date, args.commit)
        check(root)
        print(written)
        return 0
    except VersionError as exc:
        print(f"version_error: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
