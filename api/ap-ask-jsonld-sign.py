#!/usr/bin/env python3
"""Create the Wafrn RsaSignature2017 envelope used for AskQuestion."""
import argparse
import hashlib
import json
import os
import subprocess
import sys
import urllib.request

# Production keeps the JSON-LD runtime outside the web tree; local development
# can use a normal virtualenv or PYTHONPATH.
for candidate in (os.environ.get("VAAK_JSONLD_LIB", ""), "/opt/vaak-jsonld"):
    if candidate and candidate not in sys.path:
        sys.path.insert(0, candidate)
try:
    import pyld.jsonld as jsonld
except Exception as exc:  # pragma: no cover - exercised on hosts without the optional runtime
    print(f"jsonld runtime unavailable: {exc}", file=sys.stderr)
    raise SystemExit(2)


def loader(url, options=None):
    req = urllib.request.Request(
        url,
        headers={
            "Accept": "application/ld+json, application/json",
            "User-Agent": "VAAK-ActivityPub/1.0",
        },
    )
    with urllib.request.urlopen(req, timeout=8) as response:
        document = json.loads(response.read().decode("utf-8"))
    return {"contextUrl": None, "documentUrl": url, "document": document}


def normalize(value):
    return jsonld.normalize(
        value,
        {"algorithm": "URDNA2015", "format": "application/n-quads", "documentLoader": loader},
    )


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--key", required=True)
    parser.add_argument("--creator", required=True)
    parser.add_argument("--context", required=True)
    args = parser.parse_args()
    data = json.load(sys.stdin)
    nonce = os.urandom(16).hex()
    options = {
        "type": "RsaSignature2017",
        "creator": args.creator,
        "nonce": nonce,
        "created": __import__("datetime").datetime.now(__import__("datetime").timezone.utc).isoformat(timespec="milliseconds").replace("+00:00", "Z"),
        "@context": args.context,
    }
    option_for_signing = dict(options)
    option_for_signing.pop("type", None)
    option_nquads = normalize(option_for_signing)
    data_for_signing = dict(data)
    data_for_signing.pop("signature", None)
    data_nquads = normalize(data_for_signing)
    verify_data = hashlib.sha256(option_nquads.encode()).hexdigest() + hashlib.sha256(data_nquads.encode()).hexdigest()
    signed = subprocess.run(
        ["openssl", "dgst", "-sha256", "-sign", args.key],
        input=verify_data.encode(), stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=False,
    )
    if signed.returncode != 0:
        print(signed.stderr.decode("utf-8", "replace"), file=sys.stderr)
        return 3
    data["signature"] = {**{k: v for k, v in options.items() if k != "@context"}, "signatureValue": __import__("base64").b64encode(signed.stdout).decode("ascii")}
    print(json.dumps(data, ensure_ascii=False, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
