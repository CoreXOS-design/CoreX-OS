# AT-267 Assistants — post-ship full audit

> Date: 2026-07-26 · Branch: QA2 · Scope: the whole Assistants feature as it now stands, with
> particular weight on everything that landed AFTER the 2026-07-21 security audit
> (`2026-07-21-assistant-feature-security-audit.md`), which the earlier audits never covered.
> Method: source read of the security core, the resolver, all four middleware, every AT-267
> controller/model/view diff, a `gatherMiddleware()` sweep of all 1,878 authenticated routes,
> a blade-compile pass over every touched view, `php -l` over all 117 touched PHP files, and a
> written repro test for the two most consequential findings.

## Verdict

**The security core is still sound.** Nothing found in this pass lets an assistant exceed their
agent, reach another agency, create a listing, or escalate. The property-upload lock, the live
matrix ∩ agent intersection, the view-vs-mutate split, the fail-closed lifecycle, and the
role/`is_admin` pin all hold, and the 2026-07-21 remediations are intact.

**What this pass found is a different class of problem: the feature lies to the agent in three
places, and loses their assistant's work in two.** The control page presents three switches that
are wired to nothing — including one that claims to stop an assistant editing and deleting. And
two create paths still file an assistant's work under the assistant, where the agent can never
see it again. None of these are exploitable; all of them are wrong in a way a real agent will hit
in normal use.

Verified clean, no change needed: `php -l` on all 117 touched files, all 12 assistant-related
blade views compile, 2,023 routes register with no duplicate names, no download/file-streaming
route is missing `deny_assistant_download` (52 flagged by the sweep, all reviewed — every one is
either a view render, permission-gated to a section assistants default OFF from, or not a stored
document file).

---

## Findings

### F1 — CRITICAL (trust): three control-page toggles are wired to nothing

`assistant_assignments.can_manage_my_records`, `.show_attribution` and `.notify_on_action` are
migrated, cast, defaulted, rendered on the control page, saved by `AssistantMatrixController::save()`
and asserted by `AssistantControlSettingsTest` — **and read by no other line of code in the repo.**
A repo-wide search returns only the migration, the model, the saver, the form, and the persistence
test. Nothing consumes them.

The consequence is worst for the first one. The page tells the agent:

> "{Assistant} can edit & delete my records, not just add them"

An agent who switches that OFF believes they have restricted their assistant to add-and-view.
They have not. The assistant can still edit and still delete, exactly as before. This is a
security control that is presented as working and is inert — the most dangerous kind of gap,
because it stops the agent from looking for a real one.

`show_attribution` (no "added by X" tag is ever rendered) and `notify_on_action` (no notification
is ever sent) are the same defect with lower stakes.

Root cause is traceable and not mysterious: `.ai/specs/assistant-control-page.md` plans six
phases. Phases 1–3 shipped (`1d69ba4f`, `d8f0b68a`). **Phases 4 (the `can_manage_my_records` gate),
5 (the attribution partial) and 6 (the notification + `daily_activity_entries.on_behalf_of_user_id`)
never did** — but the Phase-2 UI that advertises them did. Confirming the omission: the
`on_behalf_of_user_id` migration (`2026_07_19_000006`) covers ten audit tables and **not**
`daily_activity_entries`, exactly as Phase 6 would have added.

Confirmed by test — `tests/Feature/Assistants/AssistantAuditReproTest.php::test_can_manage_my_records_off_blocks_the_assistant_from_editing`
expects 403, gets 302 (the rename succeeds).

**Fix, either way — but pick one and do it in this prompt:** ship Phase 4 (route
`can_manage_my_records` into `PermissionService::mutationScope()`, which every per-record guard
already funnels through — one chokepoint, not thirty call sites), or pull the three toggles off
the page until their phases land. Leaving a switch on screen that does nothing is not a
cosmetic debt; it is the agent being told something untrue about who can delete their deals.

---

### F2 — HIGH (break): an assistant is 403'd on the document they just created

`DocumentController::store()` correctly files a new document under the agent
(`'owner_id' => $user->ownershipUserId()`, `:91`) and then redirects to
`docuperfect.documents.edit`. But `DocumentController::edit()` (`:110`) was never converted to the
per-record guard the H5 remediation introduced — it still runs the old bare check:

