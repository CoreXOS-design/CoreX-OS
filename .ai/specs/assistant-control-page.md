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

| Phase | Scope | Status |
|---|---|---|
| **1** | Migration: settings columns on `assistant_assignments`. `AssistantAssignment` casts + defaults. | ✅ shipped `1d69ba4f` |
| **2** | Control-page behaviour panel (blade + saver) — the toggles, auto-save like the matrix. Per-assignment, so NOT a Setup Wizard item (non-negotiable #10a does not apply — confirmed). | ✅ shipped `1d69ba4f` |
| **3** | Ownership routing for the remaining create surfaces. One test each. | ✅ shipped `d8f0b68a`; the last two create sites (e-sign wizard, viewing packs) closed by the 2026-07-26 audit (F3) |
| **4** | `can_manage_my_records` gate on edit/delete + the `dataIdentityIds` visibility sweep. | ✅ shipped 2026-07-26 (audit F1) |
| **5** | Attribution partial (item 3) + drop-in on record headers. | ✅ shipped 2026-07-26 (audit F1) |
| **6** | `AssistantActedOnBehalf` notification (item 4) + the daily-activity actor column stamp (item 5). | ⚠️ notification shipped 2026-07-26 (audit F1) as the `assistant.acted_on_behalf` catalogue row fired from `LogAssistantActivity`. **The `daily_activity_entries.on_behalf_of_user_id` column is still outstanding** — it needs a migration + `schema:dump` + a demo/live migrate, so it is scheduled work, not an audit fix. |

> ⚠️ **The lesson from phases 4–6, worth keeping.** Phases 1–3 shipped and 4–6 did not — but the
> Phase-2 UI advertising all of them shipped anyway. For five days the control page told agents
> that `can_manage_my_records` restricted their assistant's edit and delete rights, and it
> restricted nothing. Found by the 2026-07-26 post-ship audit
> (`.ai/audits/2026-07-26-assistant-feature-postship-audit.md` F1); enforcement shipped the same
> day. **Never ship the switch ahead of the thing it switches** — a control that does nothing is
> worse than an absent one, because it stops the user looking for the real one. This is the same
> rule `NotificationEventTypeSeeder` states for the retired notification toggles.

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
