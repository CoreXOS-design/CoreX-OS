# Access control and agency isolation audit, release 989f6ffd0..0c7bfebed

Code: /mnt/HC_Volume_103099143/corex-worktrees/audit-release-2026-10-18 (read-only). Scope: routes/*.php and app/Http (212 files).

Method: the lead auditor read the routes diff in full and checked the "other" controllers (Admin, Tools, RoadToSold, alerts, address review, buyer notes, Docuperfect, etc.). Four sub-audits covered the client portal API, the public token routes, the leases/inspections/inventories controllers and the work orders/job cards controllers. Findings marked [lead-verified] were re-read by the lead auditor at the cited lines. The rest are sub-audit traces, each with its path written out.

Severity count: High 2, Medium 8, Low 12.

---

## HIGH

### H1. Unauthenticated upload on the inventory buyer-acceptance route gives stored XSS on the app's own domain — CONFIRMED [lead-verified]
- **Where:**
  - `app/Models/RentalInventoryBuyerAcceptance.php:123-129` (`storeWetInkUpload`).
  - Called from `app/Http/Controllers/RentalInventoryPublicController.php:113-114`.
  - Route: `POST /rental-inventory-report/{token}/buyer-acceptance` (routes/web.php:7412). This route is public.
- **Cause:**
  - The filename keeps the browser-supplied extension (`getClientOriginalExtension()`), and the file is stored with `storeAs(..., 'public')` under `/storage/...`.
  - The `mimes:pdf,jpg,jpeg,png,heic` rule only checks the sniffed content. A PNG-header polyglot named `x.html` or `x.svg` passes.
  - nginx serves `/storage/*` statically, with a Content-Type based on the extension.
- **Scenario:**
  - Anyone with the inventory report link (tenant, landlord, seller, or anyone the QR reaches) posts the file with `disposition=wet_ink` and a buyer id. The buyer id is printed on the public page.
  - The JSON response returns the public URL.
  - The agent's inventory page links it as "View uploaded scan" (`resources/views/corex/rental-inventories/show.blade.php:479`). When the agent clicks it, the script runs on corexos.co.za in the agent's session.
- **Making it worse:**
  - Files are written before `capture()` checks that the buyer is eligible (controller 110-115). Rejected attempts still leave files on the public disk.
  - `signature_image` has no size limit.
- **Fix:** store on the private disk with a server-chosen extension (`$file->store()`). Serve it through a token-checked route with `nosniff` and a sandbox CSP, as `RentalInspectionSignature::fileResponse()` does. Validate eligibility before writing, and cap `signature_image`.

### H2. Inventory "print for signature" PDF has no own/branch check, and the GET creates a public link — CONFIRMED [lead-verified]
- **Where:**
  - `app/Http/Controllers/CoreX/RentalInventoryController.php:211-222` (new).
  - Route: `GET /corex/rental-inventories/{rentalInventory}/print-for-signature`, which only requires `rental_inventories.view`.
- **Cause:** `RentalInventory` has no scoped route binding, so binding applies only the agency boundary. Every sibling action calls `assertVisible()`; this one does not.
- **Scenario:**
  - An own-scope agent changes the id to a colleague's inventory and downloads the full PDF: tenants, property, lines, photos and signatures.
  - If the inventory has no live link, this GET creates one (a write under a view permission) and embeds it as a QR code in the PDF.
  - That public link opens the public page, which is also the H1 upload surface.
- **Fix:** add `$this->assertVisible($request, $rentalInventory);` first, and stop creating the link from a GET (as `report()` already does).

---

## MEDIUM

### M1. Portal session cookie lets a user set a new password without knowing the current one — CONFIRMED [lead-verified]
- **Where:**
  - `app/Http/Controllers/Api/V1/ClientAuthController.php:179-185`, which returns 500 at 197-198.
  - Route: `POST /api/v1/client-auth/password/set`.
- **Cause:**
  - This release added `EnsureFrontendRequestsAreStateful` plus `client.auth` (`AuthenticateClientPortal`, which falls back to the `client-web` session guard) to the client-auth group.
  - Sanctum attaches a `TransientToken` to session-authenticated users, and `TransientToken::can()` always returns true.
  - So `$isActivation = $token->can('client-activation')` is true for every cookie-session ClientUser.
- **Scenario:**
  - Someone holding a portal session (a shared/unlocked browser, or a same-origin XSS such as H1 aimed at a tenant) posts `{password, password_confirmation}` with the XSRF header. The password is overwritten with no `current_password` check (compare `changePassword`, 390-393).
  - The request then 500s on `TransientToken->delete()`, but only after the password has already been saved.
  - Before this release the group was bearer-only.
- **Fix:** require `$token instanceof \Laravel\Sanctum\PersonalAccessToken` for both the activation branch and the must-change branch.

### M2. Take-on import: run and row actions ignore own/branch scope, and rows have no agency scope at all — CONFIRMED [lead-verified]
- **Where:** `app/Http/Controllers/CoreX/RentalTakeOnImportController.php`:
  - run actions: show :385, preview :324, errorsCsv :392, mapColumns :139, confirmMapping :159, cancel :373, confirmBulk :343, archive :418, restore :433
  - row actions: confirmRow :331, excludeRow :365
  - Model: `app/Models/RentalTakeOnImportRow.php` (no `BelongsToAgency`).
- **Within the agency:** `RentalTakeOnImportRun::scopeVisibleTo()` is applied only in `index()`.
  - An own-scope user with `.view` opens `/corex/rentals/take-on-import/{colleagueRunId}` and sees the colleague's uploaded landlord and tenant rows.
  - With `.manage` they can cancel, archive or bulk-confirm the run, which creates properties, contacts and leases.
- **Across agencies:** `POST .../rows/{row}/exclude` binds a row from any agency.
  - Line 367 saves `status=excluded` before anything else. The request then 500s in `touchRunCompletion()`, but the write has already been saved.
  - A `.manage` user in agency B can silently exclude agency A's pending import rows by stepping through ids.
- **Fix:**
  - Add `BelongsToAgency` to `RentalTakeOnImportRow`.
  - Check `RentalTakeOnImportRun::visibleTo($user)->whereKey(...)->exists()` in every run and row action, before any write.

### M3. Inventory wet-ink signature replacement has no own/branch check — CONFIRMED [lead-verified]
- **Where:**
  - `app/Http/Controllers/CoreX/RentalInventoryRecordingController.php:388`.
  - Route: `POST /corex/rental-inventories/{rentalInventory}/signatures/{signature}/supersede-wet-ink` (`rental_inventories.create`).
- **Scenario:** an own-scope agent replaces a party's signed scan on a colleague's inventory. The new file is filed and distributed.
- **Fix:** add `$this->assertVisible($request, $rentalInventory);` first.

### M4. Sending a buyer their acceptance link has no own/branch check — CONFIRMED [lead-verified]
- **Where:**
  - `app/Http/Controllers/CoreX/RentalInventoryBuyerAcceptanceController.php:35`.
  - Route: `POST /corex/rental-inventories/{rentalInventory}/buyer-acceptances/{contact}/send`.
- **Scenario:** an own-scope agent emails a colleague's buyers the signing link for that inventory, which also creates or refreshes its public token.
- **Fix:** use `EnforcesRecordVisibility` and call `assertVisible()` first.

### M5. Anyone with the shared inventory link can sign as any eligible buyer — CONFIRMED
- **Where:** `app/Http/Controllers/RentalInventoryPublicController.php:87-121`.
- **Cause:** the only credential is the inventory's single `public_token`, shared with tenant, landlord and seller. `buyer_contact_id` comes from the request body. The checks are only "same agency" and "eligible buyer".
- **Scenario:**
  - The seller or tenant records an acceptance in the buyer's name. Each buyer can accept only once, so the real buyer is then locked out.
  - The different error responses also reveal which contact ids exist in the agency.
- **Fix:** issue a per-buyer token (or an OTP/email gate) and take the buyer from the token, not the request body.

### M6. A crew's per-job link keeps working after the job card is moved to another crew — CONFIRMED
- **Where:**
  - `app/Models/RentalJobCard.php:538-543` (`assignCrew`)
  - `app/Models/RentalSecureAccessToken.php:150-161` (`crewJobCardTargetIsLive`)
  - `app/Http/Controllers/CrewJobLinkController.php:35-44`
- **Cause:**
  - The token stores only the card id.
  - Nothing compares the card's current `rental_crew_id` against the crew the link was issued to.
  - `revokeAllForArchivedCrew()` finds cards by their current crew, so archiving the old crew does not kill the link either.
- **Scenario:** after a reassignment, crew A can still use `/secure/job-cards/{token}` until it expires: tick tasks, upload photos, add and send parts and labour lines, and mark the job complete.
  - The contractor link has exactly this guard (`ContractorSecureLinkController.php:38`); the crew link lacks it.
- **Fix:** revoke the card's crew tokens in `assignCrew` when the crew changes, or store the crew id on the token and require it to match.

### M7. Job card `store()` accepts a property, work order or fault report outside the user's scope — CONFIRMED
- **Where:**
  - `app/Http/Controllers/CoreX/RentalJobCardController.php:177-230`
  - `app/Services/Rentals/RentalJobCardService.php:63-91`
- **Cause:** `property_id`, `lease_id`, `rental_work_order_id` and `fault_report_id` are checked with plain `exists:` and `findOrFail()`, with no `visibleTo()`. `RentalWorkOrderController::store()` has this check at :495; this path does not.
- **Scenario:**
  - An own-scope agent with `rental_job_cards.create` posts a colleague's work order id. They now own a card tied to it.
  - That gives them the tenant names and address, and lets them drive `send-quote` on the colleague's work order.
  - Separately, `lease_id` is not reconciled against the property when only a work order is given, so a card can end up with the wrong tenant.
- **Fix:** require `visibleTo($user)` for each supplied id, and force `lease_id` to match the resolved property.

### M8. The lease renewal API records a tenant's or landlord's notice without the review step — CONFIRMED
- **Where:**
  - `app/Http/Controllers/Api/V1/LeaseRenewalApiController.php:113-131`.
  - Routes: `POST /api/v1/leases/{lease}/renewal/tenant-notice` and `/landlord-notice` (`leases.renew`).
- **Cause:** the web path now creates a notice case that someone with `rental_notices.review` must accept (`LeaseNoticeService`). The API still calls `LeaseRenewalService::recordNotice()` directly.
- **Scenario:** an agent without review rights uses the API to set the move-out date and the readvertise/withdraw outcome, which changes the property listing, with no review and no notice case.
- **Fix:** route the API through `LeaseNoticeService::give(..., LeaseNotice::VIA_AGENT, ...)`, as the web path does.

---

## LOW

### L1. "Logins remembered here" cookie works as a non-expiring, non-revocable portal credential — PLAUSIBLE
- **Where:** `app/Services/PortalTabSession.php:46-55` and `ClientAuthController::tabToken` (540-576).
- **Cause:**
  - Each use refreshes the cookie's 12h life, so a browser that opens the portal at least every 12h stays signed in indefinitely.
  - Changing or setting the password does not clear it, and neither does a non-tab logout.
- **Scenario:** a tenant uses a shared PC, later changes their password, and anyone on that browser can still mint a tab token for them.
- **Fix:** bind each cookie entry to `password_set_at` or a nonce, cap its absolute age, and revoke tab tokens on password change.

### L2. Former tenant can still file fault reports and see first-aid content for a property they no longer rent — PLAUSIBLE
- **Where:** `app/Services/Rentals/RentalPortalScopeService.php:61-78` (`tenantLeaseIds` excludes only draft leases).
- **Scenario:** an ex-tenant posts to `POST /api/v1/client/rentals/properties/{old}/fault-reports`. A live fault lands in the agency queue and can go to the owner.
- **Fix:** require an active or month-to-month lease for write actions and property-specific content.

### L3. Tenant portal shows fault reports the owner raised on the tenant's lease — PLAUSIBLE (business call)
- **Where:** `RentalPortalScopeService::tenantFaultReports` / `tenantFaultReport` (168-186). The tenant scope filters only by `lease_id`, not by who reported it.
- **Scenario:** the tenant sees the owner's "request work" title, description and photos.
- **Fix:** if this is not intended, exclude `reported_by_type = landlord` (or require a sent/visible flag) in the tenant scope.

### L4. Inspection signing-link tokens are stored unhashed — CONFIRMED
- **Where:** `app/Services/Rentals/RentalInspectionSigningLinkService.php:356` and `app/Models/RentalInspectionSigningLink.php:128-138`.
- **Risk:** anyone who can read the database or a backup gets working sign/decline links. The other secure links in this release store only a SHA-256 hash.
- **Fix:** store a SHA-256 hash and look up by the hash.

### L5. Owner "Stop this" / "Query this" signed links never expire — CONFIRMED
- **Where:** `app/Mail/Rentals/RentalAuthorisationToOwnerMail.php:52-53` (uses `URL::signedRoute` with no expiry).
- **Risk:** limited, because the controller re-checks that the contact is still a current owner. A forwarded email still lets whoever has it put the job on hold or post questions as the owner indefinitely.
- **Fix:** use `temporarySignedRoute`, as the fault-settings dispute link already does.

### L6. Lease party-check returns any agency contact's name and FICA state by id — PLAUSIBLE
- **Where:** `app/Http/Controllers/CoreX/LeaseController.php:340-343` (`GET /corex/leases/party-check`, `leases.view`).
- **Scenario:** a user enumerates contact ids outside their contact scope and gets each contact's name, missing ID/email and FICA state.
- **Fix:** resolve the ids through the contact visibility scope, or require `leases.create`.

### L7. Sent-notices list sorts by any column name in `?sort=` — CONFIRMED
- **Where:** `app/Http/Controllers/CoreX/RentalNoticeController.php:47-49` (no whitelist).
- **Risk:** not SQL injection, because the identifier is quoted. A bad column name causes a 500, and sorting on hidden columns allows inferring their values.
- **Fix:** whitelist the sort keys.

### L8. Work order `create()` pre-fills from any inspection in the agency — CONFIRMED
- **Where:** `app/Http/Controllers/CoreX/RentalWorkOrderController.php:402` (`RentalInspection::find(...)` with no `visibleTo()`).
- **Risk:** read-only exposure of an out-of-scope inspection's observations and lease. Saving is still blocked by `store()`.
- **Fix:** `RentalInspection::query()->visibleTo($user)->find(...)`.

### L9. Work order `store()` / `storeBatch()` observation ids are not tied to the inspection or property — CONFIRMED
- **Where:** `RentalWorkOrderController.php:484, 553, 560` (plain `exists:`).
- **Risk:** a work order can be linked to any observation, including another agency's, and that "uses up" the observation for its real owner (the batch skips anything already raised).
- **Fix:** scope the `exists` rule through the inspection's property, and check the inspection with `visibleTo()`.

### L10. Fault report `store()` accepts another agency's fault type — CONFIRMED
- **Where:** `app/Http/Controllers/CoreX/RentalFaultReportController.php:386` (`exists:rental_fault_types,id` with no agency pin).
- **Risk:** data integrity only; nothing leaks.
- **Fix:** add an agency-scoped `Rule::exists`.

### L11. Fault report `create()` form uses unscoped lookups — CONFIRMED (older than this release)
- **Where:** `RentalFaultReportController.php:336-337` (`Property::findOrFail` / `Lease::findOrFail` with no `visibleTo()`).
- **Risk:** the form shows an out-of-scope lease's address, tenant names and landlord. `store()` refuses to save.
- **Fix:** add `visibleTo()` to both lookups.

### L12. Owner-role users are not pinned to the agency they work in on several new paths — PLAUSIBLE (may be intended)
- **Where:**
  - `AuthorizesRentalRecordScope::guardRentalRecordScope` returns early for `isOwnerRole()`.
  - The API rental reports and command centre (`Api/V1/RentalReportController`, `Api/V1/RentalCommandCentreController`). Token calls never carry a session agency override, so AgencyScope is lifted.
  - `RentalInspectionDueController::loadableLeases`.
  - `BuyerNotesAccess::visibleContactIds`.
- **Risk:** an owner account acting inside agency A reads and acts on agency B's records and report rows, while per-agency settings come from `effectiveAgencyId()`.
  - Contrast: `PropertyMarketingPost::scopeVisibleTo` already pins owners to their agency ("owner agency lock, 2026-10-18").
- **Fix:** if the owner lock is meant to apply everywhere, add `where('agency_id', $user->effectiveAgencyId())` for owner-role users in these paths.

---

## Checked and found clean
- **Admin `{agency}` routes:**
  - VAT types, catalogue item types and units: `authorizeAgency()` checks `effectiveAgencyId`, with child-belongs-to-agency checks.
  - AI usage resume and template limit: `owner_only`.
  - Article Library admin, Dev Settings and Template Transfer: `owner_only`.
- **Ad manager:**
  - CompanyAds: `ownAd()` checks the agency.
  - ScheduledPosts: `PropertyMarketingPost::visibleTo` is pinned to the agency even for owners, and image paths go through `belongsTo`.
  - Tools Article Library: global articles by design.
- **Road to Sold:** Areas, Targets, Report and the main screen use `VisibleAgents`, with hidden areas pinned to the agency. Settings routes are permission-gated per route.
- **Alerts:**
  - AlertInbox and AlertFeed `{id}` lookups go through `$user->notifications()`, and redirects are same-site only.
  - AlertSwitch uses `manage_users` plus `findScopedUser`.
- **Smaller controllers:**
  - AddressReview: agency-pinned.
  - AgencyType: `effectiveAgencyId`.
  - ProspectingListingListedDate: `visibleTo` with the MIC scope.
  - LeadResponseReport: the agent filter must be inside the scope cohort.
  - ListingStock reassign: assignees must belong to the listing's agency.
  - DealRegister: own deals only.
  - PpWebhook dedup: agency-pinned.
- **Client portal API:**
  - Every tenant and landlord lookup is pinned through `RentalPortalScopeService` (agency, deleted_at, own contact ids).
  - Agency and contact selection are validated against the login's own contacts.
  - Documents and files are served only from the person's own list, with storage paths from the DB.
  - The demo sign-in is gated to `APP_ENV=qa` plus a flag.
- **Public token routes:**
  - Contractor, crew page and completion links: 64-character random tokens, stored as SHA-256 hashes, purpose-checked, and liveness-checked on every POST. The child ids `{card}`, `{task}` and `{line}` are bound to the token's crew or card.
  - Inspection signing: liveness checks, re-checked under a lock, private signature files.
  - Owner and fault-settings signed routes use full `signed`, plus a current-owner re-check.
- **Leases and related:**
  - Leases, lease renewal (web), lease notices, breaches, rental notices, lease templates and settings: guarded.
  - Rental applications: guarded.
  - Inspections: guarded, with all child ids checked against the parent.
  - Lease capture request: the property, tenants, application and previous lease are all scope-checked.
- **Work orders and job cards:**
  - Every `{rentalJobCard}` and `{rentalWorkOrder}` action calls the guard.
  - The child ids `{invoice}`, `{quote}`, `{copy}`, `{approval}`, `{variation}`, `{dispatch}`, `{line}`, `{task}`, `{member}`, `{round}`, `{document}` and `{cause}` are parent-checked.
  - The supplier directory checks the agency.
  - The mobile job card API is guarded. `show` has no `.view` middleware, but the guard returns 403 when the user has no scope.
- **Deletes and mass assignment:**
  - No hard deletes of user data. The only `DB::table()->delete()` is the `user_managed_branches` assignment pivot.
  - No `agency_id` or `user_id` mass assignment from request input.