```php
} elseif ($scope !== 'all') {
    if ((int)$document->owner_id !== (int)$user->id) {   // ← assistant fails this
        abort(403);
    }
}
```

For an assistant on an agent whose `documents` scope is `own` (the common case), `owner_id` is the
agent and `$user->id` is the assistant, so **the create flow 403s on its own redirect.** The
mutators immediately below it (`:156, :269, :292, :336, :352, :372`) all correctly call
`guardDocument()`, which resolves `own` through `dataIdentityIds()`. `edit()` is the one that was
missed, and it is the door everything else goes through.

Confirmed by test — `AssistantAuditReproTest::test_assistant_can_open_a_document_filed_under_their_agent`
expects 2xx, gets 403.

**Fix:** replace the hand-rolled block in `edit()` with `$this->guardDocument($document, forEdit: false)`.
The trait is already `use`d in the class. Existing coverage would not have caught this —
`AssistantDocumentScopingTest` tests `destroy` only.

---

### F3 — HIGH (lost work): two create paths still file an assistant's work under the assistant

The spec's locked decision (`assistant-control-page.md` §Decisions 2, Johan 2026-07-19) is
absolute: *"Ownership is ALWAYS the agent — not a toggle. An assistant's work always files as the
agent's; there is no state where it stays the assistant's."* Two surfaces did not get the memo:

| Surface | Site | Writes |
|---|---|---|
| E-sign wizard documents | `ESignWizardController.php:1942`, `:4198`, `:4398` | `'owner_id' => $user->id` |
| Viewing packs | `ViewingPackController.php:357`, `:493` | `'agent_id' => $request->user()->id` |

(`SignatureController:105` is a third instance of the same pattern.)

This is not symmetrical and that is what makes it bite. The **assistant** can still see these
records — their `dataIdentityIds()` is `[agent, self]`. The **agent** cannot: theirs is `[agent]`.
So an assistant prepares an OTP or a mandate through the e-sign wizard, or builds a viewing pack
for a buyer, and under `own` scope **the agent it was done for can never see it again.** A viewing
pack additionally carries `agent_id` outward as the buyer-facing contact, so the pack a buyer
receives names the assistant rather than the practitioner.

Contrast the paths that were done right: `DocumentController:91`, `TaskController:71`,
`PresentationGeneratorController:111`, `CalendarEventService:42`, `DailyActivityController`,
`ContactObserver:59`, `DealV2Controller:332` all route through `ownershipUserId()`.

**Fix:** `ownershipUserId()` at all five sites, plus one test per surface (the pattern is
`AssistantActsForAgentTest`).

---

### F4 — MEDIUM: assistants are still selectable as the agent on a deal, a lead and a target

`32007f6a` removed assistants from the agent pickers in properties, contacts, Deal Register V2,
commission, company settings and agent compliance. It missed the DR2 and legacy deal capture
screens, which are the ones where picking wrong costs money:

| File | Line | Query |
|---|---|---|
| `Dr2/DealRegisterController.php` | 130, 279, 322 | `User::orderBy('name')->get()` |
| `Admin/DealController.php` | 180, 204, 233 | `User::orderBy('name')->get()` |
| `CoreX/PortalLeadController.php` | 47 | `User::query()->orderBy('name')->get()` |
| `Admin/CompanySettingsController.php` | 48 | `User::where('is_active', true)` |

`dr2/create.blade.php:412` and `:463` render that list into the **listing-agent and selling-agent
selectors, with commission split percentages.** An assistant — who has no FFC, no commission
structure, and by design no book — can be attached to a deal side and given a share. It is a
mis-click, not an exploit, but the correction is a manual commission unwind.

`Admin/TargetsManageController:70` is safe (it filters `role = 'agent'`, and the `User::saving`
pin guarantees an assistant's role is `assistant`). `RentalsController` is safe (gated on
`can_capture_rentals`).

**Fix:** `->where('is_assistant', false)` on the four queries above, and a ratchet test asserting
no agent-picker query returns an assistant.

---

### F5 — MEDIUM: no way to edit an assistant after creating them

`admin.assistants.*` registers `index, create, store, show, reassign, revoke, restore,
resend-invite` — and no `update`. The route group's own comment claims *"Full CRUD is the floor
(BUILD_STANDARD §1)"*; there is no U.

