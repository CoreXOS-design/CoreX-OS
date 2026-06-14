# CoreX — Agency Policy Acknowledgement Framework (SSOT)

**File:** `.ai/specs/claude_policy_acknowledgement_spec.md`
**Status:** Draft for build. Approved-pattern source: the live RMCP acknowledgement stack (as-built mapped 2026-06-14).
**Owner:** Johan (domain/BA) · Build: Johan + Andre
**Pillar:** Agent (`User`) — every staff member reads, signs, and is held to agency policy; the register is per-agent compliance state. Reads agency context; writes per-user acknowledgement evidence.
**One-line purpose:** A **generic, versioned agency-policy framework** with per-staff, signature-captured, legally-defensible sign-off — re-fired automatically when a policy is re-published. The **Communication & Marketing Compliance Policy (POPIA/CPA/NCC)** is instance #1.

---

## 0. The locked architectural decision: GENERALISE, do not clone

We do **not** copy the RMCP tables into `comms_policy_*`. We build **one** generic framework keyed by `policy_key`, and the Communication & Marketing Compliance Policy is its first row (`policy_key = 'communication_marketing'`).

- **RMCP stays untouched.** It is a live production system with its own `rmcp_*` tables, controllers, routes, and seeded HFC content. This framework does **not** modify, migrate, or break it.
- **Future task (noted, not in scope here):** migrate RMCP onto this framework as `policy_key = 'rmcp'`, then retire the bespoke `rmcp_*` stack. That migration is a separate spec; it is the reason we generalise now rather than clone — so the second policy (and the eventual RMCP fold-in) costs a seeder, not a rebuild.

Why generalise: HFC will accumulate many staff-signed governing documents (communication policy, social-media policy, POPIA manual, code of conduct, IT-acceptable-use, etc.). Each one is the *same machine* — version → sections → read-and-acknowledge → sign → receipt → register → re-sign on new version. Cloning that machine per policy is exactly the "half-built feature / technical debt that compounds" the Operating Principle forbids. One framework, N policies.

---

## 1. Why this exists (business requirement)

South African law requires HFC to (a) train staff on its compliance obligations and (b) hold defensible, dated, signed evidence that each staff member read and accepted each governing policy — and to re-collect that evidence whenever the policy materially changes. Today only the FICA RMCP has this; every other policy is a PDF emailed around with no signature trail, no version binding, and no register of who has signed what.

This framework makes **any** governing document a first-class, versioned, per-staff-signed artifact, with a compliance-officer register showing exactly who is valid / expired / outstanding against the current version.

Instance #1 — the **Communication & Marketing Compliance Policy** — is the staff-facing companion to the Communication Archive module (`.ai/specs/claude_communication_archive_spec.md`). The archive is the *evidence backbone*; this policy is the *staff obligation* (load every business contact; only-approved-templates outbound; honour opt-outs). The governing draft lives at `docs/policies/communication-marketing-compliance-policy.md` and seeds v1.0's sections.

---

## 2. Pillar connections

| Pillar | Relationship |
|---|---|
| **Agent (`User`)** | Primary. Each acknowledgement belongs to a user; the register lists agency staff and their per-policy status. User accessors compute live status. |
| Agency | Scope. Every table is `agency_id`-scoped (`BelongsToAgency`); policies, versions, and sign-offs are per-agency. Branch via `BelongsToBranch` on the acknowledgement (mirrors RMCP). |

