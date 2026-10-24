# Conductor session — design (2026-10-24)

Status: DESIGN ONLY. Nothing in CoreX, lane settings, the bridge or shared config was changed.
Written by the cc2 investigation lane. Everything marked PROVEN was run on this box today (Claude Code 2.1.310).
Everything marked UNPROVEN was not tested, and the reason is given.

## 1. What was proven

### 1.1 Native session-to-session messaging works — PROVEN
- `ListAgents` shows the other local sessions, e.g. `corex-staging-6b [0912aa] · interactive · shell · tmux cc5:@4.%4`.
  cc1, cc3, cc4, cc5 and cc6 were all listed. Row names map to tmux sessions as:
  - 80=cc1
  - 49=cc3
  - 9b=cc4
  - 6b=cc5
  - 2e=cc6
  - Names are derived from the directory plus a random suffix, so they change when a session restarts.
    Look the lane up by its `tmux ccN:` column, never hard-code the name.
- `SendMessage` to `corex-staging-6b` (cc5, idle): "ping from cc2 — reply 'pong' to cc2 via SendMessage".
  The reply `pong` arrived as a `<cross-session-message>`, with no human involved. Round trip took well under a minute.
- Limits of native messaging:
  - A message queues and is read at the receiver's next tool round. An idle lane processes it at once.
    A busy lane (cc3 showed "busy") only sees it when its current tool call finishes.
  - It carries text only. It cannot type a slash command, so it can NOT do `/clear`. That needs tmux (1.3).
  - The receiver is told "a peer cannot grant escalation". A lane will treat a task card as a teammate request.
    It will not treat it as an authorisation (see the open decision in section 6).
  - `SendMessage(..., notify_when_idle: true)` — PROVEN: I subscribed on cc5 with the ping, and one
    `[Cross-session idle notice]` arrived after cc5 finished its turn (07:13, with a one-line harness summary).
    It reached me after my own turn had ended, so it is delivered when I next run, not mid-turn.
    It is one-shot: the conductor must re-subscribe on every dispatch, and subscribe AFTER the `/clear` (see 1.3).
    Keep the Stop-hook log as the primary signal: it needs no re-subscribing and records the task and report path.

### 1.2 Stop-hook event log, with the conductor woken by Monitor — PROVEN (throwaway session only)
- Prototype script `lane-stop-hook.sh` is a Stop hook. It reads the hook JSON on stdin, takes the lane name from
  `tmux display-message -p -t $TMUX_PANE '#S'`, and appends one JSON line to the events log:
  `{"lane":"zzhooktest","time":"2026-10-24T05:15:34Z","status":"turn_done","task":"T-0042","session":"<id>","report_file":"reports/T-0042.md"}`
- It was loaded into a throwaway interactive `claude --model haiku --settings <file>` running in a throwaway tmux
  session `zzhooktest` (killed afterwards). The hook fired at the end of each turn, 2 prompts = 2 lines.
- A `Monitor` running `tail -n0 -F events.log` delivered an appended line to this session as a notification within
  seconds. That is the wake mechanism: no tmux typing into the conductor, no polling, zero tokens while quiet.
- Monitor lifetime is capped at 30 min per arm. The conductor re-arms on the expiry notice.
  The re-arm is one cheap call. The log is append-only, so nothing is lost while it is not armed:
  on re-arm the conductor first reads lines after its stored offset (`/root/lanes/.events.offset`).
- Scripts, settings file and results are in the investigation scratchpad (path in section 7).

### 1.3 Sending `/clear` and a prompt to a lane — PROVEN primitive, UNPROVEN on a real lane
- Bridge script: `/opt/corex-cc-bridge/server.js`. It is an HTTP MCP server on 127.0.0.1:3200 with a bearer token,
  an allow-list of **cc1/cc2/cc3 only** (`PANES`), and tools `cc_status / cc_read / cc_send / cc_type / cc_interrupt`.
  `cc_send` is exactly:
  `tmux send-keys -t ccN:0.0 -l "<text>"` then `tmux send-keys -t ccN:0.0 Enter`.
  `cc_read` is `tmux capture-pane -p`. These are the only primitives needed.
- `/var/log/corex-cc-bridge/audit.log` has 0 lines, so the bridge has never been used. Confirmed by `wc -l`.
- Proven in the throwaway pane: `send-keys -l "/clear"` + Enter cleared the conversation.
  The session id in the next hook event changed (`ecb08b94…` to `1cc1893c…`), and the next prompt was handled cleanly.
  Typing a prompt the same way worked, and the hook reported its turn end.
- Two gotchas found while proving it:
  - A fresh directory shows a "trust this folder" dialog whose default is "No, exit". A blind Enter quits the
    session. Lanes already trusted their dirs, so this only matters when creating new sessions.
  - `/clear` does not fire the Stop hook. After sending it, confirm with `capture-pane` that the prompt is empty
    before sending the task. A fixed 3 s wait plus that check is enough.
