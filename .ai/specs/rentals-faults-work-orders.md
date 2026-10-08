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
CONFIGURED, NEVER HARDCODED — a per-property fault routing profile (agency default + per-property
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
2. **Routing resolves immediately** — new, §13. `RentalFaultRoutingService::resolve()` runs the moment
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

Per §1.1's "urgency/emergency flag" and §1.3's "emergency handling." **As of the 2026-09-29 routing
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
- `rental_fault_routing.manage` — **new, §13** — managing the agency-default and per-property routing
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
- **The agency-default routing profile (§13) gets its own Setup Wizard entry** — non-negotiable #10a
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
- **A complex/estate-level routing-profile tier** (§13.1) — investigated per Johan's own instruction and
  confirmed NOT to exist as a real grouping entity in CoreX today (`complex_name` is free text only);
  building one is a separate, larger decision, not taken on here.
- **Building Andre's mobile app itself** — his job; this spec's job is the API it consumes (§7),
  unchanged in spirit from `rental-work-orders.md §13`'s own framing.

---

## 13. Fault routing profiles — configured, never hardcoded, per Johan's ruling on §14(2)

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
2. **Routing.** **SETTLED, 2026-09-29 — Johan ruled directly.** Never hardcoded — a per-property fault
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
