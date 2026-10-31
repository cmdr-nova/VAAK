#!/usr/bin/env python3
"""MKULTRA Radio now-playing card for the Streamplace stream.

Polls Icecast's status-json.xsl, and whenever the title changes it looks up
cover art and redraws a 1280x720 PNG (cover, title, artist, MKULTRA RADIO).
ffmpeg (stream.sh) re-reads that PNG every frame, so the new card shows up
without restarting the stream. The file is replaced atomically.

    python3 nowplaying.py --once          # draw the current song and exit
    python3 nowplaying.py                 # run forever (systemd)
    python3 nowplaying.py --test "Artist - Title"   # draw a fake song

Needs Pillow only (apt install python3-pil).
"""
import argparse, hashlib, io, json, os, sys, time, urllib.parse, urllib.request

from PIL import Image, ImageDraw, ImageFilter, ImageFont

STATUS_URL = os.environ.get("ICECAST_STATUS_URL", "https://radio.mkultra.monster/status-json.xsl")
MOUNT = os.environ.get("ICECAST_MOUNT", "/radio")
STATE_DIR = os.environ.get("RADIO_STATE_DIR", "/var/lib/mkultra-radio")
CARD = os.path.join(STATE_DIR, "card.png")
ART_DIR = os.path.join(STATE_DIR, "art")
LOGO = os.environ.get("RADIO_LOGO", "")  # optional fallback cover image
POLL = float(os.environ.get("RADIO_POLL_SECONDS", "5"))
UA = "mkultra-radio-stream/1 (+https://mkultra.monster)"

W, H = 1280, 720
PINK, MUTED, WHITE, SOFT = (255, 60, 200), (170, 160, 200), (255, 255, 255), (205, 195, 235)
BG = (8, 6, 18)
# The visualizer well; stream.sh overlays the bars and scope inside it.
WELL = (60, 460, W - 60, H - 40)


def log(*a):
    print(*a, file=sys.stderr, flush=True)


def fetch(url, timeout=10, accept="application/json"):
    req = urllib.request.Request(url, headers={"User-Agent": UA, "Accept": accept})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return r.read()


def current_title():
    """Return the stream title for MOUNT, or '' when the source is down."""
    data = json.loads(fetch(STATUS_URL).decode("utf-8", "replace"))
    src = (data.get("icestats") or {}).get("source") or []
    if isinstance(src, dict):  # Icecast sends a dict when there is one mount
        src = [src]
    pick = next((s for s in src if str(s.get("listenurl", "")).endswith(MOUNT)), src[0] if src else {})
    return str(pick.get("title") or pick.get("yp_currently_playing") or "").strip()


def split_title(raw):
    if " - " in raw:
        artist, title = raw.split(" - ", 1)
        return artist.strip(), title.strip()
    return "", raw.strip()


def lookup_art(artist, title):
    """(cover bytes or None, whether every lookup actually got an answer).

    iTunes Search first, then MusicBrainz + Cover Art Archive. A network
    failure is not a real miss, so the caller won't cache it.
    """
    answered = True
    if not title:
        return None, True
    term = f"{artist} {title}".strip()
    try:
        q = urllib.parse.urlencode({"term": term, "entity": "song", "limit": 5})
        res = json.loads(fetch(f"https://itunes.apple.com/search?{q}"))
        for hit in res.get("results", []):
            if artist and artist.lower() not in str(hit.get("artistName", "")).lower():
                continue
            url = str(hit.get("artworkUrl100", ""))
            if url:
                return fetch(url.replace("100x100bb", "600x600bb"), accept="image/*"), True
    except Exception as e:  # lookups are best effort
        answered = False
        log("itunes lookup failed:", e)
    try:
        query = f'recording:"{title}"' + (f' AND artist:"{artist}"' if artist else "")
        q = urllib.parse.urlencode({"query": query, "fmt": "json", "limit": 5})
        res = json.loads(fetch(f"https://musicbrainz.org/ws/2/recording?{q}"))
        for rec in res.get("recordings", []):
            for rel in rec.get("releases", [])[:3]:
                try:
                    return fetch(f"https://coverartarchive.org/release/{rel['id']}/front-500", accept="image/*"), True
                except Exception:
                    continue  # 404 = that release has no front cover
    except Exception as e:
        answered = False
        log("musicbrainz lookup failed:", e)
    return None, answered


