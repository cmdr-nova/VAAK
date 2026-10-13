# Deploying VAAK

Production deploys run from GitHub Actions. The workflow is [`.github/workflows/deploy-production.yml`](../.github/workflows/deploy-production.yml). It is manual (`workflow_dispatch`), it deploys only a commit that is on `main` and already has a green CI check, and the SSH step waits on the GitHub Environment named `production`.

Nothing in this workflow publishes the Jekyll blog. `NovaLandia/update_site.sh` stays the blog publisher. It excludes `/api/`, `/vaak/`, and `/clearance/`. This deploy never writes those exclusions' siblings: not the site root, not `clearance/`, not `/img/profile`, and not `update_site.sh`.

The live version in this checkout is **alpha 0.8.45** (`api/ap-version.php`, dated 2026-10-13). **0.8.35** is an older note at `docs/RELEASE-0.8.35.md`, not the constant the app reads.

## Version counter

`api/ap-version.php` is the only version source. It holds `VAAK_CHANNEL`, `VAAK_VERSION`, and `VAAK_VERSION_DATE`.

The login and registration page is `vaak/index.php` (`/vaak/` and `/vaak/?mode=register`). Both modes render `vaak_version_label()` from that file. The signed-in shell in `api/ap-admin.php` does the same. Do not hardcode a version on those pages.

The counter is not semver. Each deploy that asks for `version_bump=increment` adds 1 to the last component and always writes it as two digits:

| Current | Next |
| --- | --- |
| 0.8.45 | 0.8.46 |
| 0.8.09 | 0.8.10 |
| 0.8.99 | 0.9.00 |
| 0.9.99 | 0.10.00 |
| 0.99.99 | 1.0.00 |

`0.99.99` rolls the same way one place to the left: the middle component resets and the first component increments. `deploy/bump_version.py` is the implementation. `deploy/test_bump_version.py` locks the table above.

The bump updates only the live surfaces:

- `api/ap-version.php`
- the current-release, notes, and status lines in `README.md`
- the deployed-release line in `rust/README.md`
- a new `docs/RELEASE-<version>.md` (older release notes are left as history)

Choose `version_bump=none` when that commit already contains the counter you want to ship. The workflow then does not make another commit.

After a successful SSH activate, the workflow pushes an annotated tag `v<version>` and opens a GitHub Release whose notes are that release file. The tag is created only after the server health check passes. If the version commit was pushed and the later build or SSH step fails, re-run the workflow with `version_bump=none` and the version commit's SHA. Do not increment again until that commit has actually gone live, or the counter will skip a number that never deployed.

NodeInfo `software.version` stays `1.0.0`, and the Mastodon instance `version` stays `4.5.0 (compatible; vaak)`. Those strings are client-compatibility markers, not this counter.

## What you run

1. Create the GitHub Environment `production` and add yourself as a required reviewer. The workflow cannot create that protection; it only selects the environment name.
2. Put the four secrets on that environment (not as ordinary repository secrets, so other workflows cannot read them):

   | Secret | Contents |
   | --- | --- |
   | `VAAK_SSH_HOST` | Hostname or IP the runner can SSH to |
   | `VAAK_SSH_USER` | `vaak-deploy` |
   | `VAAK_SSH_KEY` | Private key for that user, PEM text including the header line |
   | `VAAK_SSH_KNOWN_HOSTS` | One or more `known_hosts` lines for the host. The workflow refuses a missing file and does not use `ssh-keyscan`. |

3. Actions → Deploy production → Run workflow.
   - `action=deploy`
   - `sha` empty for current `main`, or a full SHA that is already on `main`
   - `version_bump=increment` (default) or `none`
4. Approve the `production` environment prompt. The job then bumps (when asked), pushes that commit to `main`, waits until CI on the new commit is green, builds `vaak-worker` in a Debian bookworm container, and SSHes the artifact over.
5. The guard job's summary shows the commit and the version it intends to ship. CI for this repository must report at least one successful check on that commit other than the jobs in this workflow. A pending or failed check blocks the deploy. A commit with no other checks is refused.

`main` has to allow `github-actions[bot]` to push the version commit and the tag. If branch protection blocks that, add a bypass for the bot or the deploy stops before it builds.

## One-time server setup

Do this as root on the host. Do not point the release tree at the Jekyll document root.

