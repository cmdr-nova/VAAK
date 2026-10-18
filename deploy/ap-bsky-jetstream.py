#!/usr/bin/env python3
"""Durable, filtered Bluesky Jetstream spooler for VAAK.

The stream is deliberately only a transport layer.  PHP owns database writes
and canonicalization; this process persists each event before advancing the
cursor so a restart cannot silently lose a commit.

Segments are written as ``*.jsonl.part`` and renamed to ``*.jsonl`` only once
they are closed, so the PHP worker never claims a file this process is still
appending to.
"""
import json
import os
import pathlib
import re
import time
import urllib.parse

import websocket

ENDPOINT = os.environ.get("VAAK_JETSTREAM_URL", "wss://jetstream1.eurosky.network/subscribe")
STATE_DIR = pathlib.Path(os.environ.get("VAAK_JETSTREAM_STATE", "/var/lib/mkultra/ap/jetstream"))
WANTED_FILE = STATE_DIR / "wanted-dids.json"
CURSOR_FILE = STATE_DIR / "cursor"
INCOMING = STATE_DIR / "incoming"
MAX_EVENTS = 500
MAX_BYTES = 8 * 1024 * 1024
# A segment with events is handed to PHP after this many seconds, so Home
# stays about as fresh as the 5 second worker timer allows.
SEGMENT_MAX_AGE = float(os.environ.get("VAAK_JETSTREAM_SEGMENT_SECS", "3"))
# recv() wakes this often on a quiet stream to close a pending segment.
POLL_SECS = 1.0
# With no event for this long, reconnect from the cursor. This replaces the
# old 45 second socket timeout as the dead-connection check.
IDLE_RECONNECT_SECS = 120
WANTED_CHECK_SECS = 60
MAX_FILTER_DIDS_IN_URL = 25
# Jetstream's options_update accepts up to 10,000 DIDs. Larger graphs fall
# back to the unfiltered collections stream with the local filter below.
MAX_FILTER_DIDS_IN_HELLO = 10000
# Jetstream closes the connection on any DID it cannot parse, so only
# well-formed DIDs go into options_update.
DID_RE = re.compile(r"^did:[a-z]+:[a-zA-Z0-9._:%-]*[a-zA-Z0-9._-]$")
# After this many hello connections fail before their first event, this
# process falls back to the unfiltered stream until it restarts.
HELLO_FAILURE_LIMIT = 3
SHARD_INDEX = max(0, int(os.environ.get("VAAK_JETSTREAM_SHARD_INDEX", "0")))
SHARD_COUNT = max(1, int(os.environ.get("VAAK_JETSTREAM_SHARD_COUNT", "1")))
COLLECTIONS = [
    "app.bsky.feed.post",
    "app.bsky.feed.repost",
]


def wanted_dids():
    try:
        raw = json.loads(WANTED_FILE.read_text())
        return sorted({str(v) for v in raw if isinstance(v, str) and v.startswith("did:")})
    except (OSError, ValueError):
        return []


def cursor():
    cursor_path = STATE_DIR / ("cursor-%d" % SHARD_INDEX)
    try:
        return int(cursor_path.read_text().strip())
    except (OSError, ValueError):
        return 0


def persist_cursor(value):
    cursor_path = STATE_DIR / ("cursor-%d" % SHARD_INDEX)
    tmp = cursor_path.with_suffix(".tmp")
    tmp.write_text(str(int(value)))
    os.replace(tmp, cursor_path)


def selected_dids():
    dids = wanted_dids()
    return dids[SHARD_INDEX::SHARD_COUNT]


hello_failures = 0


def use_hello(dids):
    if hello_failures >= HELLO_FAILURE_LIMIT:
        return False
    return MAX_FILTER_DIDS_IN_URL < len(dids) <= MAX_FILTER_DIDS_IN_HELLO


def stream_url(dids, since):
    query = [("wantedCollections", collection) for collection in COLLECTIONS]
    # Jetstream accepts repeated wantedDids, but the intermediary nginx layer
    # rejects very large WebSocket request URIs.  Larger lists are sent after
    # connecting in an options_update message (see hello_message).
    if len(dids) <= MAX_FILTER_DIDS_IN_URL:
        query.extend(("wantedDids", did) for did in dids)
    elif use_hello(dids):
        query.append(("requireHello", "true"))
    if since > 0:
        query.append(("cursor", str(since)))
    return ENDPOINT + ("&" if "?" in ENDPOINT else "?") + urllib.parse.urlencode(query)


