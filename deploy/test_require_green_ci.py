#!/usr/bin/env python3
"""Decision tests for deploy/require_green_ci.py."""

from __future__ import annotations

import io
import sys
import unittest
from contextlib import redirect_stderr
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parent))

from require_green_ci import evaluate, main, should_wait


def run(name: str, status: str = "completed", conclusion: str = "success") -> dict:
    return {"name": name, "status": status, "conclusion": conclusion}


class EvaluateCiTest(unittest.TestCase):
    def test_requires_another_workflows_success(self) -> None:
        self.assertEqual(evaluate([run("php"), run("vaak-deploy-ship")]), "ok")

    def test_ignores_only_this_deploy_workflow(self) -> None:
        self.assertEqual(evaluate([run("vaak-deploy-guard"), run("vaak-deploy-ship")]), "absent")

    def test_pending_and_failure(self) -> None:
        self.assertEqual(evaluate([run("php", status="in_progress", conclusion="")]), "pending")
        self.assertEqual(evaluate([run("php", conclusion="failure")]), "fail")

    def test_skipped_without_a_success_is_not_enough(self) -> None:
        self.assertEqual(evaluate([run("php", conclusion="skipped")]), "absent")
        self.assertEqual(
            evaluate([run("php"), run("docs", conclusion="skipped")]),
            "ok",
        )

    def test_zero_check_runs_are_not_worth_waiting_for(self) -> None:
        self.assertFalse(should_wait("absent", 0))
        self.assertTrue(should_wait("absent", 2))
        self.assertTrue(should_wait("pending", 1))
        self.assertFalse(should_wait("ok", 1))
        self.assertFalse(should_wait("fail", 1))

    @patch("require_green_ci.fetch_check_runs", return_value=[])
    @patch("require_green_ci.time.sleep")
    def test_main_does_not_poll_a_commit_with_no_checks(self, sleep, _fetch) -> None:
        with redirect_stderr(io.StringIO()):
            rc = main(["--sha", "ab" * 20, "--wait", "--timeout-seconds", "1500"])
        self.assertEqual(rc, 1)
        sleep.assert_not_called()


if __name__ == "__main__":
    unittest.main()
