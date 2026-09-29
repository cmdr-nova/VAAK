#!/usr/bin/env python3
"""Durable, filtered Bluesky Jetstream spooler for VAAK.

The stream is deliberately only a transport layer.  PHP owns database writes
and canonicalization; this process persists each event before advancing the
cursor so a restart cannot silently lose a commit.
"""
import json
import os
import pathlib
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
MAX_FILTER_DIDS_IN_URL = 25
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


def stream_url(dids, since):
    query = [("wantedCollections", collection) for collection in COLLECTIONS]
    # Jetstream accepts repeated wantedDids, but the intermediary nginx layer
    # rejects very large WebSocket request URIs.  For larger graphs we use the
    # same collections-only stream and apply the DID filter before spooling.
    if len(dids) <= MAX_FILTER_DIDS_IN_URL:
        query.extend(("wantedDids", did) for did in dids)
    if since > 0:
        query.append(("cursor", str(since)))
    return ENDPOINT + ("&" if "?" in ENDPOINT else "?") + urllib.parse.urlencode(query)


def spool(ws, wanted):
    path = INCOMING / ("events-%d-%d-%d.jsonl" % (SHARD_INDEX, int(time.time()), os.getpid()))
    handle = path.open("a", encoding="utf-8")
    count = 0
    size = 0
    try:
        while True:
            raw = ws.recv()
            if raw is None:
                raise RuntimeError("Jetstream closed")
            event = json.loads(raw)
            if not isinstance(event, dict) or event.get("kind") != "commit":
                continue
            if event.get("did") not in wanted:
                continue
            line = json.dumps(event, separators=(",", ":"), ensure_ascii=False) + "\n"
            handle.write(line)
            handle.flush()
            os.fsync(handle.fileno())
            count += 1
            size += len(line.encode("utf-8"))
            if event.get("time_us") is not None:
                persist_cursor(event["time_us"])
            if count >= MAX_EVENTS or size >= MAX_BYTES:
                handle.close()
                path = INCOMING / ("events-%d-%d-%d.jsonl" % (SHARD_INDEX, int(time.time()), os.getpid()))
                handle = path.open("a", encoding="utf-8")
                count = 0
                size = 0
    finally:
        handle.close()


def main():
    STATE_DIR.mkdir(parents=True, exist_ok=True)
    INCOMING.mkdir(parents=True, exist_ok=True)
    backoff = 2
    while True:
        dids = selected_dids()
        if not dids:
            time.sleep(30)
            continue
        try:
            ws = websocket.create_connection(stream_url(dids, cursor()), timeout=45)
            backoff = 2
            spool(ws, set(dids))
        except Exception as exc:
            print("jetstream reconnect: %s" % exc, flush=True)
            time.sleep(min(backoff, 120))
            backoff = min(backoff * 2, 120)


if __name__ == "__main__":
    main()
