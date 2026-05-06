#!/usr/bin/env bash
#
# Install the agent-eval pre-push hook on Unix-like systems.
#
# Copies `scripts/hooks/pre-push` to `.git/hooks/pre-push` and makes it
# executable. Non-destructive: if a `pre-push` hook is already installed,
# it is backed up to `pre-push.bak.<unix-timestamp>` before being replaced.
#
# Usage:
#     ./scripts/install-eval-hook.sh
#
# To bypass the hook for a single push, run:
#     SKIP_EVAL_HOOK=1 git push
#
# See scripts/hooks/pre-push for full documentation.

set -euo pipefail

repo_root="$(git rev-parse --show-toplevel 2>/dev/null || true)"
if [[ -z "${repo_root}" ]]; then
    echo "error: must be run from inside the openemr git working tree." >&2
    exit 1
fi

source_hook="${repo_root}/scripts/hooks/pre-push"
hooks_dir="${repo_root}/.git/hooks"
target_hook="${hooks_dir}/pre-push"

if [[ ! -f "${source_hook}" ]]; then
    echo "error: source hook not found at ${source_hook}." >&2
    exit 1
fi

mkdir -p "${hooks_dir}"

if [[ -e "${target_hook}" || -L "${target_hook}" ]]; then
    timestamp="$(date +%s)"
    backup_path="${target_hook}.bak.${timestamp}"
    echo "warning: an existing pre-push hook was found at ${target_hook}." >&2
    echo "         backing it up to ${backup_path} and overwriting." >&2
    mv -- "${target_hook}" "${backup_path}"
fi

cp -- "${source_hook}" "${target_hook}"
chmod +x "${target_hook}"

echo "Installed agent-eval pre-push hook at ${target_hook}."
echo "Bypass once with: SKIP_EVAL_HOOK=1 git push"