def cover_for(artist, title):
    """Cached cover path, or None. A miss is cached too so we don't hammer the APIs."""
    os.makedirs(ART_DIR, exist_ok=True)
    key = hashlib.sha1(f"{artist.lower()}\n{title.lower()}".encode()).hexdigest()
    path, miss = os.path.join(ART_DIR, key + ".jpg"), os.path.join(ART_DIR, key + ".miss")
    if os.path.exists(path):
        return path
    if os.path.exists(miss) and time.time() - os.path.getmtime(miss) < 7 * 86400:
        return None
    raw, answered = lookup_art(artist, title)
    if raw:
        try:
            Image.open(io.BytesIO(raw)).convert("RGB").save(path, "JPEG", quality=90)
            return path
        except Exception as e:
            log("bad cover image:", e)
    if answered:
        open(miss, "w").close()
    return None


def font(size, bold=True):
    names = ["DejaVuSans-Bold.ttf" if bold else "DejaVuSans.ttf"]
    for d in ["/usr/share/fonts/truetype/dejavu", "/usr/share/fonts/TTF"]:
        for n in names:
            p = os.path.join(d, n)
            if os.path.exists(p):
                return ImageFont.truetype(p, size)
    return ImageFont.load_default()


def fit(draw, text, size, bold, max_w):
    """Shrink, then ellipsize, so long titles stay on the card."""
    while size > 22:
        f = font(size, bold)
        if draw.textlength(text, font=f) <= max_w:
            return text, f
        size -= 2
    f = font(size, bold)
    while text and draw.textlength(text + "…", font=f) > max_w:
        text = text[:-1]
    return text + "…", f


def render(artist, title, cover):
    bg = Image.new("RGB", (W, H), BG)
    art = None
    for p in (cover, LOGO):
        if p and os.path.exists(p):
            art = Image.open(p).convert("RGB")
            break
    if art is not None:
        blur = art.resize((W, W)).crop((0, (W - H) // 2, W, (W + H) // 2)).filter(ImageFilter.GaussianBlur(40))
        bg = Image.blend(bg, blur, 0.3)
        art = art.resize((360, 360))
    else:
        art = Image.new("RGB", (360, 360), (40, 20, 70))
        ImageDraw.Draw(art).text((180, 180), "♪", font=font(170), anchor="mm", fill=PINK)
    d = ImageDraw.Draw(bg)
    d.rectangle((56, 56, 424, 424), fill=PINK)
    bg.paste(art, (60, 60))
    x, max_w = 470, W - 470 - 60
    d.text((x, 70), "MKULTRA RADIO", font=font(64), fill=PINK)
    d.text((x + 2, 150), "24/7 · now playing", font=font(26, False), fill=MUTED)
    t, f = fit(d, title or "MKULTRA Radio", 46, True, max_w)
    d.text((x, 250), t, font=f, fill=WHITE)
    if artist:
        a, f = fit(d, artist, 34, False, max_w)
        d.text((x, 315), a, font=f, fill=SOFT)
    d.rectangle(WELL, fill=(0, 0, 0), outline=(70, 50, 110), width=2)
    os.makedirs(STATE_DIR, exist_ok=True)
    tmp = CARD + ".tmp.png"
    bg.save(tmp)
    os.replace(tmp, CARD)


def draw_raw(raw):
    artist, title = split_title(raw)
    render(artist, title, cover_for(artist, title) if raw else None)
    log("card:", repr(raw) if raw else "(offline)")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--once", action="store_true")
    ap.add_argument("--test", metavar="ARTIST - TITLE")
    args = ap.parse_args()
    if args.test is not None:
        return draw_raw(args.test)
    if not os.path.exists(CARD):
        render("", "", None)  # ffmpeg needs a card before the first poll answers
    last = None
    while True:
        try:
            raw = current_title()
        except Exception as e:
            log("status poll failed:", e)
            raw = last if last is not None else ""
        if raw != last:
            try:
                draw_raw(raw)
                last = raw
            except Exception as e:
                log("render failed:", e)
        if args.once:
            return
        time.sleep(POLL)


if __name__ == "__main__":
    main()
