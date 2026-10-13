#!/usr/bin/env python3
"""Tests for version-only release commits and the deploy plan."""

from __future__ import annotations

import subprocess
import sys
import tempfile
import textwrap
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from bump_version import apply_version
from release_commit import ReleaseError, live_is_older, plan_deploy, verify_release

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github" / "workflows" / "deploy-production.yml"
RELEASE_COMMIT = "828be543b3b53bf3ef81a1b05e2bbda9f24e2eb8"
RELEASE_PARENT = "4d380b5ed4e103a7d1ac693a056c26e93f41df1c"


def git(repo: Path, *args: str) -> str:
    result = subprocess.run(
        ["git", "-C", str(repo), *args],
        check=True,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    return result.stdout.strip()


def write_tree(root: Path, version: str, date: str) -> None:
    (root / "api").mkdir(parents=True, exist_ok=True)
    (root / "docs").mkdir(parents=True, exist_ok=True)
    (root / "rust").mkdir(parents=True, exist_ok=True)
    (root / "api" / "ap-version.php").write_text(
        textwrap.dedent(
            f"""\
            <?php
            declare(strict_types=1);
            const VAAK_VERSION = '{version}';
            const VAAK_CHANNEL = 'alpha';
            const VAAK_VERSION_DATE = '{date}';
            """
        ),
        encoding="utf-8",
    )
    (root / "README.md").write_text(
        textwrap.dedent(
            f"""\
            # VAAK

            **Current release:** alpha **{version}** · {date}

            Notes: [{version}](docs/RELEASE-{version}.md).

            ## Status

            Active alpha. **{version}** is the deployed version in `api/ap-version.php`. More text.
            """
        ),
        encoding="utf-8",
    )
    (root / "rust" / "README.md").write_text(
        "Deployed release: "
        f"[**alpha {version} · {date}**](../docs/RELEASE-{version}.md).\n\n"
        "Later paragraph. PHP’s timing stays in this sentence.\n",
        encoding="utf-8",
    )
    (root / "docs" / f"RELEASE-{version}.md").write_text(f"# VAAK alpha {version}\n", encoding="utf-8")


def init_repo(root: Path) -> None:
    git(root, "init", "-b", "main")
    git(root, "config", "user.email", "deploy-test@example.com")
    git(root, "config", "user.name", "deploy-test")
    git(root, "config", "commit.gpgsign", "false")
    git(root, "config", "core.autocrlf", "false")


def commit_all(root: Path, message: str) -> str:
    git(root, "add", "-A")
    git(root, "commit", "-m", message)
    return git(root, "rev-parse", "HEAD")


def release_repo(version: str = "0.8.45", nxt: str = "0.8.46", date: str = "2026-10-13") -> tuple[Path, str, str]:
    tmp = Path(tempfile.mkdtemp())
    init_repo(tmp)
    write_tree(tmp, version, date)
    parent = commit_all(tmp, "base")
    apply_version(tmp, nxt, date, parent)
    release = commit_all(tmp, f"Release VAAK alpha {nxt}")
    return tmp, parent, release


class VerifyReleaseTest(unittest.TestCase):
    def test_accepts_a_pure_bump_including_rollover(self) -> None:
        _tmp, parent, release = release_repo("0.8.99", "0.9.00", "2026-10-14")
        verify_release(Path(_tmp), release, parent)
        plan = plan_deploy(Path(_tmp), release, "increment")
        self.assertEqual(plan["kind"], "version-only")
        self.assertEqual(plan["reuse"], "true")
        self.assertEqual(plan["source_sha"], parent)
        self.assertEqual(plan["sha"], release)
        self.assertEqual(plan["version"], "0.9.00")
        self.assertEqual(plan["next_version"], "0.9.00")

    def test_version_bump_none_also_reuses_the_release_commit(self) -> None:
        tmp, parent, release = release_repo()
        plan = plan_deploy(tmp, release, "none")
        self.assertEqual(plan["kind"], "version-only")
        self.assertEqual(plan["source_sha"], parent)
        self.assertEqual(plan["next_version"], "0.8.46")

    def test_rejects_an_extra_file_and_a_skipped_counter(self) -> None:
        tmp, parent, _release = release_repo()
        (tmp / "api" / "evil.php").write_text("<?php echo 1;\n", encoding="utf-8")
        bad = commit_all(tmp, "not only version")
        with self.assertRaises(ReleaseError):
            verify_release(tmp, bad, parent)

        skipped = Path(tempfile.mkdtemp())
        init_repo(skipped)
        write_tree(skipped, "0.8.45", "2026-10-13")
        base = commit_all(skipped, "base")
        apply_version(skipped, "0.8.48", "2026-10-13", base)
        jumped = commit_all(skipped, "skip")
        with self.assertRaises(ReleaseError):
            verify_release(skipped, jumped, base)

    def test_rejects_a_version_file_that_is_not_the_bump_script(self) -> None:
        tmp = Path(tempfile.mkdtemp())
        init_repo(tmp)
        write_tree(tmp, "0.8.45", "2026-10-13")
        parent = commit_all(tmp, "base")
        apply_version(tmp, "0.8.46", "2026-10-13", parent)
        php = tmp / "api" / "ap-version.php"
        php.write_text(php.read_text(encoding="utf-8") + "system('id');\n", encoding="utf-8")
        tainted = commit_all(tmp, "tainted version file")
        with self.assertRaises(ReleaseError):
            verify_release(tmp, tainted, parent)
        plan = plan_deploy(tmp, tainted, "increment")
        self.assertEqual(plan["kind"], "ci-required")
        self.assertEqual(plan["reuse"], "false")

    def test_followup_that_does_not_change_the_app_reuses_the_version(self) -> None:
        tmp, _parent, release = release_repo()
        (tmp / "docs" / "DEPLOY.md").write_text("workflow notes\n", encoding="utf-8")
        (tmp / ".github" / "workflows").mkdir(parents=True)
        (tmp / ".github" / "workflows" / "deploy-production.yml").write_text("name: Deploy\n", encoding="utf-8")
        head = commit_all(tmp, "fix the deploy workflow")
        plan = plan_deploy(tmp, head, "increment")
        self.assertEqual(plan["kind"], "reuse-tree")
        self.assertEqual(plan["reuse"], "true")
        self.assertEqual(plan["sha"], head)
        self.assertEqual(plan["source_sha"], head)
        self.assertEqual(plan["version"], "0.8.46")
        self.assertEqual(plan["next_version"], "0.8.46")

    def test_app_change_after_the_release_bumps_again(self) -> None:
        tmp, _parent, _release = release_repo()
        (tmp / "api" / "changed.php").write_text("<?php\n", encoding="utf-8")
        head = commit_all(tmp, "change the app")
        plan = plan_deploy(tmp, head, "increment")
        self.assertEqual(plan["kind"], "ci-required")
        self.assertEqual(plan["reuse"], "false")
        self.assertEqual(plan["next_version"], "0.8.47")
        (tmp / "deploy").mkdir()
        (tmp / "deploy" / "ci-build-worker.sh").write_text("#!/bin/bash\n", encoding="utf-8")
        built = commit_all(tmp, "change the build")
        # The previous commit already changed the app, so this is still a bump.
        self.assertEqual(plan_deploy(tmp, built, "increment")["kind"], "ci-required")

    def test_build_script_alone_is_treated_as_a_shipped_change(self) -> None:
        tmp, _parent, _release = release_repo()
        (tmp / "deploy").mkdir()
        (tmp / "deploy" / "ci-build-worker.sh").write_text("#!/bin/bash\n", encoding="utf-8")
        head = commit_all(tmp, "change the worker build")
        plan = plan_deploy(tmp, head, "increment")
        self.assertEqual(plan["kind"], "ci-required")
        self.assertEqual(plan["next_version"], "0.8.47")

    def test_merge_of_tooling_onto_the_release_reuses_it(self) -> None:
        tmp, parent, release = release_repo()
        tree = git(tmp, "rev-parse", f"{release}^{{tree}}")
        other = git(tmp, "commit-tree", tree, "-p", parent, "-m", "other")
        merge = git(tmp, "commit-tree", tree, "-p", release, "-p", other, "-m", "merge tooling")
        plan = plan_deploy(tmp, merge, "increment")
        self.assertEqual(plan["kind"], "reuse-tree")
        self.assertEqual(plan["next_version"], "0.8.46")
        self.assertEqual(plan["source_sha"], merge)

    def test_live_version_must_be_strictly_older(self) -> None:
        live_is_older("0.8.45", "0.8.46")
        live_is_older("0.8.99\n", "0.9.00")
        with self.assertRaises(ReleaseError):
            live_is_older("0.8.46", "0.8.46")
        with self.assertRaises(ReleaseError):
            live_is_older("0.8.47", "0.8.46")

    def test_cli_rejects_a_plan_that_does_not_match_the_guard(self) -> None:
        tmp, _parent, release = release_repo()
        script = Path(__file__).resolve().parent / "release_commit.py"
        proc = subprocess.run(
            [
                sys.executable,
                str(script),
                "--root",
                str(tmp),
                "plan",
                "--sha",
                release,
                "--version-bump",
                "increment",
                "--expect-kind",
                "ci-required",
                "--expect-reuse",
                "false",
                "--expect-source",
                release,
            ],
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )
        self.assertEqual(proc.returncode, 1)
        self.assertIn("release_error", proc.stderr)

    def test_real_0_8_46_commit_is_version_only_when_present(self) -> None:
        probe = subprocess.run(
            ["git", "-C", str(ROOT), "cat-file", "-e", f"{RELEASE_COMMIT}^{{commit}}"],
            check=False,
        )
        if probe.returncode != 0:
            self.skipTest("0.8.46 release commit is not in this checkout")
        verify_release(ROOT, RELEASE_COMMIT, RELEASE_PARENT)
        plan = plan_deploy(ROOT, RELEASE_COMMIT, "increment")
        self.assertEqual(plan["kind"], "version-only")
        self.assertEqual(plan["source_sha"], RELEASE_PARENT)
        self.assertEqual(plan["next_version"], "0.8.46")
        self.assertEqual(plan["reuse"], "true")


class WorkflowContractTest(unittest.TestCase):
    def test_ship_job_does_not_wait_for_ci_on_the_release_commit(self) -> None:
        text = WORKFLOW.read_text(encoding="utf-8")
        self.assertNotIn("--wait", text)
        self.assertNotIn("timeout-seconds", text)
        self.assertIn("release_commit.py", text)
        self.assertIn("require_green_ci.py", text)
        self.assertIn("live-is-older", text)
        self.assertIn("environment: production", text)
        self.assertIn("workflow_dispatch", text)
        self.assertIn("vaak-release-activate.sh", text)
        self.assertIn("version_bump", text)


if __name__ == "__main__":
    unittest.main()
