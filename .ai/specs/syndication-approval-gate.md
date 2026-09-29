# Syndication Approval Gate — the third layer

> Status: **DRAFT — pending approval.** Drafted 2026-09-29 (QA2 lane).
> Revision 3, 2026-09-29 — Johan closed the day-one-stock question: **only new stock needs
> approval** (D8, §2 + §4.4). No open questions remain; this spec is ready to build on his go.
> Revision 2, 2026-09-29 — Johan's second pass added the email, the *Send for approval* button,
> the Property Settings location, and the pending status + filter on the Properties list. Those
> rulings are D4–D7 in §2 and they REPLACE the standalone approvals queue screen that revision 1
> proposed (§7).
> Requested by: Johan — *"an option an agency can turn on where they have a third layer for
> properties that requires an admin to approve a property for syndication — so even if compliance
> is done they still need approval from a chosen person."*
> Pillars: **Property** (the gated entity), **Agent/User** (the approver and the requester).
> Sister specs: `.ai/specs/compliance.md` (layer 2 — marketing readiness),
> `.ai/specs/p24-syndication.md`, `.ai/specs/pp-syndication-per-agency.md`,
> `.ai/specs/agency-onboarding-setup.md` §6.1 (wizard saver contract),
> `.ai/specs/corex-domain-events-spec.md` (events), `.ai/specs/multi-tenancy.md`.

---

## 1. What this feature does and why (business requirement)

CoreX today has **two** layers between a new listing and the public portals:

| Layer | What it checks | Where it lives today |
|-------|----------------|----------------------|
| 1 — On market | The property is not a draft / prospecting / withdrawn / archived listing | `EnforcesMarketingReadiness::enforceListingNotDraft()` |
| 2 — Compliance | Required document types on file, seller FICA approved, ≥4 photos, listing details complete | `MarketingReadinessService` → `compliance_snapshot_at` |

Both layers are **automatic**: they are satisfied by facts (a document exists, FICA is approved,
photos are uploaded). Nothing in CoreX lets an agency require a **human being** to look at a
listing and say "yes, that may go out under our name."

That is a real gap for any agency where the principal is accountable for what the public sees.
Compliance being complete tells you the paperwork is in order — it does not tell you the price is
right, the photos are presentable, the description is on-brand, or that the mandate is the one the
principal thought it was. Agencies with a junior or high-volume agent base need the last word to
sit with a named person, and today CoreX simply cannot offer it.

**Layer 3 is that last word.** An agency turns on *Syndication approval*, chooses who approves,
and from that moment **no property reaches any syndication target until the chosen approver has
approved it** — regardless of how green the compliance panel is.

### Why this makes CoreX *best*, not merely *working*

Property24's and Private Property's own back-offices have no concept of a pre-publication
approver — an agency that wants this control today runs it on WhatsApp and hopes. Competing CRMs
either have no gate at all or bolt "approval" onto a workflow engine an estate agent will never
configure. CoreX ships it as **one switch and one name**: the agency turns it on, picks the person,
and the rest is invisible. The agent gets one button — *Send for approval* — the approver gets an
email and a one-click filtered list of everything waiting, and the listing cannot leak out in
between. That is the absorb-the-complexity standard: the agency does simple, we do complicated.

### Off by default

An agency that does not turn this on sees **zero change** — no new button, no new banner, no new
status, no new filter, no behaviour difference anywhere. The gate is inert until the switch is on.

---

## 2. Decisions taken (Johan, 2026-09-29) — these are settled

| # | Question | Ruling |
|---|----------|--------|
| D1 | What does one approval cover? | **One approval covers every syndication target.** The approver approves the property once; the agent may then switch on Property24, Private Property and every agency website without coming back. No per-portal approval. |
| D2 | What happens when the listing changes after approval (price, photos, description)? | **Approval holds forever.** No change of any kind sends the listing back for re-approval. Once approved, always approved. |
| D3 | What does the approval hold back? | **All syndication** — Property24, Private Property, every agency website, and any syndication target added to CoreX in future. |
| D4 | How is the approver told? | **Email**, to every chosen approver, the moment a property is sent for approval. (An in-app notification rides along on the same event — §8 — but the email is the contract.) |
| D5 | How does the agent raise it? | **Once compliance is complete**, a button appears: **"Send for approval"**. Clicking it emails the approver and puts the property into a **pending** state. Before compliance is complete the button is not offered. |
| D6 | Where does the agency turn it on? | **The Property Settings section** — `/corex/settings`, the Properties block that already holds *Default Property Ordering*, *Property Statuses*, *Property Marketing* and *Syndication Portals*. It goes directly beneath Syndication Portals. |
| D7 | How does the approver find what is waiting? | **A status on the property, and a filter on the Properties list.** The Properties list — which already has search, sort, filters, pagination and an empty state — becomes the queue. **No separate approvals screen is built** (revision 1 proposed one; D7 replaces it). |
| D8 | On the day an agency switches this on, does its existing stock need approving? | **No — only new stock.** Everything the agency already has out on a portal is stamped approved automatically at switch-on and never reaches the approver. See §4.4 for the exact set and the one case it deliberately excludes. |

