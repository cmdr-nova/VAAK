# MKULTRA Radio on Streamplace

This runs a 24/7 Streamplace stream of MKULTRA Radio as
@mkultra-radio.bsky.mkultra.monster. The picture shows the cover art, title,
artist and MKULTRA RADIO, with Winamp-style spectrum bars and a scope line
underneath. Bluesky users watch it at `stream.place/mkultra-radio.bsky.mkultra.monster`.

Where things run:

- **Icecast** stays where it is, on the VAAK/mkultra box: `https://radio.mkultra.monster/radio`, with
  now-playing at `/status-json.xsl`.
- **This encoder** runs on the **grid box** (`<grid-host>`). It pulls the Icecast
  stream over the internet, about 0.2 Mbps in, and pushes about 2.7 Mbps out to Streamplace.
  The VAAK box only serves one extra Icecast listener.

There are two services:

| unit | what it does |
|---|---|
| `mkultra-radio-card` | `nowplaying.py` polls status-json every 5 s. When the song changes it looks up the cover (iTunes Search, then MusicBrainz/Cover Art Archive, then `RADIO_LOGO`, then a ♪ placeholder) and atomically rewrites `/var/lib/mkultra-radio/card.png`. Covers are cached in `art/`, and real misses are cached for 7 days. |
| `mkultra-radio-stream` | `stream.sh` runs ffmpeg. It reads `card.png` again every frame, so a new song shows up with no restart. It draws the bars and scope over the card and encodes 720p30 H.264 (no B-frames, 1 s keyframes, CBR 2500k) plus AAC 160k, which is what Streamplace asks for. It pushes to `rtmps://rtmp.stream.place:1935/live/<key>`. |

The encoder used about 0.7 of one core in testing. The unit caps it at 120% CPU and 768 MB of RAM.
If the grid box needs more room, set `STREAM_FPS=24` or `STREAM_VBITRATE=2000k`.

## Setup on the grid box (as root)

The box needs ffmpeg 4.4 or newer (for the `gradients` source) and Pillow.

    apt install ffmpeg python3-pil fonts-dejavu-core
    useradd --system --home /var/lib/mkultra-radio --shell /usr/sbin/nologin mkradio
    install -d /opt/mkultra-radio-stream
    install -m 0755 nowplaying.py stream.sh /opt/mkultra-radio-stream/
    install -d -m 0750 -g mkradio /etc/mkultra-radio
    install -m 0640 -g mkradio stream.env.example /etc/mkultra-radio/stream.env
    install -m 0644 mkultra-radio-card.service mkultra-radio-stream.service /etc/systemd/system/
    systemctl daemon-reload

To get the stream key, log in to https://stream.place as mkultra-radio.bsky.mkultra.monster.
Go to **Live Dashboard**, then **Stream from OBS**, choose RTMP, and click **Generate Stream Key**.
Paste the key into `STREAMPLACE_KEY` in `/etc/mkultra-radio/stream.env`.
Treat it like a password, because it signs every segment as that account.

### Dry run (nothing goes to Streamplace)

    sudo -u mkradio env $(grep -v '^#' /etc/mkultra-radio/stream.env | xargs) \
      RADIO_STATE_DIR=/tmp/mkr python3 /opt/mkultra-radio-stream/nowplaying.py --once
    sudo -u mkradio env $(grep -v '^#' /etc/mkultra-radio/stream.env | xargs) \
      RADIO_STATE_DIR=/tmp/mkr STREAM_OUT=/tmp/mkr/test.flv timeout 30 /opt/mkultra-radio-stream/stream.sh
    ffmpeg -ss 20 -i /tmp/mkr/test.flv -frames:v 1 /tmp/mkr/frame.png   # look at it

### Go live

    systemctl enable --now mkultra-radio-card mkultra-radio-stream
    journalctl -fu mkultra-radio-stream

Once the stream is running, go back to the Live Dashboard, set a title (for example
"MKULTRA Radio 24/7"), and click **Announce Livestream**. You only do this once per session.

### Stop / pause

    systemctl stop mkultra-radio-stream        # the card service can keep running

## Notes

- The stream key appears in ffmpeg's command line, so other local users on the box can see it in `ps`.
  The grid box is single-tenant, so that's acceptable there.
- Titles are split on the first `" - "` into artist and title. A title with no dash shows just the title.
- When Icecast is offline, the card shows "MKULTRA Radio" and ffmpeg keeps reconnecting.
  If it keeps failing, systemd stops after 10 restarts in 10 minutes. Run
  `systemctl reset-failed mkultra-radio-stream` and start it again.
- Music rights: Streamplace can label a stream `dmca-violation`. That's why this uses its own account.