This became load-bearing when `db9fc30a`/`UserManagementController:31` excluded
`is_assistant = 1` from the user directory. So: the new per-assistant **Title** (`2705c958`) can
only ever be set on the create form; a typo in an assistant's name, email, cell or title is
permanent through the UI. An admin can still reach `/admin/users/{id}/edit` by typing the URL
(the exclusion is on the listing only, and the `User::saving` pin keeps `role`/`is_admin` safe
there) — but nothing links to it, and Title is not a field on that form.

**Fix:** an `edit`/`update` pair on `admin.assistants.*` covering name, email, cell, phone, title
and FICA-required, with the same `deny_assistant` guard as the rest of the group.

---

### F6 — LOW: `assistant_activity_log` has no retention policy

`LogAssistantActivity` is appended to the global `web` group and writes one row per **successful
record-scoped request** — including every GET. For an assistant working a full day across
properties, contacts and deals, that is a row per page view, forever. The table has no pruning
command, no schedule entry, and no admin surface; the only reader
(`AssistantMatrixController::edit`) caps its own display at 200 rows.

Nothing breaks today. It is an unbounded append-only table on a per-tenant database, which is
the shape of a problem that shows up in eighteen months as slow backups.

**Fix:** a `model:prune`-style retention (12 months is the natural fit with the FICA/POPIA
retention the rest of CoreX uses), registered in `routes/console.php` beside
`assistants:sync-matrix`.

---

### F7 — LOW: the PDF Splitter lost its only navigation entry

`d9a2b4fb` added `@permission('access_pdf_suite')` around the sidebar's PDF Suite link
(`corex-sidebar.blade.php:1525`) to match the route gate. Correct as far as it goes — the link
pointed at `tools.pdf_suite.hub`, which is `permission:access_pdf_suite`, so a splitter-only user
was previously shown a link that 403s.

But the outer `@if` is `access_pdf_suite OR access_pdf_splitter`, and that single link was the
**only** sidebar entry into either tool. A user holding `access_pdf_splitter` and not
`access_pdf_suite` now has no navigation to the PDF Splitter at all, while still holding
permission to use it. Non-negotiable #2.

Not assistant-specific — it affects any user with that permission split — but it arrived in an
AT-267 commit, so it belongs in this report.

**Fix:** a second sidebar entry for `tools.pdf_splitter.index` under `@permission('access_pdf_splitter')`.

---

### F8 — LOW (cosmetic): dead assistant badge in the Switch User picker

`a165dbde` added an "Assistant / PA / Receptionist" badge to the Switch User picker
(`corex-sidebar.blade.php:2237`). `167abd99` then excluded assistants from that picker entirely
(`:88` — `->where('is_assistant', 0)`). The badge, and the `is_assistant, assistant_title` columns
added to the `select()` to feed it, are now unreachable. Harmless; delete on the next pass so the
next reader does not conclude assistants are impersonable.

---

## Deliberate widenings — checked, correct, no action

- **`AdManagerController::adScope()` returns `'all'` for an assistant** (`:35`), bypassing
  `getDataScope`. Johan's decision (`4019a30a`): an assistant owns no listings, so an `own` scope
  leaves them with an empty Ad Manager. Safe because the ad always renders
  `Property::adData()` → `$property->agent`, and the route group is
  `permission:access_properties`, which the resolver denies outright when the agency kill switch
  is off. Agency isolation is unaffected (`'all'` is still inside `AgencyScope`).
- **`PropertyController::ad()` / `brochure()` skip `authorizeProperty()` for assistants**
  (`:2091`, `:2148`). Same decision, same reasoning. Verified the payload carries marketing data
  and the listing agent's public card only — **no seller or owner PII** — and that `?ad_agent` and
  `?agent=me` are both explicitly refused for assistants, so an assistant can never put themselves
  on a listing.
- **Billing excludes assistants from seats** (`SubscriptionPricingService:113, :278`). Both the
  count and the row list were changed together, so the "who you are paying for" table and the
  invoice total agree.
- **The syndication panel is genuinely read-only for assistants** — every mutating control is
  `@unless`'d, and the POSTs behind them are independently denied by
  `deny_assistant_property_write`. Hiding follows the server-side truth rather than substituting
  for it.

