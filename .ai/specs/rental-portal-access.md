# Rental Portal Access — Tenant, Landlord, Contractor + Notices (AT-445)

**Status:** BUILT. Landed 2026-10-07. Approved design, Johan, 4 Oct 2026; corrections to the
original design (web auth mechanism, token hashing, canonical landlord roles, scoping detail,
document visibility, several spec-drift findings) given by Johan 2026-10-05, applied below.
**Ticket:** AT-445. **Pillar:** Contact (tenant, landlord, contractor — every portal identity maps
to a `Contact`), Property, Agent (`User`, as the one who still sees everything an agent sees today,
unaffected).
**Master spec:** `.ai/specs/rentals-rebuild.md`. **Absorbs and extends, does not duplicate:**
`.ai/specs/rentals-faults-work-orders.md` §4 (the secure-link/`ClientUser` design for faults/work
orders). That section's "no login for contractors, real login for tenant/owner" design was already
**settled by Johan on 2026-09-29** (not "HELD", as the original draft of this spec claimed — that
was stale framing describing an earlier version of that file) — this spec extends the same
mechanism to leases, inspections, documents, and adds notices, which §4 did not cover.

---

## 1. What this stage does and why
The audit confirmed a real, built, authenticated `ClientUser` + Sanctum + OTP system exists
(`/api/v1/client-auth/*`, `/api/v1/client/*`) with real `Contact` identity mapping
(`contacts.client_user_id`) — but carried **zero** rentals functionality before this ticket; it was
used only for buyer/seller matches, consent, and testimonials, and was **API/bearer-token only** —
no Blade web layout for an authenticated `ClientUser` session existed anywhere (the original spec's
claim that one did was spec drift — buyer-portal/match pages are separate, public, token-gated
pages, not `ClientUser` sessions). Contractors had no login mechanism of any kind. Breach
notices/notice-to-vacate did not exist, not even as a spec. This stage extends the existing portal
to tenants and landlords (adding the first web client of it), adds a no-login secure-link mechanism
for contractors, and builds the notices feature from scratch.

## 2. Tenant access (extends `ClientUser`, same login mechanism — no new auth system)
A tenant signs in through the **existing** `ClientUser` OTP/password login
(`/api/v1/client-auth/*`, unchanged) — no new password/OTP flow was built. Once authenticated, a
tenant with a `Contact` linked to an active `lease_tenants` row sees:
- **Lease** — read-only view of their own lease terms: rent, deposit, dates, landlord name(s) only
  (no phone/email — an agent reaches the landlord off-portal).
- **Documents** — any filed `Document` flagged `tenant_portal_visible` AND attached
  (`document_contacts`) to this exact `Contact` — never a blanket "any document on the property",
  which would leak a previous tenant's paperwork. (`rental-documents-spec.md` turned out to be the
  e-sign *template* catalogue, not the generic document store — the real store is
  `App\Models\Document`, which had no visibility concept before this ticket; `tenant_portal_visible`
  / `landlord_portal_visible` are two new independent boolean columns added here.)
