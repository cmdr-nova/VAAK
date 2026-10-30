# Nostr bunker (NIP-46) for VAAK's Home Screen app

A site added to the iPhone Home Screen runs outside Safari, and iOS never
loads Safari extensions there, so a NIP-07 signer like Nostash can't sign
from it. `vaak_bunker.py` is a small NIP-46 "bunker": it keeps your Nostr
key on a machine you choose (not the VAAK server) and signs for apps you
pair with it, over Nostr relays.

How VAAK uses it:

1. You paste a one-time `bunker://` link in **Settings > Profile > Nostr
   bunker**. VAAK makes a client key for this pairing, connects, and checks
   the bunker holds the same key as your linked npub.
2. On a page with no signer extension, a Nostr action (post, like, reply,
   repost, follow, mute, report) is prepared as usual, then the worker sends
   it to the bunker as an encrypted (NIP-44) `sign_event` request. The
   bunker signs, VAAK checks the answer is exactly what it asked for, and
   relays it.
3. Pages that do have an extension keep using it.

What the bunker will sign: kinds 1, 3, 5, 6, 7, 1984 and 10000 by default
(`--kinds`), never 0 (profile) or 10002 (relay list); at most 20 a minute
and 500 a day per app (`--per-minute`, `--per-day`); only for paired apps.
Every signature is logged (`journalctl -u vaak-bunker`).

If VAAK's server were broken into, the attacker would get VAAK's client
key, not your Nostr key: they could ask the bunker for those kinds until
you run `revoke`, but your key stays safe and never needs rotating.

## Setup (as root on the bunker machine)

```sh
useradd --system --create-home --shell /usr/sbin/nologin vaak-bunker
mkdir -p /opt/vaak-bunker
cp vaak_bunker.py ../nostr-signer/vaak_nostr.py /opt/vaak-bunker/
python3 -m venv /opt/vaak-bunker/venv
/opt/vaak-bunker/venv/bin/pip install 'websockets>=13'

# Your nsec, readable by vaak-bunker only. Type it in; don't paste it
# anywhere else.
install -d -m 700 -o vaak-bunker -g vaak-bunker /home/vaak-bunker/.config /home/vaak-bunker/.config/vaak-nostr
sudo -u vaak-bunker sh -c 'umask 077; cat > /home/vaak-bunker/.config/vaak-nostr/nsec'   # paste nsec1…, Enter, Ctrl-D

alias bunker='sudo -u vaak-bunker /opt/vaak-bunker/venv/bin/python /opt/vaak-bunker/vaak_bunker.py \
  --dir /home/vaak-bunker/.config/vaak-bunker --key /home/vaak-bunker/.config/vaak-nostr/nsec \
  --relay wss://relay.primal.net --relay wss://nos.lol'
bunker init          # the bunker's own key (prints its npub, not yours)

cp vaak-bunker.service /etc/systemd/system/
systemctl daemon-reload && systemctl enable --now vaak-bunker
journalctl -u vaak-bunker -n 20    # "listening on wss://…" for each relay

bunker pair --name "VAAK"          # prints bunker://… (one use, 1 hour)
```

Paste the `bunker://…` line in VAAK under **Settings > Profile > Nostr
bunker** and press Connect. The log shows `paired npub1… (VAAK)`.

The relays in `pair` must match the ones the service listens on
(`VAAK_BUNKER_RELAYS` in the unit). Change both together.

## Day to day

```sh
bunker clients               # paired apps and when each last had something signed
bunker revoke npub1…         # unpair one (or `revoke all`); takes effect on the next request
bunker pair --name "Phone"   # pair another NIP-46 app
```

Disconnecting in VAAK Settings forgets VAAK's side; run `revoke` too.
