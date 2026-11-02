#!/usr/bin/env python3
"""SemVer bump and CHANGELOG tests for deploy/bump_version.py."""

from __future__ import annotations

import sys
import tempfile
import textwrap
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from bump_version import (
    VersionError,
    apply_version,
    bump,
    bump_level,
    changelog_notes,
    check,
    main,
    next_version,
    normalize_notes,
    read_version,
    version_less,
)

URL = "https://github.com/cmdr-nova/VAAK/releases/tag/v"

GENERATED = textwrap.dedent(
    """\
    <!-- Release notes generated using configuration in .github/release.yml at main -->

    ## What's Changed
    ### ✨ Features
    * Add a thing by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/400
    ### 🐛 Fixes
    * Fix a thing by @cmdr-nova in https://github.com/cmdr-nova/VAAK/pull/401

    ## New Contributors
    * @someone made their first contribution in https://github.com/cmdr-nova/VAAK/pull/402

    **Full Changelog**: https://github.com/cmdr-nova/VAAK/compare/v0.9.83...v0.10.0
    """
)


class BumpTest(unittest.TestCase):
    def test_semver_levels(self) -> None:
        self.assertEqual(bump("0.10.3", "patch"), "0.10.4")
        self.assertEqual(bump("0.10.3", "minor"), "0.11.0")
        self.assertEqual(bump("0.10.3", "major"), "1.0.0")
        self.assertEqual(bump("0.9.99", "patch"), "0.9.100")
        self.assertEqual(next_version("1.2.3"), "1.2.4")

    def test_old_two_digit_counter_still_parses(self) -> None:
        self.assertEqual(bump("0.9.83", "patch"), "0.9.84")
        self.assertEqual(bump("0.9.83", "minor"), "0.10.0")
        self.assertEqual(bump("0.9.05", "patch"), "0.9.6")
        self.assertTrue(version_less("0.9.05", "0.9.6"))
        self.assertTrue(version_less("0.9.83", "0.10.0"))
        self.assertTrue(version_less("0.9.99", "0.9.100"))
        self.assertFalse(version_less("0.10.0", "0.10.0"))
        self.assertFalse(version_less("0.10.1", "0.10.0"))

    def test_rejects_bad_values_and_levels(self) -> None:
        for bad in ("0.8", "v0.8.45", "0.8.45 ", "10.8.45a", "0.08.1", "01.2.3"):
            with self.assertRaises(VersionError):
                bump(bad, "patch")
        with self.assertRaises(VersionError):
            bump("0.10.0", "increment")

    def test_bump_level(self) -> None:
        self.assertEqual(bump_level("0.9.83", "0.9.84"), "patch")
        self.assertEqual(bump_level("0.9.83", "0.10.0"), "minor")
        self.assertEqual(bump_level("0.9.83", "1.0.0"), "major")
        self.assertIsNone(bump_level("0.9.83", "0.9.85"))
        self.assertIsNone(bump_level("0.9.83", "0.10.1"))


class NotesTest(unittest.TestCase):
    def test_normalize_drops_comment_and_whats_changed(self) -> None:
        body = normalize_notes(GENERATED)
        self.assertNotIn("<!--", body)
        self.assertNotIn("What's Changed", body)
        self.assertIn("### ✨ Features", body)
        self.assertIn("### New Contributors", body)
        self.assertFalse(any(line.startswith("## ") for line in body.splitlines()))
        self.assertEqual(normalize_notes(body), body)

    def test_empty_notes_get_a_placeholder(self) -> None:
        self.assertIn("No pull requests", normalize_notes(""))


class ApplyVersionTest(unittest.TestCase):
    def test_bump_updates_live_files_and_prepends_changelog(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            _write_tree(root, "0.9.83", "2026-11-01")
            written = apply_version(root, bump("0.9.83", "minor"), "2026-11-02", GENERATED)
            self.assertEqual(written, "0.10.0")
            self.assertEqual(read_version(root), ("0.10.0", "alpha", "2026-11-02"))
            check(root)
            readme = (root / "README.md").read_text(encoding="utf-8")
            self.assertIn("**Current release:** alpha **0.10.0** · 2026-11-02", readme)
            self.assertIn(f"Notes: [v0.10.0]({URL}0.10.0) · [CHANGELOG](CHANGELOG.md).", readme)
            self.assertIn("**0.10.0** is the deployed version", readme)
            rust = (root / "rust" / "README.md").read_text(encoding="utf-8")
            self.assertIn(f"({URL}0.10.0).", rust)
            log = (root / "CHANGELOG.md").read_text(encoding="utf-8")
            self.assertTrue(log.startswith("# Changelog\n\nIntro.\n\n## [0.10.0]("))
            self.assertIn("Add a thing", changelog_notes(log, "0.10.0") or "")

            apply_version(root, "0.10.1", "2026-11-03", "### 🐛 Fixes\n* Fix two\n")
            log = (root / "CHANGELOG.md").read_text(encoding="utf-8")
            self.assertLess(log.index("## [0.10.1]"), log.index("## [0.10.0]"))
            self.assertEqual(changelog_notes(log, "0.10.1"), "### 🐛 Fixes\n* Fix two")
            self.assertIn("Add a thing", changelog_notes(log, "0.10.0") or "")
            check(root)

    def test_refuses_to_rewrite_an_existing_section(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            _write_tree(root, "0.10.0", "2026-11-01")
            log = root / "CHANGELOG.md"
            log.write_text(log.read_text(encoding="utf-8") + f"## [0.10.1]({URL}0.10.1) - 2026-11-01\n\nkeep\n", encoding="utf-8")
            before = log.read_text(encoding="utf-8")
            with self.assertRaises(VersionError):
                apply_version(root, "0.10.1", "2026-11-02", "new")
            self.assertEqual(log.read_text(encoding="utf-8"), before)
            self.assertEqual(read_version(root)[0], "0.10.0")

    def test_writes_never_use_leading_zeros(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            _write_tree(root, "0.9.83", "2026-11-01")
            with self.assertRaises(VersionError):
                apply_version(root, "0.9.84".replace("84", "084"), "2026-11-02", "")

    def test_cli_print_notes(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            _write_tree(root, "0.9.83", "2026-11-01")
            notes = root / "notes.md"
            notes.write_text(GENERATED, encoding="utf-8")
            self.assertEqual(main(["--root", str(root), "--bump", "patch", "--date", "2026-11-02", "--notes-file", str(notes)]), 0)
            self.assertEqual(read_version(root)[0], "0.9.84")
            self.assertEqual(main(["--root", str(root), "--print-notes", "0.9.84"]), 0)
            self.assertEqual(main(["--root", str(root), "--print-notes", "0.9.83"]), 1)


def _write_tree(root: Path, version: str, date: str) -> None:
    (root / "api").mkdir()
    (root / "rust").mkdir()
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
        f"Deployed release: [**alpha {version} · {date}**]({URL}{version}).\n\nLater paragraph without a version.\n",
        encoding="utf-8",
    )
    (root / "CHANGELOG.md").write_text("# Changelog\n\nIntro.\n", encoding="utf-8")


if __name__ == "__main__":
    unittest.main()