- **Report a fault** — opens with the agency's own first-aid content FIRST (reuses the existing
  fault-catalogue first-aid mechanism, `RentalFaultTypeService::renderFirstAidSteps()`), then one
  submit action branching on which button the tenant pressed: "that fixed it" →
  `RentalFaultReport::setOutcome(['outcome' => OUTCOME_RESOLVED_BY_FIRST_AID])` (status closes
  immediately, no staff actor); "still a problem" → the exact same
  `RentalFaultReportService::report()` an agent call uses, `captured_by_user_id` omitted,
  `reported_by_contact_id` set to the tenant, `reported_channel='app'` (not `'portal'` — the
  original draft invented a channel value that doesn't exist; `'app'` is the one already reserved
  for self-service digital channel, covering both this web portal and Andre's future native app).
- **Track status** — their own fault reports and work orders, read-only, status + timeline, scoped
  to records tied to their own lease only. Never the landlord's contact details beyond name, never
  a quote amount (not their spend to approve).
- **Confirm a completed job** — "fixed" / "not fixed", contact-attributed, via
  `RentalWorkOrder::confirmByTenant()` (new). Mirrors onto the 1:1 job card
  (`rental_job_cards.tenant_confirmed_fixed`/`tenant_confirmed_by_contact_id`, new columns) when one
  exists — AT-442's own migration comment explicitly deferred real tenant attribution to this
  ticket ("tenant confirmation is recorded BY THE AGENT for now — tenant login is AT-445").

## 3. Landlord access (extends `ClientUser`)
A landlord signs in the same way, via their own `Contact`. Landlord-side role resolution uses the
SAME canonical keys `Property::pivotRolesForContactRole('landlord')`/`Lease::landlordContacts()`
already use — `contact_property.role IN ('landlord', 'lessor')` — never a hardcoded string
duplicated in the portal layer. Sees:
- **My properties and current tenancy** — occupancy history: tenant NAME and dates only, never ID
  numbers, application documents, payslips, bank statements, or FICA files.
- **Decisions** — fault reports awaiting the owner-approval route, and work-order quotes over the
  spend limit. Approve / Decline / "I'll handle it myself" (note required) on a fault report drives
  `RentalFaultReport::recordApproval()` — widened this ticket to accept `User|Contact` (a landlord's
  own click is the evidence now, `evidence_type='portal'`, `recorded_by_contact_id` set,
  `recorded_by_user_id` null; existing agent-recorded calls are completely unchanged). A work-order
  quote decision is Approve/Decline only (no "handle it myself" — a contractor is already engaged)
  via the identically-widened `RentalWorkOrder::recordApproval()`. No second approval system either
  way — same `rental_approvals` table, same state machine.
- **Property activity** — faults, work orders **with amounts**, inspections.
- **Documents** — any filed `Document` flagged `landlord_portal_visible` and attached to this
  contact (same mechanism as §2, independent flag).

*(The AT-443 landlord property activity print report is a separate screen the audit found spec-only
at the time of this build — omitted here per the build brief's own instruction; add it if/when its
route lands.)*

## 4. Contractor access — secure per-job link, no login (new, not extending `ClientUser`)
Contractors get **no login** — a per-job secure link. New table `rental_secure_access_tokens`
(`agency_id`, `rental_work_order_id` — flat FK, not polymorphic: this ticket scopes contractor links
to work orders only — `agency_service_provider_id`, `token_hash` [SHA-256 of a 64-char random raw
token; the raw value is generated, handed back once, and never persisted or logged],
`expires_at`, `revoked_at`, `last_used_at`, `created_by_user_id`). `RentalSecureAccessToken::isLive()`
is the single gate: false once expired, revoked, the work order is completed/cancelled, or the
agency has turned `contractor_links_enabled` off. Regenerating a link revokes any previous live
token first — at most one active link per work order (`RentalSecureAccessTokenService`).
- **Upload quote** — `RentalWorkOrder::recordQuote()` (widened to accept a nullable `User` plus an
  optional `$viaNote`) feeds the existing `RentalWorkOrderQuote` table exactly as an agent-entered
  quote would, `captured_by_user_id` null, history entry tagged "via contractor link".
- **After photos** — `RentalWorkOrderService::storePhoto()` (same widening), the SAME
  `PropertyImageStorer` pipeline every other caller uses — a contractor upload is the fourth caller,
  not a new pipeline.
- **Mark done** — `RentalWorkOrderService`'s underlying `RentalWorkOrder::complete()` (same
  widening), one more caller of the real completion path, defaulting `paid_by='not_yet_paid'` since
  a contractor doesn't know the payment arrangement — an office follow-up, not a new field.
- Rate-limited at the route level (`throttle:30,1`, the same middleware every other public
  token-gated route in this app already uses, e.g. buyer-portal) — never a custom limiter.
- A top-level controller (`App\Http\Controllers\ContractorSecureLinkController`, not under
  `...\CoreX`), mirroring `RentalInspectionPublicController`'s own established pattern for
  no-session public routes: no route-model binding, token resolved and liveness-checked explicitly,
  the SAME "unavailable" response whether expired, revoked, unknown, or closed.

