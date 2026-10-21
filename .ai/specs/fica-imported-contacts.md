# FICA for imported contacts — the "sort it out" flow, the hard stop, and company TFS screening

**Status:** SPEC — for Johan's approval. **No code is written until this is approved.** (AT-480.)
**Written:** 21 Oct 2026, from the AT-480 investigation of QA1 (`8391c89a7`, gates re-checked on `cca1f92eb`) and **Johan's nine rulings of 21 Oct 2026** (§2).
**Pillars:** **Contact** (primary — FICA state), **Property** (which stock is "active"; the marketing gate), **Deal/Lease** (the rental gates), **Agent** (who declares, who chases). Compliance module: RO/CO approval path is reused, not replaced.
**Master references:** `.ai/specs/compliance.md` ("The FICA gate"), `.ai/specs/contact-entity-type.md`, `.ai/specs/rental-takeon-import.md` (§R6a company people), `.ai/BUILD_STANDARD.md` §1-§9.
**Branch:** `AT-480-fica-imported-contacts-spec`.

> **In one paragraph.** At HFC, contacts came in through imports with **no FICA at all**, and the one import "bypass" that exists
> stamps the *property* as compliant for ever, so agents renewed mandates, linked those contacts and advertised without ever being asked for
> FICA — and nothing in CoreX could show who was affected. This spec gives every import a **per-contact choice** (offline-declared /
> send online FICA / sort out later), starts a **grace clock** for "later", puts every imported contact on **one screen** with a path to
> finish their FICA, and then **blocks** the next action (renewals, mandate changes, linking to active stock, going live) once the clock
> has run out. The P24 "mark compliant" stamp stops being permanent. Company TFS screening is extended from the company's name to the
> company **and its directors**.

---

## 1. What exists today (verified on QA1) — the facts this design rests on

### 1.1 There is no contact-level bypass; the bypass is on the PROPERTY
| Import path | FICA flag | Where | What a created contact ends up with |
|---|---|---|---|
| **Property24 listings import** | `p24_import_runs.mark_compliant_on_confirm` (checkbox, **ticked by default**, `resources/views/admin/importer/index.blade.php:120`) | saved `Admin/ImporterController.php:324`; applied per row `Jobs/ConfirmP24PropertyRowJob.php:281-295` (ACTIVE rows only) | creates **no contacts**. Stamps the **property**: `compliance_snapshot_at = now()`, `first_marketed_at`, `compliance_snapshot_data.source = 'p24_go_live_migration'` |
| Contact import | none; policy comment "never auto-approved" `CoreX/ContactImportController.php:320-323` | `routes/web.php:6480` | no `fica_submissions` row → **"No FICA on file"** |
| Rental take-on (CoreX template) | none | `RentalTakeOnConfirmService::createContact` | no submission → "No FICA" |
| reOS import (AT-479) | none | `ReosTenancyConfirmService` | no submission → "No FICA" |
| `MigrateLegacyLeases` | none | tenants via `TenantContactResolver::matchOrCreate` (`MigrateLegacyLeases.php:213`) | no submission → "No FICA" |
| Sold-property importer, Propcon | none | `listing_stocks` tables exist, no writer | creates no contacts |

A contact's FICA state is **derived only** from `fica_submissions` (`FicaGate::stateFor`, `app/Services/Compliance/FicaGate.php:55`). There is **no column on `contacts` or `properties`** that records "bypassed".

### 1.2 The property stamp is permanent
`compliance_snapshot_at` is never cleared by any code path (searched). `MarketingReadinessService::statusFor` (`app/Services/Compliance/MarketingReadinessService.php:79`) returns *ready* on the stamp **before** it looks at any seller or document (`:95-103`), `isMarketable` (`:214`) likewise (`:220`), so every marketing gate passes for ever. The Command Centre "Listings pending marketing" card even **excludes** stamped properties (`CommandCenter/CommandCentreService.php:1673`).

### 1.3 The FICA model
`fica_submissions` (+ `fica_documents`, `fica_submission_documents`, `fica_status_history`, `fica_resend_logs`, `fica_tfs_screenings`, `fica_officer_appointments`). Status enum: `draft, submitted, under_review, agent_approved, corrections_requested, approved, rejected, cancelled, referred_to_co`; `intake_type` online | wet_ink; `fica_expires_at`. **Two definitions of "done" exist and must converge (§3.3):** `FicaGate::OPEN_STATUSES` (submitted or later — signing/rentals warnings, `FicaGate.php:35`) vs **approved-only** (`MarketingReadinessService::checkSellersFicaSubmissions` `:527`, `Contact::ficaStatus()` `Contact.php:434`, mobile, the property page chip).
- **Online send:** `Compliance/FicaController::store` (`:163`, mail `:222`), `resend` (`:893`), `requestCorrections` (`:861`) → `app/Mail/FicaRequestMail.php`; the client completes `/fica/{token}` (`FicaPublicController`, `routes/web.php:8096-8100`). Permission `compliance.fica.send` (`config/corex-permissions.php:350`).
- **Wet-ink / uploaded docs:** `FicaController::createWetInk` (`:237`), `storeWetInk` (`:275`), `agentUpload` (`:1138`), `linkContactDocuments` (`:1282`). The **PDF splitter** files FICA slots through `Tools/PdfSplitterController::FICA_SLOT_TO_DOC_TYPE` (`:41`; routes `routes/web.php:1687-1696`).
- **Approval:** `agentApprove` (`:505`) → optional `referToCo` (`:763`) → `complianceApprove` (`:576`; Compliance Officer only; TFS guard `tfsApprovalGuard` `:457`; self-approval separation; stamps `verified_at`, `fica_expires_at = now + FicaWindows::validityMonths`). Reject `:689`, return `:799`.
- **Agency windows:** `FicaWindows` (`app/Services/Compliance/FicaWindows.php`): link 14 d, current 11 mo, validity 24 mo, expiring-soon 60 d; saved by `FicaOfficerAppointmentsController::saveWindowSettings` (`:191`); in the Setup Wizard (`config/agency-onboarding-copy.php:1859`).
- **TFS:** `TfsScreeningService::screen` (`:27`), `screenIfNeeded` (`:127`), `subjectOf` (`:237-250`) — **a company is screened by its NAME ONLY** (from `form_data.entity.company_name`); it does **not** read `contact_representatives`. Table `fica_tfs_screenings` has `subject_kind` (individual|entity), one row per screen.

