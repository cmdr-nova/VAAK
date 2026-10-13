#!/usr/bin/env python3
"""Replay fixture inputs through PHP and Rust and report output divergences.

Rust is the source of truth. Differences are written to a job summary and a
report directory. The process exits 0 when every Rust producer succeeds.
It exits 1 when the harness breaks or a Rust producer crashes or returns an
error. PHP producer failures are recorded as divergences and do not fail the run.
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import os
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PHP = ROOT / "api" / "bin" / "php-policy-test"
CARGO = "cargo"
DROP_KEYS_DEFAULT = ["rendered_at", "csrf", "function_sha256", "generated_at", "fetched_at", "nonce"]


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--only", action="append", default=[], help="Catalog case id. Repeatable.")
    parser.add_argument("--catalog", default=str(ROOT / "parity" / "catalog.json"))
    args = parser.parse_args()

    report_dir = Path(os.environ.get("PARITY_REPORT_DIR", ROOT / "parity" / "out"))
    report_dir.mkdir(parents=True, exist_ok=True)
    work = report_dir / "work"
    work.mkdir(parents=True, exist_ok=True)

    try:
        catalog = json.loads(Path(args.catalog).read_text())
    except (OSError, json.JSONDecodeError) as exc:
        write_failure(report_dir, f"Cannot read catalog: {exc}")
        return 1

    cases = catalog.get("cases") or []
    if args.only:
        wanted = set(args.only)
        cases = [case for case in cases if case.get("id") in wanted]
        missing = wanted - {case.get("id") for case in cases}
        if missing:
            write_failure(report_dir, "Unknown catalog id(s): " + ", ".join(sorted(missing)))
            return 1
    if not cases:
        write_failure(report_dir, "Catalog has no cases.")
        return 1

    binary = build_rust(report_dir)
    if binary is None:
        return 1

    drop_keys = list(catalog.get("drop_keys") or DROP_KEYS_DEFAULT)
    results = []
    rust_failed = False
    for case in cases:
        results.append(run_case(case, binary, work, drop_keys))
        rust_failed = rust_failed or bool(results[-1].get("rust_error"))

    payload = {
        "policy": "rust-wins",
        "fail_on_divergence": False,
        "generated_at": dt.datetime.now(dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "rust_failed": rust_failed,
        "cases": results,
    }
    (report_dir / "report.json").write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n")
    markdown = render_markdown(payload)
    (report_dir / "report.md").write_text(markdown)
    summary_path = os.environ.get("GITHUB_STEP_SUMMARY")
    if summary_path:
        with open(summary_path, "a", encoding="utf-8") as handle:
            handle.write(markdown)
            if not markdown.endswith("\n"):
                handle.write("\n")
    print(markdown)
    return 1 if rust_failed else 0


def build_rust(report_dir: Path) -> Path | None:
    binary = ROOT / "rust" / "target" / "debug" / "vaak-worker"
    proc = subprocess.run(
        [CARGO, "build", "--manifest-path", str(ROOT / "rust" / "Cargo.toml"), "-p", "vaak-worker", "--bin", "vaak-worker", "--locked"],
        cwd=ROOT,
        text=True,
        capture_output=True,
    )
    if proc.returncode != 0 or not binary.is_file():
        detail = (proc.stderr or proc.stdout or "cargo build produced no binary").strip()
        write_failure(report_dir, "Rust producer binary failed to build.\n\n```\n" + detail[-4000:] + "\n```")
        return None
    return binary


def run_case(case: dict, binary: Path, work: Path, drop_keys: list[str]) -> dict:
    case_id = case["id"]
    area = case.get("area") or case_id
    summary = case.get("summary") or ""
    result = {
        "id": case_id,
        "area": area,
        "summary": summary,
        "compared": 0,
        "divergence_count": 0,
        "divergences": [],
        "php_error": None,
        "rust_error": None,
    }
    php_spec = case.get("php") or {}
    script = ROOT / php_spec.get("script", "")
    if not script.is_file():
        result["rust_error"] = f"Harness is missing PHP emitter {script}"
        return result
    php = subprocess.run(
        [str(PHP), str(script), *[str(ROOT / arg) if not os.path.isabs(arg) else arg for arg in php_spec.get("args") or []]],
        cwd=ROOT,
        text=True,
        capture_output=True,
        env={**os.environ, "TZ": "UTC"},
    )
    if php.returncode != 0:
        result["php_error"] = tail(php.stderr or php.stdout or f"exit {php.returncode}")
        result["divergence_count"] = 1
        result["divergences"] = [{"id": case_id, "path": "", "kind": "php_error", "php": result["php_error"], "rust": None}]
        return result
    try:
        envelope = json.loads(php.stdout)
        php_cases = envelope["cases"]
    except (json.JSONDecodeError, KeyError, TypeError) as exc:
        result["php_error"] = f"PHP emitter did not return a cases envelope: {exc}"
        result["divergence_count"] = 1
        result["divergences"] = [{"id": case_id, "path": "", "kind": "php_error", "php": result["php_error"], "rust": None}]
        return result

    rust_input = {"cases": [{"id": item.get("id"), "input": item.get("input")} for item in php_cases]}
    input_path = work / f"{case_id}.json"
    input_path.write_text(json.dumps(rust_input))
    producer = case.get("rust_producer") or case_id
    rust = subprocess.run(
        [str(binary), "parity-replay", "--producer", producer, "--input", str(input_path)],
        cwd=ROOT,
        text=True,
        capture_output=True,
        env={**os.environ, "TZ": "UTC"},
    )
    if rust.returncode != 0:
        result["rust_error"] = tail(rust.stderr or rust.stdout or f"exit {rust.returncode}")
        return result
    try:
        rust_cases = json.loads(rust.stdout)["cases"]
    except (json.JSONDecodeError, KeyError, TypeError) as exc:
        result["rust_error"] = f"Rust producer returned invalid JSON: {exc}; stdout={tail(rust.stdout)}"
        return result

    php_by_id = {item.get("id"): item.get("output") for item in php_cases}
    rust_by_id = {item.get("id"): item.get("output") for item in rust_cases}
    extra_drop = list(case.get("drop_keys") or [])
    unordered = set(case.get("unordered_paths") or [])
    divergences = []
    ids = list(dict.fromkeys([*php_by_id.keys(), *rust_by_id.keys()]))
    result["compared"] = len(ids)
    for item_id in ids:
        if item_id not in rust_by_id:
            divergences.append({"id": item_id, "path": "", "kind": "only_php", "php": php_by_id.get(item_id), "rust": None})
            continue
        if item_id not in php_by_id:
            divergences.append({"id": item_id, "path": "", "kind": "only_rust", "php": None, "rust": rust_by_id.get(item_id)})
            continue
        left = normalize(php_by_id[item_id], drop_keys + extra_drop)
        right = normalize(rust_by_id[item_id], drop_keys + extra_drop)
        diff(item_id, "", left, right, unordered, divergences)
    result["divergences"] = divergences
    result["divergence_count"] = len(divergences)
    return result


def normalize(value, drop_keys: list[str]):
    if isinstance(value, str):
        return canonical_timestamp(value)
    if isinstance(value, list):
        return [normalize(item, drop_keys) for item in value]
    if isinstance(value, dict):
        return {key: normalize(item, drop_keys) for key, item in value.items() if key not in drop_keys}
    return value


def canonical_timestamp(value: str) -> str:
    text = value.strip()
    if len(text) < 19 or not text[0:4].isdigit() or text[4] != "-" or text[7] != "-":
        return value
    body = text.replace("Z", "+00:00")
    if " " in body and "T" not in body:
        body = body.replace(" ", "T", 1)
    if len(body) == 19:
        body += "+00:00"
    try:
        parsed = dt.datetime.fromisoformat(body)
    except ValueError:
        return value
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=dt.timezone.utc)
    parsed = parsed.astimezone(dt.timezone.utc).replace(microsecond=0)
    return parsed.strftime("%Y-%m-%dT%H:%M:%SZ")


def diff(item_id, path: str, php, rust, unordered: set[str], out: list) -> None:
    if type(php) is not type(rust) and not (is_number(php) and is_number(rust)):
        out.append({"id": item_id, "path": path or "$", "kind": "type", "php": php, "rust": rust})
        return
    if isinstance(php, dict):
        keys = sorted(set(php) | set(rust))
        for key in keys:
            child = f"{path}.{key}" if path else key
            if key not in rust:
                out.append({"id": item_id, "path": child, "kind": "only_php", "php": php[key], "rust": None})
            elif key not in php:
                out.append({"id": item_id, "path": child, "kind": "only_rust", "php": None, "rust": rust[key]})
            else:
                diff(item_id, child, php[key], rust[key], unordered, out)
        return
    if isinstance(php, list):
        left, right = php, rust
        if path in unordered or (path and any(path == item or path.endswith("." + item) for item in unordered)):
            left = sorted(left, key=lambda item: json.dumps(item, sort_keys=True, default=str))
            right = sorted(right, key=lambda item: json.dumps(item, sort_keys=True, default=str))
        shared = min(len(left), len(right))
        for index in range(shared):
            diff(item_id, f"{path}[{index}]", left[index], right[index], unordered, out)
        for index in range(shared, len(left)):
            out.append({"id": item_id, "path": f"{path}[{index}]", "kind": "only_php", "php": left[index], "rust": None})
        for index in range(shared, len(right)):
            out.append({"id": item_id, "path": f"{path}[{index}]", "kind": "only_rust", "php": None, "rust": right[index]})
        return
    if is_number(php) and is_number(rust):
        if float(php) != float(rust):
            out.append({"id": item_id, "path": path or "$", "kind": "value", "php": php, "rust": rust})
        return
    if php != rust:
        out.append({"id": item_id, "path": path or "$", "kind": "value", "php": php, "rust": rust})


def is_number(value) -> bool:
    return isinstance(value, (int, float)) and not isinstance(value, bool)


def render_markdown(payload: dict) -> str:
    lines = [
        "## PHP vs Rust parity",
        "",
        "Rust is the source of truth. Output differences are reported and do not fail this job. The job fails only when the harness breaks or a Rust producer errors.",
        "",
        "| Area | Case | Compared | Divergent paths | Rust |",
        "| --- | --- | ---: | ---: | --- |",
    ]
    total_compared = 0
    total_div = 0
    for case in payload["cases"]:
        total_compared += case["compared"]
        total_div += case["divergence_count"]
        rust_state = "error" if case.get("rust_error") else "ok"
        lines.append(
            f"| {case['area']} | `{case['id']}` | {case['compared']} | {case['divergence_count']} | {rust_state} |"
        )
    lines.append("")
    lines.append(f"Compared **{total_compared}** fixture rows. **{total_div}** divergent paths.")
    if payload.get("rust_failed"):
        lines.append("")
        lines.append("Rust producer errors:")
        for case in payload["cases"]:
            if case.get("rust_error"):
                lines.append(f"- `{case['id']}`: {case['rust_error']}")
    lines.append("")
    lines.append("### Divergences")
    lines.append("")
    any_div = False
    for case in payload["cases"]:
        if not case["divergences"] and not case.get("php_error"):
            continue
        any_div = True
        lines.append(f"#### {case['area']} / `{case['id']}`")
        lines.append("")
        if case.get("php_error") and not case["divergences"]:
            lines.append(f"PHP emitter failed: {case['php_error']}")
            lines.append("")
            continue
        grouped: dict[str, list] = {}
        for item in case["divergences"]:
            grouped.setdefault(item["id"], []).append(item)
        for item_id, items in grouped.items():
            lines.append(f"- `{item_id}`")
            only_php = [item["path"] for item in items if item["kind"] == "only_php" and item["path"]]
            only_rust = [item["path"] for item in items if item["kind"] == "only_rust" and item["path"]]
            if only_php:
                lines.append("  - only on PHP: " + ", ".join(f"`{path}`" for path in only_php))
            if only_rust:
                lines.append("  - only on Rust: " + ", ".join(f"`{path}`" for path in only_rust))
            for item in items:
                if item["kind"] in {"value", "type", "php_error"} or (item["kind"] in {"only_php", "only_rust"} and not item["path"]):
                    lines.append(
                        f"  - `{item['path'] or item['kind']}` {item['kind']}: PHP `{preview(item.get('php'))}` / Rust `{preview(item.get('rust'))}`"
                    )
        lines.append("")
    if not any_div:
        lines.append("No divergences.")
        lines.append("")
    lines.append("Full values are in the `php-rust-parity-report` artifact (`report.json`).")
    lines.append("")
    return "\n".join(lines)


def preview(value) -> str:
    if value is None:
        return "null"
    text = value if isinstance(value, str) else json.dumps(value, ensure_ascii=False, sort_keys=True)
    text = text.replace("\n", "\\n")
    if len(text) > 180:
        return text[:177] + "..."
    return text


def tail(text: str, limit: int = 1200) -> str:
    text = (text or "").strip()
    if len(text) <= limit:
        return text
    return text[-limit:]


def write_failure(report_dir: Path, message: str) -> None:
    payload = {
        "policy": "rust-wins",
        "fail_on_divergence": False,
        "rust_failed": True,
        "harness_error": message,
        "cases": [],
    }
    report_dir.mkdir(parents=True, exist_ok=True)
    (report_dir / "report.json").write_text(json.dumps(payload, indent=2) + "\n")
    markdown = "## PHP vs Rust parity\n\nHarness or Rust failure:\n\n" + message + "\n"
    (report_dir / "report.md").write_text(markdown)
    summary_path = os.environ.get("GITHUB_STEP_SUMMARY")
    if summary_path:
        with open(summary_path, "a", encoding="utf-8") as handle:
            handle.write(markdown)
    print(markdown, file=sys.stderr)


if __name__ == "__main__":
    sys.exit(main())
