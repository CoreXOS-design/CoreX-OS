# CoreX Atlas — What Touches Where

**Commissioned by:** Johan, 2026-09-24. Investigation only — no code changes, no migrations, no deploy.

**Corrected by:** cc3 (audit, 2026-09-25) found this document wrong about an entire shipped pillar (Rental Inspections) and thin on Agent/User, one unverified duplication finding (D2), and 18 never-touched Settings controllers. Pass 1 (2026-09-25) was built directly against the `origin/QA1` checkout at commit `d1f4296bc` (2026-09-24) — the same commit cc3 was reporting on — to re-verify every corrected claim against real, current code rather than reasoning from the original pass's (wrong) premise that this checkout predated the work in question. Pass 2 (2026-09-26), on Johan's instruction after reporting the D2 finding to him, re-audited the sibling finding D1 to the same standard — it also did not survive — and walked the three rental pillars (Inventories, Fault Reports, Work Orders) left flagged-but-unwalked after pass 1, re-verified against `origin/QA1` current tip `b48a025f` (2026-09-25). Pass 3 (2026-09-26), on Johan's explicit approval after a fresh-eyes report of what was still wrong/missing, fixed two more claims that didn't survive re-checking (D3, Communication Mailboxes), walked the two previously-unwalked modules found to actually bear on the duplication question (Compliance/FICA, Prospecting/MIC), audited Onboarding Wizard coverage of the 19 Settings controllers per non-negotiable #10a, and corrected a suspected-but-wrong Notifications duplication finding (D10) once checked against real code.

**Johan's ruling that this investigation answers:** *"we built pillars. and essentially the pillars should link to each other. not 40 screens to get the work done."* And: the investigation must find duplication of work and automation opportunities, not just link targets. Separately, on the correction: *"no half assed linking. proper investigations and specs will deliver the correct product at the end."*

**Method:** Built from mechanical sources — `routes/web.php` (2,113 named routes), `resources/views/layouts/corex-sidebar.blade.php` (3,118 lines), `config/corex-permissions.php` (1,153 lines), controller method names, model relationships, and the `.ai/specs/` files named in-code. Blade views were read in full only for the two Phase 2 working surfaces (Contacts, Properties) named in Johan's brief, plus the specific blade regions cited in the Rental Inspections correction below. Every claim below carries a `file:line` citation; where no evidence was found, that is stated explicitly rather than guessed.

## Confidence and gaps

This section exists because the previous version of this file asserted a shipped feature didn't exist, and nobody caught it before it could mislead a design decision. Future work gets built on top of this document — it has to state its own reliability rather than look uniformly authoritative. It has now been corrected three times (2026-09-25, 2026-09-26 x2); every pass is recorded here so the reader can see what's been checked, when, and against which commit.

**Pass 3 (2026-09-26), re-verified against real code, every claim carrying `file:line`:**
- **D3 (Phase 3, DR2 section) — corrected.** The claim that `deals_v2` "holds 0 rows on dev and live" and that `DealV2Controller` is fully soft-retired ("routes redirect, bodies archived") is stale and incomplete. The "0 rows" figure is a verbatim quote from `.ai/specs/deal-register-v2-spec.md:16`, dated 2026-07-02 — a snapshot at spec-write time, restated here as if still current, and unverifiable without DB access (this pass did not query the database — that claim is now marked unverified, not restated as fact). Worse, `DealV2Controller::store()` (`app/Http/Controllers/DealV2/DealV2Controller.php:263`, route `deals-v2.store`, `routes/web.php:1089`) is **not** redirected — it is live, permission-gated (`permission:deals_v2.create,deals_v2.capture_own`), and calls `DealPipelineService::createDeal()` (`app/Services/DealV2/DealPipelineService.php:20-25`), which writes a real row via `DealV2::create()`. The "soft-retired, dead engine" framing has at least one live write path this document missed.
- **Communication Mailboxes — corrected.** The claim "polling job not located in this pass (scheduled, out of route-grep scope) — 'not found'" was an avoidable miss, not a genuine gap: `routes/console.php:187` — `Schedule::command('communications:poll-mailboxes')->everyFiveMinutes()->withoutOverlapping()`, backed by `app/Console/Commands/Communications/PollMailboxes.php`. The original pass scoped its grep to `routes/web.php` only and never checked `routes/console.php`.
- Two new Phase 1 sections walked to Contacts/Properties depth: **Compliance / FICA** and **Prospecting / Tracked Properties (Market Intelligence)** — both flagged as unwalked-but-relevant in the fresh-eyes report Johan approved for pass 3.
- **Onboarding Wizard coverage** — new subsection under Settings, auditing all 19 `*SettingsController` classes against `config/agency-onboarding-copy.php` per CLAUDE.md non-negotiable #10a.
- **D10 (Notifications) — added, then corrected in the same pass.** A prior fresh-eyes report suspected "no unified notification center, every service rolls its own" as a clean new duplication finding. Checked against real code, that premise was wrong: a real shared gateway (`NotificationDispatcher`, tracked initiative AT-235, its own incident history and a build-time guard test) already exists and most services use it. What survives is narrower and already-tracked, not a fresh discovery — see D10 below.
- The "Not walked at all" list below is updated: `RentalWorkOrderService::notifyCreated`/`notifyOverdue` are now confirmed traced to `NotificationDispatcher::fire()` (`RentalWorkOrderService.php:141`) as part of the D10 check; `notifyCompleted` was not specifically re-checked in this pass and stays flagged.

