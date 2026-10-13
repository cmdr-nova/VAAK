#!/usr/bin/env python3
"""Decide whether a commit is a VAAK version bump that can ship without its own CI.

GitHub does not start workflows for a push made with GITHUB_TOKEN. The
release commit this workflow creates therefore has no check runs. It is
safe to ship when:

- it has one parent
- that parent is the CI-green commit the guard already accepted
- the version is exactly one counter step ahead of the parent
- the diff is only the files deploy/bump_version.py writes, byte for byte

If main already is that commit, or later commits did not change the app
and the server is still on an older counter, a run with version_bump=increment
ships this version instead of creating the next number.
"""

from __future__ import annotations

import argparse
import os
import re
import subprocess
import sys
import tempfile
from pathlib import Path

from bump_version import (
    VERSION_RE,
    VersionError,
    apply_version,
    next_version,
    parse_version_file,
    version_less,
)

# Files the bump writes, other than the new release note. vaak/index.php
# renders vaak_version_label() and is not edited. The VERSION file inside a
# release directory is created at assemble time and is not in git.
VERSION_PATHS = (
    "api/ap-version.php",
    "README.md",
    "rust/README.md",
)

# Paths that change the running app or the worker binary. A commit after the
# release commit that touches one of these is a real change and may take a
# new counter value. Workflow, docs, and deploy tooling do not.
SHIPPED_PREFIXES = (
    "api/",
    "vaak/",
    "rust/",
)
SHIPPED_EXACT = frozenset(
    {
        "deploy/ap-bsky-jetstream.py",
        "deploy/ap-bsky-jetstream-requirements.txt",
        "deploy/wafrn-friend-hosts.txt",
        "deploy/remote/migrate.php",
        "deploy/ci-build-worker.sh",
    }
)

MAX_WALK = 40
PLAN_KEYS = ("kind", "reuse", "sha", "source_sha", "version", "next_version")
KINDS = frozenset({"version-only", "reuse-tree", "ci-required"})
RAW_RE = re.compile(r"^:(\d{6}) (\d{6}) [0-9a-f]+ [0-9a-f]+ ([A-Z])[0-9]*\t(.+)$")


class ReleaseError(RuntimeError):
    """The commit is not a pure version bump of a known parent."""


def release_note_path(version: str) -> str:
    return f"docs/RELEASE-{version}.md"


def is_shipped_path(path: str) -> bool:
    return path.startswith(SHIPPED_PREFIXES) or path in SHIPPED_EXACT


def git(repo: Path, *args: str, binary: bool = False) -> str | bytes:
    result = subprocess.run(
        ["git", "-C", str(repo), *args],
        check=False,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env={**os.environ, "GIT_TERMINAL_PROMPT": "0"},
    )
    if result.returncode != 0:
        err = result.stderr.decode("utf-8", "replace").strip()
        raise ReleaseError(err or f"git {' '.join(args)} failed")
    if binary:
        return result.stdout
    return result.stdout.decode("utf-8")


def ensure_commit(repo: Path, sha: str) -> str:
    if not re.fullmatch(r"[0-9a-f]{40}", sha):
        raise ReleaseError("sha must be 40 hex characters")
    resolved = str(git(repo, "rev-parse", "--verify", f"{sha}^{{commit}}")).strip()
    if resolved != sha:
        raise ReleaseError(f"{sha} is not that commit")
    return resolved


def parents_of(repo: Path, sha: str) -> list[str]:
    line = str(git(repo, "rev-list", "--parents", "-n", "1", sha)).strip()
    parts = line.split()
    if not parts or parts[0] != sha:
        raise ReleaseError(f"could not read parents of {sha}")
    return parts[1:]


def version_at(repo: Path, sha: str) -> tuple[str, str, str]:
    try:
        blob = git(repo, "show", f"{sha}:api/ap-version.php", binary=True)
    except ReleaseError as exc:
        raise ReleaseError(f"{sha} has no api/ap-version.php") from exc
    if not isinstance(blob, bytes):
        raise ReleaseError(f"{sha} version file was not readable")
    try:
        return parse_version_file(blob.decode("utf-8"))
    except VersionError as exc:
        raise ReleaseError(f"{sha} api/ap-version.php: {exc}") from exc


