## 17. Two agents on every lease — the owner's agent and the tenant's agent (8 Oct 2026, cc1 — Johan's ruling; QA1 only)

**Ruling (Johan, 8 Oct 2026, verbatim):** "we need to on lease know who the rental agent is for the owner and tenant - it does not mean that if retha is advertising the property that its her tenant... retha is the listing agent, maggie is the tenant agent. but I would still on lease show the agents as such and it can be changed if need be. and that selection is who is shown on the tenant and owner links."

**What was wrong.** A lease had no agent of its own. The portal "who to call" (rental-portal-access.md §20) therefore guessed: the agent who approved the signed agreement, else the one who captured it, else the property's agent — and the property's agent (the one who *advertises* it) was being read as the tenant's agent too.

### 17.1 Data
`leases.owner_agent_user_id` and `leases.tenant_agent_user_id` — nullable FK to `users`, null-on-delete (migration `2026_10_15_000600_add_owner_and_tenant_agent_to_leases`; columns only, no data written by the migration). The two may be the same person. Relations `Lease::ownerAgent()` / `tenantAgent()` (global scopes off, trashed kept, so a screen shows the name of an agent in another branch or one who has left). No new permission, no new setting (so nothing for the Setup Wizard, rule #10a): changing them uses `leases.create`, the key that already edits a lease.

### 17.2 Defaults at creation — `App\Services\Rentals\LeaseAgentService` (the one place that knows)
* **Owner's agent** = the property's primary agent (`properties.agent_id`, the one who lists / advertises it). If the property has none (or they are no longer an active user of the agency): whoever captured the lease.
* **Tenant's agent** = the agent who **sent out the rental application** that led to the lease (`rental_applications.created_by_user_id` — the invite is mailed from that agent), else the agent who **processed / approved** it (the latest `rental_application_status_history` row to `approved`, else the latest row with a user), else **whoever created the lease**, else the owner's agent.
* Only an **active user of the lease's own agency** ever qualifies at any step (never another agency's, never someone who left or was deactivated); every lookup pins the agency itself, no global scope involved.
* Capture screen (new lease and renewal) shows both as dropdowns, preselected; the owner's agent follows the property picked until the agent chooses one; a blank side takes the default. Posted values are validated by `LeaseCaptureRequest` against the same list the dropdown is built from.
* The rule that picked each agent is stored in the `lease_created` history row (`owner_agent_rule`, `tenant_agent_rule`: `property_agent`, `application_sender`, `application_approver`, `lease_creator`, `owner_agent`, `previous_term`, `chosen_on_screen`).
* Take-on import (`RentalTakeOnConfirmService`): the spreadsheet row's named agent (else the person running the import) stands in for "who created the lease" — owner's agent = the property's agent, tenant's agent = the row's agent.
* **Renewal carries both forward** from the term being renewed (`LeaseRenewalService::createRenewalTerm`, so every renewal path does, not only the capture screen); the renewal capture screen shows them preselected and they may be changed there. A term that never had agents carries what the default rules give for it.

### 17.3 Where they show, and changing them
* **Lease screen:** an "Agents" card in the right panel (owner's agent / tenant's agent; "(default)" beside a lease whose columns were never filled — it shows what the rules give). Someone holding `leases.create` sees **Change** → two dropdowns → **Save agents** (`PUT /leases/{lease}/agents`, `corex.leases.agents.update`; own/branch/agency guard; a lease of another agency is a 404). Both required; only an active user of the lease's agency can be chosen; the dropdown lists the lease's branch first and respects Split Branches.
* **Leases list / print list / CSV-XLSX export:** an Agents column (owner / tenant).
* **History:** every change writes one `lease_agent_changed` row per side changed to the tenancy log — who (`actor_user_id`), from, to (ids and names in `metadata`), when (`occurred_at`). Saving the same agents again writes nothing.

### 17.4 The portal "who to call" (replaces the approver / creator fallback of rental-portal-access.md §20)
The **tenant** portal shows the lease's **tenant's agent**; the **owner** portal shows the lease's **owner's agent**; if that person is not an active user of the agency → the **property's agent** → the **branch alone** (the office block is always there). A lease whose columns were never filled is read through the same default rules (`LeaseAgentService::effectiveIds`), so an old lease and a new one answer alike. Neither side is ever shown the other side's agent.

### 17.5 "Own" scoping
Before this, "own" for leases was derived from `created_by_user_id` only (`Lease::scopeVisibleTo` and the per-record `AuthorizesRentalRecordScope` guard). Now **own = I created it, OR I am its owner's agent, OR I am its tenant's agent** — in the list query, the tiles, the export and the direct-URL guard (`Lease::isOwnedBy`), so they cannot disagree. Changing an agent therefore moves the lease in and out of that person's own list. Branch and agency scope are unchanged. **Not changed (reported):** other rentals screens that derive "own" from a lease's creator or the property's agent — the inspections-due board and its reminder recipient (`RentalInspectionDueService::responsibleAgentId`), and the calendar (`RentalCalendarSource`) — see the build report.

### 17.6 Back-fill of existing leases — `php artisan leases:backfill-agents`
Run by hand, never by a deploy: `--dry-run` first, `--agency=` / `--lease=` to limit, `--revert` to undo. It fills only an **empty** side (a chosen one is never overwritten), by the same default rules, writes through the query builder (no model events, `updated_at` untouched), logs one `lease_agents_backfilled` row per lease (person and rule per side), and prints counts per rule per side. `--revert` empties exactly the sides it filled and only while they still hold what it wrote and no `lease_agent_changed` row has touched that side since.

### 17.7 Mail routing — NOT changed here
Which agent lease mail goes "from" / cc'd to today, and the proposed mapping, is in the build report (`/tmp/qa1-cc1-lease-agents-2026-10-08.md`); no mail routing was altered.

### 17.8 Multi-agency (rule #9)
Nothing here names an agency: the agent lists, defaults and portal answers are all per the lease's own agency, an agent of another agency is never offered, defaulted or shown, and the wording is neutral.

**Tests:** `tests/Feature/Leases/LeaseAgentsTest.php` (defaults and every fallback, capture dropdowns, change + history, permission, cross-agency, own scope, renewal, back-fill + revert), `tests/Feature/RentalPortalAccess/PortalHomeTest.php` (the right agent per side, fallbacks, another agency's agent never shown).

### 17.x Portal consumer of the agreement terms (8 Oct 2026, QA1)
`lease_agreement_terms.earliest_termination_date` (row 16 of the §15.12 field map — "earliest date notice may expire") and, where an agency's own agreement map declares them, `extra.notice_period_days` / `extra.early_cancellation_terms`, are now READ by the tenant / owner portal Home FAQ (`rental-portal-access.md` §22, `RentalPortalFaqService`). A lease holding none of them shows no FAQ; nothing is guessed from the agency's standard notice period alone. No capture field was added: there is still no per-lease notice-length or early-cancellation field on the capture screen.