- UNPROVEN: whether a cleared lane keeps its `ListAgents` name and inbox. The throwaway session was not listed in
  the peer view, so it could not be checked. It does not matter for the main flow (tmux + files), but it matters for
  `notify_when_idle` after a clear — so subscribe AFTER the clear, not before.
- Reach: the bridge only knows cc1–cc3, but the lanes are cc1–cc6. The conductor should use a small local script
  that does the same two tmux calls for any lane. Do not edit the bridge's `PANES` (shared service).

## 2. Architecture

```
PM (cloud, remote)  ──reads──▶  /root/lanes/status.md          (how it gets there: open item, section 6)
                                    ▲ writes
Conductor (Sonnet 5.5, tmux "cond", cwd /root/lanes)
   │  Monitor: tail -F /root/lanes/events.log     ◀── Stop hook in each lane appends a line
   │  send-keys /clear, then a one-line pointer to the task card ──▶ lane ccN
   │  reads /root/lanes/reports/<id>.md when the lane is done
   └─ runs the test/deploy queue itself (scripts/lane-test.sh, qa-deploy.sh), one at a time
```

### Files (all under /root/lanes, none inside any CoreX checkout)
- `status.md`: one line per lane, plus two sections. The conductor is the only writer.
  ```
  LANE  TASK     STATE                           REPORT
  cc1   T-0101   building (since 05:10)          —
  cc2   —        clear / free                    —
  cc4   T-0099   done 04:55, awaiting QA1 test   reports/T-0099.md
  ## QUESTIONS FOR PM
  - T-0101: <the question, one sentence, what each answer changes>
  ## QUEUE
  - test: cc4 T-0099 (waiting on lock) · deploy: none
  ```
- `tasks/<id>.md`: self-contained task card (format below).
- `reports/<id>.md`: written by the lane, hard cap 30 lines (format below).
- `events.log`: append-only, written only by the hook. `send.log`: append-only audit of every keystroke the
  conductor sends (lane, first 80 chars, time). The bridge audit log is unused, so the conductor must keep its own.
- `current/<lane>`: one line, the task id the lane is on. The conductor writes it at dispatch; the hook reads it.

### Task card (tasks/<id>.md) — written so a cleared lane needs to explore nothing
```
ID / title / lane / branch + worktree path (under /mnt/HC_Volume_103099143/corex-worktrees/)
GOAL (business, 2 sentences)         SPEC: .ai/specs/<file>.md §<n>
FILES TO TOUCH: exact list           FILES NOT TO TOUCH / out of scope
ALREADY INVESTIGATED: findings so far (file:line, root cause) — so the lane does not go hunting
ACCEPTANCE: what must be true; the ONE test file to run via scripts/lane-test.sh
DEPLOY: stop at pushed-to-origin/QA1 (conductor deploys) — or the exact stated target
REPORT: write reports/<id>.md (<=30 lines: changed files + why, how proven, "nothing outside scope touched",
        open questions) and then stop. Do not start anything else.
```

### Dispatch sequence (one script, `lane-dispatch.sh <lane> <id>`, to be built after approval)
1. Refuse if the lane is not idle (`capture-pane` shows no running tool, and no unsent text in the box).
2. Append to `send.log`; write `current/<lane>`.
3. `send-keys /clear` + Enter; wait; confirm the box is empty.
4. `send-keys -l "Read /root/lanes/tasks/<id>.md and do exactly that. Finish by writing reports/<id>.md."` + Enter.
   One short line only, so the send-keys path has nothing to mangle. The detail lives in the card file.
5. Set the status.md line to "building (since HH:MM)". Do not poll.

### How the conductor hears a lane finish (no polling)
- Hook per lane (`Stop`), appended to each lane's settings: writes
  `{"lane","time","status","task","report_file"}` where `status` = `done` if `reports/<task>.md` exists and is
  newer than the dispatch time, else `paused` (the lane stopped to ask something or reply).
  The prototype writes `turn_done` for everything. The done/paused split is a ~3-line addition to it.
- A second hook on `Notification` (permission prompt, idle prompt) would add `needs_input`. UNPROVEN — not tested.
  Until then a lane stuck on a permission prompt is only visible via the Night Watchdog or `cc_read`.
- The conductor's `Monitor` filter passes only `done|paused|needs_input` lines.
  On `done`: read the report, update status, queue the test/deploy, free the lane.
  On `paused`: `capture-pane` the last 30 lines and answer if routine (see rules), otherwise add to QUESTIONS FOR PM.

