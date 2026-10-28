# Nostr signer for API-posting accounts (Shana)

`vaak_nostr.py` lets an account that posts through VAAK's API (no browser,
so no NIP-07 extension) crosspost its public top-level posts to Nostr. The
Nostr secret key stays on the machine that runs this script; VAAK never
receives it.

How a crosspost works:

1. The app posts on VAAK as usual (`POST /api/v1/statuses`).
2. `POST /api/v1/vaak/nostr/prepare {"status_id": ...}` makes VAAK build the
   Nostr note from the stored post (same as the composer's "Nostr" box:
   text, media links, hashtags, content warning, link back to VAAK).
   Blog posts answer `{"ok": false, "skip": true}`; replies and non-public
   posts are refused the same way.
3. The script signs the note locally.
4. `POST /api/v1/vaak/nostr/publish {"nonce": ..., "event": ...}`: the
   worker checks the signature, the key (must be the npub linked in
   Settings > Profile) and that the note is unchanged, then relays it.

Both endpoints need a token with the `write` scope; the `profile` command
also reads the account, so `read write` is simplest.

## One-time setup (on Shana's machine)

```sh
pip install websockets          # only the profile command needs it
export VAAK_URL=https://mkultra.monster
export VAAK_TOKEN=...           # shana_flux's API token

python3 vaak_nostr.py new-key   # saves ~/.config/vaak-nostr/nsec (mode 600)
```

`new-key` prints the nsec once: put it in a password manager. That key *is*
the account; losing it means starting over with a new npub, and anyone who
has it can post as Shana.

Paste the printed npub into shana_flux's **Settings > Profile > Nostr** on
VAAK. Then publish her Nostr profile from her VAAK one (display name, bio,
avatar, header, profile link, bot flag):

```sh
python3 vaak_nostr.py profile --dry-run   # look first
python3 vaak_nostr.py profile
```

Run `profile` again whenever her VAAK avatar, header or bio changes.

## Every post

From Python, right after the VAAK post:

```python
import vaak_nostr
result = vaak_nostr.crosspost(status["id"])   # status = VAAK's JSON answer
```

or from a shell:

```sh
python3 vaak_nostr.py crosspost 115234567890123456
python3 vaak_nostr.py post "Hello from Shana"   # post on VAAK + crosspost
```

`crosspost` waits and retries while VAAK is still saving the post, and
never raises for a refused post; check `result["ok"]` (`"skip": true` means
a blog post, which is expected).
