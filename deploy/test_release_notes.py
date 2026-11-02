#!/usr/bin/env python3
"""Tests for deploy/release_notes.py (auto bump level)."""

from __future__ import annotations

import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from release_notes import auto_level, main


class AutoLevelTest(unittest.TestCase):
    def test_features_or_breaking_is_minor(self) -> None:
        self.assertEqual(auto_level("## What's Changed\n### ✨ Features\n* a\n"), "minor")
        self.assertEqual(auto_level("### 💥 Breaking changes\n* a\n"), "minor")

    def test_everything_else_is_patch(self) -> None:
        self.assertEqual(auto_level("### 🐛 Fixes\n* a\n### ⚡ Performance\n* b\n"), "patch")
        self.assertEqual(auto_level("### Other changes\n* Features page tweak\n"), "patch")
        self.assertEqual(auto_level("**Full Changelog**: x\n"), "patch")
        self.assertEqual(auto_level(""), "patch")

    def test_a_pr_title_mentioning_features_is_not_a_heading(self) -> None:
        self.assertEqual(auto_level("### 🐛 Fixes\n* Fix Features tab by @x\n"), "patch")

    def test_generate_refuses_a_bad_target(self) -> None:
        self.assertEqual(main(["generate", "--tag", "v1.0.0", "--target", "main", "--output", "/dev/null"]), 1)


if __name__ == "__main__":
    unittest.main()