### D7 in detail — why there is no separate queue screen

Johan asked for "a status on the property page where the selected user can filter and see the
pending properties." That is exactly the Properties list with one more filter. Building a second
list screen beside it would duplicate the search, the sort, the scoping and the row actions, and
would give CoreX two places to look for the same thing — the drift that CLAUDE.md's "one
syndication surface" doctrine exists to prevent. **One list. One filter. One badge.**

### Consequences of D3 that are explicitly *out* of scope

"All syndication" is read as *the portal/website pushes*. Two adjacent things are **NOT** gated by
this spec, and must not be touched by the build:

- **Send to Market** (the compliance go-live snapshot, `MarketingReadinessService::snapshotCompliance()`)
  — a property still goes "live" in CoreX exactly as today. Indeed D5 depends on it: completing
  compliance is what *produces* the Send-for-approval button.
- **Property social-media marketing posts** (`PropertyMarketingController::publish()`) — unchanged.

If Johan wants either of those held too, that is a one-line extension of §6.3's call-site list —
but it is not built without his explicit word.

### Consequence of D2 that the build must respect

Because approval holds forever, the durable fact is a **property-level stamp**
(`properties.syndication_approved_at`) — exactly the shape `compliance_snapshot_at` already has.
The gate is therefore a null check, not a status machine, and cannot drift out of date. Nothing in
this build may ever clear that stamp automatically. It is cleared only by an explicit human
*Revoke approval* action (§5.5), which is approver-only and audited.

### Consequence of D5 — "pending status" is its own badge, NOT `properties.status`

The property's own status column (Active / Draft / Sold / Let / Withdrawn …) is **not touched**.
That column is what CoreX pushes to Property24 and Private Property as the listing's live state; a
"pending" value in it would be sent to the portals as a listing status and would break the status
sync for every agency. The approval state is therefore its **own** badge, derived from the two
approval columns:

| Badge | Condition | Colour role |
|-------|-----------|-------------|
| *(nothing)* | Feature off, **or** compliance not yet complete | — |
| **Awaiting approval** | Compliance complete, request pending | amber / warning |
| **Approved for syndication** | `syndication_approved_at` set | green / success |
| **Not approved** | Last request rejected | red / crimson |
| **Needs approval** | Compliance complete, no request raised yet | muted |

**Business consequence, in one sentence:** the property's own status keeps saying Active or Draft
exactly as it does today, and the approval state shows beside it as a separate tag — so nothing
you already read on a listing changes meaning.

---

## 3. Pillar connections

| Pillar | Reads | Writes back |
|--------|-------|-------------|
| **Property** | Its own approval stamp; its compliance state (which gates whether approval may even be requested) | `syndication_approved_at`, `syndication_approved_by_user_id` |
| **Agent (User)** | The agency's chosen approvers; the requesting agent | `requested_by_user_id`, `decided_by_user_id` on the approval row |
| Contact / Deal | — | — (not touched) |

No new pillar, no new island: the approval row hangs off a Property and two Users, and the
authoritative state lives on the Property itself.

---

## 4. Data model

### 4.1 Migration A — the property stamp

`properties`:

| Column | Type | Notes |
|--------|------|-------|
| `syndication_approved_at` | `timestamp` NULL, indexed | The durable approval fact. NULL = not approved. |
| `syndication_approved_by_user_id` | `unsignedBigInteger` NULL, FK `users.id` nullOnDelete | Who approved. |

Deliberately mirrors `compliance_snapshot_at` so the two gates read identically and no developer
has to learn a second idiom.

**Migration backfill:** none. Every existing property ships with `syndication_approved_at = NULL`,
which is inert because the agency switch defaults to **off**. The grandfathering Johan asked for
(D8) happens at **switch-on**, per agency, not in the migration — see §4.4.

### 4.2 Migration B — the request/decision trail

