# Deploying VAAK

Production deploys run from GitHub Actions. The workflow is [`.github/workflows/deploy-production.yml`](../.github/workflows/deploy-production.yml). It is manual (`workflow_dispatch` on `main`). It deploys a commit that is already on `main` and has a green CI check, or a version-only release commit whose parent has that check. The activate job waits on the GitHub Environment named `production` and runs on a self-hosted runner on the production host. GitHub-hosted runners cannot SSH to the server, so this workflow does not use SSH.

Nothing in this workflow publishes the Jekyll blog. `NovaLandia/update_site.sh` stays the blog publisher. It `rsync -avz --delete`s the site root with `--exclude '/api/'` and `--exclude '/vaak/'` (trailing slash, so those rules match directories only; a second rsync uses `api/` and `vaak/`). This deploy never writes the site root, `clearance/`, `/img/profile`, or `update_site.sh`.

`/srv/mkultra/html/api` and `/srv/mkultra/html/vaak` stay **real directories**. rsync without `-L` treats a symlink as a non-directory, so if those paths were symlinks the blog publish would delete them. This deploy does not depend on a change to `update_site.sh`. The safest companion change, in the owner's NovaLandia copy of that script, is to drop the trailing slash (`--exclude '/api'` and `--exclude '/vaak'`, and the same for the second rsync). Do that when convenient. Do not treat it as a requirement for this deploy.

The counter in this checkout is **alpha 0.8.46** (`api/ap-version.php`, dated 2026-10-13). That release commit is on `main` and was not activated. **0.8.35** is an older note at `docs/RELEASE-0.8.35.md`, not the constant the app reads.

The production host is the documented machine (bots, Bluesky feeds, the Jekyll site, and VAAK). Web roots are `/srv/mkultra/html/api` and `/srv/mkultra/html/vaak`. The worker path is `/usr/local/bin/vaak-worker`. The app env file is `/etc/mkultra/vaak.env`. PHP-FPM is `php8.3-fpm.service`. The host is Ubuntu 24.04.4 LTS, x86_64, glibc 2.39. The workflow still builds the binary inside `debian:bookworm-slim` (glibc 2.36), which that host can run.

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

GitHub does not run CI on a commit this workflow pushes with its own token. The deploy does not wait for checks on that commit. The guard job has already required a green check on the parent. Before activation, the new commit must have that parent and a diff that only touches the version files above. `deploy/release_commit.py` checks the file list and that the bytes match what `deploy/bump_version.py` would write.

If `main` is already that release commit, or later commits did not change the app, `version_bump=increment` does not take the next number while the server's live version is still lower. It ships the counter already in the tree. The live number is read from `/srv/mkultra/html/api/ap-version.php` on the production host. An equal or newer server stops the run instead of inventing the next number. `version_bump=none` still deploys the commit you named, including a redeploy of the version that is already live.

### Shipping 0.8.46

`828be543b3b53bf3ef81a1b05e2bbda9f24e2eb8` is already "Release VAAK alpha 0.8.46" and was not activated. Merge the workflow fix, wait until the checks on that merge are green, then run **Deploy production** from `main`:

- `action`: deploy
- `sha`: leave empty
- `version_bump`: increment

Leave `sha` empty so the run uses the new tip of `main`. Do not paste `828be54` after the merge; `main` will have moved, and the workflow only deploys the current tip. The app files are still 0.8.46. If the server is on an older counter, the run activates 0.8.46 and does not commit 0.8.47. `version_bump=none` ships that same tree even when 0.8.46 is already live.

After a successful activate, the workflow pushes an annotated tag `v<version>` and opens a GitHub Release whose notes are that release file. The tag is created only after the health check passes. If a later step fails after the version commit is already on `main`, run the workflow again with `sha` empty. Do not increment again until that counter has actually gone live.

NodeInfo `software.version` stays `1.0.0`, and the Mastodon instance `version` stays `4.5.0 (compatible; vaak)`. Those strings are client-compatibility markers, not this counter.

## What you run

1. Create the GitHub Environment `production` and add yourself as a required reviewer. The workflow cannot create that protection; it only selects the environment name. This workflow does not read deploy secrets. Do not put an SSH private key on the environment.
2. Install the self-hosted runner (below) with labels `self-hosted` and `vaak-prod`, running as `vaak-deploy`, registered on this repository only.
3. Actions → Deploy production → Run workflow, from `main`.
   - `action=deploy`
   - `sha` empty for the current tip of `main`. A full SHA has to be that same tip.
   - `version_bump=increment` (default) or `none`