**Pass 2 (2026-09-26), re-verified against `origin/QA1` current tip `b48a025f` (7 commits ahead of pass 1's `d1f4296bc`), every claim carrying `file:line`:**
- Finding D1 (Phase 3, property search) — the headline claim ("reimplemented independently at least five times... none shared") **does not survive.** Every one of the five named implementations — once one mis-citation was fixed (see below) — calls the same shared `Property::scopeSearchAddress()`. A repo-wide check found 16 call sites across 14+ controllers/services all converged on it, including an in-code docblock (Johan, 2026-09-21) documenting a deliberate, named consolidation effort that finished *before* the original atlas was even written. D1 was wrong on arrival, the same as the original Rental Inspections claim — this is the second time a headline duplication finding in this document has not survived a real check.
- Three new Phase 1 sections at Contacts/Properties depth: **Rental Inventories, Rental Fault Reports, Rental Work Orders** (the rental-specific module, distinct from the DealV2 pipeline one).
- The existing **Work Orders** section corrected to disclaim that it covers only the DealV2/DR2 deal-pipeline panel, with a forward pointer to the new Rental Work Orders section.
- **Self-correction:** pass 1's Rental Inspections section mis-cited which property-page tab the live recording surface lives in (said `'rental'`, key is actually `'inspections'` — a different, adjacent tab holding only rental-terms fields). Found while re-verifying the Rental Inventory tab's position at Johan's request; fixed in place with a note explaining the error was pass 1's own, not a change since.

**Pass 1 (2026-09-25), re-verified against `origin/QA1` at `d1f4296bc`, every claim carrying `file:line`:**
- Rental Inspections — full rewrite. The original section was wrong, not thin: it asserted the compare-viewer, the spec, and the pillar itself didn't exist on this checkout. All three are live on `origin/QA1` at the commit this document describes.
- Agent/User — new Phase 1 section, walked to CLAUDE.md's own definition of the pillar (FFC, commission, performance) as the checklist, to the same depth as Contacts/Properties.
- Finding D2 (Phase 3, contact search) — every entry re-verified against real code; one of the original five could not be substantiated and was struck rather than kept because it "sounded right."
- Settings — all 19 distinct `*SettingsController` classes referenced from `routes/web.php` (the previous pass named ~20 route-name namespaces but investigated none individually) now have their own entry: route name(s), permission gate, config model(s) written, and which pillar the setting governs.

**Carried over unchanged across all three passes, NOT re-verified:** Contacts, Properties, Rental Applications, Leases, Core Matches, Buyer Pipeline, Presentations/CMA, Deeds Capture, E-sign/DocuPerfect, Portal Syndication, both Phase 2 working-surface writeups, and Phase 3 findings D4–D9 and A1–A7. These sections read as confident, cited prose — exactly like Rental Inspections, D1, D3, and the Communication Mailboxes claim did before each was found wrong. Their citations were spot-checked only where a correction elsewhere happened to touch the same code. **Do not treat "not yet flagged" as "verified."** Four headline claims in this document (Rental Inspections' non-existence, D1's "none shared," D3's "0 rows"/"fully retired," and Communication Mailboxes' "polling job not located") have now been wrong on inspection — that is a pattern, not a coincidence, and it should weight how much confidence the *un*-rechecked sections are given. **DR2 and Communication Mailboxes are partially re-verified as of pass 3** — the specific claims named above were checked and corrected, but the rest of each section (e.g. DR2's Reads/Writes bullets beyond D3, Communication Mailboxes' Reads/Writes bullets beyond the polling job) was not re-walked and should be treated the same as any other carried-over section.

**Not walked at all, still — flagged, not guessed:**
- Whether several Admin/BM performance controllers (`Admin\BranchPerformanceController`, `Admin\PerformanceController`, `Performance\AgencyPerformanceReportController`, `Api\V1\PerformanceDrilldownController`, `BM\AgentPerformanceController`, `BM\PerformanceController`) are one canonical service with several front doors or genuine duplication — left open on Johan's explicit instruction (2026-09-26): *"leave the Admin/BM performance controllers question flagged as you have it — one canonical service or genuine duplication is a real question and I would rather it stayed open and visible than guessed at."*
- `RentalFaultReport.rental_inspection_item_id` / `reported_inspection_observation_id` and the equivalent fields on `RentalWorkOrder` — the FKs exist, but no UI trigger from the Rental Inspections recording screen into fault-report/work-order creation was found in either pass.
- `RentalWorkOrderService::notifyCompleted` — confirmed to exist, not traced to its recipients. (`notifyCreated`/`notifyOverdue` traced in pass 3 — see D10.)

---

## PHASE 1 — The Map

### Contacts

**Screens & routes** (`corex.contacts.*`, `routes/web.php:4174-4258`):
- `corex.contacts.index` — GET `contacts` — `CoreX\ContactController@index` (routes/web.php:4175)
- `corex.contacts.store`/`.show`/`.update`/`.destroy` — `CoreX\ContactController` (routes/web.php:4176,4187,4188,4191)
- `corex.contacts.check-duplicate`/`.check-held-address` — `ContactController@checkDuplicate/checkHeldAddress` (routes/web.php:4177,4179)
- `corex.contacts.import` — `CoreX\ContactImportController@import` (routes/web.php:4180)
- `corex.contacts.export` — `CoreX\ContactExportController@export` (routes/web.php:4181)
- `corex.contacts.street-complex-search[.pdf]` — `ContactController@streetComplexSearch[Pdf]` (routes/web.php:4184-4185)
- `corex.contacts.destroy-all` — `ContactController@destroyAll` (routes/web.php:4186)
- `corex.contacts.property-address.update`/`.clear` — `ContactController@updatePropertyAddress/clearPropertyAddress` (routes/web.php:4189-4190)
- `corex.contacts.tags.sync` — `ContactController@syncTags` (routes/web.php:4192)
- `corex.contacts.consent.record`/`.revoke` — `ContactController@recordConsent/revokeConsent` (routes/web.php:4193-4194)
- `corex.contacts.touch`, `.birthday-reminder.toggle`, `.increment` — `ContactController@touch/toggleBirthdayReminder/incrementChannel` (routes/web.php:4195-4197)
- `corex.contacts.communications.not-delivered`/`.mark-sent` — `ContactController` (routes/web.php:4200-4202)
- `corex.contacts.notes.*` — `CoreX\ContactNoteController` (routes/web.php:4205-4208)
- `corex.contacts.testimonials.*` — `CoreX\ContactTestimonialController` (routes/web.php:4211-4213)
- `corex.contacts.documents.*` — `CoreX\ContactDocumentController` (routes/web.php:4216-4219)
- `corex.contacts.properties.search`/`.link`/`.unlink` — `CoreX\ContactPropertyController` (routes/web.php:4221-4223)
- `corex.contacts.representatives.*`, `.linked-entities.*` — `CoreX\ContactRepresentativeController` (routes/web.php:4225-4235)
- `corex.contacts.matches.*` (store/edit/update/setStatus/results/print/toggleHide/convertToDeal/destroy) — `CoreX\ContactMatchController` (routes/web.php:4237-4245)
- `corex.contacts.client-login.*` — `Contacts\ClientLoginController` (routes/web.php:4248-4251)
- `corex.rentals.contacts.index` — same `ContactController@index`, locked by route name to rental-relevant contact types (routes/web.php:4106-4114)
- Settings owned by this pillar: `settings/contact-types` (4258), `settings/contact-sources` (4265), `settings/contact-identifier-labels` (4273), `settings/contact-tags` (4280)

**Reads from other pillars**
- Property — `$contact->properties` / `$doc->properties` (`app/Http/Controllers/CoreX/ContactController.php:618,627`); viewing-feedback property resolution via `calendar_event_links` (`ContactController.php:649,654,728,736`); model relation `Contact::properties()` belongsToMany Property via `contact_property` (`app/Models/Contact.php:722-728`).
- Deal — `Deal::whereHas('contacts', ...)` (`ContactController.php:1124`).

**Writes to other pillars**
- Property linking only (pivot `contact_property`) via `CoreX\ContactPropertyController@link` (`app/Http/Controllers/CoreX/ContactPropertyController.php:39-63`) and `App\Services\Property\ContactPropertyLinker` (`app/Models/Contact.php:718-720`).
- Core Matches — `ContactMatchController@store` creates a `ContactMatch` row (`app/Http/Controllers/CoreX/ContactMatchController.php:520`), which (via `ContactMatchObserver`) writes back onto the Contact's own `is_buyer`/`buyer_state` fields — see Buyer Pipeline below.

**How data enters**
- Manual: `ContactController@store` — `Contact::create($data)` (`ContactController.php:1447`).
- Import: `ContactImportController@import` — XLSX/CSV → `Contact::create()` (`app/Http/Controllers/CoreX/ContactImportController.php:113,278`).
- Cross-pillar creation from Property screen: `PropertyContactController@createAndLink` (`app/Http/Controllers/CoreX/PropertyContactController.php:296`) and inline "pending new contacts" on property create (`app/Http/Controllers/CoreX/PropertyController.php:1244`).

---

### Properties

**Screens & routes** (`corex.properties.*`, `routes/web.php:3703-3999`; plus `corex.map.*` at 3999, `corex.ad-templates.*` at 4023, `corex.marketing.*` at 4298):
- `corex.properties.imported-stock` — `PropertyController@importedStock` (routes/web.php:3704)
- `corex.properties.go-live` — `PropertyController@goLive` (routes/web.php:3709)
- `corex.properties.generate-presentation`/`.presentation-coverage` — `Presentation\PresentationGeneratorController@generate/coverage` (routes/web.php:3712-3715)
- `corex.properties.sg.*` — `CoreX\PropertySgController` (routes/web.php:3719-3730) — SG (deeds/title) document lookup+save
- `corex.properties.seller-links.generate`/`.revoke` — `SellerLinkController` (routes/web.php:3733-3734)
- `corex.properties.mark-sold` — closure route, writes `property_sold_records`, updates `Property.status` (routes/web.php:3737-3770)
- `corex.properties.marketing-activity.store` — closure, `PropertyMarketingActivity::create` (routes/web.php:3773-3796)
- `corex.properties.recommendations.action` — closure, updates `property_recommendations` (routes/web.php:3799-3825)
- `corex.properties.index`/`.create`/`.store` — `PropertyController` (routes/web.php:3827-3829)
- `corex.properties.third-party-sale.*` — `CoreX\ThirdPartySaleController` (routes/web.php:3833-3835)
- `corex.properties.reports.lost-to-competitors` — `CoreX\LossAnalysisController@index` (routes/web.php:3839)
- `corex.properties.import-sold.*`, `.p24-fix.*` — `SoldPropertyImportController`, `P24ListingNumberFixController` (routes/web.php:3843-3852, super_admin only)
- `corex.properties.contacts.search-global` — `PropertyContactController@searchGlobal` (routes/web.php:3854)
- `corex.properties.wizard.*` — `CoreX\PropertyWizardController` (routes/web.php:3856-3863)
- `corex.properties.show`/`.edit`/`.ad`/`.brochure`/`.update`/`.destroy`/`.restore`/`.duplicate`/`.change-type`/`.publish-toggle` — `PropertyController` (routes/web.php:3868-3888)
- `corex.properties.convert-from-prospecting`/`.mark-not-selling` — `PropertyController` (routes/web.php:3890-3891) — Tracked-Property → Stock promotion trigger
- `corex.properties.upload-images`/`.deleteImage`/`.deleteImages`/`.reorder-images`/`.rotate-image` — gallery (routes/web.php:3892-3903)
- `corex.properties.rental-images.*`, `.rental-details.update` — rental gallery + fields (routes/web.php:3905-3919)
- `corex.properties.notes.*`, `.files.*` — `PropertyNoteController`, `PropertyFileController` (routes/web.php:3921-3927)
- `corex.properties.contacts.search`/`.link`/`.createAndLink`/`.updateRole`/`.unlink` — `PropertyContactController` (routes/web.php:3929-3933)
- `corex.properties.marketing.*` — `PropertyMarketingController` (routes/web.php:3935-3937)
- `corex.properties.syndication.*`, `.p24-syndication.*`, `.website-syndication.*` — outbound portal push (routes/web.php:3939-3973), see Portal Syndication below
- `corex.rentals.properties.index` — same `PropertyController@index`, locked by route name (routes/web.php:3980-3986)
- `corex.map.*` — `Map\MapController`/`MapActivityController`/`MapSavedSearchController` (routes/web.php:3997-4022)
- `corex.ad-templates.*` — `CoreX\PropertyAdTemplateController` (routes/web.php:4023-4030)

**Reads from other pillars**
- Contact — inline search/link (`PropertyContactController@search/link`, `app/Http/Controllers/CoreX/PropertyContactController.php:49,103`); `$property->contacts` iterated for seller/notification logic (`PropertyController.php:1296,1678,1730`); model relation `Property::contacts()` belongsToMany Contact via `contact_property` (`app/Models/Property.php:933,943`).
- Deal — inverse-only: `Deal::belongsTo(Property::class, 'property_id')` (`app/Models/Deal.php:262`).

**Writes to other pillars**
- Contact — creation from property-create form's "pending new contacts" (`Contact::create($ncData)`, `PropertyController.php:1160-1246`, firing `ContactLinkedToProperty`) and from `PropertyContactController@createAndLink` (`PropertyContactController.php:296`).
- Deal — not written directly from `PropertyController`; deal creation from a property+match pair happens in Core Matches (`ContactMatchController@convertToDeal`), which sets `deal->property_id`.

**How data enters**
- Manual: `PropertyController@store` (`PropertyController.php:911`) and the step wizard `PropertyWizardController@createDraft/saveStep/finalize` (`app/Http/Controllers/CoreX/PropertyWizardController.php:159,372,412`).
- Import: `SoldPropertyImportController` (super-admin only) → `SoldPropertyImporter::import()` → `Property::create()` (`app/Services/Properties/SoldPropertyImporter.php:149`).
- Derived/promoted: Tracked Property → Stock promotion via `TrackedPropertyMatchOrCreateService::promoteToStock()`, invoked from `PropertyController@convertFromProspecting` (routes/web.php:3890) and `TrackedPropertyController` (`app/Http/Controllers/CoreX/TrackedPropertyController.php:155`), and from Deeds Capture's `promote()`.
- Feed/scan: P24 alert-email import (`app/Services/P24/P24ImapImportService.php:147,169,189`) feeds Portal Leads / Tracked Property matching (not a direct `Property` row write).

---

### Agent / User

**NEW SECTION (2026-09-25).** CLAUDE.md defines CoreX's four core pillars as Property, Contact, Deal, and Agent (`User` model, agent role) — "the practitioner — FFC, commission, performance." The original atlas gave Property and Contact full sections and traced Deal through DR2/DealV2, but never walked Agent/User as a pillar in its own right; it appeared only as a foreign key inside other modules' Reads/Writes bullets. This section uses CLAUDE.md's own definition as the checklist: FFC, commission, performance, plus the CRUD/admin surface every other pillar got.

**Screens & routes:**
- `admin.users`/`.create`/`.store`/`.edit`/`.update` — `Admin\UserManagementController` (routes/web.php:620-629) — agent/user CRUD, gated `permission:manage_users`. **Not linked from the primary sidebar nav** (`resources/views/layouts/corex-sidebar.blade.php` — no `admin.users` reference found in that file at all); reached instead from the Settings hub (`resources/views/corex/settings.blade.php:1066`) and the Admin dashboard (`resources/views/admin/dashboard.blade.php:53`), and from the older top-nav `layouts/navigation.blade.php:60,174`.
- `admin.users.toggle`/`.toggle-website`/`.toggle-p24`/`.delete`/`.archived`/`.restore` — activation, per-portal visibility toggles, soft delete/restore (routes/web.php:631-652) — soft delete only, consistent with non-negotiable #1.
- `admin.users.role.update` — role change (routes/web.php:659); `admin.users.sync-p24` — P24 agent-id resync (routes/web.php:661); `admin.users.ffc-certificate.download` — gated `permission:manage_users` **and** `deny_assistant_download` middleware (routes/web.php:664-665) — an assistant account cannot download another agent's FFC certificate even with `manage_users`.
- `agent.portal` (index) and `/my-portal/*` — `Agent\AgentPortalController` (routes/web.php:2128-2197) — the agent's own self-service surface: `updateProfile` (:263), `saveSignature` (:295), `updateManagedBranches` (:328), `restoreAppAccess` (:359), `uploadDocument` (:368), `myPayslips`/`myPayslipShow`/`myPayslipPdf` (:487,500,520). Sidebar entry "My Portal" (`corex-sidebar.blade.php:683-687`), gated `permission:access_my_portal`.
- `commission.dashboard`/`.index`/`.principal`/`.confirm`/`.pay` — `Commission\CommissionController` (routes/web.php:2277-2290) — every route middleware'd `deny_assistant`, with an in-code comment stating why: "the whole commission/revenue engine is off-limits to assistants (they have no commission of their own and must never see agency finance)" (routes/web.php:2279-2280). Sidebar: "Commission" (personal dashboard, `corex-sidebar.blade.php:1309-1310`) and "Commission Management" (admin, `:1618`).
- `command-center.reporting.agent` — "My Performance" (sidebar `corex-sidebar.blade.php:647`); `command-center.performance` — "Performance" (`:664`); `compliance.agents` — `Compliance\AgentComplianceController@dashboard` (routes/web.php:2341-2342), the agency-wide agent-compliance dashboard — its only method in this pass is `dashboard()` (`AgentComplianceController.php:14`); no further sub-actions found on this controller.

**FFC (Fidelity Fund Certificate)**
- Fields live directly on `User`: `ffc_certificate_path`, `ffc_number`, `ffc_expiry_date` (cast `date`) — `app/Models/User.php:147,161-162,275`.
- Live UI consequence, not just stored data: the "My Portal" nav badge computes `$portalNeedsAttention` from `empty($user->ffc_number) || empty($user->ffc_certificate_path)` (plus an incomplete required-training check) and shows an amber dot on the nav item when true (`corex-sidebar.blade.php:675-689`).
- Admin-side download of the certificate is a distinct, more tightly gated route than the rest of user management (`admin.users.ffc-certificate.download`, routes/web.php:664-665, `deny_assistant_download`).

**Commission**
- `CommissionLedger` — the ledger table — belongsTo `User` (`user_id`), `Agency`, `AgentCapPeriod`, `Deal` (`deal_id`), `Property` (`property_id`) (`app/Models/CommissionLedger.php:29-34` fillable list; explicit comment on why branch is stamped from the earning agent rather than the acting user, `:15-22`).
- `User::capPeriods(): HasMany` → `AgentCapPeriod` (`User.php:1571-1573`); `User::commissionEntries(): HasMany` → `CommissionLedger` (`:1583-1585`); `User::isCapped(): bool` (`:1598`).
- **Domain-event wired, not ad-hoc** (per non-negotiable #9): `DealCommissionFinalised` event → `App\Listeners\Deal\GenerateCommissionLedgerEntries::handle()` (`app/Providers/AppServiceProvider.php:427`; listener `app/Listeners/Deal/GenerateCommissionLedgerEntries.php:32`) → `CommissionCalculationService::calculateDealCommission()` → `CommissionLedger::create()` (`app/Services/CommissionCalculationService.php:110`). This is the confirmed Deal → Agent write path: a Deal's commission is finalised, and the ledger entries for every agent on that deal are generated automatically from it — not typed in separately.
- Commission rates/splits themselves are agency-configurable, not hardcoded — see `Commission\CommissionSettingsController` in the Settings section below.

**Performance**
- `App\Services\Agent\AgentPerformanceService::getMonthlySnapshot(User $user, Carbon $month): array` (`app/Services/Agent/AgentPerformanceService.php:29`) — per-agent monthly snapshot.
- `Admin\AgentPerformanceController@show(Request, int $userId, CompanyPerformanceService $service)` (`app/Http/Controllers/Admin/AgentPerformanceController.php:17`) — admin drill-down into one agent's performance, reusing the agency-wide `CompanyPerformanceService` rather than a separate per-agent implementation.
- Also present, not opened in this pass beyond confirming existence: `Admin\BranchPerformanceController`, `Admin\PerformanceController`, `Performance\AgencyPerformanceReportController`, `Api\V1\PerformanceDrilldownController`, `BM\AgentPerformanceController`, `BM\PerformanceController` — several performance-reporting entry points exist across Admin/BM (Branch Manager)/API namespaces; how they relate to each other (one canonical service with several front doors, vs. genuine duplication) was **not determined** in this pass — flagged as unwalked rather than guessed at.

**Reads from other pillars**
- Property — `Property::agent(): BelongsTo` via `agent_id` (`app/Models/Property.php:860-862`); co-listing agent `pp_second_agent_id` (`:677,873`); OWN-scoping resolves through the agent: `$query->whereIn('agent_id', $user->dataIdentityIds())` (`Property.php:1802`).
- Deal — `Deal::agents(): BelongsToMany` (`app/Models/Deal.php:381`); `Deal::managedBy(): BelongsTo User` (`:342`) — both already cited in the existing DR2 section's Reads bullet, confirmed unchanged.
- Property24 syndication — `Property24SyndicationService` resolves the listing agent from `$property->agent`/`pp_second_agent_id` and reads `p24_agent_id`/`p24_agent_agency_id`/`p24_profile_signature`/`p24_photo_signature` off `User` (already cited in the Portal Syndication section, confirmed unchanged).

**Writes to other pillars**
- Deal → Agent (Commission) — see Commission above; this is the clearest, most fully-wired write path found for this pillar.
- Portal Syndication → Agent — `p24_agent_id`/`p24_agent_agency_id`/`p24_profile_signature`/`p24_photo_signature` written back onto `User` after a portal push (already cited in Portal Syndication, confirmed unchanged).
- Agent → Property/Contact/Deal is overwhelmingly **read** direction in this codebase (an agent is attributed to records, rather than records being written onto the agent) — the one confirmed exception in the opposite direction is the Commission ledger above.

**How data enters**
- Manual: `Admin\UserManagementController@store/update` (an admin creates/edits the account); the agent then self-maintains profile/signature/documents/managed-branches via `AgentPortalController`.
- Derived: FFC fields are typed in (by the agent or an admin) rather than fed from an external register — no FFC-authority integration or scrape was found in this pass; **not found**, stated rather than guessed.
- Derived (Commission): `CommissionLedger` rows are generated automatically off `DealCommissionFinalised`, not manually captured (see above) — the one pillar-entry path in this section that is event-driven rather than a form submission.

**Where it actually lives:** split three ways, none of them a single "Agent" home — (1) admin CRUD (`admin.users.*`) reached from Settings/Admin-dashboard, not the primary sidebar; (2) the agent's own self-service surface (`agent.portal`, "My Portal" in the sidebar); (3) Commission and Performance as their own separate sidebar items/dashboards, not sub-tabs of a user record. There is no single screen an admin or a colleague can open that shows one agent's identity, FFC status, commission, and performance together — each lives on its own route, reachable only by knowing to go looking for it separately. **Whether this constitutes a Phase 3 duplication/fragmentation finding in the same shape as D9 (Settings) was not evaluated in this pass** — noted as an observation, not asserted as a finding, since Phase 3 additions were outside this correction's scope.

---

### Compliance / FICA

**NEW SECTION (Pass 3, 2026-09-26).** Real, large module — 28 controllers in `app/Http/Controllers/Compliance/`, its own top-level sidebar group (`corex-sidebar.blade.php:1719-1743+`) with sub-items FICA, RMCP, RMCP Dashboard, Employee Screening, Policy, Whistleblow, Seller Info, Document Verification, Communications Log, RCR Questionnaires — never walked before this pass. Walked specifically to resolve whether it duplicates the Contact record's own "FICA Compliance" tab (Phase 2, described there as "download-only; no new-submission action exists here or anywhere on this screen").

**Screens & routes** (`compliance.fica.*`, `routes/web.php:2433-2465`, prefix `compliance/fica`, gated `permission:access_compliance` + `feature:compliance`) — `App\Http\Controllers\Compliance\FicaController`:
- `.index`/`.create`/`.store` (routes/web.php:2434-2436) — standard digital-signature FICA submission flow.
- `.wet-ink.create`/`.wet-ink.store` (:2437-2438) — paper-signed fallback path.
- `.contacts.search` (:2441, `FicaController.php:226`) — type-ahead contact picker, added AT-361 specifically because "Agency 1 has ~9,000 contacts; rendering them all... made the page ~8MB" (`FicaController.php:155-157`).
- `.{submission}` `.show`/`.pdf`/`.agent-approve`/`.tfs-screen`/`.tfs-decision`/`.compliance-review`/`.compliance-approve`/`.compliance-reject`/`.refer-to-co`/`.reject`/`.request-corrections`/`.resend`/`.cancel`/`.reopen`/`.agent-upload`/`.documents.remove`/`.documents.view`/`.link-contact-documents` (:2443-2464) — the full RO/CO review-and-decision workflow, including Targeted Financial Sanctions (TFS) screening.
- `compliance.agents` — `AgentComplianceController@dashboard`, its only method (`routes/web.php:2342`, `AgentComplianceController.php:14`) — agency-wide agent-compliance overview, already cited in the Agent/User section.
- Siblings, not walked to this depth (none showed a Property/Contact/Deal duplication pattern worth their own Phase 1 entry): **RMCP** (`compliance.rmcp.*`, `RmcpController.php`) — agency-level Risk Management & Compliance Programme document authoring/versioning, not contact- or property-scoped. **Policy** (`compliance.policy.*`) — "Mirrors RmcpController, generalised across `agency_policies`" (its own docblock, `PolicyController.php:16-19`). **Employee Screening** (`compliance.screening.*`) — `EmployeeScreening belongsTo User`, an Agent/User-pillar compliance record. **Whistleblow** (`compliance.whistleblow.*`) — complaint intake/approve/reject via `WhistleblowComplaintService`. **Seller Info** (`compliance.seller-info.*`) — generates a compliance info-sheet from `Agency` data (`SellerInfoController.php:27-29`); no `Contact`/`Property` FK found — not found, not asserted absent. **Document Verification** (`compliance.verification.*`) — reads/verifies `UserDocument`, **confirmed the same model** `AgentPortalController::uploadDocument()` writes (`AgentPortalController.php:391-398`) — the review half of the FFC/agent-document upload already documented in Agent/User. One store, one verification queue — no duplication found there.

**Reads from other pillars**
- Contact — `FicaSubmission belongsTo Contact` via `contact_id` (`FicaSubmission.php:81`).
- Agent/User — `Document Verification` reads `UserDocument`, shared with `AgentPortalController::uploadDocument()` (see Agent/User section); `EmployeeScreening belongsTo User`.

**Writes to other pillars**
- Contact — `FicaController::store()` (`FicaController.php:163`) writes `FicaSubmission` rows against a Contact.

**How data enters**
- Manual: agent/CO captures a FICA submission (`.create`/`.store`) or a wet-ink fallback (`.wet-ink.*`), or reviews/decides an existing one.

**The Contact-tab overlap — resolved, not a duplication.** `Contact::ficaSubmissions(): HasMany` (`Contact.php:424`) is the identical relation the Contact record's FICA tab reads (`resources/views/corex/contacts/_fica-tab-body.blade.php:14-16`) — one table, one model, read from two screens, not two data stores. The tab is genuinely read-only, confirmed by code: its only two outbound links are `compliance.fica.pdf` and `compliance.fica.show` (`_fica-tab-body.blade.php:106,112`) — zero `store`/`create` reference in its 187 lines.

**What a human actually does twice today:** not FICA data itself — the *contact search*. `FicaController::create()` (`FicaController.php:151-158`) takes no parameters and reads no `contact_id`. Starting a FICA submission for a contact already open on the Contact screen means leaving that record, landing on a blank `compliance/fica/create` picker, and re-searching for the same contact by name/phone/email through `.contacts.search` (`:2441`) — the same re-search pattern already named for Rental Applications (A1) and E-sign (A5), just never named for FICA before this pass. **What would do it for them:** a "Start FICA" action on the Contact tab that opens `compliance.fica.create` with `contact_id` pre-filled — the identical fix already prescribed for A1/A5, not a new mechanism.

**Where it actually lives:** standalone top-level sidebar group "Compliance," entirely disconnected in navigation terms from the Contact record it operates on — the only bridge today is the read-only tab.

---

### Rental Applications

**Screens & routes:**
- `corex.rental-applications.index`/`.returned` — `RentalApplicationController@index/returned` (routes/web.php:2987-2989) — tile-based control centre
- `corex.rental-applications.create`/`.search-properties`/`.contacts.quick-create`/`.store` — `RentalApplicationController` (routes/web.php:2990-2997)
- `corex.rental-applications.show`/`.update`/`.send`/`.pdf`/`.pdf-inline` (routes/web.php:2998-3003)
- `corex.rental-applications.documents.*` — download/upload/attach-existing (routes/web.php:3004-3011)
- `corex.rental-applications.destroy`/`.restore`/`.update-status` (routes/web.php:3020-3025)
- `corex.rental-applications.link-tenant-property`/`.unlink-tenant-property` (routes/web.php:3030-3033) — links an approved applicant's Contact to a Property as tenant
- `corex.rental-applications.review.*` (~20 routes, Phase-2 agent review split-screen) — `RentalApplicationReviewController` (routes/web.php:3040-3100)
- `corex.rental-applications.authorisation.*` (~15 routes, RO/CO decision workflow) — `RentalApplicationAuthorisationController` (routes/web.php:2947-2981), gated on `isRentalApplicationRO()/isRentalApplicationCO()`, registered *before* the generic `{rentalApplication}` group to avoid wildcard-swallowing (comment, routes/web.php:2942-2946)
- `rental-applications.public.*` (~12 routes) — tokenized applicant-facing form — `RentalApplicationSigningController` (routes/web.php:5082-5136), prefix `rental-application` singular
- `corex.settings.rental-applications.*` (~20 settings routes) — qualifying formula, gates, highlighters, decline templates, rate limits — `RentalApplicationSettingsController`/`RentalApplicationHighlighterController`/`RentalApplicationDeclineReasonTemplateController` (routes/web.php:2837-2928)

**Reads from other pillars**
- Property — `Property::findLinkableForRentalApplication()` (`RentalApplicationController.php:571,1118`); `searchProperties()` queries `Property::query()->where('listing_type','rental')->visibleTo(...)` (`RentalApplicationController.php:513-521`).
- Contact — `Contact::findOrFail(...)` in `store()` (`RentalApplicationController.php:568`); `ContactDuplicateService` in `quickCreateContact()` (`:676-684`).
- Model: `RentalApplication belongsTo Contact` and `belongsTo Property` (`app/Models/RentalApplication.php:691,696`).

**Writes to other pillars**
- Contact — `linkTenantProperty()`/`unlinkTenantProperty()` write the `contact_property` pivot with role `tenant` via `ContactPropertyLinker` (`RentalApplicationController.php:1137,1224`), firing `ContactLinkedToProperty`. `Contact::rental_application_status` synced via `RentalApplicationSubmitted` event → `App\Listeners\Contact\RecomputeRentalApplicationStatus`.
- Property — deliberately does **NOT** write `$property->status` on tenant link, documented in-code (`RentalApplicationController.php:1158-1170`) after an investigation found `PropertyObserver`'s off-market-delist path doesn't protect the RENTED lifecycle, so flipping status risked silently delisting from P24/PP.
- Compliance (FICA) — `findOrCreateFicaSubmission($application)` forced on submission release (`RentalApplicationSigningController.php:505`).

**How data enters**
- Internal: manual, `RentalApplicationController@create/store` (agent picks/creates a Contact, optionally a Property).
- External: tokenized public portal, no auth (routes/web.php:5082-5135), per-route named throttle limiters.

**Where it actually lives:** standalone module, own "Rentals" sidebar group (`corex-sidebar.blade.php:1052-1104`), listing "Rental Applications" and "Rental Application Authorisation" as separate subitems. Explicitly documented in-code (`corex-sidebar.blade.php:1010-1018`) as **NOT reconciled** with a second, unrelated "Rentals" nav group (see Leases below) — "Johan is deciding separately whether/how the two get reconciled. Do NOT fold them together on your own initiative."

---

### Rental Inspections

**CORRECTION (2026-09-25):** the previous version of this section stated the "compare-viewer," its spec, and the "Rental Inspections" pillar itself did not exist on this checkout, and described only the older "Rental Images" gallery feature. That was wrong, not thin. Re-checked directly against `origin/QA1` at `d1f4296bc` (2026-09-24) — the same commit the original pass was reporting on — the pillar is fully built, routed, modeled, and navigable. The error traces to a scope note claiming a worktree base that predated the work; that premise was never verified before the rest of the section was written on top of it. `rental-images.*` (`PropertyController@uploadRentalImages` etc., routes/web.php:4316-4321) does still exist as a separate, older gallery-upload feature and has not been removed — but it is no longer the whole story, and for condition-report purposes it has been superseded by the module below.

**Screens & routes** (`corex.rental-inspections.*`, routes/web.php:3184-3288, prefix `rental-inspections`, gated `permission:rental_inspections.view`):
- `corex.rental-inspections.index` — `RentalInspectionController@index` (routes/web.php:3185) — the tracked/searchable list, per `.ai/specs/rental-inspections.md` §5. Has its own top-level sidebar entry: **"Rental Inspections"** (`resources/views/layouts/corex-sidebar.blade.php:1099`, under the Rentals nav group), contradicting the original section's "no sidebar entry of its own."
- `corex.rental-inspections.create`/`.store` — `RentalInspectionController@create/store` (routes/web.php:3190-3193)
- `corex.rental-inspections.show` — `RentalInspectionController@show` (routes/web.php:3194) — the read-only, agency-level inspection record, distinct from the property tab's live recording surface (see `next()` docblock, `RentalInspectionController.php:283-289`)
- `corex.rental-inspections.form` — `RentalInspectionController@form` (routes/web.php:3197 → `RentalInspectionController.php:248-257`) — the **printable form**: a blank OMR capture form generated via `RentalInspectionFormPdfService`, downloaded before an inspection happens
- `corex.rental-inspections.report` — `RentalInspectionController@report` (routes/web.php:3200 → `RentalInspectionController.php:268-282`) — the **completed report PDF**: predecessor-vs-current comparison + signatures, no photos (those live behind the public link), via `App\Services\Rentals\RentalInspectionReportPdfService`. In-code docblock (`RentalInspectionController.php:259-266`, Johan, 2026-09-23) explicitly distinguishes this from `form()` above — "same DomPDF machinery, unrelated purpose, never confused with each other."
- `corex.rental-inspections.next` — `RentalInspectionController@next` (routes/web.php:3203) — the deliberate chain-advance action (see Chain below)
- `corex.rental-inspections.public-link.generate`/`.revoke` — `RentalInspectionController@generatePublicLink/revokePublicLink` (routes/web.php:3205-3208)
- `rental-inspections.public.show` — `RentalInspectionPublicController@show` (routes/web.php:5604-5605 → `RentalInspectionPublicController.php:31`), token-based, no auth, throttled (`throttle:rental-inspection-public-show`) — the **public report link**: `report()` above auto-generates this link if none is live before printing the QR code that points to it (`RentalInspectionController.php:274-277`)
- `corex.rental-inspections.scans.store`/`.review`/`.apply`/`.download`/`.destroy` — `RentalInspectionScanController` (routes/web.php:3212-3219) — the **scan upload**: per `.ai/specs/rental-inspection-form.md` §13 (docblock, `RentalInspectionScanController.php:1-8`), a scanned/photographed completed printable form is OCR/OMR-read (**"COLUMN MARKING ONLY... we do NOT read handwriting, ever,"** Johan) into a reviewable `RentalInspectionScanMark` set by `RentalInspectionScanReaderService`; nothing writes to the live inspection until a human confirms on the review screen — `apply()` (`RentalInspectionScanController.php:96`)
- `corex.rental-inspections.deposit-comparison` — `RentalInspectionComparisonController@show` (routes/web.php:3270 → `RentalInspectionComparisonController.php:34`) — the in-vs-out deposit-finding comparison, per `.ai/specs/rental-inspection-form.md` §7
- `corex.rental-inspections.observations.*`, `.photos.*`, `.signatures.*`, `.rooms.*`, `.complete` — `RentalInspectionRecordingController` (routes/web.php:3227-3288) — the live recording surface: observations, per-photo tagging, room notes/mark-good/mark-na, signature capture, completion
- `corex.settings.rental-inspections.*` (5 routes, routes/web.php:2848-2864) — `RentalInspectionSettingsController` — features, room-type defaults/walking order, condition states (see Settings section)
- **The "compare-viewer"** (two-panel photo comparison modal, "Single"/"Compare" mode toggle, room-tab navigation, zoom-lock) is real and embedded inside the Property show page's Rental tab: Alpine component `compareViewer` (`resources/views/corex/properties/show.blade.php:5169` onward — `x-show="compareViewer.open"`, `compareViewerSetMode`, `compareViewerToggleLock`, `compareViewerRoomTabs()` etc., `show.blade.php:5169-5221` and further); triggered via `openCompareViewer(photo, insp)` (referenced `show.blade.php:5159`). Confirmed also present in `resources/views/corex/properties/partials/rental-inspection-item-cell.blade.php` and `resources/views/corex/properties/partials/rental-inspection-recording.blade.php`.

**The chain** (predecessor/successor linkage — the mechanism the original section had no knowledge of): `RentalInspection.previous_inspection_id` (`app/Models/RentalInspection.php:173` `previousInspection(): BelongsTo`; inverse `nextInChain(): HasOne`, `:184`), unique per predecessor so "a chain link cannot fork" (`RentalInspection.php:679-681`). `RentalInspection::startNext(self $predecessor, string $type, User $by)` (`:671-704`) is the deliberate action that records a new link, copying room/item structure from the predecessor rather than re-asking the agent; refuses to follow an In-inspection with another In, and refuses a predecessor that already has a successor. `RentalInspection::chainTailFor(Property $property)` (`:543`) resolves the one open inspection per property regardless of chain depth ("In → Routine → Routine → Out").

**Reads from other pillars**
- Property — every inspection is created against a `Property`; `chainTailFor(Property $property)` (`RentalInspection.php:543`); the property show page's **"Inspections" tab** (key `'inspections'`, not `'rental'` — see correction below) includes the live recording partial keyed to the property (`show.blade.php:5087,5090` — `@include('corex.properties.partials.rental-inspection-recording', ...)`).
- Leases — `RentalInspection belongsTo Lease` via `lease_id` (referenced `RentalInspection.php:683-684`, `startNext()` resolving `$predecessor->lease`).
- Agent/User — recording actions take `User $by`/`$request->user()` throughout (`RentalInspectionController.php`, `RentalInspectionRecordingController.php`); scan review/apply is a human-confirm step tied to the acting user.

**Writes to other pillars**
- Property — inspections are recorded and reported against a specific Property; no evidence found in this pass of the inspection writing back onto `Property` fields directly (e.g. no `$property->update()` call located inside the inspection controllers) — **not found**, stated rather than guessed.
- Public/tenant-facing — `generatePublicLink()`/`revokePublicLink()` (`RentalInspectionController.php:317-332`) mint or revoke the tokenized URL that `RentalInspectionPublicController@show` serves with no CoreX login required.

**How data enters**
- Manual recording: agent works the live recording surface embedded in the property's Rental tab (`RentalInspectionRecordingController` — observations, photos, room notes, signatures) or the dedicated `RentalInspectionController@create/store` flow.
- Scan/OCR: a physically completed printable form is photographed/scanned and uploaded (`RentalInspectionScanController@store`), machine-read for column marks only, and applied only after human review (`@apply`) — never auto-applied.
- Derived/chained: `RentalInspection::startNext()` creates the next link in a property's inspection chain from an existing one, copying structure rather than re-entering it.

**Where it actually lives:** hybrid, not "embedded only" as the original section claimed — a standalone module with its own sidebar entry and searchable index (`corex.rental-inspections.index`, sidebar `corex-sidebar.blade.php:1099`) for the agency-level record, PDFs, and public link, **and** a live recording surface embedded inline inside the Property show page's own **"Inspections" tab** (`show.blade.php:1416`, key `'inspections'`) alongside the still-present older rental-images gallery (`partials/rental-section-body.blade.php`, `show.blade.php:5520`). **Self-correction (2026-09-26):** the previous version of this section cited this as the "Rental" tab (key `'rental'`, `show.blade.php:1415`) — that key is real but is a *different* tab, holding only the rental-terms fields (rental price, deposit, etc., `show.blade.php:4269` onward) plus the quick-create links into Fault Reports/Work Orders (see those sections below). The Inspections/Rental/Inventory tabs are three separate, adjacent entries in the same tab array (`show.blade.php:1415-1417`) — gated differently: Rental shows only when `listing_type==='rental'` unless new; Inspections is gated `$isNew || listing_type!=='rental'` (rentals-only, `show.blade.php:4680` region); Inventory is gated only on `!$isNew` (sale or rental alike — see the Rental Inventories section below). That is my own citation error from the prior pass, not a change since; corrected here while re-verifying the adjacent Inventory tab at Johan's request.

The sibling **Rental Inventories** module was flagged as unwalked when this section was first corrected; it, along with Rental Fault Reports and Rental Work Orders, is now walked below (2026-09-26, on Johan's instruction, having re-verified against `origin/QA1` current tip `b48a025f`).

---

### Rental Inventories

**NEW SECTION (2026-09-26).** Confirmed to exist in the prior correction pass but not investigated to Phase 1 depth. Per Johan directly: *"Rental Inventory shipped this week and moved. It now has its own tab on the property, immediately after Inspections, gated on the property not being new — NOT rentals-only, because inventory applies to sale properties too."* Confirmed exactly as described below.

**Screens & routes** (`corex.rental-inventories.*`, routes/web.php:3294-3336, prefix `rental-inventories`, gated `permission:rental_inventories.view`; mutations additionally gated `permission:rental_inventories.create`):
- `corex.rental-inventories.index`/`.create`/`.store`/`.show` — `RentalInventoryController` (routes/web.php:3295-3300)
- `corex.rental-inventories.comparison` — `RentalInventoryController@comparison` (routes/web.php:3302 → `RentalInventoryController.php:162-173`) — the **move-out comparison**: `abort_unless($rentalInventory->status === RentalInventory::STATUS_COMPLETED, 400, 'The move-out comparison is only available once the inventory itself is completed.')` — hard-gated, not just hidden in the UI; renders via `RentalInventoryComparisonService::compare()` plus `RentalInventorySetting::dispositionPresetsFor($agency_id)` for the per-agency disposition vocabulary.
- `corex.rental-inventories.cancel`/`.destroy`/`.restore` (routes/web.php:3303-3308) — soft delete only, per non-negotiable #1.
- `corex.rental-inventories.lines.store`/`.update`/`.retire`/`.dispositions.store`, `.signatures.store`, `.complete` — `RentalInventoryRecordingController` (routes/web.php:3310-3322) — the line-item recording surface: add/edit/retire a counted item, record its move-out disposition, capture signatures, mark the inventory complete.
- `corex.rental-inventories.photos.store`/`.destroy`, `.lines.photos.attach`/`.detach` — `RentalInventoryCaptureController` (routes/web.php:3326-3336) — room-tagged photo capture, and optional line-to-photo tagging (e.g. photographing a specific appliance's serial number against its line item).
- `inventory.show` — `RentalInventoryCaptureController@show(Request, Property $property)` (routes/web.php:4117-4118, `permission:rental_inventories.view`) — the property-scoped entry point: **not gated on `listing_type`** (in-code comment, routes/web.php:4116: *"Sale or rental, not gated on listing_type"*), confirming Johan's note above. Calls `RentalInventory::resolveOrStartFor($property, $request->user())` (`RentalInventoryCaptureController.php:43`) — resolves the property's current open inventory or starts one, mirroring the "one open record per property" pattern `RentalInspection::chainTailFor()` uses, but without a predecessor/successor chain — inventories are not linked to each other the way inspections are.
- `corex.settings.rental-inventory.edit`/`.update` — `RentalInventorySettingsController` (see Settings section).
- **Property page tab:** key `'inventory'` (`resources/views/corex/properties/show.blade.php:1417`), positioned immediately after the `'inspections'` tab in the tab array (`:1415-1417` — Rental, Inspections, Inventory in that order) and gated only on `!$isNew` (`show.blade.php:1436`) — confirmed **not** rentals-only: the in-code comment directly above the gate (`show.blade.php:1431-1435`) quotes Johan, 2026-09-22: *"it should be on properties, not only rental properties. inspections are rentals only, not sales."* Content region `show.blade.php:7746-7757`, including `corex.rental-inventories.partials._related-inventories`. Its own comment (`show.blade.php:7747-7752`) documents that it was *moved* here — "moved out of the bottom of the Overview tab into its own tab... so the agent no longer has to scroll past the map/Surveyor General/Key Dates/Tenant blocks to find it. Same single link, same partial, same target route — only its location on the page changed."

**Reads from other pillars**
- Property — `RentalInventory::property(): BelongsTo` (`app/Models/RentalInventory.php:59-61`).
- Leases — `RentalInventory::lease(): BelongsTo` (`:64-66`).
- Agent/User — `createdBy`/`cancelledBy`/`archivedBy` all `belongsTo User` (`:90-102`); every recording/capture action is tied to the acting user.

**Writes to other pillars**
- No evidence found in this pass of `RentalInventory` writing back onto `Property` or `Lease` fields directly — **not found**, stated rather than guessed, same pattern as Rental Inspections above.

**How data enters:** manual only. An agent opens the property's Inventory tab (or the standalone `/rental-inventories` list), which resolves or starts the one open inventory for that property (`resolveOrStartFor()`); line items, photos, and signatures are captured directly against it; `complete()` closes it out, after which — and only then — the move-out comparison becomes available. No import/feed/public-link path found (unlike Rental Inspections, which has a tenant-facing public report link — Rental Inventories has none).

---

### Rental Fault Reports

**NEW SECTION (2026-09-26).** Do not confuse with `App\Http\Controllers\FaultReportController` (top-level namespace, `admin/fault-reports/*`, routes/web.php:1193-1205) — that is an unrelated developer error/exception-capture admin tool (`capture`/`manualReport`/`bulkAction`/`clearAll`/`scan`), not this pillar. The naming collision is exactly the trap that produced the original wrong Rental Inspections claim; named here explicitly to prevent it recurring.

**Screens & routes** (`corex.rental-fault-reports.*`, routes/web.php:3342-3376, prefix `rental-fault-reports`, gated `permission:rental_fault_reports.view`; spec `.ai/specs/rental-work-orders.md` §3a/§6a — Fault Reports and Work Orders share one spec file):
- `corex.rental-fault-reports.index`/`.create`/`.store`/`.show` — `RentalFaultReportController` (routes/web.php:3343-3348)
- `corex.rental-fault-reports.pdf` — landlord-facing PDF, same view-gate as `show()` (routes/web.php:3350)
- `corex.rental-fault-reports.update`/`.cancel`/`.destroy`/`.restore`/`.photos.store` (routes/web.php:3351-3360) — soft delete only.
- `corex.rental-fault-reports.request-approval`/`.approval.store`/`.outcome.store` — `requestApproval`/`recordApproval`/`setOutcome` (routes/web.php:3365-3369ish), separately gated `permission:rental_fault_reports.record_approval`/`.resolve` — per in-code note, a decision becoming final is a heavier permission than logging or editing a report.
- `corex.rental-fault-reports.raise-work-order` — `RentalFaultReportController::raiseWorkOrder()` (routes/web.php:3374 → `RentalFaultReportController.php:333-348`) — the Stage 4 promotion: calls `RentalWorkOrderService::fromFaultReport($rentalFaultReport, $user, $validated)` (`:342`), redirects to the new work order's own show page. This is the confirmed link between the two sibling modules, not just a shared FK.
- **Property page integration:** a "Report a fault" quick-create link inside the property page's **Rental tab** (not Inspections) — `show.blade.php:4445` — with `property_id` **and** `lease_id` pre-filled from the property's active lease (`array_filter(['property_id' => $property->id, 'lease_id' => $activeLease?->id])`), plus a recent-reports list (`show.blade.php:4438-4455`). This is a genuinely embedded, pre-filled entry point — the pattern the Phase 3 automation findings elsewhere in this document call out as *missing* in other modules.
- Sidebar: standalone entry "Rental Fault Reports" (`corex-sidebar.blade.php:1111`), under the Rentals nav group.

**Reads from other pillars**
- Property — `RentalFaultReport::property(): BelongsTo` (`app/Models/RentalFaultReport.php:99-101`).
- Leases/Branch — `lease(): BelongsTo` (`:110-112`), `branch(): BelongsTo` (`:105-107`).
- Rental Inspections — `inspectionItem(): BelongsTo RentalInspectionItem` (`:115-117`), `reportedInspectionObservation(): BelongsTo RentalInspectionObservation` (`:120-122`) — fields exist linking a fault back to a specific inspection finding, but no UI trigger from the Inspections recording screen into fault-report creation was found in this pass — **not found**, stated rather than guessed; the fields may be populated by a path not covered by this pass's grep.
- Contact/Agent — `reportedByContact(): BelongsTo Contact` (`:131-133`), `reportedByUser`/`capturedByUser`/`cancelledByUser`/`createdByUser`: BelongsTo User (`:136-153`) — a fault can be reported by either a Contact (e.g. a tenant relaying it verbally to an agent) or directly by a User.

**Writes to other pillars**
- Agent/User — `RentalFaultReportService::notifyResolved()` (`app/Services/Rentals/RentalFaultReportService.php:84-102`) fires an in-app notification (`NotificationDispatcher`) to the property's listing agent (`$property->agent`) when a fault is resolved — not to a Contact.
- Rental Work Orders — `raiseWorkOrder()` above is the confirmed write: a fault report becomes a work order via `RentalWorkOrderService::fromFaultReport()`, stamping `RentalWorkOrder.reported_fault_report_id` (inverse relation `RentalFaultReport::workOrder(): BelongsTo`, `:126-128`).

**How data enters:** manual only, agent/staff-captured (`RentalFaultReportService::report(Property $property, array $attributes)`, `app/Services/Rentals/RentalFaultReportService.php:27`) — plus `storeApprovalScreenshot()` (`:48`) for an approval paper-trail. **No tenant-facing or public entry point found** — confirmed by grep across the public/no-auth route block (routes/web.php ~5590-5620): unlike Rental Inspections' tokenized public report link, a tenant cannot submit a fault report directly; it must be relayed through an agent.

---

### Rental Work Orders

**NEW SECTION (2026-09-26).** The rental-specific work-order module — distinct from the DealV2/DR2 pipeline "Work Orders" already documented below, which this section's existence now requires that section to disclaim (see the correction added to it). Same spec file as Rental Fault Reports (`.ai/specs/rental-work-orders.md` §3/§6, Stage 4).

**Screens & routes** (`corex.rental-work-orders.*`, routes/web.php:3381-3421ish, prefix `rental-work-orders`, gated `permission:rental_work_orders.view`):
- `corex.rental-work-orders.index`/`.create`/`.store`/`.show` — `RentalWorkOrderController` (routes/web.php:3382-3387) — `store()` is for a work order raised directly (not from a fault report; that path is `RentalFaultReportController::raiseWorkOrder()` above).
- `corex.rental-work-orders.pdf` — supplier-facing PDF, same view-gate as `show()` (routes/web.php:3389).
- `corex.rental-work-orders.update`/`.approval.store`/`.assign-supplier`/`.start-progress`/`.complete`/`.notes.store`/`.cancel`/`.destroy`/`.restore`/`.photos.store` — `RentalWorkOrderController` (routes/web.php:3390 onward) — the lifecycle: assign a supplier, start progress, complete, cancel; `recordApproval` separately gated `permission:rental_work_orders.record_approval`.
- `corex.rental-work-orders.quotes.store`/`.update`/`.select`/`.destroy`/`.restore`/`.download` — `RentalWorkOrderQuoteController` (routes/web.php:3413-3424, `permission:rental_work_orders.manage_quotes`) — multiple suppliers can submit quotes against one work order; one gets selected. In-code comment: "the value the approval-limit gate rides on" (routes/web.php:3411-3412) — i.e. the selected quote's amount is what an approval-threshold setting checks against. No sidebar entry of its own — reachable only from the work order's own show screen. `download` is separately gated `deny_assistant_download` (private disk, not the public photo pattern).
- `corex.settings.rental-work-orders.edit`/`.update` — `RentalWorkOrderSettingsController` (see Settings section) — includes the approval spend-threshold config (spec §3.4b/§8, Stage 3).
- **Property page integration:** a "Work order" quick-create link inside the property page's Rental tab, `property_id`/`lease_id` pre-filled the same way as Fault Reports above (`show.blade.php:4472`), plus a recent-work-orders list (`show.blade.php:4465-4482`).
- Sidebar: standalone entry "Rental Work Orders" (`corex-sidebar.blade.php:1117`).

**Reads from other pillars**
- Property/Lease/Branch — `RentalWorkOrder::property()`/`lease()`/`branch()`: all `BelongsTo` (`app/Models/RentalWorkOrder.php:92-105`).
- Rental Inspections — `inspectionItem(): BelongsTo RentalInspectionItem` (`:108-110`) — same not-found caveat as Fault Reports above: FK exists, no UI trigger located from the Inspections screen in this pass.
- Rental Fault Reports — `reportedFaultReport(): BelongsTo RentalFaultReport` (`:133-135`) — the inverse of the promotion link above.
- **DealV2/Work Orders (the OTHER "Work Orders" module)** — `RentalWorkOrder::supplier(): BelongsTo \App\Models\DealV2\AgencyServiceProvider` (`:113-115`). This is a real, confirmed reuse: the rental-specific work-order module shares the **same supplier directory** as the DealV2-era Work Orders section already in this document, rather than maintaining its own. The two modules are not disconnected after all — they meet at the supplier.
- Contact/Agent — `reportedByContact(): BelongsTo Contact` (`:118-120`), `reportedByUser`/`cancelledByUser`/`createdByUser`: BelongsTo User (`:123-145`).

**Writes to other pillars — the most fully cross-pillar-wired of the three new sections:**
- Contact (property owner) — `RentalWorkOrderService::notifyOwner(RentalWorkOrder $workOrder, string $stage)` (`app/Services/Rentals/RentalWorkOrderService.php:159-166`) resolves `$workOrder->property?->sellerOwnerContact()` (`Property.php:983`) and emails them (`RentalWorkOrderOwnerMail`) at each stage.
- Contact (tenant) — `notifyTenant()` (`:174-192`) resolves the lease's primary tenant contact (`$workOrder->lease?->tenants()->with('contact')->orderByDesc('is_primary')->first()?->contact`) and emails them (`RentalWorkOrderTenantMail`) — deliberately **skipped** when the work order came from a fault report (already notified at that point, per in-code docblock `:167-171`) and skipped entirely for a vacancy with no lease (§3.1a — no tenant to notify).
- Contact (supplier, via the DealV2 `AgencyServiceProvider`) — `notifySupplier()` (`:197-214`) resolves the assigned provider's service-contact email (falling back to the provider's own email) and emails them (`RentalWorkOrderSupplierMail`) — "can even email the supplier," per its own docblock, only once one is actually assigned.
- Also present, not opened in this pass: `notifyCreated`/`notifyCompleted`/`notifyOverdue` (`RentalWorkOrderService.php:108,114,129`) — flagged as existing, not traced to their recipients.

**How data enters:** manual capture (`RentalWorkOrderService::report(Property $property, array $attributes)`, `app/Services/Rentals/RentalWorkOrderService.php:33`) — either directly (agent raises one from the property or the standalone index) or derived (`fromFaultReport()`, `:57`, promoting an approved fault report). No tenant-facing or public entry point found, same as Fault Reports.

---

### Leases

**Screens & routes:**
- `docuperfect.leases.index` — `Docuperfect\SignatureController@leases` (routes/web.php:5023) — renders a literal placeholder stub ("under construction"), confirmed by view content and by `app/Support/Tours/defs/docuperfect.php:221-226` explicitly skipping a tour here for that reason. **No sidebar link; no other blade references this route name.**
- `docuperfect.leases.renew`/`.terminate`/`.history` — `Docuperfect\LeaseController@renewLease/terminateLease/leaseHistory` (routes/web.php:5026-5028) — working lifecycle actions, separate from the non-functional index above.
- `rental.active-leases`/`rental.expired-leases` — `Rental\RentalDivisionController@activeLeases/expiredLeases` (routes/web.php:5052-5053) — a **second, separate, functioning** lease-listing screen, querying `LeaseRecord::visibleTo($user)` (`RentalDivisionController.php:139-161`).
- `rental.dashboard`/`rental.signatures`/`rental.settings` (routes/web.php:5048-5054) — the "Rental Division" e-sign/lease dashboard, plus its own settings sub-prefixes for properties/document-types/reminders (routes/web.php:5059-5075).

**Reads from other pillars**
- Property — **not read.** `RentalDivisionController` queries `App\Models\Rental\RentalProperty` — a **separate model and table** (`rental_properties`) from the pillar `Property` model (e.g. `RentalProperty::where('is_active', true)`, `RentalDivisionController.php:48`; `RentalProperty::find(...)`, `:105`).
- Docuperfect — `LeaseRecord belongsTo Document` and `belongsTo SignatureTemplate` (`app/Models/Docuperfect/LeaseRecord.php:48-55`).

**Writes to other pillars**
- None to the pillar `Property` or `Contact` models. `LeaseRecord.property_id` (`database/migrations/2026_02_26_600007_create_lease_records_table.php:15`) is an unconstrained `unsignedBigInteger` — no FK (migration only adds FKs for `document_id`/`signature_template_id`/`previous_lease_id`/`renewed_lease_id`, lines 31-34). `property_address`/`tenant_name`/`landlord_name` are stored as plain denormalized strings (`LeaseRecord.php:20-24`), not FK-linked to Contact.
- `assignMetadata()` writes `property_id`/`property_address` from the shadow `RentalProperty` model onto the Docuperfect `Document` (`RentalDivisionController.php:104-110`) — an internal Docuperfect write, not a Property-pillar write.

**How data enters:** agents maintain a **separate** `rental_properties` list under `/rental/settings/properties` (`Rental\RentalPropertyController`), independent of the real `properties` table. `LeaseRecord` rows are created by the e-sign completion flow, not by anything touching the Property/Contact pillars.

**Where it actually lives:** split across two disconnected homes, neither cleanly tied to the pillar:
1. `docuperfect.leases.*` under DocuPerfect — the index is a dead stub.
2. A second, unrelated "Rentals" drill-down nested inside the owner-only Hidden sidebar section (`corex-sidebar.blade.php:2773-2802`, `@feature('rentals')`, permission `view_rentals`) — "Rentals", "Dashboard", "Electronic Signatures", "Active Leases", "Expired Leases" — all reading the shadow `RentalProperty` model. Explicitly flagged in-code (`corex-sidebar.blade.php:1008-1018`) as unreconciled with Rental Applications; "Johan is deciding separately."

---

### Work Orders (DR2/DealV2 deal-pipeline panel)

**CORRECTION (2026-09-26) — this section's title and scope were misleading, not wrong.** Everything below is accurate for what it describes, but "Work Orders" as a bare heading implied it was the whole of that concept in CoreX. It is not: it is specifically the **deal-pipeline** work-order panel — COC/CoC-config, DR1/DR2/DealV2 only. A second, unrelated, rental-specific "Work Orders" module — its own sidebar entry, its own controller, its own settings, its own supplier-quote workflow, reached from the property page rather than a deal pipeline step — exists separately. See the new **Rental Work Orders** section above (added the same day this correction was made). The two modules are not entirely disconnected — `RentalWorkOrder::supplier()` reuses this section's own `DealV2\AgencyServiceProvider` model (confirmed in the Rental Work Orders section's Reads bullet) — but they are two distinct features with two distinct UIs, and a reader relying on this section alone would not know the rental one exists.

**Screens & routes:**
- `pipeline.step.work-order.form`/`.send` — `DealV2\WorkOrderController@dr1Form/dr1Send` (routes/web.php:935-936) — DR1 (`App\Models\Deal`) pipeline work-order panel
- `pipeline.step.coc.panel`/`.sync`/`.send` — `WorkOrderController@cocPanel/cocSync/cocSend` (routes/web.php:939-941) — AT-229 Certificate-of-Compliance sub-process
- `pipeline.coc-config.panel`/`.save` — per-agency CoC config (routes/web.php:945-946)
- `work-order.form`/`.send` — `WorkOrderController@form/send` (routes/web.php:965-966) — the DR2 (`DealV2\DealV2`) equivalent panel
- No sidebar entry anywhere (grep of `corex-sidebar.blade.php` for "work-order"/"WorkOrder" returns nothing).

**Reads from other pillars**
- Deal — both DR1 (`App\Models\Deal`) and DR2 (`App\Models\DealV2\DealV2`) read directly as route-bound params (`WorkOrderController.php:38,58,117,132,192,220,254,275,321`).
- Property — `WorkAuthorisationGenerator` reads `$deal->property` building the work-authorisation PDF (`app/Services/DealV2/WorkAuthorisationGenerator.php:63,101`).
- Contact — `AgencyServiceProvider belongsTo Contact` (`app/Models/DealV2/AgencyServiceProvider.php:87`) — supplier firm's primary contact is a real Contact-pillar record.

**Writes to other pillars**
- Deal — `work-order.send` creates `DealDocumentDistribution` rows tying the outbound work order to the deal's pipeline step.
- Contact/DealV2 — `$providers->attachToDeal($dealV2, $provider, 'service_provider')` (`WorkOrderController.php:83`) links the supplier's Contact to the deal.
- Property/Compliance — per spec (`.ai/specs/dr2-supplier-work-orders.md` §2 Q4), a returned CoC auto-files "to the right pillar" per document-type rules; specific filing call site not traced in this pass ("not found" beyond the spec's own statement).

**How data enters:** manual, deal-driven — an agent working a Deal/DealV2 pipeline step optionally triggers a work order (per-step config on `deal_pipeline_steps.work_authorisation_template_id`); never automatic. Supplier is picked or created ad-hoc at send time.

**Where it actually lives:** not standalone. Lives entirely inside the DealV2 pipeline-step UI — `app/Models/DealV2/DealStepWorkOrder.php` (per-instance) and `DealPipelineStepWorkOrder.php` (step-template config). Spec confirms status "BUILT on QA1." No independent nav entry; reached only from within a deal's pipeline step. **This is the only kind of "work order" this section knows about — see the separate Rental Work Orders section above for the rental-specific module, which has its own standalone sidebar entry.**

---

### Core Matches

**Screens & routes:**
- `corex.core-matches.index` — `ContactMatchController@index` (routes/web.php:4032-4034)
- `corex.core-matches.all` — `ContactMatchController@allView` (routes/web.php:4037-4039), gated `core_matches.all_view`
- `corex.rentals.core-matches.index`/`.all` — same methods, locked by route name to rentals (routes/web.php:4047-4056)
- `corex.core-matches.reassign` — `ContactMatchReassignmentController@reassign` (routes/web.php:4065-4067)
- `corex.core-matches.shares.confirm` — `ContactMatchShareController@confirm` (routes/web.php:4077-4079)
- `corex.core-matches.share-history`/`.new-since-share` — `ContactMatchShareHistoryController` (routes/web.php:4087-4089,4096-4098)
- Nested under Contacts (`corex.contacts.matches.*`): store/edit/update/setStatus/results/print/toggleHide/convertToDeal/destroy — `ContactMatchController` (routes/web.php:4237-4245)

**Reads from other pillars**
- Contact — every match action scoped `abort_if($match->contact_id !== $contact->id, 403)` (`ContactMatchController.php:621,638,658`).
- Property — candidate pool via `MatchingService::propertiesForMatch()` (`app/Services/Matching/MatchingService.php:286-302,219`); specific property resolution for hide/convert (`ContactMatchController.php:643,678`).

**Writes to other pillars**
- Deal — `convertToDeal` creates a `Deal` (`property_id`, `agency_id`, `branch_id`, buyer fields from Contact, `deal_type`) in a transaction (`ContactMatchController.php:667-700`).
- Contact/Buyer Pipeline — `ContactMatchObserver::created()` (fires on every `ContactMatch::create`) calls `BuyerStateService::landOnPipeline($contact, 'wishlist_created', ...)` (`app/Observers/ContactMatchObserver.php:71-100`), setting `Contact.is_buyer=true`, `buyer_pipeline_entered_at`, `buyer_state='new'` (`app/Services/BuyerStateService.php:222-246`).
- Manual match capture additionally invokes `App\Services\Buyers\BuyerLeadCascadeService` right after `ContactMatch::create()` — "manual capture rides the SAME cascade as a portal lead" (`ContactMatchController.php:518-525`).

**How data enters**
- Manual: `ContactMatchController@store` (`:520`).
- Derived from Buyer Pipeline: `BuyerDetailController@addWishlist/saveWishlist` create/update `ContactMatch` rows from the Buyer Detail screen (`app/Http/Controllers/CommandCenter/BuyerDetailController.php:182,212`).
- Feed: portal leads reportedly create `ContactMatch` rows via the same `BuyerLeadCascadeService` (comment at `ContactMatchController.php:518-519`); exact portal-lead call site not opened in this pass (mechanical-sources-only scope) — flagged "not found".

---

### Buyer Pipeline (Command Center Buyers + Viewing Packs)

**Screens & routes:**
- `command-center.buyers.pipeline`/`.show` — `BuyerPipelineController@index`, `BuyerDetailController@show` (routes/web.php:2031-2032)
- `command-center.buyers.wishlists.add`/`.update`/`.primary`/`.archive`/`.matches` — `BuyerDetailController` (routes/web.php:2037-2042)
- `command-center.buyers.playbook-action`/`.mark-lost`/`.reengage` (routes/web.php:2044-2046)
- `command-center.buyers.update-state` — PATCH `buyers/{contact}/state` — `BuyerPipelineController@updateState` (routes/web.php:2067)
- `command-center.buyers.portal-links.generate`/`.revoke` — mint/revoke `buyer_portal_links` (routes/web.php:1967-1998)
- `corex.rentals.pipeline.index` — same `BuyerPipelineController@index`, locked to `lead_type='rental'` (routes/web.php:3993-4000)
- `corex.viewing-packs.index`/`.store`/`.from-event`/`.show`/`.update`/`.destroy`/`.restore`/`.update-appointment` — `CommandCenter\ViewingPackController` (routes/web.php:2091-2101)
- `corex.viewing-packs.buyer-pack`/`.agent-sheet` — PDF downloads (routes/web.php:2103-2105)
- `corex.viewing-packs.properties.add`/`.remove`/`.search`/`.reorder` — `ViewingPackController` (routes/web.php:2108-2112)
- `corex.viewing-packs.properties.documents.*`, `.redaction-data`, `.redact`, `.redacted-file` (routes/web.php:2114-2118)

**Reads from other pillars**
- Contact — pipeline board query `Contact::buyers()->with(['agent','matches'])` (`BuyerPipelineController.php:72,107,172,382,435`).
- Property — viewing-pack picker `Property::findOrFail(...)` (add), `Property::query()...` (search) (`ViewingPackController.php:186,391`).
- Core Matches — `ContactMatch::STATUS_ACTIVE`, `conflictingFeatureTokens()` used in wishlist create/update (`BuyerDetailController.php:182,212,341`).

**Writes to other pillars**
- Core Matches — `BuyerDetailController@addWishlist/updateWishlist` create/update `ContactMatch` rows (`BuyerDetailController.php:168-274`).
- Contact — `BuyerPipelineController@updateState` and `BuyerDetailController@markLost/reengage/markPlaybookAction` update Contact buyer-state fields via `BuyerStateService::transitionTo()` (same service as Core Matches' `landOnPipeline()`, `app/Services/BuyerStateService.php:244`).

**How data enters**
- Manual: `BuyerPipelineController@updateState` (routes/web.php:2067); `BuyerDetailController@addWishlist` (`BuyerDetailController.php:198`).
- Derived from Core Matches: every `ContactMatch::create()` auto-lands the contact onto the pipeline (`ContactMatchObserver.php:71-100`).
- Manual (Viewing Packs): `ViewingPackController@store` creates a pack tied to `contact_id` (`ViewingPackController.php:409-454`).
- Derived (Viewing Packs from Calendar): `launchFromEvent`/`regenerateFromEvent` resolve the buyer from a `CalendarEvent`'s `contact_id`/`calendar_event_links` (`ViewingPackController.php:535-620`).

---

### Presentations/CMA

**Screens & routes:**
- `presentations.index/create/store` — `Presentation\PresentationController` (routes/web.php:4350-4352) — **`store()` is soft-retired**, redirects with "New presentations are now created from a property" (`PresentationController.php:161-168`).
- `corex.properties.generate-presentation`/`.presentation-coverage` — `PresentationGeneratorController@generate/coverage` (routes/web.php:3712-3714) — the actual, live one-button entry point (AT-17 retirement note, `PresentationController.php:163-165`).
- `presentations.show/edit/update/analysis`, `.analysis.run/confirm/reopen`, `.analysis-selections` (routes/web.php:4354-4357,4462-4473)
- `presentations.upload/uploads.type/override/re-extract`, `.links.*` — evidence upload (CMA PDFs, portal links) (routes/web.php:4476-4499)
- `presentations.snapshots.saveSnapshot/showSnapshot`, `.compile`, `.simulate` — immutable snapshot generation (routes/web.php:4503-4511)
- `presentations.holding-cost`, `.simulate-trajectory`, `.price-band`, `.competitive-threats`, `.pricing-simulator*` (routes/web.php:4519-4541)
- `presentations.seller-live/capture`, `.brain` (routes/web.php:4545-4551)
- `presentations.snapshot-links.store/revoke/extend`, `.teaser-leads` (routes/web.php:4432-4439)
- `presentations.outcome.record/update` — `PresentationOutcomeController@record/update` (routes/web.php:4443-4445)
- `presentations.ai-summary.generate/accept`, `.deliveries.preview/send/index`, `.portal-captures.*`, `.live-snapshot` (routes/web.php:4448-4460,4563-4573)
- `presentations.restore` — soft-delete recovery (routes/web.php:4577)
- Public (no auth): `GET /p/{token}`, `POST /p/{token}/track`, `/capture-lead`, `/refresh`, `/request-revision` — `PublicPresentationController` (routes/web.php:125-147)
- `corex.presentations.outcomes`/`.analytics`, `.refresh-requests.*` (routes/web.php:224,228,269-282)
- `POST /portal-captures/ingest` — Chrome-extension portal-capture ingest (routes/web.php:5654)

**Reads from other pillars**
- Property — `Presentation::property()` belongsTo (`app/Models/Presentation.php:155`); `generate()` reads `property_type`, `agency_id/suburb/city/town` (`PresentationGeneratorController.php:39,79-80`).
- Contact — `Presentation::seller()` belongsTo Contact (`app/Models/Presentation.php:165`).
- Deal — `Presentation::deal()` belongsTo Deal (`app/Models/Presentation.php:170`).
- Agent/User — `Presentation::createdBy()` (`:148`); `generate()` resolves `acting_for_user_id` (`PresentationGeneratorController.php:106-108`).

**Writes to other pillars**
- Property — `PropagateCmaToProperty` listener on `PresentationFieldsExtracted` calls `PropertyCmaPropagationService::propagateFromPresentation()` to back-propagate CMA fields to the linked Property (`app/Listeners/Presentation/PropagateCmaToProperty.php:50,62-66`).
- Tracked Property — same listener feeds `TrackedPropertyMatchOrCreateService` even with no linked Property (`:22-23`).
- Deal — `PresentationOutcomeController::update()` persists `resulted_in_deal_id` (`nullable|integer|exists:deals,id`, `PresentationOutcomeController.php:62`) — **agent-recorded, not automatic.**

**How data enters**
- Property-first one-click: `PresentationGeneratorController::generate(Property $property)` (`:30`) — sale properties only, rentals rejected (`.ai/specs/presentations.md:14`).
- Manual evidence upload: agent-uploaded CMA PDFs, parsed via `MarketReportIngestService::ingest()` (`PresentationGeneratorController.php:69-77`).
- Import: Chrome-extension "PortalCapture" ingest (`Presentation\PortalCaptureController@ingest`, routes/web.php:5654).
- Legacy manual-capture path (`PresentationController::store()`) explicitly retired — "minted property_id-NULL orphans" (`PresentationController.php:163-168`).

---

### DR2 (Deal Register / Deals)

**Screens & routes:**
- `deals-dr2.index`/`.create`/`.store` — `Dr2\DealRegisterController` (routes/web.php:8-10, within the `deals-dr2` prefix block starting 821)
- `deals-dr2.search.properties`/`.eligible-properties`/`.property-contacts`/`.contacts` — capture-time JSON feeds (routes/web.php:15,24-26)
- `deals-dr2.contact.inline`/`.attorney.search`/`.attorney.inline` — inline contact/attorney creation (routes/web.php:27,33-34)
- `deals-dr2.suppliers.search`/`.suppliers.inline` — `DealV2\SupplierDirectoryController` (routes/web.php:30-31) — shared supplier directory reused by DR2
- `deals-dr2.unfiled-emails.*` — `Dr2\UnfiledEmailsController` (routes/web.php:39-48) — email-to-deal filing workflow
- `deals-dr2.comms-body.show`/`.attachment` — `Dr2\CommunicationBodyController` (routes/web.php:53-54)
- `deals-dr2.edit`/`.update`/`.quickUpdate` (routes/web.php:56,58-59)
- `deals-dr2.properties.add/remove/restore/updatePrice` — AT-398 multi-property deal support (routes/web.php:62-65)
- `deals-dr2.log`/`.remark` (routes/web.php:69-70)
- `deals-dr2.settle`/`.settle.save`/`.settle.print`/`.settle.print.agent` — `Dr2\DealSettlementController` (routes/web.php:73-76)
- `deals-dr2.pipeline`/`.pipeline.view/.timeline/.list` — `Dr2\PipelineController`/`PipelineTimelineController`/`PipelineListController` (routes/web.php:80,82,84,94)
- `deals-dr2.pipeline.step.*` (~20 actions — complete/reopen/add/na/remove/comment/due/follows/restore/reinstate/reschedule/reorder/dates/decline/bond-attorney/structure/attach) — AT-334 suspensive-condition pipeline engine (routes/web.php:85,97-117)
- `deals-dr2.communications.index/search/link/unlink` — `Dr2CommunicationLinkController` (routes/web.php:89-92) — manual email-to-deal linking (CX-108)
- `deals-dr2.pipeline.step.work-order.form/send`, `.coc.*` — `DealV2\WorkOrderController` (routes/web.php:121-132) — AT-229, runs off the DR2 (legacy-table) pipeline
- `deals-dr2.documents.store/download` — `Dr2\DealDocumentController` (routes/web.php:135-136)
- `deals-dr2.proforma.generate` — `Proforma\ProformaController@generate` (routes/web.php:140)
- `deals-dr2.distribute.compose/send/adhoc/party-email` — `Dr2\DealDistributionController` (routes/web.php:144-149) — AT-228 party-first document distribution
- `deals-v2.pipeline.*` (index/master/create/store/load-defaults/edit/update/destroy/duplicate/steps.*) — prefix `deals-v2/pipeline-setup` (routes/web.php:176-195) — **global template admin, shared by both DR2 and the retired deals-v2 module**
- `deals-v2.settings.service-types.*` — COC/service-type list feeding the DR2 work-order dropdown (routes/web.php:199-203)
- `deals-v2.suppliers.*` — shared supplier directory admin, used by DR2 (routes/web.php:208-224)
- `deals-v2.secure-doc.*` (public, no auth) — tokened+OTP recipient document flow (WS4), reused by DR2 distribution (routes/web.php:234-246)
- `deals-v2.ical` — public `.ics` feed (routes/web.php:251)
- `deals-v2.index/.overview/.export/.create/.store/.show/.edit/.update/.destroy` + step/remark/stage/settlement routes (routes/web.php:254-306) — **the separate `deals_v2` table module; `DealV2Controller::show()` is soft-retired per AT-219** (routes/web.php:819; `.ai/investigations/2026-08-01-dr2-completeness-audit.md:44`)

**DealV2 vs Dr2 — confirmed generation split, not accidental duplication**
- `routes/web.php:816-820` comment: "DR2 rebuilds DR1 on the SAME `deals` tables (spec `deal-register-v2-rebuild-spec.md`), coexisting with DR1 behind its own nav + permission. Distinct from the abandoned deals-v2 module (URI `deals-v2/*`), which sunsets under AT-219."
- `app/Models/Deal.php` (DR1/DR2 table `deals`) vs `app/Models/DealV2/DealV2.php` (separate table `deals_v2`) — two entirely different Eloquent models/tables.
- `.ai/specs/deal-register-v2-spec.md:15-16`: "DR2 is not greenfield... Phase 1–3 of v1.1 is already built (`deals_v2`, `App\Models\DealV2\*`) but holds 0 rows on dev and live and is untested" → "DR2 becomes the canonical operational deal store... DR1 (`deals`/`App\Models\Deal`) stays live and untouched behaviourally through the transition."
- `.ai/investigations/2026-08-01-dr2-completeness-audit.md:44`: `DealV2Controller` is AT-219 soft-retired — "routes redirect, bodies archived." **CORRECTION (Pass 3, 2026-09-26): this is only partially true.** `index()`/`create()`/`show()` etc. do redirect via `dr2RetiredRedirect()`, but `store()` (`DealV2Controller.php:263`, route `deals-v2.store`, `routes/web.php:1089`) does **not** — it is live, permission-gated (`permission:deals_v2.create,deals_v2.capture_own`), and writes a real row through `DealPipelineService::createDeal()` → `DealV2::create()` (`app/Services/DealV2/DealPipelineService.php:20-25`). The "0 rows on dev and live" figure two paragraphs below is a verbatim quote from a spec dated 2026-07-02 (`.ai/specs/deal-register-v2-spec.md:16`) — a snapshot at spec-write time, not something this document has verified as still true, and it cannot be verified without DB access. Treat it as unverified, not as current fact.
- **Net effect — corrected (Pass 3):** `App\Models\DealV2\*` (the `deals_v2` engine, described elsewhere as "abandoned") is kept alive for more than its shared config sub-systems (pipeline templates, supplier directory, secure-doc, work-orders, iCal, COC config) that the actively-developed `Dr2\*` controllers still call into — it also has its own live, unredirected deal-creation write path (`DealV2Controller::store()`, see correction above) that has nothing to do with DR2 calling into it. Whether that write path still gets real traffic was not determined in this pass (would require DB access) — but the code itself is not retired, regardless of traffic.

**Reads from other pillars**
- Property — `DealRegisterController::persistDeal()` writes/reads `deals.property_id` and multi-property links via `DealProperty` (`DealRegisterController.php:534,554,828`); `DealPropertyStatusService::committedDealOnProperty()` checks for conflicting active/granted deals on the same property (`:218`).
- Contact — `Deal::contacts()` belongsToMany via `deal_contacts` (`app/Models/Deal.php:300`), populated via `propertyContacts()`/`contactSearch()`/`contactInline()`.
- Agent/User — `Deal::agents()` belongsToMany User (`:381`); `managedBy()` belongsTo User (`:342`).
- Presentation — `Deal::presentation()` belongsTo Presentation via `presentation_id` (`:334-336`). **Not found:** any DR2 code path that populates `presentation_id` at deal-create time — the reverse write (Presentation → Deal) is the one confirmed (see Presentations section).

**Writes to other pillars**
- Property status — `DealCreated` event (`DealRegisterController.php:587,1743`) → `FlagPropertyUnderOfferOnDealCreated::handle()` sets `status='under_offer'` (`app/Listeners/Deal/FlagPropertyUnderOfferOnDealCreated.php:58`).
- `DealStageAdvanced` → `EnsurePropertyUnderOfferOnGrant::handle()` sets `status='under_offer'` on grant (`app/Listeners/Deal/EnsurePropertyUnderOfferOnGrant.php:71`); `MarkPropertySoldOnDealMilestone::handle()` sets `status='sold'` (`app/Listeners/Deal/MarkPropertySoldOnDealMilestone.php:67`).
- `DealClosed` → `RevertPropertyStatusOnDealDeclined::handle()` reverts `status` on decline (`app/Listeners/Deal/RevertPropertyStatusOnDealDeclined.php:86`).
- Contact — `ContactLinkedToProperty` fired from attorney/contact capture (`DealRegisterController.php:1489`) → `App\Listeners\Contact\MarkBuyerWonOnPropertyLink` and `PromoteOwnerToSellerOnPropertyLink` react, updating Contact records off deal/property linkage.

**How data enters**
- Manual capture: `DealRegisterController::store()`/`update()` via `/deals-dr2/create` (`DealRegisterController.php:373`).
- Property/contact linkage is picked from existing CoreX records via search endpoints — "no freeform property addresses or client names" (`.ai/specs/deal-register-v2-spec.md:19`).
- **Not found:** a "create-deal-from-accepted-presentation" one-click flow. The presentation→deal relationship found is the reverse: an agent manually records `resulted_in_deal_id` on the presentation.
- Import: unfiled-email filing (`Dr2\UnfiledEmailsController::file/fileBatch`) links inbound `Communication` records onto an existing deal — does not create the deal.

---

### Deeds Capture

**Screens & routes:**
- `corex.deeds-capture.index` — `CoreX\DeedsCaptureController@index` (routes/web.php:5369) — review/confirm screen for captured deeds data
- `corex.deeds-capture.promote` — `@promote` (routes/web.php:5370-5371) — confirm and create/link an Agency Stock Property
- `corex.deeds-capture.acknowledge-blocked-match` — `@acknowledgeBlockedMatch` (routes/web.php:5374-5376)
- `corex.deeds-capture.tva.ingest`/`.tva.dismiss` — `@ingestTva/dismissTva` (routes/web.php:5377-5379,5390-5391) — "The Virtual Agent" contact-capture tick-to-ingest
- `corex.deeds-capture.dismiss` — `@dismissProperty` (routes/web.php:5381-5382) — reversible soft removal
- `corex.deeds-capture.reject-match` — `@rejectMatch` (routes/web.php:5386-5388) — breaks a wrong tracked-property match link, never touches record data
- `corex.deeds-capture.owner-conflict.resolve` — `@resolveOwnerConflict` (routes/web.php:5392-5395)
- `v1.deeds-capture` — `Api\DeedsCaptureController@store` (routes/api.php:464) — Chrome-extension ingest endpoint, Sanctum-token authenticated

**Reads from other pillars**
- Property — `TrackedProperty::promotedProperty()` belongsTo Property via `promoted_to_property_id` (`app/Models/Prospecting/TrackedProperty.php:189`); `promote()` checks it before re-promotion (`DeedsCaptureController.php:704`).
- Contact — `TrackedProperty::ownerContact()` belongsTo Contact (`app/Models/Prospecting/TrackedProperty.php:272`); `Api\DeedsCaptureController` dedupes incoming owners against existing Contacts via `id_number`/`entity_reg_no` (`app/Http/Controllers/Api/DeedsCaptureController.php:437,600,738-746`).
- Agent/User — `promote()` resolves `$request->user()`, stamps `promoted_by_user_id`/`deeds_captured_by_user_id` (`TrackedProperty.php:194,199`).

**Writes to other pillars**
- Property — `promote()` calls `$matcher->promoteToStock(...)` to create/update the Agency Stock Property (`DeedsCaptureController.php:1030`); deeds-specific field overrides composed first (`:707-720`).
- Contact — `Api\DeedsCaptureController` creates natural-person owner Contacts (`Contact::create()`, `DeedsCaptureController.php:712`) and entity-owner Contacts (`contact_kind=TYPE_ENTITY`, `:761-764`) directly from the captured deeds payload.

**How data enters**
- Import (primary): `Api\DeedsCaptureController::store()` — Chrome-extension POST from CMA Info deeds-lookup pages, batched, `source_ref` required for idempotency (`.ai/specs/deeds-capture.md:16-24`; `DeedsCaptureController.php:41`), routed through `TrackedPropertyMatchOrCreateService::matchOrCreate()` per non-negotiable #10.
- Manual confirm/promote: agent reviews on `/corex/deeds-capture`, clicks Promote (`DeedsCaptureController::promote()`, `:686`).
- Per spec (`.ai/specs/deeds-capture.md:9-11`): two plays — (1) prospecting from MIC (capture → suspense → Pitch Now promotes), (2) own stock (capture → confirm on Deeds Capture screen → create property + owner). Deeds captures are explicitly filtered out of MIC Opportunities.

---

### Prospecting / Tracked Properties (Market Intelligence)

**NEW SECTION (Pass 3, 2026-09-26).** CLAUDE.md non-negotiable #10 calls this the architectural core of how ALL property data is supposed to enter CoreX exactly once: "Every data ingress into CoreX — CMA presentations, P24 alerts, PP feed events, Chrome capture imports, manual entries, mandate signings, scraping outputs, deeds-office lookups, any future source — MUST call `TrackedPropertyMatchOrCreateService::matchOrCreate()` before storing property data... No property data ever sits orphaned." Two tiers: `tracked_properties` (Prospecting → Tracked Properties, "every property CoreX has intelligence on") vs `properties` (Agency Stock). Never walked as its own pillar before this pass — only referenced in passing inside Deeds Capture and D1.

**Screens & routes** (`prospecting.*`/`market-intelligence.*`, `routes/web.php:5894-5931,6072-6117`, `permission:access_prospecting`+`feature:prospecting`):
- `market-intelligence.work` (default landing), `.opportunities`/`.opportunities.show`, `.analyse`, `.market-pulse`, `.portal-alerts` (P24 alerts — awaiting address queue), `.team` (BM dashboard), `.stale-review`/`.stale-review.reassign`/`.keep` (anti-poaching reassignment), `.claim`/`.release`/`.feedback` on a listing — `CoreX\MarketIntelligenceController` (routes/web.php:5894-5928).
- Legacy `prospecting.index` (routes/web.php:6072-6117) now 301-redirects to the canonical `market-intelligence` URL (routes/web.php:6104-6117); sub-paths stay on the legacy controller during the migration window.
- Sidebar: "Market intelligence" nav item (`corex-sidebar.blade.php:795-796`), relabelled from "Prospecting" (comment, `:755-756`).

**Reads from other pillars**
- Property — reads `p24_listings`/`prospecting_listing` rows for the portal-alerts queue.
- Agent/User — `claim()`/`release()` tie a listing to the claiming agent.

**Writes to other pillars**
- Promotion to Agency Stock happens elsewhere (Deeds Capture's `promote()`, `PropertyController@convertFromProspecting` — both already cited in the Deeds Capture and Properties sections), not inside `MarketIntelligenceController` itself.

**The 5-strategy match — confirmed real.** `TrackedPropertyMatchOrCreateService::resolveMatch()` (`app/Services/Prospecting/TrackedPropertyMatchOrCreateService.php:444`), priority order documented at `:27`, with per-strategy conflict-veto comments throughout (`:468-577`).

**How data enters — confirmed for most named sources, with a real gap in two:**
- `matchOrCreate()` IS called from: P24 (`P24LeadService.php:281`, `Property24SyndicationService.php:127`), PP feed (`PpLeadService.php:325`), Chrome capture/deeds (`DeedsCaptureController.php:363`), Chrome capture/presentations-portal (`PortalCaptureController.php:1292`), CMA presentations (`PropagateCmaToProperty.php:125`, already correctly cited in the Presentations/CMA section), market-report parsing (`ParseMarketReportJob.php:214`), map-drawn activity (`MapActivityController.php:362`, `MapController.php:646`), manual seller-outreach entry (`EntryPointController.php:496`), and the Prospecting API (`ProspectingApiController.php:560`).
- **"Manual entries" and "mandate signings" do NOT actually call it — the non-negotiable overclaims its own coverage.** `PropertyController::store()` (`app/Http/Controllers/CoreX/PropertyController.php:987`) — the standard "New Listing" save — creates the row directly at `Property::create($data)` (`:1180`) with **zero** reference to `TrackedPropertyMatchOrCreateService`/`TrackedProperty` anywhere in the method. A narrower, different mechanism exists — `ContactAddressPropertyGuard::findLinkableProperty()`/`findHeldForContact()` (`PropertyController.php:869-882`) — but it only runs inside `create()` (the GET form-render, `:826`), only when the create screen was opened from a Contact's address context, and only *surfaces a warning on the form* — nothing blocks or auto-matches at save time. `PropertyWizardController::createDraft()` (`:159`, `Property::create($data)` at `:244`) has the same gap — zero `TrackedProperty`/`matchOrCreate` references anywhere in the file. Every Docuperfect controller/service was grepped for `matchOrCreate`: zero hits against `TrackedPropertyMatchOrCreateService` — the only `matchOrCreate` in Docuperfect is `TenantContactResolver::matchOrCreate()` (`SignatureService.php:5518`), a **Contact**-matching helper for lease tenant names, unrelated to property intelligence. No mandate-signing flow feeds Prospecting/MIC at all.

**What this means in Johan's framing:** the rule that's supposed to guarantee "no property data ever sits orphaned, matched exactly once" has a hole exactly where it matters most — the ordinary, everyday "agent types in a new listing" flow. An agent creating a property by hand today gets, at best, a one-time warning on the form (only if they arrived from a Contact's address) that CoreX might already know this address — nothing stops them from typing the full address, suburb, GPS, etc. a second time even when Prospecting/MIC already holds a matched Tracked Property with prior intelligence on that exact address. The fix isn't a new screen — it's wiring the same `matchOrCreate()` call that P24/PP/deeds-capture/CMA already use into `PropertyController::store()` and `PropertyWizardController::createDraft()`, so manual creation gets the same match-first behaviour every automated feed already has. **Not determined in this pass, flagged not guessed:** whether `ContactAddressPropertyGuard` and `TrackedPropertyMatchOrCreateService` should be unified into one guard, or whether the manual-entry gap is a deliberate speed trade-off — that is a product call for Johan.

---

### E-sign/DocuPerfect

**Screens & routes:** 214 route names under `docuperfect.*` (`routes/web.php:4606-5044`) plus 42 tokenized recipient routes under `sign/*` (`routes/web.php:5147-5211`) and 3 signed-document-download routes (5215-5217). Grouped by sub-namespace (route-name counts):
- `docuperfect.signatures.*` (50) — e.g. `sign` → `SignatureController@sign` (`app/Http/Controllers/Docuperfect/SignatureController.php:879`); `send-for-signature` → `@sendForSignature` (`:1975`); `audit` → `@audit` (`:2278`)
- `docuperfect.esign.*` (34) — internal e-sign wizard — `ESignWizardController@store` (`app/Http/Controllers/Docuperfect/ESignWizardController.php:131`), `@searchProperties` (`:1128`), `@searchContacts` (`:1445`)
- `docuperfect.import.*` (14) — `DocumentImporterController@parse/generate` (`:122,525`)
- `docuperfect.templates.*` (13) — `TemplateController@index/upload/edit` (`app/Http/Controllers/Docuperfect/TemplateController.php:23,105,211`)
- `docuperfect.settings.*` (12) — document-type & named-field admin (routes/web.php:4703-4716)
- `docuperfect.sales.*` (12) — `SalesDocumentController@sendToClient/handleUpload` (`app/Http/Controllers/Docuperfect/SalesDocumentController.php:78,372`)
- `docuperfect.documents.*` (12) — `DocumentController@index/store/archive` (`app/Http/Controllers/Docuperfect/DocumentController.php:21,78,287`)
- `docuperfect.packs.*` (9), `.clauses.*` (7), `.web-packs.*` (6), `.webPreview.*` (6), `.cds.*` (6, CDS DB-backed draft builder), `.field-groups.*` (5), `.leases.*` (4), `.amendments.*` (4), `.rental.*` (3), `.api.*` (3), plus singletons (strikethroughs, recipient-templates, property, parser-test, page, conditions, compiler, attachments, dashboard, create)
- `docuperfect.compiler.*` (11, routes/web.php:4581-4597) — `CompileStudioController`, gated `permission:esign.compiler.view/compile/publish`
- Recipient signing (tokenized, no login): `signatures.external.*` (42 routes, 5147-5211) — `SigningController` — `show`/`verify`/`capture`/`complete`/`acceptAmendment`

**Reads from other pillars**
- Property — `ESignWizardController::searchProperties` queries `Property::searchAddress($q)->with('agent')` (`:1138`) and `RentalProperty::where(...)` (`:1203`); `Document belongsTo RentalProperty` via `property_id` (`app/Models/Docuperfect/Document.php:75`); `Flow belongsTo Property` (`app/Models/Docuperfect/Flow.php:52`).
- Contact — `Document belongsToMany Contact` via `document_contact` (`Document.php:80`); `Flow belongsTo Contact` (`Flow.php:57`); `ESignSigningParty belongsTo Contact` (`app/Models/Docuperfect/ESignSigningParty.php:55`); `SignatureRequest belongsTo Contact` and `FicaSubmission` (`app/Models/Docuperfect/SignatureRequest.php:218,223`).
- Agent/User — `Document belongsTo User` as `owner_id` (`Document.php:55`); `SignatureTemplate belongsTo User` as `created_by`/`supervisor_user_id`/`rejected_by` (`SignatureTemplate.php:161,166,421`); `SignatureRequest belongsTo User` as `sent_by`/`reviewed_by`/`authorised_by` (`SignatureRequest.php:213,228,233`).

**Writes to other pillars**
- Contact — `ESignWizardController` creates a new Contact when no duplicate match found during recipient capture (`Contact::create()`, `ESignWizardController.php:840-847`), gated by `ContactDuplicateService::findDuplicates()` (`:827-838`) for auto-link-to-existing instead.
- Deal — **not found**: no `Deal::`/`DealV2\Deal` reference anywhere in `app/Http/Controllers/Docuperfect/*.php`.

**How data enters**
- Manual: e-sign wizard (`ESignWizardController@create/store/saveStep`, `:59,131,680`) or direct upload (`SignatureController@processUploadAndSend`, `:87`).
- Import: `DocumentImporterController@parse/generate` (`:122,525`) parses uploaded source files (e.g. CDS) into templates/drafts.
- Recipient-entered: tokenized `sign/{token}/*` flow lets an external recipient (no CoreX login) capture signatures/fields/amendments directly.
- Derived: property/contact identity pulled from search endpoints rather than retyped — **but only inside the wizard, not carried in from the Property/Contact screens** (see Phase 2 findings).

---

### Portal Syndication

**Screens & routes:** three parallel portal integrations, all mounted as sub-actions on the property record (nested inside `corex.properties.` group, routes/web.php:3706), plus one standalone P24 admin/market-intel block:
- Private Property (SOAP) — `syndication.*` (14 routes, 3942-3959) — `PrivateProperty\SyndicationController@submit/toggle` (`:80,34`); agent-level `syndication.agent.register` (`:390`)
- Property24 (REST, ExDev v53) — `p24-syndication.*` (7 routes, 3961-3967) — `Property24\P24SyndicationController@submit/syncState` (`:70,120`)
- Agency website — `website-syndication.*` (4 routes, 3969-3972) — `Website\WebsiteSyndicationController@refresh` (`:78`)
- P24 market-intelligence admin (inbound market data, separate concern from outbound sync) — `admin/p24/*` (routes/web.php:794-798) — `Admin\P24Controller@listings/runImport`, gated `permission:manage_p24`
- Per spec (`.ai/specs/p24-syndication.md:1-16`): P24 push uses `POST /listing/v53/listings` and `PUT .../{listingNumber}/status`, images inline base64; model `P24SyndicationLog`; jobs `SubmitListingToProperty24`, `SyncProperty24Activations`

**Reads from other pillars**
- Property — every syndication method takes a bound `Property $property` (route-model binding); `P24SyndicationLog belongsTo Property` (`app/Models/P24SyndicationLog.php:34`).
- Agent/User — `Property24SyndicationService` resolves listing agent from `$property->agent ?? User::find($property->agent_id)` and `pp_second_agent_id` (`app/Services/Syndication/Property24/Property24SyndicationService.php:257,416-417,812`); reads `p24_agent_id`/`p24_agent_agency_id`/`p24_profile_signature`/`p24_photo_signature` off User (`:424,428,881-882`).

**Writes to other pillars**
- Property — writes back `properties.p24_image_signature` after an image push (`:352,398-399`), per the CLAUDE.md "refresh costs one call" contract.
- Agent/User — writes `p24_agent_id`/`p24_agent_agency_id` on first resolve/register (`:922`), `p24_profile_signature`/`p24_photo_signature` via `saveQuietly()` after a profile/photo push, fingerprint-gated to avoid re-pushing unchanged data (`:960,992`).
- Reconciliation/audit only, no direct write: `PortalInventoryGuard::snapshot/classify/conflictFor(Property)` (`app/Services/Syndication/PortalInventoryGuard.php:47,100,151`) compares CoreX state against live PP portal inventory; `AuditPortalInventory` command runs it on schedule, reporting drift rather than writing it.

**How data enters**
- Manual: agent clicks toggle/submit/deactivate on the property page.
- Feed/scan: `PortalInventoryGuard::snapshot()` pulls the agency's current PP listings to detect conflicts (`:47`); `admin/p24/import` ingests P24 market-intelligence listings inbound (separate from outbound push).
- Derived: outbound payloads built entirely from existing Property/User fields — no separate portal-specific data-entry form.

---

### Communication Mailboxes

**Screens & routes:** WhatsApp-device linking, IMAP mailbox setup, staff triage/suspense/access-request flows (`app/Models/Communications/CommunicationMailbox.php`, `Communication.php`):
- `communications.wa-devices.*` (6, 2539-2546) — `Communications\WaDeviceController`
- `communications.wa-link.*` (5, 2550-2556) — `WhatsAppLinkController@status/qr/link/unlink` (QR pairing to WAHA)
- `communications.capture.*` (4, 2560-2566) — `AgentCaptureConsentController` (per-agent WhatsApp body-ingestion consent)
- `communications.triage.*` (3, 2569-2573) — `CommunicationTriageController@index/addContact/notRealEstate`
- `settings.email-setup.*` (7, 4188-4210) — `Settings\EmailSetupController` — per-user IMAP mailbox CRUD, soft-deletable, credential reveal separately gated (`permission:reveal_mailbox_credential`, `:4211`)
- `corex.comms-suspense.*` (6, 4164-4171) — `CommsSuspenseController@index/dealSearch/verify/reassign/dismiss` — attorney-email-to-deal filing queue
- `comms-access.*` (5, 449-454) — `CommsAccessRequestController`
- Machine ingest (no session auth): `communications.wa.ingest/contact-check/ping/backfill-targets` (5661-5680, bearer token) and `communications.wa.webhook` (5686-5688, HMAC)

**Reads from other pillars**
- Contact — `WaIngestController@contactCheck` resolves numbers via `ContactIdentifierResolver` (`app/Http/Controllers/Communications/WaIngestController.php:137`); `CorrespondenceFilingService` queries Contacts to match inbound senders (`app/Services/Communications/CorrespondenceFilingService.php:78`).
- Deal — `CorrespondenceFilingService` resolves `Deal` via `deal_v2_id`/twin id to suggest a filing target (`:427,434`).

**Writes to other pillars**
- Contact — `CommunicationTriageController@addContact` creates a new Contact from an unmatched sender (`:126`); `WaArchiveIngestor` calls `$contact->touchLastContacted(...)` after filing (`app/Services/Communications/WaArchiveIngestor.php:307`).
- Deal — `CommunicationFilingSuspense belongsTo Deal` via `suggested_deal_id`/`resolved_deal_id` (`app/Models/Communications/CommunicationFilingSuspense.php:49,54`); `CommsSuspenseController@verify/reassign` resolves a suspended message onto a Deal (`:80,98`).
- Polymorphic link — `CommunicationLink::create(['linkable_type'=>Contact::class, ...])` (`WaArchiveIngestor.php:298-306`) — the mechanism that files a Communication against a Contact.

**How data enters**
- Feed/scan (primary): WhatsApp browser extension → `communications.wa.ingest` bearer-token endpoint → `WaArchiveIngestor`; WAHA server-session webhook → `WaSessionWebhookController@handle`.
- Feed/scan (email): IMAP mailboxes configured via `settings.email-setup.*`, credentials on `CommunicationMailbox`; polling job **CORRECTED (Pass 3):** `routes/console.php:187` — `Schedule::command('communications:poll-mailboxes')->everyFiveMinutes()->withoutOverlapping()`, backed by `app/Console/Commands/Communications/PollMailboxes.php`. The original "not found" was a scoping miss, not a real gap — the original pass grepped `routes/web.php` only and never checked `routes/console.php`.
- Manual: staff resolve unmatched messages via Triage (`addContact/notRealEstate`) and Comms Suspense (`verify/reassign/dismiss`).
- Derived: `CorrespondenceFilingService` auto-suggests a Deal/Contact match before a human resolves it (`:72-78,427-434`).

---

### Settings

**Screens & routes:** confirmed **not** one screen — scattered across ~20 distinct route-name namespaces / 19 distinct URI prefixes (via `grep -oE "name\('[a-zA-Z0-9._-]*settings[a-zA-Z0-9._-]*'\)" routes/web.php`, 140 individual routes), each owned by whichever module it configures, plus one central admin/company-settings area:
- `admin.company-settings` (5 routes, 3632-3655) — `Admin\CompanySettingsController`
- `admin.settings.*` (11 routes, 1362-1379) — `document-types`, `deal-distribution-rules`, `deal-property-sync`, `document-distribution` — each a different controller (`SplitterDocTypeController`, `DealDistributionRuleController`, `DealPropertySyncSettingsController`, `DocumentDistributionMatrixController`)
- `admin.branch-settings`, `admin.dev-settings` (+3 sub-prefixes), `admin.performance-settings`, `admin.proforma-settings`
- `command-center.settings`, `command-center.user-settings`
- `compliance.agency-settings`
- `corex.settings.*` — largest single group (e.g. `corex.settings.rental-applications.*`, `settings/contact-types`, `settings/contact-sources`, `settings/contact-identifier-labels`, `settings/contact-tags`, `settings/property-items`, `settings/prospecting`, `settings/outreach-templates`)
- `deals-v2.settings.service-types`
- `docuperfect.esign.settings` (compiler-side), `docuperfect.settings.*` (12 routes, document types + named fields, 4703-4716)
- bare `settings.*` (rental-division sub-page, 5057-5075, `Rental\RentalPropertyController`/`RentalDocumentTypeController`/`RentalReminderSettingsController`, nested to resolve as `rental.settings.properties.index` etc.)
- `settings.email-setup` (Communication Mailboxes)

**CORRECTION (2026-09-25):** the original pass listed the namespaces above but stated "not evaluated per-namespace in this pass (20+ distinct controllers)." Every distinct `*SettingsController` class referenced from `routes/web.php` — 19 in total (`grep -oE "[A-Za-z0-9_\\\\]*SettingsController" routes/web.php | sort -u`, aliases resolved via the `use ... as` statements at routes/web.php:1764-1767) — is now walked below: route name(s), permission gate, config model(s) it writes, and which pillar the setting governs. `CoreX\RentalApplicationSettingsController` was already named (not walked) in the Rental Applications section above; the other 18 were untouched by the original pass, matching cc3's count exactly.

| Controller | Route name(s) / prefix | Permission gate | Config model(s) written | Governs (pillar) |
|---|---|---|---|---|
| `Admin\CompanySettingsController` | `admin.company-settings`, `.update`, `.website.update`, `.push-sold`, `.testimonials.toggle` (routes/web.php:4035-4059) | `permission:manage_performance_settings` (all but the testimonial toggle, which is `permission:testimonials.publish`, routes/web.php:4057) | reads `PerformanceSetting`; writes `Agency` fields directly | Agency-wide branding/website config; bridges to **Property** (push-sold) and **Contact** (testimonial publish) — see Writes bullet below |
| `Admin\DealPropertySyncSettingsController` | `admin.settings.deal-property-sync.index`/`.update` (routes/web.php — 2 routes) | `permission:access_settings` | `AgencyDealSyncSettings` | **Deal → Property** — configures how a Deal write propagates onto its linked Property (already flagged as a cross-pillar bridge in the original pass) |
| `Admin\DevSettingsController` | `admin.dev-settings.*` (`index`/`update`/`demo-sidebar`/`demo-sidebar.update`), prefix `admin/dev-settings`, `owner_only` middleware (routes/web.php:3776-3783) | `owner_only` | `DevSetting` (get/set) | Internal dev/demo tooling — not pillar-facing; owner-only, distinct from the demo-access/webinars/demo-connection sub-prefixes nested under the same `admin/dev-settings/*` root |
| `Admin\PerformanceSettingsController` | `admin.performance-settings.edit`/`.update` (routes/web.php:1624-1629) | `permission:manage_performance_settings` (group middleware, routes/web.php:1623) | `PerformanceSetting` (get/set) | Agency-wide performance thresholds/toggles feeding **Agent** performance reporting and dashboard behaviour |
| `Admin\ProformaSettingsController` | `admin.proforma-settings`/`.update` (routes/web.php:61,71 relative) | not re-verified this pass | `AgencyProformaSettings` via `ProformaAdminService` | **Deal** — proforma/settlement document configuration (see DR2's `deals-dr2.settle.*` routes) |
| `CommandCenter\SettingsController` | `command-center.settings`, `.toggle-rule`, `.store-expectation`, `.destroy-expectation`, `.event-classes`, `.event-classes.update` (routes/web.php:2072,2076-2080) | mixed — some sub-routes `permission:command_center.settings` (contact-governance ones), main list not explicitly gated in this grep | `CalendarEventClassSetting`, `DocumentExpectation`, reads `Role`, `AutomationRule` | Command Center automation rules and calendar-event class behaviour — cross-pillar (Deal/Contact communications, calendar) |
| `CommandCenter\UserSettingsController` | `command-center.user-settings`/`.update` (routes/web.php:2083-2084) | inherits Command Center group middleware — not individually re-verified | `UserDashboardSetting` (get/update) | **Agent/User** — per-user dashboard preferences |
| `Commission\CommissionSettingsController` | `corex.settings.commission`/`.update` (routes/web.php:3746-3749) | `permission:access_settings` | `CommissionSetting`; audit trail `CommissionSettingAuditEntry::create` | **Agent** — commission rates/splits that `CommissionCalculationService` reads when generating `CommissionLedger` entries (see new Agent/User section above) |
| `Compliance\AgencyComplianceSettingsController` | `compliance.agency-settings.index`/`.store`/`.edit`/`.update`/`.destroy`, prefix `compliance/agency-settings` (routes/web.php:2634-2639) | `permission:manage_agency_compliance` + `agency.required` | `AgencyComplianceProvision`; reads `AgencyDocumentTypeConfig` | Compliance/FICA provisions — bridges **Contact** (FICA subjects) and **Agent** (compliance officers) |
| `CoreX\FeatureSettingsController` | `corex.settings.features.update` (routes/web.php — `/settings/features`) | `permission:agency_features.manage` | `AgencyFeature` via `AgencyFeatureService` | Agency-wide feature-flag gate — cross-pillar, controls which of the other pillars' features are switched on at all |
| `CoreX\LeaseSettingsController` | `corex.settings.leases.edit`/`.update` (routes/web.php:2842-2845) | `permission:leases.manage_settings` | `LeaseSetting` | **Leases** — per `.ai/specs/leases.md` §5.2, the expiry-notice window (agency-configurable, default 60 days) |
| `CoreX\RentalApplicationSettingsController` | `corex.settings.rental-applications.edit`/`.update` + 20 sub-action routes (routes/web.php:2837-2928 region) | `permission:rental_applications.manage_settings` + `agency.required` | `RentalApplicationQualifyingSetting`, `RentalApplicationApprovalEmailSetting`, `RentalApplicationDeclineEmailSetting`, `RentalApplicationChecklistConfig`, `RentalApplicationCustomField`, `RentalApplicationDocumentRequirement`, `RentalApplicationDocumentValidityWindow`, `RentalApplicationHighlighter` | **Contact/Property (rental)** — the largest single settings controller by method count (26 public methods) — qualifying formula, RO/CO authoriser assignment, approval mode, checklist-before-approval gate (AT-430), document requirements, field display config |
| `CoreX\RentalDetailsSettingsController` | `corex.settings.rental-details.edit` (routes/web.php:2882) | not re-verified this pass | `PropertyRentalDetailsCustomField` | **Property** — custom fields on a property's rental-details block |
| `CoreX\RentalInspectionSettingsController` | `corex.settings.rental-inspections.edit`/`.update`/`.features`/`.room-type-defaults`/`.room-type-order`/`.condition-states` (routes/web.php:2848-2864) | `permission:rental_inspections.manage_settings` | `RentalInspectionSetting` | **Rental Inspections** (see corrected section above) — which inspection features are active, per-room-type default items, room walking order, condition-state vocabulary |
| `CoreX\RentalInventorySettingsController` | `corex.settings.rental-inventory.edit`/`.update` (routes/web.php:2869-2871) | not re-verified this pass | `RentalInventorySetting` | **Rental Inventories** (confirmed to exist, not walked to Phase 1 depth — see Confidence and gaps) |
| `CoreX\RentalWorkOrderSettingsController` | `corex.settings.rental-work-orders.edit`/`.update` (routes/web.php:2875-2877) | not re-verified this pass | `RentalWorkOrderSetting` | **Rental Work Orders** (confirmed to exist, not walked — see Confidence and gaps; distinct from the DealV2-era Work Orders section elsewhere in this document) |
| `CoreX\SettingsController` | `corex.settings` (main index) + ~30 sub-action routes (routes/web.php — `/settings`, 32 public methods) | `permission:access_settings` + `agency.required` | `PropertySettingItem`, `AgencyDashboardSetting`, `PerformanceSetting`, plus reads/writes across `ContactType`/`ContactSource`/`ContactTag`/`ContactIdentifierLabel`/`CommissionSetting`/`AutomationRule`/`DocumentExpectation`/`AgencyLeaveVisibilityMatrix`/`Designation`/`AgentSocialAccount` | The central Settings hub — by far the broadest single controller, touching **Property** (property setting items, sort defaults), **Contact** (types/sources/tags/identifier labels), **Agent** (dashboard mode, split branches, social accounts), and agency-wide toggles (presentations, matches visibility, marketing, syndication portals, remote access, whistleblow settings) in one place |
| `Docuperfect\EsignFinalizationSettingsController` | `docuperfect.esign.settings.finalization`/`.update` (routes/web.php:5139-5140) | inherits the `docuperfect.esign.*` group's compiler permissions — not individually re-verified | `EsignSettings` | **E-sign/DocuPerfect** — finalization-step configuration |
| `Rental\RentalReminderSettingsController` | `rental.settings.reminders.index`/`.update` (routes/web.php:5521-5522) | not re-verified this pass | `RentalReminderSetting` | The **shadow "Rentals"** module documented as unreconciled with Rental Applications elsewhere in this file (see Leases §D4) — reminder scheduling for that separate `rental_properties`-backed dashboard |

**Reads from other pillars:** per-controller pattern confirmed above — the overwhelming majority read/write only their own module's config rows. The bridging exceptions are `Admin\CompanySettingsController` and `Admin\DealPropertySyncSettingsController` (both already flagged in the original pass) plus, now confirmed, `CoreX\SettingsController` itself, which is not single-pillar at all — it is the one settings controller that reaches into Property, Contact, and Agent config in a single file.

**Writes to other pillars**
- `admin.company-settings.push-sold` → `CompanySettingsController@pushSoldToWebsite` (routes/web.php:3648-3650) — pushes every SOLD Property listing to the agency website.
- `admin.company-settings.testimonials.toggle` → `@toggleTestimonial` (routes/web.php:3652-3655) — publishes a captured testimonial (Contact-sourced content) to the public website.
- `Commission\CommissionSettingsController` → indirectly, via `CommissionSetting` rows read by `CommissionCalculationService` at deal-commission-finalisation time (see Agent/User section above) — not a direct write from the settings controller itself, but the configured rate is what the Deal→Agent commission write path applies.

**How data enters:** manual only, in every sub-namespace checked — each settings screen is a CRUD form over its own config table. No import/feed/derived path found — this pillar is agency-admin-configured, not data-ingested.

**Onboarding Wizard coverage — NEW (Pass 3, 2026-09-26).** CLAUDE.md non-negotiable #10a requires every agency setting to be surfaced in the Agency Onboarding Setup Wizard (`config/agency-onboarding-copy.php`) — wired into that step's `savers`, not just present on its own settings page — unless deliberately excluded and recorded in the spec's "Deliberately NOT in the wizard" list (`.ai/specs/agency-onboarding-setup.md` §5.1). Audited all 19 `*SettingsController` classes from the table above against the wizard's actual saver wiring.

**Count: 10 of 19 reach the wizard (7 fully, 3 partially with the remainder deliberately linked out to their own settings page), 9 of 19 do not reach it at all — and none of those 9 is recorded in §5.1's exclusion list. Every one of the 9 is silence, not a decision.**

| Controller | Verdict | Evidence |
|---|---|---|
| `Admin\CompanySettingsController` | Reaches | `agency-onboarding-copy.php:213` — `branding` step saver |
| `Admin\DealPropertySyncSettingsController` | Reaches | `:524` — `properties` step saver |
| `Admin\DevSettingsController` | **Missing, undocumented** | No saver anywhere; owner-only dev tooling, not on §5.1's list |
| `Admin\PerformanceSettingsController` | **Missing, undocumented** | Writes `vat_rate`/`listings_per_sale`/`company_*` (`PerformanceSettingsController.php:66-95`) — untouched by any saver. The wizard's `identity` step writes adjacent-sounding `vat_no`/`ffc_no`/`address` onto **Agency** instead (`:83-116`) — a different store |
| `Admin\ProformaSettingsController` | Reaches | `:297` — `proforma` step |
| `CommandCenter\SettingsController` | **Missing, undocumented** | No saver references this controller |
| `CommandCenter\UserSettingsController` | Missing, defensible | Per-user, not agency-level — reasonable to exclude, but still not on §5.1's list |
| `Commission\CommissionSettingsController` | Reaches | `:277` — `commission` step |
| `Compliance\AgencyComplianceSettingsController` | **Missing, undocumented** | The wizard's `compliance` step (`:702-709`) writes only `saveWhistleblowSettings`/`saveReferralSettings` — never touches `AgencyComplianceProvision`, this controller's actual model. The step name implies coverage it doesn't have |
| `CoreX\FeatureSettingsController` | Reaches | `:154` — `capabilities` step |
| `CoreX\LeaseSettingsController` | Reaches | `:364` — `leases` step |
| `CoreX\RentalApplicationSettingsController` | Partial | 9 of 26 methods wired (`:375-389`); qualifying formula/approval-decline templates/document requirements/highlighters deliberately linked out (`:479-497`), per §5.1 |
| `CoreX\RentalDetailsSettingsController` | **Missing, undocumented** | No saver, no link-out either — unlike its siblings below |
| `CoreX\RentalInspectionSettingsController` | Partial | `:365` wires the 3 scalar window/expiry fields; room-type defaults/order/condition-states deliberately linked out (`:487-489`) |
| `CoreX\RentalInventorySettingsController` | **Missing, undocumented** | No saver, no link-out — this module shipped 2026-09-22 (see Rental Inventories section); the wizard hasn't caught up |
| `CoreX\RentalWorkOrderSettingsController` | Partial, stale comment | `:371` wires only `no_approval_spend_threshold`; the deferral comment says Rental Work Orders "aren't built (Stage 4)" — but the pass-2 correction elsewhere in this document confirms they are, live, with their own controller/sidebar/supplier-quote workflow. The wizard's comment was never updated when that shipped |
| `CoreX\SettingsController` | Reaches | Wired across most steps (`identity`, `capabilities`, `properties`, `presentations`, `matches`, `contacts`, `notifications`, `access`) |
| `Docuperfect\EsignFinalizationSettingsController` | **Missing, undocumented** | No saver anywhere |
| `Rental\RentalReminderSettingsController` | **Missing, undocumented** | No saver — consistent with belonging to the shadow `RentalProperty`/Rentals module already flagged elsewhere as unreconciled and disconnected |

**One more thing found along the way, load-bearing:** the spec's own §5.1 exclusion list is itself stale — it lists "Rental application Property Link Lock" and "Tag Contact as Tenant on Approval" as deliberately excluded, but the wizard code (`:378-379`) now wires both (`updatePropertyLock`, `updateTenantTagging`). The exclusion list was never updated when those were added.

**What a human is doing twice today (Johan's framing):** onboarding the Cape Town rentals agency in October means whoever runs the wizard finishes it and is told the agency is set up — then still has to separately find and configure, at minimum, Rental Inventory settings, Compliance provisions, e-sign finalization, and VAT rate/listings-per-sale, none of which the wizard ever mentioned exist. The wizard was supposed to be the one walkthrough that replaces hunting through 19 separate Settings screens; for 9 of them it silently isn't, and nothing in the codebase records whether that was a choice or an oversight.

---

## PHASE 2 — The Two Working Surfaces

### Contacts (`resources/views/corex/contacts/show.blade.php`, 1,898 lines)

Tabs (`show.blade.php:89-99`): Info, Properties & Core Matches, Viewings & Feedback, Notes & Testimonials, Drive, Rental Applications, FICA Compliance, Consent, Communications, Outreach, History. Controller: `CoreX\ContactController` (19 public methods).

**Actions confirmed genuinely embedded (no navigation, no re-entry) — positive baseline:**
- Consent record/revoke — `_consent-tab-body.blade.php` posts directly to `corex.contacts.consent.*`.
- FICA status/history view (download-only; no new-submission action exists here or anywhere on this screen).
- Communications archive thread list, with an *additive* deep-link to the org-wide archive (not a required navigate-away).
- Outreach/WhatsApp quick-send (Info tab, Alpine-toggled inline forms) and full Outreach tab.
- Core Match *creation* (`_match-form.blade.php` posts to `corex.contacts.matches.store`).
- Viewing Pack *creation* (Viewings tab posts to `corex.viewing-packs.store` with `contact_id` as a hidden field — no search).
- "Schedule Event" header button — opens Calendar in a new tab with `prefill_contact_id` genuinely pre-filled, no re-typing.
- "Create Listing" header dropdown — opens Property Wizard/Classic form in a new tab with `?contact_id=` pre-filled.
- Buyer Hub header link — one click to `command-center.buyers.show`, contact id in the URL, no re-entry.

**Actions that live elsewhere and require navigating away — with or without re-entry:**

| Action | Lives now (route + file:line) | Reachable from contact screen? |
|---|---|---|
| Core Match **edit** / **view results** | `corex.contacts.matches.edit`/`.results` → `ContactMatchController@edit`/`@results` (`app/Http/Controllers/CoreX/ContactMatchController.php:543,588`); even a brand-new match's `store()` (`:513`) redirects to the full-page `matches.results` screen (`:528`) | Partial — create is embedded; edit/results/print are separate full-page routes |
| **Convert Match → Deal** | `corex.contacts.matches.convertToDeal` → `ContactMatchController::convertToDeal` (`:671`), pre-fills `buyer_name/email/phone` from `Contact` (`:687-690`, fields exist on `app/Models/Contact.php:45`) and `property_id` from the match | NO — only reachable after leaving the contact for the match-results page; the resulting deal is then redirected again to `admin.deals.edit` (`:706`), a third screen |
| **Viewing Pack property selection** (the actual "pick properties" step) | Immediately after creation, agent is redirected to `corex.viewing-packs.show` (`ViewingPackController::show`, `:123`) to add properties one at a time via `addProperty`/`searchProperties` (`:179,380`) | NO — `searchProperties()` (`:380-401`) is a fresh `Property::searchAddress($q)` free-text search; it does **not** read the same buyer's already-computed Core Matches at all |
| **Rental Application — starting a new one** | `corex.rental-applications.create` → `RentalApplicationController::create` (`app/Http/Controllers/CoreX/RentalApplicationController.php:489`) — no `contact_id`/prefill param, only `old('contact_id')` bounce-back | NO. The contact screen's Rental Applications tab (`_rental-applications-tab-body.blade.php`) is explicitly history-only — no "start new application" control in its 168 lines |
| **E-sign / DocuPerfect — start a signing flow** | `ESignWizardController::create` (`app/Http/Controllers/Docuperfect/ESignWizardController.php:59`) — template-first, accepts no `contact_id`; recipient chosen later via `@searchContacts` (`:1445`) | NO — zero links from `show.blade.php` into any `docuperfect.esign.*`/`SigningController` route |
| **DR2/Deals — create a deal for this contact** | No route exists. `_linked-deals.blade.php:1-5` states explicitly: "Read-only: deal-party linking is managed from the deal edit screen, not here." Every link points to `admin.deals.edit` (`:24`) | NO — and the only deal-creation path touching a contact (Core-Match convertToDeal) isn't reachable from here either |
| **Presentations/CMA** | Not applicable — `PresentationController::create/store` retired, redirect to "open a property and click Generate Presentation" (`app/Http/Controllers/Presentation/PresentationController.php:139-169`); generator is keyed by `Property` only (`PresentationGeneratorController.php:30`) | N/A — no contact-linked presentation feature exists anywhere to make reachable |

**Confirmed re-entry of already-known contact data:**
1. Rental Applications — `resources/views/corex/rental-applications/create.blade.php:27-28`, a contact search box (name/phone/email) with no `contact_id` prefill support in `RentalApplicationController::create()`.
2. E-sign — `ESignWizardController::searchContacts` (`:1445`) is the only way to attach a recipient; `create()` has no contact-prefill path at all (not even a broken one — the pillar connection has never existed).

---

### Properties (`resources/views/corex/properties/show.blade.php`, 8,229 lines)

Tabs (`show.blade.php:1300-1311`): overview, info, gallery, rental, rental-images, contacts, notes, history, drive, intelligence, core-matches. Controllers: `PropertyController` (3,351 lines) + `PropertyContactController`/`PropertyFileController`/`PropertyNoteController`/`PropertyWizardController`/`PropertySgController`.

**Actions confirmed genuinely embedded — positive baseline:**
- Portal Syndication (PP/P24/Website) — `partials/syndication-panel.blade.php:1-20` states explicitly: "There is exactly ONE syndication surface in CoreX," shared between `show.blade.php` (inline) and the Properties index (fetched via `GET /api/v1/properties/{property}/syndication-panel` into the same modal). Not a redirect.
- Rental Inspection Images — `PropertyController::uploadRentalImages/saveRentalImagesMeta/...` (`:2213-2461`) rendered inline as in/out/custom sections (`show.blade.php:4444-4506`).
- CMA/Presentation Generation — "Generate Presentation" button (`show.blade.php:459-470`) fires `corex.properties.generate-presentation` with `$property` already bound, no re-search.
- Contact search/link/create — `corex.properties.contacts.search/.link/.search-global` fully embedded as an AJAX modal in the Contacts tab (`show.blade.php:4835-4837,6682`).
- Compliance checklist + readiness gate, Drive document upload/tag, notes CRUD, image gallery CRUD — all inline.
- Core Matches — displayed **read-only** (matching contacts/saved searches for this property), with a "View match results →" link out (`show.blade.php:6129-6274,6261`).

**Actions that live elsewhere and require navigating away — with or without re-entry:**

| Action | Lives now (route + file:line) | Reachable from property screen? |
|---|---|---|
| **Create Deal** (DR2) | `deals-dr2.create` → `Dr2\DealRegisterController::create()` (`routes/web.php:823`) — takes **zero** `Request` parameter, so `?property_id=` cannot even be received; the hidden `property_id` field (`resources/views/dr2/create.blade.php:181`) only populates via `old('property_id')` on a validation bounce or an edit | NO — zero `deals-dr2` reference anywhere in `show.blade.php` |
| **Core Match → Convert to Deal** | `ContactMatchController::convertToDeal` (`:671`) — resolves `Property::whereKey($property)`, fills buyer fields from the matched Contact, `deal_type` from the match's `listing_type` | NO — the property screen's own Core Matches tab is read-only; no "Convert to Deal" button, even though the underlying action already knows how to do this property-first |
| **Add to Viewing Pack** | `corex.viewing-packs.properties.add` → `ViewingPackController::addProperty()` (`routes/web.php:2113`) — requires an existing `ViewingPack` first, created only from Command Center/calendar | NO — zero `viewing-pack` reference anywhere in `show.blade.php` |
| **Start Rental Application** | `RentalApplicationController::create()` (`:489`) — no `Request` param, cannot receive `?property_id=`; only `old('property_id')` on bounce | NO — zero `rental-application` reference in `show.blade.php` |
| **Deeds Capture** | `CoreX\DeedsCaptureController` (`routes/web.php:5366-5393`) — operates on `TrackedProperty` match/promote/dismiss/reject, not a "enter deeds data for this listing" form keyed to a Property | NO — zero `deeds-capture` reference. (Queue/match-review shape, so "re-entry" doesn't cleanly apply here — flagging absence of a link, not a re-entry pattern) |
| **E-sign / mandate signing** | `DocumentController::create($templateId)` (`:68-76`) and `ESignWizardController::create()` (`:59-99`) — neither accepts any property reference at all | NO. The only property-screen section resembling this is "Mandate & Assignment" (`show.blade.php:3139-3191`), which is just a `mandate_type` metadata dropdown — it does not launch signing |

**Confirmed re-entry of already-known property data:**
1. Deal creation — `resources/views/dr2/create.blade.php:270` (`#dr2mp_picker`) + `DealRegisterController::searchProperties()` (`DealRegisterController.php:1039-1069`, `->searchAddress($search)`) — agent re-types/re-searches the same address CoreX already has open.
2. Viewing Pack — `ViewingPackController::searchProperties()` (`:380-401`) — fresh address/token search, not a pre-selected `property_id`.
3. Rental Application — `RentalApplicationController::searchProperties()` (`:497-519`), built as its own dedicated picker specifically because "borrowing another feature's search route" was rejected, per in-code comment — an intentional, acknowledged duplication of the address-search UI rather than an accidental one.
4. E-sign — structural, not textual re-entry: `create()` methods accept no property reference whatsoever, so the property/seller must be located and typed later inside the wizard with no linkage back to the Property record already on screen.

---

## PHASE 3 — Duplication and Automation

### Duplication of work

**D1. Address/property search — CORRECTED (2026-09-26). The headline claim does not survive.** The original entry asserted property search was "reimplemented independently at least five times... none shared" — the same shape of claim D2 made about contact search, which turned out to be materially wrong once checked. Re-verified against real code on `origin/QA1` (current tip `b48a025f`, 2026-09-25) at the time of this correction: **every one of the five named implementations calls the same shared scope**, `Property::scopeSearchAddress()` (`app/Models/Property.php:1470`), whose own inline comment states plainly: *"This is the ONE canonical property search — every picker calls it (fix-the-class)"* (`Property.php:1477`). A repo-wide grep for callers of `->searchAddress(` found **16 call sites across at least 14 distinct controllers/services** — the consolidation is broader than D1's five examples, not narrower.
- `CoreX\PropertyContactController@search/searchGlobal` — **wrong controller named.** These two methods (`PropertyContactController.php:21,49`) search **Contacts**, not properties (already corrected under D2, both call `Contact::search()`). The actual property-search-for-linking-to-a-contact method lives on a different, similarly-named controller: `CoreX\ContactPropertyController::search()` (`app/Http/Controllers/CoreX/ContactPropertyController.php:16`) — body: `Property::whereNotIn(...)->searchAddress($q)` (`:26`). Uses the shared scope.
- `Dr2\DealRegisterController::searchProperties()` (`app/Http/Controllers/Dr2/DealRegisterController.php:1039`) — `Property::query()->visibleTo(...)->searchAddress($search)` (`:1063`). Uses the shared scope.
- `CommandCenter\ViewingPackController::searchProperties()` (`app/Http/Controllers/CommandCenter/ViewingPackController.php:380`) — `Property::query()->searchAddress($q)` (`:392`), with its own in-code comment: *"Canonical property search + label (fix-the-class)"* (`:390-391`). Uses the shared scope.
- `CoreX\RentalApplicationController::searchProperties()` (`app/Http/Controllers/CoreX/RentalApplicationController.php:570`) — **the original claim was true once, and the code says so.** A docblock directly above the method (`RentalApplicationController.php:536-566`, Johan, QA1 walk, 2026-09-21) records that this endpoint used to build its own weaker OR-across-two-fields search and its own address-less label — exactly the D1 pattern — and was deliberately fixed: *"Fixed by reusing, not re-implementing: Property::scopeSearchAddress() ... This endpoint was the one rental-screen holdout still building its own weaker version — not a second implementation living alongside those, the SAME one."* The fix landed in commit `3f2c7a766` (2026-09-21), which is an ancestor of `0253e1c03` — the very commit the original atlas investigation used as its base. **D1 was already wrong on the day it was written**, not something that changed afterward. What the "deliberate decision" in the original bullet actually refers to (and is still true) is narrower than the original text implied: the endpoint stays separate under its own permission (`rental_applications.manage`-family gates) rather than being merged into another feature's *route* — that is a route/permission decision, not a query-logic one.
- `Docuperfect\ESignWizardController::searchProperties()` (`app/Http/Controllers/Docuperfect/ESignWizardController.php:1128`) — `Property::searchAddress($q)` (`:1138`), with its own in-code comment: *"Search main properties table (canonical search, newest-first)"* (`:1136`). Uses the shared scope.

**What's actually true, replacing the struck claim:** the query logic is written once and shared broadly — not "at least five times," effectively once, consolidated on purpose (`RentalApplicationController`'s docblock documents an active, named effort to close out the last holdout). The real, surviving finding is the same shape D2 landed on: duplication at the **endpoint/UI layer** — at least 16 separate routes/controller methods, each its own autocomplete widget, none reusable from one screen into another, and (per the original bullet's still-valid second half) generally not pre-filled when the agent is already on the record in question. That UI-layer/pre-fill problem is real and independent of whether the query underneath is shared — Property/Contact both show it. **Checked but not expanded into this finding:** `PropertyController.php:303` and `DealV2Controller.php:533` also call the shared scope (two more consolidated call sites beyond D1's original five, found in the same grep); a heuristic sweep for property free-text search *not* using the shared scope surfaced three controllers (`PublicAgencyPropertiesController`, `MarketIntelligenceController`, `TrackedPropertyController`) — none are internal-linking pickers in the sense D1 means (public website search and Tracked-Property-tier prospecting search respectively, a different tier per non-negotiable #10), so they were not added to this finding, but are named here rather than silently excluded.

**D2. Contact search — CORRECTED (2026-09-25).** The original entry asserted five reimplementations "in the same pattern" as D1, but its fifth item didn't even name a method, and none carried a `file:line`. Re-verified against real code: four genuine, separately-routed search endpoints exist, but — unlike D1's property search — three of the four call the **same shared query logic**, not independently reimplemented logic; the fifth original entry does not do a contact search at all and has been struck.
- `CoreX\ContactController@index` (`app/Http/Controllers/CoreX/ContactController.php:24`) — the main Contacts list search, gated on `$request->filled('search')` (`ContactController.php:86` region); own bespoke widened-search logic for the AT-394 cross-agent-match case (`:88-95`), on top of the shared scope below.
- `CoreX\PropertyContactController@searchGlobal` (`app/Http/Controllers/CoreX/PropertyContactController.php:21`) and `@search` (`:49`) — both call `Contact::withoutGlobalScope(ContactScope::class)->search($q)` (`:31,63`).
- `Dr2\DealRegisterController::contactSearch()` (`app/Http/Controllers/Dr2/DealRegisterController.php:1291`) — same pattern: `Contact::withoutGlobalScope(ContactScope::class)->search($q)` (`:1304`).
- `Docuperfect\ESignWizardController::searchContacts()` (`app/Http/Controllers/Docuperfect/ESignWizardController.php:1445`) — `Contact::query()->search($q)` (`:1453`), then narrows by e-sign role.
- **Struck:** the original fifth entry, "`CommandCenter\ViewingPackController` (contact resolution for pack ownership)," does not exist as a search. Every `Contact::` reference in that controller is a `findOrFail`/`find` by an already-known `contact_id` (`ViewingPackController.php:422,573,647`, and three more `linkable_type=>Contact::class` polymorphic-link references) — never a free-text query. There is no autocomplete/search endpoint for contacts anywhere in this file.
- **What's actually duplicated, precisely:** the three endpoints besides `ContactController@index` all delegate to one canonical scope, `Contact::scopeSearch()` (`app/Models/Contact.php:583`) and `Contact::toSearchResult()` (`:651`) — the query logic (all identifiers via child tables, relevance ordering, newest-first) is written **once**. The duplication D2 correctly identifies is at the **endpoint/UI layer**: four separate routes, four separate autocomplete widgets, none reusable from one screen into another (matching D2's original framing of the *consequence* — no pre-fill, no shared picker component — even though the underlying query is shared, not reimplemented). D1's stronger claim for property search ("each its own controller method... none shared") was not re-checked against this same possibility in this pass — see Confidence and gaps.

**D3. Two full deal-tracking engines coexist on different tables** — `App\Models\Deal` (table `deals`, actively developed as DR2) and `App\Models\DealV2\DealV2` (table `deals_v2`, soft-retired under AT-219, "holds 0 rows on dev and live" per `.ai/specs/deal-register-v2-spec.md:15-16`). The retired engine's controllers are still load-bearing for the live one: `Dr2\*` controllers call into `DealV2\WorkOrderController`, `DealV2\SupplierDirectoryController`, and `DealV2\Dr2MasterTemplateController` for pipeline templates, the supplier directory, secure-doc delivery, work orders, and CoC config — shared infrastructure living inside a module documented as sunsetting.

**D4. Leases split across two disconnected homes, one of them backed by a shadow property table:**
- `docuperfect.leases.index` is a dead placeholder stub with no sidebar link (`Docuperfect\SignatureController@leases`, routes/web.php:5023; confirmed by `app/Support/Tours/defs/docuperfect.php:221-226` explicitly skipping a tour here).
- A second, working "Rentals" drill-down (`rental.active-leases`/`.expired-leases`/`.dashboard`/`.signatures`, `Rental\RentalDivisionController`) reads an entirely **separate model and table**, `App\Models\Rental\RentalProperty` / `rental_properties` — **not** the pillar `Property` model at all. `LeaseRecord.property_id` is an unconstrained `unsignedBigInteger` with no FK (`database/migrations/2026_02_26_600007_create_lease_records_table.php:15,31-34`); `property_address`/`tenant_name`/`landlord_name` are plain denormalized strings, not linked to Property or Contact.
- This is explicitly flagged in-code, not merely discovered here: `resources/views/layouts/corex-sidebar.blade.php:1008-1018` states there are two unrelated "Rentals" nav groups and "Johan is deciding separately whether/how the two get reconciled. Do NOT fold them together on your own initiative."
- **Practical consequence:** any property change (address, status) in the real `properties` table has no automatic path into `rental_properties` — an agent using the Rental Division dashboard is working from a second, independently-maintained copy of property data for the same physical properties.

**D5. Core Matches → Deal conversion exists as working, pre-filled code but is gated behind an extra screen.** `ContactMatchController::convertToDeal` (`:671`) already knows how to build a `Deal` from a `Property` + `Contact` pair with buyer fields pre-filled (`:687-690`) — but it is reachable only from the Contact-first `match-results` full-page view, not from the Property screen's own (read-only) Core Matches tab, even though the property is the thing already open on screen.

**D6. Viewing Pack property selection ignores the buyer's own Core Matches.** `ViewingPackController::searchProperties()` (`:380-401`) is a fresh free-text address search — it does not read `contact->matches` or any prior wishlist/matched-property result, even though those matches were already computed and stored for that same buyer moments earlier via Core Matches.

**D7. E-sign/DocuPerfect has no pillar entry point from either working surface.** Neither `ESignWizardController::create()` nor `DocumentController::create()` accepts a `property_id` or `contact_id` at all — not a broken link, a structurally absent one. An agent starting a mandate signing or FICA pack from a contact or property record must re-search **both** the property and the recipient from scratch inside the wizard, even though both already exist selected on screen.

**D8. Deal↔Presentation linkage is one-directional and manual.** `Presentation.deal_id`/`resulted_in_deal_id` exist on the schema and `PresentationOutcomeController::update()` lets an agent manually record which deal a presentation resulted in (`:62`) — but no code path was found that derives or pre-fills a new deal from an accepted/confirmed presentation.

**D9. Settings are genuinely scattered, not duplicated** — ~20 distinct route-name namespaces / 19 distinct URI prefixes, each its own screen, no unified settings hub beyond `admin/company-settings`. Not a data-duplication finding, but the literal "40 screens" complexity Johan named: an agency admin configuring CoreX crosses many independent, differently-shaped settings screens with no common list/search/index across them.

**D10. Notifications — SUSPECTED AND CORRECTED IN THE SAME PASS (Pass 3, 2026-09-26).** A fresh-eyes report going into this pass suspected "no unified notification center exists — every service rolls its own one-off email delivery" as a clean new duplication finding, based on `RentalFaultReportService`/`RentalWorkOrderService` each calling their own `notify*` methods with no visible shared home. **Checked against real code, that premise was wrong — same shape as D1/D2/D3 above.** A real shared gateway exists: `NotificationDispatcher::fire()`/`send()` (`app/Services/CommandCenter/NotificationDispatcher.php`), built under a tracked initiative (AT-235) with its own audit trail (`.ai/audits/2026-07-13-at235-notifications-vs-event-classes.md`, `.ai/tickets/at235-gateway-consolidation-plan.md`) and a build-time guard test (`tests/Feature/Notifications/NotificationGatewayGuardTest.php`) that blocks new bypasses from being added. It handles per-event user preference, agency policy, open-hours windows, cooldown, channel selection, and an idempotency ledger (`notification_dispatch_log`) — it has its own production-incident history behind it (1.9M duplicate notifications on `contact.fica_missing`, 26 May–19 Jun 2026, fixed by making the dedup key mandatory). Both examples the atlas already cites are correctly wired through it: `app(NotificationDispatcher::class)->fire(...)`, confirmed at `RentalFaultReportService.php:66,91` and `RentalWorkOrderService.php:141` — the "not traced to their recipients" caveat elsewhere in this document is now closed for `notifyCreated`/`notifyOverdue`. The `Mail::to()` calls inside `RentalWorkOrderService::notifyOwner/notifyTenant/notifySupplier` are **not** bypasses of this gateway — they email property owners/tenants/suppliers (Contacts, external to CoreX), and the gateway's scope is explicitly CoreX-user preference governance, not transactional mail to external parties. That is a deliberate design boundary, not a gap.
- **What's actually true, and still worth having as a finding:** the gateway migration is real but incomplete, and the remaining debt is precisely counted and frozen by a build guard, not silently growing. `NotificationGatewayGuardTest::KNOWN_BYPASSES` (lines 59–109) lists exactly 17 files that still send notifications outside the gateway — down from 31 originally, shrinking module-by-module, capped by `assertLessThanOrEqual(17, ...)` so a new bypass can't be added without the build failing: Docuperfect/e-sign with no user-preference switch at all (`SignatureService.php`, `SalesDocumentController.php`, `SigningController.php`, `SendSignatureReminders.php`), Presentations, same gap (`RefreshRequestService.php`, `PresentationOutcomeService.php`, `PresentationDeliveryService.php`, `PublicPresentationController.php`, `PromptOutcomeCaptureJob.php`), Deals (`DealV2/NotificationService.php`), Contacts (`Listeners/Contacts/NotifyAgentOfClientTestimonial.php` — fires an event key not even in the catalogue, so it can't be configured or suppressed at all), reminders/scheduled (`CalendarReminderService.php`, `Console/Commands/CommandCenter/ProcessReminders.php`, `Console/Commands/CheckLeaseExpiry.php`), and misc (`Jobs/MatchPropertyJob.php`, `Jobs/RcrDeadlineReminderJob.php`, `Http/Controllers/Admin/ImporterController.php`). Two more named mail-to-user bypasses the guard deliberately can't catch (raw `Mail::to($user->email)`, not the Notification layer, so outside the test's scope): `Jobs/OversightDigestJob.php:198` and `Console/Commands/CommandCenter/SendCalendarDigests.php`.
- **What a human is doing twice today, for these 17+2 send paths specifically:** a user genuinely cannot turn the notification off, there's no open-hours suppression, no cooldown, and nothing is logged as having happened — each is its own one-off "email someone" implementation that has to separately reinvent consent-checking if it ever needs it, instead of getting it for free from the gateway everything else now shares. That work is real and still open. **But it is already found, already named, and already being worked down under AT-235 — not an undiscovered gap.** Recorded here so this document doesn't repeat the exact mistake its own D1/D2/D3 corrections exist to prevent: presenting an already-tracked, already-shrinking punch list as a fresh discovery.

### Automation candidates (steps that exist only because a human moves data CoreX already has between two screens)

**A1. Contact → Rental Application.** The only reason an agent must re-search for a contact already open on screen is that `RentalApplicationController::create()` takes no `Request` parameter and reads only `old('contact_id')`. No new search UI would need to be built — the picker (`quickCreateContact`/duplicate-check) already exists; it simply isn't reachable with a contact pre-selected.

**A2. Property → Deal creation (DR2).** Identical shape: `DealRegisterController::create()` takes no `Request` parameter, so `?property_id=` cannot be received even though the hidden field to hold it already exists in the create-form Blade (`dr2/create.blade.php:181`).

**A3. Property/Contact → Viewing Pack property picking.** The property-search step on the Viewing Pack screen is fully manual even when the buyer's Core Matches (already computed, stored, and displayed elsewhere) contain the exact candidate list. The two systems — Core Matches and Viewing Pack property search — do not currently talk to each other at all.

**A4. Core Matches → Deal conversion.** The automation is already built (`convertToDeal` pre-fills buyer name/email/phone/branch and property_id) — the only manual step is navigating to a different module to reach the button that already knows what to do.

**A5. Property/Contact → E-sign.** The most complete disconnection found: no automatic carry-over of either party's identity into a signing flow. Every mandate/FICA send starts from zero, regardless of which record the agent was just looking at.

**A6. Leases/RentalProperty shadow table.** Because `rental_properties` has no FK relationship to `properties`, any update to a real property (address change, status change) requires a second, independent manual update in the Rental Division's own property list for the Lease/Signature dashboard to reflect it. Two records for one physical property, maintained separately, with no computer moving data between them at all today.

**A7 (contrast — already automated, no work needed).** Portal Syndication, Rental Inspection Images, CMA/Presentation generation from a property, and Contact linking on the Property screen are all genuinely embedded, pre-filled, single-surface flows today — cited here as the working pattern the other findings above deviate from, not as findings themselves.

**A8 (Pass 3, 2026-09-26). Contact → FICA submission.** Same shape as A1/A5: `FicaController::create()` (`FicaController.php:151-158`) takes no parameters and reads no `contact_id`, so starting a FICA submission for a contact already open on the Contact screen means re-searching for that same contact from scratch through `.contacts.search`. The picker/duplicate-check machinery already exists (see the Compliance/FICA section) — it simply isn't reachable with a contact pre-selected. A "Start FICA" action on the Contact record's FICA tab, opening `compliance.fica.create` with `contact_id` pre-filled, is the same fix as A1/A5 — not a new mechanism.

**A9 (Pass 3, 2026-09-26). Manual property creation bypasses match-or-create entirely.** Not a "re-search" pattern like A1–A6, A8 — a structural gap in the system meant to prevent duplication in the first place. `PropertyController::store()` and `PropertyWizardController::createDraft()` never call `TrackedPropertyMatchOrCreateService::matchOrCreate()` (see Prospecting/MIC section) — every automated feed (P24, PP, deeds-capture, CMA, map activity) matches against Tracked Properties before writing, but an agent typing in a new listing by hand does not, beyond a one-time, easily-bypassed form warning that only fires if the create screen was opened from a Contact's address. The fix is not a new screen — it is wiring the same `matchOrCreate()` call already used by every other ingress path into these two manual-entry methods, so an agent can no longer recreate from scratch a property CoreX already holds intelligence on.
