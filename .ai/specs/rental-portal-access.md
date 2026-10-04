# Rental Portal Access — Tenant, Landlord, Contractor + Notices (AT-445)

**Status:** SPEC ONLY. Approved design, Johan, 4 Oct 2026.
**Ticket:** AT-445. **Date:** 2026-10-04. **Pillar:** Contact (tenant, landlord, contractor —
every portal identity maps to a `Contact`), Property, Agent (`User`, as the one who still sees
everything an agent sees today, unaffected).
**Master spec:** `.ai/specs/rentals-rebuild.md`. **Absorbs and extends, does not duplicate:**
`.ai/specs/rentals-faults-work-orders.md` §4 (the secure-link/`ClientUser` design for faults/work
orders — SPEC ONLY, unbuilt, confirmed absent by the audit). That section's design is not re-argued
here; this spec extends the same mechanism to leases, inspections, documents, and adds notices,
which §4 did not cover at all.

---

## 1. What this stage does and why
The audit confirmed a real, built, authenticated `ClientUser` + Sanctum + OTP system exists
(`/api/v1/client-auth/*`, `/api/v1/client/*`) with real `Contact` identity mapping
(`contacts.client_user_id`) — but carries **zero** rentals functionality today; it is used only for
buyer/seller matches, consent, and testimonials (audit Part 1 item 10). Contractors have no login
mechanism of any kind. Breach notices/notice-to-vacate do not exist, not even as a spec (audit Part
1 item 11, Part 5 top-gap #5). This stage extends the existing portal to tenants and landlords, adds
a no-login secure-link mechanism for contractors, and builds the notices feature from scratch.

## 2. Tenant access (extends `ClientUser`, same login mechanism — no new auth system)
A tenant signs in through the **existing** `ClientUser` OTP login (`/api/v1/client-auth/*`,
unchanged) — no new password/OTP flow is built. Once authenticated, a tenant with a `Contact` linked
to an active `lease_tenants` row sees:
- **Lease** — read-only view of their own Lease Hub (a cut-down `leases.md` §12 view: terms, dates,
  rent, landlord contact details for maintenance purposes only).
- **Documents** — the lease document and any other filed document tagged visible-to-tenant (per
  `rental-documents-spec.md`'s existing document inventory — this spec adds a visibility flag, does
  not redesign the document store).
- **Report a fault** — opens with the agency's own first-aid content FIRST (reuses the existing
  fault-catalogue first-aid mechanism, `rentals-faults-work-orders.md` §2, already built), then the
  report form (reuses `RentalFaultReportService::report()` via the mobile/portal API seam already
  named in `rental-work-orders.md` §13 — `reported_channel='portal'`, `captured_by_user_id` omitted,
  exactly the seam already reserved for this purpose).
- **Track status** — their own fault reports and work orders, read-only, status + timeline, scoped to
  records tied to their own lease only (never another tenant's, never another property's).

## 3. Landlord access (extends `ClientUser`)
A landlord signs in the same way, via their own `Contact` (role `landlord`/`lessor`, the existing
`contact_property` pivot — audit Part 3 item G, unchanged). Sees:
- **Approve / decline / "I'll handle it"** on quotes and work orders raised against their property —
  reuses the existing owner-approval mechanism (`rental-work-orders.md` §3.4a/§4), this stage adds
  the portal UI for the decision, not a new decision model.
- **Property activity** — the single-property print report already specced
  (`rentals-reports.md` §5, "landlord property activity report") rendered as a portal screen instead
  of a print-on-demand document — same underlying query, a screen instead of a PDF.
- **Inspection reports** — read-only, their own property's completed inspections.
- **Occupancy history** — the same addition to the Property Rental tab (`leases.md` §12.6), landlord-
  facing version (no internal agent notes, tenant contact details limited to name only for privacy).

## 4. Contractor access — secure per-job link, no login (new, not extending `ClientUser`)
Per instruction, contractors get **no login** — a per-job secure link, consistent with
`rentals-faults-work-orders.md` §4's own design intent for this audience (that section left the
secure-link-vs-login question HELD for Johan; this spec proceeds on "no login" per the task brief's
explicit instruction, closing that held question for the contractor case specifically). New table
`rental_secure_access_tokens` (work_order_id, token, expires_at, used_at nullable, agency-configurable
expiry window) — confirmed absent by the audit, built here for the first time.
- **Upload quote** — a contractor without a login submits a quote amount + optional document via the
  tokened link, feeding the existing quotes table (`RentalWorkOrderQuote`, `rental-work-orders.md`
  §3.4) exactly as an agent-entered quote would.
- **After photos** — same photo pipeline every other mobile/portal surface uses (`PropertyImageStorer`,
  per `rental-work-orders.md` §13's established "one photo pipeline, not two/three" rule — a
  contractor upload is the fourth caller of the same pipeline, not a new one).
- **Mark done** — sets the work order's completion evidence exactly as the web/mobile completion gate
  already requires (`rental-work-orders.md` §3.4) — a contractor's "mark done" is one more caller of
  `RentalWorkOrderService::complete()`, not a parallel completion path.