## 5. Role-filtered, not duplicated
Tenant, landlord, and contractor screens read the **same underlying records** an agent sees — faults,
work orders, inspections, leases, documents — filtered by role and by the specific record's own
scope, never a second copy of any table.

## 6. Scoping — `RentalPortalScopeService` (BUILD_STANDARD §1c)
One service is the single scoping layer for both audiences (`app/Services/Rentals/
RentalPortalScopeService.php`). AgencyScope is **not** applied automatically for a `ClientUser`
request (it resolves off the staff `Auth::user()`, which a `ClientUser` request never is) — every
method here explicitly `withoutGlobalScopes()` and filters by `agency_id` manually, mirroring
`ClientAuthService`'s own sanctioned bypass pattern:
- `tenantLeaseIds()`/`tenantLease()`/`tenantFaultReport()`/`tenantWorkOrder()` etc. — resolved from
  `lease_tenants.contact_id`, never a bare `Lease::find($id)`.
- `landlordPropertyIds()`/`landlordProperty()` etc. — resolved from `contact_property` where
  `role IN ('landlord','lessor')`.
- A contractor's secure link resolves ONLY the one `rental_work_order_id` the token was minted for.
- **Direct-URL-by-ID is blocked for all three audiences** — every single-record controller method
  intersects the requested id against the caller's own resolved id set and returns 404/403, never
  trusting a client-supplied id. Verified by `tests/Feature/RentalPortalAccess/PartyIsolationTest.php`
  and `ContractorSecureLinkTest.php`.
- **Archived records are invisible to the tenant / landlord portal (6 Oct 2026).** `withoutGlobalScopes()` also strips
  SoftDeletes, so **every** query in `RentalPortalScopeService` pins `deleted_at IS NULL` explicitly — an archived
  lease, fault report, work order, inspection, inventory, document, property or job card is neither listed nor openable
  by id (404, exactly like an out-of-scope record), on the web shell and the API alike. `tenantLeaseIds()` returns only
  the tenant's **live** leases, so archiving a lease takes everything that hangs off it (its faults, orders, inspections,
  inventories, job cards) out of the tenant's reach in one place. The two portal controllers no longer run their own
  scope-stripped queries: property / lease / pending-decision / fault-decision lookups go through the service
  (`tenantProperty()`, `tenantLeaseIdForProperty()`, `landlordPropertyLeases()`, `landlordActiveLeaseId()`,
  `landlordFaultReport()`, `landlordPendingFaultReports()`, `landlordPendingWorkOrders()`). Landlord property access
  was already safe (the `contact→properties()` relation honours SoftDeletes). Notices are not exposed on the portal.
  Proved per resource type by `tests/Feature/RentalPortalAccess/ArchivedRecordsHiddenFromPortalTest.php`
  (job cards: `TenantLandlordJobCardApiTest`).

## 7. Web authentication — Sanctum stateful SPA (cookie), corrected from the original draft
The original draft assumed a web Blade layout already existed for `ClientUser` sessions and said
tenant/landlord web pages would "reuse" it. **That layout did not exist** — the client portal was
API/bearer-token only (Audit §10). Johan's correction: the web portal must **never** store a bearer
token where the page's own JavaScript could read it back out (XSS = account takeover). The actual
mechanism built:
- Sanctum's stateful-SPA mode (`EnsureFrontendRequestsAreStateful`) is prepended **only** to the
  `v1/client-auth`/`v1/client` route groups in `routes/api.php` — **not** re-added to the global
  `api` middleware group, which `bootstrap/app.php` deliberately strips it from to keep the mobile
  app's bearer-token flow immune to Origin/Referer tricks. Scoped this way, the rest of `/api`
  (including the rest of this very mobile client API) is completely unaffected.
- A new session guard, `client-web` (`config/auth.php`, provider `client_users` → `ClientUser`),
  added to `config/sanctum.php`'s `guard` array alongside the untouched default `web`.