4. The guard job checks CI on the source commit. The build job compiles `vaak-worker` from that CI-green commit and uploads the binary. Approve the `production` environment prompt. The self-hosted job then either publishes a version commit (parent already green, diff limited to the version files, no CI wait) or, when that version is already on `main` and the server is still older, ships it as-is. It then activates the release locally.
5. The guard job's summary shows the commit and the version it intends to ship. CI must report at least one successful check on the source commit other than the jobs in this workflow. A pending or failed check blocks the deploy. A normal commit with no other checks is refused. A version-only release commit is accepted when its parent has that green CI.

`main` has to allow `github-actions[bot]` to push the version commit and the tag. If branch protection blocks that, add a bypass for the bot or the deploy stops before it activates.

There is no SSH step. Delete the secret `VAAK_DEPLOY` if it is still present: that was a personal SSH key, and a personal key must not be a deploy credential. Delete `VAAK_SSH_HOST`, `VAAK_SSH_USER`, `VAAK_SSH_KEY`, and `VAAK_SSH_KNOWN_HOSTS` too if they were added. This workflow does not read them.

## Self-hosted runner

GitHub-hosted runners cannot reach SSH on this host. The activate and rollback jobs run on the server as `vaak-deploy`.

Register a **repository** runner on `cmdr-nova/VAAK`, not an organization runner. In the runner's `config.sh`, add the label `vaak-prod` (`self-hosted` is added automatically). Install the service as `vaak-deploy`, not as root:

```bash
install -d -o vaak-deploy -g vaak-deploy /home/vaak-deploy/actions-runner
# As vaak-deploy, from the unpacked runner directory:
./config.sh --url https://github.com/cmdr-nova/VAAK --token '<one-time token from repo Settings → Actions → Runners>' --name vaak-prod --labels vaak-prod --unattended
./svc.sh install vaak-deploy
./svc.sh start
```

`svc.sh install` writes a systemd unit that runs `run.sh` as `vaak-deploy`. Leave it that way. Do not point the unit at root. The activate step uses `python3` from the stdlib (no extra packages), plus `bash`, `rsync`, `tar`, and the system `php`. Ubuntu 24.04 has those. The GitHub Release is tagged from a GitHub-hosted job after activate succeeds, so the server does not need the `gh` CLI.

This repository is public. A self-hosted runner will execute whatever workflow job names its labels. A pull request from a fork can propose a workflow that uses `runs-on: [self-hosted, vaak-prod]` and omit the `production` environment, and GitHub will not ask the environment reviewers. The runner must not pick up those jobs.

- The deploy workflow is `workflow_dispatch` only, and its self-hosted jobs also require `github.repository == cmdr-nova/VAAK`, `github.ref == refs/heads/main`, and the `production` environment.
- Do not put the `vaak-prod` label on any other workflow, including pull-request CI.
- In Settings → Actions → General, require approval for all outside collaborators, and do not approve a fork workflow that targets this runner.
- Do not send repository secrets to workflows from fork pull requests (the GitHub default).

The runner only needs outbound HTTPS to GitHub. It does not open an SSH port.

## One-time server setup

Do this as root on the host. Do not point the release tree at the Jekyll document root, and do not replace `html/api` or `html/vaak` with symlinks.

```bash
id vaak-deploy >/dev/null 2>&1 || useradd --system --create-home --shell /bin/bash vaak-deploy
install -d -o vaak-deploy -g www-data -m 2775 /srv/mkultra/vaak-releases/{releases,incoming,bin}
usermod -aG www-data vaak-deploy

# api and vaak stay real directories. Group-writable and setgid so vaak-deploy
# can replace files and www-data can read what the deploy writes.
chgrp www-data /srv/mkultra/html/api /srv/mkultra/html/vaak
chmod 2775 /srv/mkultra/html/api /srv/mkultra/html/vaak
find /srv/mkultra/html/api /srv/mkultra/html/vaak -type d -exec chmod 2775 {} +
find /srv/mkultra/html/api /srv/mkultra/html/vaak -type d -exec chgrp www-data {} +

# Seed a rollback target from the tree that is already live.
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
REL="/srv/mkultra/vaak-releases/releases/legacy-${STAMP}"
install -d -o vaak-deploy -g www-data -m 2775 "$REL"
cp -a /srv/mkultra/html/api "$REL/api"
cp -a /srv/mkultra/html/vaak "$REL/vaak"
install -d -o vaak-deploy -g www-data -m 2775 "$REL/bin"
install -m 0755 /usr/local/bin/vaak-worker "$REL/bin/vaak-worker"
php -r 'require $argv[1]; echo VAAK_VERSION, "\n";' /srv/mkultra/html/api/ap-version.php > "$REL/VERSION"
printf 'legacy\n' > "$REL/REVISION"
chgrp -R www-data "$REL"
printf 'legacy-%s\n' "$STAMP" > /srv/mkultra/vaak-releases/live-revision
install -m 0755 /usr/local/bin/vaak-worker /srv/mkultra/vaak-releases/bin/vaak-worker
ln -sfn /srv/mkultra/vaak-releases/bin/vaak-worker /usr/local/bin/vaak-worker
```

