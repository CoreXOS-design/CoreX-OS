# CoreX Atlas — What Touches Where

**Commissioned by:** Johan, 2026-09-24. Investigation only — no code changes, no migrations, no deploy.

**Johan's ruling that this investigation answers:** *"we built pillars. and essentially the pillars should link to each other. not 40 screens to get the work done."* And: the investigation must find duplication of work and automation opportunities, not just link targets.

**Method:** Built from mechanical sources — `routes/web.php` (2,113 named routes), `resources/views/layouts/corex-sidebar.blade.php` (3,118 lines), `config/corex-permissions.php` (1,153 lines), controller method names, model relationships, and the `.ai/specs/` files named in-code. Blade views were read in full only for the two Phase 2 working surfaces (Contacts, Properties) named in Johan's brief. Every claim below carries a `file:line` citation; where no evidence was found, that is stated explicitly rather than guessed.

**Scope note on Rental Inspections:** the "compare-viewer" feature referenced in the most recent QA1 commit (`0253e1c035`, "fix(rental-inspections): compare-viewer regressions") and its spec `.ai/specs/rental-inspections.md` **do not exist on this checkout (Staging/QA1-worktree base)**. That commit and spec live only on `origin/QA1` and unmerged feature branches — not yet promoted to Staging. Everything below describes what is actually present in this codebase, which for Rental Inspections is the older "Rental Images" feature only.

**Scope note on "Rental Inventory":** no routes, controllers, models, or views named "inventory" in a rental/property-condition sense were found anywhere in the codebase (only unrelated portal-stock "inventory" concepts — `AuditPortalInventory`, `PortalInventoryGuard`, `MonthsOfInventorySignal`, all about listing-count/market-supply, not physical asset inventory). This pillar, as named, does not currently exist as a built feature.

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

**Screens & routes:**
- `rental-images.upload`/`.save`/`.delete`/`.delete-bulk` — `PropertyController@uploadRentalImages/saveRentalImagesMeta/deleteRentalImage/deleteRentalImages` (routes/web.php:3907-3912) — condition-report photo galleries (in/out/custom sections), scoped per Property.
- `v1.mobile.properties.rental-images.*` — `Api\MobileRentalImagesController` (routes/api.php:553-556) — mobile equivalent, gated on `property.rental_inspections_available`.
- No route/controller/tab named "Rental Inspections" exists — the feature is titled "Rental Images" everywhere in code (route names, tab label, spec filename `rental-images.md`).
- The "compare-viewer" (two-panel In-vs-Out comparison) referenced in commit `0253e1c03` and spec `.ai/specs/rental-inspections.md` §20.18 **is not present on this checkout** — confirmed: spec file absent from `ls .ai/specs/`; `git branch -a --contains 0253e1c03` shows it only on `origin/QA1` and unmerged worktree branches; repo-wide grep for `compareViewer`/`roomGroups`/`_compareViewerContextFor`/`rir-compare-cell` returns zero hits in `resources/`.

**Reads from other pillars**
- Property — both controllers operate directly on `Property`; `uploadRentalImages(Request, Property $property)` reads `$property->rentalImagesStructure()` (`PropertyController.php:2213,2224`).

**Writes to other pillars**
- Property — images/metadata written straight onto `Property.rental_images_json` (`$property->update(['rental_images_json' => $structure])`, `PropertyController.php:2246`). No separate Inspection model/table exists — the property record IS the storage.

**How data enters:** manual upload only (web `PropertyController` or mobile `MobileRentalImagesController` → `PropertyImageStorer` service, `MobileRentalImagesController.php:41`). Property is a hard prerequisite — every route is `/{property}/rental-images/...`.

**Where it actually lives:** not standalone. Embedded as the "Rental Images" tab inside the Property show page (`show.blade.php:1305`, key `rental-images`), rendered by `partials/rental-section-body.blade.php`. No sidebar entry of its own.

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

### Work Orders

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

**Where it actually lives:** not standalone. Lives entirely inside the DealV2 pipeline-step UI — `app/Models/DealV2/DealStepWorkOrder.php` (per-instance) and `DealPipelineStepWorkOrder.php` (step-template config). Spec confirms status "BUILT on QA1." No independent nav entry; reached only from within a deal's pipeline step.

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
- `.ai/investigations/2026-08-01-dr2-completeness-audit.md:44`: `DealV2Controller` is AT-219 soft-retired — "routes redirect, bodies archived."
- **Net effect:** `App\Models\DealV2\*` (the abandoned `deals_v2` engine) is kept alive only for its shared config sub-systems (pipeline templates, supplier directory, secure-doc, work-orders, iCal, COC config) which the actively-developed `Dr2\*` controllers (operating on the legacy `deals` table via `App\Models\Deal`) still call into.

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
- Feed/scan (email): IMAP mailboxes configured via `settings.email-setup.*`, credentials on `CommunicationMailbox`; polling job not located in this pass (scheduled, out of route-grep scope) — "not found".
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

