# Rentals Phase 1 — Faults and Work Orders, End to End (Fault Catalogue, Self-Service, Secure Access, Real API)

**Status:** SPEC ONLY — no code, no migration, no branch. Written per Johan's explicit instruction, via
the conductor, 2026-09-29: investigate first, write the spec, do not build. Builds ON TOP of
`.ai/specs/rental-work-orders.md` (1919 lines, BUILT through Stage 7, 2026-09-29) — that spec is not
re-argued, edited, or duplicated here. This spec exists because Johan's ruling below adds four things
that spec does not have: an agency-configurable **fault catalogue** with tenant first-aid content, a
place on the **property** to record the main water valve and DB board locations, **secure, no-app
web access for tenants/owners/contractors**, and a **real, built** API surface for Andre's mobile app
(today only sketched, per §13 of the existing spec — see the investigation below).

**Amendment, 2026-09-29 (same day, first round) — the conductor ruled on two of the four open questions
originally raised.** Permission-key shape and the seeded first-aid wording's two safety rules were
SETTLED, built into their sections directly. The other two — secure-link-vs-login and whether an
emergency bypasses owner approval — were HELD, taken to Johan directly.

**Amendment, 2026-09-29 (same day, second round) — Johan ruled on both HELD questions.** (1) ACCESS:
tenants and owners get a real CoreX login — passwordless email one-time code, reusing the existing
`ClientUser`/OTP machinery (`.ai/specs/client-auth.md`), the same account usable on web now and in
Andre's app later; contractors keep a secure link per job, no account (§4, rewritten). (2) ROUTING IS
CONFIGURED, NEVER HARDCODED [REJECTED 19 Oct 2026, see §13] — a per-property fault routing profile (agency default + per-property
override, category/urgency rules, caretaker/supplier/owner-first targets each with their own spend
limit, every automatic decision logged with the rule that fired) — entirely new §13, inserted before the
open-questions section, which is now §14. **Standing instruction: do not commit or build yet.** See §14
for the full, current status of everything originally raised.
**Date:** 2026-09-29
**Author:** conductor, on Johan's ruling (relayed in full, quoted verbatim throughout)
**Pillar:** Property (`Property`) — every fault, work order, and catalogue entry anchors to a property or
its agency; Contact (tenant, owner, contractor's own contact person) is who accesses the record and who
is notified.
**Sequencing:** Rentals Phase 1, per Johan's own framing of this task. Depends on
`rental-work-orders.md` (built) and `rental-inspections.md` (built) exactly as that spec already depends
on them — nothing here re-designs either.

---

## 0. Relationship to the existing spec — what is REUSED, unchanged, vs what is NEW here

`rental-work-orders.md` already built, completely, the following — none of it is re-specified,
re-argued, or touched by this document:

- The `rental_fault_reports` / `rental_work_orders` schema, their full lifecycle state machines, the
  optional (not merged) link between them, and the four-situation evidentiary test (§1/§3/§7 of that
  spec).
- Owner approval, its two routes (`agency_appoints` / `owner_handles`), and the append-only
  `rental_approvals` evidence log (§3.4a/§3a.1).
- The spend threshold, agency-level with a property-level override, and the quote mechanism the
  approval gate actually rides on (§3.4b/§3.4c — `properties.rental_no_approval_spend_threshold`,
  `rental_work_order_quotes`, `RentalWorkOrder::selectQuote()`).
- The supplier directory (`AgencyServiceProvider` / `AgencyServiceProviderContact` / `AgencyServiceType`,
  §2) — reused again here, unchanged, a third time.
- Internal notifications via `NotificationDispatcher`, external notifications via plain `Mail::to()`
  (§4) — the mechanism this spec's new tenant/owner/contractor notifications also use.
- Permissions, screens, and the CRUD/list-screen floor for both existing surfaces (§6/§6a/§10).

**What this spec adds, because the investigation below confirmed none of it exists today:**

1. **The fault catalogue** (§1/§2) — an agency-configurable list of fault TYPES, each carrying tenant
   first-aid content, replacing today's free-text-only fault report title/description as the tenant's
   starting point.
2. **Property fields for main water valve and DB board location** (§3), so first-aid steps can be
   property-specific ("your main water valve is at: `<location>`").
3. **Tenant self-service reporting**, web-first, no app, via a secure link (§4) — today's spec deliberately
   built agent-captured-only "for now" (`rental-work-orders.md §3.2a`) with a seam (`reported_channel`,
   `captured_by_user_id`) left exactly for this. This spec is that seam being used.
4. **Owner and contractor secure web access** (§4) — genuinely new; nothing external-party-facing exists
   for rentals faults/work orders today.
5. **Contractor accept/decline and invoice upload, and tenant-confirms-completion** (§5) — three real
   process steps Johan's ruling names that are not in the built state machine today (confirmed by
   reading `RentalWorkOrder.php` directly — five statuses only, no contractor-response state, no invoice
   field anywhere).
6. **The real, built API surface** (§7) Andre's app and the new web screens both consume — today's
   `rental-work-orders.md §13` table is a sketch, never implemented (confirmed: zero `work-order`/
   `fault-report` routes anywhere in `routes/api.php`).

---

## 1. Johan's ruling, verbatim, in full

Relayed by the conductor, 2026-09-29, as the build brief for this investigation and spec. Quoted here in
full so every later section can cite back to the exact clause it implements, matching the house style
`rental-work-orders.md` already established:

> 1. FAULT CATALOGUE is agency-configurable.
>    - CoreX seeds a DEFAULT list of common faults (burst pipe/water leak, power tripping/no power,
>      geyser, blocked drain/toilet, gate/garage motor, security/alarm, roof leak, appliance, lock/keys,
>      etc.).
>    - Each agency can add, edit, archive and restore its own list (soft delete only).
>    - Per fault: name, category, urgency/emergency flag, and TENANT FIRST-AID steps (rich text, plus
>      optional uploaded images/PDF/video link that the agency can upload).
>    - The agency can also upload its own document per fault.
> 2. The tenant picks a fault from the agency's list. They see its first-aid steps BEFORE submitting
>    ("do this first").
>    - Steps can pull property data. Example: water leak + property has a main valve location → "Your
>      main water valve is at: `<location>` — close it now".
>    - Example: power tripping → switch circuits back on one at a time to find the section that trips,
>      then unplug all appliances on that section and try again.
>    - Property needs fields for main water valve location and DB board location, with an optional photo
>      each. Check if spaces/meters already cover this.
>    - Then the tenant logs the fault with photos and a description, or marks "resolved by first aid"
>      (still logged, for evidence).
> 3. Flow: fault → agent reviews → quote(s) from supplier(s).
>    - Owner approves or declines. Auto-approved under the property's landlord spend threshold. Emergency
>      handling.
>    - → work order to the contractor → contractor accepts/declines, completion photos, invoice upload →
>      tenant confirms completion → closed.
>    - Every step lands on the property's evidence log, timestamped with who did it.
>    - A fault and a work order are linked by optional reference, not the same record (existing ruling).
> 4. Access for tenant, owner and contractor: web first, by secure link/login, with no app needed.
>    - Build it as an API that Andre's mobile app will consume. Web screens are clients of the same API.
>    - Email notifications only. No WhatsApp. In-app messaging is later.
> 5. Design standard: full CRUD, list search/sort/filter/pagination, OWN/BRANCH/AGENCY scoping via Role
>    Manager, a nav link for every new screen, no hardcoded thresholds (agency settings with defaults),
>    multi-agency always, soft delete only.
> 6. Out of scope: financials (no payments), integrations with other systems, WhatsApp.

---

## 2. The fault catalogue — data model

Per §1.1/§1.2 of the ruling. New, agency-configurable, seeded with sensible defaults, soft-deletable —
the design pattern this reuses directly is `AgencyServiceType` (`app/Models/DealV2/AgencyServiceType.php`,
cited in `rental-work-orders.md §2`) — an agency's own configurable list, seeded with defaults, never
hardcoded. Same shape, new table, because a fault type carries far more content than a trade-type code
ever needs to.

```
rental_fault_types
  id
  agency_id                    -- BelongsToAgency. Every agency gets its own editable copy of the
                                --   seeded defaults, not a shared read-only global list — "each agency
                                --   can add, edit, archive and restore its own list" (§1.1).
  name                         -- e.g. "Burst pipe / water leak"
  category                     -- e.g. "Plumbing", "Electrical", "Security", "Structural", "Appliance" —
                                --   agency-editable free text, seeded from a sensible default vocabulary
                                --   at CoreX-seed time, same "informed but not constrained" treatment
                                --   `rental_inspection_items.space_type` already gets (rental-work-
                                --   orders.md §2's own reuse discipline, applied to a new field).
  urgency                       -- enum: 'routine' | 'urgent' | 'emergency'. Johan's own words: "urgency/
                                --   emergency flag." Drives the emergency-handling path (§6.5).
  first_aid_steps               -- rich text (HTML, sanitised same as every other rich-text field in
                                --   CoreX — reuses whatever sanitiser DocuPerfect/notes already run
                                --   content through, not a new one). Can reference property-data
                                --   placeholders — see §3.2 for exactly how.
  is_default                    -- bool. True for CoreX's own seeded rows, so an agency's edit/archive of
                                --   its own copy never silently reverts on a future reference-data sync
                                --   (`deploy:sync-reference-data`, non-negotiable #12) — the sync inserts
                                --   missing defaults for a NEW agency, never overwrites a row an agency
                                --   has already edited. Same discipline `DocumentTypesCatalogueSeeder`
                                --   already needs and, per this session's Task 3, was caught NOT
                                --   following correctly for one field — this column exists specifically
                                --   so this feature doesn't repeat that bug.
  sort_order                    -- int, agency-editable, for the tenant-facing picker's display order.
  is_active                     -- bool, default true — an agency can retire a fault type from the
                                --   tenant-facing picker (e.g. seasonal) without archiving it outright.
  created_by_user_id
  created_at, updated_at, deleted_at   -- soft-delete only (non-negotiable #1/§1.5) — "archive and
                                      --   restore," never destroyed. A fault type a real
                                      --   `rental_fault_reports` row references can never disappear
                                      --   from that row's history, same `is_retired`-vs-`deleted_at`
                                      --   reasoning `rental_inspection_items §3.3` already establishes,
                                      --   though here plain soft-delete suffices since nothing about a
                                      --   fault TYPE needs to remain selectable once archived — only the
                                      --   historical reference (§2.2 below) needs to survive.

rental_fault_type_documents      -- "optional uploaded images/PDF/video link... the agency can upload"
                                 --   (§1.1) — several per fault type, mixed formats, so a join table,
                                 --   not columns on rental_fault_types itself.
  id
  agency_id
  rental_fault_type_id
  document_type                  -- enum: 'image' | 'pdf' | 'video_link' | 'document'. 'video_link' is a
                                 --   URL field, not an upload — CoreX has no video hosting/transcoding
                                 --   anywhere and building one is far outside this spec's scope; an
                                 --   agency pastes a YouTube/Vimeo/etc. link instead, rendered as an
                                 --   embed on the tenant-facing first-aid screen.
  storage_path                   -- nullable — set for image/pdf/document, null for video_link.
                                 --   Images go to the public disk directly, agency/fault-type-keyed, NOT
                                 --   PropertyImageStorer — corrected during Slice 1 build (2026-09-29):
                                 --   that service requires a property id and resizes into a property's
                                 --   own gallery path; a fault-type image is agency-level catalogue
                                 --   content with no property to key it to. PDFs/documents reuse the
                                 --   private-disk pattern
                                 --   RentalWorkOrderQuoteController::download() already established for
                                 --   quote documents (rental-work-orders.md §3.4c) — gated download,
                                 --   never a raw storage URL, since this leaves the agent-authenticated
                                 --   surface once tenant self-service (§4) can view it.
  external_url                   -- nullable — set only for document_type='video_link'.
  caption                        -- nullable string, shown alongside the document on the first-aid
                                 --   screen.
  sort_order
  uploaded_by_user_id
  created_at, updated_at, deleted_at   -- soft-delete.
```

### 2.1 CoreX's seeded defaults