- `ClientAuthController::login()`/`setPassword()` detect a stateful request
  (`$request->attributes->get('sanctum')`, set by the middleware itself) and branch: stateful →
  `Auth::guard('client-web')->login($clientUser)` + session regenerate, **no token in the response
  body at all**; non-stateful (mobile) → completely unchanged, still issues a bearer token.
- `config/sanctum.php`'s stateful-domain default now also includes `Sanctum::currentRequestHost()`
  — the CURRENT request's own host — so the portal works on every environment (QA1/Staging/Prod/
  demo/local) without per-environment `SANCTUM_STATEFUL_DOMAINS` wiring, while staying safely scoped
  (only "this server, talking to itself" ever qualifies as stateful).
- A pre-existing, guard-agnostic `Login`/`Logout` event listener in `AppServiceProvider` wrote every
  login to `login_histories.user_id` (a `users` FK) unconditionally — this broke the moment a
  `ClientUser` logged in via the new guard (FK violation). Fixed by scoping that listener to
  `instanceof App\Models\User` — staff logins are completely unaffected; `ClientUser` logins already
  have their own audit trail (`ClientAccessLog`).
- Tested end-to-end: `tests/Feature/ClientAuth/ClientWebPortalSessionAuthTest.php` — stateful login
  establishes a session and never returns a token; non-stateful login is unchanged; both stateful
  and non-stateful unauthenticated requests are rejected (401).
- **Web screens**: a single new Blade shell (`resources/views/rentals/portal/shell.blade.php`) —
  mobile-first, Alpine.js, client-side "router" over one route (`GET /portal/{any?}`) — the FIRST
  web client of `/api/v1/client/*`, calling it via same-origin `fetch()` with the session cookie.
  Andre's mobile app remains the bearer-token client of the identical endpoints.

## 8. Notices — breach / notice-to-vacate (new, confirmed absent by the audit)
The original draft said this would reuse "the existing e-sign importer" (`Docuperfect\Template`).
That system is **pipeline-gated** (CLAUDE.md) and built for multi-party signing — a one-way "send
and log" notice doesn't need that machinery, and routing through it would have pulled this feature
through signing-pipeline risk for no reason. Built instead as a small, self-contained system:
- `RentalNoticeTemplate` (agency-owned, `notice_type` breach|notice_to_vacate, `body_html` with
  `{{token}}` placeholders — same convention as the fault-type first-aid content) — full CRUD +
  archive/restore, search/sort/filter/pagination (Settings → Rental Notice Templates).
- Agent picks a lease (via "Send notice" on the Lease Hub) → picks a template → types figures as
  `token=value` lines → chooses tenant and/or landlord → `RentalNoticeService::send()` renders the
  template, generates a PDF (`RentalDocumentPdfService::noticePdf()` — the SAME dompdf convention
  every other rentals PDF uses), files it as a `Document`, and emails it (`RentalNoticeMail`,
  attached as PDF) — delivery is email only, per instruction.