def hello_message(dids):
    return json.dumps(
        {
            "type": "options_update",
            "payload": {
                "wantedCollections": COLLECTIONS,
                "wantedDids": [did for did in dids if len(did) <= 2048 and DID_RE.match(did)],
                "maxMessageSizeBytes": 0,
            },
        },
        separators=(",", ":"),
    )


class Segment:
    """One spool file, invisible to the PHP worker until it is closed."""

    def __init__(self, incoming=None, clock=time.time):
        self.incoming = pathlib.Path(incoming or INCOMING)
        self.clock = clock
        self.handle = None
        self.path = None
        self.count = 0
        self.size = 0
        self.opened_at = 0.0
        self.sequence = 0

    def write(self, line):
        if self.handle is None:
            self.opened_at = self.clock()
            self.sequence += 1
            name = "events-%d-%d-%d-%06d.jsonl.part" % (
                SHARD_INDEX, int(self.opened_at * 1000), os.getpid(), self.sequence)
            self.path = self.incoming / name
            self.handle = self.path.open("a", encoding="utf-8")
        self.handle.write(line)
        self.handle.flush()
        os.fsync(self.handle.fileno())
        self.count += 1
        self.size += len(line.encode("utf-8"))

    def due(self):
        if self.handle is None:
            return False
        if self.count >= MAX_EVENTS or self.size >= MAX_BYTES:
            return True
        return self.clock() - self.opened_at >= SEGMENT_MAX_AGE

    def close(self):
        if self.handle is None:
            return
        self.handle.close()
        finalize(self.path)
        self.handle = None
        self.path = None
        self.count = 0
        self.size = 0


def finalize(path):
    """Publish a closed .part file to the worker, or drop it when empty."""
    path = pathlib.Path(path)
    try:
        if path.stat().st_size == 0:
            path.unlink()
            return
    except FileNotFoundError:
        return
    os.replace(path, path.with_name(path.name[: -len(".part")]))


def recover_partials(incoming=None):
    """Hand over segments a previous run of this shard left open."""
    incoming = pathlib.Path(incoming or INCOMING)
    for path in sorted(incoming.glob("events-%d-*.jsonl.part" % SHARD_INDEX)):
        finalize(path)


def spool(ws, dids, progress):
    wanted = set(dids)
    segment = Segment()
    last_event = time.monotonic()
    last_wanted_check = last_event
    try:
        while True:
            try:
                raw = ws.recv()
            except websocket.WebSocketTimeoutException:
                raw = None
                now = time.monotonic()
                if now - last_event >= IDLE_RECONNECT_SECS:
                    raise RuntimeError("Jetstream idle for %ds" % IDLE_RECONNECT_SECS)
            else:
                if raw is None or raw == "":
                    raise RuntimeError("Jetstream closed")
                last_event = time.monotonic()
                progress["received"] = True
                event = json.loads(raw)
                if isinstance(event, dict) and event.get("kind") == "commit" and event.get("did") in wanted:
                    line = json.dumps(event, separators=(",", ":"), ensure_ascii=False) + "\n"
                    segment.write(line)
                    if event.get("time_us") is not None:
                        persist_cursor(event["time_us"])
            if segment.due():
                segment.close()
            now = time.monotonic()
            if now - last_wanted_check >= WANTED_CHECK_SECS:
                last_wanted_check = now
                if selected_dids() != dids:
                    # Reconnect from the cursor with the new follow list.
                    return
    finally:
        segment.close()


def main():
    global hello_failures
    STATE_DIR.mkdir(parents=True, exist_ok=True)
    INCOMING.mkdir(parents=True, exist_ok=True)
    recover_partials()
    backoff = 2
    while True:
        dids = selected_dids()
        if not dids:
            time.sleep(30)
            continue
        ws = None
        hello = use_hello(dids)
        progress = {"received": False}
        started = time.monotonic()
        try:
            ws = websocket.create_connection(stream_url(dids, cursor()), timeout=45)
            if hello:
                ws.send(hello_message(dids))
            ws.settimeout(POLL_SECS)
            backoff = 2
            spool(ws, dids, progress)
        except Exception as exc:
            print("jetstream reconnect: %s" % exc, flush=True)
            # A rejected options_update closes the socket right away. A quiet
            # stream that hits the idle reconnect is not a failure.
            quick = time.monotonic() - started < 30
            if hello and quick and not progress["received"]:
                hello_failures += 1
                if hello_failures == HELLO_FAILURE_LIMIT:
                    print("jetstream: options_update keeps failing; using the unfiltered stream", flush=True)
            time.sleep(min(backoff, 120))
            backoff = min(backoff * 2, 120)
        finally:
            if ws is not None:
                try:
                    ws.close()
                except Exception:
                    pass


if __name__ == "__main__":
    main()
