#!/usr/bin/env bash
# Job-started hook for the production self-hosted runner (labels self-hosted, vaak-prod).
#
# The repository is public, so a pull request can edit a workflow to target this
# runner. This hook runs before every job the runner picks up and fails the job
# unless it is the manual production deploy on main of cmdr-nova/VAAK.
#
# Install on the production host (as root), then restart the runner service:
#   install -m 0755 -o root -g root deploy/runner-job-guard.sh /usr/local/sbin/vaak-runner-job-guard.sh
#   echo 'ACTIONS_RUNNER_HOOK_JOB_STARTED=/usr/local/sbin/vaak-runner-job-guard.sh' >> <runner dir>/.env
#
# The runner only accepts hook paths ending in .sh, .ps1 or .js. Keep it root-owned so the vaak-deploy user that runs jobs cannot rewrite it.
set -euo pipefail

allowed_repo="cmdr-nova/VAAK"
allowed_workflow="${allowed_repo}/.github/workflows/deploy-production.yml@refs/heads/main"
allowed_actors="${VAAK_RUNNER_ALLOWED_ACTORS:-cmdr-nova}"

refuse() {
  echo "vaak-runner-job-guard: refusing job: $1" >&2
  exit 1
}

[[ "${GITHUB_REPOSITORY:-}" == "${allowed_repo}" ]] || refuse "repository ${GITHUB_REPOSITORY:-unset}"
[[ "${GITHUB_EVENT_NAME:-}" == "workflow_dispatch" ]] || refuse "event ${GITHUB_EVENT_NAME:-unset}"
[[ "${GITHUB_REF:-}" == "refs/heads/main" ]] || refuse "ref ${GITHUB_REF:-unset}"
[[ "${GITHUB_WORKFLOW_REF:-}" == "${allowed_workflow}" ]] || refuse "workflow ${GITHUB_WORKFLOW_REF:-unset}"

actor="${GITHUB_TRIGGERING_ACTOR:-${GITHUB_ACTOR:-}}"
ok=0
for a in ${allowed_actors}; do
  [[ "${actor}" == "${a}" ]] && ok=1
done
[[ "${ok}" == "1" ]] || refuse "actor ${actor:-unset}"

echo "vaak-runner-job-guard: ok ${GITHUB_WORKFLOW_REF} by ${actor}"
