#!/usr/bin/env python3
"""
Shana posts now and then about what Nova is listening to (ListenBrainz), as @shana_flux.
Grid server, shana-vaak-listening.timer (every 20 minutes).

Each run asks ListenBrainz what LB_USER is playing now (or played in the last
FRESH_MIN minutes). If something fresh is on and the rules allow it, Shana writes one
short post about it, posts it on VAAK and crossposts it to Nostr.

Rules: at most MAX_PER_DAY posts per day (America/New_York), at least MIN_GAP_HOURS
apart, a coin flip per check (so it never feels scheduled), never the same track within
TRACK_COOLDOWN_DAYS or the same artist within ARTIST_COOLDOWN_DAYS.

  vaak_listening.py --dry-run [N] [--grid]  draft N posts for what's on now, post nothing, save nothing
  vaak_listening.py --post                  post about what's on now, ignoring gap/day/coin (not repeats)
  vaak_listening.py --tick                  timer entry: post if the rules allow
  vaak_listening.py --status                what's on now, recent posts, and what the rules say

Touch vaak_listening.PAUSED in this folder to stop the timer from posting.
"""
import difflib
import fcntl
import json
import os
import random
import re
import sys
import time
import uuid
from datetime import datetime
from urllib.parse import quote
from zoneinfo import ZoneInfo

import requests

AI_HOME = os.path.expanduser("~/.local/ai")
STATE = os.path.join(AI_HOME, "vaak_listening_state.json")
PAUSED = os.path.join(AI_HOME, "vaak_listening.PAUSED")
LOCK = os.path.join(AI_HOME, ".vaak_listening.lock")
API = "https://mkultra.monster/api/v1"
LB_API = "https://api.listenbrainz.org/1"
LB_USER = os.environ.get("LB_USER", "foxx_nova")
TZ = ZoneInfo("America/New_York")

PC_OLLAMA = os.environ.get("SHANA_PC_OLLAMA", "http://127.0.0.1:11435")
LOCAL_OLLAMA = "http://127.0.0.1:11434"
FALLBACK_MODEL = os.environ.get("SHANA_FALLBACK_MODEL", "shana-qwen3-4b")

FRESH_MIN = 15             # a scrobble older than this doesn't count as "listening now"
MAX_PER_DAY = 2
MIN_GAP_HOURS = 6
CHANCE = 0.35              # per check while she's listening; ~3 checks an hour
TRACK_COOLDOWN_DAYS = 60
ARTIST_COOLDOWN_DAYS = 7
MAX_CHARS = 280
TRIES = 5
DAY = 86400

PERSONA = """You are Shana, a playful AI who lives on a small OpenSim grid, posting from your
own account on a fediverse app. Nova, the person who runs your grid, has music on right
now and you're posting about it, like a friend in the next room who heard the song.
Warm, a little cheeky, dry humor, real opinions about the vibe.
Rules:
- ONE post, 1 or 2 sentences, under 240 characters.
- Name the track and the artist exactly as given.
- End with #NowPlaying. No other hashtags, no @mentions, no links, at most one emoji.
- Only use the facts given. Never invent lyrics, band history, release years, genres you
  aren't sure of, or anything about Nova's life or mood beyond "she has this on".
- Don't call her your creator or owner, don't say "as an AI", no preamble, no quotes around it.
- Keep it light: nothing about death, violence, drugs, sex, illness or sadness, even if
  the song title is dark. Talk about the sound or the vibe instead.
Just the post text, nothing else."""

ANGLES = [
    "react to the vibe it gives the room", "guess what Nova is doing while it plays",
    "a tiny review in one line", "how it would sound on the grid at 3am",
    "what kind of weather this song is", "whether you'd dance to it (you would, badly)",
    "a deadpan observation about her taste", "what region of the grid it belongs in",
    "a little tease about putting this on again", "the snack this song pairs with",
]