`/usr/local/bin/vaak-worker` is outside the blog tree. A symlink there is safe. The activate script refuses to run if `html/api` or `html/vaak` is a symlink. If an earlier experiment already replaced them with symlinks, put real directories back (`cp -aL` into a new directory, then `mv` it into place) before the next blog publish.

Leave these four files in `/srv/mkultra/html/api` alone. They are not in git. `update_site.sh` no longer copies `network-status.php` or `discord-presence.php`; the copies on the server are canonical:

- `network-status.php`
- `discord-presence.php`
- `.gts-config.php`
- `sl_visitors.json`

The activate script excludes those four names from every rsync into `html/api`, including a rollback. They are not deleted and they are not replaced from a release tree. Any other file under `api/` or `vaak/` that is not in the release is removed. Add a name to `PRESERVE_API_FILES` in `deploy/remote/vaak-release-activate.sh` before relying on another untracked file surviving a deploy.

Install the sudoers snippet after `visudo -cf`:

```bash
install -m 0440 -o root -g root deploy/vaak-deploy.sudoers /etc/sudoers.d/vaak-deploy
```

The lines grant `systemctl restart` for the VAAK units and `php8.3-fpm.service` only. There is no `ALL`, no shell, and no `daemon-reload`. Restarting `php8.3-fpm` is a brief outage and is the intended signal so PHP-FPM loads the new files. The sudoers file stays restart-only.

Create the migration env as a DDL role that is not `www-data`. The deploy runs `deploy/remote/migrate.php` as `vaak-deploy` before switching the live tree:

```bash
install -m 0640 -o root -g vaak-deploy /dev/null /etc/mkultra/vaak-migrate.env
```

```
AP_DB_DSN=pgsql:dbname=novalandia
AP_DB_USER=vaak_migrate
```

`vaak_migrate` needs `CREATE` on the application schema and must not be a superuser. The script takes a Postgres advisory lock, refuses to run when `CREATE` is missing, and fails the deploy if `ap-db.php` logs that a change was not provisioned. DDL is not wrapped in a down-migration. A failed health check puts the previous code back, but it does not undo columns that already committed.

VAAK's PHP calls `ap_db_migrate_postgres()` on normal requests, so that DDL runs as `www-data`. The function catches a missing `CREATE` and logs it. Altering a table or sequence owned by `vaak_migrate` is different: `www-data` has to be a member of `vaak_migrate`, or those requests fail and the app returns 503s. Create `vaak_migrate` as a non-superuser `LOGIN` role, transfer table and sequence ownership to it, grant `www-data` DML and default privileges, then grant the membership:

```sql
CREATE ROLE vaak_migrate WITH LOGIN NOSUPERUSER;
GRANT CREATE ON SCHEMA public TO vaak_migrate;

-- Moves tables and sequences in the application schema.
DO $$
DECLARE
  obj record;
BEGIN
  FOR obj IN
    SELECT n.nspname AS schema_name, c.relname AS object_name, c.relkind
    FROM pg_class c
    JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE n.nspname = 'public'
      AND c.relkind IN ('r', 'p', 'S')
  LOOP
    IF obj.relkind = 'S' THEN
      EXECUTE format('ALTER SEQUENCE %I.%I OWNER TO vaak_migrate', obj.schema_name, obj.object_name);
    ELSE
      EXECUTE format('ALTER TABLE %I.%I OWNER TO vaak_migrate', obj.schema_name, obj.object_name);
    END IF;
  END LOOP;
END
$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO "www-data";
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO "www-data";
ALTER DEFAULT PRIVILEGES FOR ROLE vaak_migrate IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO "www-data";
ALTER DEFAULT PRIVILEGES FOR ROLE vaak_migrate IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO "www-data";

GRANT vaak_migrate TO "www-data";
```

