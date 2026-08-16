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

**AT-357 — deploy.sh queue-worker restart targets the wrong environment.** Confirmed via
real `supervisorctl status`: one shared supervisord manages `corex-worker-live` (×2),
`corex-worker-live-mail`, `corex-worker-live-matching`, AND `corex-worker-staging` — all
visible from both `/corex` and `/corex-staging`. Both copies of `deploy.sh` (line 474) use
the same over-broad match:
```
awk '/^(hfc-queue|corex-worker)/ {print $1}' | cut -d: -f1 | sort -u | head -1
```
Two real consequences: (1) every live deploy only ever restarts `corex-worker-live` — the
mail and matching pools silently keep running old code; (2) a **staging** deploy running
this same script would restart `corex-worker-live` instead of `corex-worker-staging`,
disrupting live's background jobs from a staging action.

**Proposed fix, ready for a one-line sign-off — NOT applied, this is deploy-script/prod-config territory:**

`/corex/scripts/deploy.sh` (and mirror the equivalent for `/corex-staging/scripts/deploy.sh`,
swapping `-live` for `-staging`):
```bash
# Match ONLY this environment's pool(s) — never another environment's — and
# restart every matching pool, not just the alphabetically-first one.
SUPER_PROGS=$(sudo supervisorctl status 2>/dev/null \
    | awk '/^corex-worker-live($|-)/ {print $1}' \
    | cut -d: -f1 | sort -u)
if [[ -n "$SUPER_PROGS" ]]; then
    while IFS= read -r prog; do
        sudo supervisorctl restart "${prog}:*" | tee -a "$LOG_FILE"
    done <<< "$SUPER_PROGS"
    WORKER_MECHANISM="supervisord programs: $(echo "$SUPER_PROGS" | tr '\n' ' ')"
fi
```
(`/corex-staging/scripts/deploy.sh` — same, with `^corex-worker-staging($|-)` and no loop
needed today since staging only has one pool, but the loop form is harmless there too and
keeps both scripts structurally identical.)

## Note on this document's existence

No corex-cc-bridge MCP tool (`cc_send`/`cc_type`) was available in this session to notify
cc2 directly, and per the standing box-wide rule, raw pane-driving (tmux send-keys into a
sibling pane) is never the right tool regardless. This doc + the two pushed branches +
their commit messages are the durable, readable record — Johan or the conductor can relay
via the sanctioned channel, or cc2 can pull straight from these branches.
