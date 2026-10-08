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

---

## 16. Agent-side portal access — one person is one login; link and invite on the lease screen (7 Oct 2026, QA1)
**Trigger.** Johan, QA1 rentals test, 2026-10-07: on the new lease for a tenant (HFC contact 8966), under
"Tenant portal access", **Create Client Login** answered "email already in use" — for a tenant who had no
login at all. He asked how the logic works, wanted the dead end fixed, the link on the lease screen, and the
link in the signed-lease email.

**Root cause (not the login — the contact itself).** `ClientAuthService::isClientEmailTaken()` also counted any
`Contact` carrying the email, and the create form pre-fills the contact's own email, so every contact with a real
email collided with itself; with no `ClientUser` to attach to the controller fell through to a bare error and the
card still said "Not configured". Fixed at the root by one service (below), not by loosening the check.

### 16.1 How access works (the logic, written down)
- A `ClientUser` is **one account per email address, system-wide** (unique active email). Any number of that
  person's `contacts` rows point at it (`contacts.client_user_id`) — across agencies too (one Contact per agency).
- The portal acts for the login through its contact(s) **in the agency it has selected**. Before this stage it
  took only the first such contact; `RentalPortalScopeService::personContactIds()` now unions every contact of
  that login in the agency (tenant leases, landlord properties, documents), so one login carries both roles even
  when the person exists as two contact records. `ClientAuthService::contactForAgency()` is ordered, so the one
  contact used for attribution (`reported_by_contact_id`) is stable.
- **First-time access needs no agent action at all.** A person whose contact has a real email opens `/portal`,
  enters the email, gets a code, chooses a password; `findOrCreateClientUser()` creates the login and links the
  contacts carrying that email. The agent-side button only pre-creates it (so the card shows a status) and
  sends the link.

