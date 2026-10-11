#!/usr/bin/env python3
"""Build the staged website diff without changing either input tree.

GNU diff supplies the required EOF markers for files without a final newline.
Manifest hashes are checked before any output is written.
"""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("evidence", type=Path)
    parser.add_argument("output", type=Path)
    args = parser.parse_args()
    manifest = json.loads((args.evidence / "main-website-manifest.json").read_text())
    chunks = []
    for row in manifest:
        relative = Path(row["path"])
        if relative.is_absolute() or ".." in relative.parts:
            raise ValueError("Unsafe manifest path")
        original = args.evidence / "website-originals" / relative
        staged = args.evidence / "website-stage" / relative
        for source, key in ((original, "original_sha256"), (staged, "staged_sha256")):
            if hashlib.sha256(source.read_bytes()).hexdigest() != row[key]:
                raise ValueError(f"Manifest hash mismatch: {source}")
        result = subprocess.run(
            ["diff", "-u", "--label", f"a/{relative}", "--label", f"b/{relative}",
             str(original), str(staged)], capture_output=True, check=False,
        )
        if result.returncode not in (0, 1):
            raise RuntimeError(result.stderr.decode(errors="replace"))
        chunks.append(result.stdout)
    args.output.write_bytes(b"".join(chunks))


if __name__ == "__main__":
    main()
