# AT-231 — DR2 W3 · Inbound Attorney Comms Filing (Match-or-Create for Correspondence)

> **Status:** SIGNED OFF (Johan, 2026-07-17) — build proceeding phase-by-phase. P1 in progress.
> **Ticket:** AT-231 (To Do). Pairs with AT-228 (outbound, Production). Part of AT-215 (DR2).
> **Author:** m5 (investigation + draft), 2026-07-17. Anchored to Johan's two-route design (spec answers, 2026-07-17).
> **Pillars:** Deal (primary), Property, Contact, Agent. Reads inbound comms; writes enriched filing back to Deal + Property + Contacts.
> **Governing precedents:** Non-negotiable #10 (Universal Match-or-Create) applied to correspondence; AT-132 per-thread comms gate; `TrackedPropertyMatchOrCreateService` (match + source-chain); `pdf_splitter_learned_phrases` (learn-a-signal, auto-apply); `DealDocumentService` (3-pillar filing).

---

## 1. What this does and why (business requirement)

AT-228 opened the loop: CoreX sends deal packs OUT to attorneys/parties. AT-231 closes it: the returns that come BACK from attorneys (COC, invoices, replies) file themselves onto the right deal, and CoreX **learns the reference** so it only ever asks the agent once per correspondence.

**Doctrine — "file where confidence exists."** Email and WhatsApp file differently:

- **EMAIL → deal-level** (references are matchable).
- **WHATSAPP → person-level** (one WA thread spans many deals; per-message deal-tagging is rejected outright — it would turn agents into admin operators).

### 1.1 Johan's two-route design (verbatim-anchored)

