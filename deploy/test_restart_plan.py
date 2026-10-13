#!/usr/bin/env python3
import unittest

from restart_plan import plan


class RestartPlanTest(unittest.TestCase):
    def test_php_only_skips_both(self) -> None:
        self.assertEqual(plan(["api/ap-admin.php", "vaak/index.php", "README.md"]), (False, False))

    def test_jetstream_script_restarts_jetstream_only(self) -> None:
        self.assertEqual(plan(["deploy/ap-bsky-jetstream.py"]), (True, False))

    def test_rust_worker_restarts_shadow_only(self) -> None:
        self.assertEqual(plan(["rust/vaak-worker/src/http.rs"]), (False, True))

    def test_cargo_lock_restarts_shadow(self) -> None:
        self.assertEqual(plan(["rust/Cargo.lock"]), (False, True))

    def test_rust_readme_does_not_restart_shadow(self) -> None:
        self.assertEqual(plan(["rust/README.md"]), (False, False))

    def test_both(self) -> None:
        paths = ["deploy/ap-bsky-jetstream@.service", "rust/vaak-types/src/lib.rs"]
        self.assertEqual(plan(paths), (True, True))


if __name__ == "__main__":
    unittest.main()