### 1.4 Every FICA gate today (verified) — and the gaps
| # | Action | file:line | Block / Warn | Stamped property | Contact with no FICA |
|---|---|---|---|---|---|
| G1 | External signer opens the signing page | `Docuperfect/SigningController.php:157-208` (`FicaGate::isOpen`) | **BLOCK** (only if `fica_required` and a linked `contact_id`) | n/a | blocked |
| G2 | E-sign send | `ESignWizardController.php:3617-3680` | warn + auto-creates a draft FICA | n/a | draft created |
| G3 | Signing page save/complete endpoints | `SigningController` (various) | **no check** (render-only gate) | n/a | not gated |
| G4 | Lease create / hub | `CoreX/LeaseController.php:339`, `:756`, `ficaWarningsFor :929` | **WARN** | n/a | warns |
| G5 | Lease activation, capture, signing launch | `LeaseActivationService`, `LeaseCaptureService`, `LeaseSigningLauncher` | **none** | n/a | not gated |
| G6 | **Lease renewal** (create / store) | `CoreX/LeaseRenewalController.php:42`, `:73`; `LeaseRenewalService::createRenewalTerm :40`, `activateRenewalTerm :105`; API `Api/V1/LeaseRenewalApiController` | **none** | n/a | not gated |
| G7 | Rental application hand-off / approve | `Services/RentalApplications/AuthorisationHandOffGate.php:38-47`; `RentalApplicationAuthorisationController.php:447-455`, `:511-523` | BLOCK only if agency setting `require_fica_before_authorisation` (default **off**); else warn "approved subject to FICA" | n/a | per setting |
| G8 | Property go-live | `PropertyController::goLive :3708-3735`; mobile `Api/MobilePropertyController.php:1086` | **BLOCK** (sellers must be `approved`) | web: "already live"; **mobile re-stamps and overwrites the import note** | blocked |
| G9 | Website publish toggle | `PropertyController.php:2364` (`enforceMarketingReadiness`) | BLOCK | passes | blocked |
| G10 | P24 enable/submit/reactivate | `Property24/P24SyndicationController.php:47, 78-80, 162-164` | BLOCK | passes | blocked |
| G11 | Private Property enable/submit/reactivate | `PrivateProperty/SyndicationController.php:44-46, 86-88, 193-195` | BLOCK | passes | blocked |
| G12 | Facebook/Instagram, scheduled posts, syndication panel | `PropertyMarketingController.php:98-99`; `ScheduledPostService.php:331`; `ScheduledPostPublisher.php:246`; `Api/PropertySyndicationPanelController.php:31-39` | BLOCK | passes | blocked |
| G13 | **Website syndication** (own-website enable/activate, bulk) | `Website/WebsiteSyndicationController.php:49-50, 62-63` | **only "not draft" + "approval" — NO readiness check** | n/a | **not gated (gap)** |
| G14 | P24/PP **service layer** + `BulkSyndicateP24/PP` | `Property24SyndicationService::submitListing :159-182`; `PrivatePropertySyndicationService::submitListing`; the two commands | readiness explicitly "not an additional gate" | n/a | **not gated (gap)** |
| G15 | Auction publish / bidder | `Auctions/AuctionPublishGate.php:48`; `BidderGateService.php:31`; `AuctionBidderController::verifyFica :147` (sets `fica_verified_at` without reading any FICA record) | block | passes | a click verifies |
| G16 | **Linking a contact to a property** | `CoreX/PropertyContactController::link :103`, `createAndLink :171`, `createAndLinkEntity :335`; `ContactPropertyLinker::link :50` (≈20 callers: ESign wizard, lease capture, take-on imports, lead services, wizard…) | **none** | n/a | not gated (gap) |
| G17 | **Mandate expiry change / renewal** | `PropertyController.php:1895-1900` (`MandateExpiryPolicy::isLockedChange`, `Services/Properties/MandateExpiryPolicy.php:230`); `ExpireMandates` only expires | extension-document lock only; **no FICA check** | n/a | not gated (gap) |
| G18 | Display only | `CommandCentreService.php:1673`; `PropertyIntelligenceService.php:975`; contact chips `FicaQuickState`; `Contact::ficaQuickStatesFor` | warn/display | chip ignores the stamp | "No FICA" chip |

Global/agency switches: `DevSetting compliance_checks_disabled` (turns off the property marketing gate everywhere, `MarketingReadinessService.php:82,216,420`); the marketing FICA check only runs if the agency lists `fica` among its **required document types** (`AgencyComplianceDocTypeService::requiredTypesFor :23`); `require_fica_before_authorisation`, `require_fica_before_paddle`, per-recipient `fica_required`.

### 1.5 "Is there a screen showing bypassed contacts?" — **No.**
Neither stamped properties (the only trace is the unread JSON `compliance_snapshot_data.source`) nor contacts with no FICA are listed anywhere (the FICA index lists *submissions*, so a contact with none is invisible; the contact list has a per-row chip but no filter).

### 1.6 What exists for "linked to ACTIVE stock / ACTIVE rental"
Stock: `Property::scopeOnMarket` (`Property.php:218`, status not in `OFF_MARKET_STATUSES :69`), `scopeOwnStock :152`, `scopeLiveOnAnyPortal :2477`, `scopeTransactionLive :2292`. Contact↔property: `Contact::properties()` (`Contact.php:817`, pivot `contact_property`, roles `Property::pivotRolesForContactRole :1527`; sellers/landlords = `owner, seller, landlord, lessor`, `MarketingReadinessService::sellerContactIds :504`). Rentals: `leases.status = 'active'` (not deleted), tenants via `lease_tenants` (`Lease::tenantContacts()`), landlords via `Lease::landlordContacts()`. **There is no single "contact is on active stock or an active rental" scope — §3.7 adds it.**

---

## 2. Johan's rulings (21 Oct 2026) — binding

1. On **any contact import** (sales and rentals: contact import, rental take-on, reOS, P24 listing import) the agency picks **per contact, or for all at once**, one of:
   **(a)** *FICA held offline, agency declared* — compliant with a distinct status **"Agency declared – FICA held offline"**; records who declared and when.
   **(b)** *Send online FICA now* — existing `FicaRequestMail` flow; **only when the user presses it, never automatically during the import.**
   **(c)** *Put on the "sort out later" list* — starts a **grace period of X days**; X is an **agency setting, admin-set, default 30**; the import screen **warns that the clock starts**.
