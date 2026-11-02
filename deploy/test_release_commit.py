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
URL = "https://github.com/cmdr-nova/VAAK/releases/tag/v"
NOTES = "### ✨ Features\n* Add a thing by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/400\n"


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

            Notes: [v{version}]({URL}{version}) · [CHANGELOG](CHANGELOG.md).

            ## Status

            Active alpha. **{version}** is the deployed version in `api/ap-version.php`. More text.
            """
        ),
        encoding="utf-8",
    )
    (root / "rust" / "README.md").write_text(
        "Deployed release: "
        f"[**alpha {version} · {date}**]({URL}{version}).\n\n"
        "Later paragraph. PHP’s timing stays in this sentence.\n",
        encoding="utf-8",
    )
    (root / "CHANGELOG.md").write_text("# Changelog\n\nIntro.\n", encoding="utf-8")


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


def release_repo(version: str = "0.10.0", nxt: str = "0.10.1", date: str = "2026-11-02") -> tuple[Path, str, str]:
    tmp = Path(tempfile.mkdtemp())
    init_repo(tmp)
    write_tree(tmp, version, date)
    parent = commit_all(tmp, "base")
    apply_version(tmp, nxt, date, NOTES)
    release = commit_all(tmp, f"Release VAAK alpha {nxt}")
    return tmp, parent, release


class VerifyReleaseTest(unittest.TestCase):
    def test_accepts_a_pure_bump_from_the_old_counter(self) -> None:
        _tmp, parent, release = release_repo("0.9.83", "0.10.0", "2026-11-02")
        verify_release(Path(_tmp), release, parent)
        plan = plan_deploy(Path(_tmp), release, "minor")
        self.assertEqual(plan["kind"], "version-only")
        self.assertEqual(plan["reuse"], "true")
        self.assertEqual(plan["source_sha"], parent)
        self.assertEqual(plan["sha"], release)
        self.assertEqual(plan["version"], "0.10.0")
        self.assertEqual(plan["next_version"], "0.10.0")

    def test_accepts_patch_minor_and_major(self) -> None:
        for nxt in ("0.10.1", "0.11.0", "1.0.0"):
            tmp, parent, release = release_repo("0.10.0", nxt)
            verify_release(tmp, release, parent)

    def test_rejects_a_changelog_that_rewrites_an_old_section(self) -> None:
        tmp = Path(tempfile.mkdtemp())
        init_repo(tmp)
        write_tree(tmp, "0.10.0", "2026-11-02")
        log = tmp / "CHANGELOG.md"
        log.write_text(log.read_text(encoding="utf-8") + f"## [0.10.0]({URL}0.10.0) - 2026-11-01\n\nold\n", encoding="utf-8")
        parent = commit_all(tmp, "base")
        apply_version(tmp, "0.10.1", "2026-11-02", NOTES)
        log.write_text(log.read_text(encoding="utf-8").replace("\nold\n", "\nrewritten\n"), encoding="utf-8")
        tampered = commit_all(tmp, "release that edits history")
        with self.assertRaises(ReleaseError):
            verify_release(tmp, tampered, parent)

    def test_rejects_a_release_without_its_changelog_section(self) -> None:
        tmp = Path(tempfile.mkdtemp())
        init_repo(tmp)
        write_tree(tmp, "0.10.0", "2026-11-02")
        parent = commit_all(tmp, "base")
        apply_version(tmp, "0.10.1", "2026-11-02", NOTES)
        (tmp / "CHANGELOG.md").write_text("# Changelog\n\nIntro.\n", encoding="utf-8")
        bare = commit_all(tmp, "no section")
        with self.assertRaises(ReleaseError):
            verify_release(tmp, bare, parent)

    def test_version_bump_none_also_reuses_the_release_commit(self) -> None:
        tmp, parent, release = release_repo()
        plan = plan_deploy(tmp, release, "none")
        self.assertEqual(plan["kind"], "version-only")
        self.assertEqual(plan["source_sha"], parent)
        self.assertEqual(plan["next_version"], "0.10.1")

    def test_rejects_an_extra_file_and_a_skipped_counter(self) -> None:
        tmp, parent, _release = release_repo()
        (tmp / "api" / "evil.php").write_text("<?php echo 1;\n", encoding="utf-8")
        bad = commit_all(tmp, "not only version")
        with self.assertRaises(ReleaseError):
            verify_release(tmp, bad, parent)

        skipped = Path(tempfile.mkdtemp())
        init_repo(skipped)
        write_tree(skipped, "0.10.0", "2026-11-02")
        base = commit_all(skipped, "base")
        apply_version(skipped, "0.10.3", "2026-11-02", NOTES)
        jumped = commit_all(skipped, "skip")
        with self.assertRaises(ReleaseError):
            verify_release(skipped, jumped, base)

    def test_rejects_a_version_file_that_is_not_the_bump_script(self) -> None:
        tmp = Path(tempfile.mkdtemp())
        init_repo(tmp)
        write_tree(tmp, "0.10.0", "2026-11-02")
        parent = commit_all(tmp, "base")
        apply_version(tmp, "0.10.1", "2026-11-02", NOTES)
        php = tmp / "api" / "ap-version.php"
        php.write_text(php.read_text(encoding="utf-8") + "system('id');\n", encoding="utf-8")
        tainted = commit_all(tmp, "tainted version file")
        with self.assertRaises(ReleaseError):
            verify_release(tmp, tainted, parent)
        plan = plan_deploy(tmp, tainted, "patch")
        self.assertEqual(plan["kind"], "ci-required")
        self.assertEqual(plan["reuse"], "false")

    def test_followup_that_does_not_change_the_app_reuses_the_version(self) -> None:
        tmp, _parent, release = release_repo()
        (tmp / "docs").mkdir()
        (tmp / "docs" / "DEPLOY.md").write_text("workflow notes\n", encoding="utf-8")
        (tmp / ".github" / "workflows").mkdir(parents=True)
        (tmp / ".github" / "workflows" / "deploy-production.yml").write_text("name: Deploy\n", encoding="utf-8")
        head = commit_all(tmp, "fix the deploy workflow")
        plan = plan_deploy(tmp, head, "minor")
        self.assertEqual(plan["kind"], "reuse-tree")
        self.assertEqual(plan["reuse"], "true")
        self.assertEqual(plan["sha"], head)
        self.assertEqual(plan["source_sha"], head)
        self.assertEqual(plan["version"], "0.10.1")
        self.assertEqual(plan["next_version"], "0.10.1")

    def test_app_change_after_the_release_bumps_again(self) -> None:
        tmp, _parent, _release = release_repo()
        (tmp / "api" / "changed.php").write_text("<?php\n", encoding="utf-8")
        head = commit_all(tmp, "change the app")
        plan = plan_deploy(tmp, head, "patch")
        self.assertEqual(plan["kind"], "ci-required")
        self.assertEqual(plan["reuse"], "false")
        self.assertEqual(plan["next_version"], "0.10.2")
        self.assertEqual(plan_deploy(tmp, head, "minor")["next_version"], "0.11.0")
        self.assertEqual(plan_deploy(tmp, head, "major")["next_version"], "1.0.0")
        self.assertEqual(plan_deploy(tmp, head, "none")["next_version"], "0.10.1")
        with self.assertRaises(ReleaseError):
            plan_deploy(tmp, head, "increment")
        (tmp / "deploy").mkdir()
        (tmp / "deploy" / "ci-build-worker.sh").write_text("#!/bin/bash\n", encoding="utf-8")
        built = commit_all(tmp, "change the build")
        # The previous commit already changed the app, so this is still a bump.
        self.assertEqual(plan_deploy(tmp, built, "patch")["kind"], "ci-required")

    def test_build_script_alone_is_treated_as_a_shipped_change(self) -> None:
        tmp, _parent, _release = release_repo()
        (tmp / "deploy").mkdir()
        (tmp / "deploy" / "ci-build-worker.sh").write_text("#!/bin/bash\n", encoding="utf-8")
        head = commit_all(tmp, "change the worker build")
        plan = plan_deploy(tmp, head, "patch")
        self.assertEqual(plan["kind"], "ci-required")
        self.assertEqual(plan["next_version"], "0.10.2")

    def test_merge_of_tooling_onto_the_release_reuses_it(self) -> None:
        tmp, parent, release = release_repo()
        tree = git(tmp, "rev-parse", f"{release}^{{tree}}")
        other = git(tmp, "commit-tree", tree, "-p", parent, "-m", "other")
        merge = git(tmp, "commit-tree", tree, "-p", release, "-p", other, "-m", "merge tooling")
        plan = plan_deploy(tmp, merge, "patch")
        self.assertEqual(plan["kind"], "reuse-tree")
        self.assertEqual(plan["next_version"], "0.10.1")
        self.assertEqual(plan["source_sha"], merge)

    def test_live_version_must_be_strictly_older(self) -> None:
        live_is_older("0.10.0", "0.10.1")
        live_is_older("0.9.83\n", "0.10.0")
        live_is_older("0.9.99", "0.9.100")
        with self.assertRaises(ReleaseError):
            live_is_older("0.10.1", "0.10.1")
        with self.assertRaises(ReleaseError):
            live_is_older("0.10.2", "0.10.1")

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
                "patch",
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
        self.assertIn("release_notes.py", text)
        self.assertIn("CHANGELOG.md", text)
        self.assertNotIn("docs/RELEASE-", text)

    def test_release_groups_keep_the_titles_auto_reads(self) -> None:
        text = (ROOT / ".github" / "release.yml").read_text(encoding="utf-8")
        self.assertIn("Breaking changes", text)
        self.assertIn("Features", text)


if __name__ == "__main__":
    unittest.main()
