#!/usr/bin/env python3
"""Fail unless GitHub checks for a commit are green.

Check runs whose job name belongs to this deploy workflow are ignored, so
the workflow can require some other CI run without counting itself.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import time
import urllib.error
import urllib.request

OWN_JOBS = frozenset(
    {
        "vaak-deploy-guard",
        "vaak-deploy-release",
        "vaak-deploy-build",
        "vaak-deploy-ship",
        "vaak-deploy-rollback",
    }
)

SUCCESS = frozenset({"success", "skipped", "neutral"})


class CiError(RuntimeError):
    pass


def evaluate(check_runs: list[dict]) -> str:
    """Return ok, pending, fail, or absent."""
    others = [run for run in check_runs if run.get("name") not in OWN_JOBS]
    if any(run.get("status") != "completed" for run in others):
        return "pending"
    if any(run.get("conclusion") not in SUCCESS for run in others):
        return "fail"
    if not any(run.get("conclusion") == "success" for run in others):
        return "absent"
    return "ok"


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Require a green CI result for a commit")
    parser.add_argument("--sha", required=True)
    parser.add_argument("--wait", action="store_true", help="Poll until CI finishes or the timeout")
    parser.add_argument("--timeout-seconds", type=int, default=25 * 60)
    parser.add_argument("--interval-seconds", type=int, default=20)
    args = parser.parse_args(argv)
    if len(args.sha) != 40 or any(ch not in "0123456789abcdef" for ch in args.sha):
        print("ci_error: sha must be 40 hex characters", file=sys.stderr)
        return 1
    deadline = time.time() + args.timeout_seconds
    while True:
        try:
            runs = fetch_check_runs(args.sha)
        except CiError as exc:
            print(f"ci_error: {exc}", file=sys.stderr)
            return 1
        state = evaluate(runs)
        if state == "ok":
            print(f"ci_ok {args.sha}")
            return 0
        if state == "fail":
            print(f"ci_error: checks failed for {args.sha}", file=sys.stderr)
            return 1
        if not args.wait or time.time() >= deadline:
            print(f"ci_error: CI is {state} for {args.sha}", file=sys.stderr)
            return 1
        time.sleep(args.interval_seconds)


def fetch_check_runs(sha: str) -> list[dict]:
    token = os.environ.get("GITHUB_TOKEN", "")
    repo = os.environ.get("GITHUB_REPOSITORY", "")
    if token == "" or repo == "":
        raise CiError("GITHUB_TOKEN and GITHUB_REPOSITORY are required")
    runs: list[dict] = []
    page = 1
    while True:
        url = (
            f"https://api.github.com/repos/{repo}/commits/{sha}/check-runs"
            f"?per_page=100&page={page}"
        )
        request = urllib.request.Request(
            url,
            headers={
                "Authorization": f"Bearer {token}",
                "Accept": "application/vnd.github+json",
                "User-Agent": "vaak-deploy",
                "X-GitHub-Api-Version": "2022-11-28",
            },
        )
        try:
            with urllib.request.urlopen(request, timeout=30) as response:
                payload = json.load(response)
        except urllib.error.HTTPError as exc:
            raise CiError(f"GitHub check-runs API returned {exc.code}") from exc
        batch = payload.get("check_runs") or []
        runs.extend(batch)
        total = int(payload.get("total_count") or len(runs))
        if len(runs) >= total or not batch:
            return runs
        page += 1


if __name__ == "__main__":
    raise SystemExit(main())
