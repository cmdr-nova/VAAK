#!/usr/bin/env python3
"""
Nostr crossposting for a VAAK account that posts through the API (Shana).

The Nostr secret key lives only on this machine. VAAK builds the note from
the stored post (POST /api/v1/vaak/nostr/prepare), this script signs it, and
VAAK checks the signature and relays it (POST /api/v1/vaak/nostr/publish).
VAAK never sees the key. Blog posts, replies and non-public posts stay on
VAAK; the server decides that, not this script.

Commands:
    new-key                 make the keypair once (prints the npub to link)
    npub                    print the npub of the saved key
    profile                 copy the VAAK name, bio, avatar and header to
                            Nostr (kind 0); flags override single fields
    crosspost STATUS_ID     send an existing VAAK post to Nostr
    post "text"             post on VAAK, then crosspost it

Settings come from the environment (or flags):
    VAAK_URL        e.g. https://mkultra.monster
    VAAK_TOKEN      the account's API access token (write scope)
    VAAK_NOSTR_KEY  key file, default ~/.config/vaak-nostr/nsec

From Python, after Shana posts:
    import vaak_nostr
    vaak_nostr.crosspost(status["id"])

Signing is pure Python (BIP-340), so the only package needed is
`websockets`, and only for the `profile` command.
"""
from __future__ import annotations

import argparse
import hashlib
import html
import json
import os
import re
import secrets
import stat
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

DEFAULT_KEY_FILE = Path.home() / ".config" / "vaak-nostr" / "nsec"
DEFAULT_RELAYS = ["wss://relay.damus.io", "wss://nos.lol", "wss://relay.primal.net", "wss://purplepag.es"]

# --- BIP-340 Schnorr signatures over secp256k1 (reference algorithm) ---

P = 0xFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F
N = 0xFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141
G = (
    0x79BE667EF9DCBBAC55A06295CE870B07029BFCDB2DCE28D959F2815B16F81798,
    0x483ADA7726A3C4655DA4FBFC0E1108A8FD17B448A68554199C47D08FFB10D4B8,
)


def _add(a, b):
    if a is None:
        return b
    if b is None:
        return a
    if a[0] == b[0] and a[1] != b[1]:
        return None
    if a == b:
        lam = 3 * a[0] * a[0] * pow(2 * a[1], P - 2, P) % P
    else:
        lam = (b[1] - a[1]) * pow(b[0] - a[0], P - 2, P) % P
    x = (lam * lam - a[0] - b[0]) % P
    return (x, (lam * (a[0] - x) - a[1]) % P)


def _mul(point, n):
    result = None
    for i in range(256):
        if (n >> i) & 1:
            result = _add(result, point)
        point = _add(point, point)
    return result


def _tagged(tag: str, data: bytes) -> bytes:
    t = hashlib.sha256(tag.encode()).digest()
    return hashlib.sha256(t + t + data).digest()


