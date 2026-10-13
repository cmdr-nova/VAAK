#!/usr/bin/env bash
# Build the release vaak-worker inside Debian bookworm so the binary links
# against glibc 2.36. Run this as root in a container with the repo at /src.
set -euo pipefail

cd /src
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y --no-install-recommends build-essential ca-certificates curl pkg-config
curl --proto '=https' --tlsv1.2 -fsSL https://sh.rustup.rs | sh -s -- -y --profile minimal --default-toolchain stable
# shellcheck disable=SC1091
source "${HOME}/.cargo/env"
export CARGO_TERM_COLOR=never
cargo build --release --locked --manifest-path rust/Cargo.toml -p vaak-worker
install -m 0755 rust/target/release/vaak-worker /src/vaak-worker.bin
if [[ -n "${HOST_UID:-}" && -n "${HOST_GID:-}" ]]; then
  chown "${HOST_UID}:${HOST_GID}" /src/vaak-worker.bin
fi
echo "worker_built $(sha256sum /src/vaak-worker.bin)"