## 5. Role-filtered, not duplicated
Tenant, landlord, and contractor screens read the **same underlying records** an agent sees — faults,
work orders, inspections, leases, documents — filtered by role and by the specific record's own
scope (this tenant's lease, this landlord's property, this contractor's job), never a second copy of
any table. Nothing in this stage introduces a tenant-specific or landlord-specific duplicate of any
existing entity.

## 6. Scoping (BUILD_STANDARD §1c, applied to the portal specifically)
- A tenant's portal session never resolves any record outside their own active lease — enforced at
  the API layer by resolving the authenticated `ClientUser`'s linked `Contact` → that contact's
  `lease_tenants` rows → only those leases' child records, never a bare `Lease::find($id)` trusting a
  client-supplied ID.
- A landlord's session resolves via `contact_property` the same way, scoped to their own properties.
- A contractor's secure link resolves ONLY the one `work_order_id` the token was minted for —
  expired/used tokens return a clear "this link has expired" page, never a 500, never silently
  reusable past expiry or past the work order's own completion.
- **Direct-URL-by-ID is blocked for all three audiences**, not just unlinked — the exact floor item
  BUILD_STANDARD §1c states and the audit found genuinely missing on four of five AGENT-facing
  rental screens (`rentals-foundation-at439.md` §4.1 closes that for agents); this section states the
  equivalent floor for the three NEW audiences from first build, not retrofitted.

## 7. Agency settings (Setup Wizard, CLAUDE.md non-negotiable #10a)
- `tenant_portal_enabled` / `landlord_portal_enabled` — per-agency on/off (default ON — the whole
  point of this stage; an agency that genuinely doesn't want it can turn it off).
- `contractor_secure_link_expiry_days` — default 14 days (same reasoning `rentals-faults-work-orders.md`
  §4 already proposed-but-never-built wizard copy for — reused here, not re-invented).
- `notice_email_only` — see §8; default true (email-only per instruction).

## 8. Notices — breach / notice-to-vacate (new, confirmed absent by the audit — not even a spec existed before this)
- **Agency uploads its own notice templates** (breach notice, notice-to-vacate) — same e-sign
  importer/template-mapping mechanism as `rental-renewals.md` §4, reused here rather than building a
  second template system.
- **Agent loads the figures** (arrears amount, breach description, vacate-by date) into the mapped
  template.
- **CoreX sends and logs on the tenancy** — delivery is **email only** (per instruction — no
  WhatsApp automation anywhere in CoreX, confirmed consistent with the audit's existing finding that
  no WhatsApp automation exists for rentals notifications today). Logged as a tenancy-log entry
  (`leases.md` §12.2/§12.3) with the notice type, date sent, and a link to the sent document.
- **Arrears figures pull from Stage 8** (`rental-money.md`) once that ships — a notice drafted before
  Stage 8 lands requires the agent to type the arrears figure manually; once Stage 8 exists, the
  breach-notice draft pre-fills it from the outstanding-balance calculation (`rentals-reports.md` §8)
  — stated here as a forward dependency, not built twice.

## 9. Routes, nav, permissions, API
- **Tenant/landlord portal routes:** `/api/v1/client/rentals/*` (lease, documents, faults, work
  orders, inspections, property-activity) — the exact namespace the audit confirmed doesn't exist yet
  (audit Part 1 item 10) — built here for the first time, under the EXISTING `/api/v1/client/*`
  namespace, not a new top-level namespace.
- **Contractor secure-link routes:** `/secure/work-orders/{token}/*` (quote, photos, mark-done) — not
  under `/api/v1/client/*` since there is no `ClientUser` session backing it; token-gated instead.
- **Web screens:** tenant/landlord portal pages reuse the existing client-portal Blade layout (the
  one buyer/seller matches already render through) — no new portal shell is built.
- **Permissions:** portal access is gated by the agency settings in §7, not by `config/corex-permissions.php`
  role keys (the portal audience isn't a CoreX staff role) — the existing `ClientUser`/`Contact`
  identity link IS the access control, same as today's buyer/seller portal.
- **Notices:** new permission key `rental_notices.create` (agent-side — who may draft/send a notice),
  `rental_notice_templates.manage_settings` (template-mapping CRUD, same shape as
  `rental-renewals.md` §9).

## 10. CRUD / list-screen floor — agent-side screens this stage adds
- **Notices list** (agent-facing): routes `corex.rental-notices.{index,show}` (notices are sent, not
  edited after sending — no update/archive beyond the normal soft-delete floor for a sending error).
  Search: tenant name, property address. Sort: date sent (default, newest first). Filter: notice
  type, date range. Own/branch/agency scope, per-record guard on `show`/download. Pagination, empty
  state ("No notices sent yet").
- **Notice template CRUD:** same shape as `rental-renewals.md` §9's lease-template CRUD.

## 11. Acceptance criteria
- [ ] A tenant can log in via the existing `ClientUser` OTP flow and see only their own lease's data.
- [ ] A landlord can log in and approve/decline/"I'll handle it" on a real quote, reusing the
      existing approval mechanism.
- [ ] A contractor's secure link lets them upload a quote, upload after-photos, and mark done,
      without any login, and the link stops working after use/expiry.
- [ ] Direct-URL-by-ID access outside each audience's own scope is blocked for all three audiences.
- [ ] A notice can be drafted from an agency-uploaded template, sent by email, and appears in the
      tenancy log.
- [ ] All three portal on/off settings and the contractor link-expiry setting are in the Setup
      Wizard.
- [ ] Notices list meets the full list-screen floor.

## 12. Multi-agency
No notice template, no first-aid copy, no portal wording may assume HFC. Every template is agency-
uploaded (§8); every default (§7) is agency-neutral.

## 13. Open questions for Johan
- **Email-only vs. future SMS/WhatsApp for notices** (§8) — this spec builds email-only per
  instruction; confirm whether a future channel is anticipated so the notice-send mechanism is at
  least not actively hostile to adding one later (no architectural change requested now, just
  confirming the assumption).
