# Contributing

Do not commit secrets or live operational data. That includes API keys, tokens, passwords, private keys, `.env` files, database credentials, SMTP and OAuth secrets, production server addresses, internal hostnames, and dumps or captures from the running service.

Secrets for the running app belong in server env files such as `/etc/mkultra/*.env`. Secrets for CI and deploy belong in GitHub Actions repository secrets or GitHub Environment secrets. See [SECURITY.md](SECURITY.md).

CI runs gitleaks on git history (`.github/workflows/gitleaks.yml`). An optional local check is `git config core.hooksPath .githooks`, which installs the gitleaks pre-commit hook. The hook needs `gitleaks` on your `PATH`.
