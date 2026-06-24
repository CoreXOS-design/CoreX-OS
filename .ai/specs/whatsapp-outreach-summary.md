# WhatsApp Outreach Summary Board — Module Spec

> Status: Approved by Johan (build prompt 2026-06-24) — Jira AT-91
> Owner: Johan / Claude
> Pillars: **Contact** (the population + consent state) + **Agent** (`User`, the responsible-agent rows)
> Depends on: `.ai/specs/seller-outreach-spec.md` (the send pipeline + AT-81 5-state consent doctrine)
> Branch: `AT-whatsapp-outreach-summary` (off Staging)

---

## Section 1 — Purpose & Context

**What this does.** A single read-only board: a table of **agents (rows) × outreach states (4 columns)** with a contact count per cell. Each non-zero count links straight into the filtered contacts list for that agent + that state, scoped to WhatsApp outreach. It answers, at a glance: *for each of my agents, how many WhatsApp-pitched sellers are still awaiting a reply, have confirmed, have lapsed silently, or explicitly opted out.*

**Why it matters.** Agents send WhatsApp pitches one-at-a-time (seller-outreach module). Until now there has been no roll-up of *where those conversations stand* per agent. A manager could not see "agent X has 40 pending consent-requests and only 3 confirmations" without opening contacts one by one. This board is the accountability surface for the outreach pipeline.

**Pillar linkage.** Reads the **Contact** pillar (consent timestamps already maintained by the AT-81 doctrine) and the **Agent** pillar (`contacts.agent_id` → the operational responsible agent). Writes nothing — it is a derived view. No new table, no migration.

---

## Section 2 — Population (decided)

The board counts **contacts that have ≥1 `seller_outreach_sends` row with `channel = 'whatsapp'`** (and that send not soft-deleted, same agency). A contact with no WhatsApp send never appears — so genuinely-never-contacted (INITIAL) contacts are auto-excluded by construction.

Expressed as a `whereExists` correlated subquery (uses the `outreach_send_contact_idx (agency_id, contact_id, sent_at)` index):

```sql
EXISTS (
  SELECT 1 FROM seller_outreach_sends s
  WHERE s.contact_id = contacts.id
    AND s.agency_id  = contacts.agency_id
    AND s.channel    = 'whatsapp'
    AND s.deleted_at IS NULL
)
```

---

## Section 3 — Columns (the 4 outreach-outcome states)

Derived purely from consent timestamps + kind (NO `transaction_only` / `all_blocked` master-gating carve-out — this board is about the **outreach outcome**, not message-gating). All four are static SQL booleans on the `contacts` row:

| # | Column (plain-English header) | Condition |
|---|---|---|
| 1 | **Awaiting reply** (`pending`) | `messaging_opt_out_at IS NULL AND messaging_opted_in_at IS NULL AND outreach_permission_asked_at IS NOT NULL` |
| 2 | **Confirmed** (`confirmed`) | `messaging_opt_out_at IS NULL AND messaging_opted_in_at IS NOT NULL` |
| 3 | **No response — lapsed** (`opt_out_no_response`) | `messaging_opt_out_at IS NOT NULL AND messaging_opt_out_kind = 'no_response'` |
| 4 | **Opted out** (`opted_out`) | `messaging_opt_out_at IS NOT NULL AND (messaging_opt_out_kind <> 'no_response' OR messaging_opt_out_kind IS NULL)` |

These mirror `Contact::outreachConsentState()` exactly (PENDING / CONFIRMED / NO_RESPONSE / DECLINED).

### Section 3.1 — Coverage finding (reported, not assumed)

The build prompt asked to confirm every WhatsApp-sent contact lands in exactly one of the four. **It does not, in one edge case** — reported here and handled, never silently dropped (Robustness Charter §0: no silent data-loss):

A contact can derive to **INITIAL** (`opt_out_at NULL AND opted_in_at NULL AND outreach_permission_asked_at NULL`) *despite* having a WhatsApp send, via two paths:

1. **Sent → clicked → no consent decision.** Every send calls `markOutreachPending()` (stamps `outreach_permission_asked_at` → PENDING). But a **first click clears that marker** (`SellerOutreachLandingService::recordClick()` → `clearOutreachPending()`), and a click sets neither opt-in nor opt-out. Result: the contact is back to INITIAL — they engaged (clicked) but have not yet replied yes/no. This is a live, ongoing path.
2. **Legacy pre-AT-81 sends.** Sends created before the `outreach_permission_asked_at` column existed never stamped it; if such a contact also never opted in/out, it reads INITIAL.

**Handling:** the board carries a **Total contacted** column = the full WhatsApp population per agent. When `sum(4 states) < total`, the difference is this **`awaiting` (clicked / no reply yet)** bucket. It is surfaced as a tooltipped sub-figure on the Total cell (not a 5th primary column — the prompt fixed 4), and is independently drillable (`outreach_state=awaiting`). So every population contact is accounted for and click-through totals reconcile exactly. Johan can later promote it to a full column or fold it into Pending — reversible.