No Property/Contact/Deal writes. This is an Agent-pillar compliance feature. (The *content* of instance #1 references the Contact pillar as an obligation, but the framework itself does not write to it.)

---

## 3. Data model

Five tables. `agency_id` on every table; `softDeletes()` on all **except** the section-acknowledgement child (mirrors RMCP, where the leaf tick row is not soft-deleted).

### 3.1 `agency_policies` (NEW — the registry; no RMCP equivalent)
The generic anchor that makes this a framework rather than a clone.

| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| agency_id | fk → agencies, cascade | `BelongsToAgency` |
| policy_key | string(64) | machine key, e.g. `communication_marketing`. Stable; used by User accessors + storage paths |
| name | string(255) | human title, e.g. "Communication & Marketing Compliance Policy" |
| description | text null | one-line purpose for the dashboard selector |
| is_active | bool default true | whether the policy is offered for sign-off at all (distinct from a *version* being active) |
| timestamps + softDeletes | | |

Indexes: **`unique(['agency_id','policy_key'])`** · `index(['agency_id','is_active'])`.

### 3.2 `policy_versions` (= `rmcp_versions` + `policy_id`)
Identical governance/lifecycle block to `rmcp_versions`, re-anchored from agency-wide to per-policy.

| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| agency_id | fk, cascade | |
| **policy_id** | fk → agency_policies, cascade | **NEW vs RMCP** — scopes the version to a policy |
| version_number | unsignedInteger | |
| title | string(255) | defaults to the policy name |
| status | enum(`draft`,`active`,`superseded`) default `draft` | one `active` per **policy_id** |
| approved_by | fk → users, nullOnDelete | governance block (GN 7A — approval not delegable) |
| approved_at | timestamp null | |
| approver_title | string(100) null | |
| board_approval_document_path | string(500) null | |
| approval_ip | ipAddress null | |
| approval_notes | text null | |
| effective_from | date null | lifecycle |
| superseded_at | timestamp null | |
| superseded_by_version_id | fk null | |
| next_review_due | date null | annual review prompt |
| change_notes | text null | |
| created_by | fk → users, nullOnDelete | |
| timestamps + softDeletes | | |

Indexes: **`unique(['policy_id','version_number'])`** (vs RMCP's `agency_id,version_number`) · `index(['agency_id','status'])` · `index(['policy_id','status'])`.

### 3.3 `policy_sections` (= `rmcp_sections`)
| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| agency_id | fk, cascade | |
| policy_version_id | fk → policy_versions, cascade | |
| section_type | enum(`section`,`schedule`,`annexure`,`acknowledgement`) default `section` | the `acknowledgement` row supplies the final declaration text |
| display_order | unsignedInteger | sequential read order |
| section_number | string(20) | e.g. `3.3` |
| title | string(500) | |
| body_html | longText | supports `{{variable}}` mail-merge |
| requires_acknowledgement | bool default true | sections the staffer must tick |
| acknowledgement_prompt | string(500) null | per-section confirm wording |
| timestamps + softDeletes | | |

Index: `index(['policy_version_id','display_order'])`.

### 3.4 `policy_acknowledgements` (= `rmcp_acknowledgements` — the per-staff signed record)
| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| agency_id | fk, cascade | `BelongsToAgency, BelongsToBranch` |
| policy_id | fk → agency_policies, cascade | denormalised for fast register queries by policy |
| policy_version_id | fk → policy_versions, cascade | the version this sign-off is bound to |
| user_id | fk → users, cascade | |
| status | enum(`in_progress`,`completed`,`expired`,`superseded`) default `in_progress` | |
| started_at | timestamp useCurrent | |
| completed_at | timestamp null | |
| valid_until | date null | set to +1yr on completion |
| signature_path | string(500) null | PNG path, or `typed:{name}` |
| signature_type | string(50) null | `drawn` \| `typed` |
| typed_signature_name | string(200) null | |
| ip_address | ipAddress null | evidence |
| user_agent | string(500) null | evidence |
| device_fingerprint | string(100) null | evidence |
| declaration_text | text null | frozen snapshot embedding signer name + ID number |
| sections_acknowledged_count | unsignedInteger default 0 | |
| sections_total_count | unsignedInteger default 0 | |
| timestamps + softDeletes | | |

Indexes: `(policy_version_id,status)` · `(user_id,status)` · `(agency_id,status)` · `(policy_id,status)` · `valid_until`.

### 3.5 `policy_section_acknowledgements` (= `rmcp_section_acknowledgements`)
**Delta vs RMCP:** `agency_id` is `NOT NULL FROM CREATION` — we skip RMCP's later add-column + backfill migration (`2026_05_23_090600`). No SoftDeletes on this child.

| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| agency_id | fk → agencies, cascade, **NOT NULL at creation** | `BelongsToAgency` |
| policy_acknowledgement_id | fk → policy_acknowledgements, cascade | |
| policy_section_id | fk → policy_sections, cascade | |
| acknowledged | bool default false | |
| acknowledged_at | timestamp null | |
| acknowledgement_response | string(100) null | e.g. `yes` |
| ip_address | ipAddress null | |
| timestamps | | (no softDeletes) |

Index: **`unique(['policy_acknowledgement_id','policy_section_id'], 'policy_sec_ack_unique')`**.

*(Optional, mirrors RMCP if needed: a `policy_variables` table for mail-merge values. For instance #1 the variables are minimal — agency name, signer name, signer ID — resolvable from the `Agency` + `User` directly, so a dedicated table is deferred unless a second policy needs richer merge.)*

---

## 4. Models (`app/Models/Compliance/`)

Mirror the RMCP models 1:1 with the renames and the `policy_id` re-anchoring.

### 4.1 `AgencyPolicy` (NEW)
- `SoftDeletes, BelongsToAgency`
- `$fillable`: agency_id, policy_key, name, description, is_active
- `$casts`: `is_active => boolean`
- Relationships: `versions()` HasMany PolicyVersion; `activeVersion()` — `hasOne(PolicyVersion)->where('status','active')`; `acknowledgements()` HasMany.
- Scope: `scopeActive()` (`is_active = true`).
- Helper: `currentVersion()` returns the active PolicyVersion or null.

### 4.2 `PolicyVersion` (= `RmcpVersion`)
- `SoftDeletes, BelongsToAgency`
- `$fillable`: all 3.2 columns incl. `policy_id`
- `$casts`: approved_at/superseded_at datetime; effective_from/next_review_due date
- Relationships: `policy()` BelongsTo AgencyPolicy; `sections()` HasMany ordered by `display_order`; `approver()`, `creator()`, `supersededBy()`.
- Scopes: `active()`, `draft()`.
- `canBeEdited()` — draft only.
- **`approve(User $user, string $title, ?string $documentPath, ?string $notes)`** — supersede-then-activate **scoped to the same `policy_id`** (RMCP scopes by `agency_id`; we scope by `policy_id` so activating the comms policy v2 does not touch any other policy). Sets prior `active`→`superseded` (+ `superseded_at`, `superseded_by_version_id`), then stamps this row `active` + full governance fields + `approval_ip = request()->ip()`.

### 4.3 `PolicyAcknowledgement` (= `RmcpAcknowledgement`)
- `SoftDeletes, BelongsToAgency, BelongsToBranch`
- Status constants `STATUS_IN_PROGRESS/COMPLETED/EXPIRED/SUPERSEDED`
- `$fillable`: all 3.4 columns
- `$casts`: started_at/completed_at datetime; valid_until date; counts integer
- Relationships: `policy()`, `version()` BelongsTo PolicyVersion, `user()`, `sectionAcknowledgements()` HasMany.
- Scopes: `completed()`, `inProgress()`, `valid()` (completed AND `valid_until > now()`), `expiringSoon($days = 30)`.
- Methods: `progressPercent()`, `isComplete()`, `isValid()`, **`complete($signaturePath, $signatureType, $ip, $userAgent, $typedName = null)`** → status=completed, completed_at=now(), **`valid_until = now()->addYear()`**, persists signature + evidence.

### 4.4 `PolicySection` (= `RmcpSection`)
- `BelongsToAgency, SoftDeletes`
- type constants; `$fillable` per 3.3; `$casts` display_order int / requires_acknowledgement bool
- `version()` BelongsTo.
- **`renderedBody(array $variables)`** — `{{key}}` substitution with `e()` escaping (verbatim from RMCP).

### 4.5 `PolicySectionAcknowledgement` (= `RmcpSectionAcknowledgement`)
- `BelongsToAgency` (no SoftDeletes)
- `$fillable` per 3.5 (incl. agency_id); `$casts` acknowledged bool / acknowledged_at datetime
- `acknowledgement()`, `section()` BelongsTo.

---

## 5. User accessors — PARAMETERISED by `policy_key` (compute live; store nothing on `users`)

RMCP hard-codes the active-version lookup. We parameterise it. Add to `app/Models/User.php` (no migration — no column on `users`):

```
policyAcknowledgements(): HasMany   // all PolicyAcknowledgement for this user

currentPolicyAcknowledgement(string $policyKey): ?PolicyAcknowledgement
  // resolve agency's AgencyPolicy by (agency_id, policy_key)
  // -> its active PolicyVersion
  // -> this user's in_progress|completed ack for THAT version id (latest)

policyAcknowledgementStatus(string $policyKey): string
  // 'no_policy' | 'not_started' | 'valid' | 'expired' | 'in_progress'
  // same branch logic as rmcpAcknowledgementStatus() but keyed

outstandingPolicyAcknowledgements(): Collection
  // for every active AgencyPolicy in the user's agency, return those whose
  // status is not 'valid' (not_started | expired | in_progress)
  // -> drives the Agent Portal "you have N policies to sign" tile and the
  //    global compliance roll-up
```

The re-acknowledgement guarantee falls straight out of this: status is computed against the policy's **current active version id**. Publishing a new version supersedes the old one, so no completed ack exists for the new version → every user reverts to `not_started` with zero per-user mutation. (See §8.)

---

## 6. Controllers (`app/Http/Controllers/Compliance/`)

Three controllers mirroring RMCP, every entry scoped by `{policy}` (resolved from `policy_key` route param or the selected policy).

### 6.1 `PolicyAcknowledgementController` (= `RmcpAcknowledgementController`) — staff wizard
Routes carry `{policyKey}`. Methods mirror RMCP exactly:
- `start($policyKey)` — resolve AgencyPolicy + active version; reuse in_progress / redirect valid to receipt; create the `policy_acknowledgements` row + one `policy_section_acknowledgements` stub per `requires_acknowledgement` section (**stub carries `agency_id`** since the column is now NOT NULL); redirect to step 1.
- `step($policyKey, $order)` — render one section; **enforce sequential reading** (redirect forward to next incomplete; cannot skip); resolve mail-merge variables.
- `confirmSection($policyKey, $order)` — **AJAX**; mark section acknowledged + stamp IP; recompute `sections_acknowledged_count`; return `{ success, next_url, progress_percent, all_done }`.
- `sign($policyKey)` — gated on all sections done; build declaration from the `acknowledgement`-type section.
- `submit($policyKey)` — validate signature; build declaration snapshot embedding signer name + ID number; store signature; call `complete(...)`.
- `receipt($ack)` / `downloadReceipt($ack)` / `index($policyKey?)` — see §7.
- Helpers `currentAck($user, $policyKey)`, `nextIncompleteOrder($ack)`.

### 6.2 `PolicyDashboardController` (= `RmcpDashboardController`) — compliance-officer register
- **Delta: a policy selector.** The dashboard takes `?policy=` (default: first active policy); lists all active agency staff with per-staff status **for the selected policy** (valid / in_progress / expired / not_started / no_policy); tallies counts; filter/search/sort as RMCP.
- `report()` — printable register for the selected policy.
- **`sendReminder()` — wired to REAL email.** RMCP only logged "coming soon". Here it dispatches a queued mailable (database queue) to the staffer with a deep link to `policy.ack.start` for the outstanding policy, and records that a reminder was sent. (Use the existing mail infra; outbound only, no new package.)

### 6.3 `PolicyController` (= `RmcpController`) — authoring / versioning
- `index` (list policies + their versions), `create` (new draft version for a policy), `edit`/`update` (draft only), `show`, `approveForm`/`approve` (calls `PolicyVersion::approve()`), `downloadPdf` (version document PDF).
- Optional `variables`/`updateVariable` only if a `policy_variables` table is added later.

---

## 7. Sign-off surface, signature capture & receipt

Copy the RMCP acknowledgement views into `resources/views/compliance/policy-ack/` (`index`, `step`, `sign`, `receipt`, `receipt-print`) and the authoring views into `compliance/policy/`.

- **Signature:** reuse `rmcp-ack/sign.blade.php` verbatim — dual-mode via **`signature_pad@4.1.7`** (Type vs Draw; typed renders a Dancing Script preview; drawn → base64 PNG). Alpine `x-data`.
- **Validation (`submit`)**: `signature_type` in `drawn|typed`; `signature_data` required_if drawn; `typed_name` required_if typed (max 200); **`declaration_acknowledged` must be `accepted`**.
- **Storage:** drawn PNG written to **`storage/app/public/policy/{agency_id}/{policy_key}/{userId}-v{n}-{Ymd-His}.png`** (note the `{policy_key}` segment vs RMCP's flat `rmcp/{agency_id}/acknowledgements/`); typed stored as literal `typed:{name}`. The legal record is the DB row + the stored PNG.
- **Declaration snapshot:** frozen string embedding `{name} (ID: {id_number})` + policy name + version number, persisted to `declaration_text` (mirrors RMCP `submit()`).
- **Receipt + PDF:** `receipt` view for self/owner/CO; `downloadReceipt` renders `policy-ack/receipt-print.blade.php` → **Puppeteer PDF via `scripts/html-to-pdf.mjs`**, generated **on demand, not persisted**, downloaded as `policy-acknowledgement-{policy_key}-{user-slug}-{date}.pdf` and deleted after send. Reuse RMCP's `generateReceiptPdf()` node-path-resolution + temp-file + logging helper verbatim.

---

## 8. Versioning & re-acknowledgement (the core guarantee)

1. Each acknowledgement is bound to a specific `policy_version_id`.
2. "Has this user signed policy X?" is computed by `User::policyAcknowledgementStatus($key)` — find the policy's single `active` version, then look for this user's completed ack **for that exact version id**.
3. `PolicyVersion::approve()` activates a new version and flips the prior one to `superseded` **within the same `policy_id`**. Every prior ack still points at the now-superseded version → no completed ack exists for the new version → every staffer reverts to `not_started` → portal + register show outstanding → re-sign required. **Publishing a new version re-fires sign-off automatically, agency-wide, with zero per-user mutation.**
4. Independently, **annual expiry**: `complete()` stamps `valid_until = now()+1yr`; `isValid()` requires a future date, so a yearly re-sign is forced even without a new version. `expiringSoon()` + the register's expiring-soon tally surface the 30-day warning.
5. **NCC re-sign (instance #1, concrete):** when NCC registry detail lands (target July 2026), the CO authors **v2.0** of the communication policy (fills §5 NCC placeholder), `approve()`s it → v1.0 superseded → **all staff revert to `not_started`** → everyone re-signs v2.0. Exactly the policy doc's "a new version re-fires the sign-off for all staff."

---

## 9. Permissions / Navigation

### Permissions (`config/corex-permissions.php`, `compliance` section)
Add three generic keys (the framework is one feature regardless of policy count):
- `access_policy` — view & sign policies (staff)
- `edit_policy` — edit draft versions (CO / admin)
- `approve_policy` — board approval / activate (supersede-and-publish gate)

**Reuse, do not duplicate:**
- `access_compliance_dashboard` — gates the register dashboard (same key RMCP dashboard uses).
- `manage_information_officer` — the POPIA Information Officer permission **already exists**; wire to it for IO-specific actions rather than creating a new one.

Grant `access_policy` broadly (all staff sign); `edit_policy`/`approve_policy` to CO/owner bundles (mirror RMCP grants at the owner/admin role rows).

### Navigation
- **Sidebar — Compliance panel** (`resources/views/layouts/corex-sidebar.blade.php`, alongside the existing RMCP / RMCP Dashboard links): add `@permission('access_policy')` → "Policies" (→ `policy.index` or the staff sign hub) and `@permission('access_compliance_dashboard')` → "Policy Register" (→ `policy.dashboard.index`), each with `request()->routeIs('policy.*' / 'policy.dashboard.*')` active state.
- **Agent Portal compliance tile** (`AgentPortalController`): add a tile driven by `User::outstandingPolicyAcknowledgements()` — green when none outstanding, red/amber with "N policies to sign" linking to the first outstanding `policy.ack.start`. Mirrors the existing RMCP status tile.

### Routes (names, mirror RMCP grouping)
- Staff wizard under `/my-portal/policy/{policyKey}/acknowledge/...`, middleware `permission:access_policy` + `agency.required`, names `policy.ack.*` (`start` POST, `step/{order}` GET, `confirm/{order}` POST, `sign` GET, `submit` POST, `receipt/{ack}` GET, `receipt/{ack}/pdf` GET, `my-acknowledgements` GET).
- Register under `compliance/policy-dashboard`, `permission:access_compliance_dashboard`, names `policy.dashboard.*` (`index`, `reminder` POST, `report.pdf`).
- Authoring under `compliance/policy-manager`, names `policy.*`, per-action gated `access_policy`/`edit_policy`/`approve_policy`.

---

## 10. Seeding instance #1 (NOT published until the gate clears — see §11)

Seeder (e.g. `CommunicationPolicySeeder`, run via the existing demo/compliance seeder chain) creates, for the agency:
1. `agency_policies` row: `policy_key='communication_marketing'`, name "Communication & Marketing Compliance Policy", `is_active=true`.
2. `policy_versions` row v1.0 in **`draft`** status (NOT approved — the gate forbids publish-before-verify).
3. `policy_sections` seeded from **`docs/policies/communication-marketing-compliance-policy.md`**, mapping the doc's sections:
   - (1) Why this policy exists — `section`
   - (2) The communication archive — what is captured — `section`
   - (3) The contact rule — `section`
   - (3.3) Inbound contact — grace period — `section`
   - (4) Outbound marketing — approved templates only — `section`
   - (5) NCC registry obligations — **PENDING placeholder** — `section`, `requires_acknowledgement=false` until v2.0 fills it
   - (6) Your obligations — summary — `section`
   - (7) Acknowledgement — the declaration — `section_type='acknowledgement'` (supplies `sign` page declaration text)

The `.md` is the source draft; `policy_sections.body_html` is the runtime SSOT once seeded. Keep them reconciled until v1.0 is approved, then the DB is authoritative.

---

## 11. GATE — must be honoured at build time

**The framework (foundation + wizard + register + authoring) may be built and tested freely.** What is **gated** is **publishing v1.0 and collecting staff sign-off**:

> v1.0 of the Communication & Marketing Compliance Policy must **not** be approved/activated or signed by staff **until the email + WhatsApp ingestion (Communication Archive Phases 2–3) is LIVE and VERIFIED**, and then the **Compliance Officer has verified the policy document against the real, working communication loop**.

Rationale: the policy asserts to staff, as fact, that their email and WhatsApp are being captured and retained for 5 years, that capture is gated to loaded contacts, and that an inbound grace period applies. Asking staff to sign a legal declaration about a capture system that is not yet live (or behaves differently than written) is exactly the "ship compromised work" failure the Operating Principle forbids — and it manufactures a defective evidence trail. So: **build the machine; seed v1.0 as `draft`; do NOT seed-and-publish; do NOT enable staff sign-off** until the archive is verified and the CO signs off the document against reality.

Build leaves v1.0 in `draft`. Approval/activation is a deliberate human step taken after the gate clears.

---

## 12. Tests (the coverage RMCP never got — mandatory here)

Per BUILD_STANDARD + the schema-snapshot rule. Feature tests under `tests/Feature/Compliance/Policy/`:

1. **Version supersession → re-ack trigger.** Seed policy + v1 active; user completes v1 (status `valid`). Approve v2 for the same `policy_id`. Assert v1 → `superseded`, and `policyAcknowledgementStatus($key)` for that user now returns `not_started`.
2. **Multi-policy isolation.** Two policies (e.g. `communication_marketing` + a second). User signs policy A. Assert policy A status `valid` while policy B status `not_started`; approving a new version of A does not touch B's versions or acks.
3. **Sequential-section enforcement.** Start an ack; request `step` for a later order than the next-incomplete; assert redirect to the next incomplete; assert `sign` is blocked (redirect) until all `requires_acknowledgement` sections are confirmed.
4. **`complete()` validity stamping.** Complete an ack; assert `status=completed`, `completed_at` set, `valid_until == completed_at + 1 year`, signature + ip/user_agent persisted, `isValid()` true.
5. **Signature validation.** `submit` without `declaration_acknowledged` → 422; `drawn` without `signature_data` → 422; `typed` without `typed_name` → 422; valid drawn payload writes a PNG under the `policy/{agency_id}/{policy_key}/` path.
6. **Dashboard status tallies.** Mixed cohort (valid / expired / in_progress / not_started) for the selected policy; assert counts and the per-staff rows; assert the policy selector switches the computed set.

Run `php artisan schema:dump` after the five migrations land; commit the snapshot in the same commit (non-negotiable #12a).

---

## 13. Build order (prompt sequence)

- **P1 — Foundation.** 5 migrations (incl. `agency_id` NOT NULL on the section-ack child from creation), 5 models with relationships/scopes/`approve()`/`complete()`/`renderedBody()`, the parameterised `User` accessors. `schema:dump`. Tinker: instantiate models, create a draft policy+version+sections, assert accessors compute. *(no UI yet)*
- **P2 — Wizard + sign-off + receipt.** `PolicyAcknowledgementController` (start→step→confirm→sign→submit→receipt→pdf), copied `policy-ack/*` views incl. signature_pad surface, storage path, declaration snapshot, Puppeteer receipt. Routes `policy.ack.*` + `permission:access_policy`. Agent Portal tile. Tests #3–#5.
- **P3 — Register + authoring + seeding (draft only).** `PolicyDashboardController` (with policy selector + REAL-email `sendReminder`), `PolicyController` authoring/versioning, `compliance/policy-*` routes, sidebar links, permissions in `corex-permissions.php`. `CommunicationPolicySeeder` seeding v1.0 **as draft** from the `.md`. Tests #1, #2, #6. **Do NOT approve/publish v1.0 (GATE §11).**
- **Later (separate spec):** migrate RMCP onto this framework as `policy_key='rmcp'`; retire `rmcp_*`.

---

## 14. Files to create / modify

**Create:**
- `database/migrations/*_create_agency_policies_table.php`
- `database/migrations/*_create_policy_versions_table.php`
- `database/migrations/*_create_policy_sections_table.php`
- `database/migrations/*_create_policy_acknowledgements_table.php`
- `database/migrations/*_create_policy_section_acknowledgements_table.php`
- `app/Models/Compliance/AgencyPolicy.php`, `PolicyVersion.php`, `PolicyAcknowledgement.php`, `PolicySection.php`, `PolicySectionAcknowledgement.php`
- `app/Http/Controllers/Compliance/PolicyAcknowledgementController.php`, `PolicyDashboardController.php`, `PolicyController.php`
- `resources/views/compliance/policy-ack/{index,step,sign,receipt,receipt-print}.blade.php`
- `resources/views/compliance/policy/{index,show,edit,create,approve}.blade.php`
- `resources/views/compliance/policy-dashboard/{index,report}.blade.php`
- `database/seeders/CommunicationPolicySeeder.php`
- `tests/Feature/Compliance/Policy/*` (six suites per §12)
- Mailable for `sendReminder` (e.g. `app/Mail/PolicyAcknowledgementReminder.php`)

**Modify:**
- `app/Models/User.php` — add the four parameterised accessors (no migration)
- `config/corex-permissions.php` — add `access_policy`, `edit_policy`, `approve_policy`; grant in role bundles
- `resources/views/layouts/corex-sidebar.blade.php` — Compliance-panel links
- `app/Http/Controllers/Agent/AgentPortalController.php` — outstanding-policies tile
- `routes/web.php` — the three route groups
- `database/schema/mysql-schema.sql` — regenerated snapshot

**Do NOT touch:** any `rmcp_*` table, model, controller, route, view, or seeder. RMCP is live and stays as-is until the future fold-in.

---

## 15. Acceptance criteria

- A CO can create a policy, author a draft version with ordered sections, and approve it — approving v2 supersedes v1 **only within that policy**.
- A staffer signs via sequential read → per-section confirm → dual-mode signature → receipt; evidence (signature PNG, IP, UA, declaration snapshot, valid_until=+1yr) is persisted; a receipt PDF downloads on demand.
- `User::policyAcknowledgementStatus($key)` and `outstandingPolicyAcknowledgements()` reflect reality with no stored user column; publishing a new version reverts all staff to `not_started`.
- The register shows per-staff status for a selected policy with working counts, filter/search/sort, and a reminder that sends a real email.
- Two policies are fully isolated.
- All six test suites pass; `dev-check.ps1` 0 new failures; permissions + sidebar + portal tile present.
- v1.0 of the communication policy exists **as a draft, unpublished, unsigned** — honouring the §11 gate.
- The close meets the Operating Principle: a generic framework that makes the *next* policy a seeder, not a rebuild — and RMCP is provably untouched.