**EASY ROUTE.** The agent's outbound email carries our reference. The attorney's email is already known (they're a linked provider-contact on the deal). The RETURN email lands in a **SUSPENSE** attached to the contact/property screen. If match-confidence is high we **auto-assign**, **but the agent MUST VERIFY the first email of a correspondence.** Once verified, the same ref **auto-files for the rest of the transaction** — "done for rest of the transaction." Verification anchors available: **attorney email, seller email, buyer email.**

**DIFFICULT ROUTE.** Attorneys who run their own reference systems (e.g. VDS) don't echo our token. The agent **manually links** each email to its deal (or verifies a suggestion). After the link/verify, we **LEARN** that pattern and auto-file it next time.

Both routes converge on the same spine: **ref-stamping → suspense → first-verify-then-trust → learned refs.** (Johan Q1: this IS the mechanism. Q2: yes, a review screen = the suspense queue. Q3: provider-contact resolution for WA, PLUS a per-attorney "WhatsApp allowed" tickbox and a linked WA number on the firm/contact record.)

---

## 2. Investigation baseline (what exists — the extend-don't-greenfield map)

| Piece | State | file:line anchor |
|---|---|---|
| Inbound email ingest (poll→parse→dedup→match→archive) | **Built** (AT-181 archive) | `app/Services/Communications/ImapMailboxPoller.php`, `EmailArchiveIngestor.php` |
| Unmatched inbound email | **Dropped before storage** — must flip to *park* for known attorney senders | `EmailArchiveIngestor.php:71-91` |
| `attorney_ref` link method + Deal/Property morph on `CommunicationLink` | **Reserved, populated nowhere** — the intended AT-231 hook | `CommunicationLink.php:20` |
| Outbound stamps a resolvable deal ref | **NOT built** — subject is free-text, no headers, `thread_key=null` | `DealPackMail.php:33`, `OutboundProvisionalLogger.php:130` |
| `TrackedPropertyMatchOrCreateService` (match + source_chain + external_refs) | Built — the shape to mirror | `app/Services/Prospecting/TrackedPropertyMatchOrCreateService.php` |
| `pdf_splitter_learned_phrases` (learn + auto-apply @ threshold) | Built — the learned-ref precedent | `PdfSplitterController::logFeedback/getLearnedBoosts` |
| `DealDocumentService::fileClassifiedDocument()` (3-pillar filing) | Built — the filing entrypoint | `app/Services/DealV2/DealDocumentService.php:119` |
| Attorney firm→person model | Built — `AgencyServiceProvider`→`AgencyServiceProviderContact` = "Linda at VDS" | `app/Models/DealV2/AgencyServiceProvider*.php` |
| Deal↔attorney FK | Built | `deals.attorney_provider_id` + `attorney_contact_id` |
| AT-132 per-thread comms gate | Built, type-agnostic on `(contact_id, thread_key)` | `CommsAccessGrantService.php`, `Communication::scopeVisibleTo` |
| WA ingest (phone→Contact→file) | Built — resolves to **Contact** only | `WaArchiveIngestor.php`, `ContactIdentifierResolver.php` |

---

## 3. EMAIL ROUTE — the mechanism

### 3.1 Outbound reference stamping (the AT-228 prerequisite — in AT-231 scope)

The ticket states: *"AT-228 outbound must stamp a resolvable deal reference so email replies match hands-free — the two are one loop."* That was never built. AT-231 adds it.

**Canonical token (subject-visible, machine-read):**

```
Documents — {deal_no}  [CX-D{deal_id}]
```

- `{deal_no}` = human label (unchanged from AT-228, `DealPackMail.php:33`).
- `[CX-D{deal_id}]` = the **machine anchor**. `{deal_id}` = the DR1 `deals.id` (immutable PK). We do **not** encode `deal_no`: it is a nullable, reassignable string sometimes already `D-`-prefixed; the PK is stable and unique. The matcher resolves DR1 deal → its `deal_v2_id` twin for filing (consistent with how AT-228 links comms to `DealV2`).
- Parse regex: `/\[CX-D(\d+)\]/` — the `CX-D` prefix + brackets make false-positive collisions with body numbers effectively nil.

**Belt-and-suspenders (secondary confidence, not primary):** on every AT-228 outbound send, set an RFC `Message-ID` and persist it as the outbound comm's `thread_key` (today it is `null`). A true reply carries `In-Reply-To`/`References` → the inbound poller already derives `thread_key` from those → a thread_key match becomes a strong corroborating signal. The subject token remains the **primary durable anchor** because attorneys often compose fresh (no `References`), but keep the subject line.

**Prevent-or-absorb:** a deal with no `deal_no` still sends (`[CX-D{id}]` needs only the id, always present). An attorney who strips the token from a fresh compose falls through to the party-email anchors (§3.3) — never a hard failure.

### 3.2 Inbound park scope — POPIA-bounded

Today unmatched inbound mail is **dropped** (`EmailArchiveIngestor.php:71-91`). AT-231 flips this to **park** — but **only for known attorney-firm senders** (Johan's POPIA scope). Concretely:

- Sender email (or its domain) matches an `AgencyServiceProvider`/`AgencyServiceProviderContact` email/domain in the receiving mailbox's agency → **park** (store the Communication + raise a suspense row).
- Sender is neither a known Contact nor a known attorney-firm → **dropped, exactly as today.** We never park arbitrary unknown mail. This keeps the drop→park flip narrow and POPIA-defensible.
- Known Contact senders continue to archive as today (unchanged); AT-231 additionally runs deal-resolution on them.

### 3.3 Correspondence resolution + confidence rules (the three email anchors)

New service **`CorrespondenceMatchService`** (mirrors `TrackedPropertyMatchOrCreateService` shape: `resolve($agencyId, $parsedEmail): CorrespondenceMatch`, strategy-ordered, first strong signal wins, every decision appended to an audit chain). Anchors, per Johan: **attorney email, seller email, buyer email.**

Resolution builds a candidate deal + a confidence tier:

| Tier | Rule | Behaviour |
|---|---|---|
| **VERIFIED-AUTO** (silent) | A **verified learned-ref** (§3.5) matches this signal | Auto-file silently. No suspense. "Rest of the transaction." |
| **HIGH** (auto-assign, first-verify required) | CX token resolves to deal D **AND** sender/participants match ≥1 of D's party emails (attorney / seller / buyer) | Provisional link + suspense row `pending`; surfaces as "Suggested: Deal D" for one-tap verify |
| **MEDIUM** (auto-suggest, must verify — Johan sign-off) | CX token present but no party-email corroboration; **OR** sender == a known attorney linked to **exactly one** active deal, no token | Suspense row `pending` **with the deal auto-suggested**; agent still verifies the first email |
| **LOW** (manual, difficult route) | Sender is a known attorney-firm (so we parked) but resolves to 0 or >1 candidate deals, no token | Suspense row `pending`, **no** confident suggestion; agent manually links |

`thread_key` match (§3.1 belt) upgrades MEDIUM→HIGH when present. `numbersConflict`-style veto is not needed here (id-exact token), but a token that resolves to a deal in a **different agency** than the mailbox is rejected (cross-tenant guard).

### 3.4 First-verify gate

Even a HIGH match is **provisional** until the agent verifies the **first** email of a correspondence. "Correspondence" = the `(deal, attorney-provider-contact)` pair (or, difficult route, the `(deal, learned-signal)` pair). On first verify:

1. The provisional `CommunicationLink(linkable=DealV2, method=attorney_ref)` is confirmed (confidence→100).
2. Attachments file to the pillars via `DealDocumentService` (§3.6).
3. The resolving signal is written as a **verified** learned-ref (§3.5).
4. Suspense row → `verified`.

Thereafter every inbound email carrying that signal is **VERIFIED-AUTO** — filed silently, no suspense. This is Johan's "done for rest of the transaction."

### 3.5 Learned-ref table (mirrors `pdf_splitter_learned_phrases`)

New table `communication_learned_refs`:

| col | type | note |
|---|---|---|
| `id` | pk | |
| `agency_id` | fk | tenant |
| `deal_id` | fk → `deals.id` | the resolved deal |
| `attorney_provider_id` | fk nullable | firm |
| `attorney_provider_contact_id` | fk nullable | person |
| `signal_type` | enum | `cx_token` \| `subject_pattern` \| `external_ref` \| `sender_email` |
| `signal_value` | string(200) | normalised (lowercased/trimmed); e.g. `cx-d1234`, a VDS matter regex-capture, or a sender address |
| `is_verified` | bool | only verified signals auto-file (mirrors `enabled`) |
| `verified_by_user_id` | fk nullable | first-verifier |
| `verified_at` | timestamp nullable | |
| `hits` | uint | times auto-applied (audit) |
| timestamps + softDeletes | | |

`unique(agency_id, signal_type, signal_value)`. On a subsequent match, `hits++`. **Difficult route:** the agent's manual link captures the attorney's own ref — we store `signal_type=external_ref` (the exact matter string) or `subject_pattern` (a captured regex where a series is obvious, e.g. `VDS/\d+/\d+`), scoped to that sender. A different matter number → no match → new correspondence → new manual link + learn. Learning is fully failure-isolated (try/catch; a learn failure never blocks the file).

### 3.6 Filing on verify

Filing routes through the existing `DealDocumentService::fileClassifiedDocument(Property, $attrs, $destination, $contacts, $actor, $deal)`:

- `$attrs` built from the `CommunicationAttachment` (original_name, storage_path, mime, size, `source_type='inbound_email'`, `source_id=communication_id`).
- `$destination` (property vs contact pillar) resolved from the attachment's doc-type via the AT-227 doc-type rules — CoC→property, invoice→contact (caller decides, as the service contract requires).
- `$deal` = the resolved `DealV2` twin.
- A `CommunicationLink(method=attorney_ref)` ties the email itself to the deal/property/contacts.
- Audit-logged (match decision + source: `cx_token` / `learned_ref` / `manual` / `party_email`) and comms-logged.

### 3.7 Suspense / review UI — the "spider-web" (both doors open)

Johan (c): the queue lives in **BOTH** places — **Deals AND Comms** — his "spider-web effect": never make a user navigate to one door when both can open. So the same review surface is reachable from the Deals area and the Comms area, plus the contextual strips. All read one `communication_filing_suspense` table and share one verify/link/reassign action:

1. **Global review queue** — reachable at **`/deals/comms-suspense`** (Deals nav) **and** surfaced in the **Comms** area (Comms nav) — one controller/view, two nav entries. Lists parked attorney emails awaiting first-verify, newest first, paginated, with the suggested deal + confidence chip + attachments preview. One-tap **Verify** (accept suggestion) or **Link to deal…** (property/deal picker for LOW).
2. **Contact screen** — a "To verify" strip on the linked attorney-provider-contact's comms area.
3. **Property screen** — a "Returns awaiting filing" strip on any property whose candidate deal has pending suspense.

No dead read-only states — every suspense row shows *why* it's here and offers the verify/link action (STANDARDS "No Silent Locks").

### 3.8 Edit / Reassign (Johan sign-off — new requirement)

An agent who linked (or verified) a mail against the **wrong deal/contact** must be able to **edit and fix it.** Reassignment is a first-class, audit-trailed operation — not a delete-and-redo:

1. From the deal's comms grouping, the contact screen, or the suspense queue, the agent opens a filed/verified correspondence and picks **Reassign → Deal/Contact…**.
2. On reassign, CoreX **re-files cleanly**: the old `CommunicationLink(attorney_ref)` and any files placed on the wrong deal's pillars are **withdrawn** (soft-unlinked — no hard delete, no orphan), and the correspondence + attachments re-file to the corrected deal + its pillars via `DealDocumentService`.
3. **The learned pattern is corrected**, not just the one email: the mis-learned `communication_learned_refs` row for that signal is re-pointed to the correct deal (or marked `is_verified=false` and re-learned against the correct deal), so future same-ref mail follows the correction — a wrong first-verify never poisons "the rest of the transaction" permanently.
4. **Audit-trailed**: every reassignment writes an audit entry (from-deal, to-deal, actor, timestamp, reason optional) and a comms-log note. Idempotent and transactional — a failed reassign rolls back to the prior clean state.

Prevent-or-absorb: reassign to the *same* deal is a no-op; reassign to a soft-deleted deal is refused with a clear message; the re-file reuses the idempotent `syncWithoutDetaching` filing so double-reassign never duplicates.

---

## 4. WHATSAPP ROUTE — person-level

### 4.1 Provider-contact WA resolution + the tickbox (Johan Q3)

- Add per-person controls on `agency_service_provider_contacts`: **`wa_allowed`** (bool, default false — the "WhatsApp allowed with this attorney" tickbox) and **`wa_number`** (string nullable, the linked WA number). (Firm-level `wa_number` default optional on `agency_service_providers`.)
- Extend `ContactIdentifierResolver`: when phone→Contact yields **no** match, attempt phone→`AgencyServiceProviderContact` where `wa_allowed=true` and `wa_number` matches (last-9 normalisation, same as contacts). A provider-contact match files the Communication linked to the **provider-contact** (`linkable_type=AgencyServiceProviderContact`) under its firm — person-level, exactly like a contact.
- `WaArchiveIngestor` threads under `wa:<last-9>` as today; `owner_user_id` = ingesting agent.
- **Match-or-create gate stays intact:** no `wa_allowed` provider-contact match and no Contact match → dropped as today (WA POPIA parity — we never ingest an un-opted supplier number).

### 4.2 AT-132 gate reuse — one honest deviation

The AT-132 gate keys on `(contact_id, thread_key)`. Provider-contacts are **not** Contacts. To reuse the gate verbatim in behaviour, `comms_access_requests` and `comms_thread_settings` gain a **nullable `provider_contact_id`** alongside `contact_id` (exactly one set), and `CommsAccessGrantService` accepts either subject. This is the **single declared deviation** from "AT-132 verbatim" — the request→grant→visibility flow, midnight/logout revocation, audit sink, and `scopeVisibleTo` composition are otherwise unchanged. (Alternative considered and rejected: minting a shadow `Contact` for every WA-enabled attorney — heavier, pollutes the contact directory, violates the hard-locked contact-type doctrine.)

### 4.3 Optional ad-hoc WA→deal link (affordance, never a gate)

An agent may manually attach a specific WA message/exchange to a deal — a single `CommunicationLink(linkable=DealV2, method=manual)`. Not a workflow, not required, no per-message tagging asked of anyone.

---

## 5. Data model / migrations

1. `communication_learned_refs` — new table (§3.5).
2. `communication_filing_suspense` — new table: `id, agency_id, communication_id (fk), channel, suggested_deal_id (fk deals.id, nullable), confidence enum(high|medium|low), status enum(pending|verified|dismissed), resolved_deal_id (fk, nullable), resolved_by_user_id (fk, nullable), resolved_at, learned_signal_type, learned_signal_value, timestamps, softDeletes`. Index `(agency_id, status)`.
3. `agency_service_provider_contacts` — add `wa_allowed` (bool default 0), `wa_number` (string nullable). (Optional firm-level `wa_number` on `agency_service_providers`.)
4. `comms_access_requests` + `comms_thread_settings` — add nullable `provider_contact_id` (fk) (§4.2).
5. **AT-228 outbound** — `DealPackMail` gains the `[CX-D{id}]` subject token + `Message-ID` header; `OutboundProvisionalLogger::logDistribution` writes the `thread_key` (currently `null`). No new table.
6. `CommunicationLink` — no migration; start **writing** the reserved `attorney_ref` method + `DealV2`/`Property` morphs.

All new tables carry `agency_id` + `BelongsToAgency` + `SoftDeletes` (non-negotiables #1, #7). Reference data: no global seed rows required; register nothing new in `deploy:sync-reference-data` unless a doc-type is added (none is).

---

## 6. Permissions

New keys in `config/corex-permissions.php` (+ sidebar gate + route middleware + controller checks):

- `deal_comms_suspense.view` — see the review queue / suspense strips.
- `deal_comms_suspense.resolve` — verify/link/dismiss a parked email (default: the deal's agent + roles holding existing deal-docs write).
- WA provider-contact threads reuse the **existing** AT-132 grant permissions (`communications.grant_access` etc.) — no new key.

Gating: an agent sees only suspense for deals they can see (deal visibility scope); resolve requires `resolve` + deal access.

---

## 7. Navigation

- **Deals sidebar → "Comms Suspense"** AND **Comms area → "To File"** — the same queue reachable from both doors (Johan's spider-web, §3.7c). Badge = pending count for the agent. Same-day nav entry (non-negotiable #2).
- Contact screen + Property screen strips are contextual entries (no new top-level nav needed).

---

## 8. User flows

**Email — easy route (happy path):**
1. Agent sends deal pack via AT-228 → subject carries `[CX-D{id}]`, outbound comm gets `thread_key`.
2. Attorney replies (COC attached). Poller ingests; sender = the deal's linked attorney; token resolves → **HIGH**.
3. Email parks; suspense row `pending`; a "Suggested: Deal 1234" chip appears on the contact + property screens and the queue.
4. Agent taps **Verify** → COC files to property (doc-type rule), link confirmed, learned-ref `cx-d1234` marked verified.
5. Next return on the same deal with the token → **VERIFIED-AUTO**, filed silently. Agent never touches it again.

**Email — difficult route (VDS):**
1. VDS emails using their own `VDS/2026/0912` ref, no CX token. Sender is a known attorney-firm → **parked**, resolves LOW (their ref means nothing to us yet).
2. Suspense shows the email with no confident suggestion. Agent picks the deal (**Link to deal…**).
3. On link: file + learn `external_ref = vds/2026/0912` (scoped to that sender). Next `VDS/2026/0912` email auto-files silently. A new matter `VDS/2026/1500` → new manual link + learn.

**Email — unknown sender:** not a known attorney-firm, not a Contact → **dropped** as today (POPIA scope). Nothing parked, nothing surfaced.

**WhatsApp — Linda at VDS:**
1. VDS provider-contact "Linda" has `wa_allowed=true` + `wa_number`.
2. Linda WhatsApps the agent. Resolver: no Contact match → provider-contact match → Communication files **on Linda under VDS**, threaded `wa:<9>`, AT-132 gated (only the participating agent sees; unlock via request-access).
3. No per-deal tagging asked. Optionally the agent ad-hoc-links one exchange to a deal.

---

## 9. Robustness (BUILD_STANDARD input-space + prevent-or-absorb)

- **Token stripped / mangled by attorney** → fall through to party-email anchors; worst case LOW → manual. Never a 500, never a silent drop of a *known-attorney* email.
- **Attachment > 25 MB** (existing `MAX_ATTACHMENT_BYTES`) → email still parks; oversized attachment flagged, not filed; agent notified. No crash.
- **Deal resolved but soft-deleted / merged** → suspense shows the deleted-deal note (BUILD_STANDARD §4 deleted-related-record); agent re-links. Never file to a trashed deal silently.
- **Token resolves cross-agency** → rejected (tenant guard), treated as no-token.
- **Same email delivered twice** (dedup on `agency_id+external_id`) → one suspense row; verify is idempotent; learned-ref `unique` prevents dupes; `hits++` only.
- **Two active deals share an attorney, token present** → token wins (id-exact); token absent → MEDIUM only if exactly one active deal, else LOW.
- **Provider-contact `wa_allowed` off** → WA never ingests that number (prevent).
- Learn + file each wrapped so a learn failure never blocks a file, and a file failure rolls back cleanly (no half-filed doc, no orphan link).

---

## 10. Acceptance criteria

**Email:**
- [ ] AT-228 outbound stamps `[CX-D{id}]` + a `Message-ID`, and the outbound comm persists a non-null `thread_key`.
- [ ] A token-carrying reply from the deal's attorney → HIGH → parks + suggests; agent verifies once; COC files to property, invoice to contact (doc-type routed).
- [ ] After first verify, a second same-token email **auto-files silently** (no suspense).
- [ ] A difficult-route email (own ref, no token) → LOW → agent links → learns → next same-ref email auto-files.
- [ ] An unknown-sender email is **dropped** (not parked) — POPIA scope proven.
- [ ] Suspense visible on the global queue + contact screen + property screen; one shared verify/link action; nav entry + permission gate present.

**WhatsApp:**
- [ ] A `wa_allowed` provider-contact's WA ingests + files **on that person under the firm**, threaded, AT-132-gated; no per-deal tagging asked.
- [ ] `wa_allowed=false` (or blank number) → not ingested.
- [ ] The optional ad-hoc WA-exchange→deal link works and is never required.

**Edit / Reassign (§3.8):**
- [ ] An agent can reassign a wrongly-linked correspondence to the correct deal/contact; old links + mis-filed docs withdraw cleanly (soft, no orphan); re-file to the correct pillars.
- [ ] The mis-learned ref is corrected so future same-ref mail follows the correction.
- [ ] Reassign is audit-trailed, idempotent, transactional; reassign-to-same is a no-op; reassign-to-deleted-deal is refused clearly.

**Cross-cutting:**
- [ ] Every match decision audit-logged with its source (`cx_token`/`learned_ref`/`manual`/`party_email`/`wa_person`).
- [ ] Tests cover: token-HIGH auto-suggest, first-verify gate, silent-auto after verify, difficult-route learn, unknown-sender drop, deleted-deal render, duplicate-email idempotency, WA provider-contact file, WA opt-out drop.

## 11. Files to create / modify (indicative)

**Create:** `app/Services/Communications/CorrespondenceMatchService.php`; `app/Models/Communications/CommunicationLearnedRef.php` + `CommunicationFilingSuspense.php`; migrations (§5.1–5.4); `app/Http/Controllers/DealV2/CommsSuspenseController.php`; `resources/views/corex/deals/comms-suspense/*`; suspense strip partials for contact/property; tests under `tests/Feature/DealV2/InboundCorrespondence/`.
**Modify:** `EmailArchiveIngestor.php` (park known-attorney unmatched; run `CorrespondenceMatchService`); `WaArchiveIngestor.php` + `ContactIdentifierResolver.php` (provider-contact resolution); `DealPackMail.php` + `OutboundProvisionalLogger.php` (token + thread_key); `CommsAccessGrantService.php` + AT-132 models (`provider_contact_id`); `AgencyServiceProviderContact.php` (wa fields); provider-contact edit UI (tickbox + number); `config/corex-permissions.php`; DR2 sidebar.

## 12. Build sequencing (phases, one concern per prompt)

1. **P1 — Outbound ref** (AT-228 token + Message-ID + thread_key). Small, unblocks matching. Tests: token present, thread_key persisted.
2. **P2 — Park + match + suspense (email core)**: flip drop→park (known attorney only), `CorrespondenceMatchService`, suspense table + queue UI + first-verify + filing.
3. **P3 — Learned refs** (both routes) + silent-auto.
4. **P4 — WhatsApp** provider-contact resolution + wa fields/tickbox + AT-132 `provider_contact_id` + ad-hoc link.

Each phase QA1-first, single-file tests, then Staging via m2. P4 (queue/WA-dependent) gets first QA on Staging per the QA-web-only rule.

## 13. Declared deviations / resolved decisions

- **Deviation (declared):** AT-132 gate gains a nullable `provider_contact_id` subject to serve non-Contact attorney persons (§4.2). Everything else is verbatim.
- **Token encodes `deals.id`, not `deal_no`** (§3.1) — stability over human-readability for the machine anchor; `deal_no` stays the visible label.
- **Johan sign-off (2026-07-17), all three resolved:**
  - (a) **WA number is per-CONTACT (per person)** — the BBB scenario (one firm, multiple attorneys + paralegals per transaction) is already served by the provider-contact model; WA rides the same person-level structure with per-person `wa_allowed` + `wa_number`. (No firm-level default in scope.)
  - (b) **Auto-suggest at MEDIUM** confidence; the agent still verifies the first email (§3.4). **Plus the new EDIT/REASSIGN requirement (§3.8).**
  - (c) **Queue lives in BOTH Deals AND Comms** (§3.7) — the spider-web; never one door when two can open.

## 14. Deliberately NOT in scope

- No per-message WA deal-tagging (rejected by Johan outright).
- No parking of unknown-sender mail (POPIA scope = known attorney-firm senders only).
- No new contact type for attorneys (contact types stay hard-locked to 6).
- No AI body-parsing of email content to guess deals — resolution is ref/anchor-driven, not LLM-guessed.

---

**Spec-conformance:** implements Johan's two-route design (§1.1) verbatim-anchored; single declared deviation from AT-132 (§4.2, §13). Applies non-negotiable #10 to correspondence; reuses AT-132, `DealDocumentService`, and the `pdf_splitter_learned_phrases` learn pattern.
