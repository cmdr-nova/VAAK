# Shana's ListenBrainz posts

`vaak_listening.py` runs on the grid box (149.56.107.36) as user shana, from
/home/shana/.local/ai, every 20 minutes (shana-vaak-listening.timer). When Nova
(ListenBrainz user foxx_nova) is listening right now, it may post one short
#NowPlaying post as @shana_flux and crosspost it to Nostr.

Rules: max 2 posts a day (New York time), 6 h apart, 35% coin flip once per song,
no same track within 60 days, no same artist within 7. Uses the PC's model through
the tunnel when it answers, else the grid's shana-qwen3-4b. Word filter + the model's
SAFE/UNSAFE check, like the shitpost job.

    venv/bin/python vaak_listening.py --status        # what's on and what the rules say
    venv/bin/python vaak_listening.py --dry-run 3     # sample posts, nothing posted
    venv/bin/python vaak_listening.py --post          # post now (still no repeats)
    touch vaak_listening.PAUSED                       # stop the timer posting

State: vaak_listening_state.json (its own file; never the shitpost state).
Log: journalctl -u shana-vaak-listening