- Logged on the tenancy: `RentalNotice` row + a new `'notice'` entry type in
  `LeaseTimelineService` (that file's own docblock had already reserved this exact extension point).
- **Arrears figures**: no Stage 8 yet, so the agent types any figure manually — stated as a forward
  dependency, not built twice, unchanged from the original design.

## 9. Routes, nav, permissions, API
- **Tenant/landlord portal routes:** `/api/v1/client/rentals/*` and `/api/v1/client/rentals/
  landlord/*`, gated additionally by `rental-portal.enabled:{tenant|landlord}` middleware (§10) —
  built under the existing `/api/v1/client/*` namespace.
- **Contractor secure-link routes:** `/secure/work-orders/{token}/*` (show, quote, photo,
  mark-done) — `routes/web.php`, `throttle:30,1`, no `ClientUser` session.
- **Web portal shell:** `GET /portal/{any?}` (`routes/web.php`).
- **Permissions:** portal END-USER access is gated by the agency settings in §10, not by
  `config/corex-permissions.php` (the portal audience isn't a CoreX staff role) — unchanged from the
  original design. New STAFF-side keys: `rental_portal.manage_settings`, `rental_notices.create`,
  `rental_notice_templates.manage_settings`.

## 10. Agency settings (Setup Wizard, CLAUDE.md non-negotiable #10a)
New table `rental_portal_settings` (one row per agency, read-time default pattern), each toggle its
own independent, has()-guarded saver — never one combined saver — per
`agency-onboarding-setup.md` §6.1's warning that a shared saver requiring several fields at once
will reject a wizard step render that only shows some of them:
- `tenant_portal_enabled` / `landlord_portal_enabled` / `contractor_links_enabled` — default ON.
- `contractor_secure_link_expiry_days` — default 14.
- `notify_landlord_on_decision_needed` / `notify_tenant_on_status_change` — default ON. (There is
  **no** `notify_agent_on_new_fault` setting — that notification already existed as live, generic
  infrastructure: `RentalFaultReportService::notifyCreated()` → `NotificationDispatcher`, which
  resolves channel per-USER via `NotificationPreferenceService`, a finer control than a second,
  agency-wide toggle would be. The two settings that ARE new address genuinely new audiences —
  landlord/tenant are portal identities, not CoreX staff `User`s, so the existing dispatcher can't
  reach them.)
- All six controls registered on the existing Rentals wizard step (`config/agency-onboarding-copy.php`),
  each with its own saver entry, matching `RentalWorkOrderSettingsController`'s own established
  shape for this exact reason.
- `notify_landlord_on_decision_needed` fires from `RentalFaultReport::requestApproval()` and
  `RentalWorkOrder::selectQuote()` (when a quote crosses the spend threshold).
  `notify_tenant_on_status_change` fires from `RentalFaultReport::recordApproval()`,
  `setOutcome()` (skipped for the tenant's own first-aid self-resolution — notifying someone of the
  action they just took is noise), and `recordWorkOrderRaised()`.

*(§8's `notice_email_only` setting from the original draft was not built as a stored toggle — there
is no second channel yet to toggle against, so it would be vacuous. Email-only is the fixed,
current behaviour; add the setting when/if a second channel is actually built.)*

## 11. CRUD / list-screen floor — agent-side screens this stage adds
- **Notices list** (`corex.rental-notices.{index,show}`): search (tenant name, property address via
  the lease relation), sort (date sent, default newest-first), filter (notice type, date range),
  agency scope, pagination, empty state. Not edited after sending — soft-delete floor only.
- **Notice template CRUD** (`corex.rental-notice-templates.*`): search (name), sort (name/notice_type/
  created_at), filter (notice_type, active/archived), archive/restore, pagination, empty state.
- **Invite surfaces**: the existing "Client App Access" partial
  (`corex.contacts.partials.client-app-access`) — unmodified — now also renders on the Lease Hub
  (per tenant and landlord contact) and the property Rental tab (current lease's tenant(s) +
  property's landlord(s)). Last-login was already shown by that partial; no new UI needed for it.

## 12. Acceptance criteria
- [x] A tenant can log in (OTP or password, same existing flow) and see only their own lease's data.
- [x] A landlord can log in and approve/decline/"I'll handle it" on a real quote/fault report,
      reusing the existing approval mechanism (now `User|Contact`-attributable).
- [x] A contractor's secure link lets them upload a quote, upload after-photos, and mark done,
      without any login, and the link stops working after expiry/revocation/completion.
- [x] Direct-URL-by-ID access outside each audience's own scope is blocked for all three audiences —
      covered by automated tests.
- [x] A notice can be drafted from an agency-uploaded template, sent by email, and appears in the
      tenancy log.
- [x] All portal on/off settings and the contractor link-expiry setting are in the Setup Wizard.
- [x] Notices list meets the full list-screen floor.
- [x] The web portal session-cookie auth path is covered by an automated test, including the
      unauthenticated-rejection case.

## 13. Multi-agency
No notice template, no first-aid copy, no portal wording assumes HFC. Every template is
agency-uploaded (§8); every default (§10) is agency-neutral.

## 14. Spec-drift found and resolved during build (for the record)
The approved design (2026-10-04) contained several claims that didn't match the actual codebase,
surfaced during Step 0 investigation and resolved with Johan before building (2026-10-05):
1. §4's "HELD" framing for login-vs-secure-link was stale — already settled 2026-09-29.
2. `reported_channel='portal'` doesn't exist as an enum value — built using the existing `'app'`
   value instead.
3. The proposed `rental_secure_access_tokens` polymorphic shape (`tokenable_type/id`, covering fault
   reports too) was simplified to a flat `rental_work_order_id` FK, matching this ticket's actual
   scope (work orders only).
4. `rental-documents-spec.md` is the e-sign template catalogue, not a generic document store with a
   visibility concept — the real target for the two new visibility flags is `App\Models\Document`.
5. No Blade web layout existed for an authenticated `ClientUser` session — the client portal was
   API/bearer-only. §7 above is the resolution: Sanctum stateful-SPA cookie auth, scoped narrowly,
   plus the first web shell.
6. The AT-443 landlord property activity report route did not exist at build time — omitted per
   the build brief's own contingency instruction.

## 15. Landlord "Request work / report a problem" (AT-447 follow-up, built 2026-10-05)

**Small, additive amendment.** §3 above described the landlord as decide-only ("Decisions — fault
reports awaiting the owner-approval route... Approve / Decline / 'I'll handle it myself'") — this
section adds the one thing that was genuinely missing: a landlord can now also RAISE a problem
themselves, on a property they own, from the portal.

**What it does NOT change:** the landlord still never creates a work order directly and never picks
a supplier — both of those stay exclusively an agent action, raised from the fault report the agent
now sees once the landlord's request lands. This is a new way to CREATE a `rental_fault_reports` row,
not a new way to commission work.

- **New value**, `RentalFaultReport::REPORTED_BY_LANDLORD = 'landlord'` — distinct from
  `owner_instructed` (an agent recording that the owner asked for something verbally/by phone/
  WhatsApp); `landlord` means the landlord typed it into their own portal session directly. Never
  offered on the agent-side "Report a Fault" create form — only
  `ClientLandlordRentalsController::faultReportStore()` ever sets it. Rendered automatically by the
  existing generic `ucfirst(str_replace('_', ' ', $faultReport->reported_by_type))` label everywhere
  "Reported by" is already shown — no new template/match-statement needed.
- **Route**: `POST /api/v1/client/rentals/landlord/properties/{property}/fault-reports` →
  `ClientLandlordRentalsController::faultReportStore()`, gated by the SAME
  `rental-portal.enabled:landlord` middleware and `RentalPortalScopeService::landlordProperty()`
  ownership check (404 for a property the contact isn't a landlord/lessor on, including cross-agency)
  as every other landlord-portal route in §6 — mirrors
  `ClientTenantRentalsController::faultReportStore()` closely, minus the fault-type/first-aid step
  (a landlord isn't asked "did that fix it" the way a tenant is — there is no self-resolution path
  for a landlord's own request).
- **Lease attachment**: the property's active lease if one exists, else `lease_id = null` — the same
  vacancy-period allowance the agent-side create form already has.
- **Lands exactly like any other fault report**: `RentalFaultReportService::report()` is the same
  method the agent-side and tenant-side paths both call, so the new row appears in the agency's Fault
  Reports list and the Command Centre's open-faults tile with NO extra wiring — both already query by
  `status`, never by `reported_by_type` (confirmed directly against
  `RentalCommandCentreService`'s own fault-report queries before writing this). An agent raises the
  work order from it via the pre-existing `raiseWorkOrder()` action, choosing supplier or the internal
  team exactly as for any other fault report.
- **Isolation**: covered by the same party-isolation suite as the tenant path —
  `tests/Feature/RentalPortalAccess/PartyIsolationTest.php` — a landlord can only raise on their own
  property; a cross-agency landlord contact 404s, never leaks a row into the wrong agency's list.

**Files:** `app/Models/RentalFaultReport.php` (new constant) ·
`app/Http/Controllers/Api/V1/ClientLandlordRentalsController.php` (new `faultReportStore()`) ·
`routes/api.php` (one new route) ·
`tests/Feature/RentalPortalAccess/{RentalPortalWorkflowTest.php,PartyIsolationTest.php}` (new tests).
