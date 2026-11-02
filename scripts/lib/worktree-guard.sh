#!/usr/bin/env bash
#
# worktree-guard.sh — source this at the top of any lane script that writes .env, runs composer or key:generate.
#
#   source /corex-qa1/scripts/lib/worktree-guard.sh
#   corex_require_worktree "/mnt/HC_Volume_103099143/corex-worktrees/<branch>" || exit 1
#
# WHY (2026-11-02 05:47): a lane's worktree-setup script hit a failed `cd`, carried on in /corex-qa1 and ran
# `cp .env.example .env`, `key:generate` and `composer install` there. QA1's .env became the blank template (sqlite),
# every QA1 page returned 500, and the only .env backup on the box was seven weeks old.
#
# It changes into the given directory (and fails if it cannot), then refuses to go on when the directory is a
# deploy checkout: /corex, /corex-qa1, /corex-qa2, /corex-staging (or the data-volume path any of them is mounted from),
# or any directory whose .git is a folder rather than the file a linked worktree has.

corex_require_worktree() {
    local target="${1:-}"
    if [ -z "$target" ]; then
        echo "worktree-guard: no worktree path given - refusing." >&2
        return 1
    fi
    cd "$target" || { echo "worktree-guard: cannot cd to '$target' - refusing to carry on in $(pwd)." >&2; return 1; }

    local here dir
    here="$(pwd -P)"
    for dir in /corex /corex-qa1 /corex-qa2 /corex-staging; do
        if [ "$here" = "$dir" ] || [ "$here" = "$(readlink -f "$dir" 2>/dev/null)" ]; then
            echo "worktree-guard: $here is a deploy checkout ($dir) - refusing to touch .env or vendor here." >&2
            return 1
        fi
    done
    if [ ! -f .git ]; then
        echo "worktree-guard: $here is not a linked worktree (.git is not a file) - refusing." >&2
        return 1
    fi

    return 0
}