def diff_entries(repo: Path, parent: str, sha: str) -> list[tuple[str, str, str, str]]:
    """Return (old_mode, new_mode, status, path) for each changed path."""
    raw = str(git(repo, "diff", "--raw", "--no-renames", parent, sha))
    entries: list[tuple[str, str, str, str]] = []
    for line in raw.splitlines():
        if line == "":
            continue
        match = RAW_RE.fullmatch(line)
        if match is None:
            raise ReleaseError(f"unparsed diff line: {line}")
        entries.append((match.group(1), match.group(2), match.group(3), match.group(4)))
    return entries


def changed_paths(repo: Path, parent: str, sha: str) -> list[str]:
    out = str(git(repo, "diff", "--name-only", "--no-renames", parent, sha))
    return [line for line in out.splitlines() if line]


def verify_release(repo: Path, sha: str, parent: str) -> None:
    """Raise unless sha is exactly the version bump of parent."""
    sha = ensure_commit(repo, sha)
    parent = ensure_commit(repo, parent)
    found = parents_of(repo, sha)
    if found != [parent]:
        raise ReleaseError(f"{sha} parent is {found or 'none'}, expected {parent}")
    child_version, _channel, child_date = version_at(repo, sha)
    parent_version, _parent_channel, _parent_date = version_at(repo, parent)
    if child_version != next_version(parent_version):
        raise ReleaseError(
            f"{sha} version {child_version} is not the next counter value after {parent_version}"
        )
    expected = {
        "api/ap-version.php": ("100644", "100644", "M"),
        "README.md": ("100644", "100644", "M"),
        "rust/README.md": ("100644", "100644", "M"),
        release_note_path(child_version): ("000000", "100644", "A"),
    }
    entries = diff_entries(repo, parent, sha)
    actual = {path: (old, new, status) for old, new, status, path in entries}
    if actual != expected:
        raise ReleaseError(
            f"{sha} does not change only the version files "
            f"(expected {sorted(expected)}, got {sorted(actual)})"
        )
    with tempfile.TemporaryDirectory() as tmp:
        root = Path(tmp)
        for path in VERSION_PATHS:
            target = root / path
            target.parent.mkdir(parents=True, exist_ok=True)
            blob = git(repo, "show", f"{parent}:{path}", binary=True)
            if not isinstance(blob, bytes):
                raise ReleaseError(f"could not read {parent}:{path}")
            target.write_bytes(blob)
        (root / "docs").mkdir(parents=True, exist_ok=True)
        try:
            apply_version(root, child_version, child_date, parent)
        except VersionError as exc:
            raise ReleaseError(f"parent tree could not be bumped to match {sha}: {exc}") from exc
        for path in expected:
            blob = git(repo, "show", f"{sha}:{path}", binary=True)
            disk = (root / path).read_bytes()
            if blob != disk:
                raise ReleaseError(f"{path} in {sha} is not a pure version bump of {parent}")


def is_version_commit(repo: Path, sha: str) -> bool:
    found = parents_of(repo, sha)
    if len(found) != 1:
        return False
    try:
        verify_release(repo, sha, found[0])
    except (ReleaseError, VersionError):
        return False
    return True


def version_introducer(repo: Path, sha: str) -> str | None:
    """First commit on the way to sha that set the version sha still has.

    Walks first parents only, and only while api/ap-version.php keeps the
    same counter. Returns None when that introducing commit is not within
    MAX_WALK or the version file disappears.
    """
    version, _channel, _date = version_at(repo, sha)
    current = sha
    for _ in range(MAX_WALK):
        found = parents_of(repo, current)
        if not found:
            return None
        parent = found[0]
        try:
            parent_version = version_at(repo, parent)[0]
        except ReleaseError:
            return None
        if parent_version != version:
            return current
        current = parent
    return None


def plan_deploy(repo: Path, sha: str, version_bump: str) -> dict[str, str]:
    """Choose the CI-green commit, the tree to ship, and whether to bump."""
    if version_bump not in {"increment", "none"}:
        raise ReleaseError("version_bump must be increment or none")
    sha = ensure_commit(repo, sha)
    version = version_at(repo, sha)[0]
    if is_version_commit(repo, sha):
        parent = parents_of(repo, sha)[0]
        return _plan("version-only", True, sha, parent, version, version)
    anchor = version_introducer(repo, sha)
    if (
        anchor is not None
        and anchor != sha
        and is_version_commit(repo, anchor)
        and not any(is_shipped_path(path) for path in changed_paths(repo, anchor, sha))
    ):
        # The app still matches the release commit. This sha needs its own
        # green CI (it is not a GITHUB_TOKEN version bump). Do not invent
        # the next counter value for the same app.
        return _plan("reuse-tree", True, sha, sha, version, version)
    ship = version if version_bump == "none" else next_version(version)
    return _plan("ci-required", False, sha, sha, version, ship)