**Reads from other pillars:** not evaluated per-namespace in this pass (20+ distinct controllers). Pattern observed: settings controllers generally read/write their OWN module's config rows. Two confirmed exceptions bridging pillars: `admin.company-settings` and `deal-property-sync` (`Admin\DealPropertySyncSettingsController`, routes/web.php:3651 — configures how Deal writes to Property).

**Writes to other pillars**
- `admin.company-settings.push-sold` → `CompanySettingsController@pushSoldToWebsite` (routes/web.php:3648-3650) — pushes every SOLD Property listing to the agency website.
- `admin.company-settings.testimonials.toggle` → `@toggleTestimonial` (routes/web.php:3652-3655) — publishes a captured testimonial (Contact-sourced content) to the public website.

**How data enters:** manual only, in every sub-namespace observed — each settings screen is a CRUD form over its own config table. No import/feed/derived path found — this pillar is agency-admin-configured, not data-ingested.

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

**D1. Address/property search reimplemented independently at least five times**, each its own controller method and its own autocomplete UI, none shared, none pre-filled when the agent is already on the record in question:
- `CoreX\PropertyContactController@search/searchGlobal` (property-linking search)
- `Dr2\DealRegisterController::searchProperties()` (`DealRegisterController.php:1039-1069`)
- `CommandCenter\ViewingPackController::searchProperties()` (`ViewingPackController.php:380-401`)
- `CoreX\RentalApplicationController::searchProperties()` (`RentalApplicationController.php:497-519`) — in-code comment confirms this was a **deliberate** decision not to reuse another feature's search route
- `Docuperfect\ESignWizardController::searchProperties()` (`ESignWizardController.php:1128,1138`)

**D2. Contact search reimplemented independently in the same pattern:**
- `CoreX\ContactController@index` (global search)
- `CoreX\PropertyContactController@search/searchGlobal`
- `Dr2\DealRegisterController::contactSearch()`
- `Docuperfect\ESignWizardController::searchContacts()` (`:1445`)
- `CommandCenter\ViewingPackController` (contact resolution for pack ownership)

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

### Automation candidates (steps that exist only because a human moves data CoreX already has between two screens)

**A1. Contact → Rental Application.** The only reason an agent must re-search for a contact already open on screen is that `RentalApplicationController::create()` takes no `Request` parameter and reads only `old('contact_id')`. No new search UI would need to be built — the picker (`quickCreateContact`/duplicate-check) already exists; it simply isn't reachable with a contact pre-selected.

**A2. Property → Deal creation (DR2).** Identical shape: `DealRegisterController::create()` takes no `Request` parameter, so `?property_id=` cannot be received even though the hidden field to hold it already exists in the create-form Blade (`dr2/create.blade.php:181`).

**A3. Property/Contact → Viewing Pack property picking.** The property-search step on the Viewing Pack screen is fully manual even when the buyer's Core Matches (already computed, stored, and displayed elsewhere) contain the exact candidate list. The two systems — Core Matches and Viewing Pack property search — do not currently talk to each other at all.

**A4. Core Matches → Deal conversion.** The automation is already built (`convertToDeal` pre-fills buyer name/email/phone/branch and property_id) — the only manual step is navigating to a different module to reach the button that already knows what to do.

**A5. Property/Contact → E-sign.** The most complete disconnection found: no automatic carry-over of either party's identity into a signing flow. Every mandate/FICA send starts from zero, regardless of which record the agent was just looking at.

**A6. Leases/RentalProperty shadow table.** Because `rental_properties` has no FK relationship to `properties`, any update to a real property (address change, status change) requires a second, independent manual update in the Rental Division's own property list for the Lease/Signature dashboard to reflect it. Two records for one physical property, maintained separately, with no computer moving data between them at all today.

**A7 (contrast — already automated, no work needed).** Portal Syndication, Rental Inspection Images, CMA/Presentation generation from a property, and Contact linking on the Property screen are all genuinely embedded, pre-filled, single-surface flows today — cited here as the working pattern the other findings above deviate from, not as findings themselves.