---

## Section 4 — Agent attribution (decided)

Rows key off **`contacts.agent_id`** (the operational, reassignable responsible agent — `Contact::agent()`), NOT `created_by_user_id` (immutable creator). Contacts with `agent_id IS NULL` in the population roll up into an **"Unassigned"** row (drillable via `agent_id=unassigned`) so no send is silently lost.

---

## Section 5 — Read model

One service — `App\Services\SellerOutreach\WhatsappOutreachSummaryService::board()` — runs a **single scoped `groupBy` query** on the `Contact` model (so `ContactScope` + `AgencyScope` apply automatically):

```
Contact::query()
  ->hasWhatsappOutreach()                       // whereExists (Section 2)
  ->selectRaw('contacts.agent_id, COUNT(*) total,
               SUM(CASE WHEN <pending>   THEN 1 ELSE 0 END) pending, ...4 states...,
               SUM(CASE WHEN <awaiting>  THEN 1 ELSE 0 END) awaiting')
  ->groupBy('contacts.agent_id')
```

The 4-state + awaiting SQL fragments come from **one source** — `Contact::outreachStateSql($state)` — reused by both the CASE expression here and the drill-through filter (Section 6) so the two can never drift. Counting is done in SQL; never per-record in PHP. Agent display names resolved in one follow-up `User::whereIn` query.

---

## Section 6 — Drill-through (click-through parity is mandatory)

Each non-zero cell links to:
```
corex.contacts.index?agent_id=<id|unassigned>&outreach_state=<state>&channel=whatsapp
```
The Total cell links with no `outreach_state` (whole population for that agent).

`ContactController@index` gains three filters that reproduce a board cell **exactly**:
- **`agent_id`** — reconciled to filter **`contacts.agent_id`** (was `created_by_user_id` — Johan: "misleading"). `unassigned` → `whereNull(agent_id)`; `''`/`all` → no filter.
- **`outreach_state`** — applies `Contact::outreachStateSql($state)` (same fragments as the board) for `pending|confirmed|opt_out_no_response|opted_out|awaiting`.
- **`channel=whatsapp`** — applies `hasWhatsappOutreach()` (same population subquery).

**Invariant:** for every cell, `count == length(drilled list)`. Proven in verification.

---

## Section 7 — Permissions

New dedicated key **`outreach.summary.view`** (module `outreach`) in `config/corex-permissions.php`, granted by default to super_admin/admin/branch_manager/agent. Agents get it too because the board doubles as their own outreach pipeline — `ContactScope` collapses their view to a single (own) row. Enforced at: route middleware (`permission:outreach.summary.view`), controller (`hasPermission` check), and sidebar gating. `ContactScope` independently enforces row visibility (agent → own; BM → branch; admin → all).

---

## Section 8 — UI & Navigation

- View: `resources/views/corex/outreach-summary/index.blade.php`, `@extends('layouts.corex')`, CoreX design tokens (navy header, teal accents, existing amber/orange badge tokens). Plain-English column headers + `title=` tooltips. Zero cells render plain "0" (not links); non-zero cells are links. A totals row (column totals) and Total-contacted column included.
- Nav: new sidebar subitem **"WhatsApp Outreach"** under the **Real Estate** group beside Contacts (`layouts/corex-sidebar.blade.php`), gated `@permission('outreach.summary.view')`, active-state matched to `corex.outreach-summary.*`.
- Route: `GET /corex/real-estate/outreach-summary` → `corex.outreach-summary.index`.

---

## Section 9 — Acceptance criteria

1. Board renders agents × 4 columns + Total; counts match a manual SQL cross-check.
2. Every cell count equals its drilled-list length (parity) — proven for pending + no_response + opted_out at minimum.
3. Scope: agent sees only their own row; BM sees branch agents; admin/owner sees all.
4. Never-contacted (no WhatsApp send) contacts do not appear.
5. The `awaiting` leftover (Section 3.1) is accounted for, visible, and drillable — no silent loss.
6. Nav link present + routes correctly; permission gate blocks unauthorised roles (403).
7. `php -l` clean; relevant tests pass with 0 new failures; Tinker functional check passes.

---

## Section 10 — Files

**Create:** `app/Services/SellerOutreach/WhatsappOutreachSummaryService.php`, `app/Http/Controllers/CoreX/WhatsappOutreachSummaryController.php`, `resources/views/corex/outreach-summary/index.blade.php`, `tests/Feature/SellerOutreach/WhatsappOutreachSummaryTest.php`.
**Modify:** `app/Models/Contact.php` (state-SQL source + scopes), `app/Http/Controllers/CoreX/ContactController.php` (3 filters), `routes/web.php` (route), `config/corex-permissions.php` (permission), `resources/views/layouts/corex-sidebar.blade.php` (nav).
**No migration** — all columns exist.
