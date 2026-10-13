#!/usr/bin/env python3
"""Decide which optional production units a deploy should restart.

ap-bsky-jetstream restarts when the diff touches the Jetstream program or its
unit files. vaak-worker-shadow restarts when the diff touches the Rust worker
that the unit executes. PHP-only deploys restart neither.
"""

from __future__ import annotations

import sys

JETSTREAM_PATHS = frozenset(
    {
        "deploy/ap-bsky-jetstream.py",
        "deploy/ap-bsky-jetstream-requirements.txt",
        "deploy/wafrn-friend-hosts.txt",
        "deploy/ap-bsky-jetstream.service",
        "deploy/ap-bsky-jetstream@.service",
        "deploy/ap-bsky-jetstream-wanted.service",
        "deploy/ap-bsky-jetstream-worker.service",
    }
)

SHADOW_PREFIXES = (
    "rust/vaak-worker/",
    "rust/vaak-types/",
)
SHADOW_PATHS = frozenset(
    {
        "rust/Cargo.toml",
        "rust/Cargo.lock",
        "deploy/vaak-worker-shadow.service",
    }
)


def plan(paths: list[str]) -> tuple[bool, bool]:
    jetstream = any(path in JETSTREAM_PATHS for path in paths)
    shadow = any(
        path in SHADOW_PATHS or path.startswith(SHADOW_PREFIXES) for path in paths
    )
    return jetstream, shadow


def main(argv: list[str] | None = None) -> int:
    args = list(sys.argv[1:] if argv is None else argv)
    if args:
        paths = args
    else:
        paths = [line.strip() for line in sys.stdin if line.strip()]
    jetstream, shadow = plan(paths)
    print(f"VAAK_RESTART_JETSTREAM={int(jetstream)}")
    print(f"VAAK_RESTART_SHADOW={int(shadow)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