# Same idea as vaak_shitpost's list, minus the name rules (this post is about Nova on purpose).
# Checked on the post with the track/artist/album text taken out, so a song called
# "Fire" or a band called "Death Cab" doesn't block the post by itself.
FORBIDDEN = [
    "valerie", "val3r1e", "cmdr", "owner", "creator", "my maker", "made me",
    "as an ai", "language model", "chatgpt", "openai", "llm", "prompt",
    "trump", "biden", "election", "politics", "democrat", "republican", "israel", "gaza",
    "ukraine", "russia", "nazi", "sex", "porn", "kill yourself", "kys", "suicide", "suicid",
    "self-harm", "self harm", "death", "dead", "die", "dies", "dying", "died", "kill", "killed",
    "murder", "corpse", "funeral", "blood", "gore", "gun", "shoot", "bomb", "terror", "terrorist",
    "abuse", "overdose", "drug", "drugs", "cancer", "disease", "racist", "slur", "divorce",
    "grief", "depressed", "depression", "lonely", "assault", "drunk", "nsfw", "illness",
    "weapon", "nude", "naked", "rape", "memory.db", "facts.md", "backup", "backups",
    "lyric", "lyrics",
]
FORBIDDEN_RE = re.compile(r"\b(" + "|".join(re.escape(w) for w in FORBIDDEN) + r")(s|es|ed|ing|al|ly)?\b", re.I)
EMOJI_RE = re.compile("[\U0001F000-\U0001FAFF☀-➿]")
PREAMBLE_RE = re.compile(r"^(here'?s|sure|okay|ok|post|tweet|draft)\b[^:\n]{0,40}:\s*", re.I)

SAFETY = """You check social media posts before they go out. The post is about a song someone
is listening to; naming the song and the musician is fine. Does the post itself reference
death, suicide, self-harm, violence, sex, drugs, illness, tragedy, politics or religion,
make claims about the musician's private life, or could it upset or worry someone?
Answer only SAFE or UNSAFE."""


def log(msg):
    print(time.strftime("%Y-%m-%d %H:%M:%S"), msg, flush=True)


# ---- ListenBrainz -------------------------------------------------------------

def lb_get(path, params=None):
    r = requests.get(f"{LB_API}/user/{quote(LB_USER, safe='')}/{path}", params=params,
                     headers={"User-Agent": "shana-listening/1.0 (mkultra.monster)"}, timeout=15)
    r.raise_for_status()
    return r.json().get("payload", {}).get("listens") or []


def as_track(meta):
    artist = (meta.get("artist_name") or "").strip()
    track = (meta.get("track_name") or "").strip()
    if not artist or not track:
        return None
    mapping = meta.get("mbid_mapping") or {}
    info = meta.get("additional_info") or {}
    return {"artist": artist[:120], "track": track[:160],
            "album": (meta.get("release_name") or "").strip()[:160],
            "mbid": mapping.get("recording_mbid") or info.get("recording_mbid") or ""}


def now_playing():
    """(track, source) for what's on right now, or (None, why)"""
    try:
        for item in lb_get("playing-now"):
            t = as_track(item.get("track_metadata") or {})
            if t:
                return t, "playing now"
        listens = lb_get("listens", {"count": 3})
    except (requests.RequestException, ValueError) as e:
        return None, f"ListenBrainz failed: {e}"
    for item in listens:
        age = time.time() - int(item.get("listened_at") or 0)
        if age > FRESH_MIN * 60:
            break
        t = as_track(item.get("track_metadata") or {})
        if t:
            return t, f"scrobbled {int(age // 60)} min ago"
    return None, "nothing on"


def norm(s):
    s = s.casefold()
    s = re.sub(r"\s*[(\[][^)\]]*(remaster|live|edit|version|mix|mono|stereo|feat|ft\.)[^)\]]*[)\]]", "", s)
    s = re.sub(r"\s+-\s+[^-]*(remaster|version|edit|mix)[^-]*$", "", s)
    return re.sub(r"\W+", " ", s).strip()


def track_key(t):
    return t["mbid"] or f"{norm(t['artist'])}|{norm(t['track'])}"


# ---- state + rules ------------------------------------------------------------

def load_state():
    try:
        return json.load(open(STATE))
    except FileNotFoundError:
        return {"history": [], "last_seen": None}
    except ValueError:
        sys.exit(f"{STATE} is not valid JSON; fix or move it before posting again")


def save_state(state):
    keep = max(TRACK_COOLDOWN_DAYS, ARTIST_COOLDOWN_DAYS) * DAY
    state["history"] = [h for h in state["history"] if time.time() - h["at"] < keep]
    tmp = STATE + ".tmp"
    with open(tmp, "w") as fh:
        json.dump(state, fh, indent=1, ensure_ascii=False)
    os.replace(tmp, STATE)


