#!/bin/sh
# Push MKULTRA Radio to Streamplace: Icecast audio + now-playing card
# (nowplaying.py) + Winamp-style spectrum bars and a scope line.
#
# Env (see stream.env.example): ICECAST_URL, STREAMPLACE_KEY, RADIO_STATE_DIR,
# optional STREAM_OUT (override the RTMPS target, e.g. a local test.flv),
# STREAM_FPS, STREAM_VBITRATE, STREAM_ABITRATE.
#
# Streamplace wants H.264, no B-frames, a keyframe every second, CBR, AAC.
set -eu
: "${ICECAST_URL:?set ICECAST_URL}"
STATE_DIR="${RADIO_STATE_DIR:-/var/lib/mkultra-radio}"
CARD="$STATE_DIR/card.png"
FPS="${STREAM_FPS:-24}"
VB="${STREAM_VBITRATE:-2500k}"
AB="${STREAM_ABITRATE:-160k}"
if [ -n "${STREAM_OUT:-}" ]; then
  OUT="$STREAM_OUT"
else
  : "${STREAMPLACE_KEY:?set STREAMPLACE_KEY}"
  OUT="rtmps://rtmp.stream.place:1935/live/$STREAMPLACE_KEY"
fi
# On a fresh start the card service may not have drawn the first card yet.
i=0
while [ ! -f "$CARD" ]; do
  i=$((i + 1))
  [ "$i" -gt 30 ] && { echo "no card at $CARD after 30 s (is mkultra-radio-card running?)" >&2; exit 1; }
  sleep 1
done

# Bars: 72 bands (0-16 kHz; MP3 has little above that) drawn as a white mask, blown up with nearest-neighbour to
# 1152 px, cut into blocks by two black grids, then used as the alpha of a
# green -> yellow -> red gradient. Scope: a pink centre line under the bars
# (mono, so showwaves doesn't paint the right channel in its default blue).
# Thread counts are capped so ffmpeg works within the one core systemd gives it.
exec ffmpeg -hide_banner -loglevel warning -nostdin -filter_threads 1 -filter_complex_threads 1 \
  -thread_queue_size 64 -f image2 -loop 1 -framerate "$FPS" -i "$CARD" \
  -reconnect 1 -reconnect_streamed 1 -reconnect_on_network_error 1 -reconnect_delay_max 30 \
  -rw_timeout 15000000 -i "$ICECAST_URL" \
  -f lavfi -i "gradients=s=1152x170:n=3:c0=0xff3030:c1=0xffe032:c2=0x39ff6a:x0=0:y0=0:x1=0:y1=169:speed=0.00001:r=$FPS" \
  -filter_complex "\
[1:a]aresample=48000:async=1,asplit=3[aout][abars][ascope];\
[abars]aresample=32000,showfreqs=s=72x170:mode=bar:ascale=log:fscale=log:win_size=2048:averaging=2:colors=white,format=gray,\
scale=1152x170:flags=neighbor,drawgrid=w=16:h=170:t=3:c=black,drawgrid=w=1152:h=6:t=2:c=black[mask];\
[2:v]format=rgba[grad];[grad][mask]alphamerge[bars];\
[ascope]aformat=channel_layouts=mono,showwaves=s=1152x50:mode=cline:draw=full:rate=$FPS:colors=0xff3cc8,format=rgba,colorkey=black:0.1[scope];\
[0:v]fps=$FPS,format=rgba[bg];[bg][bars]overlay=64:466:shortest=1[v1];[v1][scope]overlay=64:628,format=yuv420p[vout]" \
  -map "[vout]" -map "[aout]" \
  -c:v libx264 -threads 2 -preset veryfast -tune zerolatency -profile:v main -bf 0 \
  -g "$FPS" -keyint_min "$FPS" -sc_threshold 0 \
  -b:v "$VB" -maxrate "$VB" -bufsize "$VB" \
  -c:a aac -b:a "$AB" -ar 48000 -ac 2 \
  -f flv "$OUT"
