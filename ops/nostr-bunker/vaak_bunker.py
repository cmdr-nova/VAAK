#!/usr/bin/env python3
"""
A small NIP-46 bunker: holds one Nostr key on this machine and signs for
paired apps (VAAK's Home Screen app, or any NIP-46 client) over relays.

The key never leaves this machine. An app pairs once with a one-time
bunker:// link from `pair`; after that the bunker signs that app's requests
on its own, but only for the kinds in --kinds, at most --per-minute and
--per-day of them, and only until you `revoke` it. Every signature is
logged.

Commands:
    init                    make the bunker's own key (not your Nostr key)
    pair [--name NAME]      print a one-time bunker:// link (valid 1 hour)
    clients                 list paired apps
    revoke CLIENT           unpair an app (its npub or hex key, or "all")
    run                     serve requests (run it under systemd)

Settings (flags or environment):
    --key / VAAK_NOSTR_KEY          your nsec file (the key it signs with)
    --dir / VAAK_BUNKER_DIR         state dir, default ~/.config/vaak-bunker
    --relay / VAAK_BUNKER_RELAYS    relays (comma separated in the env)

Needs `websockets` (pip install websockets) and vaak_nostr.py from
ops/nostr-signer next to this file (for BIP-340 and bech32).
"""
from __future__ import annotations

import argparse
import asyncio
import base64
import hashlib
import hmac
import json
import logging
import os
import secrets
import stat
import struct
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
sys.path.insert(1, str(Path(__file__).resolve().parent.parent / "nostr-signer"))
import vaak_nostr as vn  # noqa: E402

KIND = 24133
DEFAULT_DIR = Path.home() / ".config" / "vaak-bunker"
DEFAULT_RELAYS = ["wss://relay.primal.net", "wss://nos.lol"]
# Notes, follow list, deletes, reposts, reactions, reports, mute list.
# Never kind 0 (profile) or 10002 (relay list).
DEFAULT_KINDS = [1, 3, 5, 6, 7, 1984, 10000]
PAIR_TTL = 3600
CLOCK_SLACK = 600
MAX_CONTENT = 64 * 1024

log = logging.getLogger("vaak-bunker")


# --- NIP-44 v2 (pure Python; messages are small) ---

def _rotl(v, c):
    return ((v << c) & 0xFFFFFFFF) | (v >> (32 - c))


def _chacha20_block(key: bytes, counter: int, nonce: bytes) -> bytes:
    c = [0x61707865, 0x3320646E, 0x79622D32, 0x6B206574]
    s = c + list(struct.unpack("<8I", key)) + [counter] + list(struct.unpack("<3I", nonce))
    x = s[:]

    def qr(a, b, cc, d):
        x[a] = (x[a] + x[b]) & 0xFFFFFFFF; x[d] = _rotl(x[d] ^ x[a], 16)
        x[cc] = (x[cc] + x[d]) & 0xFFFFFFFF; x[b] = _rotl(x[b] ^ x[cc], 12)
        x[a] = (x[a] + x[b]) & 0xFFFFFFFF; x[d] = _rotl(x[d] ^ x[a], 8)
        x[cc] = (x[cc] + x[d]) & 0xFFFFFFFF; x[b] = _rotl(x[b] ^ x[cc], 7)

    for _ in range(10):
        qr(0, 4, 8, 12); qr(1, 5, 9, 13); qr(2, 6, 10, 14); qr(3, 7, 11, 15)
        qr(0, 5, 10, 15); qr(1, 6, 11, 12); qr(2, 7, 8, 13); qr(3, 4, 9, 14)
    return struct.pack("<16I", *[(x[i] + s[i]) & 0xFFFFFFFF for i in range(16)])


