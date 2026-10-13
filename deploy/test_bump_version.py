#!/usr/bin/env python3
"""Counter rollover tests for deploy/bump_version.py."""

from __future__ import annotations

import sys
import tempfile
import textwrap
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from bump_version import VersionError, apply_version, check, next_version, read_version, version_less


class NextVersionTest(unittest.TestCase):
    def test_increments_last_component_as_two_digits(self) -> None:
        self.assertEqual(next_version("0.8.45"), "0.8.46")
        self.assertEqual(next_version("0.8.09"), "0.8.10")
        self.assertEqual(next_version("0.9.00"), "0.9.01")

    def test_rolls_99_into_the_middle_component(self) -> None:
        self.assertEqual(next_version("0.8.99"), "0.9.00")
        self.assertEqual(next_version("0.9.99"), "0.10.00")

    def test_rolls_middle_99_into_the_first_component(self) -> None:
        self.assertEqual(next_version("0.99.99"), "1.0.00")
        self.assertEqual(next_version("1.99.99"), "2.0.00")

    def test_rejects_values_outside_the_counter(self) -> None:
        for bad in ("0.8.5", "0.8.100", "0.08.01", "0.8", "v0.8.45", "0.8.45 ", "10.8.45a"):
            with self.assertRaises(VersionError):
                next_version(bad)

    def test_orders_the_counter_across_rollover(self) -> None:
        self.assertTrue(version_less("0.8.45", "0.8.46"))
        self.assertTrue(version_less("0.8.99", "0.9.00"))
        self.assertTrue(version_less("0.9.99", "0.10.00"))
        self.assertFalse(version_less("0.8.46", "0.8.46"))
        self.assertFalse(version_less("0.8.46", "0.8.45"))


class ApplyVersionTest(unittest.TestCase):
    def test_increment_updates_live_files_and_leaves_old_notes(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            _write_tree(root, "0.8.99", "2026-10-13")
            (root / "docs" / "RELEASE-0.8.99.md").write_text("# old\n", encoding="utf-8")
            written = apply_version(root, next_version("0.8.99"), "2026-10-14", "abc123")
            self.assertEqual(written, "0.9.00")
            version, channel, date = read_version(root)
            self.assertEqual((version, channel, date), ("0.9.00", "alpha", "2026-10-14"))
            check(root)
            readme = (root / "README.md").read_text(encoding="utf-8")
            self.assertIn("**Current release:** alpha **0.9.00** · 2026-10-14", readme)
            self.assertIn("Notes: [0.9.00](docs/RELEASE-0.9.00.md).", readme)
            self.assertIn("**0.9.00** is the deployed version", readme)
            rust = (root / "rust" / "README.md").read_text(encoding="utf-8")
            self.assertIn("../docs/RELEASE-0.9.00.md", rust)
            self.assertEqual((root / "docs" / "RELEASE-0.8.99.md").read_text(encoding="utf-8"), "# old\n")
            note = (root / "docs" / "RELEASE-0.9.00.md").read_text(encoding="utf-8")
            self.assertIn("abc123", note)
            self.assertIn("0.9.00", note)

    def test_refuses_to_overwrite_an_existing_release_note(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            _write_tree(root, "0.8.45", "2026-10-13")
            (root / "docs" / "RELEASE-0.8.46.md").write_text("keep\n", encoding="utf-8")
            with self.assertRaises(VersionError):
                apply_version(root, "0.8.46", "2026-10-14", "abc")
            self.assertEqual((root / "docs" / "RELEASE-0.8.46.md").read_text(encoding="utf-8"), "keep\n")


def _write_tree(root: Path, version: str, date: str) -> None:
    (root / "api").mkdir()
    (root / "docs").mkdir()
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

            Notes: [{version}](docs/RELEASE-{version}.md).

            ## Status

            Active alpha. **{version}** is the deployed version in `api/ap-version.php`. More text.
            """
        ),
        encoding="utf-8",
    )
    (root / "rust" / "README.md").write_text(
        f"Deployed release: [**alpha {version} · {date}**](../docs/RELEASE-{version}.md).\n\nLater paragraph without a version.\n",
        encoding="utf-8",
    )
    (root / "docs" / f"RELEASE-{version}.md").write_text(f"# VAAK alpha {version}\n", encoding="utf-8")


if __name__ == "__main__":
    unittest.main()