`property_syndication_approvals` — the audit history. One row per request; the latest row is the
current request. History is never overwritten.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `agency_id` | unsignedBigInteger, indexed | `BelongsToAgency` / `AgencyScope` (multi-tenancy non-negotiable) |
| `branch_id` | unsignedBigInteger NULL, indexed | Stamped from the property at request time — drives branch-scoped visibility (§7.3) |
| `property_id` | unsignedBigInteger, indexed | |
| `status` | enum: `pending`, `approved`, `rejected`, `withdrawn` | |
| `requested_by_user_id` | unsignedBigInteger, FK | The agent who asked |
| `requested_at` | timestamp | |
| `request_note` | text NULL, max 2000 | Optional "anything the approver should know" — carried into the email |
| `decided_by_user_id` | unsignedBigInteger NULL, FK | |
| `decided_at` | timestamp NULL | |
| `decision_note` | text NULL, max 2000 | **Required when rejecting** — an agent must be told why |
| `notified_at` | timestamp NULL | When the approver email actually went out (proves D4, and makes a failed send visible) |
| `deleted_at` | timestamp NULL | SoftDeletes (non-negotiable #1) |
| `created_at` / `updated_at` | timestamps | |

Indexes: `(agency_id, status, requested_at)` and `(property_id, id)` ("latest request for this
property").

**A revoke (§5.5) writes a new row** with `status = withdrawn`, the revoking approver in
`decided_by_user_id` and the reason in `decision_note`, and clears the property stamp — so the
trail reads as a continuous story rather than a mutated record.

### 4.3 Agency settings (no new settings table — the existing one)

Two `PerformanceSetting` keys, agency-scoped (`PerformanceSetting::get($key, $default, $agencyId)`
— the same mechanism `syndication_pp_enabled` / `syndication_p24_enabled` / `pp_exclusivity_enabled`
already use, so this joins the existing Property Settings block rather than starting a parallel
one):

| Key | Default | Meaning |
|-----|---------|---------|
| `syndication_approval_required` | `0` (off) | The master switch for layer 3 |
| `syndication_approver_user_ids` | `[]` | JSON array of `users.id` — the chosen approver(s) |

**Validation rule (hard):** the switch cannot be saved ON with an empty approver list. The saver
rejects it with *"Choose at least one person who approves listings before you turn this on."* This
is what prevents the only way this feature could brick an agency — a gate with nobody behind it.

Multiple approvers are allowed and expected: a single named approver on leave would otherwise stop
the agency's entire marketing. **All** chosen approvers get the email; any one may approve; the
first decision wins and the property leaves the pending state for everyone.

### 4.4 Switch-on grandfathering — "only new stock" (D8)

The instant `syndication_approval_required` flips **off → on** for an agency,
`GrandfatherSyndicatedStockJob` runs once for that agency and stamps, in chunks of 200:

```
syndication_approved_at         = now()
syndication_approved_by_user_id = the admin who flipped the switch
```

plus one `property_syndication_approvals` row per property, `status = approved`,
`decision_note = "Approved automatically — already published when syndication approval was
switched on."` The trail must show *why* a property is approved that nobody approved.

**The set it stamps — already published, on any target:**

- an active Property24 presence (`p24_ref` set and its syndication row enabled), **or**
- an enabled Private Property syndication row, **or**
- an enabled `property_website_syndications` row for any of the agency's websites.

**The three cases it deliberately does NOT stamp:**

1. **A property that has never been published anywhere.** Old or new, if it has not gone out, it
   needs the nod. This is the narrow reading of D8 and it is the deliberate call: Johan's concern
   is not re-approving what is already public, and a never-published listing is not something the
   approver has ever seen. Stamping it would put a permanent hole in the control on day one —
   under D2 an approval never expires, so a listing loaded last month could reach the portals
   unseen forever. **Business consequence, one sentence: nothing already out on a portal ever
   comes back to your approver, and anything that has not gone out yet needs one nod before it
   does.**
2. **A property whose latest approval row is `withdrawn`** — i.e. an approver has deliberately
   revoked it. A later off→on cycle of the switch must never quietly undo a human revoke.
3. **Off-market stock** (`Property::OFF_MARKET_STATUSES`) — nothing to publish, nothing to approve.

**Idempotent.** The job only ever writes where `syndication_approved_at IS NULL`, so switching the
feature off and on again re-grandfathers only what has become published in the meantime, and never
re-stamps or double-rows anything.

---

## 5. User flow

### 5.1 The agency admin turns it on (D6)

1. **CoreX Settings → Properties**, directly beneath *Syndication Portals*. New control:
   **"Require approval before a listing is syndicated"**, with the plain-English explanation, and a
   **"Who approves"** people-picker listing the agency's active users (multi-select).
2. Save. Validation per §4.3.

### 5.2 The agent (this is the whole experience for them)

1. Agent loads the listing and works the compliance checklist — **unchanged**.
2. Agent completes compliance / Sends to Market — **unchanged**. Compliance goes green as today.
3. **The moment compliance is complete**, a button appears on the property page (compliance/status
   area) and in the Syndication panel: **"Send for approval"**, with an optional note box.
   The property shows the muted **Needs approval** badge. Every portal and website control in the
   Syndication panel is disabled, with the reason stated on the panel — never a dead switch with
   no explanation.
4. Agent clicks **Send for approval** → every chosen approver is **emailed** (D4), the property
   moves to **Awaiting approval** (amber badge), and the button becomes **Cancel request**.
5. On approval: badge turns green, the agent is notified, every portal control unlocks — the panel
   behaves exactly as it does today for an agency without the feature.
6. On rejection: badge turns red with **"Not approved — <reason>"**, and the button becomes
   **Send for approval** again once the agent has fixed what was raised.

### 5.3 The approver

1. **Email** arrives the moment a request is raised (§5.4).
2. It links straight to the property. It also links to **Properties → filtered to *Awaiting
   approval***, which is the whole queue in one click.
3. On the property, the approval card shows who asked, when, their note, and the compliance summary
   — then **Approve** (optional note) or **Reject** (**note required**).
4. The Properties list carries the same two actions inline on each *Awaiting approval* row, so a
   batch of listings can be worked without opening each one — each still a separate, deliberate
   click. **No "approve all" control is built**; a control whose purpose is a human looking at each
   listing is defeated by a tick-all box.
5. Approving stamps the property and unlocks every portal control for that property permanently (D2).

### 5.4 The email (D4)

- **To:** every user id in `syndication_approver_user_ids` (active users only), one email each.
- **Subject:** `Approval needed: <street address>, <suburb>`
- **Body:** property address, listing type and price, the listing agent's name, the agent's note if
  they left one, the compliance summary line ("Compliance complete — cleared <date>"), and two
  buttons: **Open the property** and **See everything waiting**.
- **Sent** from the queued job on the `SyndicationApprovalRequested` event (§8), stamping
  `notified_at` on success. A send failure is logged and leaves `notified_at` NULL so it is visible
  rather than silent — the property still shows *Awaiting approval*, so the gate never depends on
  the email arriving.
- Honours `OutboundMailGuard` exactly as every other CoreX email does (on staging it is
  intercepted, by design).

### 5.5 Revoking an approval (the only path back)

An approver may **Revoke approval** on an approved property. This clears
`syndication_approved_at`, writes the `withdrawn` audit row with a required reason, notifies the
listing agent, and returns the badge to **Needs approval**. It does **not** deactivate the listing
on any portal it already reached — pulling a live listing down is the existing per-portal
Deactivate action and stays a separate, deliberate act. Revoke means "it cannot go anywhere *new*
until re-approved", and the banner says exactly that.

Included because an approval that can never be taken back is not a control, and because
BUILD_STANDARD §1a forbids shipping a state no human can reverse.

---

## 6. The gate — exactly where it is enforced

### 6.1 One service, one exception, one trait

- `App\Services\Syndication\SyndicationApprovalService`
  - `isRequired(Property): bool` — agency switch on? (false ⇒ every method below is a no-op)
  - `isApproved(Property): bool` — `!isRequired() || syndication_approved_at !== null`
  - `canRequest(Property): bool` — compliance complete (D5) **and** no request already pending
  - `canApprove(User, Property): bool` — §6.2
  - `request()` / `approve()` / `reject()` / `revoke()` — the four transitions, each writing its
    audit row and firing its event inside one DB transaction
  - `stateFor(Property): SyndicationApprovalState` — the readonly DTO the badge, the banner and the
    list row all render from (mirrors `ReadinessReport`, so there is one idiom for both gates)
- `App\Services\Syndication\SyndicationApprovalRequiredException` — renders **422** with
  `{ message, approval_state }` for the JSON callers and a flash for web, exactly as
  `MarketingBlockedException` already does. Message: *"This listing has not been approved for
  syndication yet."*
- `App\Http\Controllers\Concerns\EnforcesSyndicationApproval::enforceSyndicationApproval(Property, string $target)`
  — a **separate** trait from `EnforcesMarketingReadiness`. Layer 2 and layer 3 are different
  concerns; folding layer 3 into the compliance trait would make a compliance service responsible
  for a non-compliance rule and would read as "compliance blocked it" to every existing caller.

### 6.2 Who may approve — one rule, no second source

A user may approve iff **their id is in the agency's `syndication_approver_user_ids`**, or they are
the agency owner / agency admin (the standing fallback so an agency whose chosen approver has left
is never stuck — the same doctrine as every other designated-person feature in CoreX).

Expressed as **`SyndicationApprovalService::canApprove(User, ?Property)`**, called from the
controller (`abort_unless(... , 403)`) and from every Blade surface. **One source of truth.** A
second, role-matrix permission for *approving* is deliberately NOT created — two lists of "who
approves" would drift, and the agency picker is the one Johan asked for.

> **Build note, 2026-09-29 (revision 3).** Revision 1 of this spec proposed a Laravel Gate ability
> (`can:syndication.approve`) for this. The codebase has **zero** `Gate::define` and **zero**
> `@can` — CoreX's own idiom for exactly this shape is a service gate class
> (`DealPropertyOwnerGate`, `AgentSeatLockService`) plus `hasPermission()` middleware. Introducing
> the framework's Gate here would have been a second authorisation idiom for one feature, against
> INVESTIGATE → COPY → ADAPT. The authority rule and its single source are unchanged; only its
> expression is the house one. Route middleware remains `permission:access_properties` (plus the
> group's `agency.required` / `deny_assistant_property_write`), with the approver check in the
> controller — the layered shape CLAUDE.md #5 names ("route middleware, controller checks").

The role-matrix permission that IS added (non-negotiable #5) governs who may *configure* the gate:

```php
['key' => 'properties.syndication.manage_approvers',
 'label' => 'Configure Syndication Approval (switch + approvers)',
 'section' => 'properties', 'type' => 'action', 'module' => 'properties', 'sort_order' => <next>],
```

### 6.3 Call sites — every path that puts a listing ON a target

`enforceSyndicationApproval()` is added immediately **after** the existing
`enforceMarketingReadiness()` call (compliance first, then approval — so an agent with outstanding
compliance sees the compliance message, which is the more actionable one):

| File | Methods |
|------|---------|
| `app/Http/Controllers/Property24/P24SyndicationController.php` | `toggle()` (only when turning ON), `submit()`, `reactivate()` |
| `app/Http/Controllers/PrivateProperty/SyndicationController.php` | `toggle()` (only when turning ON), `submit()`, `reactivate()` |
| `app/Http/Controllers/Website/WebsiteSyndicationController.php` | `toggle()` (only when turning ON), `activate()` |

**Deliberately NOT gated** (part of the spec, not an oversight):

- `deactivate()` on every portal — removing exposure is never blocked. Blocking a take-down would
  be actively dangerous.
- `refresh()` and the observer's status syncs (`PropertyObserver` P24 status push, website
  removals) — these only ever act on a listing *already* on the portal, which under D2 was approved
  before it got there. Gating them would mean a revoke silently breaks status syncs on live
  listings, which is worse than the thing the gate exists to prevent.
- The console bulk commands (`BulkSyndicateP24`, `BulkSyndicatePP`, `P24SyndicateSubmit`) — root-run
  operational tools, not agent paths. **They must honour the gate**: each skips unapproved
  properties and prints `SKIPPED (awaiting syndication approval)` in its summary, so a bulk run can
  never launder a listing past the approver.
- `PropertySyndicationPanelController` — read-only; it *renders* the state rather than enforcing it.

### 6.4 UI surfaces — three, all fed by one DTO

1. `resources/views/corex/properties/partials/syndication-panel.blade.php` — the single syndication
   control surface in CoreX (property page *and* Properties-index modal both render it). Gets the
   approval banner, the disabled-controls state, and the Send-for-approval / Cancel-request buttons,
   via a new partial `_syndication-approval-banner.blade.php`.
2. The property page's compliance/status area — the **Send for approval** button (D5) and the
   approval badge, beside the existing compliance state, plus the approver's Approve / Reject /
   Revoke card.
3. The Properties list — the badge on each row, the **Awaiting approval** filter and the inline
   row actions (§7).

When `isRequired()` is false, every one of the three renders **nothing at all** — byte-for-byte
today's screens.

---

## 7. The approver's queue = the Properties list (D7)

No new page. The existing Properties list already ships search, sort, filters, pagination and an
empty state (BUILD_STANDARD §1b), and it already carries the precedent this follows exactly:
`?filter=marketing_pending`, the compliance click-through
(`PropertyController::index()` — `whereNull('compliance_snapshot_at')`).

### 7.1 What is added to it

- **Filter value `?filter=approval_pending`** — applied as
  `whereNotNull('compliance_snapshot_at')->whereNull('syndication_approved_at')` restricted to the
  properties whose latest approval row is `pending`, excluding `Property::OFF_MARKET_STATUSES`.
  It joins the existing filter-persistence set (saved per-list in session, cleared by "Clear
  filters") exactly as `marketing_pending` does.
- **A filter chip in the existing filter bar** — *Awaiting approval* — visible only when the
  feature is on and only to users who pass `can:syndication.approve`. **This is the feature's
  navigation entry** (non-negotiable #2): there is no new page to link to, and the control is
  discoverable in the place the user already filters.
- **A KPI tile** in the list header strip — *Awaiting approval*, with the count across the full
  filtered set, clicking through to the filter. Same conditional visibility. It reuses the
  existing single-aggregate conditional-SUM query, so it costs **no extra round trip**.
- **The badge on every row** (§2, "pending status is its own badge").
- **Inline row actions** on pending rows for an approver: Approve · Reject.

### 7.2 The floor, restated against this screen

| Requirement | How it is met |
|-------------|---------------|
| Search | The list's existing address/suburb/title/reference search — unchanged, and it narrows *within* the approval filter |
| Sort | Existing sort set; the agency's *Default Property Ordering* remains the default |
| Filters | The new `approval_pending` value alongside every existing filter (agent, branch, status, type, price, beds/baths) — they compose |
| Pagination | Existing, filters preserved in the query string |
| Empty state | The list's existing empty state, with approval-specific copy when the approval filter is the active one: *"Nothing waiting for your approval."* |

### 7.3 Scoping — own / branch / agency

`PropertySyndicationApproval::scopeVisibleTo(Builder, User)`, mirroring
`FicaSubmission::scopeVisibleTo()` exactly (the closest existing analogue — an approval queue with
the same three-tier shape):

- **all** — agency owner/admin, and any approver whose role scope is agency-wide.
- **branch** — an approver limited to a branch: `where branch_id = effectiveBranchId()`, and
  `whereRaw('1 = 0')` when they have no branch (fail closed, never fail open).
- **own** — anybody else: only requests they raised themselves.

The list filter and the Approve/Reject/Revoke endpoints run the **same** membership test, so list
visibility and write authority can never disagree (the `AuthorizesDealAccess` doctrine). Agency
isolation is `BelongsToAgency` + `AgencyScope` at the query layer: an approval id from another
agency 404s on direct URL, it is not merely unlinked.

---

## 8. Events (non-negotiable #9 — domain events, not ad-hoc calls)

Registered in `.ai/specs/corex-domain-events-spec.md`'s catalogue under Property:

| Event | Fired when | Payload | Listeners |
|-------|-----------|---------|-----------|
| `Property\SyndicationApprovalRequested` | Agent clicks *Send for approval* | `property`, `approvalId`, `requestedByUserId`, `approverUserIds`, `agencyId` | `NotifySyndicationApprovers` → queues `SendSyndicationApprovalEmailJob` (§5.4) + audit |
| `Property\SyndicationApproved` | Approver approves | `property`, `approvalId`, `decidedByUserId`, `agencyId` | `NotifyListingAgentOfSyndicationDecision` + audit |
| `Property\SyndicationRejected` | Approver rejects | `property`, `approvalId`, `decidedByUserId`, `reason`, `agencyId` | same + audit |
| `Property\SyndicationApprovalRevoked` | Approver revokes | `property`, `approvalId`, `decidedByUserId`, `reason`, `agencyId` | same + audit |

**Listeners are registered explicitly in `AppServiceProvider::boot()` via `Event::listen()`** —
event discovery is OFF in this codebase, and a queued listener on a domain event fatals on
`AbstractDomainEvent`'s readonly `$eventId`. Listeners stay **sync** and queue a Job carrying
scalars for the email. The job runs on a **served** queue (never `ai` or `p24`, which have no
worker on live).

---

## 9. Setup Wizard (non-negotiable #10a — same prompt, no exceptions)

`config/agency-onboarding-copy.php`, the **Properties** step, gains the control:

- **Label:** "Require approval before a listing is syndicated"
- **`explain`:** "When this is on, a listing that has passed compliance still cannot be sent to
  Property24, Private Property or your website until a person you choose has approved it."
- **`affects`:** "What this changes: when compliance is done your agents get a *Send for approval*
  button instead of the portal switches; the people you pick below get an email for every listing
  and a new *Awaiting approval* filter on the Properties list."
- Plus the **"Who approves"** picker in the same step, immediately beneath it.

Wired into that step's existing `savers` per `agency-onboarding-setup.md` §6.1 — `updateSyndicationPortals`
is **already** registered as a saver on that step, and because the step posts a **subset** of the
saver's fields, the boolean write is guarded with `$request->has()` so saving this step can never
wipe `syndication_pp_enabled` / `syndication_p24_enabled` (the exact failure §6.1 exists to prevent).

### 9.1 Deliberately NOT in the wizard — PENDING JOHAN'S RULING (§10a point 3)

**Status: built everywhere EXCEPT the wizard, awaiting Johan's decision.** Two concrete reasons,
both structural rather than convenience:

1. **The wizard has no control type that can express the approver picker.** Its vocabulary is
   `toggle | number | text | textarea | select`, and `select` takes a STATIC option map baked into
   `config/agency-onboarding-copy.php`. "Who approves" is a live, per-agency list of that agency's
   users — it cannot be a static map.
2. **A brand-new agency has nobody to choose yet.** Onboarding runs before the team exists; the
   agency admin is often the only user in the system at that moment.

Shipping the toggle ALONE would be worse than omitting it: the saver refuses to store the switch
ON with an empty roster (§4.3), so the wizard step would appear to save and silently not — the
precise silent-failure class §6.1 of the parent spec exists to prevent.

**The ask for Johan (business):** should a brand-new agency be walked through this at setup time
at all, or is it correctly a decision they make from Settings once they have a team? If he wants
it in the wizard, the honest build is a new `user_multiselect` control type in the wizard's
vocabulary — a real addition to the wizard, specced and built on its own, not smuggled in here.

---

## 10. Acceptance criteria

Feature **off** (default):

1. Not one pixel changes anywhere: no badge, no button, no filter chip, no KPI tile, no extra query
   on the property page or the Properties list, and every portal toggle behaves as it does today.

Feature **on**:

2. Saving the switch ON with no approver chosen is rejected with the §4.3 message.
3. A property whose compliance is **not** complete shows **no** Send-for-approval button (D5).
4. A compliance-green, never-approved property: P24 toggle-on, P24 submit, P24 reactivate, PP
   toggle-on, PP submit, PP reactivate, website toggle-on and website activate **each** return 422
   with the approval message and **no call is made to any portal API**.
5. The same property's **deactivate** on each portal still works.
6. Agent clicks *Send for approval* → a `pending` row exists, **every chosen approver is emailed**
   (assert the mailable, its recipients and that the address, price, agent and note are in it),
   `notified_at` is stamped, the badge reads *Awaiting approval*, portal controls stay disabled.
7. A mail failure leaves `notified_at` NULL and the request still `pending` — the gate does not
   depend on the email arriving.
8. `?filter=approval_pending` on the Properties list returns exactly the pending set, composes with
   the agent/branch/status/price filters, survives pagination, and shows the approval-specific
   empty state when nothing is waiting.
9. The filter chip and KPI tile are invisible to a user who fails `can:syndication.approve`, and
   invisible to everyone when the feature is off.
10. A non-approver hitting the approve endpoint by direct POST gets 403. An approver from another
    agency gets 404.
11. Approver approves → `properties.syndication_approved_at` is stamped, the agent is notified, the
    badge turns green, and **all eight** actions in (4) now succeed.
12. Agent then changes price / photos / description → approval is **still** valid and portals still
    work (D2 — asserted explicitly so nobody "helpfully" adds re-approval later).
13. Approver rejects with a reason → the agent sees the reason and can re-send; portals stay shut.
14. Approver revokes an approved property → the stamp clears, new portal enables are blocked, and
    any listing already live on a portal is **not** touched.
15. Branch-scoped approver sees only their branch's pending properties and cannot approve one
    outside it (403), including by direct URL.
16. `BulkSyndicateP24` / `BulkSyndicatePP` skip unapproved properties and report the skip count.
17. An agency with the switch on, whose properties are already live on portals, has **nothing
    pulled down** by this build.
18. The property's own `status` column is never written by any path in this feature.
19. **Grandfathering (D8/§4.4)** — switching the feature on for an agency that has stock live on
    P24, on Private Property and on a website stamps all three as approved, writes their audit
    rows with the automatic-approval note, and leaves them able to syndicate with no approver
    involvement; while in the same agency a never-published property, an off-market property, and
    a previously **revoked** property are each left unapproved.
20. Switching the feature off and on again re-runs the grandfathering without re-stamping or
    double-rowing anything already approved, and without resurrecting a revoked approval.

---

## 11. Files

**Create**

```
database/migrations/xxxx_add_syndication_approval_to_properties.php
database/migrations/xxxx_create_property_syndication_approvals_table.php
app/Models/PropertySyndicationApproval.php
app/Services/Syndication/SyndicationApprovalService.php
app/Services/Syndication/SyndicationApprovalState.php
app/Services/Syndication/SyndicationApprovalRequiredException.php
app/Http/Controllers/Concerns/EnforcesSyndicationApproval.php
app/Http/Controllers/CoreX/SyndicationApprovalController.php   (send / cancel / approve / reject / revoke)
app/Events/Property/SyndicationApprovalRequested.php
app/Events/Property/SyndicationApproved.php
app/Events/Property/SyndicationRejected.php
app/Events/Property/SyndicationApprovalRevoked.php
app/Listeners/Property/NotifySyndicationApprovalDecision.php
app/Jobs/Property/SendSyndicationApprovalEmailJob.php
app/Jobs/Property/GrandfatherSyndicatedStockJob.php            (§4.4 switch-on stamping)
app/Mail/SyndicationApprovalRequestedMail.php
resources/views/emails/syndication-approval-requested.blade.php
resources/views/corex/properties/partials/_syndication-approval-banner.blade.php
resources/views/corex/properties/partials/_syndication-approval-badge.blade.php
tests/Feature/Syndication/SyndicationApprovalGateTest.php
tests/Feature/Syndication/SyndicationApprovalQueueTest.php
.ai/specs/syndication-approval-gate.md   (this file)
```

**Modify**

```
app/Models/Property.php                                   (2 columns, casts, relation, latest-approval accessor)
app/Providers/AppServiceProvider.php                      (Gate ability + Event::listen registrations)
config/corex-permissions.php                              (properties.syndication.manage_approvers)
config/agency-onboarding-copy.php                         (§9 wizard control)
routes/web.php                                            (5 action routes, named, gated)
app/Http/Controllers/CoreX/SettingsController.php         (updateSyndicationPortals — 2 keys + validation + §4.4 job dispatch on off→on)
resources/views/corex/settings.blade.php                  (Properties section: switch + people-picker)
app/Http/Controllers/CoreX/PropertyController.php         (approval_pending filter + KPI tile + show() state)
resources/views/corex/properties/index.blade.php          (filter chip, KPI tile, row badge, row actions)
resources/views/corex/properties/show.blade.php           (badge + Send for approval + approver card)
resources/views/corex/properties/partials/syndication-panel.blade.php   (banner + disabled state)
app/Http/Controllers/Property24/P24SyndicationController.php            (3 call sites)
app/Http/Controllers/PrivateProperty/SyndicationController.php          (3 call sites)
app/Http/Controllers/Website/WebsiteSyndicationController.php           (2 call sites)
app/Console/Commands/BulkSyndicateP24.php                 (skip + report)
app/Console/Commands/BulkSyndicatePP.php                  (skip + report)
.ai/specs/corex-domain-events-spec.md                     (4 catalogue rows)
.ai/CHAT_STARTER.md                                       (close-out)
```

**Not touched:** `MarketingReadinessService`, the compliance snapshot, `properties.status`,
`PropertyObserver`, `PropertyMarketingController`, and every portal `deactivate` / `refresh` path.

---

## 12. Test plan

`tests/Feature/Syndication/SyndicationApprovalGateTest.php` — the gate: off-is-inert; no button
before compliance; all eight blocked actions; deactivate still allowed; the email (recipients +
content, via `Mail::fake()`) and `notified_at`; mail-failure leaves the request pending; approve
unlocks; D2 (edit-after-approval still approved); revoke re-blocks without touching live listings;
403/404 authority cases; `properties.status` never written; and an `Http::fake()` assertion that
**no portal HTTP call is made** while blocked (per the `Http::fake()`-merges-stubs gotcha, assert
per-URL counts — never `assertNotSent`).

`tests/Feature/Syndication/SyndicationApprovalQueueTest.php` — the list: the `approval_pending`
filter's exact result set, composition with the other filters, persistence across pagination, the
empty state, chip/tile visibility by permission and by switch state, and the three visibility tiers
including the branchless fail-closed case.

Per CLAUDE.md #13, only these two files are run during the build — never a broad suite.