Per §1.1's own list, seeded via an idempotent seeder — same pattern as
`DocumentTypesCatalogueSeeder`/`deploy:sync-reference-data` (non-negotiable #12), `is_default=true`,
inserted for every agency (existing and new) that doesn't already have a row with that name.

**Settled, 2026-09-29 — conductor's ruling on §14(4), two hard content rules for every seeded fault type,
built into the seeder itself, not left to editorial discretion alone:**

1. **Every default fault's `first_aid_steps` opens with the same fixed safety line, verbatim, before any
   fault-specific content**: *"If anyone is in danger, or there is fire, gas or live electrical exposure,
   leave the area and call emergency services first."* This is prepended by the seeder for all nine
   defaults below, not authored per-row, so it can never be accidentally dropped from one fault type
   while present on the others.
2. **Electrical first-aid steps never instruct a tenant to open the DB board beyond switching a
   breaker.** No default content anywhere describes removing a cover, touching internal wiring, or any
   action past flipping a labelled switch — enforced here as a content constraint on what gets seeded,
   not a technical gate (an agency editing its own copy could still write something unsafe; that is the
   agency's own editorial responsibility once they've customised CoreX's default, same as any other
   agency-owned content in this feature).

**The conductor reviews the full wording before it ships; an agency can edit its own copy after that**
(§1.1's own "each agency can add, edit" — unchanged, this content is a starting point, not a locked
text).

**Corrected, 2026-09-29 — Johan's QA1 review of Slice 1: the seeded defaults must actually USE the
placeholders, not just describe the action in the abstract.** Burst pipe and Power tripping rewritten
below to name `{{main_water_valve_location}}`/`{{db_board_location}}` directly, per Johan's exact
wording. A missing location renders a clean standalone fallback sentence
(`RentalFaultTypeService::renderFirstAidSteps()`, §3.2), never a raw `{{token}}` or a broken "is at: ."
clause — the whole "Your main water valve is at: {{token}}." lead-in is swapped for the fallback
sentence, with a bare-token defensive substitution as a second line of defence if an agency's own
customised wording no longer matches that lead-in shape.

| name | category | urgency | first_aid_steps (summary, safety line omitted here for brevity — see rule 1 above; full rich text reviewed by the conductor before build) |
|---|---|---|---|
| Burst pipe / water leak | Plumbing | emergency | Your main water valve is at: `{{main_water_valve_location}}`. Close it now. Turn off any electrical appliances near the water — do not touch them if already wet. |
| Power tripping / no power | Electrical | urgent | Your DB board is at: `{{db_board_location}}`. Switch all circuit breakers off, then on one at a time to find the section that trips. Unplug every appliance on that section and try again. If it still trips, leave it off and log the fault. |
| Geyser | Plumbing | urgent | Switch off the geyser's isolator switch at the DB board (switching only). Do not touch a leaking geyser element. |
| Blocked drain / toilet | Plumbing | routine | Stop using the affected drain/toilet. Do not pour chemicals down it before the agency's plumber has seen it. |
| Gate / garage motor | Security | routine | Operate the gate/garage manually if a manual release exists; do not force the motor. |
| Security / alarm | Security | emergency | If a break-in is suspected, do not enter — contact the agency and, if appropriate, the police first. |
| Roof leak | Structural | urgent | Move furniture/belongings away from the leak; place a container to catch water. |
| Appliance | Appliance | routine | Unplug the appliance at the wall if there's any smell of burning or visible damage — do not touch a damaged plug or cord. |
| Lock / keys | Security | routine | Confirm which lock/door before reporting — helps the agency send the right locksmith. |

**Not yet finalised — held for the conductor's review before build**, per the ruling above; this table is
the seeder's draft content, not signed-off copy.

### 2.2 How a fault report references its catalogue entry

`rental_fault_reports` (built, `rental-work-orders.md §3a`) gets one new nullable column,
`rental_fault_type_id` (FK `rental_fault_types`, nullable because an agent-captured report can still be
free-text-only if nothing in the catalogue fits — the catalogue guides the tenant, it does not
constrain the agent). The existing `title`/`description` fields are unchanged — `title` can be
pre-filled from the fault type's `name` when one is picked, `description` remains the free-text detail
either party adds. **No migration is needed to the fault type's own row for this to survive
archival** — `rental_fault_type_id` stays set on a historical report even after the fault type itself is
archived, same reasoning as every other "history survives archival" pattern in this feature family.

---

## 3. Property fields — main water valve and DB board location

Per §1.2's own explicit instruction to check spaces/meters first: **checked directly against the code,
confirmed by the investigation above — neither `properties.spaces_json`, the new `property_rooms`
table, nor `rental_inspection_items` (kind `space`|`meter`) carries a location description or a photo
for a specific utility control point.** All three track WHAT exists and, for the inspection items,
its CONDITION over time — none is the right shape for "here is where a specific, single, safety-critical
control point physically is." This is a genuine new pair of fields, not a reuse.

### 3.1 Schema

Two new nullable columns on `properties`, sitting with the property's other rental-tab fields
(`deposit_amount`, `admin_fee`, `rental_no_approval_spend_threshold` — same route, same screen, same
scoping, per `rental-work-orders.md §3.4b`'s own precedent):

```
properties
  rental_main_water_valve_location   -- nullable string(255). Free text — "outside, left of the front
                                     --   door, in the meter box" — not a structured location picker.
                                     --   A structured picker (tied to property_rooms, say) is a real,
                                     --   separate design question this spec does not take on: Johan's
                                     --   own example ("Your main water valve is at: <location>") reads
                                     --   as a plain sentence, not a room reference.
  rental_main_water_valve_photo_path  -- nullable string. PropertyImageStorer, same pipeline as every
                                     --   other property photo.
  rental_db_board_location            -- nullable string(255). Same free-text shape as the valve field.
  rental_db_board_photo_path          -- nullable string. Same photo pipeline.
```

### 3.2 How first-aid steps "pull property data"

Per §1.2's own example: `first_aid_steps` (§2) is rich text that MAY contain a small, fixed set of
placeholder tokens — `{{main_water_valve_location}}`, `{{db_board_location}}` — resolved at render time
by `RentalFaultTypeService::renderFirstAidSteps(RentalFaultType $type, Property $property)` against the
specific property the tenant is reporting on. **Not a general template-merge engine** — a hardcoded,
small allow-list of tokens this spec names, resolved by simple string replacement, matching the
"prevent-or-absorb, never break" discipline (`BUILD_STANDARD.md §3`): a property with no valve location
recorded renders the token as an honest "not recorded — contact the agency" fallback, never a blank or a
literal unresolved `{{...}}` string leaking to a tenant's screen.

**Settled**: the photo (§3.1), when the property has one recorded, renders inline directly beside its
location text on the first-aid screen — not behind a "see photo" link. Johan's own reason for asking for
"an optional photo each" (§1.2) is to help a tenant standing in an unfamiliar spot actually FIND the
valve/board; a link a panicked tenant has to notice and tap defeats that purpose. When no photo is
recorded, only the location text (or the "not recorded" fallback above) renders — no broken-image
placeholder.

---

## 4. Access — real CoreX login for tenant/owner (passwordless OTP), secure link for contractors

**Settled, 2026-09-29 — Johan's ruling on §14(1).** Superseding this section's original "secure-link
only" proposal in full. Quoted verbatim: *"tenants and owners get a real CoreX login. Passwordless email
one-time code (reuse the existing DR2/e-sign OTP pattern). The same account is used on the web now and
in Andre's app later. Contractors get a secure link per job, with no account. Remove the 'secure-link
only' model for tenants and owners. Keep secure links for contractors."*

**This ruling lands on TWO different existing mechanisms, not one — investigated directly before
writing this section, because picking the wrong one would mean building a parallel, competing auth
system:**

- **`ClientUser` / `client_otps` / `client_access_logs`** (`.ai/specs/client-auth.md`, BUILT 2026-05-09,
  `app/Models/ClientUser.php`, `app/Services/ClientAuthService.php`,
  `app/Http/Controllers/Api/V1/ClientAuthController.php`) — **this already IS "a real CoreX login" for a
  Contact**, and it already IS the "same account on web now, app later" shape: one `ClientUser` row per
  real person (globally unique email), linked to `contacts.client_user_id`, spanning multiple agencies
  and multiple linked Contact rows, Sanctum tokens with a `client` ability. A tenant or owner logging in
  today is exactly this system's purpose (`client-auth.md`'s own "Purpose": *"buyer, seller, tenant,
  landlord, prospect"* — tenant and landlord/owner are already named, on day one).
- **The canonical `OtpService`** (`app/Services/Otp/OtpService.php`) — *"the ONE one-time-code engine for
  CoreX... destination-agnostic and consumer-agnostic"* — already the engine BEHIND `client-auth.md`'s
  own OTP step, and separately consumed by `RentalApplicationSigningController` and
  `DealV2/SecureDocumentController` (the DR2/e-sign pattern Johan named directly) for a different
  purpose: a **stateless, per-token, passwordless verify-and-view session with no persistent account at
  all** — exactly the shape this spec's contractor access still needs (§4.3).

**The one real gap, checked directly against `client-auth.md`'s own API table (§107 of that spec):**
today, `ClientUser` OTP is used ONLY to activate an account or reset a forgotten password — the code is
verified once, then a PASSWORD must be set, and every login after that is email+password
(`client-auth.login`, §107). There is no "log in with a fresh OTP every time, never set a password" mode
on that system today. **This is the one piece this spec actually adds**, and it belongs to
`client-auth.md`'s own territory, not a competing implementation:

### 4.1 What this spec proposes, and the cross-spec coordination point it raises

**Reuse `ClientUser` as the account, unchanged** — a tenant or owner is a `Contact`; if that contact's
`contacts.client_user_id` is unset, one is created the first time access is needed (same
`findContactsByIdentifierAcrossAgencies`/account-creation path `client-auth.md` already has for an
agent-created login, §262 of that spec — "fake-email generation" is a DIFFERENT, unrelated case and is
not reused here; a tenant/owner already has a real email on their Contact record).

**Add ONE new passwordless-login flow to the existing `ClientAuthController`/`ClientAuthService`**, using
the same `OtpService` engine already wired into that controller (via `client-auth.otp.send`/
`client-auth.otp.verify`), but returning a full Sanctum session directly instead of a 15-minute
activation token for password-set:

- `POST /api/v1/client-auth/otp/login` — **new.** `{email, code, device_name}` → verifies via
  `OtpService` exactly like `client-auth.otp.verify` already does, but on success issues a real,
  long-lived Sanctum token (same 30-day sliding expiry as `client-auth.login`'s own tokens, §102 of that
  spec) directly — no password ever set, no password ever required, no password field ever shown. A
  `ClientUser` that has never set a password stays exactly that way permanently if it only ever logs in
  this way; the existing password path is untouched and still available for anyone who wants it (out of
  this spec's call to remove).
- No other change to `client_users`/`client_otps`/`client_access_logs` — same tables, same columns,
  same audit sink, same rate limits (§107's own 1/min-5/hour on `otp/send`).

**[Flagged, not decided here — a genuine cross-spec coordination point, not a technical detail]**: this
adds a new endpoint and a new login MODE to a system `client-auth.md` — a spec this document does not
own — already fully specs and BUILT. Per non-negotiable #3/#4 (spec-exact, stay in your lane), the
actual implementation of `POST /api/v1/client-auth/otp/login` belongs to whichever lane owns
`client-auth.md` (built on the `andre` branch originally), coordinated at build time, not silently
folded into this spec's own build. This section specifies WHAT is needed and WHY it's the right reuse,
not a claim that this spec's own build touches `ClientAuthController` unilaterally.

### 4.2 Contractors — secure link, no account, unchanged from the original proposal

Per Johan's own explicit carve-out: *"Contractors get a secure link per job, with no account. Keep
secure links for contractors."* The original design stands, narrowed to contractors only:

- **`rental_secure_access_tokens`** — new table, one row per issued link:
  ```
  id, agency_id, token (unique, random, unguessable — same generation as the existing /sign/{token}),
  tokenable_type, tokenable_id    -- polymorphic: RentalFaultReport | RentalWorkOrder
  agency_service_provider_id       -- required FK agency_service_providers. No recipient_type/
                                   --   recipient_contact_id columns any more — contractor-only now,
                                   --   so there is exactly one recipient shape, not a polymorphic choice.
  expires_at                     -- default 14 days, matching the existing rental-application precedent.
  used_at                        -- nullable — SINGLE-USE for the accept/decline decision (§5.1);
                                   --   consuming it marks used_at, but the link still resolves to a
                                   --   read-only "you already responded" view afterward, not a dead link,
                                   --   so a contractor can still reach completion-photo/invoice upload
                                   --   (§5.2) via the SAME link once accepted — that part is multi-use
                                   --   until the work order reaches a terminal status.
  created_at
  ```
- A fresh link is issued the moment a work order is assigned to a contractor (`ordered_at` stamped,
  `rental-work-orders.md §3.4`), mailed to whichever `AgencyServiceProviderContact` email the existing
  supplier-notification path already resolves (`rental-work-orders.md §4`) — no new recipient-resolution
  logic, same lookup.
- The same underlying mechanism `RentalApplicationSigningController`/`DealSecureLinkMail` already
  establish — cited, reused, not re-argued.

### 4.3 What each party can see and do

| Party | Auth | Sees | Can do |
|---|---|---|---|
| **Tenant** | `ClientUser` session (§4.1), Sanctum `client` ability | The fault-type catalogue (§2) with first-aid content (§3.2); their own fault reports and linked work orders on the property; status/timeline | Pick a fault type, view first-aid steps, log a fault (photos + description) or mark "resolved by first aid" (§4.4); confirm work-order completion (§5.3) |
| **Owner** | `ClientUser` session (§4.1), Sanctum `client` ability | Fault reports and work orders on properties they own; quotes and routing decisions awaiting their approval | Approve/decline a quote (records a `rental_approvals` row, §3.4a of the existing spec — the client-authenticated submission IS the "in writing" evidence itself, superseding the agent-uploaded-screenshot fallback for owners who use this path) |
| **Contractor** | Tokened link (§4.2), no account | The specific work order named by their token | Accept/decline the job (§5.1); upload completion photos and an invoice (§5.2) |

**Read access is always scoped to the requester** — a tenant/owner's `ClientUser` session is scoped by
`contacts.client_user_id` → the specific properties/leases that contact is actually linked to (same
scoping `client.matches`/`client.properties.show` already enforce, §107 of `client-auth.md`, reused
verbatim, not re-derived); a contractor's link is scoped by the token's own
`tokenable_type`/`tokenable_id`. Never by trusting a client-supplied ID, matching the non-negotiable
#7/§1c OWN-scoping discipline applied to an external party instead of an internal role.

**Settled — first aid is shown before submission is even possible, not just first in reading order.**
Per §1.2: *"They see its first-aid steps BEFORE submitting."* The tenant flow (§8.3) is a hard two-step
sequence, not merely an order the screens happen to render in: step 1 is fault-type selection →
first-aid display (§3.2); the log-fault form and the "resolved by first aid" button (§4.4) are not
reachable, rendered, or submittable until step 1 has actually displayed a first-aid screen for the
picked fault type — a tenant cannot skip straight to logging without the app/browser having shown them
the content first. Enforced client-side (the form literally doesn't exist in the DOM until step 1
completes) AND server-side (`POST /api/v1/client/rentals/properties/{property}/fault-reports`, §7.2,
requires `rental_fault_type_id` when one exists in the catalogue for the category picked, so a client
that tried to bypass the UI still can't submit without having fetched the first-aid content first).

### 4.4 "Resolved by first aid" — still logged, for evidence

Per §1.2's closing instruction. A tenant who follows the first-aid steps and doesn't need further action
still submits — their `ClientUser` session's own path (§4.1), not a silent close. This writes a
`rental_fault_reports` row exactly as a normal report does, with a new outcome value:

`RentalFaultReport::OUTCOME_RESOLVED_BY_FIRST_AID` — new, added to the existing five-value `outcome`
enum (`rental-work-orders.md §3a.2`). The fault report reaches `status='resolved'` immediately, with
`repaired_at` set to the moment of submission (the tenant IS reporting that it's already fixed) and
`outcome_note` optional (unlike the other non-`repaired` outcomes, which require one — the tenant
picking "the first-aid steps worked" is self-explanatory in a way `not_repaired`/`tenant_liable` are
not). This is evidence exactly the same weight as any other resolved fault report — visible to the
out-inspection's attached view (`rental-work-orders.md §3a.5`) alongside every other outcome.

---

## 5. The three missing process steps — contractor accept/decline, invoice, tenant confirms

Per §1.3 of the ruling. Confirmed by direct investigation of `RentalWorkOrder.php`: today's five statuses
(`reported`/`ordered`/`in_progress`/`completed`/`cancelled`) have no contractor-response state and no
invoice field anywhere. All three are new.

### 5.1 Contractor accept/decline

New nullable columns on `rental_work_orders`:

```
contractor_response              -- nullable enum: 'pending' | 'accepted' | 'declined'. Set to
                                 --   'pending' the moment a work order moves to 'ordered'
                                 --   (agency_service_provider_id assigned) — before this, the column is
                                 --   null (not yet asked). A declined response does NOT auto-cancel the
                                 --   work order — the agent chooses the next step (re-assign a different
                                 --   supplier, or cancel) — matching this feature family's own "an agent
                                 --   decides, nothing auto-acts on a fact alone" discipline already
                                 --   established for the approval gate.
contractor_responded_at          -- nullable timestamp
contractor_decline_reason         -- nullable text, required when contractor_response='declined'.
```

`RentalWorkOrder::recordContractorResponse(string $response, ?string $reason, ?User $by)` — sibling
method to `assignSupplier()`/`complete()`, same file, same pattern. `$by` is nullable specifically
because this is called from BOTH the agent-authenticated web path (an agent phones the contractor and
records the answer, exactly like approval evidence today) AND the contractor's own secure-link
submission (§4.2) — same seam discipline `RentalFaultReportService::report()` already uses for
`captured_by_user_id` (`rental-work-orders.md §3.2a`). Writes a `rental_work_order_updates` row
(`update_type='contractor_response'`, new value alongside the existing five) either way — the
"comprehensive log" Johan explicitly asked for does not distinguish who submitted it, only what
happened and when.

### 5.2 Invoice upload

New table, sibling to `rental_work_order_quotes` (§3.4c of the existing spec) — reuses that table's own
structure and reasoning rather than inventing a new shape:

```
rental_work_order_invoices
  id, agency_id, rental_work_order_id   -- required FK
  agency_service_provider_id             -- required FK — who invoiced.
  amount                                -- required decimal(10,2).
  invoice_date                          -- required date.
  document_storage_path                  -- required string (unlike a quote, an invoice without a
                                         --   document is not useful evidence — Johan's ruling names
                                         --   "invoice upload," not "invoice detail," singular and
                                         --   specific, unlike the quote's deliberate either/or). Private
                                         --   disk, same gated-download pattern as
                                         --   RentalWorkOrderQuoteController::download().
  uploaded_by_user_id                   -- nullable — same nullable-for-the-external-submitter reasoning
                                         --   as §5.1's contractor_responded fields; set when an agent
                                         --   uploads on the contractor's behalf, null when the
                                         --   contractor uploads it themselves via their secure link.
  created_at, updated_at, deleted_at      -- soft-delete only.
```

**Built 8 Oct 2026 (cc6, QA1) as the office-side half — see `rental-work-orders.md` §17.31:** the agent files the supplier's invoice (number, date, amount, file; limits are agency settings) on the work order, the owner sees it only when the agent ticks "share with owner", the tenant never does. The contractor's own secure-link upload (§7.3) is still not built. Table name and columns there are the built ones (they differ from the sketch above: `invoice_number`, `share_with_owner`, a nullable supplier).

**Deliberately NOT a financial feature** — same evidence-trail-only treatment `cost_amount`/`paid_by`
already get (`rental-work-orders.md §5.1`, unchanged by this spec, explicitly named again in §6 below).
The invoice amount is NOT automatically written to `rental_work_orders.cost_amount` — an agent
reconciles the two manually if they differ (a contractor's final invoice can legitimately differ from
their quote), matching this whole feature family's "record the fact, don't compute or trigger a
payment" discipline.

### 5.3 Tenant confirms completion

New nullable columns on `rental_work_orders`:

```
tenant_confirmed_completion_at    -- nullable timestamp. Set the moment a tenant, via their ClientUser
                                  --   session (§4.1), confirms the work is done to their satisfaction.
tenant_confirmation_note           -- nullable text — an optional comment the tenant can leave (e.g.
                                  --   "fixed, thanks" or "still leaking a little").
```

**Deliberately NOT a gate on `status='completed'`** — an agent/contractor can mark a work order
`completed` (§3.4 of the existing spec, unchanged) without waiting on the tenant, because a tenant who
never responds must not permanently block the record from reaching a closed state — matching this
feature family's "never let an external party's silence become an internal deadlock" principle already
implicit in how owner approval works (an agent still has a `verbal_note` fallback rather than being
blocked forever, `rental-work-orders.md §3.4a`). Tenant confirmation is **additional, valuable evidence
layered onto an already-completed work order**, not a required step to reach one. **Updated, 2026-09-29
(§4.1)**: the tenant reaches this through their `ClientUser` session, not an expiring link — there is no
"window" to reason about the way a contractor's tokened link has one; a tenant can always come back and
confirm (or leave a note) at any later time, the same way any other `ClientUser` feature stays reachable
for as long as the account exists.

---

## 6. Flow, emergency handling, and the property evidence log

Per §1.3's full flow description, walked through against what's built vs new:

1. **Fault** — tenant self-service (§4) or agent-captured (existing) → `rental_fault_reports` row, now
   optionally carrying `rental_fault_type_id` (§2.2).
2. **~~Routing resolves immediately~~ [REJECTED 19 Oct 2026 - no automatic routing; the agent picks]** — was: new, §13. `RentalFaultRoutingService::resolve()` runs the moment
   the fault report is created, BEFORE step 3 — not after step 4 as an earlier draft of this section had
   it. Its outcome decides whether step 3 is the normal agent-review path at all: `route='agent_review'`
   (the default, today's unchanged behaviour) proceeds to step 3 exactly as built; `route='caretaker'` /
   `'supplier'` / `'owner_first'` sends the fault down §13's own routed path instead, which still ends at
   a work order (or an owner decision) but skips the agent manually reviewing and obtaining quotes first
   — the routed target (or the owner, for `owner_first`) is contacted directly, by email (§13.6), and a
   work order is raised against them once accepted/approved, re-joining the normal flow at step 6.
3. **Agent reviews** — existing (`RentalFaultReportController`), unchanged, and still the default path
   (step 2's `route='agent_review'` outcome).
4. **Quote(s) from supplier(s)** — existing, `rental_work_order_quotes` (§3.4c of the existing spec),
   unchanged. Sits on `rental_work_orders`, not `rental_fault_reports` — a quote is obtained once a work
   order exists.
5. **Owner approves or declines, auto-approved under threshold** — existing, unchanged
   (`RentalWorkOrder::selectQuote()`, §3.4c) for the `agent_review` path; for a routed path, the
   equivalent gate is the route's own spend limit (§13.3) instead of the property's flat threshold. The
   owner's decision, either way, can now ALSO arrive via their own `ClientUser` session (§4.1/§4.3),
   which is new — the decision mechanics (`recordApproval`) are unchanged; only a second,
   client-authenticated entry point into the same method is added.
6. **Work order to the contractor** — existing (`assignSupplier()`), unchanged; for a routed
   `'caretaker'`/`'supplier'` fault, this is where the routed target actually lands, same table, same
   method.
7. **Contractor accepts/declines** — new, §5.1.
8. **Completion photos** — existing (`rental_work_order_photos`, `photo_type='completed'`), unchanged.
9. **Invoice upload** — new, §5.2.
10. **Tenant confirms completion** — new, §5.3.
11. **Closed** — existing `status='completed'`, unchanged; tenant confirmation is evidence layered on
    top, not a new terminal state (§5.3).

**Every step lands on the property's evidence log, timestamped with who did it** — already true today
(`rental_work_order_updates`/`rental_fault_report_updates`/`rental_approvals`, all append-only, all
carrying `created_by_user_id` or the nullable external-submitter equivalent, §5.1/§5.2/§5.3 above). This
spec's new steps (contractor response, invoice, tenant confirmation) write into the SAME
`rental_work_order_updates` table with new `update_type` values — no second, competing evidence log is
created.

### 6.5 Emergency handling

Per §1.1's "urgency/emergency flag" and §1.3's "emergency handling." **[The routing ruling of 2026-09-29 (§13) was REJECTED on 19 Oct 2026: an emergency is flagged and sorted first (§29), never auto-routed to a caretaker or supplier.]** As of the 2026-09-29 routing
ruling (§13), emergency handling is no longer a bare notification-priority treatment — it is the primary
thing the fault routing profile (§13) exists to configure**: WHO an emergency-flagged fault routes to
(caretaker, nominated supplier, or owner-first) and what spend limit applies to that route, entirely
agency/property-configurable, never hardcoded. §13 is now the authoritative section for what "emergency
handling" means structurally; this section covers only what's unchanged from the original proposal:

- A fault report whose `rental_fault_type_id` resolves to `urgency='emergency'` (§2) fires its
  `rental_fault_report.created` internal notification (`rental-work-orders.md §4`) with elevated
  priority — reusing whatever priority/urgency signal `NotificationDispatcher` already supports for
  other event keys, not inventing a new channel. This fires REGARDLESS of which route §14 sends the
  fault down — the assigned agent is always told, even when a caretaker or supplier was also notified
  automatically.
- On the agent-facing list screens (§8), an emergency-flagged fault/work order sorts and visually flags
  above routine ones — same treatment the existing `priority` column already gets
  (`rental-work-orders.md §3.1`, itself a `[cc4 design call]` — this spec's emergency flag and that
  existing priority field should very likely be reconciled into one concept at build time rather than
  carrying two overlapping "how urgent is this" fields; flagged here, not decided).
- **Whether an emergency bypasses owner approval is now answered by §14, not left open**: it depends on
  which route the property's (or agency default's) routing profile names for emergencies and that
  route's own configured spend limit — see §14.2. There is no long a single yes/no answer to this
  question; it is per-property configuration, exactly as Johan's routing ruling requires.

---

## 7. The API — real routes, built this time, not sketched

Per §1.4: "Build it as an API that Andre's mobile app will consume. Web screens are clients of the same
API." This is the one place this spec diverges most sharply from what `rental-work-orders.md §13`
already wrote — that section explicitly said "not built this pass — spec only." This spec's job is to
name what actually gets built, split correctly between the two very different auth contexts this feature
now has.

### 7.1 Agent/internal surface — Sanctum bearer, existing convention

Exactly the table `rental-work-orders.md §13` already sketched (`/api/v1/mobile/...`, Sanctum bearer,
same `{"message": ...}` error convention), now to be actually implemented, PLUS the new catalogue
endpoints:

| Method | Route | Calls |
|---|---|---|
| `GET` | `/api/v1/mobile/rental-fault-types` | The agency's own fault catalogue (§2), for an agent's own reporting screen to offer the same picker a tenant sees. |
| `POST` | `/api/v1/mobile/properties/{property}/fault-reports` | `RentalFaultReportService::report()` — as already sketched, now built. |
| `POST` | `/api/v1/mobile/fault-reports/{faultReport}/photos` | `PropertyImageStorer`, as already sketched. |
| `POST` | `/api/v1/mobile/fault-reports/{faultReport}/approval` | `RentalFaultReportService::recordApproval()`, as already sketched. |
| `POST` | `/api/v1/mobile/fault-reports/{faultReport}/outcome` | `RentalFaultReportService::setOutcome()`, as already sketched. |
| `POST` | `/api/v1/mobile/properties/{property}/work-orders` | `RentalWorkOrderService::report()`, as already sketched. |
| `POST` | `/api/v1/mobile/work-orders/{workOrder}/photos` | `PropertyImageStorer`, as already sketched. |
| `POST` | `/api/v1/mobile/work-orders/{workOrder}/assign-supplier` | `RentalWorkOrderService::assignSupplier()`, as already sketched. |
| `POST` | `/api/v1/mobile/work-orders/{workOrder}/contractor-response` | `RentalWorkOrder::recordContractorResponse()` (§5.1) — new. |
| `POST` | `/api/v1/mobile/work-orders/{workOrder}/invoices` | New — records an invoice (§5.2), agent-uploaded-on-contractor's-behalf path. |
| `POST` | `/api/v1/mobile/work-orders/{workOrder}/complete` | `RentalWorkOrderService::complete()`, as already sketched. |

### 7.2 Tenant/owner surface — `ClientUser` Sanctum session, `client` ability

**Updated, 2026-09-29 (§4.1)** — no longer token-authed. A tenant/owner presents their `ClientUser`
Sanctum token (issued by `client-auth.otp.login`, §4.1, or the existing password login) exactly as
`client-auth.md §107`'s own `/api/v1/client/*` routes already do. This spec's new endpoints sit in that
SAME namespace, as a natural extension of it, not a competing one:

| Method | Route | Calls |
|---|---|---|
| `GET` | `/api/v1/client/rentals/fault-types` | The current agency's fault catalogue (§2), first-aid steps rendered against whichever property the request names (§3.2) — resolvable because `client.matches`/`client.properties.show` already establish which properties a given `ClientUser` may see (§107 of `client-auth.md`), reused here rather than re-derived. |
| `POST` | `/api/v1/client/rentals/properties/{property}/fault-reports` | `RentalFaultReportService::report()` — SAME service method the internal surface calls (§7.1), `captured_by_user_id` omitted, `reported_by_contact_id` resolved from the authenticated `ClientUser`'s linked Contact, `reported_channel='web_link'` (new value, alongside the `'app'` placeholder `rental-work-orders.md §3.2a` already reserved) — the exact seam that spec built for. |
| `POST` | `/api/v1/client/rentals/fault-reports/{faultReport}/photos` | `PropertyImageStorer`, same pipeline. |
| `POST` | `/api/v1/client/rentals/fault-reports/{faultReport}/resolved-by-first-aid` | New — §4.4's outcome. |
| `GET` | `/api/v1/client/rentals/fault-reports` / `/work-orders` | The authenticated contact's own faults/work orders on properties they're linked to — status/timeline (§4.3's "Sees" column). |
| `POST` | `/api/v1/client/rentals/quotes/{quote}/decision` | Owner path — records approval via the SAME `recordApproval`-family method the internal surface uses. Scoped: refuses unless the authenticated contact resolves as an owner-side contact on that quote's work order's property (§4.3's read-scoping). |
| `POST` | `/api/v1/client/rentals/work-orders/{workOrder}/confirm-completion` | Tenant path — §5.3, new. |

### 7.3 Contractor surface — tokened link, no account, new middleware

**Unchanged in mechanism from the original proposal, narrowed to contractors only.** A contractor has no
Sanctum credential of any kind — the token itself (§4.2) IS the credential, resolved by a new
route-model-binding-style middleware (`ResolveRentalSecureAccessToken`, modelled directly on however
`RentalApplicationSigningController` resolves its own `/sign/{token}` today):

| Method | Route | Calls |
|---|---|---|
| `GET` | `/rentals/access/{token}` | Resolves the token, renders the contractor's job-detail screen — the ONE entry point every emailed contractor link points at. |
| `POST` | `/api/v1/rentals/access/{token}/contractor-response` | `RentalWorkOrder::recordContractorResponse()` (§5.1) — SAME method §7.1's internal route calls. |
| `POST` | `/api/v1/rentals/access/{token}/invoices` | Records an invoice (§5.2) — SAME method §7.1's internal route calls. |
| `POST` | `/api/v1/rentals/access/{token}/photos` | Contractor completion photos — `PropertyImageStorer`, same pipeline. |

**The discipline both tables are built on, restated**: every external route (§7.2 AND §7.3) calls the
identical service method the internal, agent-authenticated route (§7.1) calls — never a second
implementation of the same business rule. This is the same "one method, two entry points, differ only in
who's nullable" pattern `rental-work-orders.md §3.2a` already established for `captured_by_user_id`, now
applied consistently across every new external action this spec adds, across BOTH new auth contexts.
**Web screens ARE clients of this same API** (§1.4's own instruction) — the tenant/owner web screens
call §7.2 exactly as Andre's future app will (`ClientUser` Sanctum session, one account, both surfaces),
and the contractor web screen calls §7.3 exactly as a contractor's own future app access would if ever
built — not a separate server-rendered form-post path on either side, so the API is genuinely the single
surface every client consumes, not parallel implementations wearing one name.

**Rate limiting**: `/api/v1/client/rentals/*` inherits whatever throttling `client-auth.md`'s own
`/api/v1/client/*` group already applies (not re-specified here — that group's own territory);
`/rentals/access/{token}/*` gets its own `throttle` middleware, matching the existing
`v1/fault-report`/`v1/login` precedent (`routes/api.php:100-102`, `throttle:30,1`) — a guessable-token
brute-force surface is exactly what this class of route must not become.

---

## 8. Screens — CRUD/list-screen floor (per §1.5 of the ruling, `BUILD_STANDARD.md §1a-§1d`)

### 8.1 Fault catalogue — new agency-wide admin screen

Route group `corex.rental-fault-types.*`, sidebar entry under Rentals alongside the existing three
(Leases, Rental Inspections, Rental Work Orders, Rental Fault Reports):

- **Search**: name, category.
- **Sort**: sort_order (default — matches the tenant-facing picker's own order), name, category,
  urgency.
- **Filter**: category, urgency, active/archived (minimum per §1b).
- **Pagination**: standard.
- **Empty state**: distinct "no custom fault types yet — CoreX's defaults are shown below" vs "none
  matching this filter."
- **Full CRUD**: create, edit, archive, restore — per §1.5's own explicit instruction, soft-delete only.
  A `rental_fault_type_documents` manager (upload/reorder/remove-by-archiving) sits on the fault type's
  own edit screen, same "parent record owns its children" shape `rental_work_order_quotes` already
  established.
- **Scoping**: `rental_fault_types`/`rental_fault_type_documents` both `use BelongsToAgency` +
  `AgencyScope` — an agency only ever sees and edits its own catalogue (including its own copies of the
  seeded defaults), never another agency's.

### 8.2 The property tab — new fields, alongside existing rental-tab money fields

`resources/views/corex/properties/show.blade.php`'s rental tab (§3.1 of this spec) gets two new
location+photo pairs, same panel, same route (`PUT /{property}/rental-details`), same permission
scoping as `deposit_amount`/`admin_fee`/`rental_no_approval_spend_threshold` already use.

### 8.3 Tenant/owner/contractor-facing screens — new, external-facing

**Updated, 2026-09-29 (§4)** — two different shells now, matching the two different auth contexts:

- **Tenant/owner** — a lightweight **Client Portal** shell (new, but sized to match whatever minimal
  shell `client-auth.md`'s own web-facing screens use today, if any exist yet — checked at build time,
  not assumed here since that spec's own scope is mobile-app-first) behind the `ClientUser` Sanctum
  session (§4.1/§7.2): fault-type picker with first-aid content → log form (photos + description) or
  "resolved by first aid" → their own fault/work-order status timeline; owner additionally sees
  quote(s) awaiting decision, with approve/decline.
- **Contractor** — `/rentals/access/{token}` (§7.3), token-only, no login shell at all: the assigned
  job's detail, accept/decline, completion photos + invoice upload once accepted.

Neither gets a CoreX internal sidebar entry — these are not internal CoreX screens (non-negotiable #2
governs pages an internal user navigates to). The Client Portal screens are reached by logging in
(§4.1); the contractor screen is reached only via a mailed link, same as `RentalApplicationSigningController`
today has no sidebar entry either.

### 8.4 Existing screens, extended

- **Fault report create/show** (existing) — gains the fault-type picker (§2.2) as an optional field
  alongside the existing free-text title/description; a fault report created from a catalogue pick shows
  the fault type's name and category as a badge.
- **Work order show** (existing) — gains the Contractor Response (§5.1), Invoices (§5.2), and Tenant
  Confirmation (§5.3) sections, same `@permission`-gated pattern the existing Quotes section
  (`rental-work-orders.md §3.4c`) already established. No new sidebar entry — same reasoning as quotes,
  reachable from the work order's own show screen.

---

## 9. Permissions

New keys, same naming convention as `rental-work-orders.md §10`.

**Settled, 2026-09-29 — conductor's ruling on §14(3):** follow the existing pattern exactly, don't
invent a new split. **Checked directly against `config/corex-permissions.php`**: neither sibling this
spec reuses splits archive/restore out from create/edit — `rental_work_orders.create`/
`rental_fault_reports.create` each already gate their own module's archive/restore/destroy routes
(confirmed at `routes/web.php` — `.../restore` and `DELETE .../{model}` both ride on
`.create`'s middleware, not a separate key), and the supplier directory this whole feature family reuses
(§2) has exactly ONE key for its entire CRUD surface, `deals_v2.manage_suppliers`
(`config/corex-permissions.php:618`). Built to that precedent, not a new one:

- `rental_fault_types.view`
- `rental_fault_types.create` (covers create, edit, archive, restore — same single-key shape as
  `rental_work_orders.create`/`rental_fault_reports.create`, no `.archive`/`.restore` split)
- `rental_work_orders.record_contractor_response` — same weight-of-decision reasoning
  `rental_work_orders.record_approval` already established (§5.1 records a real external decision).
- `rental_work_orders.manage_invoices` — same reasoning as `.manage_quotes` (§3.4c of the existing
  spec) — a second money-adjacent evidence type, same permission shape.
- ~~`rental_fault_routing.manage`~~ **(withdrawn 19 Oct 2026 - §13 rejected, no such permission is built)** — **new, §13** — managing the agency-default and per-property routing
  profiles/rules (§13.2). One key, same single-key shape as the rest of this section, since a routing
  profile is edited as a whole (profile + its rules), not through separately-permissioned sub-actions.
- **No new permission for the contractor token surface** (§7.3, §4.2) — by design, that surface has no
  CoreX user/role at all; access is gated entirely by token possession, not by the Role Manager. This is
  a deliberate, structural difference from every other permission in CoreX and is named here so it isn't
  mistaken for an oversight.
- **The tenant/owner `ClientUser` surface (§7.2) is likewise NOT gated by CoreX's Role Manager** — a
  `ClientUser` is not a CoreX `User` and carries no permission keys at all; its own scoping is
  contact-linkage (§4.3), not a Role Manager grant. Named here for the same reason — a structural
  difference, not an oversight.

---

## 10. Settings — every threshold configurable, Setup Wizard entries (non-negotiable #10a)

The existing spend threshold (`rental-work-orders.md §3.4b`) is unchanged and already has its Setup
Wizard entry (`rental-work-orders.md §11`, Stage 6: `config/agency-onboarding-copy.php`). What this spec
DOES add to agency settings:

- **`rental_work_order_settings.contractor_link_expiry_days`** — nullable int, default 14 (matching the
  existing rental-application precedent, §4.2). **Updated, 2026-09-29 (§4)**: this governs the
  CONTRACTOR link only now — a tenant/owner has a persistent `ClientUser` account (§4.1) with no link to
  expire. Agency-configurable, never hardcoded, per §1.5. **New Setup Wizard entry required in the same
  prompt that builds this** (non-negotiable #10a) — explain: "how long a link mailed to a contractor
  stays valid before they'd need the agency to resend it"; affects: "links older than this many days
  will no longer work for that contractor."
- **The fault catalogue itself (§2) is NOT a single on/off setting** — it is a full CRUD screen (§8.1),
  not a wizard toggle, matching how `AgencyServiceType`'s own catalogue is managed (its own screen, not
  a wizard field) rather than treated as a setup-wizard checkbox.
- ~~**The agency-default routing profile (§13) gets its own Setup Wizard entry**~~ **(withdrawn 19 Oct 2026 - §13 rejected)** — non-negotiable #10a
  applies to it directly: explain: "who an emergency fault (burst pipe, no power) gets sent to before an
  agent even looks at it — your caretaker, a nominated supplier, or the property owner directly, and up
  to what amount"; affects: "changes who's contacted first when something urgent is reported, and
  whether it needs your sign-off first." The per-property override (§13.7) stays on the property tab,
  not the wizard, same treatment the spend-threshold override already gets (§8's own note).

---

## 11. Multi-agency, always (non-negotiable #9)

Every piece of this spec is per-agency by construction: the fault catalogue is agency-owned
(`rental_fault_types.agency_id`), the property fields are per-property (already agency-scoped via
`Property`'s own `BelongsToAgency`), the contractor secure-link tokens are agency-scoped
(`rental_secure_access_tokens.agency_id`), the routing profiles/rules are agency-owned with a
per-property override (`rental_fault_routing_profiles.agency_id`, §13), and the notification/email copy
this spec's new steps trigger must read correctly for the Cape Town rentals agency starting October 2026
exactly as `rental-work-orders.md §13`'s own closing note already commits to — no agency ID, no
HFC-specific wording, anywhere in this spec's service layer, seeded catalogue content included (the
seeded defaults in §2.1 are generic "a main water valve," never "HFC's usual supplier," etc.). A
`ClientUser` (§4.1) is deliberately NOT agency-scoped — it already spans agencies by design
(`client-auth.md`'s own multi-agency model, unchanged, reused here) — but every rentals action that
account takes is scoped to the specific agency/property/lease the linked Contact actually belongs to
(§4.3), never assumed from a single "home" agency.

---

## 12. Out of scope (this spec)

- **Financials — no payments** (§1.6). The invoice (§5.2) is evidence, exactly like the existing quote
  and `cost_amount` — never computed, reconciled, or paid through CoreX.
- **Integrations with other systems** (§1.6) — nothing in this spec calls, or is called by, any
  system outside CoreX.
- **WhatsApp, anywhere** (§1.6/§1.4) — unchanged from `rental-work-orders.md §4`'s own finding: no
  automated WhatsApp send capability exists or is built here. Every new notification this spec adds
  (tenant self-service confirmation, owner/contractor secure-link emails) is email, exactly per §1.4.
- **In-app messaging** (§1.4) — "is later," explicitly not this spec.
- **A persistent contractor login** (§4.2) — settled OUT by Johan's own ruling: contractors get a secure
  link per job, no account. (Tenant/owner DO now get a persistent login, §4.1 — no longer out of scope;
  see §14(1).)
- **A structured (room-linked) picker for the water valve/DB board location** (§3.1) — free text only;
  tying it to `property_rooms` is a real future enhancement, not built here.
- **Reconciling the existing `priority` field and this spec's new `urgency`/emergency flag** (§6.5) —
  flagged as likely-overlapping, not resolved here.
- ~~**A complex/estate-level routing-profile tier** (§13.1)~~ **(withdrawn 19 Oct 2026 - routing profiles are rejected, so this future gap no longer exists)** — investigated per Johan's own instruction and
  confirmed NOT to exist as a real grouping entity in CoreX today (`complex_name` is free text only);
  building one is a separate, larger decision, not taken on here.
- **Building Andre's mobile app itself** — his job; this spec's job is the API it consumes (§7),
  unchanged in spirit from `rental-work-orders.md §13`'s own framing.

---

## 13. Fault routing profiles — Rejected by Johan 19 Oct 2026 – agent or owner selects the contractor

> **REJECTED by Johan, 19 Oct 2026 - the agent or the owner selects and appoints the contractor; CoreX never sends a fault or work order to a contractor by itself (by specialty, category, urgency or any rule).** Everything in this section (the routing profile tables, `RentalFaultRoutingService`, the permission `rental_fault_routing.manage`, its Setup Wizard entry, the routing log and its screens) is **VOID and must not be built**; it is kept below only as the record of what was rejected. What stands instead: §21 (the agent's "who looks first" list is a pick-list the agent confirms), §22 (nothing is automatic - every job carries an authorisation record), §23 (the owner's own limits) and §26 (quote-first).

**Settled, 2026-09-29.** Johan's design doctrine, verbatim: *"don't build one way where there are
options; build the options and let the agency set it up; it can vary PER PROPERTY."* This section
replaces §6.5's original "notification-priority only" emergency treatment with a real, configurable
routing engine that decides WHO gets a fault/work order and WHETHER owner approval applies — the single
biggest structural addition this ruling makes.

### 13.1 Checked first, per Johan's own instruction: does a complex/scheme grouping exist?

**No.** Investigated directly before speccing anything: `properties.complex_name` (`app/Models/
Property.php:652`) is a plain free-text string used only for address formatting
(`PropertyAddressReconciler`) — there is no `Complex`/`Scheme`/`Estate` model, no `complex_id`/
`scheme_id` FK anywhere, and no table that groups properties into a real, queryable parent entity.
`app/Models/MarketReports/SchemeOwner.php` is unrelated — sectional-title ownership records for market
reporting, not a property-grouping entity. **A complex/estate-level routing-profile tier that properties
could inherit from is therefore a real future enhancement, not specced or built here** — building it
would mean designing a whole new grouping entity, which is a separate, larger decision outside this
spec's scope (named in §15 below, not silently added).

### 13.2 Data model

```
rental_fault_routing_profiles       -- one row per property (override) or per agency (default) — same
                                    --   null-means-agency-default shape §3.4b of the existing spec
                                    --   already established for the spend threshold.
  id
  agency_id                        -- BelongsToAgency
  property_id                       -- NULLABLE FK properties. NULL = this is the agency's DEFAULT
                                    --   profile — applied to every property that has no override, and
                                    --   to every NEW property at creation time (Johan's own words: "an
                                    --   agency-level default profile applies to new properties, with a
                                    --   per-property override"). Exactly one default profile per agency
                                    --   (unique index on agency_id where property_id is null); at most
                                    --   one override profile per property (unique index on
                                    --   agency_id+property_id where property_id is not null).
  emergency_route                   -- enum: 'caretaker' | 'supplier' | 'owner_first'. Which target an
                                    --   emergency-flagged fault (§2, rental_fault_types.urgency
                                    --   ='emergency') routes to.
  emergency_caretaker_spend_limit     -- nullable decimal(10,2). Applies when emergency_route='caretaker'.
  emergency_supplier_id               -- nullable FK agency_service_providers. The nominated supplier when
                                     --   emergency_route='supplier' — "a nominated supplier," Johan's own
                                     --   wording; ONE nominated default, not a list (per-category/urgency
                                     --   overrides, §13.3, are where a DIFFERENT supplier per trade lives).
  emergency_supplier_spend_limit       -- nullable decimal(10,2). Applies when emergency_route='supplier'.
  emergency_owner_first_spend_limit     -- nullable decimal(10,2). Applies when emergency_route
                                       --   ='owner_first' — the amount below which even the "owner
                                       --   first" route auto-proceeds without waiting for a reply (e.g.
                                       --   a trivial emergency call-out fee), above which it genuinely
                                       --   waits. Nullable meaning "always wait, no auto-proceed."
  non_emergency_route                 -- enum: 'agent_review' | 'caretaker' | 'supplier'. Default
                                     --   'agent_review' — today's existing built behaviour (§0 of this
                                     --   spec, unchanged): the fault sits for an agent to review, quote,
                                     --   and (per the property's existing spend threshold,
                                     --   `rental-work-orders.md §3.4b`) seek owner approval or
                                     --   auto-approve. 'caretaker'/'supplier' bypass agent review
                                     --   entirely for non-emergency faults too, per Johan's own "option
                                     --   to route straight to a caretaker/supplier."
  non_emergency_caretaker_spend_limit    -- nullable decimal(10,2), mirrors the emergency fields above.
  non_emergency_supplier_id              -- nullable FK agency_service_providers.
  non_emergency_supplier_spend_limit     -- nullable decimal(10,2).
  created_by_user_id
  created_at, updated_at, deleted_at      -- soft-delete only. A profile actively referenced by history
                                         --   (§13.5's routing-decision log rows cite the profile that
                                         --   fired) is never destroyed, same reasoning as every other
                                         --   history-bearing record in this feature family.

rental_fault_routing_rules            -- per-category/per-urgency overrides WITHIN a profile — "plumbing
                                      --   emergencies go to supplier X, electrical to supplier Y," Johan's
                                      --   own example. Several per profile, most-specific-wins.
  id
  agency_id
  rental_fault_routing_profile_id       -- required FK.
  category                             -- nullable string — matches `rental_fault_types.category` (§2)
                                      --   free-text vocabulary. Null = applies to any category.
  urgency                              -- nullable enum ('routine'|'urgent'|'emergency'). Null = applies
                                      --   to any urgency.
  route                               -- enum: 'caretaker' | 'supplier' | 'owner_first' | 'agent_review'
                                      --   — same value set as the profile's own emergency/non_emergency
                                      --   routes, unified into one field here since a rule can fire for
                                      --   either urgency band.
  agency_service_provider_id            -- nullable FK — set when route='supplier'.
  spend_limit                          -- nullable decimal(10,2).
  sort_order                           -- int — resolution order when more than one rule could match
                                      --   (§13.3 below); an agent-facing screen shows rules in this
                                      --   order so "which rule fires first" is never a mystery.
  is_active
  created_at, updated_at, deleted_at
```

**Every agency gets a seeded default profile, so an unconfigured agency is never broken.** Per
non-negotiable "no hardcoded thresholds (agency settings with defaults)" — a fresh agency (or one that
never visits the new routing settings screen, §13.7) must still work correctly, not silently fail to
route anything. The seeder that creates each agency's row also creates its `rental_fault_routing_profiles`
default row with `emergency_route='owner_first'`, `non_emergency_route='agent_review'`, every spend-limit
field null, and no rules — i.e. **today's existing, already-built behaviour exactly**: every fault,
emergency or not, reaches an agent/owner via the normal flow, nothing auto-routes to anyone until an
agency deliberately configures a caretaker or supplier route. This is a real database row with sensible,
named defaults, not a numeric constant buried in code — the agency can see and change it immediately from
the settings screen (§13.7), matching the same "default is a real row, not a magic number" shape
`rental_work_order_settings` already established for the spend threshold (`rental-work-orders.md §8`).

### 13.3 Resolution — `RentalFaultRoutingService::resolve()`

`RentalFaultRoutingService::resolve(RentalFaultReport $report): RentalFaultRoutingDecision` — called the
moment a fault report is created (§4 of this spec / `rental-work-orders.md §3a`), BEFORE the existing
"agent reviews" step, so the decision is available immediately, not computed lazily:

1. Resolve the applicable profile: the property's own override
   (`rental_fault_routing_profiles.property_id = $report->property_id`) if one exists, else the agency's
   default (`property_id IS NULL`) — matching the null-means-inherit convention this whole feature
   family already uses (§3.4b of the existing spec).
2. Check `rental_fault_routing_rules` on that profile for a match against the fault's `category`/
   `urgency` (§2), most specific first: category+urgency match beats category-only or urgency-only beats
   a wildcard (both null) rule, ties broken by `sort_order`. **[Design call, flagged for Johan]**: this
   specificity ordering is a sensible default, not itself specified by the ruling — the agent-facing
   routing-rule screen (§13.7) should make the actual firing order visible and testable, not just
   trust the algorithm silently.
3. If no rule matches, fall through to the profile's own `emergency_route`/`non_emergency_route` per the
   fault's `urgency` (`emergency` vs everything else).
4. The resolved route names a target (caretaker contact, nominated supplier, or "owner first"/"agent
   review") and a spend limit. **The spend limit gates exactly like the existing threshold gate already
   does** (`rental-work-orders.md §3.4c`'s `selectQuote()`) — at or under the limit, the route proceeds
   without further owner sign-off; over it, the fault still routes to the named target but flips to
   requiring owner approval before a work order is actually raised, mirroring the existing quote-gate
   mechanic exactly rather than inventing a second gate shape.
5. **`route='agent_review'`** (the default, unchanged today's-behaviour case) skips routing entirely —
   the fault proceeds exactly as `rental-work-orders.md` already built it, no routing decision is logged
   because none was made (§13.5 only logs when routing actually changed the path).

### 13.4 Resolving "caretaker"

Per Johan's own instruction: *"a caretaker (a contact linked to the property/complex in a caretaker role,
from the agency's contact types, not hardcoded)."* **Reuses the exact mechanism
`Property::sellerOwnerContact()` (`app/Models/Property.php:1040`) already establishes** — a
`contact_property` pivot role lookup, `role` a plain string column, no schema change needed. New sibling
method `Property::caretakerContact(): ?Contact`, resolving `contact_property.role = 'caretaker'`
(the value itself sourced from the agency's own `ContactType` list, `app/Models/ContactType.php` —
already agency-configurable, already soft-deletable, already the mechanism `inv-party-roles-2026-09-29`
extended this same pivot for — "from the agency's contact types" is satisfied by this being just another
selectable type in that same list, not a new hardcoded enum value anywhere in code).

### 13.5 Every automatic routing decision is logged, with the rule that fired

Per Johan's closing instruction. New `update_type` value on the existing `rental_fault_report_updates`
table (`rental-work-orders.md §11`, Stage 1) — **not a new log table**, same "one comprehensive log"
discipline this whole feature family already commits to:

- `RentalFaultReportUpdate::TYPE_ROUTING_DECISION` — written by `RentalFaultRoutingService::resolve()`
  itself, immediately after resolving, whenever a rule OTHER than the plain default (`agent_review` with
  no matching rule) fires. The row's `note` records, in plain language: which profile fired (agency
  default or this property's override), which rule if any (category/urgency/rule id, or "profile
  default"), the resolved route and target (caretaker contact name, or nominated supplier name, or
  "owner first"), and the spend limit that applied. `created_by_user_id` is null — this is a system
  decision, not a human one, same nullable-for-system-action treatment already established elsewhere in
  this feature family for automated steps.
- This is what makes "every step lands on the property's evidence log, timestamped with who did it"
  (§1.3's own closing line) true for the ONE step in this whole flow that has no human "who" — the log
  entry itself names the RULE as the actor, which is the honest answer.

### 13.6 Notifications — a routed target must actually be told, by email

**Real gap in an earlier draft of this section, fixed here**: resolving a route is meaningless if the
caretaker or nominated supplier is never actually contacted. Reuses `rental-work-orders.md §4`'s existing
external-mail mechanism exactly — no new notification pipeline:

- **`route='caretaker'`** — the resolved `Property::caretakerContact()` (§13.4) is mailed the same shape
  of notice a supplier already gets for a directly-raised work order (`rental-work-orders.md §4`'s
  supplier Mailable, property address/description/trade type/who-to-contact-back), addressed to whatever
  email is on that Contact's own record. **Skipped, not attempted, if the property has no caretaker
  contact linked** — logged as a no-op (same treatment §4 of the existing spec already gives a
  property with no owner attached), and the fault falls back to `route='agent_review'` for THIS
  occurrence so it doesn't silently vanish — an agent is notified instead, exactly as if no routing rule
  had matched, with a note on the evidence log (§13.5) naming why the routed path couldn't complete.
- **`route='supplier'`** — the nominated `agency_service_provider_id` (profile-level or rule-level, §13.2)
  is mailed via the EXACT SAME supplier-notification path `rental-work-orders.md §4` already built —
  same `AgencyServiceProviderContact` email resolution, same Mailable, no second implementation.
- **`route='owner_first'`** — the property's owner contact (`Property::sellerOwnerContact()`, already
  reused throughout this feature family) is mailed, and — because this route now has an owner making a
  first-look decision rather than an agent — the request is one the owner can act on directly through
  their `ClientUser` session (§4.1/§4.3), same as any other owner decision.
- **The assigned agent is always told too** — unchanged from §6.5's own unaffected point: routing WHO
  gets the job doesn't remove the agent from the loop, it just means they're informed rather than the
  one initiating.

### 13.7 Screens

**New agency-wide settings screen** — `corex.settings.rental-fault-routing`, alongside the existing
`corex.settings.rental-work-orders` (`routes/web.php:2970`, `rental-work-orders.md §3.4b`/Stage 3):
manage the agency's DEFAULT profile and its routing rules (§13.2/§13.3) — full CRUD on the rules table
(add/edit/archive/restore a rule, per §1.5's own floor), reorderable `sort_order`.

**New property-tab section** — alongside the existing spend-threshold override field (§8.2/`rental-
work-orders.md §3.4b`), a "Fault Routing" panel: shows the agency default read-only, with an "override
for this property" toggle that reveals the same profile/rule editing UI scoped to `property_id = this
property`. Same route/permission shape as the spend-threshold override.

No new sidebar entry for the rules screen itself — reachable from the existing Rental Work Order
Settings screen (§13.7's own settings screen) and the property tab, same "no standalone nav item for a
settings sub-panel" treatment the existing quotes/routing-adjacent screens already get.

---

## 14. Open questions — status as of 2026-09-29 (all four now settled)

1. **Access: secure link, or login?** **SETTLED, 2026-09-29 — Johan ruled directly.** Tenants and owners
   get a real `ClientUser` login, passwordless one-time email code, the same account on web now and
   Andre's app later; contractors keep a secure link per job, no account. §4 rewritten in full.
2. **Routing.** ~~SETTLED, 2026-09-29~~ **REJECTED by Johan, 19 Oct 2026 - agent or owner selects the contractor; §13 is void.** (Original text follows.) **SETTLED, 2026-09-29 — Johan ruled directly.** Never hardcoded — a per-property fault
   routing profile (agency default + per-property override), covering emergency routing
   (caretaker/supplier/owner-first, each with its own spend limit), non-emergency routing
   (auto-approve-under-threshold, unchanged, or route straight to a caretaker/supplier), per-category/
   urgency rules, and a logged rule-that-fired on every automatic decision. New §13, in full. A
   complex/estate-level tier was checked for and confirmed not to exist — named as a real future gap
   (§12), not built.
3. **Permission key shape for the fault catalogue.** **SETTLED, 2026-09-29** — follow the existing
   pattern exactly: one key (`rental_fault_types.create`) covers create/edit/archive/restore, matching
   both `rental_work_orders.create`/`rental_fault_reports.create` and `deals_v2.manage_suppliers`. No new
   split invented. §9 updated accordingly.
4. **Seeded first-aid wording.** **SETTLED, 2026-09-29** — every default opens with a fixed safety line
   (leave the area, call emergency services, if there is danger/fire/gas/live electrical exposure);
   electrical steps never instruct beyond switching a breaker at the DB board. The conductor reviews the
   full wording before build; an agency can edit its own copy afterward, same as any other agency-owned
   catalogue content. §2.1 updated accordingly.

**New cross-spec coordination point raised by item 1** (§4.1) — the passwordless-OTP-login endpoint
belongs to `client-auth.md`'s own territory (`ClientAuthController`/`ClientAuthService`), not this
spec's. Flagged there, not decided unilaterally.

**Standing instruction, pending confirmation to proceed to build**: this spec file remains as the
current design of record for Rentals Phase 1 faults/work orders. All four originally-raised questions
are now settled per Johan's rulings above.

### 8.5 Portal round 2 (8 Oct 2026, QA1) — what the tenant / owner sees after picking a fault type
Per §2/§3.2/§4.3 the portal now returns, for the chosen fault type and property, the same content the agent picker shows — first-aid steps (line breaks kept), urgency, the property's valve and DB-board photos, the agency's uploaded images / PDFs / video links — via `RentalFaultTypePortalView`, and takes several photos (agency limits). Full detail and the double-submit guard: `rental-portal-access.md` §22.
---

## 15. Fault report → owner → decision (Johan's rulings F1–F7, 8 Oct 2026, QA1)

Builds on §3a of `rental-work-orders.md` (the fault report + its append-only `rental_approvals` log) and the owner portal (`rental-portal-access.md`). Work-order / job-card internals are untouched.

**F1 — who reports.** The tenant (portal) or the agent (CoreX); also the owner for their own request. Any of them creates a normal `rental_fault_reports` row, status `reported`.

**F2 — the owner does NOT see it until the agent sends it.**
- Statuses (agent wording, `RentalFaultReport::statusLabel()`): **Reported** (`reported`) → **Under agent review** (`under_review`, set when the owner version is saved) → **Sent to owner** (`awaiting_approval`) → **Owner decided** (`approved` / `declined` / `owner_handling`), then work order / resolved as before.
- The agent prepares a SEPARATE owner version on the fault screen ("Owner version" card): `owner_title`, `owner_description`, `owner_agent_note`, `owner_photo_ids` (none shared unless ticked). The tenant's original (`title`, `description`, photos) is never edited; a tenant's / owner's own report cannot be edited in place at all (409).
- "Send to owner" (`POST …/send-to-owner`, permission `rental_fault_reports.send_to_owner`, new) requires a saved owner version; sets `sent_to_owner_at/_by_user_id`, `owner_approval_status=pending`, emails the owner (`RentalLandlordDecisionNeededMail`, sanitised title + portal link). After sending, the version is fixed. `RentalFaultReport::requestApproval()` is this action (a caller with no prepared version sends the original wording unchanged — API/legacy only; the screen forces the review step).
- ONE visibility rule for the owner, `RentalFaultReport::scopeVisibleToOwner()` / `RentalPortalScopeService::landlord*`: a fault is visible once SENT, once DECIDED (by anyone), or when the owner reported it. Applied to the list, detail, "needs my decision" and the decision endpoint (unsent → 404). Migration `2026_10_08_120000` backfills `sent_to_owner_at` for faults already pending.

**F3/F4 — the owner decides on the portal** (`GET …/landlord/fault-reports/{id}` + `POST …/{id}/decision`): Approve, or Decline (reason REQUIRED). If approving, who handles it: `own` (own contractor, optional name + phone → route `owner_handles`), `list` (a supplier from the list for this type of work → `agency_appoints` + `agency_service_provider_id`), or `agency` (my agent arranges it → `agency_appoints`, no supplier; the way out when the list is empty). Old payload values `approve_agency_appoints` / `approve_owner_handles` still work (note no longer required).
- The supplier list = `RentalFaultContractorService`: active agency suppliers carrying an agency service type (`AgencyServiceType` code/label) that matches the fault type's category (equal after normalising, or one contains the other). No category / no matching type = EMPTY list; screens say so and offer the other routes. The same service validates both the owner's and the agent's choice.
- The owner's pick is stored on the append-only `rental_approvals` row: `contractor_source` (`own`/`agency`), `contractor_name`, `contractor_phone`, `agency_service_provider_id`.

**F5/F6 — the agent records the decision** on the existing "Owner decision" card: approved / declined (reason required), who appoints (owner: optional name + number; agency: pick from the same list), evidence type + what the owner said. Buttons **Save decision** and **Save decision and create work order**. The second only redirects to the fault screen with the EXISTING work-order form opened and pre-filled (external contractor, trade, the chosen supplier in a hidden field); the work order is created only when the agent confirms that form, through the existing `fromFaultReport()`; the supplier is stored as a pre-selection (an update row says so) — ordering still follows the quote/authorisation gates (§17.6.5). The button is offered only on the agency route; where the existing rule blocks a work order (owner arranges it, declined) the decision is saved and the reason shown.

**F7 — one decision, read-only on the other side.** `recordApproval()` runs in a transaction on a locked row and refuses a second decision from either side, naming who decided and how. `RentalFaultReport::decisionSummary()` feeds both screens: decision, who, how ("Decided by the owner on the portal link" / "Captured by the agent (verbal note…)"), when, reason, contractor. The agent's record form disappears once decided; the owner's detail shows `awaiting_decision:false`. History (`history()`) lists the owner-version, send and decision steps with actor (owner portal decisions show the owner's name). No hard deletes; own/branch/agency scoping on the fault list unchanged (`scopeVisibleTo`).

**Tests:** `tests/Feature/RentalFaultFlow/FaultOwnerFlowTest.php`. **Not in this build:** internal crew, the work-order-as-owner-view, a work order for the owner's own contractor (existing rule: "owner handling" blocks it — business question raised).

---

## 16. After the owner's decision: the WORK ORDER is the external record, the JOB CARD is internal (Johan W1–W6, 8 Oct 2026, QA1)

Changes what exists (`rental-work-orders.md` §3, §14, §17); no second version beside it.

**W1 — the flow.** fault report → owner decision → **WORK ORDER, always** (every approved fault, whoever does the work) → **JOB CARD only when the internal crew does the work**. `RentalFaultReport::workOrderBlockReason()` now allows a work order on every approved route (the old "owner is handling it" block is gone); declined stays blocked.

**W3 — who does the work is set on the work order** (`rental_work_orders.assignment_type`): `internal` (crew — creates the job card), `outside_supplier` (an agency contractor / supplier — quotes and authorisation as before), **`owner_contractor`** (new — the owner's own contractor; `contractor_name` / `contractor_phone`, both optional). The create form on the fault screen offers the three, pre-filled from the decision (owner route → owner's contractor with their details; agency route → the chosen supplier). An owner-contractor work order starts already appointed (`ordered`) and `RentalApprovalGateService::authoriseToProceed()` answers "the owner arranges and pays their own contractor" — the agency prices, quotes and authorises nothing; every other route's money gate is unchanged.

**W4 — the job card is INTERNAL.** It is the record between the crew and the user who created the work order (instructions, time, materials, notes). Owners and tenants never see it: the portal job-card endpoints (`…/job-cards`, `…/job-cards/{id}`, tenant and owner) and `RentalPortalScopeService`'s job-card readers are removed, and the work-order client payload no longer carries `crew_completion` / `due_at`. Outside contractors keep their own job cards; none is created in CoreX for them.

**W2 — what they see on the work order** (`RentalWorkOrderClientViewService`): who is doing the repair (`who_label`, contractor name; the owner also sees their own contractor's phone, the tenant never does), the **appointment** (`appointment_at`, `appointment_note`), and a plain **stage**. Stages are DATA, `config/rental-work-order-stages.php` (key → words per audience): `created`, `appointment_set`, `in_progress`, `check_requested`, `reopened`, `completed`, `cancelled` (+ owner/agent-only `needs_decision`). `RentalWorkOrder::stageKey()/stageLabel($audience)` and the portal payload read it. The tenant's fault list/detail and the owner's fault detail carry the linked work-order summary (`RentalWorkOrderClientViewService::summary()`).

**Appointment (W2/W6).** `rental_work_orders.appointment_at/_note/appointment_set_at/_by_user_id/_by_contact_id`. ONE method, `RentalWorkOrderService::setAppointment()`, used by (a) the agent's Appointment card on the work-order screen (`POST …/rental-work-orders/{id}/appointment`, permission `rental_work_orders.create`), (b) the owner on the portal (`POST …/landlord/work-orders/{id}/appointment`), (c) the job-card booking (`RentalJobCard::schedule()` mirrors into the work order so internal jobs inform the tenant too). Every set/change is a history row (who, from → to, note); the tenant is emailed (`RentalWorkOrderAppointmentMail`) on a real set or change — saving the same date and note again sends nothing — under the existing "notify tenant on status change" agency setting (no new setting, so nothing new for the Setup Wizard). A finished/cancelled work order refuses an appointment.

**Progress (W6).** The agent moves the work order as before (start, contractor done, complete). The owner may also report the work **started** or **finished** from the portal (`POST …/landlord/work-orders/{id}/progress`, `action=started|finished`) — through the same `startProgress()` gate and the same tenant-check completion round as the office's "contractor reports done" (`RentalCompletionService::recordOwnerReportedDone`, via `owner_portal`); the internal crew's progress comes only from its job card.

**Not in this round (W6): a contractor login/link.** A later contractor link would need: a per-work-order secure token (the existing `RentalSecureAccessTokenService` / `ContractorSecureLinkController` already issue one for external contractors — it needs an owner-contractor variant that works without a supplier record), an appointment-propose/confirm action routed through `setAppointment()` with a contractor actor, a "work started / finished" action routed through `startProgress()` / `recordContractorDone()`, photo upload into `RentalWorkOrderPhoto`, and a decision on whether the tenant sees the contractor's phone. Nothing of that is built.

**Tests:** `tests/Feature/RentalFaultFlow/WorkOrderFlowTest.php`.

**Follow-up (8 Oct 2026) — the agent is told when the OWNER acts.** When the owner sets or changes the appointment, or reports the work started / finished from the portal, the responsible agent — the lease's OWNER-side agent, else the property's agent, the same rule the portal's "who to call" uses (`LeaseAgentService::responsibleUser()`, now shared by both) — gets the in-app alert and, if they have it on, the email, through the one `NotificationDispatcher` (event types `rental_work_order.owner_appointment`, `rental_work_order.owner_progress`, registered by migration `2026_10_08_170000`, default on, in-app + email supported). The agent's own settings decide: event on/off, channels (email is the agent's per-event opt-in), open hours and the cooldown between the same alert (default 6h). No duplicates: a repeat save of the same appointment, "started" on a job already started, or "finished" reported twice (one tenant check) sends nothing more. The agent's own bookings notify the tenant, never the agent. Removed in the W-round: the four portal job-card endpoints (tenant and owner `GET …/job-cards`, `GET …/job-cards/{id}`).

---

## 17. Owner's fault screen, the tenant's progress line, who is told, and "Review fault" (Johan, 8 Oct 2026 16:30–16:36, QA1)

**Owner's Faults tab is the place to decide.** A fault sent to the owner and awaiting an answer shows "Needs your decision" in the Faults list (flag `needs_decision`) and opens as one screen: the agent's version (title, description, shared photos, agent note), approve / decline-with-reason, contractor route (own contractor name + phone / agency supplier list / agent arranges). After deciding the same screen is read-only and, once a work order exists, shows its stage, who, the appointment and a link "Open the work order" (Work orders tab). The work order card links back ("See the fault"). Tab rename: **Jobs → Work orders** on both sides (see `rental-portal-access.md` §24 for the removed Decisions tab and where each decision moved).

**One progress line (fault AND work order).** `RentalFaultProgressService::forFault($fault, 'tenant'|'owner'|'agent')`, derived from the real records — never a typed status:
1 Sent to agent (reported_at) → 2 Agent reviewing (owner version saved / first update / any status past "reported") → 3 Sent to owner for approval (sent_to_owner_at) → 4 Owner approved (decision time; or *Not approved*) → 5 Sent to contractor for scheduling (work order created; wording by route: agency contractor / "our maintenance team" for the internal crew / "the owner's contractor") → 6 Appointment set (date & time in the agency timezone + who is coming) → 7 Work in progress (work order/card in progress) → 8 Work completed (while a completion round awaits the tenant the step reads "Work completed — please check", with the check action for the tenant only).
Each reached step shows its date; the last reached is `current`. A declined fault ends at its decision: the tenant sees only **"Not approved — your agent will contact you"** (never the owner's reason or agent notes — the tenant payload also drops `outcome_note` on a declined fault); the owner sees "You declined". A disputed work order reads "Not complete — being put right"; cancelled reads "Cancelled". **The words are data:** `config/rental-fault-progress.php` (per label: tenant / owner / agent wording) — edit there, not in the view. The tenant's Faults tab shows it on each fault (one line per step, no helper text) with "See the work order"; the owner's fault card shows it in owner wording.

**Tenant notifications (existing "notify tenant on status change" setting; no duplicates).** At *sent to owner*, *owner approved / not approved* and *work completed*, once each: `RentalPortalNotificationService::notifyTenantStatusChanged()` keys on the current step and remembers the last one told (`rental_fault_reports.tenant_progress_notified`), so a work order closing and then the fault outcome being saved is one mail. The mail shows the progress label + date (neutral for a declined fault). The appointment and "please check" mails are the existing ones. Raising the work order no longer sends a separate tenant mail.

**Who is told about a NEW fault** (tenant- or owner-reported): `LeaseAgentService::faultRecipients()` — the lease's tenant-side and owner-side agents (once if the same person) → else the property's agent → else the branch manager → the branch's office admin → the agency admin; inactive users skipped; a fault is never reported to nobody. Same event `rental_fault_report.created`, same per-agent settings; email defaults unchanged.

**Review step button (AT-451, 12 Oct 2026).** The "Owner version" card's save button reads **"Save draft"** (it was "Save owner version") — label only, same behaviour: it saves the agent's draft of what the owner will see; nothing is sent until "Send to owner". **When the person who should be told has every alert switched off**, the fault is still created and still shows as "Review fault" in the Rental Command Centre (their own agency's queue); the alert to them is dropped by design and recorded in `notification_drop_log`, they see the all-alerts-off bar, and an administrator sees them on `/corex/alerts-off` — see `notification-system-overview.md` §14.

**Both agents hear every step (AT-451 round 2, 12 Oct 2026).** The "Who is told about a NEW fault" rule above is now the rule for the WHOLE chain — owner decision, outcome, work order created / completed / overdue, extra work, the owner's quote decision / appointment / progress, the tenant's completion answer and the crew's lines all go to `LeaseAgentService::recipientsForEvent()` (both lease agents, else property agent, else branch manager / office admin / agency admin), never to the staff member who did it. Before/after table: `notification-system-overview.md` §15.

**Command centre.** A fault in status *reported* (no agent has reviewed it) is a needs-action item **"Review fault"** (`fault_to_review`), scoped own / branch / agency at the query layer exactly like the other rules, and leaves the queue as soon as the status moves (sent to owner, under review, work order…).

**Tests:** `OwnerFaultScreenTest` (screen, deep link, leak, recipients + fallback chain, state→step on the internal / agency-contractor / owner-contractor routes and the declined path, reopened / cancelled, data-driven words, tenant mails once), `RentalCommandCentreServiceTest` (Review fault: appears, leaves, scoping).

**Back-half walk follow-up (8 Oct 2026).** Step 5 of the progress line ("Sent to contractor for scheduling") is reached on the agency-contractor route only when the office actually sends the work order (`ordered_at`), not at creation; a fault whose work order was CANCELLED may get a new one (`RentalFaultReport::hasLiveWorkOrder()`); the command centre gains "Resolve dispute" for a disputed work order. Full list and the walk: `rental-work-orders.md` §17.30; tests `tests/Feature/RentalMaintenanceWalk/BackHalfWalkTest.php`.


---

## 18. Fault photos: one viewer, agent marks, a note per photo, clearer screens, "no contractor" warning (AT-452, Johan 12 Oct 2026, QA1)

### 18.1 Where fault photos are shown today (before AT-452)
(a) Staff fault page `resources/views/corex/rental-fault-reports/show.blade.php` — the "Photos the owner sees" Share ticks (owner-version card) and the side "Photos" block; both opened the file in a new tab.
(b) Owner portal "Review and decide" — `resources/views/rentals/portal/_fault-detail.blade.php` (owner-version photos; `ClientLandlordRentalsController::faultReportShow`).
(c) Tenant portal fault card — `shell.blade.php` `data-tenant-fault`: showed **no photos at all** (the tenant API only carried photos of the *work*). It now shows the tenant's own photos.
(d) Work order — staff work-order page lists the copied `reported` photos (new tab); the crew job page deliberately excludes `reported` photos; the contractor secure link shows no photos. Contractor/crew now see the reported photos with the agent's marks and notes (see 18.5).

### 18.2 One shared viewer
`resources/views/partials/photo-viewer.blade.php` (CSS + vanilla JS, no framework, included once per page) exposes `window.CorexPhotoViewer.open({photos, index, editable, handlers})`. Popup over the page (never a new tab); close by X / Esc / backdrop; previous / next by buttons, arrow keys, swipe; "2 of 5" counter; zoom in / out / reset with pan (wheel, pinch, buttons, double-tap); thumbnails in the strip, full image in the viewer (`PortalImageService::urls()`); phone width; focus trapped and returned; no browser dialogs. One tick **"Agent's marks and notes"** (default on); off shows the photo exactly as sent. Marks are an SVG overlay in the image's own coordinate space, so the tenant's file is never drawn on or re-encoded.

### 18.3 Data
- `rental_fault_photo_marks`: agency_id, rental_fault_report_id, rental_fault_report_photo_id, `shape` (`circle`|`arrow`), `geometry` JSON in **normalised** 0–1 coordinates (circle `{cx,cy,r}` with r relative to image width; arrow `{x1,y1,x2,y2}`), `colour`, created_by/updated_by/deleted_by user, timestamps, **soft delete**.
- `rental_fault_photo_notes`: one short description per photo (`description` ≤ 500), `recorded_by_user_id`, `recorded_at`, soft delete (setting again restores the same row).
- The original `rental_fault_report_photos` row and file are never touched. Proven by checksum in `FaultPhotoMarksTest`.
- History: `RentalFaultReportUpdate` types `photo_mark_added|changed|removed|restored`, `photo_note_set|removed` (who, when, which photo) — shown in the fault History card.
- Setting `rental_portal_settings.tenant_sees_agent_marks` (default **off**). Settings page → Fault photos; Setup Wizard (Rentals portal step) via `updateTenantSeesAgentMarks` (has()-guarded, onboarding §6.1).

### 18.4 Who may do what / sees what (server-side — `RentalFaultPhotoAnnotationService`)
| Audience | Marks and notes |
|---|---|
| Staff who may edit the fault (`rental_fault_reports.create` + `guardRentalRecordScope`) | add / move / remove marks, set / clear the note; everything visible |
| Staff view-only | read |
| Owner | only photos the agent ticked Share (the owner version) **and** only once the fault has been sent (`sent_to_owner_at`) |
| Tenant | their own photos (portal uploads, `uploaded_by_user_id` null, on faults they can already see); marks / notes only if `tenant_sees_agent_marks` |
| Contractor (private link page) | EVERY photo of the fault behind the work order, read-only in the shared viewer, with marks and notes (`RentalFaultPhotoAnnotationService::contractorProblemPhotos()`; changed 14 Oct 2026 - it used to be only the photos the agent ticked Share for the owner, so an un-ticked tenant photo left the page empty). The job email's photo count is the same list. |
| Crew (job card page) | the same list as the contractor: EVERY photo of the fault, read-only in the shared viewer, with marks and notes (`forJobCard()` -> `contractorProblemPhotos()`; changed 14 Oct 2026 on Johan's ruling - it was only the photos ticked Share for the owner) |
The portal identity / link checks are unchanged — the new data rides on the existing payloads. Staff routes: `POST rental-fault-reports/{id}/photos/{photo}/marks`, `PUT|DELETE …/marks/{mark}`, `POST …/marks/{mark}/restore`, `PUT|DELETE …/note` (JSON, same convention as `storePhoto`).
List-screen floor: not a list entity; scoping is per fault (agency global scope + `guardRentalRecordScope` own / branch / agency); archive = soft delete with a one-step Undo (restore route) and the history entry.

### 18.5 Screens
- Staff fault page: Share thumbnails and the Photos block open the viewer; marks tools only for editors. Work-order page: reported photos open the viewer (read-only marks).
- Owner "Review and decide": header, then sections each with a heading and a divider, in this order: **Progress**; **What the tenant reported** ("What you reported" when the owner reported it); **Photos**; **What your agent says**; **Your decision**. No new helper text; empty sections are left out. Same data as before.
- Tenant "Try this first": the safety line in its own warning box with an icon; "Try this first" a real heading; the steps an ordered list in a highlighted box (wording unchanged — only split into lines; `RentalFaultTypePortalView` adds `safety_line` + `steps`, `first_aid_steps` is unchanged); "That fixed it" / "Still a problem" two equal buttons side by side (stacked on a narrow phone). Owner wizard unchanged.

### 18.6 Item 9 — "No contractor loaded for <fault type>" warning before sending
- ONE method: `RentalFaultContractorService::hasContractorFor($fault)` = `optionsFor($fault)->isNotEmpty()`. The owner portal's note used `optionsFor()` being empty (`ClientLandlordRentalsController::faultReportShow`, `contractors`); it now sends `has_contractor` from the same method, and the agent page uses it too, so they cannot disagree. A fault with no type = all contractors count (unchanged). The test is agency-scoped (`agency_id`, active, maintenance contractors); there is no branch dimension in it, so the agent page has none either.
- Staff fault page, owner-version card, above Save owner version / Save and send: one line "No contractor loaded for <fault type>." plus, only for users with `deals_v2.manage_suppliers`, **Add a contractor** and **Add <fault type> to an existing contractor**. Both open the existing Supplier Directory (`deals-v2.suppliers.index`) with `?fault=<id>&mode=add|types`; the directory shows a banner "Back to fault report #n" and (add mode) opens the add form with the matching trade pre-ticked. Back on the fault page the check runs again.
- Sending stays allowed, but only through the app's own confirm dialog ("Send without a contractor for <fault type>?"); the send is never blocked on the server (other callers, e.g. the API, send as before), but every send while uncovered writes `sent_without_contractor` (who, when) to the fault history, saying whether the dialog confirmed it (`send_without_contractor=1`). The owner-side note is unchanged and shows only if there is no contractor when the owner opens it.

### 18.7 Tests
`tests/Feature/RentalFaultFlow/FaultPhotoMarksTest.php`, `FaultNoContractorWarningTest.php`, `tests/js/photo-viewer.test.mjs`, `tests/js/portal-shell.test.mjs` (viewer hook), render gate + headless check at 390 / 1280 / 1920 px.

---

## 19. The contractor and the crew are told the moment they are appointed; one rule for "who suits"; a fault the tenant fixed is not a fault in progress (AT-452 round 2, Johan 12 Oct 2026, QA1)

### 19.1 What was broken (read-only investigation, work order 77)
`createFromFaultDecision` made the work order and told nobody but the property's agent; the contractor link (§4.2) and the contractor mail existed but nothing called them (`RentalSecureAccessTokenService::issueFor` had no callers), the only outbound path was the work-order-PDF button, which stays off until a quote has been typed in and the owner's approval covers it. The agent's contractor list was every active maintenance contractor (no type rule) while the owner portal's "no contractor on file" used a different rule (category → service types → ticked suppliers), so the two disagreed.

### 19.2 A — told the moment they are appointed (`RentalContractorJobService`)
Every path that gives a work order an outside contractor now sends the job: `createFromFaultDecision` (after the commit, so a mail problem never undoes the work order), the work-order screen's new "Choose the contractor / Choose a different contractor" (`RentalWorkOrder` supplier without a typed quote), and the go-ahead route (`assignSupplier`) when the contractor is new (first time, or changed after a declined quote). The same contractor again is just the existing PDF resend.
- **Email** (agency mail path, as the agent who appointed them, `RentalContractorJobMail`): the job's title and description as the owner saw them, the address, the property's access notes, how many photos with the office's marks and notes, the agent's name and number, and ONE private link (`/secure/work-orders/{token}`). No owner details, no prices; the tenant's name and number only when the agency has switched that on for crews (`crew_link_show_tenant_contact`).
- **The contractor's page** shows the job, the photos in the shared viewer with the marks and notes, **Accept / Decline (reason required)**, the quote upload, photos and mark-done. A link only opens the job for the contractor it was made for: change the contractor and the old link is dead at once.
- **WhatsApp** is never automated. A contractor with a mobile gets a `prepared` row; the agent presses "Open WhatsApp and send" (records `opened_at`, mints a link for that message, redirects to wa.me with the text) and only then "Mark as sent". "Mark as sent" without an open is refused. The emailed link keeps working.
- **No email and no mobile**: a `not_sent` row, the work order says "NOT sent — no email or mobile on file" with "Add their details" (the Supplier Directory's contacts, with a way back), the agent is told, and the Send button works the moment details exist. The picker marks such contractors "no contact details" before they are chosen.
- **Never twice**: every attempt claims a unique `idempotency_key` (`wo{id}:p{provider}:appt{n}` for an appointment, the form's one-time key for each Resend press) before sending.
- **Ledger** `rental_job_dispatches` (channel, destination, status `sent|failed|prepared|opened|marked_sent|not_sent`, who, when, the token). History lines on the work order: "Job sent to contractor X by email to … — who", "Job NOT sent …", "Contractor response …". The first counted send sets `ordered_at`/status `ordered` and `contractor_response='pending'` (new columns `contractor_response|responded_at|decline_reason` — §5.1: pending, accepted, declined, quote_uploaded).
- **The lease's agents are told** (sent / not sent / answered) through `RentalWorkOrderService::notifyAgentOfOwnerAction()` only — the one place that decides who they are (AT-451 round 2 changes that to `LeaseAgentService::faultRecipients()`; this feature follows it, nothing is duplicated). Event keys `rental_work_order.contractor_job_sent|contractor_job_not_sent|contractor_responded`.
- **A quote from the contractor's link has its own alert type, `rental_work_order.quote_received`** (14 Oct 2026, Johan, work order 192). Title "Quote received - <contractor> R<amount> - <property address>", body = the quote's own description (a document-only quote says "Quote document attached - <job title>"), opens the work order; same recipients as every other contractor alert (`notifyAgentOfOwnerAction()`), raised by `RentalContractorJobService::recordResponse()` for `quote_uploaded`. **Why a separate type:** `NotificationDispatcher` drops a second alert of the SAME event type for the SAME subject inside the person's `min_minutes_between_same` cooldown (360 min default), so a quote sent a minute after "accepted the job" (both `contractor_responded`) was recorded in the history but never alerted anyone. Registered by migration `2026_11_03_100000_register_quote_received_notification`. The agent-side "Capture a quote" form does not alert (the agent is the one doing it). Because the cooldown is per type, a SECOND quote on the same work order within the cooldown is still not announced - the first alert already points at the work order, where every quote is listed.
- **Known gap (reported 14 Oct 2026, not yet fixed):** every other contractor-link action - decline, invoice sent, extra money asked, photo uploaded, work finished - still shares `contractor_responded` (§22.17), so only the first of them per work order per cooldown alerts anyone; a photo uploaded before "Work finished" uses up the slot the "Finished - awaiting your check" alert needs. The contractor link has no appointment action (the agent or owner sets the appointment; the owner's is `owner_appointment`).

### 19.3 B — the supplier card (`rental-work-orders/show.blade.php`)
Always shows the next action: contractor name and contact details; "Job sent <date> by <channel> to <destination> — <who>" or the NOT-sent line; "Their answer: …"; **Send the job again** (no typed quote needed; disabled with an "Add an email or mobile number" link when there are no details); the WhatsApp pair; "Choose a different contractor" (grouped by the rule below); and, separately, the go-ahead (work order PDF) once a quote is selected and the owner's approval covers it — with a plain line saying what unlocks it. The old "Capture the quote and select it first" dead end is gone.

### 19.4 C — ONE rule (`RentalFaultContractorService::classify()`)
`classify()` returns every active maintenance contractor once, in `suits` (ticked for a service type that matches the fault type's category) or `others` (still selectable — an agency may use a general handyman); a fault with no type: everyone suits. `optionsFor()` (owner portal list), `hasContractorFor()` (owner note + the agent's pre-send warning) and `pickerFor()` (agent picker) are all views of it. Pickers show "Suits <type>" then "Other contractors"; when none suit: "No contractor loaded for <type>." with the two add paths (Add a contractor / Add <type> to an existing contractor).
**How the mapping is configured today:** there is no mapping table. A fault type has a free-text `category` (defaults: Plumbing, Electrical, Security, Appliance, Structural — editable per agency in the fault catalogue); the agency's trades are its service types (Settings → COC / Service Types, `deals-v2.settings.service-types`, seeded with COC "Electrical COC", Beetle, Gas, Electric Fence COC, Plumbing, Other and fully editable); a contractor is ticked for trades in the Supplier Directory. A category matches a trade when the normalised words are equal or one contains the other (≥ 4 letters): "Plumbing"✓, "Electrical"✓ (Electrical COC). "Security", "Appliance" and "Structural" match nothing in the default list, which is why a roof leak could never find anyone. `classify()` now returns `mapping_gap` so the screens say "your trade list has no ‘Structural’ yet" instead of silently nobody. **Recommended default trades to add (Johan's call — it touches the Deals COC pipeline's list, so it is NOT seeded here):** Structural / Roofing, Security (gates, locks, alarms), Appliances, General maintenance.

### 19.5 D — the in-house crew
When a job card is created from a fault decision and the agency setting `crew_link_auto_send_on_fault_job` is on (default on; settings page + Setup Wizard, `updateCrewLinkAutoSendOnFaultJob`, has()-guarded), the crew's per-job link is emailed to the crew (honest-send pattern, ledger, job-card history "sent automatically"); a crew with only a mobile gets the WhatsApp open-then-mark pair; a crew with no details, or no crew chosen yet, gets a plain "NOT sent" line. The job card's crew panel shows the state and a "Send the crew their link now"; the existing "Email a link" form stays as the Resend.

### 19.6 Tests
`ContractorJobDispatchTest`, `TenantSelfResolvedFaultTest`, updated `CrewJobServiceTest` key list where needed, and the headless-browser proof on deployed QA1 (a TEST staff login appoints a contractor on a fresh TEST fault, the mail is read from the catcher, the contractor link is opened in a second browser context and a quote uploaded, the supplier card updates).

### 19.7 F — "That fixed it" is not a fault in progress
**Found:** the tenant portal's "That fixed it" POSTed `first_aid_resolved`; `ClientTenantRentalsController::faultReportStore` created the fault through `report()` (status `reported`, fires the agent alert "Fault reported", `RentalFaultReportService::notifyCreated`) and then `setOutcome(resolved_by_first_aid)` (status `resolved`, `resolved_at`, `repaired_at`, no note). The tenant's Faults tab drew EVERY fault as a card with `RentalFaultProgressService::forFault` — so a closed one showed the whole timeline.
**Now:** `RentalFaultReportService::reportSelfResolved()` — the fault is logged born closed (`resolved`, outcome `resolved_by_first_aid`, a note, history line), with no "new fault" alert, nothing for the Review-fault queue (status `reported` only), not in the lease's open count (`LeaseHubService::openItemCounts`). **The owner DOES see it** (Johan's correction, §19.8). `RentalFaultReport::isTenantSelfResolved()` (resolved + first-aid outcome + reported by the tenant + not an automatic close) is derived from what is stored, so records made before this display correctly untouched. Staff read **"Resolved by tenant - tried the suggested fix"** on the list, the print list, exports and the record (`statusLabel()`), the record carries a plain banner and offers no review / owner / work-order actions; the tenancy log has an informational line. The tenant payload carries `self_resolved`, `closed`, `end_state`, `fault_type_id/name`, `resolved_at` and no progress; the Faults tab is ONE list (§19.9): the self-resolved one-liner reads "<type> - reported <date> - you fixed this yourself" and, expanded, offers "Still a problem? Report it again" (a NEW report of the same type, straight to the form, linked back to the resolved one). A tenant who reads the advice and presses Cancel makes no request, so no record (tested).
**Johan's record:** fault #93 "Lock / keys" on lease 94 (14:04, status `resolved`, outcome `resolved_by_first_aid`, reported by the tenant, channel app, no owner step) — not edited by hand; it is displayed correctly by the rule above.

### 19.8 F-OWNER — the owner SEES a fault the tenant fixed themselves (Johan's correction)
Johan's reasons: it shows the owner the fault existed, that the agency's advice dealt with it proactively, that it was resolved at no cost, and if it comes back they already knew. `RentalFaultReport::scopeVisibleToOwner()` / `isVisibleToOwner()` include a tenant-self-resolved fault. **Nothing is pushed:** no email, no alert, never under "Needs your decision" or in the decisions count (it is closed, `needs_decision=false`); an entry in their list is enough (no owner activity digest exists in CoreX today, so none is added).
- Owner list row (collapsed, §19.9): "<type> - reported <date> - resolved without a repair"; expanded: "<type> - <property>. Reported by the tenant <date>. Resolved with the suggested step: <the advice text the tenant was shown>. No cost." plus the tenant's own words when they typed any (`RentalFaultTypePortalView::adviceFor()`). The detail screen has the same under "Resolved without a repair".
- **"Have this repaired anyway"** (owner portal, and the agent's record): the owner's existing *Request work* flow opens pre-filled from the report (type, property, the note "Follows the report of <date> that the tenant resolved themselves.") and is saved with `follows_fault_report_id`; the agent's button (`RentalFaultReportService::raiseFollowUp`) makes the same kind of linked new fault report through the normal review → owner version → send → decision → work order flow, once (pressing it again opens the one already made). The resolved report is never edited; it shows "Owner asked for a repair on <date>" / "A repair was asked for on <date>" with a link.
- **Recurrence ("Reported before"):** `RentalFaultReport::reportedBefore()` — the most recent EARLIER fault of the same type on the same property (not cancelled / archived). A tenant-resolved one counts inside the agency's window (`fault_recurrence_window_days`, default 90, settings + Setup Wizard, has()-guarded) or when the new report was raised from it ("Report it again" / "Have this repaired anyway"); any other earlier fault of that type (repaired, declined, still open) counts whenever it was. The line "Reported before on <date> - the tenant resolved it then with the suggested step" (or "- repaired then") with a link shows on the agent's fault page and the owner's decision screen (linked only to a report that owner can open). The new report also shows "Follows the report of <date> that the tenant resolved themselves."

### 19.9 G — one list, closed ones collapsed, every row dated
Tenant Faults tab and owner Faults list: open faults first (newest first), expanded by default and collapsible; then every closed one (newest first) as ONE line "<type> - reported <date> - <end state>" with a control to expand its history; every row, open or closed, shows its reported date. End states are the audience's own words (`RentalFaultReport::endStateFor()`): tenant — you fixed this yourself, repaired, not approved, cancelled, closed (never the owner's reason); owner — resolved without a repair, repaired, you declined, cancelled, closed. "Needs your decision" items stay on top and open. Open / closed state is remembered for the visit only (sessionStorage). This replaces the separate "Resolved" group.

### 19.10 H — nothing navigates away mid-task; I — every fault the agency acted on reaches the owner
**H (standing rule for this module):** an action that helps finish the screen you are on opens OVER it. "Add a contractor" and "Add <type> to an existing contractor" (fault page warning, the appoint-stage picker, the work order's supplier card) are popups (`partials/contractor-popups`): the first is the Supplier Directory's own form (`deals-v2/suppliers/_add-form`, saved through `SupplierDirectoryController::store` — JSON when asked — same fields, same validation) with the fault's type pre-ticked; the second is a searchable list of the agency's active contractors with their current fields of work (`GET deals-v2/suppliers/trade-picker`), tick, **Save** / **Cancel** (`POST deals-v2/suppliers/add-trade`), a confirmation toast ("<type> added to <contractor>"), and the page's warning clears by itself (page-state, no reload) — a trade missing from the agency's list can be created in the popup by someone who may manage the trade list. Pickers / the supplier card re-read themselves from the page in place. Written to the contractor's history (`agency_service_provider_events`: who, when, from which fault; shown under "Recent changes" in the directory). Nothing typed on the page is touched; the owner-version card's send dialog swaps between "Send without a contractor for <type>?" and the normal one.
**I:** Johan: "any fault, even if it is in the repair limit, still needs to go to the owner. Even if the agent authorised and got it fixed, the owner still needs to know about it."
- *Before:* the early path ("Start a work order before the owner decides") needed no owner version, never set the fault's visibility (`sent_to_owner_at` stayed null, `owner_approval_status` `not_required`), so the FAULT never appeared in the owner's Faults list; the work order showed under the owner's Work orders tab but nothing was sent (`RentalWorkOrderService::notifyOwner()` is intentionally empty); the owner heard only at completion (the final statement). It also could not run once an owner version existed (status `under_review` was refused) — fixed.
- *Now:* in the prepare stage the agent saves the owner version (title, description, shared photos, note) and presses **"Go ahead and tell the owner"** (with an "This is an emergency" tick): one step creates the work order, marks the fault arranged-by-agent (`arranged_by_agent_at / _by_user_id / _basis / _limit_amount`), writes the history and sends the owner the normal owner notice (`RentalLandlordInformedMail`, same channel and same on/off setting as "a decision is needed"; completion keeps the existing final statement with the final amount). The fault appears in the owner portal at once — never under "Needs your decision" — as "Arranged by your agent": the fault, property, shared photos, "Your agent arranged this repair under your R<limit> no-approval limit." (or "as an emergency"), the contractor / crew, the quoted / approved amount, the same progress line ("Your agent told you" → "Arranged by your agent under your no-approval limit") through to completed with the final cost. Over the limit later (a dearer quote, extra work): the existing rule still applies — owner approval is asked on the work order and the fault screen says so. The owner can **Query this** (`POST /api/v1/client/rentals/landlord/fault-reports/{id}/query`): a note to the agents (history + alert to both lease agents, event `rental_fault_report.owner_query`), not an approve / decline.
- *Not acted on:* a fault the tenant never submitted leaves no record; one the agent cancelled / closed as not a fault before acting, and never sent, stays invisible to the owner. Any fault with a work order is visible (`rental_work_order_id` set), so "every fault the agency acted on" holds; a work order cancelled later keeps its fault visible.

### 19.11 Popup wording — plain agent language (wording only, no behaviour change)
The two contractor popups opened from the fault / work order pages speak the agent's language. The Supplier Directory page keeps its own wording: the shared form `deals-v2/suppliers/_add-form` takes a `$popup` switch, and only the popups set it.

| Where | Was | Now |
|---|---|---|
| Add a contractor — sub-heading | Added to your directory with "<type>" ticked as a field of work. | This contractor will be offered for "<type>" faults. |
| same, no fault type known | Added to your directory. | Add the details of the new contractor. |
| field label | Specialty | Trade |
| address label + helper | Address (the firm's business address — used on documents this supplier signs, e.g. as executor) | Business address (no helper) |
| tick | Preferred for this specialty | Our preferred contractor for this trade |
| certificates label + helper | Service types (the COCs / services this supplier handles — filters the work-order picker) | Certificates and services they can do (no helper) |
| nothing configured | No service types configured yet — add them under Settings → COC / Service Types. | No certificates or services have been set up yet. Ask someone who manages the trade list to add them. |
| validation (popup/JSON only) | "The specialty field is required." | "The trade field is required." (also: company registration number, preferred contractor tick) |
| archived-match answer | …then add the field of work there. | …then add this kind of work to them there. |
| Add <type> to an existing contractor — contractor with none | no fields of work yet | no trades yet |

Unchanged on purpose: the title "Add <type> to an existing contractor", its sub-heading, search, "already does this", the trade-list lines, Save / Cancel, the confirmation "<type> added to <contractor>", and the third popup (contact details).

### 19.12 The owner's own comment, and "Have this repaired anyway" done properly (12 Oct 2026, Johan testing the owner portal)
- **A real button.** On the owner portal "Have this repaired anyway" was a faint outline: it is now the portal's standard filled action button (full width at 390). The agent-side button on the fault page is the filled staff button too.
- **What the owner sees when they press it.** The tenant's report **read-only** under "What the tenant reported" (their words, the date, their photos if any) - the owner can never edit or overwrite it: no owner route writes to a report's title, description, photos or date (a test lists the owner's routes and fails if one appears). Under it the owner's own box **"Your comment to your agent"** (optional, plain text) and the button **"Ask my agent to have this repaired"**.
- **On submit.** The resolved report stays resolved (unchanged). A linked new request is raised for the agent exactly as before (both lease agents are told, as for any new fault; one live request at a time). The comment is saved with the owner's name and the date/time (`rental_fault_reports.owner_comment`, `owner_comment_by_contact_id`, `owner_commented_at`) on the new request. History says, in plain words, "<owner> asked for this to be repaired and wrote: ..." (on the new request and on the resolved report).
- **The agent's fault page** shows a separate block **"Owner's comment"** (who, when, text) - distinct from the tenant's report and from the agent's own "Your note / recommendation to the owner".
- **The owner's normal decision form** has the same box, same label **"Your comment to your agent"**, for approve and decline (decline still needs the owner to say why - that sentence IS the comment); it is saved the same way, shown to the agent in the same block, and read back to the owner under their decision. (The "get a quote first" choice of round 3 uses the same box.)
- **Read back.** After submitting, the owner sees their comment again (resolved report: next to "Owner asked for a repair on <date>"; decision form: under "Your decision").

### 19.13 Equal buttons, and "Go ahead now and tell the owner" becomes "Approve within agent limit" (12 Oct 2026)
- **One button size.** The filled buttons (`corex-btn-primary` / `-danger`) had no border and the outline ones a 1px border, so the two were different heights side by side ("Save draft" next to "Save and send to owner"). On the fault page and the work order page (root class `cx-equal-btns`, `resources/css/corex.css`) every `corex-btn-*` now has the same height (min 2.25rem), font size (0.8125rem), padding and border width; an action row (`cx-action-row`) lays them out on one line and stacks them full width at 390.
- **The owner-version action row** is three equal buttons: **Save draft**, **Save and send to owner**, **Approve within agent limit** (secondary style). Under it ONE line: "For a repair inside this property's agent limit (R<amount>). The owner is told, not asked." The long paragraph and the tiny text link are gone. The button is disabled until the owner version is saved (the line then says so). It opens the "who does the work" panel under the row; its submit button is "Approve within agent limit" and opens the confirm popup titled "Approve within agent limit" (with the "This is an emergency" tick; round 3 adds the reason and the emergency limit).
- **Renamed everywhere it appears:** history label "Approved within agent limit - owner told", history sentence "The agent approved this within their limit (R<amount>) and the owner was told, not asked." The owner-facing "Arranged by your agent" is unchanged.
- **Other rows checked** (fault page and work order page): see the commit message / the report to Johan for the list of what was equalised.

### 19.14 The owner's work request is an instruction; the photo description is readable (12 Oct 2026)
- **Photo viewer (one shared component: staff fault page, work order, owner portal, tenant portal, contractor and crew pages).** The photo's description sits **centred at the bottom** on a dark translucent band (`rgba(0,0,0,.6)`), 17px (16px on a phone), wrapping, max 70% of the width (94% at ≤600px), below the photo stage so it can never cover the previous / next arrows or the zoom bar. The agent's edit box (description textarea + Save/Remove) is centred the same way.
- **Owner "Request work" form (owner only; the tenant's form is unchanged).** An owner instructs, they do not troubleshoot: the type dropdown stays, the self-help ("Before you request work", "That fixed it") is gone. Heading **"Ask your agent to arrange work"**; fields **What needs doing** (type), **Details**, **Photos (optional)**, **Your comment to your agent (optional)**; button **"Send work request to my agent"**; after sending: **"Sent to your agent. They will arrange a contractor and a quote."** There is no title box - the type's name (or the start of the details) is the title; a type or some details are required.
- **What reaches the agent.** The fault is flagged **"Requested by the owner"** (banner on the fault page; alert "Work requested by the owner - <address>" to both lease agents, opening straight onto the create-work-order step). The owner's instruction IS their authority to go to a quote: the fault is born approved (the agency appoints), recorded as the owner's own decision from the portal, so the owner is never asked to decide on their own request, and the tenant is not mailed about a fault they never reported. **Limits still apply afterwards:** a quote above the property's agent limit goes to the owner to approve. The same applies to "Ask my agent to have this repaired" on a tenant-resolved fault (§19.12). The owner reads it as "With your agent".
- **Alert popup (answer only, no change):** auto-dismisses after 9 s (longer for long text, up to 15 s - `components/toast-notifications.blade.php:85-90`), or sooner on its X (`:59`); clicking it opens the alert's link (`:107-109`). It is shown once per browser tab session (ids kept in `sessionStorage`), so it does not return on the next page load - but an alert that arrives while the agent is changing page is picked up by the next page from the tab's stored "last checked" point (14 Oct 2026), a new tab shows what arrived in the last N unread minutes (agency setting, default 10), and the popup is on every staff layout; it does not mark the alert read - it stays unread in the Today tile / `/corex/notifications` until opened there.

## 20. Call-out fee on a supplier (AT-452 family, Johan 12 Oct 2026, QA1)
**Business rule.** A contractor may charge just to come out. The agent must know that BEFORE sending someone, and the owner must be told. This section only adds the fee as a fact on the supplier record and shows it; it never blocks anything. The approval rule on top of it ("owner must agree to a call-out") belongs to the fault-flow work and reads the two methods in 20.3.

### 20.1 Data (additive migration `2026_10_26_100000_supplier_callout_fee`)
- `agency_service_providers`: `callout_fee_mode` varchar(7) NOT NULL default `unknown` (`yes` | `no` | `unknown`), `callout_fee_amount` decimal(10,2) NULL (incl. VAT, ZAR), `callout_fee_note` varchar(255) NULL. Every existing supplier becomes **Not known**; nothing is guessed or back-filled.
- `agency_service_provider_callout_history` (append-only): agency_id, service_provider_id, user_id (null = system), source (`directory` | `popup` | `create`), from_mode/from_amount/from_note, to_mode/to_amount/to_note, created_at.
- Rules (one place, `SupplierCalloutFeeService`): **Yes** needs an amount above R0; the note is optional; **No / Not known** clear the amount and note. Nothing changes and no history row is written when nothing actually changed.

### 20.2 Wording (identical everywhere)
- Question: **"Charges a call-out fee?"** - Yes / No / Not known. When Yes: **"Call-out fee (R, incl. VAT)"** (required) and **"Call-out note"** (optional, e.g. "waived if the job goes ahead", "after hours R1 200").
- Picker suffix: **"- call-out R750"** / **"- no call-out fee"** / **"- call-out fee not known"** (amounts print as R750, R1 200, R750.50).
- Notice before sending someone to inspect/quote: Yes: **"<Supplier> charges a call-out fee of R750. The owner will be told."** Not known: **"We don't know if <Supplier> charges a call-out fee - check before sending."** No: nothing.

### 20.3 Methods other work builds on (`AgencyServiceProvider`)
`calloutFeeStatus(): 'yes'|'no'|'unknown'`, `calloutFeeAmount(): ?float` (null unless yes), `calloutFeeNote(): ?string`, `calloutFeeSuffix(): string` (picker text), `calloutFeeLine(): ?string` (the sentence for notices; null unless yes). Fault-flow code must call these, never read the columns.

### 20.4 Where it shows
| Where | What |
|---|---|
| Supplier Directory (`deals-v2/suppliers`) | New **Call-out** column (sortable, filterable: Any / Charges / No fee / Not known); search by name, company, email, phone; sort by name, trade, call-out; status filter (Active / Inactive / All); pagination (50); **Restore** next to Deactivate; an inline "Call-out fee" editor on each active supplier row that saves through its own route; last changes shown in the row's history. |
| "Add a provider" form and the two fault/work-order "Add a contractor" popups | The same three-way question and fields (shared `_add-form`). |
| Pickers | Fault page contractor list; work order quote picker and "Send work order to contractor" picker; invoice supplier picker - suffix per 20.2. |
| Fault page picker and work order pickers | The notice in 20.2 appears as soon as a supplier is chosen, before the agent confirms. |
| Work order supplier card | A "Call-out fee: ..." line under the contractor's name (all three states). |
| Owner | The appointment line of the owner's progress view and the owner portal's work order summary state **"Call-out fee: R750 (note)"** when there is one. Never shown to the tenant. |
| Contractor job email | When the fee is Yes: "Call-out fee on our records: R750 (note). Tell us if this is not right." Nothing for No / Not known. |

### 20.5 Permissions and scoping
Editing uses the existing `deals_v2.manage_suppliers` (routes and controller); every read and write is limited to the user's own agency at the query layer; a supplier of another agency is a 403/404 by id. No new permission key. Not a setting, so no wizard step (wording lives in code, the fee is per supplier).

### 20.6 Tests (`tests/Feature/RentalFaultFlow/SupplierCalloutFeeTest.php`)
Default Not known; Yes needs an amount; No/Not known clear it; unchanged save writes no history; history who/from/to; methods; suffix text for all three states and amount formats; picker rows carry the suffix; notice texts; directory search/sort/filter/pagination/agency scope/restore; popup JSON create with fee; other agency's supplier refused; owner line present and tenant line absent; contractor email line only for Yes.

### 20.7 Johan's ruling (12 Oct 2026): the agent's TWO choices, ONE shared block
Whenever a contractor is selected to go and look at a fault - with a call-out fee, without, or not known - the agent's choices are exactly two, as two equal-sized, aligned buttons beside the call-out notice: **"Send <name> to inspect"** and **"Send to owner to authorise"**. Same wording for cc5's default contractor / caretaker.
- ONE partial: `resources/views/partials/contractor-inspect-choice.blade.php` (notice + the two buttons). The fault page, the work order page and cc5's default-contractor screen all `@include` it; nobody keeps a second copy.
- This work (call-out fee) owns the **notice** and the fee methods. The partial renders the two buttons **only when the caller passes `$inspectAttrs` and `$authoriseAttrs`** - what each button does is the fault flow's (cc6) decision, wired by the caller; until it is wired the notice shows and no dead button appears.
- The notice never blocks; "no fee" shows no notice but the buttons still apply.

### 20.8 Supplier Directory list - one line per supplier, and a Rentals menu link (Johan, 13 Oct 2026, QA1)
**Why.** Every supplier was printed fully open (trades, address, call-out editor, contacts), so the list could not be scanned. Same screen, same data, same routes; only the layout and one menu link change.
- **One line per supplier.** Name (with the Preferred badge and company), Kind, Specialty, main contact, Call-out, Status and one **Open** button. Main contact = the first active contact person at the supplier (else the supplier's own email), with phone / email under it. Everything else (kind change, attorney role, service types, business address, call-out fee editor and its history, recent changes, contacts, Set preferred, Deactivate) sits in the row's details, which **Open** expands in place; the button then reads **Close**. One row is open at a time. A save or a validation error reloads the page and the same row is opened again (remembered in the browser for that one reload; `?open=<id>` also opens a row; the supplier a fault page sent you to opens by itself).
- **Buttons.** Every row button (Open, Close, Restore) is the same size. **Set preferred** and **Deactivate** are equal-sized buttons inside the details. Deactivate asks first (the app's own confirm dialog) and never deletes - the supplier goes Inactive and **Restore** (on the row, unchanged) brings it back. Inactive suppliers have no details to open.
- **Kind** in the line is read-only text; the persist-on-change Kind select moved into the details (same route, same behaviour).
- **Unchanged:** search, Show / Kind / Call-out filters, every sort, 50-per-page paging, Restore, the Add provider form, every route, permission and agency scope.
- **Phone width (390).** Kind, Specialty, Contact and Status columns fold under the name (one small line) so the row keeps Name, Call-out and the button; the details stack in one column.
- **Wording.** Page sub-heading: "Service providers and contractors used on deals and rentals." (neutral; the old line named deal-register examples).
- **Menu.** A **Suppliers & contractors** link in the Rentals section of the sidebar, same permission as the page (`deals_v2.manage_suppliers`), same screen. The Deal Register Settings link stays. Arriving by the Rentals link (`?nav=rentals`) keeps the Rentals menu open and its link highlighted; arriving by the old link behaves as before.
- **Tests:** `tests/Feature/RentalFaultFlow/SupplierDirectoryListTest.php` (one summary row per supplier, details present but closed, Open button, equal-size button classes, Set preferred / Deactivate as buttons with confirm, neutral sub-heading, Rentals link present with permission and absent without, sidebar open-state for `?nav=rentals`). Existing `SupplierKindTest`, `SupplierCalloutFeeTest` still pass.
- Not a setting; no wizard step.

### 20.8a Validation state belongs to the form that was submitted (Johan, 13 Oct 2026 - data-corruption fix)
**The bug.** The call-out partial refilled itself from the last submitted form (`old()`), and the Supplier Directory holds one copy per supplier plus the Add form. A failed save on supplier A therefore showed A's failed values in every other supplier's editor, and pressing Save on one of those wrote a wrong fee.
- **Rule (the bug class, not the instance).** Input remembered after a failed save is used ONLY by the form that was submitted. Every call-out editor posts its own `_callout_scope` (its id prefix: `sp<id>` per supplier, `sp` for the Add form / each popup's own prefix); only the copy whose scope comes back refills and shows the reason (inline, under that editor); every other copy shows its stored values. One partial, so the directory rows, the Add form and the fault / work order "Add a contractor" popups all follow it.
- The other per-row editors (kind, service types, attorney role, business address) save on change and read stored values, and the contact add form never refilled; none reads `old()`. A scan test fails if any Supplier Directory view or the contractor popups start to read `old()` outside the scoped partial.
- Tests: `tests/Feature/RentalFaultFlow/SupplierCalloutOldInputScopeTest.php` - failed save on A leaves B and C showing their stored values; saving B and C afterwards stores their own values and writes no history; a failed Add refills only the Add form; scopes unique; the scan.

### 20.9 Two more kinds, and the kind follows the trade (Johan, 13 Oct 2026)
**Why.** Transfer attorneys and bond originators showed Kind = "Contractor", which is wrong and misleading.
- **Kinds added** to the one list (`AgencyServiceProvider::KINDS`): `attorney_conveyancer` "Attorney / conveyancer", `bond_originator` "Bond originator". Existing keys and labels unchanged.
- **Never a rental contractor.** `NON_MAINTENANCE_KINDS` = those two. `maintenanceContractors()` (the ONE rule behind every rental contractor picker, quote / work order / invoice supplier check, contractor job send and the "Who looks at faults first" picker and save) and its validation twin `maintenanceExistsRule()` now also exclude by kind, so the exclusion holds whatever the trade says (a firm re-kinded by hand, or saved with trade "Other"). The existing trade / attorney-flag exclusions stay.
- **Kind follows the trade.** Every way of creating a supplier (directory form, deal inline add, deal register attorney firm) goes through `AgencyServiceProvider::kindFor()`: trade Transfer attorney / Bond attorney / Conveyancer -> Attorney / conveyancer; Bond originator -> Bond originator; only a plain Contractor is re-derived, a kind chosen on purpose is kept. On the Add form: Attorney / conveyancer keeps a trade picker limited to the three attorney trades (default Conveyancer) so deal pickers still find the firm; Bond originator is saved with trade Bond originator; the other kinds are saved with trade Other as before.
- **Data fix** (migration `2026_10_28_100000_correct_supplier_kind_for_attorneys_and_originators`, additive, idempotent, no deletes): suppliers still marked Contractor whose trade is Transfer attorney, Bond attorney or Conveyancer (or that carry an attorney flag) -> Attorney / conveyancer; trade Bond originator -> Bond originator. Each change writes a line to the supplier's own change history ("Kind corrected from Contractor to ... by the system (the trade is ...)", no user = system). Not touched: any supplier whose kind was already set to something else, and External agency (stays Contractor - not asked for).
- **Not a setting**, so no wizard step. Tests: `tests/Feature/RentalFaultFlow/SupplierKindCorrectionTest.php` (kinds, correction + history + count + idempotence, kind from trade at creation, Add form, neither kind in any rental contractor picker or first-responder picker / save / rule).

## 21. Supplier kind, and "Who looks at faults first" (AT-452 family, Johan 12 Oct 2026, QA1)

**What and why.** A fault is not always a job for a contractor. On a sectional-title block the first person to look is the caretaker or the complex manager; in an insured case the insurer's assessor; on an owner who keeps their own maintenance man, that man. Today CoreX only has contractors, and nothing says who should be told first. This section adds (1) a **kind** on every supplier, and (2) a short, ordered **"Who looks at faults first"** list on a rental property and, as a default, on the owner (contact as landlord). Lanes: this section = kind + first-responder rules + the fault-page block + picker order. cc4 owns the supplier call-out fee fields; cc6 owns the fault-flow decision logic (what happens after the agent presses the button). Nothing here sends anything automatically and nothing here changes approval logic.

**Checked first (read-only, 12 Oct):** suppliers live in `agency_service_providers` (model `DealV2/AgencyServiceProvider`); there was no "kind", no preferred contractor per property or owner, and §13's routing profiles (nominated supplier) were specced but never built (no table, no service). `landlordContact()` / the `contact_property` roles `landlord|owner|seller|lessor` are how an owner links to a property.

### 21.1 Supplier kind
- New column `agency_service_providers.kind` (varchar 40, NOT NULL, default `contractor`; every existing row = `contractor`). Values (one list, `AgencyServiceProvider::KINDS`): `contractor` "Contractor", `caretaker` "Caretaker / complex manager", `owner_team` "Owner's own maintenance team", `insurer` "Insurer / insurance assessor", `body_corporate` "Body corporate / managing agent". The labels are the only wording; nothing else keys off the text.
- **Supplier Directory list** (`deals-v2/suppliers`): a **Kind** column (sortable), a **Kind** filter beside the search / status / call-out filters, and the kind changed per supplier with a persist-on-change select (same idiom as the service types); chosen on the add form (default Contractor). The list's own search, status filter, sorting, paging and Restore are cc4's (§20.4) - not repeated here. A kind other than Contractor needs no trade: the trade is always saved as "Other".
- Kind does **not** change who is offered as a repair contractor anywhere (the maintenance-contractor rule is untouched). It is shown beside the name in pickers (a small badge for every kind except Contractor).
- Multi-agency: the list is per agency; a supplier's kind is that agency's own data. No agency-specific wording.

### 21.2 Who looks at faults first - the rule
- Table `rental_first_responders` (BelongsToAgency, SoftDeletes): `agency_id`, **exactly one of** `property_id` / `contact_id` (property rule vs owner default), `agency_service_provider_id`, `position` (1-3), `fault_scope` (`all` | `types`), `fault_type_ids` (JSON list of `rental_fault_types.id`, only when scope = `types`), `tell_mode` (`inspect` = "Notify to inspect and report back", `info_only` = "Notify for information only"), `created_by_user_id`, timestamps, `deleted_at`. **Max 3 entries per property and per owner; the same supplier cannot appear twice in one list.** A list is saved as a whole (the form posts all rows); rows removed from the form are soft-deleted, so history keeps resolving.
- Table `rental_first_responder_changes` (append-only change log, like the other work terms): `agency_id`, `property_id` / `contact_id`, `changed_by_user_id`, `changed_at`, `old_summary`, `new_summary` (plain-English one-line-per-entry text), `agreed_with` (optional, "agreed how / when"). Shown under "History" on both screens.
- **Which rule is in effect for a fault of type T** (`FirstResponderService::forProperty()`): (1) the property's own entries that cover T (scope `all`, or `types` including T); if there are any, those are in effect (source = property); (2) otherwise the owner's default entries that cover T (source = owner; the property's landlord contacts are tried primary first); (3) otherwise nobody. So a property entry overrides the owner's for the fault types it covers, and the owner's default still covers the rest. Entries come back in their saved order. A supplier that is archived/inactive is skipped, never offered.
- **The clean method:** `Property::firstRespondersFor(?RentalFaultType|int $faultType): Collection` (delegates to `FirstResponderService`). Each item: `supplier_id`, `name`, `kind`, `kind_label`, `tell_mode`, `tell_label`, `source` (`property`|`owner`), `source_label` ("Set for this property" / "From owner default - Siyabonga Simamane"), `position`, `callout_line` (cc4's call-out wording - `AgencyServiceProvider::calloutFeeSuffix()` - when that exists, else null), `phone`, `has_contact`. cc6 wires the flow on top of this; it is the only thing they need to call.

### 21.3 Screens
- **Property** (rental tab, under the work terms panel): panel "Who looks at faults first". Up to 3 rows: supplier picker (search by name or kind; **Add a supplier** opens the existing add-supplier popup over the page - nothing navigates away - and the new supplier is picked), **For** (All faults / Only these fault types - tick list of the agency's fault types), **Tell them** (the two wordings above). Save, with an optional "Agreed how / when" and a History. A line states which rule is in effect: "In effect: set for this property" or "In effect: from owner default (Siyabonga Simamane) - change for this property", or "Nobody is set up" - per fault-type group when they differ. Permission: `rental_work_orders.manage_work_terms` to save (scoped like every property write, `authorizeProperty`); anyone who can see the rental tab sees it read-only.
- **Owner** (contact page, Properties tab, shown when the contact is a landlord/owner/seller/lessor on at least one property or already has a default): panel "This owner's default for all their properties" - same picker, same rows, same History. Permission: `contacts.edit` + `rental_work_orders.manage_work_terms`; contact scope via `authorizeContact`. The panel lists which of the owner's properties override it.
- **Fault page** (`rental-fault-reports/show`): while the fault is open and has no work order, a block "First to look at this" above the owner-version / action area: "First to look at this: <name> (<kind>) - <call-out line>" (the call-out line is cc4's `AgencyServiceProvider::calloutFeeSuffix()`, shown when the supplier has it), where the rule came from ("Set for this property" / "From owner default - <owner>"), the other entries as "Then: ..." beneath, and **exactly two choices, always, even when a default exists** (Johan's ruling, 12 Oct): two equal-sized, aligned buttons - **Send <name> to inspect** and **Send to owner to authorise**. Nothing is ever auto-sent; the agent presses one. The call-out notice and the two buttons are cc4's ONE shared block, `partials/contractor-inspect-choice.blade.php` (§20.7), used here, on the work order page and wherever a contractor is chosen to look at a fault - the wording and buttons exist in one version only; `partials/first-look-block.blade.php` is just the "First to look at this" heading, source and "then ..." lines around it. **The buttons send nothing yet:** both post to one route and go through the single seam `FirstResponderDispatcher::send($fault, $responder, $by, $choice)` (`inspect` | `owner_authorise`); the default implementation only writes a line on the fault's history ("<agent> chose to send <name> to inspect - not sent yet" / "... chose to send this to the owner to authorise - not sent yet"). cc6 replaces the implementation with the real flow; the buttons, route and history line do not change. An "information only" entry still shows the same two buttons; its "Notify for information only" setting is passed to the seam in `$responder['tell_mode']` for cc6 to act on. No approval, status or owner-notice logic is touched.
- **Contractor picker** on the fault page ("Create work order" / appoint): a group **"Set for this property"** at the top (the first responders for this fault's type, in order, from either source, any kind), then the existing **"Suits <type>"** and **"Other contractors"** (a first responder is not repeated below). Every row shows the kind badge when it is not a Contractor.

### 21.4 Not in this build
First-responder rules are not a setting an agency configures in Setup Wizard terms (it is per-property data, like the work terms, which are not in the wizard either); recorded in the "Deliberately NOT in the wizard" list. No automatic send, no change to who is told about a fault, no change to the approval gate. §13 routing profiles stay unbuilt.

### 21.5 Tests
`tests/Feature/Rentals/FirstResponderTest.php` (service resolution, override, per-type, skip inactive, max 3, no duplicates, agency isolation, property and owner screens save + change log, permission and scope), `tests/Feature/Rentals/SupplierKindTest.php` (default kind, list column/filter/search/sort/reactivate, add form), `tests/Feature/Rentals/FaultPageFirstResponderTest.php` (block, button, history line, picker order, no auto send).

## 22. THE GOVERNING RULE: nothing is automatic - every job carries an AUTHORISATION RECORD at each of two decisions (AT-452, Johan 12 Oct 2026, QA1)

**This section governs.** Where §15, §17.6, §19.10 (item I), §19.13, §20.7 or §21.3 say otherwise, this section wins (they are annotated below). It replaces the parked "round 3 / two limits" design.

### 22.1 Principle
Work proceeds only after an **authorisation record** exists. There are two decisions, in this order, and no amount is ever approved before an amount exists:
- **Decision 1 - who goes to look.** Authority to send someone to inspect and quote (point `inspect`).
- **Decision 2 - the cost.** Authority to spend an amount that now exists: a quote, or our own crew's work-order selling price, or an amount the agent types for a small known job (point `cost`).

A record (table `rental_authorisations`, append-only; a newer one supersedes, nothing is edited or deleted) holds: the point, the outcome (`authorised` / `declined`), **who** authorised, **on what basis**, **at what stage**, **when**, the amount it covers (D2 "approved up to", D1 the call-out fee), the owner's words/proof when it is the owner's instruction, and whether the owner has been told.

| Basis (`basis`) | Who | Reads (owner's own words) |
|---|---|---|
| `owner_link` | the owner pressed the button on their link | "Authorised by you on your link on <date time>." |
| `owner_instruction` | an agent captured what the owner told them | "Authorised by your agent <name> on your instruction, given by <phone call> on <date time>." + the note |
| `agent_no_callout_fee` | D1, the visit costs nothing (no call-out fee, or our own crew) | "Your agent <name> sent <contractor> to look at this and give a quote. <Contractor> charges no call-out fee, so the visit costs you nothing." |
| `agent_callout_setting` | D1, the owner's call-out permission covers the fee | "Your agent <name> sent <contractor> to look at this and give a quote, under your call-out permission of R___. The call-out fee is R___." |
| `agent_repair_limit` | D2, the amount is inside the owner's repair limit | "Authorised by agent <name> under the owner's repair limit of R___." |
| `agent_emergency_limit` | D2, an emergency inside the owner's emergency limit | "...under the owner's emergency limit of R___." (with the agent's reason) |

### 22.2 The owner's three settings are cc4's; we READ them through one resolver
`App\Services\Rentals\RentalOwnerFaultSettings::for($property)` (cc4's resolver, §23.2) is the ONLY reader: `repairLimit()`, `emergencyLimit()` and `calloutAllowedUpTo()` return null = **must ask the owner** (nothing recorded, or the owner disputed). Nothing in the fault flow reads the three settings' columns. Stage 1 uses `calloutAllowedUpTo()`; stage 2 will use `repairLimit()` / `emergencyLimit()`.

### 22.3 The agent's fault page (top to bottom, in WORKING ORDER - Johan's walk of QA1, 13 Oct 2026)
1. **What the tenant reported** - read-only (their words, who, channel, date, tenancy, item, photos). The loose "Tenancy / Item / Reported by / Channel / Captured by / Reported at" lines are gone (they repeated this block). **Cancel report** (and Archive) are secondary buttons in the page header actions.
2. **What the tenant says they checked** - the self-checks the tenant ticked when they reported (the "Try this first" steps are tickable on the tenant's form; the ticks are stored with the report as a snapshot). A fault with none: "No self-checks were shown for this fault."
3. **Confirmed with the tenant** - the agent records HOW (phone call / WhatsApp / email / in person), WHEN, and a NOTE. A later one is added, never overwrites.
4. **Reported before** (§19.8).
5. **What the owner will see** - the owner version (title, description, photos, the agent's note) with ONE button, **Save**. There is no "Save and send to owner" and no "Send saved version to owner": there is ONE way to the owner.
6. **Who goes to look first?** (sub-line "Nothing is sent until you press a button.") - the picker, the two equal buttons and "Record the owner's decision". **Both buttons save "What the owner will see" themselves** (the page sends the draft's fields with the press; an invalid draft - no title - sends nothing), so the agent never scrolls back to save. The amber "No contractor loaded for <type>" line, with its two add paths, shows ONCE, here. No "Decision 1 / Decision 2" wording anywhere a user sees it (stage 2: "Approve the cost").
Picker rows are one aligned line each: name | kind or trade | phone | call-out. A contractor with no email or mobile gets the amber line "No email or mobile on file - they cannot be sent the job" and "Send <name> to inspect" is disabled for them (the owner can still be asked).

### 22.4 Decision 1 - who goes to look
The agent picks (one list, one rule): **Set for this property** (cc5), **Suits <type>**, **Other contractors** (each with cc4's call-out suffix), or **Our own crew**. With someone selected, exactly TWO equal buttons (the shared partial `partials/contractor-inspect-choice`, actions passed in; fees read only through `calloutFeeStatus()` / `calloutFeeAmount()`; cc5's `FirstResponderDispatcher` seam is replaced by `FaultInspectionService`):
- **"Send <name> to inspect"** - allowed when the contractor has **no** call-out fee, or our own crew; or when there is a fee (a fee **not known is treated as a fee**) AND `calloutAllowance(property)` covers it (a fee not known can never be covered - it has no amount: the line then says so). Otherwise the button is disabled with: "<Name> charges a call-out fee of R750. The owner must authorise this first." (not known: "We don't know whether <Name> charges a call-out fee. The owner must authorise this first - or set the fee on the contractor's card."). On send, in ONE step: the authorisation record (basis `agent_no_callout_fee` / `agent_callout_setting`) -> the work order (contractor: the existing job email + secure link; crew: job card) -> the owner is told at once (who, why, the fee) -> the fault shows to the owner as "Arranged by your agent". **The owner version must be saved first** (the owner is told what they will read).
- **"Send to owner to authorise"** - the ONE way to send a fault to the owner. The fault goes to the owner on their link. It works with **nobody picked** (the owner is simply asked "send someone to look and quote?"); with someone picked it carries that contractor and its call-out fee. The owner chooses **"Send someone to look and quote"** or **"Decline"**.
- **"Record the owner's decision"** is available whenever the owner's answer is awaited (prepare and sent stages).
- **"Approve within agent limit" is gone from the fault page** (and the early "Start a work order before the owner decides" path with it): approval against a limit exists only at Decision 2. A work order cannot be made from a fault without a Decision 1 authorisation (`RentalFaultReport::workOrderBlockReason()`).
Buttons in every row are the same size and aligned (§19.13 stands).

### 22.5 "Record the owner's decision" - one popup, both decisions (replaces the old Emergency approval form and the fault page's "Record decision on the owner's behalf"; old records and Void keep working)
Fields: **Which owner** (the lease's / property's owners); **Decision** (D1: "Send someone to look and quote" / "Declined"; D2: "Approved" with **"Approved up to R___"** / "Declined"); **How the owner told us** (phone call / WhatsApp / email); **When**; **What the owner said** (required); **Proof** (optional upload). Saved with the agent's name; never edited; **replaceable by a newer record until the next stage has been acted on** (D1: until a work order exists; D2: until the work starts); the full history stays. The owner who disagrees presses **Query this**; the agent then records a newer decision. (An owner's own press on their link is final: an agent cannot replace it.) The owner's link/portal then reads "Authorised by your agent <name> on your instruction, given by <phone call> on <date time>" + the note + **"Query this"**, and the owner is **emailed the same sentence immediately** (to the owner's address; QA1's outbound guard holds it).

### 22.6 Decision 2 - approve the cost (stage 2, built 13 Oct 2026)
Nothing is approved before an amount exists, and nothing is approved by itself. A work order may not start (be sent, scheduled, started, completed by the crew) until its cost has an authorisation record (`point = cost`). **Selecting a quote or pricing a job card no longer approves anything**; it only puts an amount in front of someone.
- **The amount** (`RentalAuthorisationService::costMeasure()`): the selected quote's owner-facing amount (what the owner pays, VAT included); for **our own crew** the **work-order selling price** (the job card's owner-facing total) - the job card's cost, margin and mark-up are never shown to the owner and never measured; or, when neither exists, **an amount the agent types** for a small known job. A call-out fee already charged counts inside the job total.
- **Within the owner's repair limit** (`RentalOwnerFaultSettings::for($p)->repairLimit()`, null = must ask) -> the agent presses **"Authorise R___ within the repair limit"**; record basis `agent_repair_limit`; the owner is told at once (email, same sentence as the portal).
- **Emergency:** the agent ticks **"This is an emergency"** and must write the **reason**; the limit then is `emergencyLimit()` (record basis `agent_emergency_limit`, with the reason). Above it - emergency or not - and whenever a limit is not set or disputed, **the owner's authority comes first**: the owner approves on their link (a quote over the limit goes to them as before), or the agent records the owner's decision (below). An agent can never authorise above the applicable limit.
- **"Record the owner's decision" (cost):** same popup as Decision 1, on the work order: which owner, **Approved up to R___** or Declined, how they told us, when, what they said (required), optional proof. Basis `owner_instruction`; the amount approved must cover the amount being measured. An owner's own press on their link is basis `owner_link`.
- **The old "Emergency approval" form on the work order is gone** (it recorded the owner's agreement with no amount). Old emergency approvals already on file stay readable and voidable. A work order approved before stage 2 (approved by the owner, by the old limit rule, or emergency) keeps working; nothing is back-filled.
- **A final invoice / cost above what was authorised** sends **the difference to the owner**: the close raises a request for the extra (a variation, origin `final_invoice`), emails the owner, and the job closes once the owner has decided. **THE AUTHORISED AMOUNT IS A HARD LOCK (Johan, 13 Oct 2026: "if its set to R750 then thats a hard lock"): NO OVERRUN IS ALLOWED.** Not the repair limit, not a tolerance %, not R0.01. Any final cost, variation (extra work, a higher quote, a crew price change) or invoice even one cent above the authorised amount goes to the owner as a request for the extra, and the job cannot complete or close until it is decided (the owner on their link, or the agent recording the owner's instruction). Exactly equal passes. The same hard reading applies to a call-out fee against the owner's call-out allowance, to the owner's repair limit and to the owner's emergency limit: `fee/amount <= limit` compared in whole cents, so R0.01 over is over. Nothing in the rentals authority flow reads a tolerance any more.
- Replace-until-started: an agent-recorded cost decision can be replaced by a newer record until the work has started; an owner's own press is final.

### 22.7 Stop this / Query this (stage 3 - Johan, 13 Oct 2026)
**Which notices.** Every notice the owner receives about something an agent did WITHOUT the owner deciding on the link carries the same two buttons: today the "send <name> to inspect" notice and the "decision recorded on your instruction" notice (both are `RentalAuthorisationToOwnerMail`, sent by `RentalAuthorisationService::tellOwner()`), and - when stage 2 lands - "authorised under limit" (it goes through the same `tellOwner()`, so it gets the buttons with no further work). The same two buttons sit under the matching section of the owner's fault view on their portal (`Arranged by your agent` and `Authorised on your instruction`) through ONE shared partial (`rentals/portal/_owner-notice-actions`). A notice that ASKS the owner to decide ("Send to owner to authorise") is not one of these: the owner is deciding there. Wording is neutral, no agency name or branding in code.
**Two equal-sized, aligned buttons** - "Stop this" and "Query this" - in the email (two equal table cells), on the email-link pages and on the portal (side by side at 390px wide).

**Stop this.**
- *Available* until the work has started. "Started" = the work order is `in_progress` / `disputed` / `completed`, or its job card is `in_progress` / signed off / `completed` / `disputed` (there is no separate "contractor on site" record in CoreX; those are the only markers, and one function - `RentalOwnerHoldService::workStarted()` - decides). From then the button is replaced by the line **"Work has started - contact your agent"** and "Query this" (still available).
- *Pressing it* asks for a **reason** (required, 3-500 characters) and a **confirm**. From the email, the link opens a page that asks (a plain GET never records anything - mail scanners fetch links); only the owner's own POST press records it. The link is signed and names this owner, this job and the action. From the portal, the same two steps happen inside the fault view (logged-in owner, per-property scope).
- *Effect* (all in one step, under a lock on the fault so two presses or a press racing the contractor's first start cannot both win): a **hold** row is written (`rental_owner_holds`, append-only; active = not yet resumed). Nothing else about the fault or work order changes - the fault keeps its status. The fault and the work order (and its job card) now read **PAUSED BY OWNER**: the reason, who, when. Every send / approve / start action is refused with that reason shown (see 22.12a for the exact guard list); the buttons are disabled with the reason beside them. Both lease agents are alerted **in-app and by email** (event `rental_fault_report.owner_hold`, plus a direct email each - in-app alone depends on each agent's own switches). If the job had already been emailed to the appointed contractor or the crew (a counted email row on the dispatch ledger), they are emailed **"On hold - do not attend until you hear from us"**; a contractor reached only by WhatsApp cannot be emailed - the agents' alert then says "phone them". The tenant's progress line reads **On hold**. The authorisation records of the fault gain the line **"Stopped by owner <name> on <date time>: <reason>"**, and the fault / work order history says it in plain words.
- *Press twice / already stopped*: the second press is told "already on hold" and nothing is written twice.

**Resuming.** Resume needs a **fresh authority** - there is no plain Resume button. Two doors only: (1) the owner presses **"Go ahead after all"** (portal, or the stop page of their email link); that is a new authorisation record, basis `owner_link`, which becomes the standing record for the point that was stopped; (2) the agent presses **Resume**, which opens the same popup as "Record the owner's decision" - owner, how they told you, when, what they said (required), optional proof - and writes a new record, basis `owner_instruction`; the owner is emailed the same sentence (with the two buttons again). Either door closes the hold ("Resumed by ...") and tells both lease agents. The contractor / crew are NOT emailed automatically on resume: the agent uses the normal "Send the job again", which works again once the hold is closed. History in plain words: "Stopped by <owner> ... Resumed on <date> - <authority sentence>".

**Query this.** The owner types a question (3-1000 characters). The job is NOT stopped. Both lease agents are alerted in-app **and** by email. The question shows on the fault page in the block **"Owner's question"** (what was asked, when, who) with an **Answer** popup; the answer (required, saved under the agent's name, never edited - a second answer is added) shows on the fault page and on the owner's link / portal under their question, and the owner is emailed it. Questions asked before this build (history lines of type `owner_query`) are carried into the new table as unanswered questions by the migration. Available at every stage, including after the work has started and while paused.

**Plain wording, no hard deletes** - holds, queries and answers are append-only; nothing is ever deleted. **Not a setting** (no Setup Wizard entry, per 10a: nothing here is configurable per agency).

### 22.8 Who pays - a log only (stage 4)
CoreX does no finances yet: **no charge is raised.** At report-back the agent records **What was found** and **Cause**, marks **Who pays** (Owner / Tenant / Shared / Insurance or body corporate) with a reason. The default is suggested from the fault type's self-check ruling (e.g. empty gas bottle, flat batteries -> Tenant; blocked drain: foreign object -> Tenant, roots / collapsed pipe -> Owner), shown beside the tenant's ticks and the agent's confirmation. The owner and the tenant are told the ruling. Same for own-crew job cards. **Built as §22.13.**

### 22.9 What was already live and is changed by this section
"Approve within agent limit" (§19.13) -> removed from the fault page (Decision 2 only). §19.10 item I ("Go ahead and tell the owner") -> becomes Decision 1 "Send <name> to inspect" (owner told, with the fee). The owner's fault decision buttons read "Send someone to look and quote" / "Decline" (§15 F3). The owner's own work request (§19.14) is the owner's own instruction: it is recorded as a Decision 1 authorisation with basis `owner_link`. Everything else in §19.12-19.14 stands.

### 22.10 Multi-agency
No HFC wording or ids. All wording is neutral; limits and permissions are per property (owner settings, cc4) and per agency; the resolver is the single read point; the records are `BelongsToAgency`.

### 22.11 Tests
`tests/Feature/RentalFaultFlow/AuthorityFlowTest.php` (records, bases, the call-out rule incl. unknown fee, the resolver default, the block on a work order without authority, replace-until-WO, the owner's sentence + email, scoping, tenant ticks, tenant confirmation) and later stages' files named in each stage's commit.

### 22.13 RentalAuthorisationService - the methods other work builds on (stable names, do not rename)
`rowsFor($faultOrWo)`, `activeFor($faultOrWo, $point)`, `ownersFor($subject)`, `sentenceFor($row)` (the owner's words), `recordTextFor($row)` (the agent's words), `whenText($row)`, `tellOwner($row)` (emails every owner with an address; sets `owner_told_at`); Decision 1: `recordOwnerDecision()`, `recordAgentInspection()`, `mirrorApproval()`, `ownerPayload($fault)`; Decision 2 (stage 2): `costMeasure($wo)`, `costEvaluation($wo, ?float $typed, bool $emergency)`, `costBlockReason($wo)`, `authoriseCost($wo, $user, $in)`, `recordOwnerCostDecision($user, $wo, $d, $proof)`, `mirrorOwnerQuoteDecision(...)`, `hasCostAuthority($wo)`. A record's columns: `point`, `outcome`, `basis`, `stage`, `amount_up_to`, `limit_amount`, `is_emergency`, `emergency_reason`, `owner_contact_id`, `told_via`, `told_at`, `note`, `by_user_id`, `by_contact_id`, `owner_told_at`, `superseded_at`. Stage 3 ("Stop this") hangs off a record id; stage 4 ("Who pays") is a separate log and never touches a record.

### 22.14 Stage 2 as built (13 Oct 2026)
- **No migration** (the ledger and its cost columns came with stage 1). New: `RentalWorkOrderCostController` (`corex.rental-work-orders.cost.authorise`, `.cost.owner-decision`), `_cost-decision.blade.php` ("Approve the cost", on the work order AND the own-crew job card), constants `BASIS_AGENT_REPAIR_LIMIT` / `BASIS_AGENT_EMERGENCY_LIMIT` (work order), `DECISION_NEEDS_AGENT` (decision rows), variation origin `final_invoice`.
- **The gate** (`RentalApprovalGateService`): `evaluateQuote()` no longer approves anything - inside the owner's repair limit (`RentalOwnerFaultSettings::repairLimit()`) the work order stays "not required" with no approved amount and a `needs_agent` decision row, nothing is sent to the owner; above it, or with no limit, it goes to the owner as before. `authoriseToProceed()` / `authoriseCard()` require `RentalAuthorisationService::hasCostAuthority()`: a cost record, or work approved before stage 2 (owner approved, old limit rule, grandfathered, an old emergency agreement), or the owner's own contractor, or pricing switched off for the agency. The job card moves to "approved" only when the cost has authority.
- **One writer for the owner's decision on a cost:** `RentalWorkOrder::recordApproval()` -> `RentalApprovalGateService::recordOwnerDecision()` -> `mirrorOwnerQuoteDecision()` (basis `owner_link` for the owner's press, `owner_instruction` for an agent recording it); the amount the owner approved up to becomes the work order's baseline.
- **Extra money:** `evaluateVariation()` approves NOTHING above the authorised amount (no repair limit, no tolerance - the tolerance branch was removed 13 Oct 2026); every increase, even R0.01, is `needs_owner`. The old variation-tolerance work term is no longer read anywhere (`WorkTerms` carries only the legacy repair limit): the agency setting (and the "email the owner when extra work passes within the agreed tolerance" switch) are removed from Settings and the Setup Wizard, the per-property "Work terms agreed with the owner" panel (its only field was the tolerance) and its route are removed, the lease hub tolerance line is removed, and the auto-approved-extra owner email is removed. The stored values (`properties.rental_variation_tolerance_percent`, `rental_work_order_settings.variation_tolerance_percent`, `notify_landlord_on_auto_variation`, the work-terms history table and old `auto_approved` variations) are NOT deleted - they stay as history, labelled as an old agreement. `RentalCloseGuards::assertFinalCostWithinApproval()` raises a `final_invoice` request for the difference (`raiseFinalInvoiceVariation()`), emails the owner, and refuses the close until they decide.
- **Removed:** the work order's "Emergency approval" record form and its route (`emergency-approval.store`); the "Record decision on the owner's behalf" form. Old emergency agreements stay readable and voidable. The wording "approved automatically / within the no-approval limit" is gone from the work order and job card screens.
- **The agent's queue** (Command Centre): `wo_authorise_cost` / `jc_authorise_cost` rows say a confirmed price inside the owner's limit is waiting for the agent to authorise it.
- **Own crew:** the amount is the job card's owner-facing total (the work-order selling price); cost, margin and mark-up are never read by the cost decision and never appear in what the owner is told.
- **Wording sweep (Johan's walk of work order 77):** the old rule is gone from every rentals screen, email and PDF - "approved automatically", "approved on the spot", "No-approval spend limit for this property", "No approval needed yet", "Emergency approval - only if the work cannot wait", "Record decision on the owner's behalf". The work order's "What happens next", the Quotes box, the first-quote note, the approval badge and the job card's price button/panel now say "Nothing is approved by itself" and point at "Approve the cost". The agency's own setting is relabelled **"Suggested repair limit"** (settings page, setup wizard, lease hub): it is only a SUGGESTION shown when an owner's settings are filled in - what an agent may authorise is the OWNER's own repair / emergency limit. "Approve the cost" (work order and job card) and the lease hub show those limits with who said so: Confirmed by owner / Captured by your agent (recorded by <name>) / Owner does not agree / Not set yet. The permission "Record Owner Emergency Approval" is now "Authorise an Emergency Cost (owner emergency limit)". The owner's emails headline a cost "Your agent authorised a cost". What remains by design: history rows from before stage 2 (old basis labels, "Earlier emergency approval" with its Void, old variation rows) and the owner's own agreed tolerance %.
- **One section, said once (Johan's walk of work order 77, 13 Oct):** the old "Approval / Why was this approved? / Work cannot start yet" card is gone; "Approve the cost" (work order AND own-crew job card) is the ONE section: status badge (Not authorised yet / Waiting for the owner / Cost authorised / Owner declined, plus "Extra work waiting for the owner"), the owner's limits as two aligned lines ("Repairs you may authorise: up to R___ / not set - ask the owner"; "Emergency repairs you may authorise: ..."), one status line ("Confirmed by the owner" / "Recorded by <name> - not yet confirmed by the owner" / "The owner does not agree with these" / "Not set yet") with a link "Owner's fault settings" to the property's Rental tab panel, the amount, the emergency tick, ONE reason line that is also why the first button is off (no second paragraph), the two equal buttons, and BELOW them the authorisation records (who, basis, when). "Nothing is approved until you press a button." is said ONCE, under the heading. The Quotes intro reads "Capture the contractor's quote, or let them upload it from their link."; the first quote line "The first quote is selected for you."; the locked appointment line "An appointment can be set once the cost is authorised." The work order's detail block drops empty lines (Item, Trade) and what the Supplier / Appointment cards already say.
- **Tests:** `tests/Feature/RentalFaultFlow/AuthorityCostTest.php`; the shared fixtures `BuildsApprovalFixtures::ownerLimits()` / `authoriseCostOf()`; fixtures for jobs "already under way" are cleared the way pre-records work is.

### 22.12 Stage 1 as built (12 Oct 2026)
- **Tables/columns** (migration `2026_10_27_100000`): `rental_authorisations` (append-only; `superseded_at` / `superseded_by_id`); on the fault `tenant_self_checks` (JSON snapshot), `tenant_confirmed_*`, `proposed_assignment|supplier|crew`.
- **One writer:** `RentalFaultReport::recordApproval()` (the owner's link, the owner's own work request, an agent capturing the owner's words) calls `RentalAuthorisationService::mirrorApproval()`, so every Decision 1 leaves a record whichever door it came through. The agent's own send-to-inspect is `FaultInspectionService::sendToInspect()`: the record is written INSIDE the work-order transaction (`createFromFaultDecision(..., $authority)`); a fault nobody has authorised cannot get a work order (`workOrderBlockReason()`).
- **Tenant ticks:** the tenant's "Try this first" steps are tickable; "Still a problem" / "That fixed it" send the snapshot; the agent reads it under "What the tenant says they checked".
- **Replaced/removed:** cc5's `FirstResponderDispatcher` seam and record-only dispatcher; the fault page's "Approve within agent limit" (button, line, early "Start a work order before the owner decides"); the old "Record decision on the owner's behalf" form (the `approval.store` route stays for the API/legacy). The "go ahead" owner mail (`RentalLandlordInformedMail`) is replaced by `RentalAuthorisationToOwnerMail` (the same sentence the portal shows).
- **Not in stage 1 (stage 3 is now built, see 22.12a):** "Stop this" - the owner's notice then carried "Query this" only; Decision 2, the emergency limit, the old Emergency approval form on the work order, Who pays.

### 22.12a Stage 3 as built (13 Oct 2026) - "Stop this" / "Query this"
- **Tables** (migration `2026_10_28_200000`): `rental_owner_holds` (append-only; active = `resumed_at` null; keyed to the fault and its work order), `rental_owner_queries` (question + answer; questions asked before this build are carried in once as unanswered, `asked_via = earlier`); notification event `rental_fault_report.owner_hold`. Models `RentalOwnerHold`, `RentalOwnerQuery`.
- **ONE service:** `RentalOwnerHoldService` - `activeFor()`, `holdReason()` (the sentence every refusal shows: "PAUSED BY OWNER on <date time> - <owner> said: "<reason>". Nothing can be sent, approved or started until the owner says go ahead."), `assertNotOnHold()`, `workStarted()`, `stopBlock()`, `stop()`, `resumeByOwner()`, `resumeByAgent()`, `ask()`, `answer()`, `queriesFor()`, `historyFor()`. Added to cc6's `RentalAuthorisationService` as NEW methods only (no signature changed): `recordFreshAuthority()` and `toldAt()`.
- **Guards (every send / approve / start refuses with the reason):** `RentalApprovalGateService::authoriseToProceed()` (first check - covers start, schedule, assign supplier, the job card's schedule / start / crew link, and every screen that reads the gate); `RentalWorkOrder::assertApprovalAllows()` and the agent's `recordApproval()`; `RentalContractorJobService::appoint()`, `send()` (job email) and `sendCrewLink()`; `FaultInspectionService::blockReason()` (both Decision 1 buttons); `RentalFaultReport::workOrderBlockReason()` and `requestApproval()` (send to owner); `RentalAuthorisationService::recordBlockReason()` (while paused the way forward is Resume). **Stage 2 (cost approval / emergency) must call `RentalOwnerHoldService::holdReason($subject)` before its own approve / authorise actions** - the owner's own press is never blocked by a hold, only the agent's actions are.
- **Owner notices:** `RentalAuthorisationToOwnerMail` carries two equal table-cell buttons built from signed links to `/owner/job/{fault|work-order}/{id}/{contact}/{stop|query}` (`RentalOwnerNoticeController`; GET asks, only the owner's POST records; scanners cannot trigger it; a link for a stranger's contact id is a 404). The owner portal shows ONE shared block `portal/_owner-notice-actions` (side by side at 390px) under the notices; API `POST fault-reports/{id}/stop|resume|query`, state in `fault_report.owner_actions`.
- **Agent screens:** red PAUSED BY OWNER banner on the fault page (with **Resume**), the work order and the job card; "Authorisation records" lists "Stopped by owner <name> on <date time>: <reason>" and how it was lifted; block **Owner's question** with the **Answer** popup; fault and work order history lines in plain words. Tenant progress: the current step reads **On hold** (the tenant is never told why).
- **Mail:** agents (in-app + a direct email each), contractor / crew who were already sent the job ("On hold - do not attend until you hear from us"; WhatsApp-only contractors cannot be emailed - the agents' alert says to phone them), the owner (the answer to a question; the fresh-authority sentence on an agent's Resume).
- **Not built (decided):** no automatic "you can go ahead" email to the contractor on resume - the agent uses "Send the job again", which works once the hold is closed. No "contractor on site" button - the only "started" markers are the work order / job card statuses (see 22.7). `RentalOwnerVariationAutoMail` (a variation auto-approved inside tolerance) is another agent-acts-without-the-owner notice that was not on the list: it does not carry the buttons yet.
- **Contractor and crew links while on hold (found in the 13 Oct end-to-end walk):** the contractor's private link shows "On hold - please do not attend" and offers nothing to answer, quote, photograph or mark done; every one of those actions refuses with "This job is on hold - please do not attend until you hear from us." - never the owner's reason. The crew page shows the same banner and its "Mark work completed" line reads "on hold" (`CrewJobService::payload()['on_hold']`).
- **The time the agent records for the owner's go-ahead** is stored in the app timezone (`RentalAuthorisationService::toldAt()`); the stage 1 helper stored it as UTC, which the column reads back as app time, so the owner was shown a time two hours early.
- **Tests:** `tests/Feature/RentalFaultFlow/OwnerStopQueryTest.php`.

### 22.13 Stage 4 - "Who pays": a LOGGED RULING (AT-452, Johan 13 Oct 2026, QA1)

**A log, not a charge.** CoreX does no finances yet: this raises NO charge and creates NO invoice or ledger line. It records what was found, the cause, and the agent's ruling on who pays, and tells the owner and the tenant. The work order's existing `paid_by` ("who settles the contractor's bill", required when the office closes a job) is a different thing and is NOT touched; the new field is always labelled **"Who pays (ruling)"** where both could be seen.

**Checked first (13 Oct, read-only).** The "Try this first" self-checks are not separate records: they are the lines of a fault type's `first_aid_steps` text, cut up at display time (`RentalFaultTypePortalView::splitSteps`); the tenant's ticks are a snapshot on the fault (`tenant_self_checks`, step text + ticked). The contractor reports back on the secure link (`ContractorSecureLinkController::markDone`, a bare "Mark done"), the crew on the crew job link and the crew page (`CrewJobService::markCompleted`); an agent closes from the work order page. Owner and tenant read completion from the portal fault payloads (`ClientTenantRentalsController::faultReportSummary`, `ClientLandlordRentalsController`).

#### 22.13.1 Data (additive migration `2026_10_28_100000_rental_who_pays_log`)
- `rental_fault_type_causes` (BelongsToAgency, SoftDeletes): `rental_fault_type_id`, `kind` (`self_check` | `cause`), `label` (varchar 191), `default_payer` (`owner` | `tenant` | `shared` | `insurance_body_corporate` | NULL = no default), `sort_order`, `is_active`, `created_by_user_id`. One list per fault type. A `self_check` row is a thing the tenant can check ("Remote control battery is flat"); a `cause` row is what the contractor may find ("Roots or collapsed pipe"). Both are offered in the report-back cause list. A self-check row whose label equals (case/space/full-stop-insensitive) a step the tenant ticked also shows its default beside that tick on the agent's block.
- On `rental_work_orders`: `found_text` (text), `cause_id` (the list row picked, nullable), `cause_label` (snapshot of the picked label, so a later edit of the list never rewrites history), `cause_note` (free text), `reported_back_at`, `reported_back_by` (varchar 191: "<contractor/crew name> (contractor link)" or the agent's name), `who_pays` (the CURRENT ruling's payer, for the list/filter), `who_pays_ruling_id`.
- `rental_payer_rulings` (BelongsToAgency, **append-only**, never edited or deleted): `rental_work_order_id`, `rental_fault_report_id`, `payer` (`owner` | `tenant` | `shared` | `insurance_body_corporate`), `tenant_percent` / `owner_percent` (only for `shared`; 1-99, sum 100), `reason` (required), `evidence_path` / `evidence_name` (optional upload, private disk), `found_text` / `cause_text` / `suggested_payer` (snapshots at the moment of ruling), `ruled_by_user_id`, `ruled_at`, `superseded_at` / `superseded_by_id`, `owner_told_at`, `tenant_told_at`.
- **Seeded defaults** (the migration back-fills every existing agency's default fault types; `RentalFaultType::seedDefaultsFor()` seeds new agencies; both idempotent, neutral wording, agency may edit/archive its copy): Gate / garage motor - self-check "Remote control battery is flat" -> Tenant; Geyser - self-check "Gas geyser batteries are flat" -> Tenant; Appliance - self-check "Gas bottle is empty" -> Tenant; Blocked drain / toilet - cause "Foreign object in the drain" -> Tenant, "Roots in the pipe" -> Owner, "Collapsed pipe" -> Owner; EVERY default fault type - cause "Wear and tear" -> Owner. ("Something else (describe it)" is not a row: it is always offered last on the report-back, with a required free-text box, and has no default.)

#### 22.13.2 Report-back: What was found + Cause
- **Contractor link** (`/rentals/secure-link/<token>`) "Mark job done" and **crew job link / crew page** "Mark work completed": two new fields above the button on a job that came from a fault - **What was found** (required, 3+ characters) and **Cause** (a pick-list of that fault type's rows + "Something else (describe it)" last, with a free-text box that is required for "Something else" and optional extra for a picked row). A job NOT from a fault shows neither field and behaves exactly as before.
- **Agent**: the "Who pays" block on the work order carries the same two fields, so the agent can enter them when the contractor did not, or correct them (every change writes a work-order history line with who/when/from/to). Saved by `RentalWhoPaysService::recordFindings()`, the one writer for all four doors.
- Permission: the agent's save uses `rental_work_orders.manage_completion`; scope is the work order's own `guardRentalRecordScope` (own/branch/agency). The two public links are authorised by their token as today.

#### 22.13.3 The "Who pays" block (work order page, fault-linked work orders only)
Side by side, top to bottom: **What the tenant says they checked** (the ticks), **Confirmed with the tenant** (the agent's record, §22.3), **What was found**, **Cause**, **Suggested: Owner / Tenant / ...** (the picked cause's default and why: "From the default for <cause>"; nothing when the cause has none), then the ruling form: **Who pays** (Owner / Tenant / Shared / Insurance or body corporate; Shared asks "Tenant pays __ %" and shows the owner's share), **Reason** (required), **Evidence** (optional file, JPG/PNG/WEBP/PDF, 10 MB). **Save ruling** needs What was found and a Cause on the record first. Saved with the agent's name and time; a newer ruling replaces the current one, the older stays in **History** with who/when/what/why - never edited in place. Permission `rental_work_orders.manage_completion`. The fault page shows the current ruling read-only with a link to the work order, so both lease agents see it in both places.

#### 22.13.4 Who is told (Johan's ruling, 14 Oct 2026 - supersedes the first version of this section)
**The owner is always told** the ruling (email, under the existing owner-notice setting, and their portal fault view). **The TENANT is told ONLY when they have something to pay** (`RentalWhoPaysService::tenantInvolved()`): ruling **Tenant** (all of it) or **Shared** (their share and percentage only - never the owner's side). For **Owner** and **Insurance or body corporate** the tenant gets **no who-pays message at all** and **their portal shows no who-pays line** (`portalPayload($fault, 'tenant')` is null) - they only see that the repair is completed. Nothing is invoiced.
- **Owner reads:** "What was found: ... / Cause: ... / Who pays: You will pay for this. | The tenant will pay for this. | The cost is shared: you pay 60% and the tenant pays 40%. | Your insurer or the body corporate is expected to pay for this. / Why: <reason>. Your agency will be in touch about payment."
- **Tenant reads (only when they pay; factual, never accusing):** "What was found: ... / Cause recorded: ... / Who pays: You are asked to pay for this. | You are asked to pay 40% of the cost. / Reason recorded: <reason>. Your agency will be in touch about payment."
- **Replacing a ruling follows the same rule:** the owner is told the new ruling; the tenant is told when the new ruling asks them to pay (Tenant / Shared, flagged as replacing the earlier one) OR when the EARLIER ruling asked them to pay and the new one no longer does - then they get only "You are no longer asked to pay for this repair. Nothing more is needed from you." (what changed for them; no reason, because it may speak of the owner). Owner <-> Insurance changes tell the tenant nothing.
- **The staff line** on the ruling reads "Owner told <date>; tenant told <date>", or "tenant not told (nothing for them to pay)" when the tenant had nothing to pay. The evidence file is internal and never shown to owner or tenant.

#### 22.13.5 Fault-type screen (CRUD floor)
Fault type edit screen (`/corex/rental-fault-types/<id>/edit`), new panel **"Who normally pays"**, permission `rental_fault_types.create`, agency-scoped (another agency's type or row is a 404): two lists (Self-checks, Causes), each row **label + "If this was the cause, who normally pays" (Owner / Tenant / Shared / Insurance or body corporate / No default)**. Create, edit, **Archive**, **Restore** (a "Show archived" switch is the status filter); nothing is hard-deleted. Default order: the order saved (up/down by sort number). Search and paging are not offered: a type carries a handful of rows (stated cap 40 per type). Empty state: "No self-checks yet - add the things a tenant can check before reporting." / "No causes yet - add what a contractor might find."

#### 22.13.6 Work order list, print list and CSV/XLSX
New columns **Cause** and **Who pays (ruling)** (CSV/XLSX and print list too) and two filters: **Who pays (ruling)** (Any / Owner / Tenant / Shared / Insurance or body corporate / Not ruled yet) and **Cause** (contains, searches the cause label and note); both ride the same scoped query as the screen, print and export. Sort: unchanged (default reported date, newest first).

#### 22.13.7 Multi-agency, wizard, permissions
No HFC wording or ids; every list and default is the agency's own data (seeded neutral). **Not a wizard setting** (it is fault-catalogue DATA, like the first-aid steps, which are not in the wizard either) - recorded in `agency-onboarding-setup.md` §5.1 "Deliberately NOT in the wizard" for Johan. No new permission key (reuses `rental_fault_types.create` and `rental_work_orders.manage_completion`). Events: the ruling writes work-order and fault history lines; no new domain event (it changes no pillar state).

#### 22.13.8 Tests
`tests/Feature/RentalFaultFlow/WhoPaysRulingTest.php`: seeded defaults + idempotent; fault-type CRUD (create/edit/archive/restore, agency isolation, permission); report-back via contractor link and crew link (required fields, non-fault job unchanged); agent record/correct with history; suggested default from the cause and from a ticked self-check; ruling needs findings + reason; shared needs a split; replacement keeps history and supersedes; emails to owner and tenant (to can.assurance@gmail.com only) with the ruled wording; portal payloads for owner and tenant; list columns/filters/export; scoping (another agency's work order is refused); nothing is invoiced (no ledger/invoice rows written).

#### 22.13.9 As built (13 Oct 2026)
Everything above is built as written, with these exact points: the cause list also offers the fault type's self-check rows (causes first); the ruling is saved through `RentalWhoPaysService::rule()` (one writer) and the contractor link, crew link/page and the agent all record findings through `recordFindings()`; the owner email honours the existing owner-notice setting, the tenant email goes to every tenant on the lease with an address; the fault page shows the ruling read-only (`_who-pays-summary`). The "Who looks at faults first" intro on the property Rental tab is now one line: "Who the agent sees first on a fault for this property. Nothing is sent automatically." Not changed: the work order's `paid_by`, any approval logic, any charge or invoice.

### 22.14 End-to-end walk fixes - owner and contractor screens, one date rule (13 Oct 2026, QA1)
Found walking the deployed QA1 as tenant, agent, owner and contractor (`/tmp/at452/walk-defects.md`); the owner- and contractor-facing items are fixed here.
- **The owner's "decision needed" email (a FAULT)** reads "Your agent needs your go-ahead": it names who the agent would send and what the visit costs ("<name> charges no call-out fee, so the visit costs you nothing." / "Call-out fee: R750."), says "You can say yes, choose someone else, or decline. Nothing is spent: you will be asked again before any repair cost is approved.", and its button reads "Open it and answer". A work-order quote mail keeps its own wording.
- **One press: "Yes, send <name>".** When the agent suggested someone, the owner's decision screen is three equal buttons: **Yes, send <name>** (API `handled_by = proposed`: the agent's suggested contractor, or "the agency arranges it" for our own team; refused in plain words when nobody was suggested), **Choose someone else** (the earlier form) and **Decline** (the reason stays required). The owner's optional comment sits above them. No "You approved" step before they decide: the step reads **Your decision**.
- **The progress line follows the route actually taken.** Nothing decided yet: ONE neutral step ("Your agent is arranging who comes to look") - no "Sent to owner for approval / Owner approved". Sent to the owner: both steps, the second reading "Your decision" (tenant: "Waiting for the owner") until answered. An agent wrote the owner's phone answer down: no "sent to owner" step at all (nothing was sent), the step reads "Recorded on your instruction". Sent straight to inspect: unchanged ("Your agent told you / Someone is coming to look").
- **The owner is told a decision once and never "decided by the owner on the portal link".** The owner payload's `decision.how` is gone; the "Your decision" section appears only for a decision the OWNER pressed ("You asked for someone to look and quote. On <date time>. Your agent will arrange it and tell you. You will be asked again before any repair cost is approved."). A decision an agent wrote down is carried by the one sentence ("Authorised by your agent <name> on your instruction, given by <phone call> on <date time>.") and nothing repeats it.
- **One date rule.** South African format everywhere a person reads a date - "13 Oct 2026", "13 Oct 2026 05:37" (24-hour), appointments "Wed 14 Oct 2026, 10:00" - from ONE helper pair: `App\Support\AppDate::day()/dateTime()` (emails, authorisation sentence) and the portal's `fmtDay()/fmtDateTime()` (never `toLocaleString`, which printed "10/13/2026, 5:37:10 AM").
- **One storage rule (the UTC/SAST class).** A time a person types is read in the agency's timezone and stored in the APP timezone (`AppDate::parse()/store()`), because every `datetime` column is read back in the app timezone; `->utc()` stored 05:43 as 03:43. Fixed at every place the fault flow did it: `RentalAuthorisationService::parseToldAt()` (Record the owner's decision; Resume; `toldAt()` now delegates to it), `RentalFaultAuthorityController::confirmWithTenant()`, and the two appointment controllers (`RentalWorkOrderController::setAppointment`, `ClientLandlordRentalsController::workOrderAppointment` - `RentalWorkOrderService::setAppointment()` already converted, so those were harmless; they now use the helper). Rows written before this fix keep their old (two-hours-early) values on QA1.
- **The contractor's page, in order:** the job; "This is a visit to look at the problem and give a quote. Any repair work is agreed with the owner separately, before it starts."; **Can you take this job?** (Accept / Decline - gone once answered, replaced by the answer); **Upload a quote** ("Details of the quote (needed if you do not attach a document)" / "Quote document (needed if you do not write the details)"; hidden after a decline); then, only once accepted, **Upload after photos** and **Mark job done / What did you find? / What caused it?**. The server holds the same order (photos and Mark done before accepting: "Please accept the job first."). The contractor's email carries the same visit sentence.
- **The agency's display name** (`Agency::displayNameFor()`): the agency's own name, never the half-filled `trading_name` ("Johan and Elize Properties T/A"). Used on the contractor page / email (`RentalMaintenanceMail`, `RentalContractorJobService`), the email-link pages and the "On hold" mail. The other rentals mails still read `trading_name ?: name` (crew link, tenant completion check, dispute, lease status, portal header) - they should adopt the helper (or the agency's trading name should be completed).
- **Re-walk fixes (same day):** the owner's three buttons are equal in HEIGHT as well as width (one border box for every button in a pair or stack); a contractor's link opened after the job is finished says "This job is finished - thank you" instead of "expired or replaced"; the two-hours-early times already stored on QA1 (agent-typed `told_at`, `decided_at`, `tenant_confirmed_at`, recorded before the fix) were corrected by hand (`+2h`, only rows with a typed time - seconds 00 - at least 115 minutes before the moment they were recorded).
- **Agency display name - the whole class (same day).** `Agency::displayNameFor()` is the ONE place the name a customer reads is built; `Agency::publicBrandingFor()['name']` reads it too. Covered: every rentals mail (`LeaseAgreementStatusMail`, `RentalDisputeSentBackMail`, `RentalLandlordDisputeMail`, `RentalTenantCompletionCheckMail`, `RentalJobCardCrewLinkMail`, `RentalJobCardCrewCompletedLandlordMail`, `RentalWorkOrderTenantMail` / `OwnerMail` / `SupplierMail` / `AppointmentMail`, `RentalMaintenanceMail` and so the contractor job / quote / work-order mails, `RentalCrewStandingLinkMail`, `RentalPortalInviteMail`, `RentalJobOnHoldMail`), the shared email footer (`BaseSignatureMail`), the portal header (`RentalPortalOverviewService`), the inspection report branding, all nine rentals PDFs (`RentalDocumentPdfService`), the rental application PDF and decline email, the secure-link and email-link pages. `tests/Feature/RentalFaultFlow/AgencyDisplayNameClassTest.php` scans the rentals code and templates and FAILS if any builds the name by hand (`->trading_name`, `->agency->name`, `$agency->name`); a new rentals email must use the helper.
- **Contractor page, a quote already chosen (13 Oct 2026, demo walk).** When the agency has selected this contractor's quote (not declined, not replaced) the page says "Your quote of R6,800.00 is with the owner. The agency will tell you when you can go ahead." (or "has been approved" / "has been received") instead of offering the upload form again. A declined quote brings the form back, so a revised quote can be sent. `tests/Feature/RentalFaultFlow/OwnerContractorWalkFixesTest.php`.
- **One supplier label and one supplier search (13 Oct 2026, Johan: "Steve" of "Cape Town Contractors" was identified only as "Steve").** `AgencyServiceProvider::displayName()` / `labelFor()` is the ONE label wherever a supplier is searched, picked or listed: business name first, then the contact person - "Cape Town Contractors - Steve"; one name only when only one exists; once when both are equal. `AgencyServiceProvider::scopeMatching()` is the ONE search: business name, contact person, email, phone, the supplier's trade and every trade ticked on it (by code or label), so "Cape", "Steve", "contractors" and "plumb" all find him. Used by: the Supplier Directory (search and main label), the fault contractor picker (label, "Send <name> to inspect", call-out notice, search box text), first responders, the work order screens and lists, the quote/invoice supplier dropdowns, the deal pipeline supplier dropdown (`label` in the payload) and the JSON picker search. The stored contact name is unchanged. `tests/Feature/DealV2/SupplierLabelAndSearchTest.php`.
- **Not here (other lanes):** a contractor can still finish a job nobody authorised the cost of (cc6 - `RentalWorkOrder::complete()` / `ContractorSecureLinkController::markDone`); staff fault / work order wording and duplicates (cc6); the `&#9744;` in the Who-pays block and test suppliers in the pickers (cc5 / data).
- **Tests:** `tests/Feature/RentalFaultFlow/OwnerContractorWalkFixesTest.php`, `tests/js/portal-shell.test.mjs` (`fmtDateTime`; section order now includes `who-pays`).

*(The section below is the owner-settings build (cc4). Its code comments and tests cite it as "section 22.x" from before the numbering collided: they mean this §23.x. Section 22 above governs the flow; §22.2 there names this resolver as the only reader of the three settings.)*

### 22.15 The go-ahead goes out by itself, and says exactly what it covers (Johan, 14 Oct 2026, QA1 - items 1 and 2)

**Found first (14 Oct, read-only).** The old "go-ahead" was only the agent's button on the work order (`corex.rental-work-orders.assign-supplier`, labelled "Send work order to contractor" / "Resend work order to contractor"): `RentalWorkOrderService::sendContractorWorkOrder()` emailed the work-order PDF with the line "The owner has approved it, so you can go ahead." - no amount, no link. Nothing was sent when the owner approved on their link (work order 154: approved 08:57, the agent pressed the button at 08:59).

**1. Automatic.** `RentalGoAheadService::onCostAuthorised()` is called by every writer of a cost authorisation (`RentalAuthorisationService::authoriseCost()` - the agent inside the repair/emergency limit; `mirrorOwnerQuoteDecision()` - the owner's press on their link AND an agent recording the owner's instruction, both via `RentalWorkOrder::recordApproval()`), **after the surrounding transaction commits** (`DB::afterCommit`). It sends the go-ahead email to the work order's contractor when ALL of these hold: the record is an *authorised* cost record and still the active one; the work order is an outside-contractor job (not our own crew, not the owner's own contractor), open (not completed/cancelled) with a contractor; the authorised amount covers the selected quote's owner-facing amount (whole cents; exactly equal passes) - or, for a small job with no quote, the amount the agent typed; the selected quote is from that contractor.
- **Once per authorisation record** (unique dispatch key `wo<id>:goahead:auth<record id>`); a newer record (e.g. a higher approval) sends a fresh go-ahead for the new authority. Never throws: a mail problem leaves a `failed` dispatch row, a history line and an agent notification, never an undone authorisation.
- **Not sent** (and said so in the work order's history, `job_not_sent`): the owner has the job on hold (the pause text), or the contractor has no email on file (dispatch row `not_sent`; the agent is told; adding an email and pressing the button sends it). An authorisation that does not cover the quote sends nothing and writes nothing.
- **Logged:** one `work_order_sent` history line "Go-ahead sent automatically to <contractor> (with the job link) - <who authorised, in the agent's words>" (e.g. "Authorised by <owner> on their link on <date>", "Authorised by agent <name> under the owner's repair limit of R___", "Authorised by agent <name> on the instruction of <owner>, given by phone call ..."). A reported work order moves to ordered when the go-ahead goes out.
- **For cc6 (display only - no new columns):** read the work order's `rental_job_dispatches` rows with `reason = 'go_ahead'` (columns `status` sent | failed | not_sent, `sent_at`, `destination`, `recipient_name`; newest row is current). `RentalContractorJobService::statusFor()` already counts a `sent` go-ahead as "sent". The agent's button stays and is renamed **"Send the go-ahead again"** (same mail, same wording).

**2. The wording** (`RentalGoAheadService::wordsFor()`; the contractor's OWN figure - their quote, never the agency's selling price): with a selected quote: "Your quote of R850.00 for <job> at <address> has been accepted. You may go ahead. Please note: any work that would take the cost above R850.00 must first be authorised by the owner before that work is done - tell us through the link below and wait for the answer." Small job with no quote: "You may go ahead with <job> at <address> up to R300.00. Please note: any work that would take the cost above R300.00 must first be authorised by the owner ...". The email (`emails.rentals.maintenance.contractor-work-order`) carries that sentence in bold, the work order PDF, an **"Open the job"** button to the contractor's private link (a fresh link; the previous one stops working - one live link at a time) and the line "Use your private link to tell us the work is done, send photos and your invoice, or ask for extra money before doing extra work." The agency name is the display-name helper (`Agency::displayNameFor`, no "T/A"). There is no WhatsApp/SMS variant of the go-ahead (WhatsApp is only the agent-opened first job message, §19), so there is nothing to keep in step. Old / grandfathered work with no cost record keeps the previous plain sentence.

**Tests:** `tests/Feature/RentalFaultFlow/GoAheadTest.php` (owner link, agent in limit, agent recording the owner's instruction, small job, once per record and again for a newer one, owner hold, no contractor email, not covered, the button and the same mail).

### 22.16 The owner sets the repair appointment ONLY when there is no tenant (Johan, 14 Oct 2026, QA1 - item 4)
**Rule.** The owner may set or change the repair appointment from their portal only when nobody lives in the property. With a tenant they only SEE it; the agent arranges it with the tenant. **One question, one place:** `RentalWorkOrderService::propertyHasTenant($wo)` - true when the work order's lease, or any other lease on the same property, is `active` (an empty / expired / ended property = false).
- **Enforced in the service the portal route calls**, not just hidden: `RentalWorkOrderService::setAppointment()` throws "Your tenant lives in the property, so your agent arranges the appointment with them. You can see it here once it is set." when the caller is an owner (a contact) and there is a tenant; `ClientLandlordRentalsController::workOrderAppointment` returns it as a 422. The agent (a user) is never affected.
- **The owner's card** (`RentalWorkOrderClientViewService`): new key `owner_tenant_in_property`; `owner_can_appoint` is false when it is true. The portal shows the control only when `owner_can_appoint`; with a tenant it says the sentence above (`data-owner-appointment-tenant`) and still shows the appointment line once the agent has set it; without a tenant and before approval the old "can be set once the job is approved" line stays.
- **The staff work order page** line follows the same condition: with a tenant "The tenant is emailed when you set or change this. A tenant lives in the property, so only you arrange the appointment - the owner sees it on their portal." (`data-appointment-agent-only`); without one "... The property has no tenant, so the owner can also set it from their portal." (`data-appointment-owner-too`).
- **Tests:** `tests/Feature/RentalFaultFlow/OwnerAppointmentTest.php`; `NightFlowFixesTest` and `BackHalfWalkTest` (owner-contractor route) now expect the owner NOT to book with a tenant living there (the old rule: "the owner may also set the appointment" - W6, 8 Oct - is replaced for a let property).

### 22.17 The contractor finishes the job on their private link (Johan, 14 Oct 2026, QA1 - item 3)
**Found first (14 Oct, read-only).** `/secure/work-orders/{token}` (`ContractorSecureLinkController`) already offered accept / decline, "Upload a quote", and - only after accepting - "Upload after photos" and "Mark job done" (with "What did you find?" + cause, §22.13.2). It offered NO invoice, NO request for extra money, did not show the appointment, and nothing on it reacted to the go-ahead. The go-ahead email had no link at all until §22.15.

**The page once the cost is authorised** (`unlocked` = `RentalGoAheadService::jobOpenForWork()`: an outside-contractor job, still open, with cost authority - an authorisation record, or old / grandfathered work; never while the owner has the job stopped). Before that it is exactly as before (quote card; the old after-photo + done controls after an accept, and a line saying invoice / extra money open once the owner has authorised the cost). Once unlocked the quote card is replaced by:
1. **"You may go ahead"** - the authorised amount (the contractor's figure) and the rule that anything above it needs the owner first.
2. **Appointment** - the date/time (agency timezone) and the note; "No appointment has been set yet. The agency arranges it and will tell you." Display only (the contractor cannot set it).
3. **Photos** - "Before or during the work" (stored as the work order's `in_progress` photo phase) or "After the work" (`completed`; also the default for a post with no choice, as before). They land in the work order's Photos by phase.
4. **Ask for extra money** - amount (R) + reason, BEFORE doing extra work. `RentalApprovalGateService::raiseContractorExtra()` makes a variation (origin `contractor`, status awaiting the owner, the contractor's own sentence in `note`, extra = the contractor's amount plus the agency's fee on it if the agency adds one) under the **hard lock**; the owner is emailed ("The contractor says: ...") and sees the reason on their card; both lease agents are told. One open request at a time. The page then says **"Waiting for the owner ... Please do not do the extra work until we tell you the owner has agreed."**; afterwards it shows "the owner agreed / did not agree" for the last request. An approved request raises the authorised amount (the work order's `approved_amount`, as every variation).
5. **Your invoice** - invoice number, date, amount, file (agency file-type / size rules shown). `RentalWorkOrderInvoiceService::upload()` now accepts no staff user: the row has no `uploaded_by_user_id` and the history says "<contractor> on their link"; it appears under the work order's **Supplier invoices** (not shared with the owner until the agent shares it, as before). **THE HARD LOCK:** `RentalGoAheadService::invoiceLockMessage()` refuses an invoice when the invoices already filed plus this one come to even one cent more than `contractorCeiling()` (the authorised `approved_amount` less the agency's own fee on the selected quote); exactly equal passes. Refusal wording: "This invoice cannot be accepted: with it, your invoices come to R___, which is above the R___ the owner authorised. No amount above the authorised amount can be accepted. If the extra work was really needed, use "Ask for extra money" first and wait for the owner's answer." Old work with no authorised amount in money has no ceiling.
6. **The work is finished** - the button is **"Work finished"** (found + cause for a fault job, §22.13.2; the photos and notes are sent as before). **It reports the work finished and NOTHING more** (Johan's 9 Oct rule L2, ruling received 14 Oct 2026 - the earlier text of this item, "`complete()` straight away", was spec drift and is withdrawn): `RentalCompletionService::recordContractorLinkDone()` opens the same completion round as every other finished report (`reported_via = contractor_link`, history "via contractor link"), the work order stays **in progress**, and it is shown to the agent as **"Finished - awaiting agent check"** (the work-order screen's badge and Next line, the Command Centre queue row, the work-order list tile + filter `awaiting_check`, the fault's agent progress line). **It does NOT close the job, does NOT send the owner's final statement, does NOT tell the tenant "Work completed"** - those happen only when the AGENT closes it from the work-order screen ("Mark the work as complete": confirms the work, rules who pays - owner / tenant / deposit / not yet paid; "bill the tenant" is still not built, only the ruling field) exactly as every close does today (`complete()` -> `RentalWorkOrderClosed` -> owner final statement; tenant status mail; the fault resolves). The agent is told once through the normal alert popup ("Finished - awaiting your check", event `rental_work_order.contractor_responded`); pressing the button again changes nothing and tells nobody again. The contractor's page then says "you have told us the work is finished - the agency will check it and close the job" in place of the button; the invoice and photo controls keep working until the agent closes; if the agent sends the work back (§17.10 dispute) the button returns. The tenant's optional check follows the agency's existing setting, as for any finished report. No waiting-window threshold was added - nothing times out; the agent closes when they have checked.
- **Agents notified** (event `rental_work_order.contractor_responded`, the one rule for who they are; a quote is `rental_work_order.quote_received` - see §19.2): photo uploaded (before/after), invoice sent, extra money asked, work finished ("Finished - awaiting your check"). Every action is also on the work order's history.
- **Unchanged:** the link stops working when the job is completed or cancelled (and when it is given to another contractor); on hold = "On hold - please do not attend" and nothing can be filed or asked.
- **Not in this build:** the contractor is not emailed when the owner decides an extra (the page shows the answer next time they open it); no contractor-side edit/withdraw of an invoice or request (the agent handles those on the work order).
- **Tests:** `tests/Feature/RentalFaultFlow/ContractorFinishTest.php`.

### 22.18 Who reports "the work has started" / "the work is finished": the TENANT when someone lives there, the OWNER when nobody does (Johan's ruling, 14 Oct 2026, QA1 - extends item 4)
**Rule.** On the owner's portal the old pair "set / change the appointment", "the work has started", "the work is finished" was the wrong way round. Now (`RentalWorkOrderService::propertyHasTenant()` decides - an active lease on the property):
- **A. A tenant lives there:** the OWNER only watches - the appointment, progress, quote, authorised amount, photos, invoices when shared - and can still approve / decline / stop / query. No set/change appointment, no "started", no "finished" (§22.16 for the appointment; this section for the other two).
- **B. The TENANT gets the actions** on their Work orders view (`rentals/portal/shell.blade.php`, block `data-tenant-progress`): **"The contractor has arrived - work has started"**, and **"The work is finished"** with an optional note and photos, with **"It is not fixed"** (a reason of 5+ characters, optional photos) as the alternative. API: `POST /api/v1/client/rentals/work-orders/{id}/progress` (`ClientRentalWorkOrdersController::tenantProgress`), body `action` = `started` | `finished` | `not_fixed`, `note?`, `photos[]?` - the tenant's own work order only.
- **C. Effect.** *started* -> `RentalWorkOrder::startProgress()` (status in progress; history `tenant_progress`: "Work started - reported by tenant <name> (the contractor has arrived)"); the lease agents are told. *finished* -> **records the tenant's confirmation and does NOT complete the work order** (completion carries the final cost under the hard lock, what was found and who pays - the agent's "Mark the work as complete" does that): `RentalCompletionService::openRound()` with `reported_via = tenant_portal` and `tenant_is_reporter` (no tenant-check mail - there is nobody to ask) then `respond(fixed = true)` as the tenant, which mirrors the answer onto the work order and tells the agent ("Tenant confirmed the work is done"). Photos are stored as `completed`-phase work order photos. *not fixed* -> with a "finished" report waiting it is the usual "tenant says not fixed" dispute row (agent told, job stays open); otherwise a `tenant_progress` history line "Tenant <name> says it is NOT fixed: ..." and the agents are told. The job stays open either way.
- **For cc6 (the Next line / staff page): read** `rental_work_orders.tenant_confirmed_at` + `tenant_confirmed_fixed` (true) + `tenant_confirmed_by_contact_id` + `tenant_confirmation_note`, and the newest `rental_work_completion_rounds` row for the work order: `reported_via = 'tenant_portal'`, `reported_by_label = '<name> (tenant)'`, `opened_at` (when), `outcome = 'confirmed'`. `RentalWorkOrderClientViewService::reportedFinished($wo)` is true (the same "Work reported finished" state the owner's / contractor's report uses), so the existing "reported finished, waiting for the agent" line appears; "Tenant says the work is finished <date>" = `tenant_confirmed_at` while `reportedFinished` and not completed. A tenant "not fixed" with no round is the work order history line (`update_type = tenant_progress`).
- **D. No tenant (an empty property):** the owner keeps the appointment control and "started" / "finished" - the same handlers (`recordOwnerProgress`), the owner as the reporter.
- **E. When they appear - and are enforced.** ONE rule, `RentalWorkOrderService::progressBlock($wo, 'owner'|'tenant', $action)` (null = allowed, else the plain sentence): not the agency's own crew (their job card carries it); not a closed job; not while the owner has it stopped; the owner only without a tenant and the tenant only with one; **only once the cost is authorised** (`hasCostAuthority`, the owner's own contractor exempt) **and the go-ahead has gone** (`goAheadGone()`: a counted `go_ahead` dispatch, or the agent's own send logged as `work_order_sent`; the owner's own contractor exempt); not "finished" again while one is already waiting for the agent. The portal payload flags (`owner_can_start/finish`, `tenant_can_start/finish` + the reason in `owner_progress_note` / `tenant_progress_note`) come from the same function the controllers refuse with, so hiding a button is never the only guard. A contractor with no email never gets a go-ahead, so nobody can press "started" until the agent sends it themselves.
- **Notification event:** the existing `rental_work_order.owner_progress` event carries the tenant messages too (title/body say "Tenant ..."); a separate event key would need a catalogue migration and was not added.
- **Tests:** `tests/Feature/RentalFaultFlow/TenantOwnerProgressTest.php` (A-E, including a direct POST by the wrong role and by another agency's tenant); older tests that had the owner report progress with a tenant living there, or before the go-ahead had gone, were updated to this rule.

### 22.19 The tenant is never told "Owner approved" when the owner was never asked (14 Oct 2026, QA1 - cc5's walk of lease 106, faults 379-380)
**What happened.** The agent sent someone to look (inside the owner's no-fee authority, so the owner is TOLD, not asked). The tenant got the email "Update on your fault report: Owner approved". **Trigger:** `recordWorkOrderRaised()` tells the tenant the moment the work order exists; `FaultInspectionService::sendToInspect()` only marked the fault "arranged by the agent" AFTER the work order was created, so the mail was written from a fault that had a work order and no owner step - the progress line's old rule read that as "owner approved" (`owner_decided` -> `owner_approved`), and the once-only ledger (`tenant_progress_notified`) then kept the true line from ever being sent.
**Rule.** "Owner approved" is shown to a tenant only when an owner decision exists (the owner's own press, or an agent recording what the owner instructed). A work order with NO owner step - the owner neither asked nor recorded - is the agent going ahead: step 3 "Your agent is arranging the repair", step 4 by what it was: "Someone is coming to look" (sent to look and quote), "Repair approved by your agent" (inside the owner's repair limit - approved, without claiming the owner did it), "Repair arranged" (emergency). Fixed in two places: (1) `sendToInspect` marks the fault arranged INSIDE the authority callback, before the work order is raised, so the first tenant mail is already in the true words; (2) `RentalFaultProgressService` itself no longer derives "Owner approved" from a work order alone (a fault with a work order, no decision and no send to the owner counts as arranged), so no ordering of writes can bring the wrong words back. `approval_mode = one_step` belongs to rental APPLICATIONS (an agent approving directly), not to faults - faults have no such switch. Tests: `AuthorityFlowTest` (the tenant is never told Owner approved after a send-to-look; a work order with no owner step reads "approved by your agent", never the owner's; a real owner decision still reads "Owner approved").

### 22.20 cc5 re-walk fixes (14 Oct 2026, QA1)
- **The agent's private note for the contractor (Johan: "it is for the contractor only").** New column `rental_work_orders.appointment_contractor_note` (500). The appointment form has two notes: **"Note for the tenant (optional) - the owner and the contractor can read it too"** (the existing `appointment_note`, unchanged: tenant email, tenant + owner portal) and **"Note for the contractor (optional) - private: the tenant and the owner never see it"** (outside-contractor jobs only). The contractor note is shown ONLY on the contractor's private link ("Note from the agent") and in the contractor's appointment email ("Note for you from the agent: ..."), and on the agent's own screen/history. `RentalWorkOrderService::setAppointment(..., $contractorNote = false)`: `false` = leave it alone (the job card's booking and the owner's portal do not know about it), null / '' clears it; changing only this note counts as a change. Tests `WorkOrderFlowTest` (mail + both portals + payloads + the shell file), `ContractorFinishTest` (page + email).
- **Portal probe is silent.** The portal page asks `/client-auth/tab-token` on every open; it now sends `probe: true` and a browser with nobody signed in gets `200 {token: null, needs_signin: true}` instead of a red 401 (any caller without `probe` still gets the 401). Tests `PortalTabSigninTest`, `tests/js/portal-shell.test.mjs`.
- **Lease list tile/filter = the page's status.** A signed renewal that only waits for its start date ("Signed - starts 1 Nov 2027", lease 119) is no longer counted as a Draft: new tile + filter `status=signed_awaiting_start` ("Signed - starts later"), `Lease::scopeSignedRenewalAwaitingStart()` is the SQL twin of `isSignedRenewalAwaitingStart()`, the Draft tile/filter exclude it, and the CSV/xlsx export and print-list read `displayStatus()` too. Test `RenewalWalkFixesTest`.
- **Application review has ONE submit button** (the header's, Johan's placement rule), 30px like the others; the side-column twin is gone, and with one-step approval the Approve / Decline pair replaces it (as before). Test `SherryWalkFixesTest`.
- **"Already signed" page tells the truth**: once everyone has signed the copy waits for the agent's approval - "Your agent checks the signed document first, and you will receive the fully signed copy once they have approved it"; before that, "once all parties have signed and your agent has approved it". Test `AlreadySignedWordingTest`.
- **Data (QA1 only):** the duplicate fault type "Pool pump is not running" row 1560 (unused) was archived (soft); 1559 stays. The double-press guard already refuses a second live type with the same name, so no merge tool was added.

### 22.21 cc5 second-walk fixes (14 Oct 2026, QA1)
- **The appointment waits for the owner's go-ahead (quote-first, §22.17 / §22.18 E).** `RentalWorkOrderClientViewService::appointmentBlock()` = the old "approved" rule PLUS: a job that has only been SENT (status ordered) no longer counts as cleared - it needs a **cost authority** (`RentalAuthorisationService::hasCostAuthority`: the owner approved, an agent authorisation under the owner's limit, an emergency, old grandfathered work) **or an authorised look-and-quote visit** (an active `inspect` authorisation). The owner's own contractor and the agency's own crew are exempt. Enforced in `setAppointment()` (so no mail goes to tenant or contractor), in the staff form (`data-appointment-locked` says why), in the owner's `owner_can_appoint`, and in what tenant/owner are shown (an appointment booked before this rule stays hidden from them until the cost is authorised; the office sees what is stored). Test `AppointmentWaitsForCostAuthorityTest`.
- **The contractor's link always shows an appointment that exists** (the mail says the link "shows the details"): date/time, the tenant note, and the agent's private "Note from the agent" - also before the cost is authorised.
- **Lease "Next" step:** an in-inspection already begun (draft / in progress / awaiting signature) is the current step - "Continue in-inspection" (opens that inspection) - never a second "Start in-inspection". Test `LeaseHubTest`.
- **Move-out notice vs renewal (lease 115):** the two cannot both stand. `LeaseRenewalService::renewalBlockedByNotice()` refuses creating a renewal (every capture / copy-forward / template path goes through `createRenewalTerm`; the renewal screen turns the agent back with the sentence) while a notice is on file, and `noticeBlockedByRenewal()` refuses recording a notice while a renewal is in progress or signed - the agent withdraws one first (Reverse notice / cancel the renewal). Data that already contradicts itself shows an amber banner on the lease screen (`Lease::noticeRenewalConflict()`, `data-qa="lease-notice-renewal-conflict"`). Test `RenewalWalkFixesTest`.
- **Relisting asks the last rent:** when a tenancy ends and the property goes back on the market (`PropertyStatusFollowsLeaseService::restorePreLetStatus`) a rental property's `rental_amount` (the asking rent) becomes the ended lease's last rent (R14,500 -> R15,660), written to the property's history; the agent can still change it on the property. Sale listings are untouched. The notice-time "readvertise while the tenant is still there" path is unchanged. Test `LeasePropertyStatusTest`.
- **PIN hint** on the inspection / document signing screen links to `My Portal > Profile` (its first section is Signature & PIN) and says so. **Agent's inspection email** is in the second person with the exact place: "open the inspection in CoreX, find the Agent line and press 'Sign with PIN'", plus an "Open the inspection in CoreX" button. **Command Centre pagers** stack the "Showing x to y of z" line above the buttons (and wrap long page rows) at 1366 / 1280.
- **Mailpit banner link:** the setting is `MAILPIT_UI_URL` (config `mail.mailpit_ui_url`, default empty - no built-in link). On this box `mail.demo1.corexos.co.za` IS this box's own catcher (its nginx vhost proxies to 127.0.0.1:8025, basic auth or the rig IP), so QA1's value was already pointing at the right Mailpit; nothing changed.

## 23. "How I want faults handled" - the OWNER's settings, per property (AT-452 family, Johan 12 Oct 2026, QA1)

**What and why.** What an agent may authorise without asking is the OWNER's decision about the OWNER's property - not an agency setting and not something an agent can assume. This section records, per rental property, the owner's three answers, who confirmed them and how, and gives the fault flow ONE place to read them. Agency defaults are only pre-filled PROPOSALS; until an owner (or an agent, with evidence) has recorded values, nothing is standing authority and every decision goes to the owner.

### 23.1 Data (additive migration `2026_10_27_100000_owner_fault_handling_settings`)
- `rental_property_fault_settings` - ONE row per property (`property_id` unique, BelongsToAgency): `repair_limit`, `emergency_limit` (decimal 10,2, null = no value), `callout_mode` (`yes` | `no` | null) + `callout_limit` (decimal, only when yes), `status` (`not_set` | `captured_by_agent` | `confirmed_by_owner` | `disputed`), `version` (+1 on every change - the "I do not agree" link names the version it was written for), capture evidence (`capture_basis` = `phone_call` | `whatsapp` | `email` | `signed_mandate` | `carried_over`, `capture_occurred_at`, `capture_note`, `capture_file_path`/`_name`, `captured_by_user_id`, `captured_at`), `confirmed_at`/`confirmed_by_contact_id`, `disputed_at`/`disputed_by_contact_id`/`dispute_note`, `last_sent_to_owner_at`.
- `rental_property_fault_setting_changes` - append-only history: `action` (`captured` | `owner_changed` | `confirmed` | `disputed` | `carried_over` | `sent_to_owner` | `change_requested`), actor (`user_id` or `contact_id`, or system), `old_values`/`new_values` (JSON), `status_after`, `basis`, `occurred_at`, `note`, `created_at`. Nothing is edited or deleted.
- Two notification event types (in-app + email, default on): `rental_fault_settings.disputed` ("Owner does not agree with the fault-handling settings") and `rental_fault_settings.change_requested` ("Owner asks to change who looks at faults first").
- **Carry-over (same migration):** every property with an existing `rental_no_approval_spend_threshold` gets a row: `repair_limit` = that value, status `captured_by_agent`, basis `carried_over`, note "Carried over from the existing work terms", history `carried_over`. NO owner is emailed. Everything else stays `not_set` (no row). The old column is NOT removed or changed; the existing approval gate keeps reading it until the fault flow moves onto the resolver.

### 23.2 The ONE resolver cc6 reads - `App\Services\Rentals\RentalOwnerFaultSettings::for(Property $property)`
Stable names (do not rename): `status(): string` (`confirmed_by_owner` | `captured_by_agent` | `disputed` | `not_set`), `hasStandingAuthority(): bool` (true only for `confirmed_by_owner` and `captured_by_agent`), `repairLimit(): ?float`, `emergencyLimit(): ?float`, `calloutAllowedUpTo(): ?float`, `firstResponders(RentalFaultType|int|null $type = null): Collection` (cc5's `Property::firstRespondersFor`, read-only), `proposed(): array` (agency defaults for pre-filling screens), `values(): array` (what is stored, for screens), `statusLabel()`, `capturedBy()`, `confirmedAt()`.
**`null` always means MUST ASK THE OWNER.** `repairLimit()`, `emergencyLimit()` and `calloutAllowedUpTo()` return null when status is `not_set` or `disputed`, when no value is stored, and (call-out) when the owner said No. A stored R0 is a real value (agent may authorise nothing without asking). Fault-flow code never reads the columns.

### 23.3 Wording (identical everywhere)
Section: **"How I want faults handled"**. Fields: "Repairs my agent may authorise without asking me: up to R___"; "Emergency repairs my agent may authorise without asking me: up to R___"; "May my agent send a contractor who charges a call-out fee without asking me first?" **Yes, up to R___ / No**; read-only line "Who looks at faults first" (cc5) with **"Ask for a change"** (tells both lease agents; no data changes). Status words: Confirmed by owner / Captured by your agent - not confirmed yet / Owner does not agree / Not set yet.

### 23.4 Owner portal
Per property on the owner's Properties tab (390-wide first): the section above, the status line (who, when, how), pre-filled from stored values else from the proposals (marked "suggested"), **Confirm these** (saves all three as `confirmed_by_owner`; a changed value is logged `owner_changed`), the change history in plain words, and the first-responder line. A link `?as=owner&handling=<propertyId>` opens it. Property scope: the owner's own properties only (`RentalPortalScopeService::landlordProperty`).

### 23.5 Agent side (property Rental tab, under the work terms)
Panel "How the owner wants faults handled": current values + status, history, and the capture form: the three values, **How the owner told us** (phone call / WhatsApp / email / signed mandate), **When**, **Note (required)**, optional upload; **Save for the owner and tell them**. Status becomes `captured_by_agent`. Banner while status is `captured_by_agent` or `disputed`: "Owner has not confirmed these yet" / "Owner does not agree" with **Send to owner to confirm**. Permission `rental_work_orders.manage_work_terms`; scope `authorizeProperty`; settled rentals only.

### 23.6 Emails and the dispute
Every agent capture, and every "Send to owner to confirm", emails each owner of the property at once (to can.assurance@gmail.com in tests): each value, who captured it, the evidence basis, and two equal buttons **"Open my settings"** (PortalLink, owner view) and **"I do not agree with this"** (a SIGNED link tied to contact + property + version; the page asks one question and only the confirm press - never the email scanner's GET - records the dispute). A dispute sets `disputed`, stores who/when/why, alerts both lease agents (in-app through the registered event, and a direct email to each, because in-app alone depends on each agent's own notification switches), and the resolver returns "must ask" for everything until the owner confirms or the agent re-captures with NEW evidence (re-capture clears the dispute, status `captured_by_agent`, version +1). A link written for an older version is told so and sent to the portal.

### 23.7 ONE place for the repair limit (Johan, 12 Oct 2026, evening)
The repair ("no-approval") limit is edited ONLY in "How the owner wants faults handled". (The work-terms panel (§17.6.2) was removed on 13 Oct 2026 - its only remaining field, the variation tolerance, was ruled out by §22.6.) It no longer accepted a limit; a posted `no_approval_limit` is ignored. `properties.rental_no_approval_spend_threshold` is no longer written by anything; it stays as a read-only FALLBACK for a property whose owner has no answer yet. Every reader of the limit goes through two functions, and both now ask the owner's settings first (`RentalOwnerFaultSettings::limitInForce($property)`: the owner's repair limit once status is anything but `not_set`, R0 when it is "must ask", e.g. disputed; null while there is no answer, so the old fallback chain - legacy column, then agency default - still applies): `RentalWorkOrderSetting::thresholdFor($property)` and `RentalApprovalGateService::termsFor($property)`. Everything else (the gate's checks, job card stage, fault show/limit line, quote selection, the lease screen) already calls one of those two. The new flow (§22) reads `RentalOwnerFaultSettings::for($property)->repairLimit()` (null = must ask); once the flow stops needing the fallback, the legacy column and the not_set branch above can be removed.

### 23.7a Not in this build
The fault flow's use of the resolver (cc6). The gate's own rules are unchanged - only where the number comes from. Not an agency setting, so no Setup Wizard step (per-property data like the work terms; add to the "Deliberately NOT in the wizard" list).

### 23.8 Tests
`tests/Feature/RentalFaultFlow/OwnerFaultSettingsTest.php`: resolver for every status incl. dispute fallback; carry-over; agent capture (evidence required, scope, permission, email); owner confirm/change via the portal API (scope, validation, history); signed dispute link (version, scanner GET records nothing, alerts both agents); first-responder change request; banner and "Send to owner to confirm".

## 24. The staff work order screen - layout rework (Johan, 13 Oct 2026, QA1; demo to a prospect at 12:00)

Johan: the work order page "needs a rework, it is a complete mess - so hard to read anything or see what is going on". **Layout and wording only**: no route, service, rule or authority-flow change. Every action that existed still exists and posts to the same place (`corex.rental-work-orders.*`). `resources/views/corex/rental-work-orders/show.blade.php` and its partials; the presentation read lives in ONE class, `App\Services\Rentals\RentalWorkOrderScreen::build()` (a pure read of existing model state - it writes nothing and sends nothing).

### 24.1 Top to bottom
A. **Header** (unchanged): title, stage badge, property, Download PDF, All work orders, the lease tab strip, the PAUSED BY OWNER banner when it applies.
B. **"Where this job is"** strip - one row: Reported > Contractor sent > Quote in > Cost approved > Go-ahead sent > Work in progress > Completed > Who pays ruled. Done = ticked with its date ("13 Oct 2026 08:57"), current = highlighted, later = greyed. Steps that do not apply are LEFT OUT: own crew has no Contractor sent / Quote in / Go-ahead sent; owner arranges has no Contractor sent / Quote in / Cost approved / Go-ahead sent; "Who pays ruled" only for a job that came from a fault. A cancelled job has no current step.
C. **"Next" bar** directly under the strip: ONE sentence (what is waiting, on whom) and at most ONE primary button, derived from existing model state only. The button either submits a form that was already on the page (`<button form="...">`: `wo-send-job-form`, `wo-go-ahead-form`, `wo-start-progress-form`) or opens the panel that holds the control and presses it (`data-wo-go`, `data-wo-click`), or is a link. States and what they say: no contractor ("Choose the contractor"); contractor chosen, job not sent ("Send the job to X"); sent, no answer ("Waiting for X to accept or quote." / "Send the job again"); contractor declined; no quote selected; quote inside the owner's repair limit ("Authorise R___"); quote above the limit or no limit ("The owner must approve it - sent to the owner <date>." / "Record the owner's decision"); declined by the owner ("Capture a quote"); authorised, go-ahead not yet sent ("Send the go-ahead"); go-ahead sent ("Mark in progress"); in progress ("Complete"); reported finished; disputed; extra work waiting for the owner (hard lock, "Record the owner's reply"); owner pressed Stop (PAUSED BY OWNER; "Open the fault to resume it"); completed with who-pays unruled ("Rule who pays"); completed; cancelled. Own-crew jobs read the crew price ("Authorise R___" / "Record the owner's decision") then point to the job card.
D. **Two cards, facts only, no forms**: *The job* (description, tenant with phone, contractor with phone / email / call-out fee, appointment or "Not booked", from fault #, reported date) and *The money* (selected quote with supplier, date and document link; the authorised amount with who / how / when in one line; the owner's repair and emergency limits - printed ONLY here, with who said so; extra work waiting; final cost and invoices; who pays once ruled). They stack under 900px. Nothing is printed in both a card and a panel (the old "Owner approval: Approved" fact line is gone).
E. **Collapsible panels** (`<details>`, component `x-wo-panel`), each with a one-line summary in its header so a closed panel still reads; only the panel for the current step is open (errors reopen the panel they belong to): Contractor (own crew: Job card), Quotes (the capture form sits behind "Capture a quote" once a quote exists), Approve the cost (unchanged from stage 2; its limits block is not repeated - `$cdHideLimits`), Appointment (the form that used to sit at the very top), Completion (mark in progress, complete, contractor reports done, the tenant check), Who pays, Supplier invoices (the form behind "File an invoice"), Photos, History (closed; header shows the latest entry). **Edit, Archive and Cancel work order** sit at the very bottom, small and secondary.
F. **Rules**: no helper paragraphs (at most one short line per panel); nothing printed twice; buttons in a row are the same height; one money format ("R1,000.00"); dates as "13 Oct 2026 08:57" in the agency's time zone; no jargon ("job was: ...", "this is the one that stands" are gone).

### 24.2 The go-ahead (shared with the automatic send - cc5)
The screen only SHOWS it, from existing data: the latest `work_order_sent` entry in the work order's updates, else `ordered_at`. Contractor panel: "Go-ahead sent <date>" and "Send the go-ahead again" as a secondary button; before it is sent, "Send the go-ahead" is the Next bar's button. The send logic (`assign-supplier` -> `RentalWorkOrderService::sendContractorWorkOrder`) is NOT touched here; when the go-ahead goes out automatically the moment the cost is authorised, it must keep writing the same `work_order_sent` update and moving the work order to `ordered`, and this screen reads it with no change.

### 24.3 Tests
`tests/Feature/RentalFaultFlow/WorkOrderScreenStatesTest.php`: every state renders and the Next bar carries the right sentence and the right ONE button; the strip leaves out steps that do not apply; the current panel is open and the others closed; the limits are printed once; no "printed twice" wording; one money and date format; Cancel sits after the last panel. Older tests that asserted the removed "What happens next" wording were updated to the new sentences.

### 24.4 ONE date-and-time control (Johan, 13 Oct 2026)
The browser's date-time popup (`<input type="datetime-local">`) is gone from the work order and fault screens - Johan: the popup "is just blank" with no way to confirm. Every date-time input uses the Blade component `<x-date-time-field name="..." :value="'Y-m-d\TH:i'">`: a native **date** field (the popup closes when a day is picked) and a **time dropdown** (07:00 to 18:00 in 30-minute steps, plus "Other time" which reveals a typed HH:MM), side by side, 38px high, aligned with the "Set appointment" button. It still posts ONE field under the same name, `YYYY-MM-DDTHH:MM`, so nothing server-side changed: the controllers read it in the AGENCY time zone (`App\Support\AppDate::parse`) and store it in the app zone, so 14:30 picked is 14:30 on the agent's page, in the tenant's email (`RentalWorkOrderAppointmentMail`) and in the portal payload (`2026-10-20T14:30:00+02:00`). Used by: the appointment, "When did they tell you?" in *Record the owner's decision* (work order and fault), the hold/resume form, and the tenant-picture time. (There is no shared date-picker component elsewhere in CoreX; the native date field is the same one every list filter uses. The owner portal's own appointment box is a separate app and is unchanged.)

### 24.5 Completing the work from "go-ahead sent" (Johan, 13 Oct 2026)
The agent is not on site and the contractor never presses "in progress", so once the cost is authorised and the go-ahead has gone the Next bar's button is **"Mark the work as complete"**: it opens the completion form in the Completion panel (final cost, notes and, for a job from a fault that has no findings yet, **what was found + the cause in the SAME form** - saved first, so a bad cause stops with nothing completed). Completing straight from `ordered` is allowed (the model always allowed it); history reads **"Completed (was: go-ahead sent)"**. "Mark in progress" stays as a small secondary button. Gates unchanged: nothing completes before its cost is authorised; a final cost even R0.01 over the authorised amount is refused, raises the request for the extra to the owner and the job stays open; the owner's hold blocks it. The strip ticks "Work in progress" through when the job was completed directly. After completion the Next bar goes to "Rule who pays".

### 24.6 Corrections after Johan's first look (work order 158)
Contractor summary follows the real state ("quote received R6,800.00"); "Authorise R___" is only SHOWN as a button when the repair limit can cover it (or an emergency limit could - then it is shown, and enabled by the emergency tick), otherwise only "Record the owner's decision"; the helper line "Nothing is approved until you press a button." is gone; the History header shows the last meaningful event, never "Note added". **Tenant says (read-only, from the work order's existing tenant-check columns `tenant_confirmed_at / tenant_confirmed_fixed / tenant_confirmed_by_contact_id / tenant_confirmation_note`; cc5's tenant-portal "work is finished" / "it is not fixed" must write these, or tell cc6 the new field names):** fixed -> "Thandi Mokoena says the work is finished (13 Oct 2026 14:10)." + "Mark the work as complete"; not fixed -> "Tenant says it is NOT fixed - <note>" + "Send the contractor back" (the existing send-the-job-again action).

### 24.7 One source of truth for a contractor's trade (Johan, 13 Oct 2026)
Johan: contractor types (Settings -> Service types) "should be linked to the contractor specialty" in the Supplier Directory. Cause: there were TWO unlinked lists. (1) `AgencyServiceType` (per agency: Electrical COC, Plumbing, Security, ...) ticked per supplier in the pivot `agency_service_provider_service_types` - the fault "Suits <fault type>" match (`RentalFaultContractorService::classifyFor`) read ONLY this. (2) The fixed `specialty` enum (`SupplierDirectoryController::SPECIALTIES`: plumber, electrician, other, transfer_attorney ...) - what the add form's Trade/Specialty dropdown and the directory SPECIALTY column read. A supplier added through the dropdown carried only (2), so it never matched.
**Now:** a contractor's trade IS its ticked service types. The add form's Trade dropdown offers the agency's active service types ("Your trades", value `type:CODE`) beside the fixed property-sales roles (transfer/bond attorney, conveyancer, bond originator, external agency); an agency with no service types still sees the old list. Saving `type:CODE` ticks that type on the supplier and derives the legacy `specialty` through ONE bridge, `AgencyServiceProvider::legacySpecialtyForType()` (Plumbing -> plumber, Electrical COC -> electrician, Electric Fence -> electric_fence, Beetle -> entomologist, Gas -> gas, anything else -> other), so the deals pipelines / DR work orders keep reading `specialty` unchanged. The directory SPECIALTY column shows a contractor's ticked service types (attorneys and bond originators keep their role word); a supplier with none ticked shows the old value. The fault match uses the same ticks, and a supplier with NO ticks but an old fixed trade that equals the matching type still suits. Migration `2026_10_29_400000_backfill_supplier_service_types_from_old_specialty` ticks (per agency, additive, idempotent) the matching type for every active supplier that has no ticks at all; `specialty` is never changed, nothing is deleted. Agency-scoped throughout. Test: `SupplierTradesFromServiceTypesTest`.

### 24.8 Service type GROUPS - "Your trades" vs "Property sales", agency-configurable (Johan, 13 Oct 2026)
**Today (before this change):** the supplier form's two group labels, "Your trades" and "Property sales", and the property-sales roles (transfer attorney, bond attorney, conveyancer, bond originator, external agency) were hard-coded in `_add-form.blade.php` / `SupplierDirectoryController::SPECIALTIES`; a service type added in Settings always landed under "Your trades".
**Now:**
- `agency_service_type_groups` (agency_id via `BelongsToAgency`, code, label, sort_order, is_active, soft delete). Seeded per agency with the two defaults (exact labels kept). Settings -> Service types (`/deals-v2/settings/service-types`, "Groups" button): add, rename (the stored `code` never changes), reorder (up / down), archive, restore. **A group with service types in it cannot be archived** ("... still has N service types in it. Move them ... first - a group can only be archived when it is empty."); a type restored into an archived group comes back into "Your trades".
- `agency_service_types.group_id` - **required** on the add and edit forms; list has a Group column, a Group filter and sort (order / name / group / stored value). Every existing type was put in "Your trades" by migration `2026_10_29_500000_service_type_groups` (additive, re-runnable, deletes nothing).
- **The property-sales roles are real rows** in "Property sales" (editable name, group, order, archive) via `agency_service_types.legacy_specialty`. **How the stored value was kept:** a supplier's `agency_service_providers.specialty` is exactly what it was (`transfer_attorney`, `bond_attorney`, `conveyancer`, `bond_originator`, `external_agency`); the role row's `legacy_specialty` records which stored value it stands for, and the Trade dropdown posts that raw value for a role (so `kindFor`, the attorney / bond-originator rules, `ATTORNEY_SPECIALTIES`, `NON_MAINTENANCE_SPECIALTIES`, the DR attorney matching and the deals pipeline pickers see no change). A role row is **not a work type**: `AgencyServiceType::active()` and the new `work()` scope exclude it, so the pipeline step config, work-order dropdowns, supplier tick boxes and the rental fault match never see it; `offered()` (active, roles included) feeds only the Trade dropdown. Renaming a role changes its label only.
- **Supplier add / edit Trade dropdown** is grouped by the agency's groups (`AgencyServiceTypeGroup::dropdown()`: one `<optgroup>` per active group with its offered types, in the agency's order); a trade posts `type:CODE` (ticks that service type and derives the legacy `specialty`, section 24.7), a role posts its stored value. **Supplier Directory:** new Group filter (a supplier is in a group when one of its ticked types is, or its stored role is); the Specialty column shows a role by its (editable) name; **sorting by Specialty now sorts on the word the column shows** (ticked type label, else role name, else the old value) - the leftover "sorts on the old stored value" is fixed.
- Not in the Setup Wizard: service types themselves are not a wizard step today (spec `agency-onboarding-setup.md` has none); groups follow them. Johan's call whether they should be (non-negotiable 10a).
- Tests: `ServiceTypeGroupsTest` (10). Also fixed `FirstResponderTest` "contractor picker lists the first responders on top": it assumed the plumber supplier sat under "Other contractors"; since 24.7 a supplier whose old trade is plumber **suits** a plumbing fault, so the heading is "Suits <type>" (the test now asserts the first responders sit above "Suits").


### 22.19 Sherry walk, third batch (13 Oct 2026, cc1) - the maintenance chain (tests `SherryWalkRound3FaultFlowTest`)
- **The contractor is told.** `RentalWorkOrderService::emailContractorUpdate()` sends a short plain note (`RentalContractorUpdateMail`, AS the responsible agent, agency mail path) when (a) the appointment is set or moved - "The appointment for this job is now <date time> (<note>)" - and (b) the owner answers the contractor's own request for extra money - "Extra work approved ... You may do it" / "not approved ... Please do not do it". It goes to the supplier's first service contact, else the supplier's email; a job with no contractor, an internal crew job, an owner's-own-contractor job, or a contractor with no email is quietly skipped (the agent already sees the change on the work order). This replaces the "Not in this build" lines of §22.17 for these two cases.
- **The job email promises photos only when there are some** ("look at the photos" was static text).
- **The appointment note** field is labelled "Note for the tenant (optional) - the owner and the contractor can read it too": it is tenant-facing by design (placeholder "the plumber will call 30 minutes before"); anything meant for the contractor alone goes in the work order's own instructions.
- **One money format in the fault flow: `R1,350.00`.** The go-ahead words, the contractor page's "authorised" line, the extra-money history line and the repair-limit sentence used `R1 350.00`; the owner/tenant portal wrote `R 1350.00` (`toFixed(2)`). All now `R` + thousands comma + two decimals. (Whole-rand call-out fees and the owner repair-limit chip keep their own short form, `R1 200`.)
- **Tenant/owner portal buttons are one height.** `.btn` had `border:none` and `.btn-outline` a 1px border (47px vs 49px); every `.btn` now has a 1px transparent border, box-sized.
- **A fault type cannot be added twice.** "Pool pump is not running" was in the tenant's list twice (QA1 types 1559 and 1560, created in the same second by one login): `RentalFaultTypeController::validated()` refuses a second live type with the same name in the agency (case and spaces ignored; archived names do not count, so a name can come back), on add and on rename, and the Add form shuts itself after the first press. The two existing rows are Johan's and were left as they are - archive one on `/corex/rental-fault-types`.
- **RESOLVED 14 Oct 2026 (Johan's ruling; was OPEN): the contractor's link no longer closes the job.** "Mark job done" became **"Work finished"**: it reports the work finished, the job stays open as **"Finished - awaiting agent check"**, and the owner's final statement and the tenant's "Work completed" go out only when the agent closes it (§22.17 item 6). Tests: `ContractorFinishTest`, `FaultResolvedOnEveryCompletionRouteTest` (route 2 + two new: finish notifies only the agent; the agent's close sends the owner statement + tenant mail), `WhoPaysRulingTest`, `ContractorSecureLinkTest`, `ReportedFinishedWordingTest` (tile + filter).

## Contractor page shows the fault's photos (14 Oct 2026, rental re-walk fault 2)
**What was wrong.** The tenant's photo was stored on the fault (`rental_fault_report_photos`), but `/secure/work-orders/{token}` showed "no photos". A work order is created with only the photos the agent ticked **Share** for the owner (none are ticked by default), and the page read only those copies - so an un-ticked photo, or one added after the work order was made, never reached the contractor.
**Fix.** The contractor's "Photos of the problem" card lists every photo of the fault (read-only, shared viewer, the office's marks and notes), merged with any `reported` photo put on the work order itself and never twice (matched on the stored path). The owner's own view is NOT changed: the owner still sees only what the agent shared. The crew job page now follows the same rule (Johan, 14 Oct 2026: "crew job page shows every fault photo"); test `tests/Feature/RentalCrewLinks/CrewPageShowsFaultPhotosTest.php`. Tests: `tests/Feature/RentalFaultFlow/ContractorPageShowsFaultPhotosTest.php`.

## 25. Contractor link + email: tenant, access contact, Open in Maps (AT-459, Johan 15 Oct 2026, QA1)
The contractor's job email and `/secure/work-orders/{token}` page now carry the tenant's name with a tap-to-call number (only when the agency allows crews and contractors to see the tenant - the existing crew-link setting, default off), who lets them in (the work order's access contact: name, tap-to-call number, note) and an **Open in Maps** link built the same way as the crew page's (the property's GPS point, else the address). **Crew-only stays crew-only:** the contractor link gets no parts, labour, hours or quote-building. The access contact moved from the single "Access notes" box to three fields on the work order; full design in rental-work-orders.md §14.32. The email previously read the PROPERTY's access note; it now reads the work order's, falling back to the card's and then the property's, so nothing already typed is lost.

## Audit fix 2026-10-18 (A-L10/L11)
Fault report `store()` accepts only a live fault type of the user's own agency (agency-scoped `Rule::exists`); the "Report a fault" `create()` form resolves `property_id` / `lease_id` through `visibleTo()` (404 outside the user's scope). Test: `RentalFaultReportScopeGuardTest`.

### Owner "Stop this" / "Query this" links expire (audit 2026-10-18, A-L5)
The signed links in the authorisation-to-owner email (and the forms on the page they open) are `temporarySignedRoute` links valid for 60 days - the same as the "I do not agree" link in the fault-settings email (a code constant, `RentalOwnerNoticeController::LINK_DAYS`; no agency setting exists for either). After that the owner uses the same two buttons inside their portal. Test: `OwnerStopQueryTest`.

## 26. Quote-first: "owner said send a contractor out for a quote" (Johan's ruling 12 Oct 2026; built 19 Oct 2026, QA1, AT-452)
**The ruling.** At the fault stage nothing is authorised, because the cost is unknown. The agent sends a contractor for a QUOTE ONLY and tells the owner (link or phone). Authority given that way is authority to send a contractor to quote - never to do the work. Once the quote lands the amount goes to the owner (or is inside the owner's limit) under the ordinary cost-authority rules (§22.6).
**What already existed (found 19 Oct, no new capture built).** "Record the owner's decision" (§22.5) *is* the capture: the owner's channel (phone call / WhatsApp / email), when, what they said, optional proof, saved under the agent's name, emailed to the owner, written to the fault history and the authorisation ledger (Decision 1, point "who goes to look"). It never writes a cost authority. The owner's link already reads it as "Authorised by your agent {name} on your instruction, given by {WhatsApp} on {date}" with the agent's note and "Query this". The older "Get a quote first" owner button of the archived round-3 branch (`archive/at452-round3-approvals-2026-10-12`) was superseded by this and is not revived.
**What this build changed.**
1. The agent's option now reads **"Spoke / WhatsApped owner — owner said send a contractor out for a quote"** (the channel picker - phone / WhatsApp / email - is unchanged; "Declined" is unchanged). The "a decision is already recorded" warning uses the same words.
2. **New plain state "Waiting for the contractor's quote"**, for the OWNER and the AGENT only (data: `config/rental-work-order-stages.php` key `awaiting_contractor_quote`, and `config/rental-fault-progress.php` key of the same name). `RentalWorkOrderClientViewService::awaitingContractorQuote()` is the one rule: an OUTSIDE contractor's work order, already SENT (status ordered), with a standing authorised "who goes to look" record (on the work order or its fault), NO cost authority yet, and no live quote (a quote that is deleted, replaced or declined does not count). It drives the work-order badge (`stageKey`) and the fault's progress line (the current "sent to contractor" step is relabelled, like "On hold"). Before the job is sent the badge stays "Created"; the owner's own contractor and the agency's crew never show it; an appointment, once booked, still shows as "Appointment set".
3. The moment a quote lands (or the cost is authorised) the state goes and the existing cost-authority flow takes over (owner "Needs your decision" / approved within limit / etc.) - unchanged.
**Consequence for older wording.** A job the owner approved on their link and the office has sent, with no quote yet, used to read "Sent to contractor for scheduling"; it now reads "Waiting for the contractor's quote" (the owner's approval IS the look-and-quote authority, §22.17). `OwnerFaultScreenTest` was updated for this.
**Tenant note (Johan, 19 Oct - D1/Q2 stand).** The tenant is never told about quotes: they keep the plain work-order stage "Created" and the plain progress line "Sent to contractor for scheduling" (the stage key and progress flag are not even set for the tenant, so nothing in the tenant's data says "quote"). The tenant label entries in the two config files hold those plain words.
**Tests.** `AuthorityFlowTest` (wording and channel picker; captured-instruction -> waiting line for every audience -> quote lands -> line gone; not for an unauthorised job or the owner's own contractor).
**Setup Wizard (non-negotiable 10a).** No new setting - the wording is fixed text and the rule has no agency switch, so nothing to surface in the wizard.

## 27. "Other - not on the list" on the problem list (Johan, 19 Oct 2026, QA1, AT-452)
**Where the list comes from.** The agency's own catalogue (`rental_fault_types`, section 2), read by the tenant link (`ClientTenantRentalsController::faultTypes` -> the "What's the problem?" select in `rentals/portal/shell.blade.php`) and by the agent's "Log a fault" form (`rental-fault-reports/create.blade.php`).
**What was built.** "Other - not on the list" is always the LAST choice on both screens, on top of whatever the agency has. It is not a catalogue row (a new agency has nothing to seed; an agency cannot archive it): the fault report carries `fault_type_other = true` and no catalogue type (`rental_fault_reports.fault_type_other`, default false; every older report is untouched).
- **Tenant link.** Choosing it goes straight to one required box, "What needs attention?", plus photos as normal. No suggested self-checks, no "That fixed it" (the server refuses a self-resolved "Other"). The title is made from the start of what they wrote ("Other: ...").
- **Agent form.** The same last choice; the description is already required. The old blank choice now reads "No type chosen".
- **Agent screen.** The fault shows an **Other** badge with the tenant's own words unchanged. While it is open, "Change type..." + "Save type" gives it a proper type of the agency's own list; the change is written to the history ("Type of fault changed - from "Other" to "<type>"", with who and when). Only an Other fault, only while open, only to this agency's live types.
- **Contractor warning.** An Other fault never reads as "no contractor loaded for this type" (one rule, `RentalFaultContractorService::hasContractorFor`): the agent picks the contractor / type when sending, and the send-without-contractor line is never written for it.
- **Owner side.** Unchanged: the owner sees only the agent's version (title, wording, photos the agent shared); the tenant's raw words never reach the owner link.
**Tests.** `OtherFaultTypeTest` (tenant report, required description, never self-resolved, normal report unchanged, Other last on both forms, agent form, re-classify + history + refusals, no-contractor rule, owner sees only the agent's version).
**Setup Wizard (non-negotiable 10a).** No setting - the choice is always there and has no agency switch, so nothing to surface in the wizard.

## 28. Fault screen simplified, 19 Oct 2026 (office side; Johan approved the audit of 19 Oct, QA1, AT-452)
**Why.** The agent's FAULT screen was "up and down": no Returned/Next bar, the real buttons 1.7 screens down behind four big cards, the owner's answer 700 px below the card it feeds, a dead end after "Declined", and the same facts typed again at four later steps. The WORK ORDER screen already has the stage line, the Next bar and folded panels (§24) - it only gets the carry-through and small fixes below. **Replaces §22.3 ("the agent's fault page, top to bottom") for the layout; every rule in §15, §22 and §26 stands.** Office (agent) side only: no tenant or owner screen, email or portal changes. Audit with every file: `/root/audit-fault-office-2026-10-19.md`.

### 28.1 The fault page, top to bottom (new)
1. Header (unchanged) and the lease tab strip. The banners (tenant fixed it, requested by owner, owner's comment, arranged by agent, PAUSED BY OWNER and the owner's questions) stay where they are.
2. **Stage strip** "Where this fault is": Reported > Sent to owner > Owner decided > Work order > Closed (steps that do not apply are left out; a cancelled fault has no current step). Same `.wo-strip` look as the work order.
3. **Returned | Next bar** (the job card's pattern: `.wo-next` with two halves, `data-land-on-next` on the bar, so a saved action lands on it). Pure read, `App\Services\Rentals\RentalFaultScreen::build()` (`steps`, `returned`, `next`, `panels`) - it writes and sends nothing. **Every stage has a next action:**

| Stage | Returned (what came back last) | Next (primary button) |
|---|---|---|
| Reported / under review | "Reported <date> by <tenant> via <channel>" | "Send to the owner to authorise" (opens "Who goes to look first?", which sits directly under the bar); secondary "Record the owner's decision" |
| With owner | the owner's question / comment / pause when there is one, else "Sent <date> - the owner has not answered" | "Record the owner's decision" |
| Owner approved (appoint) | "Owner approved by <how> on <date>: '<their words>'" (the decision text moves here, out of a card 700 px lower) | "Create work order" (the green form keeps its place) |
| Owner declined | "Owner declined by <how> on <date>: '<their words>'" | **"Owner changed their mind - change the decision"** (opens the same popup; §28.5) |
| Work order raised | "Work order #N: <its own stage>" | **"Open work order #N"** |
| Closed / cancelled | what closed it | "Open the work order" when there is one, else none (nothing is left to do) |

4. **Folded panels** (`<x-wo-panel>`, the work order's own component): finished stages fold to ONE summary line; the current stage's panel is open; History is collapsed. Panels: *What the tenant reported* (summary: type, channel, date - open only in the first stage), *What the owner will see* (open only before it is sent; afterwards "Sent <date>"), *Who goes to look first?* (open in prepare / with owner), *Authorisation records*, *Photos*, *History*. The empty "Owner decision" shell is gone while nothing is decided; "Outcome" is folded while a work order is live ("set automatically when the work is completed - change"), and its dead "What happened" box is not asked for.
5. The status badge is printed once; "Owner approved" is stated once in the bar.

The strip/bar/panel CSS lives in ONE shared partial (`corex/rentals/_stage-screen-style.blade.php`) used by the fault page. (The work order and job card pages still carry their own copies; folding those into the shared partial is a follow-up, left alone because the job card screen is another lane's file this week.)

### 28.2 Carry through, do not retype (every value prefills from the step before, stays editable, and can be added to)
- **Owner's copy.** The owner's title is no longer compulsory: blank means "use the fault's title" (server fallback, no 422, no silently aborted send button). The owner's words prefill from the tenant's description; clearing them on purpose is kept (the old `??` re-filled the tenant's raw text). The card shows the owner's copy as a preview with "Edit wording"; the visible input is the agent's note (the "add to it" box). Both send buttons still save it.
- **Contractor picked once.** The pick made in "Who goes to look first?" travels into "Record the owner's decision" (hidden fields) and is stored on the record, so the "Create work order" form opens with it chosen; pre-fill order: the owner's pick, then the proposed one, then the property's first-to-look default. The work order and the later steps use it. **Superseded in part by 29.R3 (20 Oct 2026): the "proposed one" now counts only when an agent recorded the owner's decision with it, never when it was merely proposed to the owner.**
- **Completion notes** prefill from the latest "contractor reports done" note; **access contact** has a "The tenant is the access contact" button that fills name and phone, and the duplicate access fields leave the work order's foot "Edit" form; **who-pays reason** prefills with the suggestion sentence; **final cost** prefills invoices total, else the authorised amount, else the selected quote's owner-facing amount.
- **A blank final cost can no longer be saved at completion** for an outside contractor's job (`cost_amount` required; the owner's-own-contractor job keeps it optional) (it emailed the owner a final statement with no amount). Our own crew's job is closed from its job card and is unchanged. The hard lock (§22.6, not even R0.01 over) is unchanged.

### 28.3 One owner note can cover both decisions - "Same call: also approves the cost up to R___"
In "Record the owner's decision" (go-ahead to look and quote), a second option **"Same call: the owner also approved the cost up to R___"** (only offered on an approval, never on a decline). It is stored on the same authorisation record (`rental_authorisations.also_cost_up_to`, additive migration, nullable). It authorises nothing by itself - §26 stands: at this stage the cost is unknown, so the owner's go-ahead is authority to look and quote only. When a quote is in on the work order and its owner-facing amount is **at or below** that figure, the work order's Approve-the-cost panel shows "The owner approved up to R___ on the same call (<how>, <date>)" with ONE button **"Use it - approve R___"**; pressing it writes the ordinary cost record (basis `owner_instruction`, the SAME note, how and when, amount = the figure) through `recordOwnerCostDecision`, so every existing rule applies (an amount below the quote is refused; the hard lock). A quote above the figure shows "the quote is above what the owner approved on the call - ask the owner" and the normal "Record the owner's decision". Nothing is recorded without the press. Replacing the fault-level decision replaces the figure with it.

### 28.4 Tenant confirmation, a phone quote
- **Confirmed with the tenant:** how and when only; **"What the tenant said" is optional** (the history line then reads "Confirmed with the tenant by <how> on <date>" with no quote marks).
- **A phone quote** is allowed on the work order: amount + a "Quoted by phone" tick + the date, with **no document and no written details**. The server rule (`document OR details`) becomes `document OR details OR quoted-by-phone`; no new column - a phone quote saves its details line as "Quoted by phone on <date>", so every list that shows a quote reads it, and the PDF/owner copy carry the same words. The owner still authorises the cost exactly as for any quote (§22.6).

### 28.5 A recorded "Declined" can be changed from the screen
The server already allows a newer agent-recorded decision until a work order exists (`reopenFaultDecision`; the owner's own press on their link is final). The screen now offers it: stage *declined* shows the Next button "Owner changed their mind - change the decision" opening the same popup (`_record-owner-decision`, which no longer needs "Who goes to look first?" around it). The popup's warning says what will be replaced; the history and the Authorisation records keep BOTH entries and mark the older "replaced". Refused (with the existing reason) when the owner decided on their link or a work order exists.

### 28.6 Photos to the owner: no change
The agent ticks which photos go to the owner; none go unless ticked (Johan's 8 Oct ruling, §15 F2). The "tick all / none" control is not built.

### 28.7 Small fixes (audit P5)
1. **Trade type** is removed from the standalone "New work order" form (it was never used to pick the contractor).
2. **`paid_by` on a fault work order** is no longer posted by the Complete form (it stayed `not_yet_paid`; "Who pays" is the real record). The server defaults it to `not_yet_paid` for a job from a fault; a job not from a fault keeps the select with "Owner" preselected.
3. **One findings entry point:** "What was found" + "Cause" are entered ONCE - in the Complete form, or in the Who-pays panel when that was skipped - and become read-only once a ruling is built on them (the contractor and crew links still record theirs). Before a ruling the panel still offers "Correct what was found".
4. **The foot "Edit" form** of the work order no longer carries the access-contact fields (they live in the Appointment panel only).
5. **Labels match the server:** in the Complete form "What was found" is optional (the server never required it; the Who-pays panel asks for it before any ruling) and no longer says "(required)"; when it is entered the cause must be chosen (the server refuses otherwise). The Who-pays panel's own findings form keeps "(required)" because the server does require both there.
6. **Cause** gets a blank first option "Choose..." so skipping it never silently records "Wear and tear".
7. **Photos panel default type follows the stage** (before the work: "Reported (before)"; once the work is done: "Completed").
8. **Owner-version validation rules live in ONE place** (`RentalFaultReport::OWNER_VERSION_RULES`), used by the fault controller and the authority controller.

### 28.8 Required typed fields across the flow (straightforward case)
Before - **10**: fault title, fault description, owner's words (go-ahead), quote amount, quote details-or-document, owner's words (cost), appointment date, appointment time, what was found (label said required), who-pays reason. After - **7**: fault title, fault description, owner's words (once; **6 effectively** when the cost was approved on the same call, because the cost step then needs no words), quote amount, appointment date, appointment time (what was found is now optional; the who-pays reason prefills; the quote's details-or-document is not needed for a phone quote). Also stops being compulsory: the owner's title and "What the tenant said". The scroll to the next action drops from up to 1.7 screens to under one.

### 28.9 Not in this build (said so, not forgotten)
The work order's own Returned half, an Appointment step on its bar and moving Invoices before Completion (audit P4); deriving the fault title when the agent leaves it blank, pre-selecting the active lease on the two "new" forms, and the standalone work order's contractor picker (audit P3) - none were in Johan's approved list. Dead markup in `_raise-work-order-form` and the old `/approval` route are reported, not changed.

### 28.10 Tests, permissions, wizard
`FaultScreenNextBarTest` (the Returned/Next text and button per stage, panels open/folded), `FaultOwnerFlowTest` updated, plus tests for Declined -> change, the same-call figure and its one-press use, the phone quote, a blank final cost refused, the optional tenant note, the contractor carry and the owner title fallback. No new permission key (existing `rental_fault_reports.*` / `rental_work_orders.*` gate every control). **Setup Wizard (10a): no setting is added, nothing to surface.** Multi-agency: all wording neutral.

### 28.11 As built (QA1, 19 Oct 2026)
`RentalFaultScreen` (stage, strip, Returned, Next, panel summaries; one pure read) and `corex/rentals/_stage-screen-style`. The decision popup (`_record-owner-decision`) is now also usable on its own (declined stage). Migration `2026_11_18_100000_add_also_cost_up_to_to_rental_authorisations_table` (nullable, additive). New route `corex.rental-work-orders.cost.use-same-call` (`RentalWorkOrderCostController@useSameCall`, permission `rental_work_orders.record_approval`); service `RentalAuthorisationService::sameCallOffer()` / `useSameCallApproval()`; `RentalWorkOrderInvoiceService::suggestedFinalCost()`. The "First to look at this: X" sentence above the picker is gone (the ticked row says it); the owner-decision card moved into the "Authorisation records" panel as the full record. Tests: `FaultScreenSimplifiedTest` (21), and `AuthorityFlowTest` / `FirstResponderTest` updated for the optional tenant note, the blank owner title and the removed sentence.

## 29. Fault / work order / crew audit fixes (Johan approved every finding of the 19 Oct 2026 end-to-end audit, QA1, AT-473)
**Source:** `/root/rental-e2e-2026-10-19.md` section 2C/2D (item numbers C-A1 ... C-H1, D18-D26 below refer to it). **Rules:** every timing is an agency setting with a default; the bug CLASS is fixed, not the instance; no new permission key. **Agency settings added (Settings -> Rental portal AND the Setup Wizard, rule 10a):** `owner_reminder_hours` (24), `owner_second_reminder_hours` (24), `owner_queue_after_hours` (24), `link_renew_on_use` (on), `quote_max_amount` (R1,000,000); one existing entry changed default: `notify_landlord_on_crew_completion` is now OFF (see C-C4). Package texts follow (P1 chasing/queue/alerts, P2 public links, P3 portal/media, P4 staff side).

### 29.P1 Chasing the owner, the queue, the alert types, the owner-finished rule, the updated statement (audit fixes, 19 Oct 2026, QA1) - package P1

**C-A1 - an owner who never answers is chased (`rentals:chase-owner-decisions`, every 30 minutes, `RentalOwnerChaseService`).** For every fault still *awaiting the owner* (status `awaiting_approval`, owner decision pending, `sent_to_owner_at` set) and NOT paused by the owner (`RentalOwnerHoldService::activeFor`): after `owner_reminder_hours` (agency setting, default 24) with no answer the owner is emailed a REMINDER (the same "your agent needs your go-ahead" mail with a "A reminder:" line and a "Reminder:" subject); `owner_second_reminder_hours` (24) after that a SECOND reminder; `owner_queue_after_hours` (24) after that the fault goes on the agent's Command Centre queue as **"Owner has not answered"** (type `fault_owner_silent`, replaces the plain "Open" row for that fault; "reminded twice, no answer: phone the owner and record what they say"). The three settings are in Settings -> Rental portal and in the Setup Wizard (`config/agency-onboarding-copy.php`). Idempotent: a per-fault ledger (`rental_fault_reports.owner_reminder_1_at / owner_reminder_2_at / owner_queue_flagged_at`) is claimed with one conditional UPDATE before anything is sent, so two scheduler runs cannot both act; ONE step per fault per run, so a catch-up after downtime never sends both reminders at once; a paused fault is skipped and its clock does not advance; an owner who decides (or a cancelled fault) leaves the scan by status. Every step writes a history line for the agent ("First / Second reminder emailed to the owner...", "The owner has not answered after two reminders - it is on your Command Centre queue"). `--dry-run` counts and changes nothing.
**The silent skip is gone.** `RentalPortalNotificationService::notifyLandlordDecisionNeeded()` used to send nothing, without a word, when the agency had switched the email off or no owner had an address. It now returns `{sent, reason}` and, for a fault, writes a history line ("Owner could not be told - the agency has switched off the email ... Phone them." / "no owner is on file" / "no owner has an email address on file"); a reminder that cannot go out says "Owner reminder not sent - ...", and the ledger still advances so the queue row is not held back by a missing address.

**C-A2 - owner-declined has a queue row.** `fault_owner_declined` ("Owner declined" - the owner said no: tell the tenant, or change the decision if the owner rings back) for every fault in status `declined` whose outcome is still the automatic one. The row leaves when the agent sets the outcome themselves, archives the fault, or changes the decision (the fault leaves `declined`).

**C-A3 - the missing queue rows and alerts.**
- `fault_under_review` ("Send to owner"): the owner copy is saved but never sent - an abandoned draft no longer leaves the queue.
- `wo_contractor_declined` ("Pick another contractor", with the contractor's reason): a contractor who declined leaves the work order `ordered`; the queue used to say "Set appointment". It never shows "Set appointment" for that job.
- Quote-first: an `ordered` work order whose contractor uploaded a quote that nobody selected now shows "Choose a quote"; a selected quote with no cost authority shows "Authorise the cost" (or the informational "With owner" while the owner decides). Before this the quote rules existed only in the `reported` branch.
- Internal crew: a job card the crew marked complete shows "Crew finished - sign off" (`jc_agent_signoff`), and once both sign-offs are in "Complete the job card" (`jc_complete`).
- Alerts: when the crew marks a job complete (link, crew page, signed copy) both lease agents get an in-app alert `rental_job_card.crew_completed` (`NotifyAgentOfCrewCompleted`, on `RentalJobCardCrewCompleted`). A contractor's / owner's "finished" report already alerts the agents (`rental_work_order.contractor_finished` / `owner_finished`), so `RentalWorkReportedDone` is deliberately NOT given a second listener (it would double-alert); the crew route was the only one with none.

**C-B1..B5 - the 360-minute cooldown can no longer swallow a different event.** `NotificationDispatcher` drops a second alert of the same (person, event type, subject) inside the person's cooldown, so each distinct event has its OWN type (migration `2026_11_20_100000`, in-app + email, default on, no push, like `quote_received`):
- B1 contractor on their link: `rental_work_order.contractor_accepted / contractor_declined / contractor_invoice / contractor_extra_money / contractor_photo / contractor_finished` (the legacy `contractor_responded` stays registered but is no longer fired);
- B2 progress reports: `owner_started / owner_finished / tenant_started / tenant_not_fixed` (the legacy `owner_progress` is no longer fired);
- B3 the automatic go-ahead: `rental_work_order.go_ahead_sent` (own type beside `contractor_job_sent`);
- B4 lifting a hold: `rental_fault_report.owner_resumed` (the stop keeps `owner_hold`);
- B5 a variation: the alert's SUBJECT is now the variation, not the work order, so a second extra on the same work order within the cooldown is its own alert.
Rule for the future: an alert type names ONE kind of event; a new event never reuses a type that another event on the same subject can fire within six hours. Test: two different events on the same work order within a minute both deliver (`AuditP1AlertsAndQueueTest`).

**C-C4 - one rule for when the owner hears that the work was REPORTED finished.** The specs disagreed: rental-work-orders.md 14.27 Q11 emailed the landlord the moment the CREW completed (default ON); 22.17 item 6 (Johan, 14 Oct - the later ruling) says a contractor's "Work finished" tells the owner NOTHING until the agent checks and closes it. Both routes now consult ONE decision method, `OwnerFinishNotice::tellOwnerWhenReportedFinished($agencyId)`, driven by the agency setting `notify_landlord_on_crew_completion`, **whose default is now OFF** (the later ruling is the default). Off: the owner hears at the agent's close (the final statement) on every route. On: the owner is emailed when the crew OR the contractor reports it finished (the crew's existing mail; for a contractor a short `RentalOwnerWorkReportedMail`, with a history line on the work order). The setting's wizard / settings wording is updated ("Email the owner as soon as the work is reported finished (crew or contractor)"). NOTE: agencies with the setting unset change from "told at crew completion" to "told at close" - they can switch it on again.

**C-F2 - the owner's statement follows the Who-pays ruling.** The final statement said "Paid by: Not yet paid" and the ruling came later. After a ruling on a COMPLETED job whose statement was already emailed, `RentalWorkOrderService::resendFinalStatementForRuling()` regenerates the statement (its "Paid by" line now shows the ruling) and re-sends it ONCE per ruling (claimed through `rental_work_orders.final_statement_ruling_id`), subject "Updated final statement - who pays ruled", a line saying who pays, and a history line on the work order and the fault. A job whose first statement never went out sends nothing (the first one will already carry the ruling). No charge, no ledger - the money flow stays with reOS.

**Tests.** `AuditP1OwnerChaseTest`, `AuditP1AlertsAndQueueTest`, `AuditP1StatementRulingTest`. Updated existing tests: `RentalJobCardCrewCompletionMailTest` (the setting is switched on explicitly; a default-OFF test added).
**Setup Wizard (10a).** The three owner-chase settings (and the already-present link-renew / largest-quote settings of the foundation commit) are surfaced in the wizard; the reworded `notify_landlord_on_crew_completion` entry carries its new default (0).

### 29.P2 Public links: the contractor link, the crew job link, the crew page (audit fixes 19 Oct 2026, package P2)

**Settings used (all added by the foundation commit, none new here):** `link_renew_on_use` (default on) and `quote_max_amount` (default R1,000,000) on `rental_portal_settings`, with the existing link-expiry days. Both are on the Rental Portal settings page and in the Setup Wizard. **Not agency settings, by design:** the public-link throttles (`config/rental-public-links.php`: 30 per token per minute, 60 per token per 10 minutes for photo/line routes, 300 per address per minute) - they protect the server, they are not an agency's business rule.

**C-A4 - cancelling a work order tells the people it affects, and the dead link says why.** `RentalWorkOrder::cancel()` now calls `RentalWorkOrderCancelNotifier` (once - a second cancel is refused): everyone who was EMAILED the job (the dispatch ledger: contractor, crew) gets "Job cancelled - do not attend" (`RentalJobCancelledMail`, audience `worker`); a contractor reached only by WhatsApp is named on the history line as "Phone <name>"; the tenant gets "Repair cancelled" only when an appointment had been arranged with them and the agency's tenant-status-change notification is on. The agency's cancel reason is internal and is never in a mail. One history line records exactly who was told. The contractor link, the crew job link, the crew page (for a cancelled card) and the tenant completion link show **"This job was cancelled"** (`RentalSecureAccessTokenService::closedStateFor()`); a job that finished shows "This job is finished"; every other dead-link reason (expired, revoked, replaced, unknown) stays ONE identical generic page.

**C-A6 - the contractor's own "I have started".** `POST secure/work-orders/{token}/started` (`rentals.secure-link.started`), optional, additional to the tenant's / owner's report (spec 22.18). It goes through `RentalWorkOrderService::recordContractorStarted()` -> `startProgress()` (same state, same history, same gates) and alerts the lease's agents with its OWN event type `rental_work_order.contractor_started` (migration `2026_11_21_100000_register_contractor_started_notification`), so the 360-minute cooldown on `contractor_responded` cannot swallow it. Refused in plain words for: the agency's own team / the owner's own contractor, a closed job, an owner hold, a contractor who declined, a cost the owner has not authorised, a go-ahead that has not gone, work already reported finished. Already in progress is a quiet no-op. The button shows once, while the job has not started.

**C-D1 - a public-link page that outlived the session.** The 419 handler (`bootstrap/app.php`) sends a request to `secure/*` or `owner/*` back to the page it came from (the Referer when it is this site's own link page, else the link's front page) with what was typed kept (`old()`, never files) and "Your page was open for a while, so nothing was saved. Please press the button again." - never the staff login. A JSON call gets the same words with status 419 and `retry: true`.

**C-D2 - throttles per token.** Two named limiters (`rental-link`, `rental-link-heavy`, `AppServiceProvider`) replace the string limiters: the bucket is the SHA-256 of the link token (plus a per-address ceiling for made-up tokens), and a route carries exactly ONE of them, so a photo or line request is counted once, not twice, and a crew on one office or mobile address no longer shares a bucket.

**C-D3 - expiry renews on use.** When `link_renew_on_use` is on, every successful use of a live contractor link, per-job crew link or crew standing link moves `expires_at` forward by the number of days it was minted with (`RentalSecureAccessTokenService::renewOnUse`, one place - every action resolves its link there; at most one write a day). It never shortens a longer expiry, a link that stands until revoked stays that way, a revoked / expired / finished-or-cancelled link is never revived, and the tenant's completion link ends with its window.

**C-C6 - a declined contractor cannot quote; sane amounts.** The server (not just the page) refuses a quote from a contractor who declined ("accept the job first") and on a job that has started or is already authorised ("use Ask for extra money"); a refused quote does not flip the answer back. The amount must be more than R0 and at most `quote_max_amount`. A contractor can no longer decline once the work has started.

**C-C5 - an owner hold refuses EVERY public action.** One route middleware (`rental.link.hold`, `RentalLinkHoldGuard`) on all four public-link groups resolves the token's job (work order / job card / the crew page's card) and asks `RentalOwnerHoldService`; while held, every POST (tick, photos, add / change / undo line, send to office, complete, respond, quote, invoice, extra, started, mark done) is refused with "This job is on hold - please do not attend until you hear from us." (JSON: 423). Reading the page is never blocked. The owner's reason is never shown.

**C-E1 - findings typed once.** `RentalWhoPaysService::carriedFindings()` = what is on the work order, else what the crew wrote at "Send to office". Every "What was found" box (the contractor / crew completion fields, the agent's Complete form) starts with it, editable, with a "filled in from what you already told the office" line; a blank box on the crew's "Mark work completed" records the carried findings instead of losing them. The cause question stays.

**C-E2 - contractor money typed once.** `RentalWorkOrderInvoiceService::suggestedInvoiceAmount()` = what the owner authorised for the contractor (else the selected quote) less the invoices already filed; it starts the invoice amount on the contractor's page and on the agent's invoice form. The agent's final cost already starts from the invoices' total (spec 28.2), so the contractor's invoice reaches it with no retyping.

**Not done / notes.** A cancelled job card that has no work order is not mailed (every normal job card has one). The tenant completion link stays live on a cancelled job (it ends with its own window). Tests: `AuditP2LinksTest`, `AuditP2CrewLinksTest`.

### 29.P3 Tenant portal, photos, owner decision, mobile job card, existence leaks (audit fixes 19 Oct 2026, package P3)

**C-A5 / D20 - the tenant can add to a fault and withdraw it.** Two new tenant portal routes (auto-listed in Admin -> API; the group already carries `portal.once`, so a double tap does the work once):
- `POST /api/v1/client/rentals/fault-reports/{faultReport}/notes` (`client.rentals.fault-reports.add-to`) - body `note` (optional, 2000) and `photos[]` (at least one of the two). Allowed while the fault is open (not resolved, cancelled, declined, or a tenant-resolved record). The note and the photos reach the agent's fault page (a "<tenant> (tenant) added a note ... / N photos" history line; the photos with no uploader = the tenant). The owner sees nothing new unless the agent shares it (owner-version rules are untouched). The agents are alerted through their OWN alert type `rental_fault_report.tenant_update` (not `rental_fault_report.created`, whose per-fault cooldown would have swallowed it).
- `POST /api/v1/client/rentals/fault-reports/{faultReport}/withdraw` (`client.rentals.fault-reports.withdraw`) - body `reason` (required, 3-500). Only while the fault is still reported / under review / awaiting the owner's decision AND no work order is live (otherwise 409 "already being dealt with - contact your agent"). It is the same cancel, in the tenant's name (`RentalFaultReport::cancelByTenant`): status `cancelled`, reason "Withdrawn by the tenant: ...", a history line, the agents alerted. Nothing is deleted.
- Both are scoped to the tenant's own leases at the query layer (`tenantFaultReport`); another tenant's id is a 404. The fault summary carries `can_add` / `can_withdraw` so the app and the web portal draw the buttons only when they will work.

**C-I2 - a retried report makes ONE fault.** `faultReportStore` now stores the photo files first and creates the fault and its photo rows in ONE transaction. A photo that fails to store fails before any fault exists, so a retry with the same submission key makes the single fault; a retry after success replays the stored answer (`portal.once`).

**C-I1 / B19 - HEIC accepted everywhere, converted once.** `App\Services\Images\HeicConverter` (Imagick, HEIC delegate present on this stack) turns an iPhone HEIC / HEIF (detected by mime, extension or the `ftyp` brand) into an upright JPEG, longest edge at most 2560 px, quality 85, inside `PropertyImageStorer::store()` - the one place every photo upload (tenant, owner, crew, contractor, agent, mobile) goes through. The file is stored as `.jpg`. The capture time is read from the HEIC's EXIF before conversion (`RentalInspectionPhotoCaptureTime`), so the "taken at" source stays `exif`. All portal upload rules now use one list (`HeicConverter::UPLOAD_MIMES` = jpg, jpeg, png, webp, heic, heif) instead of Laravel's `image` rule, which rejected HEIC. If conversion is ever impossible (no HEIC delegate) the original is stored unchanged, as before - an upload never fails because of it. Quote documents (PDF or picture of a quote) are documents, not photos, and are stored as sent.

**D26 - the owner's quote decision carries the quote it saw.** `POST /landlord/work-orders/{id}/decision` now requires `quote_id`, `quote_revision` and `quote_amount` (the owner-facing amount) - the same snapshot the owner screen is given (`work_orders[].quote` in `/landlord/decisions`, `quote.{id,revision,amount}` in the work-order payload). If the selected quote is not that quote at that revision and amount, the answer is **409 "The quote has changed since you looked at it - please look again before you decide."** and nothing is recorded. When a quote is selected the fields are required (a blind approve is never safe): a client that omits them answers 422; a work order with no selected quote (an old record) has nothing to snapshot and decides as before. The web portal sends them and refreshes the list on a 409.

**C-D8 / D18 - the mobile job card.** `GET/PUT /api/v1/mobile/rental-job-cards/{id}` return `total_amount`, line `unit_price` and `line_total` only to a user with `rental_job_cards.view_costs` (same rule as the web screen and the reports); without it the keys are absent. Photo `url` is an absolute URL (APP_URL + the stored `/storage/...` path), here and in the photo-upload response, like every other image URL in the mobile API (rental-images.md 7a).

**C-D6 / A28 - existence leaks answer 404.** New rule `App\Rules\FoundInAgency::in('table')`: the id must belong to the user's own agency (soft-deleted rows excluded) and an id that does not answers **404** - the same answer for "another agency's record" and "no such record" (a bare `exists:` rule is an unscoped query that says 422 for one and passes the other). Applied to: job-card line catalogue item (`storeLine`), job-card create ids (lease, work order, fault), the renewal draft-from-template id (web and API), the notice template id, work-order inspection / observation ids, inspection recording room / observation / item ids, the inventory capture line id, and the inspection party contact. (Property ids are left to their `visibleTo` check: they already 404/403 after the rule, and `properties` is shared by many controllers.)

**D19 - one "is it fixed?" rule set.** The tenant's answer is `POST /work-orders/{id}/progress` (open job: started / finished / not_fixed), `POST /work-orders/{id}/completion-response` (a waiting round) and the public completion link. The legacy `POST /work-orders/{id}/confirm` is DEPRECATED and now only delegates: a waiting round -> `RentalCompletionService::respond`; an open job -> `RentalWorkOrderService::recordTenantProgress` (finished / not_fixed); only a completed job keeps its old plain sign-off. It accepts photos under the same limits (`portal.photos`) and the same HEIC list, and returns the work-order payload the other two return (plus its two old keys).

**D5 (reported, not built) - tenant photos on the public disk.** Tenant / owner / crew photos are copies on the PUBLIC disk (random file names, link-bearer readable); quote files, invoices and who-pays evidence are private. Moving them to the private disk means serving every photo through an authorised route (portal, staff pages, emails, PDFs and the shared photo viewer all use the `/storage/...` URL today). Size: about 3-4 lane days (storer + a signed or authorised file route + every view that renders `storage_path` + a back-fill that moves existing files). It needs Johan's decision first because photos in emailed links would then need signing.

**Tests.** `AuditP3PortalTest` (add / withdraw / scope / closed / alert type, retry makes one fault, HEIC accepted + converted + brand detection, D26 snapshot and 409, mobile costs + absolute URL, 404 for foreign vs missing, legacy confirm delegates). Older tests updated where they pinned the old behaviour are listed in the package report.
**Setup Wizard (10a):** no new setting in this package.

### 29.P4 Staff side (conductor)
- **C-D7 - cancel and archive are guarded on the server, not only hidden.** `RentalFaultReport::cancel()` refuses a fault with a LIVE work order ("cancel the work order first") and any fault the owner has already decided (the screen rule: Cancel exists only while reported / under review / awaiting the owner). `RentalFaultReport::archiveBlockReason()` is the one reason Archive refuses: a live work order, photos or a linked work order on record, or the owner being sent the fault and deciding on it (awaiting approval / approved / owner handling) - archiving takes the fault off the owner's list. Tests: `AuditP4StaffGuardsTest`.
- **C-C3 - ONE way to the owner (supersedes the leftover wording of 22.3).** The only action that sends a fault to the owner is "Send to owner to authorise" (`FaultInspectionService::sendToOwnerToAuthorise`). The old `POST .../send-to-owner` endpoint is kept as the same action with nobody proposed - it now calls that service, so it has the same gates (owner version saved, not on hold, not decided, no live work order) and the same history line; the duplicate `request-approval` route is gone; "Save" on the owner version no longer honours `send_now` (Save only saves).
- **C-C2 - emergency priority (spec 6.5) built; routing profiles (spec 13) REJECTED.** An emergency-type fault (the fault type's own `urgency = emergency` in the agency's catalogue - nothing hardcoded) now: sends `rental_fault_report.created` at the dispatcher's existing elevated severity (`warning`, titled "EMERGENCY fault reported") to every agent who is told of a new fault; is flagged "Emergency" on the fault list and sorted above routine faults while it is open (closed/declined ones are not flagged). **Section 13 (routing profiles) was rejected by Johan on 19 Oct 2026 - agent or owner selects the contractor** (see §13 and the ruling below); nothing is built for it and no code path sends a fault or work order to a contractor by itself.
- **C-H1 - wording and timezone.** The user-visible "Johan's own words" exception text (repaired date required) is plain agency-neutral wording. The hardcoded `Africa/Johannesburg` literal that backed the timezone fallback in ~30 places is replaced by ONE helper (`AppDate::agencyTimezone()`), which reads the agency's timezone, then the application timezone, and never a place name; `CrewPricingBlock` now uses the agency timezone like every other crew screen.

### 29.R Johan's rulings on the audit fixes (19 Oct 2026)
1. **The owner is told the work is complete only when the agent marks it complete.** `notify_landlord_on_crew_completion` stays **default OFF** (C-C4). With it off the owner hears at the agent's close on every route (crew, contractor, owner-reported); an agency may switch it on to be emailed when the crew or contractor REPORTS the work finished. Specs 14.27 Q11 (landlord emailed at crew completion, default ON) is superseded by this ruling and by 22.17 item 6.
2. **Routing profiles (§13, sending a fault or work order to a contractor automatically, e.g. by specialty) are REJECTED - the agent or the owner selects and appoints the contractor.** §13 is marked rejected and its permission key, Setup Wizard entry and future-gap items are withdrawn. A code audit the same day found **no server path that assigns a contractor by itself**; the only automatic behaviours are pre-selections in forms that still need the agent's press, and sending the job to the contractor already chosen (reported to Johan, not changed): the "Who goes to look first?" picker pre-ticks the property's first-responder (§21) or the contractor proposed when sent to the owner; the "Create work order" form pre-fills the owner's pick, then the proposed one, then the property's first-responder (§28.2); both crew pickers pre-select the agency's only crew when there is exactly one; the quote form pre-fills the work order's contractor; the go-ahead (§22.15) is sent automatically to the contractor the agent already chose once the cost is authorised. **The two contractor pre-selections named here are replaced by the single rule in 29.R3 (20 Oct 2026).**
3. **Contractor pre-fill (Johan, 20 Oct 2026, QA1) - the property's default contractor, or nothing.** The property's default contractor (the "first responder", §21, set on the property from the suppliers - Johan's 12 Oct ruling) is the one that loads by default on **"Who goes to look first?"** and on **"Create work order"** when the owner has not picked one. If the property has no default contractor, **nothing is pre-filled: the agent picks.** ONE rule, `RentalFaultContractorService::defaultSupplierId()`, read by both screens, in this order: (a) the contractor the OWNER picked (their decision names an agency contractor - a pick from the list, or "Yes, send <name>" on their link); (b) when an AGENT recorded the owner's decision (phone / WhatsApp / email), the contractor the agent chose in that same step (§28.2 - it travels with the decision); (c) the property's default contractor for this fault's type (§21.2 `Property::firstRespondersFor()`, which includes the owner's default list where the property has none of its own); (d) nothing. Never pre-filled: the contractor the agent merely **proposed** when sending the fault to the owner (when the owner decided on their own link it counts only if their decision names it - "you arrange it" does not); the first contractor in the list; the only contractor the agency has. The owner's own contractor loads no agency contractor. The id is ticked only if it is one of the picker's rows (an archived or inactive supplier is never ticked). **Crew is not a contractor:** both crew pickers still pre-select the agency's only crew when there is exactly one. The server never fills a contractor in: creating the work order and sending someone to inspect both refuse unless a contractor (or the crew) was explicitly submitted. The quote form and the work order's "Choose a different contractor" list show the contractor already on the work order - that is the saved choice, not a default. Tests: `tests/Feature/RentalFaultFlow/ContractorDefaultOnlyTest.php` (7). Setup Wizard (10a): no new setting - the default is the existing per-property §21 list.

## 30. Audit of the 19-20 Oct 2026 fault / work-order / crew build - every finding fixed (Johan: "fix all issues", 20 Oct 2026, branch `audit-fix-faults-photos-2026-10-20`)
Source: the audit report of 20 Oct 2026 (H1, M1-M5, L1-L10). Each sub-section names the finding it closes. Section 29's "D5 (reported, not built) - tenant photos on the public disk" is **superseded by 30.1**.

### 30.1 One rule for every picture a tenant or the crew sends: it is PRIVATE (H1, L5, and the D5 note)
Rule: a picture a tenant, the crew or the office-on-the-tenant's-behalf sends is stored on the private disk as a `private:private-images/...` reference (`PrivateImageService::storeUpload`), exactly as the tenant's first fault upload already was. It is never at a permanent `/storage/...` address. Every reader already understood a `private:` reference (security audit D14), so nothing reads the raw reference any more: the office screens use `displayUrl()` (a signed, expiring link that dies when the photo / fault / work order / card is archived), the portals use `PortalImageService::urls()`, PDFs read the file server-side, emails carry `RentalWorkOrderPhoto::mailUrl()`.
- **Tenant adds to an open fault** (`RentalFaultReportService::tenantAddsToFault`) - was the PUBLIC disk (H1, it undid D14). Now `storeUpload`.
- **Tenant progress photos** ("finished" / "not fixed" on the progress route, `RentalWorkOrderService::storeTenantPhotos`) - private, and marked `uploaded_via = tenant` (so the move command and any future rule can tell them from a contractor's upload). `RentalWorkOrderService::storePhoto()` takes `$private` / `$uploadedVia`; contractor and office uploads keep the public pipeline.
- **Tenant dispute photos** (`RentalCompletionService::storeDisputePhotos`, whoever records them) - private.
- **Crew receipts** (`RentalJobCardService::attachLineReceipt` -> `storePhoto(..., $private = true)`) - private (L5). They are office evidence of a payment: the office card shows them through a signed link, the crew's own page shows the crew their receipt through a signed link, neither the owner nor the tenant ever sees one.
- **An inspection photo raised to a fault** (`RentalInspectionFollowUpService::linkObservationPhotosToFaultReport`) - the fault photo REFERENCES the inspection photo's private file (same `private:` value, a second DB row); nothing is copied to the public disk any more (`PrivateImageStore::copyToPublic` is removed). A photo whose file is gone is not linked. A legacy (public) inspection photo is linked as before. Nothing ever deletes a private picture, so the shared reference cannot dangle.
- **Emails** (the crew / contractor send-back, the owner's dispute mail) now carry `RentalWorkOrderPhoto::mailUrl()`: a signed link that lasts the portal's longest picture-link window (`RentalPortalSetting::MAX_PORTAL_PHOTO_LINK_MINUTES`, 24 h - a mail is read hours later; the on-screen window would be dead), a public picture's absolute address as before.
- **Readers changed to `displayUrl()`**: the tenant-dispute panel and history (`rental-completion/_panel`), the crew's dispute block (`CrewDisputeBlock`), the crew page's gallery and line photos (`CrewJobService`, `CrewPricingBlock`), the awaiting-line thumbnails, and the receipt thumbnail (`_receipt-thumb`).
- **The move command** `rentals:privatise-fault-photos` (preview by default, `--force` to move, `--agency`, `--limit`) now also moves already-uploaded public copies of: work-order photos of type `dispute`, type `crew_receipt`, and `uploaded_via = tenant` (the same copy -> verify -> repoint -> remove-original order; a file anything else also points at is left alone and reported). Fault photos with no staff uploader (which include everything the tenant added with the old code) were already covered. **Not movable:** a tenant progress photo written before this change - it carries no marker that tells it from a contractor's upload, so it is left alone (the list of such rows can be produced by the work order's `tenant_progress` history lines).
- **Tests:** `PrivateImageTest` (new cases for each kind, the reader links, the mail link's longer life, the inspection reference, the move command); existing tests that pinned the raw `/storage` value updated (`DisputeLifecycleTest`, `CrewLabourHoursAndReceiptTest`, `LineEntryServiceTest`, `OfficeLineEntryTest`).

### 30.2 Catalogue: a below-cost item can be saved again (M1)
Selling below cost is allowed and stores a NEGATIVE markup (derived from selling and cost). The edit form posts that derived value back, and the validator then refused it ("min 0"), so the next Save of the same item failed. The markup box is an INPUT only when `pricing_driver = markup`; for a selling-price item it is the figure the server derived and recalculates, so `RentalCatalogueItemController::validated()` holds it to "not negative" only when markup is the driver (a typed negative markup is still refused). Test: `CataloguePricingBlockTest` (re-save of a below-cost item; typed negative markup still refused).

### 30.3 The mobile app's access note goes where every surface reads it (M3)
`PUT /api/v1/mobile/rental-job-cards/{id}` (`access_notes`, now max 2000 like the web form) writes through `JobSiteInfo::saveAccessContact()` - the work order holds the note, the card keeps no stale copy, who lets the worker in and their number are left as they are. The app's job-card payload reads `access_notes` back through `JobSiteInfo::accessContact()` (work order, then card, then the property's note), so it shows what the crew link, the contractor link, the emails and the web card show. Test: `CrewAccessContactAndSiteInfoTest`.

### 30.4 Owner chasing: a reminder is only "done" when it went out (M2)
`RentalOwnerChaseService` (spec 29.P1 C-A1) changes in four ways:
- **Send failure.** The send is inside a try/catch per fault. A mail transport error takes the claim back (`owner_reminder_1_at` / `_2_at` / `owner_queue_flagged_at` go back to null), sets `owner_chase_failed_at`, writes ONE history line ("... could not be sent because of a technical problem - it will be tried again at the next check. If it keeps failing, phone the owner.") and the step is retried on the next run (every 30 minutes). The marker stops a long outage writing a line every run; a success clears it. A broken fault never stops the other faults or agencies (the run counts them as `failed`, shown by `rentals:chase-owner-decisions`). The reminder mail goes to each owner on its own try/catch (`RentalPortalNotificationService::notifyLandlordDecisionNeeded` returns `failed`): one owner's bad address does not stop the next owner, and the history says "1 address could not be reached - phone that owner". Only when owners HAVE addresses and every send failed does it throw (the chase then retries).
- **Faults already waiting when the feature shipped are not chased on day one.** New column `owner_chase_from` (migration `2026_11_26_100000`, a NEW migration - the earlier ledger migration is untouched). The clock of the current wait is the later of `sent_to_owner_at` and `owner_chase_from`. The migration sets `owner_chase_from = now()` for every fault that is awaiting the owner, was never reminded, and is not archived: its first reminder is one `owner_reminder_hours` after the feature shipped, not at once. A chase already under way (a reminder recorded) keeps its own ledger and carries on.
- **Re-opening a decision restarts the wait.** `RentalAuthorisationService::reopenFaultDecision()` clears the three ledger columns and the failure marker and, when the fault was already sent to the owner, sets `owner_chase_from = now()` - so a re-opened fault is neither chased at once on its old sent date nor never chased again.
- Tests: `AuditP1OwnerChaseTest` (mail failure not recorded done, once-only history line across runs, one owner failing, the pre-feature wait, the migration's backfill rule, reopen).

### 30.5 The catch-up command no longer touches a quoted or approved card (M4)
`rentals:rederive-catalogue-markup-lines` (spec 17.39) now skips - and lists as skipped, with the reason - a card whose quote was sent to the owner (any quote row, or its VAT frozen) or whose work order the owner approved an amount on (`approved_amount` / approved status), for the markup re-derive AND the `--clear-hand` path. The total must stay what the owner was asked to approve / approved. Every card it DOES change gets a history line (`line_priced`: each line, old and new selling price) so the change is never invisible. Test: `CatalogueMarkupDriverTest`.

### 30.6 Closing a job card does not contradict a settled payer or repeat a statement (M5)
- `RentalJobCardService::complete()` notes the work order's own `paid_by` BEFORE closing. If the work order was already completed with a payer that is not the owner (tenant, deposit deduction, not yet paid), the close form's pre-selected "Owner" records NO "Owner" ruling (`recordWhoPaysAtClose`). Test: `JobCardAt460FixesTest`.
- `RentalWorkOrderService::resendFinalStatementForRuling()` (spec 29.P1 C-F2) re-sends only when the statement the owner holds would now say something different: it compares the "Paid by" text of the statement last sent (the last ruling it was re-sent for, else the ruling standing when the original went out, else the work order's `paid_by`) with the new ruling's summary; equal = nothing is sent and the ruling is recorded as covered. Test: `AuditP1StatementRulingTest`.