def chacha20(key: bytes, nonce: bytes, data: bytes) -> bytes:
    out = bytearray()
    for i in range(0, len(data), 64):
        block = _chacha20_block(key, i // 64, nonce)
        out.extend(a ^ b for a, b in zip(data[i:i + 64], block))
    return bytes(out)


def _hmac(key: bytes, *parts: bytes) -> bytes:
    return hmac.new(key, b"".join(parts), hashlib.sha256).digest()


def conversation_key(secret: bytes, pubkey_hex: str) -> bytes:
    pt = vn._lift_x(int(pubkey_hex, 16))
    if pt is None:
        raise ValueError("pubkey not on curve")
    shared = vn._mul(pt, int.from_bytes(secret, "big"))
    return _hmac(b"nip44-v2", shared[0].to_bytes(32, "big"))


def _message_keys(conv: bytes, nonce: bytes):
    t1 = _hmac(conv, nonce, b"\x01")
    t2 = _hmac(conv, t1, nonce, b"\x02")
    t3 = _hmac(conv, t2, nonce, b"\x03")
    okm = t1 + t2 + t3
    return okm[:32], okm[32:44], okm[44:76]


def _padded_len(n: int) -> int:
    if n <= 32:
        return 32
    next_power = 1 << (n - 1).bit_length()
    chunk = 32 if next_power <= 256 else next_power // 8
    return chunk * ((n - 1) // chunk + 1)


def nip44_encrypt(conv: bytes, text: str, nonce: bytes | None = None) -> str:
    raw = text.encode("utf-8")
    if not 1 <= len(raw) <= 65535:
        raise ValueError("message length out of range")
    nonce = nonce or secrets.token_bytes(32)
    key, iv, mac_key = _message_keys(conv, nonce)
    padded = struct.pack(">H", len(raw)) + raw + b"\x00" * (_padded_len(len(raw)) - len(raw))
    ct = chacha20(key, iv, padded)
    return base64.b64encode(b"\x02" + nonce + ct + _hmac(mac_key, nonce, ct)).decode()


def nip44_decrypt(conv: bytes, payload: str) -> str:
    raw = base64.b64decode(payload, validate=True)
    if len(raw) < 99 or raw[0] != 2:
        raise ValueError("unknown encryption version")
    nonce, ct, mac = raw[1:33], raw[33:-32], raw[-32:]
    key, iv, mac_key = _message_keys(conv, nonce)
    if not hmac.compare_digest(_hmac(mac_key, nonce, ct), mac):
        raise ValueError("bad mac")
    padded = chacha20(key, iv, ct)
    n = struct.unpack(">H", padded[:2])[0]
    if n < 1 or len(padded) != 2 + _padded_len(n):
        raise ValueError("bad padding")
    return padded[2:2 + n].decode("utf-8")


# --- State ---

class State:
    """signer.key (the bunker's own key) and clients.json in --dir."""

    def __init__(self, directory: Path):
        self.dir = directory.expanduser()
        self.file = self.dir / "clients.json"

    def _write_private(self, path: Path, text: str, exclusive=False) -> None:
        self.dir.mkdir(parents=True, exist_ok=True, mode=0o700)
        flags = os.O_WRONLY | os.O_CREAT | (os.O_EXCL if exclusive else os.O_TRUNC)
        fd = os.open(path, flags, stat.S_IRUSR | stat.S_IWUSR)
        with os.fdopen(fd, "w") as out:
            out.write(text)

    def signer_secret(self) -> bytes:
        path = self.dir / "signer.key"
        try:
            return bytes.fromhex(path.read_text().strip())
        except FileNotFoundError:
            raise SystemExit(f"No bunker key at {path}. Run `init` first.")

    def init(self) -> str:
        path = self.dir / "signer.key"
        if path.exists():
            raise SystemExit(f"{path} already exists.")
        while True:
            sk = secrets.token_bytes(32)
            if 1 <= int.from_bytes(sk, "big") < vn.N:
                break
        self._write_private(path, sk.hex() + "\n", exclusive=True)
        return vn.pubkey_of(sk).hex()

    def load(self) -> dict:
        try:
            data = json.loads(self.file.read_text())
        except FileNotFoundError:
            data = {}
        data.setdefault("clients", {})
        data.setdefault("pairing", {})
        now = time.time()
        data["pairing"] = {k: v for k, v in data["pairing"].items() if v.get("expires", 0) > now}
        return data

    def save(self, data: dict) -> None:
        tmp = self.file.with_suffix(".tmp")
        self._write_private(tmp, json.dumps(data, indent=1, sort_keys=True))
        os.replace(tmp, self.file)


def _hex_key(text: str) -> str:
    text = text.strip().lower()
    if text.startswith("npub1"):
        return vn.bech32_decode(text, "npub").hex()
    if len(text) == 64 and all(c in "0123456789abcdef" for c in text):
        return text
    raise SystemExit("Give the client's npub or 64-character hex key.")


# --- Serving ---

class Bunker:
    def __init__(self, state: State, user_secret: bytes, relays: list[str], kinds: list[int], per_minute: int, per_day: int):
        self.state = state
        self.user_secret = user_secret
        self.user_pubkey = vn.pubkey_of(user_secret).hex()
        self.signer_secret = state.signer_secret()
        self.signer_pubkey = vn.pubkey_of(self.signer_secret).hex()
        self.relays = relays
        self.kinds = set(kinds)
        self.per_minute = per_minute
        self.per_day = per_day
        self.seen: dict[str, float] = {}
        self.signed: dict[str, list[float]] = {}
        self.sockets: dict[str, object] = {}

    def _allowed_now(self, client: str) -> bool:
        now = time.time()
        times = [t for t in self.signed.get(client, []) if now - t < 86400]
        self.signed[client] = times
        if len([t for t in times if now - t < 60]) >= self.per_minute or len(times) >= self.per_day:
            return False
        times.append(now)
        return True

    def handle(self, client: str, req: dict) -> dict:
        """One decrypted request -> the JSON-RPC answer (without sending)."""
        rid = str(req.get("id", ""))
        method = str(req.get("method", ""))
        params = [str(p) for p in (req.get("params") or [])]
        data = self.state.load()
        paired = client in data["clients"]

        def ok(result: str) -> dict:
            return {"id": rid, "result": result}

        def err(text: str) -> dict:
            return {"id": rid, "result": "", "error": text}

        if method == "connect":
            secret = params[1] if len(params) > 1 else ""
            if paired:
                return ok("ack")
            entry = data["pairing"].pop(secret, None) if secret else None
            if entry is None:
                log.warning("refused connect from %s: no valid pairing secret", vn.bech32_encode("npub", bytes.fromhex(client)))
                return err("unauthorized: make a fresh link with `vaak_bunker.py pair`")
            data["clients"][client] = {"name": entry.get("name", ""), "paired_at": int(time.time())}
            self.state.save(data)
            log.info("paired %s (%s)", vn.bech32_encode("npub", bytes.fromhex(client)), entry.get("name", ""))
            return ok("ack")
        if not paired:
            return err("unauthorized")
        if method == "ping":
            return ok("pong")
        if method == "get_public_key":
            return ok(self.user_pubkey)
        if method != "sign_event":
            return err(f"{method} is not supported by this bunker")
        try:
            tpl = json.loads(params[0])
            kind = int(tpl["kind"])
            created_at = int(tpl.get("created_at") or time.time())
            tags = tpl.get("tags") or []
            content = str(tpl.get("content") or "")
        except (IndexError, ValueError, KeyError, TypeError):
            return err("bad event template")
        if kind not in self.kinds:
            log.warning("refused kind %s for %s", kind, data["clients"][client].get("name", client[:12]))
            return err(f"this bunker does not sign kind {kind}")
        if not isinstance(tags, list) or not all(isinstance(t, list) and all(isinstance(v, str) for v in t) for t in tags):
            return err("bad tags")
        if len(content.encode("utf-8")) > MAX_CONTENT or abs(created_at - time.time()) > CLOCK_SLACK:
            return err("event too large or clock too far off")
        if not self._allowed_now(client):
            log.warning("rate limit hit for %s", data["clients"][client].get("name", client[:12]))
            return err("rate limit: try again later")
        ev = {"pubkey": self.user_pubkey, "created_at": created_at, "kind": kind, "tags": tags, "content": content}
        ev["id"] = vn.event_id(ev)
        ev["sig"] = vn.schnorr_sign(bytes.fromhex(ev["id"]), self.user_secret).hex()
        data["clients"][client]["last_signed_at"] = int(time.time())
        self.state.save(data)
        log.info("signed kind %s %s for %s", kind, ev["id"][:12], data["clients"][client].get("name", client[:12]))
        return ok(json.dumps(ev, separators=(",", ":"), ensure_ascii=False))

    def answer_event(self, client: str, reply: dict) -> dict:
        conv = conversation_key(self.signer_secret, client)
        ev = {
            "pubkey": self.signer_pubkey,
            "created_at": int(time.time()),
            "kind": KIND,
            "tags": [["p", client]],
            "content": nip44_encrypt(conv, json.dumps(reply, separators=(",", ":"), ensure_ascii=False)),
        }
        ev["id"] = vn.event_id(ev)
        ev["sig"] = vn.schnorr_sign(bytes.fromhex(ev["id"]), self.signer_secret).hex()
        return ev

    def on_event(self, ev: dict) -> dict | None:
        """A kind 24133 from a relay -> the signed answer to publish, or None."""
        try:
            if ev.get("kind") != KIND or ev["id"] in self.seen:
                return None
            if not any(t[:2] == ["p", self.signer_pubkey] for t in ev.get("tags", [])):
                return None
            if vn.event_id(ev) != ev["id"] or not vn.schnorr_verify(bytes.fromhex(ev["id"]), bytes.fromhex(ev["pubkey"]), bytes.fromhex(ev["sig"])):
                return None
            now = time.time()
            self.seen = {k: v for k, v in self.seen.items() if now - v < 600}
            self.seen[ev["id"]] = now
            client = ev["pubkey"]
            req = json.loads(nip44_decrypt(conversation_key(self.signer_secret, client), ev["content"]))
        except (KeyError, ValueError, TypeError) as e:
            log.debug("dropped event: %s", e)
            return None
        return self.answer_event(client, self.handle(client, req))

    async def serve_relay(self, url: str) -> None:
        from websockets.asyncio.client import connect

        delay = 2
        while True:
            try:
                async with connect(url, open_timeout=15, ping_interval=30, user_agent_header="vaak-bunker/1.0") as ws:
                    self.sockets[url] = ws
                    delay = 2
                    log.info("listening on %s", url)
                    await ws.send(json.dumps(["REQ", "bunker", {"kinds": [KIND], "#p": [self.signer_pubkey], "since": int(time.time()) - 30}]))
                    async for raw in ws:
                        msg = json.loads(raw)
                        if msg[0] == "EVENT" and len(msg) > 2:
                            answer = self.on_event(msg[2])
                            if answer:
                                await self.broadcast(answer)
                        elif msg[0] == "NOTICE":
                            log.info("%s notice: %s", url, str(msg[1])[:160])
                        elif msg[0] == "OK" and not msg[2]:
                            log.warning("%s refused an answer: %s", url, str(msg[3])[:160])
            except asyncio.CancelledError:
                raise
            except Exception as e:  # reconnect on anything
                log.warning("%s: %s; retrying in %ss", url, e, delay)
            self.sockets.pop(url, None)
            await asyncio.sleep(delay)
            delay = min(delay * 2, 120)

    async def broadcast(self, ev: dict) -> None:
        text = json.dumps(["EVENT", ev], separators=(",", ":"), ensure_ascii=False)
        for url, ws in list(self.sockets.items()):
            try:
                await ws.send(text)
            except Exception as e:
                log.warning("%s send failed: %s", url, e)

    async def run(self) -> None:
        log.info("bunker %s signing for %s", vn.bech32_encode("npub", bytes.fromhex(self.signer_pubkey)), vn.bech32_encode("npub", bytes.fromhex(self.user_pubkey)))
        await asyncio.gather(*(self.serve_relay(u) for u in self.relays))


def bunker_link(signer_pubkey: str, relays: list[str], secret: str) -> str:
    from urllib.parse import quote

    query = "&".join(f"relay={quote(r, safe='')}" for r in relays)
    return f"bunker://{signer_pubkey}?{query}&secret={secret}"


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[1], formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--key", help="your nsec file (default $VAAK_NOSTR_KEY or ~/.config/vaak-nostr/nsec)")
    ap.add_argument("--dir", default=os.environ.get("VAAK_BUNKER_DIR") or str(DEFAULT_DIR))
    ap.add_argument("--relay", action="append", help="relay to listen on (repeatable)")
    ap.add_argument("--kinds", default=",".join(map(str, DEFAULT_KINDS)), help="kinds it may sign")
    ap.add_argument("--per-minute", type=int, default=20)
    ap.add_argument("--per-day", type=int, default=500)
    ap.add_argument("-v", "--verbose", action="store_true")
    sub = ap.add_subparsers(dest="cmd", required=True)
    sub.add_parser("init")
    pair = sub.add_parser("pair")
    pair.add_argument("--name", default="VAAK")
    sub.add_parser("clients")
    rev = sub.add_parser("revoke")
    rev.add_argument("client")
    sub.add_parser("run")
    args = ap.parse_args(argv)
    logging.basicConfig(level=logging.DEBUG if args.verbose else logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    relays = args.relay or [r.strip() for r in (os.environ.get("VAAK_BUNKER_RELAYS") or "").split(",") if r.strip()] or DEFAULT_RELAYS
    state = State(Path(args.dir))

    if args.cmd == "init":
        pk = state.init()
        print("Bunker key made. Its npub (not your Nostr account):", vn.bech32_encode("npub", bytes.fromhex(pk)))
        return 0
    if args.cmd == "pair":
        signer = vn.pubkey_of(state.signer_secret()).hex()
        secret = secrets.token_hex(16)
        data = state.load()
        data["pairing"][secret] = {"name": args.name, "expires": time.time() + PAIR_TTL}
        state.save(data)
        print("Paste this in VAAK > Settings > Profile > Nostr bunker within an hour (it works once):")
        print(bunker_link(signer, relays, secret))
        return 0
    if args.cmd == "clients":
        data = state.load()
        if not data["clients"]:
            print("No paired apps.")
        for pk, info in sorted(data["clients"].items()):
            last = info.get("last_signed_at")
            when = time.strftime("%Y-%m-%d %H:%M", time.localtime(last)) if last else "never"
            print(f"{vn.bech32_encode('npub', bytes.fromhex(pk))}  {info.get('name', '')}  paired {time.strftime('%Y-%m-%d', time.localtime(info.get('paired_at', 0)))}  last signed {when}")
        return 0
    if args.cmd == "revoke":
        data = state.load()
        if args.client == "all":
            n = len(data["clients"])
            data["clients"] = {}
        else:
            n = 1 if data["clients"].pop(_hex_key(args.client), None) is not None else 0
        state.save(data)
        print(f"Revoked {n}. A running bunker picks this up on the next request.")
        return 0
    if args.cmd == "run":
        kinds = [int(k) for k in args.kinds.split(",") if k.strip()]
        if 0 in kinds or 10002 in kinds:
            raise SystemExit("Refusing to sign kind 0 or 10002 for apps; drop them from --kinds.")
        bunker = Bunker(state, vn.load_secret(args.key), relays, kinds, args.per_minute, args.per_day)
        try:
            asyncio.run(bunker.run())
        except KeyboardInterrupt:
            pass
        return 0
    return 1


if __name__ == "__main__":
    sys.exit(main())