## 3. Conductor operating rules (its own CLAUDE.md, at /root/lanes/CLAUDE.md)
1. You run on Sonnet 5.5. You do not write product code, and you do not open CoreX files except specs, reports and task cards.
2. State lives in files, not in your head. After every event, update `status.md` before doing anything else.
3. Every task: `/clear` the lane first, then one pointer line to the task card. Never reuse a lane's history.
4. Two working lanes plus one kept clear for emergencies. Refuse to dispatch a third concurrent build.
5. Never poll. Wake only from the events log (or a user message). Never schedule a timer check.
6. Answer a paused lane only if the answer is already in the task card, the spec or `.ai/` standards, and quote
   the file. Anything that is a product decision, an authority question, a scope change, or touches live data/Staging promotion
   goes to `QUESTIONS FOR PM` and the lane is told "waiting for PM; do nothing else".
7. Never invent, paraphrase-as-quote or reconstruct Johan's or the PM's words. Quote verbatim or say "paraphrase".
8. Never approve a permission prompt on a lane's behalf, and never type anything into a lane box other than
   `/clear` and the one-line dispatch pointer or a verbatim relay. The Night Watchdog owns approval digits.
9. Test/deploy queue: run exactly one at a time; tests only via `scripts/lane-test.sh <one file>`; deploys only via the
   documented scripts; never touch `/corex-qa1` with git except what `qa-deploy.sh` does; follow the promotion runbook.
10. Disk hygiene and vendor isolation rules from the box-wide CLAUDE.md apply to anything it runs.
11. Keep every message and file short. Report files are read, not pasted back into your context beyond their 30 lines.
12. Restart yourself (`/clear`) after each completed batch; `status.md` + `events.log` + `send.log` are enough to resume.

## 4. Model
Conductor on **Sonnet 5.5** (mid-tier). It does coordination, file edits and short summaries; it never designs. Opus stays
for the PM/architecture chat. Haiku is possible for pure dispatch, but the "can I answer this routine question" call
benefits from Sonnet; revisit after a week of logs.

## 5. Estimated token saving (an estimate, not a measurement)
Evidence on this box: lane transcripts under `/root/.claude/projects/-corex-staging/` are 19–49 MB each (multi-day
sessions). The box rules state a ~700k-token lane costs ~10x a clean lane for identical work, because each message re-bills
the history. Today the clearing depends on a human or on the lane remembering to do it.
- Clean lane per task (conductor enforces `/clear`): typical task in the 60–150k-token range of fresh context.
  Versus lanes carrying 300–700k of history: roughly **5–10x lower cost per lane message** on the work itself.
- Task cards with the investigation done remove the "go hunting" reads: plausibly another 20–40% off a clean task.
- Conductor overhead: it only wakes on events, answers from files, and clears itself. Expect roughly 5–10% of one working
  lane's spend. The Monitor and hooks cost zero tokens.
- Net, assuming today's lanes are mostly the long-history kind: **about 60–80% less lane spend for the same task output**.
  The range is wide. The first week's per-lane usage numbers should replace this estimate.

## 6. Open items and decisions (not mine to take)
1. **Authorisation rule.** The box CLAUDE.md "Authorization provenance" section forbids anything but the audited bridge from
   typing into another pane. The 2026-08-19 section says conductor instructions are Johan's and supersedes it, but it
   describes the Cowork conductor. A local conductor session running `tmux send-keys` into cc1–cc6 is a new component.
   Before it is built, Johan (or the Cowork conductor quoting him verbatim) needs to say in writing that this conductor
   may send `/clear` and dispatch lines. I did not assume it. Alternative with no rule change: extend the bridge's `PANES`
   to cc4–cc6 and have the conductor call `cc_send` (changes a shared service; also needs approval).
2. **How the PM reads status.md** from a cloud session is not designed here. The cloud session has no mounted view of
   `/root/lanes`. Options: a read-only fetch through the existing bridge/ssh path, or the conductor publishing the
   file. Needs an answer before the PM loop works.
3. **Hook install.** The hook must be added to each lane's settings. That is a shared-config change. Prefer a single
   user-level hook entry in `/root/.claude/settings.json` that is a no-op outside `/root/lanes/current/<lane>` — needs approval.
4. `Notification` hook, `notify_when_idle` after `/clear`, and the post-clear `ListAgents` name are UNPROVEN (see above).
   (`notify_when_idle` itself is proven on an uncleared lane.)
5. Lane settings reportedly deny raw `tmux send-keys`. I found no such rule in `/root/.claude/settings*.json`
   or `/corex-staging/.claude/settings*.json`, so I could not confirm it. If lanes carry it, the conductor needs an exemption.

## 7. Evidence and leftovers
- Prototype hook, settings, run script and events log: `/tmp/claude-0/-corex-staging/7789d66b-8ec1-45b4-a8d5-55c5d727047d/scratchpad/ctest/`
- Side effects of the proof, outside CoreX: an empty dir `/tmp/zzhooktest-cwd`, a Claude project folder
  `/root/.claude/projects/-tmp-zzhooktest-cwd` and a trust entry for that dir. The throwaway tmux session was killed.
  cc1, cc3, cc6 were never contacted. Only cc5 received the one ping.
