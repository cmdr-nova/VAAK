#!/usr/bin/env bash
# Self-test for deploy/runner-job-guard.sh.
set -euo pipefail
guard="$(cd "$(dirname "$0")" && pwd)/runner-job-guard.sh"

run() {
  env -i PATH="$PATH" "$@" bash "$guard" >/dev/null 2>&1
}
good=(GITHUB_REPOSITORY=cmdr-nova/VAAK GITHUB_EVENT_NAME=workflow_dispatch GITHUB_REF=refs/heads/main
  GITHUB_WORKFLOW_REF=cmdr-nova/VAAK/.github/workflows/deploy-production.yml@refs/heads/main
  GITHUB_TRIGGERING_ACTOR=cmdr-nova)

run "${good[@]}" || { echo "manual deploy was refused" >&2; exit 1; }

bad() {
  if run "$@"; then echo "accepted: $*" >&2; exit 1; fi
}
bad "${good[@]}" GITHUB_EVENT_NAME=pull_request
bad "${good[@]}" GITHUB_EVENT_NAME=pull_request_target
bad "${good[@]}" GITHUB_REF=refs/pull/1/merge
bad "${good[@]}" GITHUB_REPOSITORY=someone/VAAK
bad "${good[@]}" GITHUB_WORKFLOW_REF=cmdr-nova/VAAK/.github/workflows/ci.yml@refs/heads/main
bad "${good[@]}" GITHUB_WORKFLOW_REF=cmdr-nova/VAAK/.github/workflows/deploy-production.yml@refs/heads/other
bad "${good[@]}" GITHUB_TRIGGERING_ACTOR=someone-else
bad GITHUB_REPOSITORY=cmdr-nova/VAAK
echo "runner_job_guard_test_ok"
