#!/usr/bin/env python3
"""Post MKULTRA Radio's current track to Bluesky as the radio account.

Run hourly by mkultra-radio-announce.timer. Each run:
  - reads the current Icecast title (same source as the stream card),
  - skips if Icecast is offline or the track was already announced,
  - logs in to the account's PDS with an app password,
  - posts "Now playing" with a #NowPlaying tag, a link to the Streamplace
    page and a link card using the cached cover art as its thumbnail.

    python3 announce.py --dry-run   # print the post, send nothing
    python3 announce.py             # post (still skips repeats)

Env (stream.env): BSKY_PDS, BSKY_HANDLE, BSKY_APP_PASSWORD, plus the Icecast
vars nowplaying.py uses. Optional STREAM_PAGE_URL.
"""
import argparse, io, json, os, sys, urllib.error, urllib.request
from datetime import datetime, timezone

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import nowplaying as np  # noqa: E402

PDS = os.environ.get("BSKY_PDS", "https://bsky.mkultra.monster").rstrip("/")
HANDLE = os.environ.get("BSKY_HANDLE", "mkultra-radio.bsky.mkultra.monster")
STREAM_PAGE = os.environ.get("STREAM_PAGE_URL", f"https://stream.place/{HANDLE}")
STATE = os.path.join(np.STATE_DIR, "announce-state.json")
MAX_GRAPHEMES = 300


def xrpc(method, body=None, token=None, raw=None, ctype="application/json"):
    data = raw if raw is not None else (json.dumps(body).encode() if body is not None else None)
    req = urllib.request.Request(f"{PDS}/xrpc/{method}", data=data, method="POST" if data is not None else "GET")
    req.add_header("User-Agent", np.UA)
    if data is not None:
        req.add_header("Content-Type", ctype)
    if token:
        req.add_header("Authorization", f"Bearer {token}")
    try:
        with urllib.request.urlopen(req, timeout=20) as r:
            return json.loads(r.read() or b"{}")
    except urllib.error.HTTPError as e:
        raise RuntimeError(f"{method}: HTTP {e.code} {e.read()[:300]!r}") from None


def build_text(artist, title):
    """Return (text, facets). Facet offsets are UTF-8 byte offsets."""
    song = f"{title} by {artist}" if artist else title
    tail_tag, link_label = "#NowPlaying", STREAM_PAGE.split("://", 1)[-1]
    fixed = f"🎶 Now playing on MKULTRA Radio: \n\nTune in 24/7: {link_label}\n{tail_tag}"
    room = MAX_GRAPHEMES - len(fixed)
    if len(song) > room:
        song = song[: max(room - 1, 1)] + "…"
    head = f"🎶 Now playing on MKULTRA Radio: {song}\n\nTune in 24/7: "
    text = f"{head}{link_label}\n{tail_tag}"
    b = lambda s: len(s.encode("utf-8"))  # noqa: E731
    link_start = b(head)
    tag_start = b(f"{head}{link_label}\n")
    facets = [
        {"index": {"byteStart": link_start, "byteEnd": link_start + b(link_label)},
         "features": [{"$type": "app.bsky.richtext.facet#link", "uri": STREAM_PAGE}]},
        {"index": {"byteStart": tag_start, "byteEnd": tag_start + b(tail_tag)},
         "features": [{"$type": "app.bsky.richtext.facet#tag", "tag": "NowPlaying"}]},
    ]
    return text, facets


def thumb_bytes(cover):
    """JPEG under Bluesky's 1 MB blob limit, or None."""
    if not cover or not os.path.exists(cover):
        return None
    im = np.Image.open(cover).convert("RGB")
    im.thumbnail((800, 800))
    for q in (85, 70, 55):
        buf = io.BytesIO()
        im.save(buf, "JPEG", quality=q)
        if buf.tell() < 950_000:
            return buf.getvalue()
    return None


def load_state():
    try:
        with open(STATE) as f:
            return json.load(f)
    except Exception:
        return {}


def save_state(state):
    tmp = STATE + ".tmp"
    with open(tmp, "w") as f:
        json.dump(state, f)
    os.replace(tmp, STATE)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    raw = np.current_title()
    if not raw:
        np.log("announce: Icecast offline or no title, skipping")
        return
    state = load_state()
    if raw == state.get("last") and not args.dry_run:
        np.log("announce: same track as last post, skipping:", raw)
        return
    artist, title = np.split_title(raw)
    text, facets = build_text(artist, title)
    thumb = thumb_bytes(np.cover_for(artist, title))
    if args.dry_run:
        print(text)
        print(json.dumps(facets))
        print("thumb:", f"{len(thumb)} bytes" if thumb else "none")
        return

    pw = os.environ.get("BSKY_APP_PASSWORD", "")
    if not pw:
        sys.exit("announce: BSKY_APP_PASSWORD is not set")
    sess = xrpc("com.atproto.server.createSession", {"identifier": HANDLE, "password": pw})
    token, did = sess["accessJwt"], sess["did"]
    external = {"uri": STREAM_PAGE, "title": "MKULTRA Radio · live 24/7",
                "description": f"Now playing: {raw}"}
    if thumb:
        blob = xrpc("com.atproto.repo.uploadBlob", token=token, raw=thumb, ctype="image/jpeg")
        external["thumb"] = blob["blob"]
    record = {
        "$type": "app.bsky.feed.post",
        "text": text,
        "facets": facets,
        "langs": ["en"],
        "embed": {"$type": "app.bsky.embed.external", "external": external},
        "createdAt": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
    }
    res = xrpc("com.atproto.repo.createRecord", {"repo": did, "collection": "app.bsky.feed.post", "record": record}, token=token)
    save_state({"last": raw, "uri": res.get("uri"), "at": record["createdAt"]})
    np.log("announce: posted", res.get("uri"))


if __name__ == "__main__":
    main()
