#!/usr/bin/env python3
"""Decision tests for deploy/require_green_ci.py."""

from __future__ import annotations

import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from require_green_ci import evaluate


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


if __name__ == "__main__":
    unittest.main()