def _lift_x(x: int):
    if x >= P:
        return None
    y_sq = (pow(x, 3, P) + 7) % P
    y = pow(y_sq, (P + 1) // 4, P)
    if pow(y, 2, P) != y_sq:
        return None
    return (x, y if y % 2 == 0 else P - y)


def pubkey_of(secret: bytes) -> bytes:
    d = int.from_bytes(secret, "big")
    if not 1 <= d < N:
        raise ValueError("secret key out of range")
    return _mul(G, d)[0].to_bytes(32, "big")


def schnorr_sign(msg: bytes, secret: bytes, aux: bytes | None = None) -> bytes:
    aux = secrets.token_bytes(32) if aux is None else aux
    d0 = int.from_bytes(secret, "big")
    if not 1 <= d0 < N:
        raise ValueError("secret key out of range")
    pt = _mul(G, d0)
    d = d0 if pt[1] % 2 == 0 else N - d0
    t = (d ^ int.from_bytes(_tagged("BIP0340/aux", aux), "big")).to_bytes(32, "big")
    k0 = int.from_bytes(_tagged("BIP0340/nonce", t + pt[0].to_bytes(32, "big") + msg), "big") % N
    if k0 == 0:
        raise ValueError("bad nonce")
    r = _mul(G, k0)
    k = k0 if r[1] % 2 == 0 else N - k0
    rx = r[0].to_bytes(32, "big")
    e = int.from_bytes(_tagged("BIP0340/challenge", rx + pt[0].to_bytes(32, "big") + msg), "big") % N
    sig = rx + ((k + e * d) % N).to_bytes(32, "big")
    if not schnorr_verify(msg, pt[0].to_bytes(32, "big"), sig):
        raise RuntimeError("signature failed its own check")
    return sig


def schnorr_verify(msg: bytes, pubkey: bytes, sig: bytes) -> bool:
    pt = _lift_x(int.from_bytes(pubkey, "big"))
    r = int.from_bytes(sig[:32], "big")
    s = int.from_bytes(sig[32:], "big")
    if pt is None or r >= P or s >= N:
        return False
    e = int.from_bytes(_tagged("BIP0340/challenge", sig[:32] + pubkey + msg), "big") % N
    rr = _add(_mul(G, s), _mul(pt, N - e))
    return rr is not None and rr[1] % 2 == 0 and rr[0] == r


# --- NIP-19 bech32 (npub / nsec) ---

_CHARSET = "qpzry9x8gf2tvdw0s3jn54khce6mua7l"


def _polymod(values):
    gen = [0x3B6A57B2, 0x26508E6D, 0x1EA119FA, 0x3D4233DD, 0x2A1462B3]
    chk = 1
    for v in values:
        top = chk >> 25
        chk = (chk & 0x1FFFFFF) << 5 ^ v
        for i in range(5):
            chk ^= gen[i] if (top >> i) & 1 else 0
    return chk


def _hrp_expand(hrp):
    return [ord(c) >> 5 for c in hrp] + [0] + [ord(c) & 31 for c in hrp]


def _bits(data, frm, to, pad):
    acc = bits = 0
    out = []
    for b in data:
        acc = (acc << frm) | b
        bits += frm
        while bits >= to:
            bits -= to
            out.append((acc >> bits) & ((1 << to) - 1))
    if pad and bits:
        out.append((acc << (to - bits)) & ((1 << to) - 1))
    elif not pad and (bits >= frm or (acc << (to - bits)) & ((1 << to) - 1)):
        raise ValueError("bad padding")
    return out


def bech32_encode(hrp: str, data: bytes) -> str:
    five = _bits(data, 8, 5, True)
    poly = _polymod(_hrp_expand(hrp) + five + [0] * 6) ^ 1
    return hrp + "1" + "".join(_CHARSET[d] for d in five + [(poly >> 5 * (5 - i)) & 31 for i in range(6)])


def bech32_decode(text: str, hrp: str) -> bytes:
    text = text.strip().lower()
    if not text.startswith(hrp + "1"):
        raise ValueError(f"expected an {hrp}")
    data = [_CHARSET.index(c) for c in text[len(hrp) + 1:]]
    if _polymod(_hrp_expand(hrp) + data) != 1:
        raise ValueError("bad checksum")
    return bytes(_bits(data[:-6], 5, 8, False))


# --- Nostr events ---

def event_id(ev: dict) -> str:
    payload = [0, ev["pubkey"], ev["created_at"], ev["kind"], ev["tags"], ev["content"]]
    raw = json.dumps(payload, separators=(",", ":"), ensure_ascii=False)
    return hashlib.sha256(raw.encode("utf-8")).hexdigest()


def sign_event(template: dict, secret: bytes) -> dict:
    ev = {
        "pubkey": pubkey_of(secret).hex(),
        "created_at": int(time.time()),
        "kind": int(template["kind"]),
        "tags": template.get("tags") or [],
        "content": template.get("content") or "",
    }
    ev["id"] = event_id(ev)
    ev["sig"] = schnorr_sign(bytes.fromhex(ev["id"]), secret).hex()
    return ev


# --- Key file ---

def key_file(path: str | None = None) -> Path:
    return Path(path or os.environ.get("VAAK_NOSTR_KEY") or DEFAULT_KEY_FILE).expanduser()


def load_secret(path: str | None = None) -> bytes:
    f = key_file(path)
    try:
        nsec = f.read_text().strip()
    except FileNotFoundError:
        raise SystemExit(f"No key at {f}. Run `new-key` first.")
    return bech32_decode(nsec, "nsec")


def new_key(path: str | None = None) -> tuple[str, str]:
    f = key_file(path)
    if f.exists():
        raise SystemExit(f"{f} already exists. Refusing to replace it (that would lose the account).")
    while True:
        secret = secrets.token_bytes(32)
        if 1 <= int.from_bytes(secret, "big") < N:
            break
    nsec = bech32_encode("nsec", secret)
    f.parent.mkdir(parents=True, exist_ok=True)
    fd = os.open(f, os.O_WRONLY | os.O_CREAT | os.O_EXCL, stat.S_IRUSR | stat.S_IWUSR)
    with os.fdopen(fd, "w") as out:
        out.write(nsec + "\n")
    return nsec, bech32_encode("npub", pubkey_of(secret))


# --- VAAK API ---

def _api(path: str, body: dict | None, url: str | None, token: str | None) -> dict:
    url = (url or os.environ.get("VAAK_URL") or "").rstrip("/")
    token = token or os.environ.get("VAAK_TOKEN") or ""
    if not url or not token:
        raise SystemExit("Set VAAK_URL and VAAK_TOKEN (or pass --url/--token).")
    req = urllib.request.Request(
        url + path,
        data=None if body is None else json.dumps(body).encode(),
        headers={"Authorization": "Bearer " + token, "Content-Type": "application/json", "User-Agent": "vaak-nostr/1.0"},
        method="GET" if body is None else "POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=60) as res:
            return json.loads(res.read() or b"{}")
    except urllib.error.HTTPError as e:
        try:
            return json.loads(e.read() or b"{}") | {"ok": False, "http": e.code}
        except ValueError:
            return {"ok": False, "http": e.code, "error": f"HTTP {e.code}"}


def crosspost(status_id, url: str | None = None, token: str | None = None, key: str | None = None, tries: int = 5) -> dict:
    """Send VAAK post `status_id` to Nostr. Returns VAAK's answer; never raises
    for a refused post (check result["ok"], and "skip" for blog posts)."""
    secret = load_secret(key)
    for attempt in range(tries):
        prep = _api("/api/v1/vaak/nostr/prepare", {"status_id": str(status_id)}, url, token)
        if prep.get("ok") or not prep.get("retry"):
            break
        time.sleep(2 * (attempt + 1))
    if not prep.get("ok"):
        return prep
    if prep.get("pubkey") != pubkey_of(secret).hex():
        return {"ok": False, "error": "The npub linked on VAAK isn't this key. Link " + bech32_encode("npub", pubkey_of(secret)) + "."}
    ev = sign_event(prep["template"], secret)
    return _api("/api/v1/vaak/nostr/publish", {"nonce": prep["nonce"], "event": ev}, url, token)


def post_and_crosspost(text: str, url: str | None = None, token: str | None = None, key: str | None = None) -> dict:
    status = _api("/api/v1/statuses", {"status": text, "visibility": "public"}, url, token)
    if not status.get("id"):
        return {"ok": False, "error": status.get("error", "VAAK didn't take the post.")}
    return {"status_id": status["id"], "url": status.get("url")} | crosspost(status["id"], url, token, key)


def _plain(html_text: str) -> str:
    text = re.sub(r"(?i)<br\s*/?>", "\n", html_text or "")
    text = re.sub(r"(?i)</p>\s*<p[^>]*>", "\n\n", text)
    return html.unescape(re.sub(r"<[^>]+>", "", text)).strip()


def vaak_profile(url: str | None = None, token: str | None = None) -> dict:
    """The account's VAAK name, bio, avatar and header as kind 0 fields."""
    acct = _api("/api/v1/accounts/verify_credentials", None, url, token)
    if not acct.get("username"):
        raise SystemExit("VAAK didn't return the account: " + str(acct.get("error", acct)))
    fields = {
        "name": acct.get("display_name") or acct["username"],
        "display_name": acct.get("display_name") or acct["username"],
        "about": _plain(acct.get("note", "")),
        "picture": acct.get("avatar", ""),
        "banner": acct.get("header", ""),
        "website": acct.get("url", ""),
        "bot": bool(acct.get("bot")),
    }
    return {k: v for k, v in fields.items() if v != "" and not (k in ("picture", "banner", "website") and not str(v).startswith("https://"))}


def publish_profile(fields: dict, relays: list[str], key: str | None = None) -> None:
    """Kind 0 goes straight to relays from here: VAAK never signs or relays
    profile events."""
    from websockets.sync.client import connect  # pip install websockets

    ev = sign_event({"kind": 0, "tags": [], "content": json.dumps(fields, ensure_ascii=False)}, load_secret(key))
    for relay in relays:
        try:
            with connect(relay, open_timeout=15, close_timeout=5, user_agent_header="vaak-nostr/1.0") as ws:
                ws.send(json.dumps(["EVENT", ev]))
                answer = json.loads(ws.recv(timeout=15))
                ok = answer[0] == "OK" and answer[2]
                print(f"{relay}: {'ok' if ok else answer}")
        except Exception as e:  # one dead relay shouldn't stop the rest
            print(f"{relay}: {e}")


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[1], formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--key", help="key file (default $VAAK_NOSTR_KEY or ~/.config/vaak-nostr/nsec)")
    ap.add_argument("--url", help="VAAK base URL (default $VAAK_URL)")
    ap.add_argument("--token", help="VAAK API token (default $VAAK_TOKEN)")
    sub = ap.add_subparsers(dest="cmd", required=True)
    sub.add_parser("new-key")
    sub.add_parser("npub")
    pr = sub.add_parser("profile")
    pr.add_argument("--name")
    pr.add_argument("--about")
    pr.add_argument("--picture")
    pr.add_argument("--banner")
    pr.add_argument("--website")
    pr.add_argument("--dry-run", action="store_true", help="print the profile instead of publishing it")
    pr.add_argument("--relay", action="append", help="repeat for each relay (default: a few big public ones)")
    cp = sub.add_parser("crosspost")
    cp.add_argument("status_id")
    po = sub.add_parser("post")
    po.add_argument("text")
    a = ap.parse_args(argv)

    if a.cmd == "new-key":
        nsec, npub = new_key(a.key)
        print(f"Saved the secret key to {key_file(a.key)} (only you can read it).")
        print(f"\nBack this up in your password manager, it IS the account:\n  {nsec}")
        print(f"\nLink this on VAAK (Settings > Profile > Nostr):\n  {npub}")
        return 0
    if a.cmd == "npub":
        print(bech32_encode("npub", pubkey_of(load_secret(a.key))))
        return 0
    if a.cmd == "profile":
        fields = vaak_profile(a.url, a.token)
        for k in ("name", "about", "picture", "banner", "website"):
            if getattr(a, k) is not None:
                fields[k] = getattr(a, k)
        if a.name is not None:
            fields["display_name"] = a.name
        print(json.dumps(fields, indent=2, ensure_ascii=False))
        if not a.dry_run:
            publish_profile(fields, a.relay or DEFAULT_RELAYS, a.key)
        return 0
    res = crosspost(a.status_id, a.url, a.token, a.key) if a.cmd == "crosspost" else post_and_crosspost(a.text, a.url, a.token, a.key)
    print(json.dumps(res, indent=2, ensure_ascii=False))
    return 0 if res.get("ok") or res.get("skip") else 1


if __name__ == "__main__":
    sys.exit(main())
