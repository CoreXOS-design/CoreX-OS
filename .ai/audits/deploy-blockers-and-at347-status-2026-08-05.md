# AT-347 + deploy-pipeline cluster — status for cc2 / Johan

Written so this is readable without pane-to-pane messaging (no cc-bridge tool available
in this session — see note at bottom). Two fix branches ready to fold into cc2's live
promotion. **Live promotion is HELD — not executed, not authorised by me.**

## AT-347 — ready to fold in

- **Branch:** `fix/AT-347-main-blade-comment-hardening`
- **Commit:** `4e0081f6`
- **Pushed:** yes, to `origin` (`github.com/CoreXOS-design/CoreX-OS`)
- **Verdict: pure insurance, not a live fix.** Traced Laravel's own `BladeCompiler::compileString()`
  source — `compileComments()` runs as a complete pass, stripping every `{{-- --}}` block
  from the whole string, *before* the `@directive` tokenizer ever runs. Confirmed live and
  QA1 both run Laravel 12.51.0. Empirically confirmed on all 3 relevant PHP binaries —
  8.4 (this box's CLI default), **8.3.31 (live's actual FPM pool** — verified via
  `/etc/nginx/sites-enabled/corexos.co.za`, the real live vhost; `corex.hfcoastal.co.za` is
  a redirect stub, not the app), and 8.2 (QA1's pool). A synthetic repro (prose `@if(...)`
  in a comment, next to a real unrelated `@if/@endif`) survives intact on all three — no
  directive stolen, no content silently dropped. Also rendered main's actual, currently
  *unfixed* `_match-form.blade.php` via a real `view()->render()` call with live data on
  QA1 — succeeded, byte-identical output length to the fixed version.
  **The contacts-500 does not currently reproduce against live's real stack.** Shipping the
  hardening anyway because cc3 had already built + reviewed it (zero behaviour change,
  24/25 files byte-identical, the 25th only differs inside dead code) — cheap, zero-risk
  insurance regardless of whether the original report is still current.

## AT-310 / AT-356 — ready to fold in

- **Branch:** `fix/AT-310-AT-356-sync-permissions-merge-defaults`
- **Commit:** `b4f057dd`
- **Pushed:** yes
- Real fix (not insurance) — `corex:sync-permissions --merge-defaults` 1062-aborts on a
  soft-deleted permission grant occupying the unique index slot. Already built + proven on
  QA1 (cc5, 2026-07-31), never reached `main`. Reproduced fresh today on **agency 1's admin
  role** (the exact scenario named in the ticket) in a rolled-back transaction: plain insert
  throws 1062 as expected; the fixed `insertOrIgnore` path affects 0 rows, no exception.

## HELD — not part of tonight's fold-in, need Johan's call first

**AT-359 — `/etc/hfc-deploy.env` stuck in local-backup mode.** Root cause: `deploy.sh:209-210`
correctly and deliberately hard-fails any production deploy when `BACKUP_MODE=local` — a
working safety gate, not a bug. The file is genuinely missing real Hetzner Storage Box
credentials. Not touched — I don't have real credentials to provision, and this gates
production data-safety. **Needs Johan** to either provision a real Storage Box and fill in
`/etc/hfc-deploy.env`, or make an explicit, informed call to accept local-only backup for
tonight.

**AT-357 — FIXED and committed, both environments.** Confirmed via real `supervisorctl
status`: one shared supervisord manages `corex-worker-live` (×2), `corex-worker-live-mail`,
`corex-worker-live-matching`, AND `corex-worker-staging` — all visible from both `/corex`
and `/corex-staging`. Both copies of `deploy.sh` used the same over-broad match:
```
awk '/^(hfc-queue|corex-worker)/ {print $1}' | cut -d: -f1 | sort -u | head -1
```
Two real consequences: (1) every live deploy only ever restarted `corex-worker-live` — the
mail and matching pools silently kept running old code; (2) a **staging** deploy running
this same script would restart `corex-worker-live` instead of `corex-worker-staging`,
disrupting live's background jobs from a staging action.

Applied and committed (per Johan's explicit go-ahead — no sign-off needed, "just fix
these"):
- `/corex/scripts/deploy.sh` (live) — commit `11ccea05` on `main`, pushed.
- `/corex-staging/scripts/deploy.sh` (staging) — commit `97132e49` on `Staging`, pushed.

Both now match only their own environment's pool(s) and loop over every match instead of
`head -1`. First implementation attempt used `awk '/^corex-worker-live($|-)/'`, which
dry-run testing against real `supervisorctl status` output caught as wrong — the character
immediately after the pool name in that output is `:` (the `program:process_name`
separator), not `-` or end-of-line, so the bare `corex-worker-live` program was silently
excluded and the staging pattern matched nothing at all. Corrected to
`awk '/^corex-worker-live[:-]/'` (and `[:-]` staging-side), re-verified by dry-run (no
restart executed) to match exactly:
- live: `corex-worker-live`, `corex-worker-live-mail`, `corex-worker-live-matching`
- staging: `corex-worker-staging`
- zero cross-environment overlap either direction

This unblocks cc2's live deploy — the worker-restart step will now correctly cycle all
three live pools on a live deploy, and will never again touch a live pool from a staging
run.

## Note on this document's existence

No corex-cc-bridge MCP tool (`cc_send`/`cc_type`) was available in this session to notify
cc2 directly, and per the standing box-wide rule, raw pane-driving (tmux send-keys into a
sibling pane) is never the right tool regardless. This doc + the two pushed branches +
their commit messages are the durable, readable record — Johan or the conductor can relay
via the sanctioned channel, or cc2 can pull straight from these branches.