```bash
id vaak-deploy >/dev/null 2>&1 || useradd --system --create-home --shell /bin/bash vaak-deploy
install -d -o vaak-deploy -g www-data -m 2775 /srv/mkultra/vaak-releases/{releases,incoming}
usermod -aG www-data vaak-deploy

# Seed the current live tree so the symlink cutover does not change bytes.
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
install -d -o vaak-deploy -g www-data -m 2775 "/srv/mkultra/vaak-releases/releases/legacy-${STAMP}"
cp -a /srv/mkultra/html/api "/srv/mkultra/vaak-releases/releases/legacy-${STAMP}/api"
cp -a /srv/mkultra/html/vaak "/srv/mkultra/vaak-releases/releases/legacy-${STAMP}/vaak"
install -d -o vaak-deploy -g www-data -m 2775 "/srv/mkultra/vaak-releases/releases/legacy-${STAMP}/bin"
install -m 0755 /usr/local/bin/vaak-worker "/srv/mkultra/vaak-releases/releases/legacy-${STAMP}/bin/vaak-worker"
# VERSION is required by a later rollback. Read it from the seeded PHP if it defines VAAK_VERSION.
php -r 'require $argv[1]; echo VAAK_VERSION, "\n";' /srv/mkultra/html/api/ap-version.php \
  > "/srv/mkultra/vaak-releases/releases/legacy-${STAMP}/VERSION"
printf 'legacy\n' > "/srv/mkultra/vaak-releases/releases/legacy-${STAMP}/REVISION"
chgrp -R www-data "/srv/mkultra/vaak-releases/releases/legacy-${STAMP}"
ln -sfn "releases/legacy-${STAMP}" /srv/mkultra/vaak-releases/current

# Replace only the VAAK paths. Leave index.html, the blog, clearance, and img/ alone.
mv /srv/mkultra/html/api /srv/mkultra/html/api.pre-symlink
mv /srv/mkultra/html/vaak /srv/mkultra/html/vaak.pre-symlink
ln -s /srv/mkultra/vaak-releases/current/api /srv/mkultra/html/api
ln -s /srv/mkultra/vaak-releases/current/vaak /srv/mkultra/html/vaak
ln -sfn /srv/mkultra/vaak-releases/current/bin/vaak-worker /usr/local/bin/vaak-worker
```

Confirm the site still answers before deleting `api.pre-symlink` and `vaak.pre-symlink`. The activate script refuses to run unless those three symlinks point at `current`. It will not convert a real directory itself, because that directory sits in the blog's web root.

Install the sudoers snippet after `visudo -cf`:

```bash
install -m 0440 -o root -g root deploy/vaak-deploy.sudoers /etc/sudoers.d/vaak-deploy
```

The lines grant `systemctl restart` for the VAAK units and `php8.3-fpm.service` only. There is no `ALL`, no shell, and no `daemon-reload`.

Create the migration env as a DDL role that is not `www-data`. `www-data` is intentionally unable to `CREATE`, and `ap_db_migrate_postgres()` swallows that failure during normal requests. The deploy runs `deploy/remote/migrate.php` as `vaak-deploy` before the symlink swap:

```bash
install -m 0640 -o root -g vaak-deploy /dev/null /etc/mkultra/vaak-migrate.env
```

```
AP_DB_DSN=pgsql:dbname=novalandia
AP_DB_USER=vaak_migrate
AP_DB_PASSWORD=replace-me
```

`vaak_migrate` needs `CREATE` on the application schema and must not be a superuser. The script takes a Postgres advisory lock, refuses to run when `CREATE` is missing, and fails the deploy if `ap-db.php` logs that a change was not provisioned. DDL is not wrapped in a down-migration. A failed health check puts the previous code back, but it does not undo columns that already committed.

Put `vaak-deploy`'s SSH public key in `/home/vaak-deploy/.ssh/authorized_keys`. The runner is a GitHub-hosted Ubuntu runner, so the host firewall has to allow that inbound SSH, or this workflow cannot connect.

## What the activate step does

`deploy/remote/vaak-release-activate.sh` runs on the server as `vaak-deploy`.