2. The source system's FICA status (e.g. the reOS FICA Compliance report) is captured **as reference only** and **never counts as CoreX compliance**.
3. New screen **"Imported Contacts FICA"**: list of contacts created/linked by imports with their choice, days left and status; open a contact to **upload FICA docs through the existing PDF splitter** (the one rental-application docs use) then **mark compliant through the normal RO/CO approval path**; **bulk "Send online FICA"**; full design standard (search, sort, filter, pagination, empty state, OWN/BRANCH/AGENCY scoping); **nav link under Compliance**.
4. When the grace period **expires**, the contact becomes **FICA outstanding** and the **next action is BLOCKED (not a warning)** until FICA is done: no lease renewal unless FICA is done for the outstanding contacts; no changing/extending a mandate expiry; no linking that contact to an active property; no going live or syndicating (website included) for properties whose sellers or landlords are outstanding.
5. **Scope:** only contacts on **ACTIVE stock / ACTIVE rentals**, or **later used on an active property**. Thousands of old imported contacts are never chased.
6. **Admin override** (so nobody has to "call CoreX to disable FICA"): an admin can change the FICA status or extend the grace period, for **IMPORTED contacts only**, from that screen. **Audit log:** who, when, from/to, reason (**required**).
7. The P24 import "mark compliant" stamp must **stop being a permanent bypass**. Spec how stamped properties feed this same flow (their sellers get the same three choices) and whether to turn the checkbox's default off.
8. **Close the gaps found:** the website-syndication readiness check, and a FICA check on contact-to-property linking and mandate renewal, per ruling 4.
9. **Company TFS screening covers the company AND its directors** (`contact_representatives`) — Johan's 16 Oct wish. Own section (§3.14).

---

## 3. Design

### 3.1 The states of an imported contact (one state machine)
`imported_contact_fica.state`:

| State | Meaning | Counts as FICA-satisfied for gates? |
|---|---|---|
| `pending_choice` | Created/linked by an import; nobody has chosen yet | **No** — but nothing is blocked until it is *used* (§3.7/§3.8: using it forces the choice) |
| `declared_offline` | (a) — "Agency declared – FICA held offline" (who, when, optional note) | **Yes** |
| `online_requested` | (b) pressed — a `fica_submissions` row exists (draft/submitted/…) | **Yes once the submission is `submitted` or later** (FicaGate OPEN) for sales/rentals *warnings and signing*; the **hard-stop gates (§3.8) need `approved` OR `declared_offline`** |
| `grace` | (c) — clock running, `grace_ends_at` in the future | **Yes while the clock runs** (it is permission to proceed, not compliance) |
| `outstanding` | grace expired (or never chosen and forced), no FICA done | **No — gates BLOCK** |
| `compliant` | a real FICA reached `approved` (normal RO/CO path) | Yes |
| `dormant` | not on active stock/rental and not later used (ruling 5) | n/a — **never chased, never counted**; hidden from the default list view |