def _plan(
    kind: str,
    reuse: bool,
    sha: str,
    source_sha: str,
    version: str,
    ship: str,
) -> dict[str, str]:
    if kind not in KINDS:
        raise ReleaseError(f"bad plan kind {kind}")
    fields = {
        "kind": kind,
        "reuse": "true" if reuse else "false",
        "sha": sha,
        "source_sha": source_sha,
        "version": version,
        "next_version": ship,
    }
    format_plan(fields)
    return fields


def format_plan(fields: dict[str, str]) -> str:
    if fields.get("kind") not in KINDS:
        raise ReleaseError("plan kind is not valid")
    if fields.get("reuse") not in {"true", "false"}:
        raise ReleaseError("plan reuse flag is not valid")
    for key in ("sha", "source_sha"):
        if not re.fullmatch(r"[0-9a-f]{40}", fields.get(key, "")):
            raise ReleaseError(f"plan {key} is not a commit sha")
    for key in ("version", "next_version"):
        if VERSION_RE.fullmatch(fields.get(key, "")) is None:
            raise ReleaseError(f"plan {key} is not a VAAK counter")
    return "".join(f"{key}={fields[key]}\n" for key in PLAN_KEYS)


def live_is_older(live: str, ship: str) -> None:
    """Raise unless the server counter is strictly older than ship.

    increment uses this so an undeployed release commit is activated as
    itself. An equal or newer server refuses, instead of publishing the
    next number for code that already went out.
    """
    live = live.strip()
    ship = ship.strip()
    try:
        older = version_less(live, ship)
    except VersionError as exc:
        raise ReleaseError(str(exc)) from exc
    if not older:
        raise ReleaseError(
            f"server version {live} is not older than {ship}. "
            f"Refusing to publish {next_version(ship)} for code that is already this release. "
            "Run again with version_bump=none to redeploy this same commit."
        )


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Check a VAAK version-only release commit")
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[1])
    sub = parser.add_subparsers(dest="cmd", required=True)

    plan = sub.add_parser("plan", help="Print how this commit should be deployed")
    plan.add_argument("--sha", required=True)
    plan.add_argument("--version-bump", required=True, choices=["increment", "none"])
    plan.add_argument("--github-output", type=Path)
    plan.add_argument("--expect-kind")
    plan.add_argument("--expect-reuse")
    plan.add_argument("--expect-source")

    verify = sub.add_parser("verify", help="Require a pure version bump of the given parent")
    verify.add_argument("--sha", required=True)
    verify.add_argument("--parent", required=True)

    live = sub.add_parser("live-is-older", help="Require the server counter to be older")
    live.add_argument("--live", required=True)
    live.add_argument("--version", required=True)

    args = parser.parse_args(argv)
    root = args.root.resolve()
    try:
        if args.cmd == "verify":
            verify_release(root, args.sha, args.parent)
            print(f"release_ok {args.sha}")
            return 0
        if args.cmd == "live-is-older":
            live_is_older(args.live, args.version)
            print(f"live_ok {args.live.strip()} < {args.version.strip()}")
            return 0
        fields = plan_deploy(root, args.sha, args.version_bump)
        text = format_plan(fields)
        expects = (args.expect_kind, args.expect_reuse, args.expect_source)
        if any(item is not None for item in expects):
            if any(item is None for item in expects):
                raise ReleaseError("pass --expect-kind, --expect-reuse, and --expect-source together")
            if (
                fields["kind"] != args.expect_kind
                or fields["reuse"] != args.expect_reuse
                or fields["source_sha"] != args.expect_source
            ):
                raise ReleaseError(
                    "deploy plan changed after the guard job "
                    f"(now kind={fields['kind']} reuse={fields['reuse']} "
                    f"source={fields['source_sha']})"
                )
        if args.github_output is not None:
            with args.github_output.open("a", encoding="utf-8") as handle:
                handle.write(text)
        print(text, end="")
        return 0
    except (ReleaseError, VersionError) as exc:
        print(f"release_error: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
