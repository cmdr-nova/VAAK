# Security

This repository is a public source mirror. Do not commit secrets, production hostnames, server addresses, or captures from the live database.

## Where secrets live

Runtime secrets stay on the server, outside git and outside the web root:

- `/etc/mkultra/*.env` holds application, database, SMTP, OAuth, object-storage, and publish configuration
- `/etc/mkultra/ap-inbox/` holds ActivityPub private keys
- `/etc/mkultra/vapid.json` holds Web Push (VAPID) keys

CI and deploy credentials stay in GitHub Actions repository secrets or GitHub Environment secrets. Workflow files may name those secrets. They must not contain the values.

## Reporting

If you find a credential or a production secret in this repo, report it privately to the maintainer and rotate the credential. Deleting a file does not remove it from git history.

## Optional pre-commit scan

[gitleaks](https://github.com/gitleaks/gitleaks) runs in CI (`.github/workflows/gitleaks.yml`) against the default ruleset and `.gitleaks.toml`. To scan staged changes locally:

```sh
git config core.hooksPath .githooks
```

That enables `.githooks/pre-commit`, which runs `gitleaks protect --staged`. Unset `core.hooksPath` to turn the hook off.
