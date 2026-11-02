#!/usr/bin/env python3
"""Generate VAAK release notes and pick the SemVer bump from them.

Notes come from GitHub's "generate release notes" API, which groups merged
pull requests by label using .github/release.yml. The same text becomes the
new CHANGELOG.md section (deploy/bump_version.py) and the GitHub Release.

auto picks the bump from those groups. VAAK is still 0.x, so following the
usual 0.x convention a breaking change or a feature is a minor bump and
everything else is a patch. A major bump (1.0.0) is only ever chosen by hand.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

# Category titles in .github/release.yml that make auto choose a minor bump.
MINOR_CATEGORIES = ("Breaking changes", "Features")


class NotesError(RuntimeError):
    pass


def auto_level(notes: str) -> str:
    """minor when the notes have a Breaking changes or Features group, else patch."""
    for line in notes.splitlines():
        match = re.match(r"^#{2,3} (.+?)\s*$", line)
        if match is None:
            continue
        title = match.group(1)
        if any(title == name or title.endswith(" " + name) for name in MINOR_CATEGORIES):
            return "minor"
    return "patch"


def _api(method: str, path: str, body: dict | None = None) -> tuple[int, dict]:
    token = os.environ.get("GITHUB_TOKEN", "")
    repo = os.environ.get("GITHUB_REPOSITORY", "")
    if token == "" or repo == "":
        raise NotesError("GITHUB_TOKEN and GITHUB_REPOSITORY are required")
    data = json.dumps(body).encode("utf-8") if body is not None else None
    request = urllib.request.Request(
        f"https://api.github.com/repos/{repo}{path}",
        data=data,
        method=method,
        headers={
            "Authorization": f"Bearer {token}",
            "Accept": "application/vnd.github+json",
            "User-Agent": "vaak-deploy",
            "X-GitHub-Api-Version": "2022-11-28",
            **({"Content-Type": "application/json"} if data is not None else {}),
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            return response.status, json.load(response)
    except urllib.error.HTTPError as exc:
        return exc.code, {}


def tag_exists(tag: str) -> bool:
    status, _ = _api("GET", f"/git/ref/tags/{urllib.parse.quote(tag, safe='')}")
    if status == 200:
        return True
    if status == 404:
        return False
    raise NotesError(f"GitHub tag lookup for {tag} returned {status}")


def generate(tag: str, target: str, previous: str | None) -> str:
    body: dict[str, str] = {"tag_name": tag, "target_commitish": target}
    if previous and tag_exists(previous):
        body["previous_tag_name"] = previous
    status, payload = _api("POST", "/releases/generate-notes", body)
    if status != 200 or not isinstance(payload.get("body"), str):
        raise NotesError(f"GitHub generate-notes returned {status}")
    return payload["body"]


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="VAAK release notes")
    sub = parser.add_subparsers(dest="cmd", required=True)
    gen = sub.add_parser("generate", help="Write GitHub generated notes for a release")
    gen.add_argument("--tag", required=True, help="Tag the release will get, e.g. v0.10.0")
    gen.add_argument("--target", required=True, help="Commit the notes run up to")
    gen.add_argument("--previous", help="Previous release tag, used when it exists")
    gen.add_argument("--output", type=Path, required=True)
    lvl = sub.add_parser("level", help="Print patch or minor for notes written by generate")
    lvl.add_argument("--notes", type=Path, required=True)
    args = parser.parse_args(argv)
    try:
        if args.cmd == "generate":
            if not re.fullmatch(r"[0-9a-f]{40}", args.target):
                raise NotesError("--target must be a 40-character commit sha")
            args.output.write_text(generate(args.tag, args.target, args.previous), encoding="utf-8")
            print(f"notes_ok {args.output}")
            return 0
        print(auto_level(args.notes.read_text(encoding="utf-8")))
        return 0
    except NotesError as exc:
        print(f"notes_error: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