Run that SQL as a superuser. The env file also sets `AP_DB_PASSWORD`. Keep that value only on the server. `GRANT vaak_migrate TO "www-data";` is required once tables are owned by `vaak_migrate`. `"www-data"` inherits that membership, which is what lets request-time DDL succeed.

## What the activate step does

`deploy/remote/vaak-release-activate.sh` runs on the server as `vaak-deploy`.

1. Takes a non-blocking lock at `/srv/mkultra/vaak-releases/.deploy.lock`.
2. Refuses to continue unless `html/api` and `html/vaak` are real directories, `/usr/local/bin/vaak-worker` is a symlink to `/srv/mkultra/vaak-releases/bin/vaak-worker`, and the release root is outside `/srv/mkultra/html`.
3. Extracts the tarball under `releases/<git sha>/`. The tarball is the CI artifact: filtered `api/`, `vaak/`, `bin/vaak-worker`, and `migrate.php`. Replay tools under `api/bin/` are omitted except the production CLIs `home-timeline-warm.php`, `notif-projection-fill.php`, `phyrian-decay.php`, `phyrian-sync.php`, and `ranked-warm.php`. `api/fixtures/` and `docs/` are omitted.
4. Runs `php -l` on the new PHP, then `migrate.php` against that tree, before copying it into the live directories.
5. `rsync -a --checksum --delete-delay --delay-updates` copies `api/` and `vaak/` into the real directories. `--checksum` replaces a file whose bytes changed even when the size and mtime match. The four canonical files above are excluded, so a deploy and a rollback both leave them as they are on disk. The worker binary is installed with `mv` onto `/srv/mkultra/vaak-releases/bin/vaak-worker`.
6. Restarts the required units, then requires `http://127.0.0.1:8787/healthz` to contain `"ok":true`, every required unit to be active, and the live `VAAK_VERSION` to match the release `VERSION` file.
7. On a failed health check, rsyncs the previous release back the same way (still excluding the four canonical files), restarts, and exits non-zero.
8. Keeps the live release, the previous release, and five other directories. It does not touch backup timers or `/var/backups`.

Required restarts, including the brief `php8.3-fpm` restart:

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

Restarted only when that unit is installed **and** the diff says so:

- `vaak-worker-shadow.service`, when the diff touches `rust/vaak-worker/`, `rust/vaak-types/`, `rust/Cargo.toml`, `rust/Cargo.lock`, or `deploy/vaak-worker-shadow.service`. The workflow passes that decision from `git diff` so a fresh bookworm build of unchanged Rust does not restart it. A PHP-only deploy leaves the running shadow process on its current binary.
- `ap-bsky-jetstream@0.service` and `ap-bsky-jetstream@1.service`, when the diff touches `deploy/ap-bsky-jetstream.py`, its requirements or friend-host file, or the Jetstream unit files.

Production runs those two template instances. There is no `ap-bsky-jetstream.service`. Their `ExecStart` reads `api/deploy/ap-bsky-jetstream.py` through the real `html/api` directory, so a Jetstream diff restarts both installed instances. Oneshot timers pick up the new PHP on their next run because they exec the path in the real directory. Rollback compares the two release trees and restarts an optional unit when that tree diff includes its files.

The worker binary is built in CI (`cargo build --release --locked -p vaak-worker` inside `debian:bookworm-slim`) and is not compiled on the server. The version commit does not rebuild it. The build job compiles the CI-green commit, which is the parent when the tip is only the version bump. That binary is the one that ships.

## Roll back

Run the same workflow with `action=rollback` and `rollback_to` set to a git SHA already under `/srv/mkultra/vaak-releases/releases/`, or to a version such as `0.8.45`. The environment approval still applies. Rollback rsyncs that release into the real directories, restarts, and health-checks. It does not run migrations, it does not move the git tag, and it does not touch the four canonical `api/` files.

From the server, the same command is:

```bash
bash /home/vaak-deploy/actions-runner/_work/VAAK/VAAK/deploy/remote/vaak-release-activate.sh rollback 0.8.45
```

The checked-out path depends on the runner layout. The workflow runs the script from its own checkout; the line above is the usual repository-runner work directory.

Stopping the Rust units entirely, and turning the PHP feature flags off, is still the cutover rollback in `rust/README.md`. That is a different operation from putting the previous release back.

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
python3 deploy/test_release_commit.py
python3 deploy/test_restart_plan.py
python3 deploy/bump_version.py --check
bash deploy/assemble-release.sh --self-test
bash deploy/remote/vaak-release-activate.sh --self-test
```

Do not point those commands at a server.