1. Takes a non-blocking lock at `/srv/mkultra/vaak-releases/.deploy.lock`.
2. Checks the three symlinks and refuses to continue if the release root is inside `/srv/mkultra/html`.
3. Extracts the tarball under `releases/<git sha>/`. The tarball is the CI artifact: filtered `api/`, `vaak/`, `bin/vaak-worker`, and `migrate.php`. Replay tools under `api/bin/` are omitted except the production CLIs `home-timeline-warm.php`, `notif-projection-fill.php`, `phyrian-decay.php`, `phyrian-sync.php`, and `ranked-warm.php`. `api/fixtures/` and `docs/` are omitted.
4. Copies these files forward from the previous tree when the new tree does not contain them: `api/network-status.php`, `api/discord-presence.php`, `api/.gts-config.php`, `api/sl_visitors.json`. The blog publisher SCPs the first two; they are not in this repository.
5. Runs `php -l` on the new PHP, then `migrate.php` against that tree, before changing `current`.
6. Points `current` at the new directory with `mv -Tf` on a symlink. `html/api`, `html/vaak`, and `/usr/local/bin/vaak-worker` follow `current` and are not rewritten.
7. Restarts the units below, then requires `http://127.0.0.1:8787/healthz` to contain `"ok":true`, every required unit to be active, and `VAAK_VERSION` to match the release `VERSION` file.
8. On a failed health check, points `current` back at the previous release, restarts again, and exits non-zero.
9. Keeps the live release, the previous release, and five other directories. It does not touch backup timers or `/var/backups`.

Required restarts:

- `php8.3-fpm.service`
- `vaak-redis-queue-worker.service`
- `vaak-worker-notif.service`
- `vaak-worker-notif-list.service`
- `vaak-worker-ranked-warm.service`
- `vaak-worker-thin-warm.service`
- `vaak-worker-actor-warm.service`
- `vaak-worker-ap-actor-warm.service`
- `vaak-worker-timeline-fanout.service`
- `vaak-worker-shadow-http.service` (Axum on `127.0.0.1:8787`)

Restarted only when the unit is installed:

- `vaak-worker-shadow.service`
- `ap-bsky-jetstream.service`

`ap-bsky-jetstream@.service` instances are not restarted. Their `ExecStart` reads `api/deploy/ap-bsky-jetstream.py` from the tree this release ships, so an enabled instance keeps the old process until it is restarted by hand. Oneshot timers pick up the new PHP on their next run because they exec the path through `current`.

The worker binary is built in CI (`cargo build --release --locked -p vaak-worker` inside `debian:bookworm-slim`) and is not compiled on the server.

## Roll back

Run the same workflow with `action=rollback` and `rollback_to` set to a git SHA already under `/srv/mkultra/vaak-releases/releases/`, or to a version such as `0.8.45`. The environment approval still applies. Rollback swaps `current`, restarts, and health-checks. It does not run migrations and it does not move the git tag.

From the server, the same command is:

```bash
bash /srv/mkultra/vaak-releases/incoming/vaak-release-activate.sh rollback 0.8.45
```

Stopping the Rust units entirely, and turning the PHP feature flags off, is still the cutover rollback in `rust/README.md`. That is a different operation from swapping this release symlink.

## Scripts this replaces

These stay in the tree. They are not how production is deployed anymore:

- Ad hoc `scp` of `rust/target/release/vaak-worker` onto `/usr/local/bin/vaak-worker`, including the example in `rust/README.md`.
- Ad hoc `rsync` or `scp` of `api/` and `vaak/` from a local checkout.
- Archived `sync-vaak-public.sh`. That file is not in this repository; do not run a copy that still lives on a workstation.

These are not deploys of the VAAK app and this workflow does not replace them:

- `NovaLandia/update_site.sh` (Jekyll blog; keeps excluding `api/`, `vaak/`, and `clearance/`)
- `deploy_clearance.sh` (Clearance, separate tree)
- `deploy/vaak-postgres-backup.sh`, `deploy/vaak-disc3-backup.sh`, and their timers
- `deploy/vaak-integrity-check.sh`
- The systemd units and cron files under `deploy/` and `ops/`. Installing or changing a unit is still a root edit under `/etc/systemd/system`. This workflow does not copy unit files and it cannot `daemon-reload`.

## Local checks

```bash
python3 deploy/test_bump_version.py
python3 deploy/test_require_green_ci.py
python3 deploy/bump_version.py --check
bash deploy/assemble-release.sh --self-test
bash deploy/remote/vaak-release-activate.sh --self-test
```

Do not point those commands at a server.