## Repro tests

`tests/Feature/Assistants/AssistantAuditReproTest.php` is **committed and currently RED — by
design.** Two tests, both written to describe the correct behaviour, so they name the defect now
and become the ratchet the moment F1 and F2 are fixed. It will fail `dev-check.ps1` until then;
delete it or turn it green as part of the fix, do not leave it red indefinitely.

---

# REMEDIATION — all eight fixed, same day (2026-07-26)

Johan: "fix everything." On F1 the report offered two options (ship the enforcement, or pull the
toggles); **shipped the enforcement**, because pulling a switch the agent was promised removes a
capability rather than delivering it.

| # | Sev | Fix |
|---|-----|-----|
| F1 | CRITICAL | `can_manage_my_records` + `show_attribution` + `notify_on_action` all now do what the page says — see below |
| F2 | HIGH | `DocumentController::edit()` → `guardDocument($document, forEdit: false)`; the pre-H5 bare `owner_id === self` check is gone |
| F3 | HIGH | `ownershipUserId()` at all 5 create sites (ESignWizard ×3, ViewingPack ×2, SignatureController ×1) + the 4 `owner_id === self` esign LOOKUPS moved to `dataIdentityIds()`, or the assistant would have been 404'd on the work they just filed under the agent |
| F4 | MED | `->where('is_assistant', false)` on the 6 remaining agent pickers (DR2 ×3, Admin\Deal ×3) + PortalLead + Admin\CompanySettings |
| F5 | MED | `admin.assistants.edit` / `.update` + `edit.blade.php` + an "Edit details" entry on the show page (nav same day, non-negotiable #2) |
| F6 | LOW | `AssistantActivityLog` is `Prunable` at 12 months; `model:prune` scheduled 04:30, model-scoped not blanket |
| F7 | LOW | PDF Splitter has its own sidebar entry, shown only when the Suite link is not already there |
| F8 | LOW | dead assistant badge + its two `select()` columns removed from the Switch User picker |

## F1 in detail — three layers, one helper

`User::canMutateRecords()` is the single source of truth (mirrors `canDownloadDocuments()`, fails
closed on a missing assignment). Everything reads it, so middleware, guard and UI cannot drift:

1. **`PermissionService::mutationScope()` returns `null`** when the toggle is off. Deliberately
   `null`, not `'own'`: null is what every per-record guard and every `scopeVisibleTo()` already
   reads as "no rows", so all six existing call sites (properties, contacts, deals, deals-v2,
   documents, e-sign, mobile) inherited the behaviour with no new branch — **and so will any guard
   added tomorrow.**
2. **The surfaces that resolve their own scope** read it directly: `AuthorizesContactAccess`
   (contacts go through the global `ContactScope`, not `mutationScope`) and the task + calendar
   guards on both the web and API controllers.
3. **`DenyAssistantRecordMutation`** — a global structural backstop on the `web` AND `api` groups.
   Only a fraction of ~1,878 authed routes pass a per-record guard, and a hand-picked list of the
   rest is exactly what goes stale, so it inverts the same way `DenyAssistantPropertyWrite` does:
   any PUT/PATCH/DELETE is denied unless the route is on a 9-name allow list of the assistant's
   **own account** (password, profile, theme, notification prefs). POST is untouched — "add" still
   works, which is the toggle's whole sentence. Fails CLOSED: a new PUT route ships denied and
   someone tells us, rather than shipping open and nobody ever knowing.

`show_attribution` → `AssistantActivityLog::attributionFor()` (request-memoised) + a
`<x-assistant-attribution>` component on the property, contact and deal pages. It reads the
activity log rather than the record, because ownership routing deliberately erases the assistant
from the record itself — that is the point, and it is why attribution needs its own source. Only
`edited`/`deleted` count; a read is not a contribution.

`notify_on_action` → fired from `LogAssistantActivity`, which is already the one chokepoint every
assistant request passes through (the alternative is a `notify()` sprinkled across thirty
controllers — precisely how F3's two gaps happened). New catalogue row
`assistant.acted_on_behalf`, **in-app only**: a per-change email would be spam and the agent would
turn the whole thing off. Channel selection, open-hours and the per-(user, event, subject)
cooldown are the gateway's, not re-implemented here.

## Verification

- `tests/Feature/Assistants/AssistantAuditReproTest.php` — the two repro tests were written RED
  first and are now green, plus four more (delete blocked, viewing still allowed, default-on
  unaffected, F5 admin edit incl. the role/is_admin pin). **6 passed.**
- Blast-radius run, explicit files not a directory sweep (non-negotiable #13): control settings,
  document/contact/task/deal scoping, route guard, and both static notification guards
  (`every_fire_call_site_passes_a_dedup_key`, `every_live_catalogue_toggle_can_actually_be_fired`
  — the new row and the new `fire()` site both satisfy them). **26 passed, 0 regressions.**
- `php -l` clean on all 27 changed/new PHP files; all 15 touched blade views compile;
  view/route/cache cleared; 2,025 routes register with no duplicate names.
- **No migrations added** — so no `schema:dump` needed. The new notification event type travels
  via `NotificationEventTypeSeeder`, already registered in `deploy:sync-reference-data` (AT-162).

---

# ROUND 2 — the full-suite run (Johan: "it needs to be 100%")

The wider sweep came back **207 passed, 0 failed — and 3 SKIPPED.** Skipped is not passing, so the
skips were investigated rather than reported as green. All three were real, and two of them were
worse than a plain gap.

### F9 — HIGH: an assistant's compliance card could never be anything but red

`AgentPortalController::computeComplianceStatus()` had no assistant branch at all. It built **FFC
Certificate, FFC Number, FFC Expiry, PI Insurance and Tax Clearance** for every user — PPRA
*practitioner licensing* items that an assistant, who is not a property practitioner, can never
hold. Each one resolved `red` ("Not uploaded" / "Not set"), which pinned `overall` to red and
`issues_count` above zero **for the entire life of the account.** An always-red compliance card is
not a warning; it is noise that teaches people to ignore the card.

Fixed at the SOURCE, not in Blade: that array is what the overview card, the Compliance tab,
`overall` and `issues_count` all read, so hiding the rows in the view would have left the counters
lying. Assistants keep ID Copy (FICA identity), RMCP acknowledgement and employee screening —
obligations of anyone employed around client money and documents. Only the licensing items come out.

The blade's two hardcoded key lists now **derive their rows from the data** (`array_key_exists`
against `$complianceStatus`) instead of restating them, so the view can never desync from the
controller again. That desync was immediate and real: the first version of this fix 500'd the
portal on `Undefined array key "ffc_number"`, caught by the suite.

This was the 2026-07-19 audit's "Finding 4a residual", parked for a render-capable lane and open
ever since.

### F10 — MED (test integrity): two money-path tests had been inert for five days

`AssistantOwnershipTest` carried two `markTestSkipped` TDD targets — *"an assistant-created DEAL
attributes to the agent"* and *"…CONTACT is owned by the agent"* — waiting on ownership routing.
**That routing shipped in `d8f0b68a`. The skip lines were never removed.** So the two tests
covering commission attribution sat green-but-proving-nothing, with placeholder payloads
(`$payload = [/* deal_type, purchase_price, … */]`) that would not have run if unskipped.

Both are now activated with real payloads and pass. They assert what matters: `listing_agent_id`
and `contacts.agent_id` land on the **agent**, while `created_by_id` / `created_by_user_id` still
record the **assistant** as the actor.

**The rule this earns:** a skipped test is a claim, not a guarantee. When the work a skip waits on
lands, the skip is part of that work's definition of done — a suite reporting "207 passed" while
three of its assertions were switched off is exactly the test theatre `NotificationCatalogueHasProducersTest`
was written to prevent.

## Final verification

`tests/Feature/Assistants` (all 32 files) + the four AT-267 tests outside it:
**211 passed, 587 assertions, 0 failed, 0 skipped.**

## Still open — deliberately not done here

- **`daily_activity_entries.on_behalf_of_user_id`** (control-page spec Phase 6, item 5). It is the
  only piece of that spec still unbuilt: it needs a migration, and a migration on QA2 means a
  `schema:dump` and a demo/live migrate. That is a deploy-shaped change, not an audit fix, so it
  is Johan's call to schedule rather than something to slip into a remediation commit. Daily
  activity already files under the correct agent (`ownershipUserId()`); what is missing is only
  the audit column naming which assistant keyed each number.