### 16.2 `RentalPortalAccessService` — the one agent-side path
`attach($contact, ?email, ?actor, ?request, ?tempPassword)`: idempotent; the email defaults to the contact's own.
- No login for the email → create it (origin agency = the contact's), attach. Login exists → **attach this
  contact to it** (outcome `created | attached | already | switched`).
- **Same-person rule** (so a stranger's login can never be opened onto this contact's data): the email must be on
  this contact, or on another contact in this agency (mirror column or any saved email), or the login must already
  be tied to a contact in this agency, or be an agent-managed placeholder address. Otherwise it is refused **in
  plain words** ("…belongs to someone who is not on your contact list…" / "…managed by another agency…" / "That
  email is not saved on <name>'s contact…") — never a bare "already in use".
- No email → "<name> has no email address saved yet. Add one to the contact first". The lease card links to the
  contact; nothing is typed on the lease screen, so the login always belongs to the person on file.
- **Email changed on the contact** (or the login is a `@corexclient.co.za` placeholder that cannot receive mail):
  the card shows "Contact email changed" with **Move the login to <new email>** (`switchToContactEmail`) — the
  contact moves to the login for the new email; the old login is soft-deleted only when nobody else is on it and it
  is the agency's to remove (a login managed by another agency is never touched).
- Two tenants on one lease: each contact is attached on its own; a shared email is one login. A person who is
  tenant on one lease and landlord on another is one login, both roles (16.1).
- `enabledFor($agencyId, 'tenant'|'landlord')` = the agency's tenant / landlord portal toggle **and** the Rentals
  features switch. Off → the card says so, no link is offered, nothing can be sent, no link in any email.

### 16.3 Lease screen (the two cards only — `corex.leases._portal-access-person`)
Per person: name, status (**Not set up · Pending OTP · Active · Must change password · Contact email changed ·
No email on the contact · Portal access is switched off**), the login email, then:
- not set up → one primary action **"Set up portal access & email the link"** (sets up, then sends) and a quiet
  "Set up only";
- set up → the personal link (`/portal?email=…` — the portal pre-fills the email and waits for **Continue**;
  nothing is looked up or sent by opening the link) with **Copy**, **Email link / Resend invite** and **Share on
  WhatsApp** (`WhatsAppNumberFormatter`, the contact's own dial code);
- the old reset-password / sign-out-devices / remove actions stay on the contact page (a link points there).
Routes `corex.leases.portal-access.{setup,invite,switch}` (`POST /corex/leases/{lease}/portal-access/{contact}/…`),
`LeasePortalAccessController`: lease scope first (own / branch / agency via `guardRentalRecordScope`), permission
`client_app.create_login`, and the contact must be one of THIS lease's tenants/landlords (anything else is a 404).
The contact page's "Create Client Login" (`ClientLoginController::create`) now goes through the same service.
Invites go through `RentalMailDispatcher` as the pressing agent (`RentalPortalInviteMail`, agency-branded,
neutral wording) and are logged to `client_access_logs` (`portal_invite_sent`).

### 16.4 Automatic access when a lease is signed + the link in the signed copy
- **Agency setting `auto_portal_access_on_signing`** (`rental_portal_settings`, read-time default **ON**), a
  has()-guarded saver `updateAutoPortalAccessOnSigning`, a toggle on Settings → Rental portal **and** a control on
  the Setup Wizard Rentals step (§10a): *"Give tenant and landlord portal access automatically when a lease is
  signed."* When ON, at signing every tenant and landlord with a real email gets their login
  (`provisionForSignedLease`, best effort, after the commit — a fault never undoes the signing; a person without an
  email, or whose email cannot be attached, is skipped and logged). OFF = fully manual and **no link in the mail**.
  Fires from `LeaseSigningStateService::announceSigned()` (e-signed lease) and from
  `LeaseCaptureService` after a signed paper copy is attached.
- **The completion copy** (`SignatureService::sendCompletionEmails` → `SignedDocumentMail`) of a lease agreement
  gains a **"Your CoreX portal"** block for each signer who is a tenant / landlord of that lease: what that role
  can really do today, a button and the plain link, and the first-time note (code, then choose a password). Tenant:
  *view your lease and the property, report a fault and follow it through to a fix, and see your documents.*
  Landlord: *approve or decline repair decisions, see your properties and tenancy, and follow faults and jobs.*
  (Copy lists only what the web portal's tabs offer — no statements, and inspections are API/mobile only, so neither
  is promised.) A person who is both gets one link listing both. No block for any other document, for a signer who
  is not a party to the lease, for a placeholder address, when that audience's portal is off, or when automatic
  access is off.
- **Paper copies.** Access is created automatically when a signed paper copy is attached, and (since §18) the parties
  are emailed the copy and their link — the same as the e-sign path.

### 16.5 Acceptance
- [x] Create on a contact whose email is its own, with no login: creates and attaches (no "already in use").
- [x] Existing login for the email: attached; stranger's login: plain refusal, nothing linked.
- [x] No email, changed email, placeholder login, two tenants, one person in two roles: each handled (tests).
- [x] Link with Copy / Email / WhatsApp / Resend on both cards; invite mail lists only what the role gets.
- [x] Signing creates access (setting, default ON, also in the wizard); the signed copy carries the block; nothing
      when the portal is off for the agency.
- [x] Multi-agency (CLAUDE.md #9): no HFC wording; defaults neutral; the second agency sees its own branding and
      its own toggles.
- Tests: `tests/Feature/RentalPortalAccess/{LeasePortalAccessTest,LeasePortalSigningTest}.php`.

**Files:** `app/Services/Rentals/RentalPortalAccessService.php` · `app/Exceptions/Rentals/PortalAccessException.php` ·
`app/Http/Controllers/CoreX/LeasePortalAccessController.php` · `app/Http/Controllers/Contacts/ClientLoginController.php` ·
`app/Mail/Rentals/RentalPortalInviteMail.php` + `resources/views/emails/rentals/{portal-invite,partials/portal-link-button}.blade.php` ·
`resources/views/corex/leases/{_portal-access-person,show}.blade.php` · `resources/views/rentals/portal/shell.blade.php` (email pre-fill) ·
`app/Services/Rentals/RentalPortalScopeService.php` · `app/Services/ClientAuthService.php` (ordered `contactForAgency`) ·
`app/Mail/Signatures/SignedDocumentMail.php` + `emails/signatures/signed-document.blade.php` · `app/Services/Docuperfect/SignatureService.php` ·
`app/Services/Rentals/{LeaseSigningStateService,LeaseCaptureService}.php` · `app/Models/RentalPortalSetting.php` +
migration `2026_10_15_000300_…` · `RentalPortalSettingsController` · `AgencySetupWizardController` · `config/agency-onboarding-copy.php` ·
`resources/views/corex/settings/rental-portal.blade.php` · `routes/web.php`.


---

## 17. "Unauthorized" after creating the portal password — a staff session in the same browser (7 Oct 2026, QA1)
**Symptom (Johan, QA1).** Opened a tenant's personal link, entered the emailed code, was asked to choose a password, chose one
and landed on "Unauthorized" — identically for the owner's link.

**Cause (one sentence).** Sanctum decides who a browser request is by trying the staff `web` session before the portal's own, so
in a browser that was also signed in as staff, the staff user answered for the portal's set-password call, and the portal
refused it because that user is not a portal person (`ClientAuthController::setPassword` → 401 "Unauthorized.").
In a clean browser the same path always worked (proved: `PortalLinkToHomeTest`, clean-browser cases).

**Fix — the bug class, not the one call.**
- New route middleware `client.auth` (`AuthenticateClientPortal`) replaces `auth:sanctum` on **every** client-portal route:
  `/api/v1/client-auth/password/set`, the logged-in `/api/v1/client-auth/*` group and all of `/api/v1/client/*` (so the
  rentals portal, matches, consent, testimonials, seller insights — tenant, owner and every other portal person). For the
  duration of that request Sanctum is told to consult only the `client-web` session guard, then the bearer token (the
  mobile app's path, unchanged); the setting is restored in `finally`, so staff and token APIs keep the default list.
- A staff session therefore can neither answer for a portal request nor be disturbed by one. A staff session alone still
  gets 401 from the portal API; a staff bearer token still gets 403 (`client.ability`).
- **Portal sign-out / account deletion no longer ends the staff session.** They used to invalidate the whole session, which
  signed a staff user in the same browser out. `ClientAuthController::endPortalSession()` removes only the portal login
  (session key + guard user) and destroys the session only when no staff user is in it. It deliberately does **not** call
  `SessionGuard::logout()`: that fires the global Logout event whose staff-only listeners
  (`RevokeCommsGrantsOnLogout`, typed `User`) would 500 on a `ClientUser` — found by the test below.
- **Existing logins need nothing.** Nothing was written wrongly: the failed call never set the password, so those
  people simply open their link again (code, then choose a password). A login created from the lease screen
  (Pending OTP) stays valid.
- A new portal route must use `client.auth`, never a bare `auth:sanctum`.

**Tests (`tests/Feature/RentalPortalAccess/PortalLinkToHomeTest.php`, 12):** the whole path over the real endpoints — personal link
email → lookup → emailed code → verify → set password (activation token on that one call only) → portal home (`/me`, then the
tenant's lease or the owner's property) — for tenant and owner, a brand-new login and an existing login, in a clean browser AND
with a staff user already signed in (held in the cookie session, as a real browser holds it); plus: returning person signs in
with the password in a staff browser; portal sign-out leaves the staff login in the session; a staff session alone and a staff
bearer token never open the portal API. Every simulated request starts with cleared guards and the default `web` guard (in
production each request is its own process; without this the test process leaks the previous request's user — and `Auth::shouldUse()`
writes the default guard into config — which produced misleading 403s while the test was being built).

---

## 18. Paper-signed lease: the parties are emailed the copy and their portal link (7 Oct 2026, QA1 — Johan's ruling)
**Ruling (Johan, 7 Oct 2026):** when a paper-signed (wet ink) lease is uploaded/attached, YES — the tenant(s) and owner get the lease
copy by email and their portal link, same as the e-sign path, same rules.

**What happens.** At the end of `LeaseCaptureService::capture()` with intent `paper_copy` — the only place a signed paper copy is
attached (New Lease, the Renewal screen's "I already have the signed copy", the renewal upload and both APIs all go through it) —
and after the lease has committed: (1) portal access is created for the parties (§16.4), then (2) `LeaseSignedCopyMailer::sendOnPaperAttach()`.
- **Who receives** (as `SignatureService::sendCompletionEmails`): the lease's tenants and landlords, never the agent; **one mail per
  distinct address** (a person who is both, or two tenants sharing an address, get one); a party with no email, or a placeholder
  address, is skipped and **named in the tenancy log**.
- **What:** the existing `SignedDocumentMail` — subject "Fully signed: Lease agreement — <address>", the signed copy attached under its
  own file name **and its own type** (a photo or Word upload is no longer labelled PDF — `SignedDocumentMail` takes an optional
  per-document `mime`; e-sign PDFs unchanged), and the per-person **"Your CoreX portal"** block under exactly the §16.4 rules
  (automatic access on, that audience's portal on, real email, party of the lease).
- **How (mail guard):** through `RentalMailDispatcher` as the agent who attached it — their own mailbox path, audited fallback to the shared
  mailer, and the non-production redirect via the mail guard — never a plain `Mail::to()`.
- **Logged:** one `lease_signed_copy_emailed` row in the tenancy log: who got it, who could not be reached, who has no email.
- **No new setting:** the e-sign copy mail has none, Johan asked for "same as the e-sign path", and the portal block already follows the
  agency's portal switches.

**Never twice — the re-upload rule (reported to Johan).** `leases.signed_copy_emailed_at` is an atomic once-per-lease claim (`UPDATE … WHERE
signed_copy_emailed_at IS NULL`, the same idea as `signature_templates.completion_emails_sent_at`), taken by the run that sends.
- Double submit of the same screen: the capture key returns the existing lease before anything runs — nothing is mailed again.
- Retry, or a different signed copy filed against the same lease later (a replacement): the claim is already set — **nobody is mailed
  again.** Today the lease screens have no "replace the signed copy" action (a paper capture always makes a new lease or renewal term, each
  with its own once-only mail), so this is a guard for any path added later; a corrected copy would have to be sent deliberately.
- **Nothing delivered** (every address failed, or nobody has an email): the claim is **released** (or never taken), so the lease is not left
  looking "sent" when nobody received it, and a later run can send. Partial delivery keeps the claim and names the failures in the log.
- A mail fault never undoes the capture (best effort, after commit).

**Tests (`LeasePaperCopyMailTest`, 16):** tenant and owner each get the copy + own link (agent never); access created first; tenancy-log row; one
person on two roles = one mail listing both; two tenants one address = one mail; a party without an email skipped and named; nobody with an
email = no mail, no claim; one portal off = copy without link; automatic access off = copy without link and no login; same screen twice; retry and
a replaced copy; all-fail releases the claim and a later run sends; one failing address does not stop the rest; a renewal from a paper copy mails the
renewal term's parties only; a photo upload keeps its own type; lease-only capture sends nothing.


---

## 19. The portal Documents area — signed lease agreement and distributed inspection reports (7 Oct 2026, QA1)
**Trigger.** Johan, after retesting the portal links: *"We are a bit shy on data on these links? We have documents — so we can put the lease
agreement here, we can put the inspection report here."* The Documents tab already existed for the tenant (names only, nothing openable, and
only documents flagged by hand); the owner had no Documents tab in the web portal at all.

**What each person sees — one list, built by `RentalPortalDocumentService` for both audiences, newest first, one row per document:**
1. **Lease agreements** — the SIGNED agreement of every live lease the person is a party to (tenant: their `lease_tenants` rows; owner: every
   lease on a property they own, earlier tenancies included — the owner already sees the occupancy history). "Signed" = signed and accepted
   (`signed_at`), `signed` / `signed_on_paper`, or created from a completed signed agreement (`source` `esign_document` /
   `uploaded_signed_copy`) **and** a filed document exists: the filed e-sign copy of the lease's envelope, else the attached wet-ink copy — the
   same document `Lease::signedDocument()` names, read scope-free and agency-pinned. **A lease that is not signed is never shown** (a draft,
   out for signing, awaiting the agent's approval, declined, voided, expired envelope). A signed renewal is **its own row** ("Lease renewal").
2. **Inspection reports** — only reports that have been **distributed**, using cc6's definition (rental-inspections.md §47,
   `RentalInspection::isDistributed()` = Completed, or an email copy logged as sent to a tenant/landlord): never a draft, one in progress, one
   still in signing, or a cancelled one. Tenant: reports on their own lease(s); owner: reports on leases of their own properties. The row is the
   signed PDF the completion step filed (`source_type` `rental_inspection_report`). The portal keeps **no rule of its own**: it calls
   `RentalInspection::isDistributed()` (cc6, landed on QA1 the same day) and only adds "not cancelled". No inspection code was touched.
   (`rental_inspections.lease_id` is required, so there is no lease-less inspection.)
3. **Documents the agency shared on purpose** — the pre-existing `tenant_portal_visible` / `landlord_portal_visible` flag + contact attachment,
   unchanged; merged into the same list (a document that is both a filed lease copy and flagged appears once, as the lease agreement).

**What I chose where the spec was silent (reported to Johan).**
- **Archived** lease → its documents AND its inspection reports are not shown: the standing rule (§6, 6 Oct) is that archived records are
  invisible to the portal; the owner's reports follow their lease too. **Cancelled / ended / renewed** (not archived) → stay visible as history.
- **Co-tenants** on one lease see the same lease documents (the list is per lease, not per contact record; one login across several contact
  records unions them, §16).
- **Owner** sees every signed lease and distributed report on their own properties, including earlier tenants' — the occupancy history they
  already see; a tenant never sees another tenancy's documents or the owner's.
- Soft-deleted documents never show; a document whose file is missing from disk stays listed but opening it is a clean 404.

**Each row:** name (e.g. "Lease agreement — <address>", "Move-in inspection report — <address>"), type (+ subtype: Renewal / Move-in /
Move-out / Interim / Ad hoc), date (signing date for a lease, completion date for a report), the property and the lease period it belongs to,
**View** and **Download**. API `GET /api/v1/client/rentals/documents` and `…/landlord/documents` (existing keys `id, name, type, uploaded_at`
kept for the mobile app; additive `kind, subtype, date, mime, size, belongs_to, view_url, download_url` + `meta`). Search (document name, type,
property address, lease period), sort (newest first by default; name; type), filter (type, date range), pagination (25, max 100), real empty
state (nothing yet) and a separate "no match — clear filters" state. The web portal shows one shared panel (`rentals/portal/_documents`) in the
tenant's Documents tab and a new Documents tab for the owner.

**Authorised downloads.** `GET /api/v1/client/rentals/documents/{document}/file` (and `…/landlord/documents/{document}/file`, `?download=1` to
force a download): behind `client.auth` + `client.ability` + the audience's portal switch, and the id is looked up **in the caller's own list**
(`findFor()` — the list and the file route share it), so an id that is not theirs — another party's, a draft, an archived lease's, a deleted
document, another agency's, or one that does not exist — is a 404 with nothing leaked. No public URL, no storage path in any response. PDF and
images open inline; anything else is always an attachment; `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`. Every
open/download writes `client_access_logs` (`document_viewed` / `document_downloaded`, document id, kind, role).

**Reported, not changed:** the existing tenant/landlord API lists `ClientTenantRentalsController::inspections` / `ClientLandlordRentalsController::inspections` (via `RentalPortalScopeService::tenantInspections()` / `landlordInspections()`) return every non-archived inspection of the party's lease(s), drafts and ones still in signing included — the status filter §45.8 planned is not there. Outside this task, so unchanged; the Documents area does not use their output.

**Not built (reported):** inventories, deposit/settlement schedules, notices, lease addenda that are not a separate lease, rent statements.

**Tests — `tests/Feature/RentalPortalAccess/PortalDocumentsTest.php` (17):** a tenant sees exactly their signed leases (paper, e-signed renewal,
cancelled-as-history), distributed reports on their leases and the shared document — and never a draft / out-for-signing lease, an archived
lease, a deleted or unshared document, a previous tenant's or another property's lease or report, an in-progress / in-signing / draft /
cancelled inspection or an archived lease's report; co-tenants see the same; "distributed" = completed or a sent copy; an owner sees their
property's signed leases and reports (earlier tenancies included, archived excluded) and another owner only theirs; a party opens their own file
(headers, bytes, audit rows); every cross-party / not-ready id is a 404 on both file routes (tenant↔owner, tenant↔tenant, owner↔owner,
cross-agency, unknown id); no session or a staff session alone is 401; missing file = 404; image inline / Word as download; the portal switch
closes the audience; search / sort / type / date / pagination / invalid filter; empty list; mobile keys kept; the page carries the panel for both audiences.


---

## 20. The portal Home — who to call, inspection dates, where the lease stands; and only SENT inspections in the list (8 Oct 2026, QA1)
**Trigger.** Johan agreed the next portal items on 8 Oct 2026, for both tenant and owner: the managing agent's contact, inspection dates, lease end / renewal
status, and the older inspections list to stop returning drafts. Rent statements, arrears, deposit and payments wait for the rental money build — **not here**.

**The Home tab.** A new first tab, "Home", opened by default for both audiences (`rentals/portal/_home.blade.php`, one panel). One block per property —
tenant: each property they rent; owner: each property they own (a vacant one shows "No current tenancy"). Compact, no helper text. API
`GET /api/v1/client/rentals/overview` and `…/landlord/overview`, behind `client.auth` + `client.ability` + the audience's portal switch, built by
`RentalPortalOverviewService` from `RentalPortalScopeService` only — never a bare find; agency and `deleted_at` pinned. The owner's response also carries
`decisions_waiting` (the existing pending decisions), shown as one link to the Decisions tab. Nothing is stored; no migration, no setting.

1. **Agent contact.** Name, designation, phone (cell first, else office phone), email (the outward-facing address, `User::outward_email`, never the login),
   and the agency / branch details (agency name, branch name, phone, email, address — the branch's own first, the agency's where the branch has none).
   Who is "the agent" (**amended 8 Oct 2026 — leases.md §17**): the lease now carries **two agents of its own**, the owner's agent and the
   tenant's agent. The **tenant** portal shows the lease's **tenant's agent**; the **owner** portal shows the lease's **owner's agent**; if that
   person is not an active user of the same agency (left, deactivated, archived, another agency) → the **property's agent** → the branch alone.
   A lease whose agents were never filled in is read through the same default rules (`LeaseAgentService::effectiveIds`). The earlier
   approver / creator fallback (`accepted_by_user_id` → `created_by_user_id` → `agent_id`) is gone. The office block is always present.
2. **Inspection dates.** *Upcoming*: a booked date today or later whose recording has not reached signing (draft or in progress) — type, date, time, nothing
   recorded. *Past*: inspections that have been **sent** — type, date, status ("Completed", or "Report sent" when a copy was emailed before the inspection was
   marked complete); newest ten listed, the rest counted. **No report link on the home**: a report is opened from Documents under §19's rule (distributed +
   signed lease etc.), unchanged. **cc6's "PDF only once ALL parties have signed" flag had not landed on QA1 when this was built** (cc6's worktree was level with
   QA1): the hook needed is one boolean on the inspection, e.g. `RentalInspection::isFullySigned()` (every required party has a non-voided signed / wet-ink
   signature), and the single place to call it is `RentalPortalDocumentService::inspectionIsShareable()` — add `&& $inspection->isFullySigned()` there. The portal
   keeps no copy of that rule and no inspection code was touched.
3. **Lease end and renewal.** From the lease record, in plain words, first match wins: *notice given* ("You have given notice" / "The tenant has given notice" /
   "The landlord has given notice" / "Notice has been given by you" — notice date and move-out date) → *renewed* ("Lease renewed — New term …") →
   *month-to-month* → *ended* → *renewal window* (the agency's own `expiry_notice_window_days` before the end date, days left) → *upcoming* ("Starts …") →
   *running* ("Ends …, N days left"). Always: start, end, and the **notice period** — the agency's own `tenant_notice_period_days` (default 30). The lease shown
   per property is the one in force, else the most recent that ran its course; a draft, cancelled or archived lease is never a home.

**4. Only sent inspections in the list endpoint.** `GET /api/v1/client/rentals/inspections` and `…/landlord/inspections` (and Documents, which reads the same
scope methods) now come from **one rule**, `RentalPortalScopeService::inspectionPortalState()`: *sent* = cc6's `RentalInspection::isDistributed()` (completed, or a
copy logged as sent to a tenant / landlord), or *scheduled* = a future booked date as above; never a draft, one in signing that is not yet sent, a cancelled one, or
a booked date that passed without a report. A scheduled row reports `status: "scheduled"` — a draft / in-progress state is never exposed. The old keys
(`id, type, status, completed_at`) are kept for the mobile app; `type_label, when, status_label, date, time` are added.

**Choices I made where the spec was silent (reported to Johan).** (The "agent on the lease" rule was replaced by leases.md §17.) A cancelled booking simply disappears (the parties were
already told when it was cancelled); a booked date that passes without a report disappears rather than showing as "missed"; co-tenants and owners see the same
rule as in §19.

**Tests — `tests/Feature/RentalPortalAccess/PortalHomeTest.php`:** the agent on the lease with branch / agency details; each side sees its own agent (tenant: tenant's agent, owner: owner's agent); an agent who left → the property's agent → the
branch; branch without details → the agency's; an agent of another agency never shown; every lease state and the wording for each side; the agency's own notice period
and renewal window; draft / cancelled / archived lease is no home; upcoming + past inspections, nothing unsent, no report link, newest ten + count; the list endpoint
(tenant and owner) returns sent + booked only and keeps the mobile keys; the owner home (one block per property, vacant property, tenant names); tenant ↔ tenant,
owner ↔ owner, tenant ↔ owner, another agency; no session / staff session alone = 401; the portal switch closes each audience; the page has the Home panel for both.

---

## 21. The owner's fault view and decision (8 Oct 2026, QA1 — fault flow F2/F3/F4/F7)

The owner's portal Faults / Decisions tabs show a fault only after the agent has SENT it (or it is decided, or the owner reported it): `RentalFaultReport::scopeVisibleToOwner()`, used by every `RentalPortalScopeService::landlord*FaultReport*` method. They show the agent's sanitised version only — title, description, shared photos, agent note — never the tenant's original. New endpoint `GET /api/v1/client/rentals/landlord/fault-reports/{id}` (detail + contractor list for this type of work + read-only decision) and an extended `POST …/decision` (Approve/Decline with required reason; own contractor with optional name/phone; a listed contractor; or "my agent arranges it"). The shell's Decisions tab opens a review-and-decide card; "My requests" opens the same card read-only. Full rules: `rentals-faults-work-orders.md` §15.