Transitions: `pending_choice → declared_offline | online_requested | grace` (agent/admin choice); `grace → outstanding` (clock, nightly job §3.6); any → `compliant` (a FICA submission is approved — observer on `FicaSubmission`); `outstanding/grace/online_requested → declared_offline` (admin override §3.9 or the agent's declaration); `dormant ↔ pending_choice` (when the contact becomes used on an active property/lease).

### 3.2 Data model (new migrations)
**`imported_contact_fica`** — one row per contact per agency (the *current* FICA-import state of an imported contact):
```
id
agency_id                      NOT NULL  (BelongsToAgency)
branch_id                      nullable  (of the importing user, for BRANCH scope)
contact_id                     NOT NULL  FK contacts; UNIQUE (agency_id, contact_id)
source                         varchar(30) NOT NULL   -- contact_import | rental_take_on | reos_tenancy | p24_stamped | other
source_run_type / source_run_id nullable              -- e.g. RentalTakeOnImportRun, P24ImportRun, the contact-import batch
imported_by_user_id            nullable FK users
imported_at                    timestamp NOT NULL
state                          varchar(20) NOT NULL DEFAULT 'pending_choice'   -- §3.1
choice_by_user_id / choice_at  nullable               -- who made the (a)/(b)/(c) choice, when
declaration_note               varchar(500) nullable  -- (a): optional free text ("held in the paper file, cabinet 3")
fica_submission_id             nullable FK fica_submissions  -- (b)/compliant: the submission that carries it
online_sent_at                 nullable
grace_days                     smallint nullable      -- SNAPSHOT of the agency setting at the moment the clock started
grace_started_at / grace_ends_at   nullable
source_fica_status             varchar(60) nullable   -- REFERENCE ONLY (ruling 2): e.g. "Compliant" / "Expired" as the source system said
source_fica_note               varchar(500) nullable
source_fica_captured_at        nullable
last_override_at               nullable
created_at, updated_at, deleted_at   -- SoftDeletes (archive, never hard delete)
INDEX (agency_id, state, grace_ends_at)    INDEX (contact_id)
```
**`imported_contact_fica_log`** — the audit trail (append-only; also the override log, ruling 6):
```
id, imported_contact_fica_id, agency_id, contact_id,
event            varchar(30)  -- created | choice_declared | choice_online | choice_later | grace_expired | online_sent | fica_approved
                              -- | override_status | override_grace | became_active | went_dormant | bulk_choice
from_state, to_state          varchar(20) nullable
from_grace_ends_at, to_grace_ends_at   nullable
actor_user_id    nullable (null = the system / the nightly job)
reason           varchar(500) nullable   -- REQUIRED for override_* (server-enforced)
metadata         json nullable
created_at
```
Plus the existing `fica_status_history` is still written when a real `fica_submissions` row changes state — this spec does not touch it.

**Settings columns on `agencies`** (nullable = "use the default", the `FicaWindows` pattern): `fica_import_grace_days` (default **30**, min 1, max 365). Optional later: `fica_import_default_choice` (see Decision D2).
**`p24_import_runs`**: no schema change; `mark_compliant_on_confirm` stays but its meaning narrows (§3.10).
**`fica_tfs_screenings`** (§3.14): add `subject_contact_id` (nullable FK), `subject_role` varchar(30), `subject_source` varchar(30).

### 3.3 ONE FICA status service (converge the two definitions)
New `App\Services\Compliance\ContactFicaStatus` (the single answer used by every gate and screen):
- `forContact(int $contactId): array{state, satisfied_soft, satisfied_hard, label, reason, days_left, imported:bool}`.
- **soft** = `FicaGate::isOpen` **OR** `declared_offline` **OR** `grace` (not expired) **OR** `online_requested`+submitted — used by the *warning* places (G2, G4, G7 warn text) and signing.
- **hard** = `approved` **OR** `declared_offline` **OR** `grace` (not expired) — used by the *blocking* gates in §3.8. A contact that is **not imported** keeps today's behaviour exactly (hard = `approved`, soft = FicaGate OPEN): **nothing changes for contacts that did not come in through an import** (ruling 5/6: imported contacts only).
- `FicaGate`, `MarketingReadinessService::checkSellersFicaSubmissions`, `Contact::ficaStatus()`/`ficaQuickState` call this service for the imported-contact cases, so "Agency declared – FICA held offline" shows everywhere a FICA state shows (contact chip, property page chip, mobile `fica_status`, signing, rentals) — new chip tone **info/grey: "Agency declared – FICA held offline"**; and **"FICA outstanding — blocked"** (danger) for `outstanding`.

### 3.4 Capturing the choice at import (ruling 1)
A shared Blade partial `corex/compliance/_import-fica-choice.blade.php` + `ImportedContactFicaService::register(Contact, source, run, user)`; every importer calls `register()` for each contact it **creates or links** (state `pending_choice`).
| Import | Where the choice is offered | Per contact / all at once |
|---|---|---|
| **reOS import** | review screen `preview-reos.blade.php`: a "FICA for these contacts" bar (apply to all ticked) + a per-party select in the owner/tenant cell (next to "Use the contact already in CoreX…") | both; applied at Import time **in the row's transaction**, after the contact exists |
| **Rental take-on (CoreX template)** | `take-on-import/preview.blade.php` header bar + per-row select | both |
| **Contact import** | `ContactImportController` — the pre-import options screen (new "FICA for these contacts" radio) | all at once (one choice for the file), changeable later on the new screen |
| **P24 listing import** | the P24 importer creates no contacts; the choice is offered **to the sellers of the stamped properties** from the new screen (§3.10) | per contact / all |
- Options shown (plain words): **"FICA is held offline — we declare it"**, **"Send them an online FICA form"** (button only — *never auto-sent during the import*, ruling 1b; at import it only **queues the intent**: state `pending_choice` with `intent = send_online`; the actual `FicaRequestMail` goes out when someone presses **Send** on the new screen or in the import's result panel), **"Sort out later — starts a {X}-day clock"**.
- The **warning for (c)** is shown on the import screen itself, in the agency's own number: *"Choosing 'sort out later' starts a 30-day clock for each of these contacts. When it ends, anything that needs their FICA (renewing a lease or mandate, linking them to a property, going live) is blocked until their FICA is done."*
- Default selection: **none** (Decision D2). The import can proceed with `pending_choice` (nothing is blocked until a contact is *used*, §3.7); the review screen shows a count "n contacts have no FICA choice yet".

### 3.5 Source-system FICA status = reference only (ruling 2)
reOS "FICA Compliance" (and any equivalent column) is parsed into `source_fica_status` / `source_fica_note` / `source_fica_captured_at` (reOS adds the report as a third optional upload — a follow-up to AT-479 R2, not built here; the column simply exists for any importer to fill). It is shown on the new screen as **"Source says: Compliant (reOS) — reference only"** and **never** feeds `ContactFicaStatus`. A test asserts that a row with `source_fica_status = 'Compliant'` and no choice is still `pending_choice`/not satisfied.

### 3.6 The grace clock and the nightly job
- `grace_ends_at = grace_started_at + grace_days` (snapshot of the agency setting; a later settings change does not move running clocks — only an admin override does).
- **`fica:imported-expire`** (new command, `routes/console.php`, daily 06:00, `withoutOverlapping`): sets `grace → outstanding` where `grace_ends_at < now`, writes `grace_expired` to the log, fires a domain event (§3.16). Also re-evaluates `dormant ↔ pending_choice` for contacts whose active-use status changed. Idempotent, `--dry-run` default-off for QA.
- **Scheduler caveat (BUILD_STANDARD §8 / promotion note):** QA sites run no scheduler and Staging's cron was off from 21 Aug — so every gate **also evaluates expiry lazily** (`ContactFicaStatus` treats `state = grace AND grace_ends_at < now` as `outstanding` at read time); the job only makes the stored state and the log catch up. The feature therefore works on QA1 and Staging without cron.
- **Days left** on the screen = `ceil(grace_ends_at - today)`; red at ≤ 7; "Overdue by n days" after.
- **Reminders** (no mail to real addresses in tests): 7 days and 1 day before expiry, and on expiry, an in-app notification to the importing agent and the branch manager through the existing `NotificationDispatcher` (new event types registered by migration, `register_*_notification` pattern). Outbound mail goes only through the existing guarded mailers.

### 3.7 Who is chased — ACTIVE scope (ruling 5)
A contact is **relevant** (chased; shown by default; gates apply) when it is, right now, any of:
1. a **seller/landlord** (pivot roles `owner|seller|landlord|lessor`, `contact_property.deleted_at IS NULL`) of a property that is `Property::onMarket()->ownStock()` (not deleted), **or** a property with an active mandate (`Property::scopeTransactionLive`);
2. a **landlord** or **tenant** of a lease with `status = 'active'` (not deleted) — `Lease::landlordContacts()` / `lease_tenants`;
3. **about to be used** on such a property or lease: the moment someone tries to link it (G16), renew (G6) or go live (G8) — §3.8 forces the choice at that moment.
New query object `ActiveFicaScope` (`App\Services\Compliance\ActiveFicaScope::contactIdsQuery(agencyId)`; `isRelevant(Contact)`): the union of (1) and (2). Everything else is `dormant`: **never chased**, no clock, no block, not in the default list (a "Show dormant" filter exists for the admin).
Performance: the nightly job and the list use set-based queries on `contact_property`, `leases`, `lease_tenants` with the existing indexes; no per-contact loop over thousands of old contacts.

### 3.8 The gates — every one, file:line, block vs warn (rule 4 + rule 8)
"Outstanding" below = `ContactFicaStatus` hard-not-satisfied for an **imported** contact. Non-imported contacts: **unchanged** behaviour. Every BLOCK shows the same plain message and the same three-choice panel (declare / send online / sort out later — the latter only if the contact is `pending_choice`; an `outstanding` contact can only be fixed by completing FICA or by an admin override), links to the new screen, and is **logged** (`gate_blocked` in the log table with the action name).

| # | Action | Gate placement (file:line today) | New behaviour |
|---|---|---|---|
| N1 | **Lease renewal** (create + store, web + API) | `CoreX/LeaseRenewalController.php:42` (`create`) and `:73` (`store`); `LeaseRenewalService::createRenewalTerm :40` (the choke point — API and web both pass through it); `activateRenewalTerm :105` | **BLOCK** if any tenant or landlord of the lease is `outstanding` (imported). A `pending_choice` imported party → the screen shows the choice panel first (the renewal *page* opens; the *store* is the hard stop). |
| N2 | **Mandate expiry change / extension** | `PropertyController.php:1895-1900` next to `MandateExpiryPolicy::isLockedChange`; also any other writer of `properties.expiry_date` found by the phase-5 grep (`PropertyController.php:1425` create, `:2074-2085` takeover) | **BLOCK** a change of `expiry_date` on a property whose sellers/landlords include an `outstanding` imported contact, or a `pending_choice` one (forces the choice). A rejected save leaves no orphan upload (same discipline as `isLockedChange`). |
| N3 | **Linking a contact to a property** | `ContactPropertyLinker::link :50` is the choke point (≈20 callers) → new `FicaLinkGuard::assertCanLink($contact, $property, $role)` called at the top of `link()` **for seller/landlord/owner/lessor roles on an active property**, plus the three UI entries `PropertyContactController::link :103`, `createAndLink :171`, `createAndLinkEntity :335` (to return a 422 with the panel instead of a stack trace) | **BLOCK** when the contact is `outstanding`; when `pending_choice` and the property is active → the panel forces the choice (a, b or c) and then the link proceeds. **Imports link inside the importer's own transaction and are not blocked by it** (they *register* the contact, state `pending_choice`; the guard has an explicit `via_import` allow so an import never fails half-way — ruling 1 says choose *after*). Tenants on leases: guarded at lease creation `LeaseCaptureService.php:974` the same way. |
| N4 | **Go live / syndicate** | the existing readiness gate: `MarketingReadinessService::statusFor :79` → `checkSellersFicaSubmissions :527` now asks `ContactFicaStatus` **hard** (so `declared_offline` and in-grace sellers pass; `outstanding` sellers fail with "FICA outstanding — {names}"). Callers already enforcing it: `goLive :3708`, mobile `sendToMarket :1086`, `publishToggle :2364`, `P24SyndicationController :47,78,162`, `PrivateProperty/SyndicationController :44,86,193`, `PropertyMarketingController :98`, `ScheduledPostService :331`, `ScheduledPostPublisher :246`, `PropertySyndicationPanelController :31`, `AuctionPublishGate :48`, `SyndicationApprovalService :139,:254`, public pages `PublicAgencyPropertiesController :67` | **BLOCK** for outstanding sellers/landlords — and **the stamp no longer short-circuits this one check** (§3.10). |
| N5 | **Website syndication (the gap)** | `Website/WebsiteSyndicationController.php:49-50, 62-63` and `bulkEnable` — add `enforceMarketingReadiness($property)` (the same trait method P24/PP use, `Concerns/EnforcesMarketingReadiness.php:55`) | **BLOCK** exactly like P24/PP. Also the public `Api/V1/Website/ListingsController` keeps only its status block-list (it is a read path). |
| N6 | **Service-layer / bulk commands (the gap)** | `Property24SyndicationService::submitListing :159-182`, `PrivatePropertySyndicationService::submitListing`, `BulkSyndicateP24`, `BulkSyndicatePP` | **BLOCK** (the service-layer check is the one that cannot be bypassed from a command or a job). The commands today say compliance is "NOT an additional gate" — that comment is superseded for *outstanding imported sellers only* (the rest of the readiness checks stay out of the service layer so existing bulk behaviour is unchanged). |
| N7 | Rental application hand-off / approve | `AuthorisationHandOffGate.php:38-47` | **unchanged** (setting-driven). Imported-tenant states feed `ficaGateDescribe` so the warning text can say "Agency declared – held offline". |
| N8 | Signing (G1-G3) | `SigningController.php:157-208` | **unchanged for external signers** (the FicaGate OPEN rule). `declared_offline` counts as open so a declared tenant is not sent a FICA form to sign. G3 (endpoints with a valid token) is a separate existing gap — **reported, not changed here**. |
| N9 | Mobile `sendToMarket` re-stamp | `MobilePropertyController.php:1086` | stops overwriting an existing stamp/`compliance_snapshot_data.source` (it currently re-snapshots a stamped property); keep the import note. |

**Block message (one wording):** *"{Name} came in through an import and their FICA isn't done. {Action} is blocked until it is. [Open Imported Contacts FICA]"* — never a stack trace (BUILD_STANDARD §4); validation errors for form posts, 422 JSON for API/mobile.

### 3.9 Admin override (ruling 6)
On the new screen only, for **imported** contacts only, permission `fica_import.override` (admin/owner roles by default):
- **Change FICA status** to `declared_offline` (with the declaration note) or back to `pending_choice`/`grace`; **extend grace** (set a new `grace_ends_at`, +N days or a date, max +365 per action).
- A **reason is required** (server-enforced; 10-500 characters); every override writes `imported_contact_fica_log` (`override_status` / `override_grace`: actor, time, from/to, reason) and a `fica_status_history`-style entry on the contact's audit trail (`ContactAuditService`, `AT-321`). The screen's contact drawer shows this history.
- An override **never** creates or approves a `fica_submissions` row (the real RO/CO path is unchanged); it changes only the import-state, so audits can tell "approved by the CO" from "declared by the agency" from "admin extended".
- No code path may blank the audit trail; overrides are non-deletable (archive only).

### 3.10 The P24 "mark compliant" stamp stops being a permanent bypass (ruling 7)
1. **The checkbox default → OFF**, and the copy changes. **Recommendation: yes, turn the default off.** Today it is ticked (`index.blade.php:120`), so every go-live import silently stamps all active stock; the people who tick through never decide. New copy: *"These listings are already live and compliant — don't ask for their documents again. (Sellers' FICA is still checked — see Imported Contacts FICA.)"* — the box now only means "skip the document/photo/details checks for these listings", **not** "skip FICA".
2. **The stamp no longer short-circuits the seller FICA check.** `statusFor :95-103` and `isMarketable :220`: if `compliance_snapshot_data.source = 'p24_go_live_migration'` the snapshot still clears documents/photos/details, but the **seller FICA** part is evaluated through `ContactFicaStatus` (N4). Other snapshots (a genuine go-live via `snapshotCompliance`, `v2`) are unchanged — the stamp is only "permanent" for the full snapshot taken after real checks.
3. **Backfill / feeding the same flow:** `fica:register-stamped-sellers` (new command, dry-run default): for every ACTIVE property with `compliance_snapshot_data.source = 'p24_go_live_migration'`, register each linked seller/landlord (`sellerContactIds`) in `imported_contact_fica` with `source = 'p24_stamped'`, `state = pending_choice` → they appear on the new screen with the same three choices. Run once per agency on deploy, and re-run by the nightly job for sellers linked **after** the import (so a seller linked to a stamped property later is registered the moment they are linked — N3 does this at link time).
4. **Stamped property with no seller linked** (P24 exports carry none): it is listed on the new screen under a separate tab **"Stock with no seller on file"** (a data-quality list; nothing to verify, nothing to block). Decision D3: whether to also block syndication re-submission after the grace window for these — *recommended: no*, there is no person to verify.
5. **Existing stamped properties are not re-opened or de-listed.** They stay live; only the FICA of their sellers is now tracked and, after the grace window, enforced.
6. The Command Centre card "Listings pending marketing" (`CommandCentreService.php:1673`) and the property chip `PropertyIntelligenceService.php:975` are updated to read `ContactFicaStatus` so they stop contradicting each other (the chip no longer says "Outstanding" on a stamped property whose sellers are declared).

### 3.11 The screen "Imported Contacts FICA" (ruling 3)
- **Route / nav:** `GET /corex/compliance/imported-contacts-fica` (`compliance.imported-fica.index`), a new sub-item **"Imported Contacts FICA"** under **Compliance** in the sidebar group at `resources/views/layouts/corex-sidebar.blade.php:2120-2137` (next to "FICA"), inside `@feature('compliance')`, gated by `fica_import.view`, with a **features On/Off** entry (STANDARDS "every sidebar item ships with a features toggle"): `corex-features.php` key `imported-contacts-fica`, `depends_on ['compliance']`, `route_prefixes ['corex/compliance/imported-contacts-fica', 'api/v1/compliance/imported-fica']`.
- **Rows:** contacts registered in `imported_contact_fica` (relevant by default). Columns: contact (name, kind chip), source (CoreX template / reOS / contact import / P24 stamped) + import date, linked to (property address / lease, with Active chip), **FICA choice**, **days left** (red ≤ 7, "overdue" after), **status** (pending choice / declared offline / online requested / in grace / outstanding / compliant), source's own FICA status (reference, muted), agent.
- **Search (named fields):** contact name, phone, e-mail, ID/registration number, property address, source file name, agent name.
- **Sort:** contact name, source, imported date, days left, status, agent. **Default: days left ascending** (most urgent first; `outstanding` first of all, then nearest expiry, then `pending_choice`).
- **Filters:** status (all the §3.1 states), choice (a/b/c/none), source, agent, branch, date range (imported date), "expires within 7 / 30 days", "on active stock", "on active rental", "show dormant" (admin), plus a tab **"Stock with no seller on file"** (§3.10).
- **Pagination** 25 per page, selection persists across pages (server-held, like the reOS review); **empty states**: "no imported contacts need FICA attention" vs "none match these filters".
- **Row actions:** *Declare held offline* (note), *Send online FICA* (single), *Sort out later* (starts clock), *Open* (drawer: FICA state, linked properties/leases, source reference, audit history, **Upload FICA documents** → the PDF splitter, **Open submission**), and for admins *Override…* (§3.9).
- **Bulk actions** (on ticked rows / "all matching the filter"): **Send online FICA**, **Declare held offline** (one note for all), **Start grace**. Each bulk action reports "n sent, m skipped (already compliant / no e-mail address)". **Bulk send is the only mass mailer in this feature and it is user-pressed, rate-limited (≤ 50 per click, queued through the existing `FicaController::store` logic per contact), and skips contacts with no e-mail.**
- **Scoping (BUILD_STANDARD §1c) — enforced at the query layer:** `ImportedContactFica::scopeVisibleTo($user)` mirrors `FicaSubmission::scopeVisibleTo` (Role-Manager `fica.view` own/branch/company ceiling, `FicaSubmission::ficaScopeFor`): **OWN** = contacts the user imported or is the agent of; **BRANCH** = `branch_id` = the user's; **AGENCY** = all of the agency (owners pinned to the working agency, as `RentalDataScope::pinOwnerToWorkingAgency`). Every detail view, drawer, document upload, override, bulk action and JSON endpoint re-checks it by id → **404** otherwise; the model also uses `BelongsToAgency`. A contact's name/phone are not exposed to a user whose Contacts scope would hide it (reuse `Contact` visibility for the drawer).
- **API (non-negotiable #7):** `GET /api/v1/compliance/imported-fica` (list), `POST .../{id}/choice`, `POST .../{id}/send-online`, `POST .../bulk`, `POST .../{id}/override` — named `api.v1.compliance.imported-fica.*`, listed in Admin → API, session auth, same permission and scope checks.

### 3.12 Uploading FICA docs and finishing through the normal approval path (ruling 3)
From the drawer, **Upload FICA documents** opens the existing PDF splitter pre-filled for the contact: `tools.pdf_splitter.index` (`routes/web.php:1687`) with the contact as the destination and the FICA slots (`FICA_SLOT_TO_DOC_TYPE`: `fica_form`, `id → id_copy`, `por → proof_of_address`, `PdfSplitterController.php:41`). That is the same splitter the rental-application documents use. After filing, the screen offers **"Start wet-ink FICA review"**: it creates (or reuses) a `fica_submissions` row with `intake_type = 'wet_ink'` through `FicaController::storeWetInk` (`:275`) and links the filed documents (`linkContactDocuments :1282`); from there it is the **normal path**: `agentApprove :505` → (`referToCo :763`) → `complianceApprove :576`. A `FicaSubmission` observer (existing `FicaSubmissionObserver`) flips `imported_contact_fica.state → compliant` and writes `fica_approved` to the log when the submission reaches `approved`. The RO/CO separation of duties (secondary officers cannot self-approve) is untouched.

### 3.13 "Send online FICA" — reuse, never automatic
`ImportedContactFicaService::sendOnline(Contact, User)` calls the **same code** as `FicaController::store` (extract the sending part into `App\Services\Compliance\FicaRequestSender`, used by both; `store` behaviour unchanged): creates the `draft` submission with a token (`FicaWindows::linkExpiryDays`), sends `FicaRequestMail`, writes `fica_resend_logs` as resend does, sets `state = online_requested`, `online_sent_at`. Skips a contact with no e-mail (reported). **Never called from an importer.** Mail guards: QA outbound is held by the existing outbound guard.

### 3.14 Company TFS screening: the company AND its directors (ruling 9)
Today (`TfsScreeningService::subjectOf :237-250`) a company submission screens **one subject — the company name** — and ignores `contact_representatives`. New behaviour:
- **Screening set** for a submission whose `entity_type != natural` = the **company/trust/partnership** + **every person linked to it through `contact_representatives`** (`Contact::representatives()`, `Contact.php:942` — directors, trustees, members, executors, "Other"/contact person) **+ any beneficial owners/directors typed on the FICA form** (`form_data.entity.beneficial_owners[]`, `trustees[]`, `partners[]` as `FicaPublicController::submit` collects them), de-duplicated by normalised name+ID.
- `fica_tfs_screenings` gets `subject_contact_id` (nullable), `subject_role` (`company|director|trustee|member|executor|other|beneficial_owner|partner`), `subject_source` (`entity|representatives|fica_form`). **One row per subject per screen run**; `fica_submission_id` stays the parent; existing single-subject rows remain valid (`subject_role` null = legacy).
- `screen()` (`:27`) loops the set; `screenIfNeeded()` (`:127`) fingerprint becomes the sorted hash of all subjects' `name|id`, so adding a director re-screens only the new subject (existing outcomes reused if the fingerprint of that subject is unchanged).
- **Outcome roll-up** for the submission = the worst of its subjects (`hit` > `review_required` > `error` > `passed`); `FicaController::tfsApprovalGuard :457` blocks CO approval until **every** subject is `passed` or a decided false-positive (`tfsDecision :421` per subject row, with the subject's name shown). The TFS report PDF (`tfsReport :474`) lists each subject and outcome.
- **Where the people come from for imported companies:** the reOS "People for this company" panel already writes `contact_representatives` (rental-takeon-import.md R6a), so an imported company arrives with its people; the Imported Contacts FICA drawer shows "People screened: n" and a link to add a director (the existing Representatives panel).
- Out of scope: screening the *directors' own FICA* as separate submissions (each director may later be asked for their own FICA through the same screen; not required for the company's approval).

### 3.15 Settings, permissions, Setup Wizard (non-negotiable #10a)
| Setting | Where | Default | Notes |
|---|---|---|---|
| `agencies.fica_import_grace_days` | Settings → Profile & Account → FICA time windows (beside the four `FicaWindows`), saver `FicaOfficerAppointmentsController::saveWindowSettings :191` extended; `FicaWindows::importGraceDays()` | **30** (min 1, max 365) | **Also in the Agency Onboarding Setup Wizard** (`config/agency-onboarding-copy.php` Compliance step, next to `fica_link_expiry_days :1859`) with `explain` ("how long an imported contact may be used before their FICA must be done") and `affects` ("when the 'sort out later' clock ends and actions start to be blocked"); the saver guards with `$request->has()` (spec `agency-onboarding-setup.md` §6.1). |
| `agencies.fica_import_default_choice` (optional, Decision D2) | same place | none | only if Johan wants a pre-selected choice; otherwise not built |
| feature toggle `imported-contacts-fica` | `config/corex-features.php` | on | |
**Permissions** (`config/corex-permissions.php`, module `fica`, section compliance): `fica_import.view` (see the screen — scoped by `fica.view`), `fica_import.manage` (declare / send / sort-out-later / bulk / upload), `fica_import.override` (admin override, §3.9). Defaults: owner/admin all three; branch manager view+manage within branch; agents view+manage for their own contacts. Added to the Role Manager and to `corex:sync-permissions` defaults.

### 3.16 Domain events (non-negotiable #9)
New events in `.ai/specs/corex-domain-events-spec.md` catalogue (`Fica\ImportedContactRegistered`, `Fica\ImportedContactDeclared`, `Fica\ImportedGraceStarted`, `Fica\ImportedGraceExpired`, `Fica\ImportedContactCompliant`, `Fica\ImportedOverrideApplied`). Listeners: notifications (§3.6), the Command Centre task "Sort out FICA for {name}" (a `CommandTask`, due at `grace_ends_at`), audit. `FicaApproved` (existing) is the trigger for `compliant`.

### 3.17 Input space — prevent or absorb (BUILD_STANDARD §2/§3)
| Input / situation | Decision |
|---|---|
| Import contact already has a real FICA (approved) | register as `compliant`, no choice asked |
| Contact imported twice (re-import) | one row (unique agency+contact); the first choice stands; re-import never resets a clock or a declaration |
| Choice made for a contact that is `dormant` | allowed; recorded; the clock only matters once relevant — **but the clock starts at the choice (ruling 1c)** and the screen says so |
| Bulk "send online" including contacts with no e-mail / opted out | skipped and listed, never an error; opted-out (`messaging_opt_out`) respected |
| Declaration with no note | allowed (note optional); who/when always recorded |
| Override without a reason / reason < 10 chars | rejected with a plain message; nothing changes |
| Override on a NON-imported contact | 404/422 "only imported contacts" |
| Grace days setting changed after clocks run | running clocks keep their snapshot; new clocks use the new value |
| Contact merged / archived | archived contacts drop off (soft delete), merged → rows follow the survivor (merge hook) |
| Lease with several tenants, one outstanding | renewal blocked, the message names who |
| Property with several sellers | all must be satisfied (soft-delete of a link removes them) |
| `pending_choice` contact linked inside an importer's transaction | allowed (`via_import`); registered |
| Nightly job not running (QA/Staging) | lazy expiry at read time (§3.6) |
| Mail failure on send | row stays `pending_choice`/unchanged, error shown per contact, retry works |

### 3.18 Tests (all of them on `Mail::fake()` / `Notification::fake()`; **no mail to real addresses — synthetic "ZZ TEST" contacts and `@example.test` only**)
State machine (every transition); each import registers contacts and never sends mail; the three choices incl. "(b) only on press"; source reference never satisfies the gate; grace snapshot & lazy expiry; ACTIVE scope (dormant contacts never chased, a contact becoming active becomes relevant); **each gate N1-N6 and N9: allowed when declared / in grace / approved, BLOCKED when outstanding, forced choice when pending, non-imported contacts unchanged**, with the block message; the website-syndication gap closed; the stamped property's sellers evaluated while documents stay cleared; checkbox default and copy; backfill command (dry-run + apply, idempotent); screen: search/sort/default/filters/pagination/empty states/scoping by direct-URL id (own/branch/agency, another agency 404), bulk send (skips, rate limit); override (reason required, audit rows, imported-only, permission); PDF-splitter hand-off creates a wet-ink submission and approval flips the state; TFS: company + directors + form owners, fingerprint, roll-up, approval guard, legacy rows.

### 3.19 Phases (each about a day, each shippable on its own)
| # | Phase | Ships | Depends |
|---|---|---|---|
| **P1** | **Foundation:** migrations (`imported_contact_fica`, `_log`, grace setting), models, `ContactFicaStatus`, `ActiveFicaScope`, `FicaWindows::importGraceDays`, settings UI + Setup Wizard control, permissions, feature toggle. No behaviour change yet (nothing registers or blocks). | invisible | — |
| **P2** | **Capture at import:** `ImportedContactFicaService::register`, the shared choice partial, hooked into reOS, rental take-on, contact import; (a)/(c) wired, (b) records intent; grace warning text; source-reference columns. | choice on imports | P1 |
| **P3** | **The screen:** list/search/sort/filter/pagination/scoping, drawer, single actions, API, nav link; `FicaRequestSender` extracted, **Send online** + bulk; domain events + reminders; `fica:imported-expire` + lazy expiry. | screen usable | P1-P2 |
| **P4** | **Documents + approval hand-off:** PDF-splitter pre-fill, wet-ink submission creation, observer → `compliant`; admin override + audit (§3.9). | finish a contact's FICA | P3 |
| **P5** | **The gates (blocking):** N1 lease renewal, N2 mandate expiry, N3 contact linking (+ forced-choice panel), N4 readiness via `ContactFicaStatus`, N9. Behind the per-agency feature toggle so it can be switched on agency by agency after the lists are cleaned. | the hard stop | P1-P4 |
| **P6** | **Close the syndication gaps + the stamp:** N5 website readiness, N6 service-layer/bulk; P24 checkbox default off + copy; stamp no longer bypasses seller FICA; `fica:register-stamped-sellers` backfill; Command Centre / chip consistency; "Stock with no seller on file" tab. | stamped stock tracked | P1-P3 |
| **P7** | **Company TFS: company + directors:** migration, `TfsScreeningService` loop/fingerprint/roll-up, approval guard, report PDF, drawer "People screened". | directors screened | independent (can run beside P2-P6) |

P7 is independent and can go first if Johan prefers; P5 should not be switched on for an agency before its P3 list has been worked through (feature toggle per agency).

### 3.20 Decisions for Johan (business questions — I have made the engineering calls)
- **D1 — Does choosing "(b) Send online FICA" also start the clock?** Recommended **yes**: otherwise a contact can sit at "requested, never submitted" for ever. They'd be blocked after the same X days if they still haven't submitted (hard gates need approved/declared/in-grace). 
- **D2 — Pre-selected choice on the import screens?** Recommended **none** (the agency must decide); a "default for this agency" setting can come later.
- **D3 — Stamped P24 stock with no seller on file:** list it only (recommended), or also block re-syndication after the window?
- **D4 — Grace for contacts that are `pending_choice` and get used:** the forced-choice panel appears at the moment of use (link / renew / go-live). Is "(c) sort out later" allowed there too (recommended yes — same X days) or only (a)/(b)?
- **D5 — Role for the P24/reOS "contact person"** is the existing "Other" capacity (no new vocabulary). OK, or add a "Contact person" capacity to `ContactRepresentative::CAPACITIES`?

### 3.21 Out of scope / reported, not changed
G3 (signing POST endpoints are not gated by FICA once a token is held) and the auction "verify FICA by a click" (`AuctionBidderController::verifyFica :147`); the mobile app's own FICA wording; contacts created outside imports (the normal contact form, lead capture) — they keep today's behaviour exactly; a FICA-expiry (24-month) regime for imported contacts beyond the first completion (the existing `FicaWindows` expiry applies once a real submission exists).

---

## 4. Files (new and modified, for the build)
**New:** migrations (2: `imported_contact_fica` + `_log`; `fica_tfs_screenings` subject columns; agency grace column; notification types); `app/Models/ImportedContactFica.php`, `ImportedContactFicaLog.php`; `app/Services/Compliance/ContactFicaStatus.php`, `ActiveFicaScope.php`, `ImportedContactFicaService.php`, `FicaRequestSender.php`, `FicaLinkGuard.php`; `app/Console/Commands/FicaImportedExpire.php`, `FicaRegisterStampedSellers.php`; `app/Http/Controllers/Compliance/ImportedContactsFicaController.php` + API controller; views `corex/compliance/imported-fica/{index,_drawer,_import-fica-choice}.blade.php`; events + listeners; tests under `tests/Feature/Compliance/ImportedFica/`.
**Modified:** `FicaGate`, `MarketingReadinessService` (`:79-103`, `:214-221`, `:527`), `Contact::ficaStatus/ficaQuickState`, `FicaController` (extract sender), `TfsScreeningService`, `FicaController::tfsApprovalGuard`, `LeaseRenewalService`/`LeaseRenewalController`, `PropertyController` (`:1895`), `ContactPropertyLinker`, `PropertyContactController`, `WebsiteSyndicationController`, `Property24SyndicationService`, `PrivatePropertySyndicationService`, `MobilePropertyController`, `ConfirmP24PropertyRowJob`/`P24` importer view+controller, `ContactImportController`, the reOS and rental take-on review views and confirm services, `FicaOfficerAppointmentsController::saveWindowSettings`, `config/agency-onboarding-copy.php`, `config/corex-permissions.php`, `config/corex-features.php`, `routes/web.php`, `routes/console.php`, sidebar, `database/schema/mysql-schema.sql` (regenerated with `scripts/schema-dump.sh`).
