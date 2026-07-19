# Assistant Control Page V2 + acts-as-agent completion — design & build plan

> Status: **DESIGN — awaiting Johan's go-ahead before build.** Extends
> `.ai/specs/assistants-feature-spec.md`. Author: 2026-07-19.
> Driver: Johan — "the assistant control page should look and function like the role manager but
> for the individual assistant," with the acts-as-agent capabilities + attribution + notifications
> all as on/off toggles the agent controls, same as the feature switchboard.

## What exists today

- `Agent\AssistantMatrixController` + `resources/views/agent/assistants/matrix.blade.php` — the
  agent's own control page. Already a role-manager-style, grouped, auto-saving permission matrix
  (checkbox per capability + scope selector for `.view` keys, locked rows explained, NEW badges).
- Ownership routing DONE for **calendar events** + **daily activity** (assistant's entries land on
  the agent; verified). Everything else an assistant creates is still owned by the assistant.
- `assistant_assignments` has status/audit columns but **no per-assignment behaviour settings**.

## The two layers of the page

1. **Behaviour panel (NEW)** — a plain-English "How {assistant} works for you" card at the top,
   styled like the feature switchboard: a few master toggles, per-assignment, stored on
   `assistant_assignments`.
2. **Capability matrix (EXISTS)** — the detailed per-permission grid below, unchanged in shape.
   The behaviour toggles gate the *behaviour*; the matrix gates *which modules* the assistant may
   touch. A capability is live only when both agree (matrix grants the module AND the behaviour
   toggle is on).

## New per-assignment settings (columns on `assistant_assignments`, all default the safe way)

| Column | Default | Toggle label on the page | Effect |
|---|---|---|---|
| `acts_as_agent` | true | "Everything {a} does is filed as mine" | Records {a} creates (calendar, daily, contacts, deals, tasks, notes, presentations, viewing packs) are OWNED BY the agent (`ownershipUserId()`), so they show on the agent's book as the agent's. Off ⇒ {a}'s creations stay theirs (rare; for a purely-personal assistant). |
| `can_manage_my_records` | true | "{a} can edit & delete my records, not just add" | Gates whether {a}'s edit/delete of the agent's records is allowed (per-record auth resolves through `dataIdentityIds()`). Off ⇒ {a} can add + view but not modify the agent's existing records. |
| `show_attribution` | true | "Show \"added by {a}\" on things they do" | The agent's calendar/activity/records show a small "added by {a}" tag (from `created_by` + the on-behalf trail). |
| `notify_on_action` | true | "Notify me when {a} adds or changes something" | The agent gets an in-app notification when {a} creates/edits on their behalf. |

(The per-MODULE "can add contacts / deals / calendar…" control is the existing matrix checkbox —
we keep it there rather than duplicate it, and label the matrix sections in the plain language the
switchboard uses. Item 1's "toggle to turn on/off" per surface IS that checkbox.)

## Behaviour wiring

- **Ownership routing (item 1)** — extend the calendar/daily pattern to every create surface an
  assistant can reach, gated by `acts_as_agent`: Contact (`agent_id`), DealV2
  (`listing_agent_id`), CommandTask, notes, Presentation, ViewingPack, offers. Each: owner ←
  `ownershipUserId()` when `acts_as_agent`; actor stays the assistant. One create-path edit + one
  test per surface (mirrors `AssistantActsForAgentTest`).
- **Edit/delete visibility (item 2)** — every per-record authorize/`isVisibleTo` on those models
  resolves `own` through `dataIdentityIds()` (ViewingPack already fixed; audit the rest via
  `AssistantVisibilityCoverageTest`, which already enumerates them), gated by `can_manage_my_records`.
- **Attribution (item 3)** — a small blade partial `x-assistant-attribution` that, given a record
  with `created_by`/`on_behalf_of`, renders "added by {a}" when `show_attribution` is on. Dropped
  into the calendar event card + daily activity + record headers.
- **Notifications (item 4)** — a `AssistantActedOnBehalf` notification to the agent, fired from a
  single chokepoint (a small service the create paths call), respecting `notify_on_action`.
- **Actor column (item 5)** — `daily_activity_entries` gains `on_behalf_of_user_id` (+ the
  `StampsOnBehalfOf`-style stamp at the raw-insert site), so the audit names the assistant behind
  each daily number, not just the owning agent.

## Build phases (each ends green on `tests/Feature/Assistants/*`)

| Phase | Scope |
|---|---|
| **1** | Migration: 4 settings columns on `assistant_assignments` + `daily_activity_entries.on_behalf_of_user_id`. `AssistantAssignment` casts + defaults. |
| **2** | Control-page behaviour panel (blade + saver) — the 4 toggles, auto-save like the matrix. Wizard/settings parity per non-negotiable #10a if these count as agency settings (they are per-assignment, so likely not wizard — confirm). |
| **3** | Ownership routing for the remaining create surfaces, gated by `acts_as_agent`. One test each. |
| **4** | `can_manage_my_records` gate on edit/delete + the `dataIdentityIds` visibility sweep. |
| **5** | Attribution partial (item 3) + drop-in on calendar/daily/record headers. |
| **6** | `AssistantActedOnBehalf` notification (item 4) + the daily-activity actor column stamp (item 5). |

## Decisions (Johan, 2026-07-19) — LOCKED

1. **Layout:** master behaviour panel (plain toggles) ON TOP of the existing per-module matrix.
   Not per-surface toggles.
2. **Ownership is ALWAYS the agent** — not a toggle. An assistant's work always files as the
   agent's; there is no state where it stays the assistant's. So the panel shows an always-on
   INFO line ("Everything {a} does is automatically filed as yours") and does NOT gate ownership.
   `acts_as_agent` column is therefore dropped — ownership routing is unconditional for assistants.

**Resulting behaviour panel — 3 real toggles + 1 info line:**

| Setting | Type | Default |
|---|---|---|
| "Everything {a} does is filed as yours" | info (always on) | — |
| `can_manage_my_records` — "{a} can edit & delete my records, not just add" | toggle | ON |
| `show_attribution` — "Show \"added by {a}\" on things they do" | toggle | ON |
| `notify_on_action` — "Notify me when {a} adds or changes something" | toggle | OFF (quieter default) |

Phase 1 migration therefore adds **3** columns to `assistant_assignments`
(`can_manage_my_records`, `show_attribution`, `notify_on_action`) + `on_behalf_of_user_id` on
`daily_activity_entries`. Phase 3 ownership routing is unconditional (no `acts_as_agent` gate).