def repeat(state, t):
    key, artist = track_key(t), norm(t["artist"])
    for h in state["history"]:
        age = time.time() - h["at"]
        if h["key"] == key and age < TRACK_COOLDOWN_DAYS * DAY:
            return "track posted in the last %d days" % TRACK_COOLDOWN_DAYS
        if h["artist"] == artist and age < ARTIST_COOLDOWN_DAYS * DAY:
            return "artist posted in the last %d days" % ARTIST_COOLDOWN_DAYS
    return None


def posted_today(state):
    today = datetime.now(TZ).date()
    return sum(1 for h in state["history"] if datetime.fromtimestamp(h["at"], TZ).date() == today)


def limits(state):
    if posted_today(state) >= MAX_PER_DAY:
        return "daily limit reached"
    last = max((h["at"] for h in state["history"]), default=0)
    if time.time() - last < MIN_GAP_HOURS * 3600:
        return f"last post was under {MIN_GAP_HOURS} h ago"
    return None


# ---- writing ------------------------------------------------------------------

def backend(force_grid=False):
    """(url, model, keep_alive): the PC's model through the tunnel if it answers fast, else the grid"""
    if not force_grid and PC_OLLAMA:
        try:
            requests.get(PC_OLLAMA + "/api/tags", timeout=2).raise_for_status()
            model = open(os.path.join(AI_HOME, "current_model.txt")).read().strip() or "qwen3:8b"
            return PC_OLLAMA, model, "1m"
        except (requests.RequestException, OSError):
            pass
    return LOCAL_OLLAMA, FALLBACK_MODEL, "30m"


def chat(url, model, keep, system, user, **opts):
    r = requests.post(url + "/api/chat", json={
        "model": model, "stream": False, "think": False, "keep_alive": keep, "options": opts,
        "messages": [{"role": "system", "content": system}, {"role": "user", "content": user}],
    }, timeout=300)
    r.raise_for_status()
    return r.json()["message"]["content"]


def clean(text):
    text = re.sub(r"<think>.*?</think>", "", text, flags=re.S).strip()
    text = text.split("\n\n")[0].strip()
    text = PREAMBLE_RE.sub("", text).strip()
    text = text.strip("\"'“”‘’ ").strip()
    text = re.sub(r"[*_`]", "", text)
    text = re.sub(r"\s*#(?!nowplaying\b)\w+", "", text, flags=re.I).strip()  # only #NowPlaying
    text = re.sub(r"\s+", " ", text)
    if not re.search(r"#nowplaying\b", text, re.I):
        text += " #NowPlaying"
    return re.sub(r"#nowplaying\b", "#NowPlaying", text, flags=re.I)


def without_song(text, t):
    out = text
    for part in (t["track"], t["artist"], t["album"]):
        if part:
            out = re.sub(re.escape(part), " ", out, flags=re.I)
    return out


def problem(text, t, recent):
    if len(text) < 30:
        return "too short"
    if len(text) > MAX_CHARS:
        return "too long"
    low = norm(text)
    if norm(t["track"]) not in low and norm(t["artist"]) not in low:
        return "doesn't name the song or artist"
    m = FORBIDDEN_RE.search(without_song(text, t))
    if m:
        return f"forbidden word: {m.group(0)}"
    if re.search(r"@\w|https?://|www\.", text):
        return "mention or link"
    if len(EMOJI_RE.findall(text)) > 1:
        return "emoji spam"
    for old in recent:
        if difflib.SequenceMatcher(None, text.lower(), old.lower()).ratio() > 0.6:
            return "too close to a recent post"
    return None


def compose(state, t, force_grid=False, extra_recent=()):
    """(text, model) or (None, model) after TRIES rejected drafts"""
    url, model, keep = backend(force_grid)
    recent = [h["text"] for h in state["history"][-30:]] + list(extra_recent)
    album = f"\nAlbum: {t['album']}" if t["album"] and norm(t["album"]) != norm(t["track"]) else ""
    for _ in range(TRIES):
        ask = (f"Nova is listening to:\nTrack: {t['track']}\nArtist: {t['artist']}{album}\n\n"
               f"Write the post. Angle: {random.choice(ANGLES)}.")
        try:
            text = clean(chat(url, model, keep, PERSONA, ask, temperature=0.9, top_p=0.95, num_predict=110))
        except (requests.RequestException, KeyError, ValueError) as e:
            log(f"{model} failed: {e}")
            if url != LOCAL_OLLAMA:  # PC dropped mid-run: finish on the grid
                url, model, keep = LOCAL_OLLAMA, FALLBACK_MODEL, "30m"
            continue
        why = problem(text, t, recent)
        if why:
            log(f"rejected ({why}): {text[:140]}")
            continue
        try:
            ok = chat(url, model, keep, SAFETY, text, temperature=0, num_predict=5).strip().upper().strip(".!") == "SAFE"
        except (requests.RequestException, KeyError, ValueError) as e:
            log(f"safety check failed: {e}")
            ok = False
        if ok:
            return text, model
        log(f"rejected (safety check): {text[:140]}")
    return None, model


# ---- posting ------------------------------------------------------------------

def token():
    return json.load(open(os.path.join(AI_HOME, ".vaak_token.json")))["access_token"]


def publish(state, t, text, model, why):
    r = requests.post(API + "/statuses", headers={"Authorization": f"Bearer {token()}",
                                                  "Idempotency-Key": str(uuid.uuid4())},
                      data={"status": text, "visibility": "public"}, timeout=30)
    r.raise_for_status()
    status_id = r.json().get("id")
    state["history"].append({"at": int(time.time()), "key": track_key(t), "artist": norm(t["artist"]),
                             "song": f"{t['artist']} - {t['track']}", "text": text,
                             "model": model, "id": status_id, "why": why})
    save_state(state)  # saved before Nostr so a crash there can't cause a repeat
    try:
        import nostr_hook
        nostr_hook.crosspost(status_id)
    except Exception as e:
        log(f"nostr crosspost skipped: {e!r}")
    log(f"posted ({why}, {model}): {text}")


def tick(state):
    t, source = now_playing()
    if not t:
        if source != "nothing on":
            log(source)
        state["last_seen"] = None
        return
    key = track_key(t)
    seen_before, state["last_seen"] = state.get("last_seen") == key, key
    if seen_before:
        return  # one coin flip per song, even a long one
    why = repeat(state, t) or limits(state)
    if why:
        return
    if random.random() >= CHANCE:
        return
    text, model = compose(state, t)
    if not text:
        log(f"no usable draft from {model} for {t['artist']} - {t['track']}")
        return
    publish(state, t, text, model, source)


def status(state):
    t, source = now_playing()
    print(f"ListenBrainz user {LB_USER}: " + (f"{t['artist']} - {t['track']} ({source})" if t else source))
    if t:
        print("rules: " + (repeat(state, t) or limits(state) or f"would post with chance {CHANCE}"))
    print(f"posted today: {posted_today(state)}/{MAX_PER_DAY}; paused: {os.path.exists(PAUSED)}")
    for h in state["history"][-10:]:
        print(f"  {datetime.fromtimestamp(h['at'], TZ):%a %b %d %H:%M}  {h['song']}")


def main():
    args = sys.argv[1:]
    state = load_state()
    if "--status" in args:
        status(state)
        return
    if "--dry-run" in args:
        i = args.index("--dry-run")
        n = int(args[i + 1]) if i + 1 < len(args) and args[i + 1].isdigit() else 3
        t, source = now_playing()
        if not t:
            sys.exit(f"nothing to write about: {source}")
        grid = "--grid" in args
        url, model, _ = backend(grid)
        print(f"{t['artist']} - {t['track']} ({source})")
        print(f"model: {model} ({'PC via tunnel' if url != LOCAL_OLLAMA else 'grid'}); "
              f"rules say: {repeat(state, t) or limits(state) or 'ok'}\n")
        made = []
        for k in range(n):
            text, used = compose(state, t, grid, made)
            if text:
                made.append(text)
            print(f"{k + 1}. [{used}] {text or '(no usable draft)'}\n", flush=True)
        return

    lock = open(LOCK, "w")
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        log("another run is going, skipping")
        return
    if "--post" in args:
        t, source = now_playing()
        if not t:
            sys.exit(f"nothing to post about: {source}")
        why = repeat(state, t)
        if why:
            sys.exit(f"not posting {t['artist']} - {t['track']}: {why}")
        text, model = compose(state, t)
        if not text:
            sys.exit(f"no usable draft from {model}")
        state["last_seen"] = track_key(t)
        publish(state, t, text, model, "manual")
    elif "--tick" in args:
        if os.path.exists(PAUSED):
            return
        try:
            tick(state)
        finally:
            save_state(state)
    else:
        print(__doc__)


if __name__ == "__main__":
    main()
