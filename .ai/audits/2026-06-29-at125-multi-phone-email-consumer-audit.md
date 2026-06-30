# AT-125 — Multi phone/email on Contacts: CONSUMER AUDIT (spec basis)

> **READ-ONLY.** Audit of every consumer of `contacts.phone` / `contacts.email` before the
> single-column → multi-identifier model change. This becomes the AT-125 spec.
> Date: 2026-06-29 · Branch: read-only (nothing changed) · Author: Claude (Opus 4.8)
> Method: 4 parallel code-sweep agents + direct reads of Contact, ContactDuplicateService,
> MarketingConsentService, ContactIdentifierResolver, marketing_suppressions migration.

---

## TL;DR

- **Identifier surface today = exactly two columns**: `contacts.phone` (varchar 255, **NOT NULL**) and
  `contacts.email` (varchar 255, nullable). **No index, no uniqueness** on either. No
  alt/secondary/mobile/cell column exists (though code already *references* dead `cell_number`/`mobile`
  fallbacks — latent multi-number intent).
- **~77 readers, ~15 writer sites, ~20 single-column-lookup sites** across the system. Phone/email are
  load-bearing across outreach, ingestion, dedup, FICA, documents/e-sign, exports, portal, mobile API,
  calendar, deals.
- **Consent is the sharp one.** Suppression is *stored* per-identifier (`marketing_suppressions`,
  keyed by normalised email / last-9 phone) but *applied* contact-level: `optOutContact()` suppresses
  **every** identifier the contact has **and** sets a contact-level `messaging_opt_out_at` flag. The
  send gate blocks if **any** identifier is suppressed OR the contact flag is set. → **An opt-out
  blocks the whole contact regardless of which identifier was used.** AT-125 must *preserve* this
  default (privacy-correct: a 2nd unsuppressed email must NOT make an opted-out person reachable).
- **Dedup/lookup is the blast radius.** "Find contact by email/phone" is a raw column expression
  (`RIGHT(REGEXP_REPLACE(phone,'[^0-9]',''),9)`, `LOWER(TRIM(email))`) in `ContactDuplicateService`
  and `ContactIdentifierResolver`, plus ~20 ad-hoc `where('phone'|'email')` / `LIKE` sites. Every one
  becomes a join under multi-identifier.
- **Recommended model**: `contact_phones` + `contact_emails` (BelongsToAgency + SoftDeletes, one
  `is_primary` per kind), **keep the singular `contacts.phone`/`email` columns as synced-primary
  mirrors** (back-compat shim) so the ~77 readers keep working; migrate readers/lookups incrementally;
  point AT-122 ingestion + dedup at the new tables. Full-deprecate later.

---

## 1. THE COLUMNS TODAY (identifier surface)

DB truth (`nexus_os`, authoritative):

| Column | Type | Null | Index | Unique |
|---|---|---|---|---|
| `contacts.phone` | varchar(255) | **NOT NULL** | none | none |
| `contacts.email` | varchar(255) | nullable | none | none |

- **No other identifier columns** on `contacts` (checked `phone|email|mobile|cell|fax|tel|contact_num|whatsapp` regex): only `phone`, `email`, plus the activity counters `whatsapp_count`/`email_count` and consent booleans `opt_out_email`/`opt_out_whatsapp`.
- `phone` is **NOT NULL** — every `Contact::create` must supply it (some paths pass `''`). The multi-model must preserve a non-null primary-phone mirror or relax this column.
- **No uniqueness** — duplicates are *detected* (ContactDuplicateService, soft-warn) not *prevented*. Multi-identifier doesn't break a unique constraint (there is none) but changes every "find by identifier" path.
- Model: `App\Models\Contact` — `phone`, `email` in `$fillable` (`Contact.php:37`). No accessor/mutator/normalisation on either (raw strings). Import spec (`.ai/specs/contacts.md:43-64`) already has a separate "Phone" (landline) column that maps to nothing today → pre-existing demand for a 2nd number.

---

## 2. EVERY READER of contacts.phone / contacts.email

### 2a. Outreach — send / merge-field / queue / reachability
- `SellerOutreachComposerService.php:89` — `normalisePhone($contact)` → recipient WhatsApp number.
- `SellerOutreachComposerService.php:340` — `normalisePhone` reads `$contact->phone ?? $contact->cell_number ?? $contact->mobile` (0→27). **`cell_number`/`mobile` columns don't exist → dead fallbacks today; the natural hook for primary-phone resolution.**
- `SellerOutreachComposerService.php:90,356` — `resolveEmail($contact)` reads `$contact->email`, lowercased → recipient email.
- `SellerOutreachComposerService.php:97,360-375` — `buildValidationIssues` → `no_phone` / `no_email` reachability flags.
- `SellerOutreachSenderService.php:111-112` — freezes `recipient_phone_snapshot` / `recipient_email_snapshot` onto the send (so the send record is immune to later identifier changes — good).
- `SellerOutreachSenderService.php:163-170` — `Mail::to($context->recipientEmail)` email dispatch.
- `SellerOutreachSenderService.php:183-198` — `whatsappUrl()` builds `wa.me/{phone}` from the snapshot.
- `SellerOutreachSenderService.php:223-233` — `mailtoUrl()` from the snapshot.
- `OutreachQueueService.php:64-65` — reachability gate `filled($contact->phone)` / `filled($contact->email)` at enqueue.
- `SellerOutreachLandingService.php:224,228-243` — agent callback WhatsApp link (agent's phone, not contact's).
- `OutboundProvisionalLogger.php:48` — `$channel===EMAIL ? $contact->email : $contact->phone` → `participant_identifiers` on the provisional comm (AT-59).

### 2b. Ingestion / dedup / resolver (becomes joins under multi-identifier — see §5)
- `ContactIdentifierResolver.php:39-45` — `resolveEmail()` = `whereRaw('LOWER(TRIM(email)) = ?')`.
- `ContactIdentifierResolver.php:48-59` — `resolvePhone()` = `whereRaw("RIGHT(REGEXP_REPLACE(phone,'[^0-9]',''),9) = ?")`.
- Consumers of the resolver: `EmailArchiveIngestor` + `WaArchiveIngestor` (AT-122 match-only gate), `WaIngestController.php:90` (contact-check), `MarketingConsentService.php:153`, `UnsubscribeController.php:34`.
- `ContactDuplicateService.php:53-56,147-155` — `findDuplicates` raw expressions on `phone`/`email`; `:111-112` normalizeValue; `:167` `identifyMatch` reads `$existing->{$field}`.

### 2c. Display — contact / buyer / match surfaces (~30 sites)
- Contact show: `corex/contacts/show.blade.php:83,88` (header phone + mailto), `:305,312` (JS WhatsApp/mailto), `:522,528` (edit inputs).
- Contact list: `corex/contacts/index.blade.php:535,540` (row), `:584,590` (inline edit).
- Match results: `corex/contacts/match-results.blade.php:8,112,113,473`.
- Core matches: `corex/core-matches/index.blade.php:105,108`; property show core-match block `corex/properties/show.blade.php:5641-5650`.
- Buyer detail: `command-center/buyers/detail.blade.php:59,60,224`.
- Buyer-matches panel: `prospecting/_buyer-matches-panel.blade.php:66,67,85,86,115,116`.
- Shared match component: `shared/match.blade.php:149-160`.
- Admin duplicate cleanup: `command-center/admin/duplicate-cleanup.blade.php:85`.
- Controller payloads: `ContactController.php:133-134`; `MobileCoreMatchController.php:39-40,89-90,196`; `MobilePropertyController.php:992-993`; `Api/V1/ClientPortalController.php:623-624`; `PropertyContactController.php:371-372`; `MobileContactController.php:165`.

### 2d. Exports
- `ContactExportController.php:97` (email → "Email"), `:98` (phone → "Cell"), `:109` (`email_count`).

### 2e. Documents / e-sign / viewing packs
- `Docuperfect/RoleBlockExpansionService.php:1831,1835` — `{email}`/`{phone}` merge-field expansion in templates (mandates etc.).
- `Docuperfect/ESignWizardController.php:546-547` — passes contact email + phone(as `cell`) to the e-sign template.
- `viewing-packs/show.blade.php:374-375` — buyer phone/email into the JS data structure.

### 2f. FICA / compliance
- `Compliance/FicaController.php:131,150` (send FICA request to `contact->email`), `:436-437` (approval mail), `:466-467` (rejection mail), `:472` (display).
- `compliance/fica/index.blade.php:166` (list display).
- `Compliance/FicaWetInkService.php:70-71` (email+phone into the wet-ink payload).
- `Compliance/WhistleblowComplaintService.php:400` (email for complaint logging).

### 2g. Calendar / deals / notifications
- `ContactMatchController.php:267-268` — sets `deal.buyer_email`/`buyer_phone` from the contact (deal sync — a *copy*, not a live read).
- `Notifications/Presentations/TeaserLeadCapturedNotification.php:49-50` (lead, not contact, but same shape).
- Mobile WhatsApp link builders: `MobileContactController.php:165`, `MobileCoreMatchController.php:196`.

---

## 3. EVERY WRITER of contacts.phone / contacts.email

### 3a. Form create/edit (+ validation rules)
- `ContactController.php:401-402` (`phone` `required|string|max:30`; `email` `nullable|email|max:150`), `:517` create, `:594-595` update rules, `:644` update.
- `PropertyContactController.php:198-199` rules, `:280` `Contact::create`.
- `MobileContactController.php:102-103` create rules, `:125` create, `:138-139` update rules.
- Form fields: `corex/contacts/show.blade.php:522,528`; `corex/contacts/index.blade.php:584,590` (inline edit POST).

### 3b. Import / ingestion contact-creation
- `ContactImportController.php:38-46` (header map incl. `cell`→`phone`), `:229-235` (phone fallback from `phone_secondary` — **note: import already merges a secondary number into the single column**), `:286-303` create.
- `Property24/P24LeadService.php:325-337` (`new Contact` email/phone).
- `Presentation/PublicPresentationController.php:693-706` (teaser lead → contact).
- `SellerOutreach/EntryPointController.php:167-184,388-403` (prospect/tracked-property contact create).
- `Docuperfect/ESignWizardController.php:840-847` (signer → contact, email only, **no phone** — would violate NOT NULL unless defaulted).
- `Communications/CommunicationTriageController.php:60-65` (triage "add contact").
- `PrivateProperty/PpWebhookController.php:124-129` (PP portal webhook).
- `CoreX/PropertyController.php:877` (`Contact::create` from property inline-create).

### 3c. Match-or-create / API
- `MobileContactController.php:110-112` (dup check `where('phone')->orWhere('email')`), `:125` create.
- `MobilePropertyController.php:950-952` dup check, `:960-971` create.

> **No outreach-domain writer** touches `Contact.phone/email` — outreach only reads then snapshots to the send. Writers are concentrated in the form, the importers, and the API.

---

## 4. CONSENT / SUPPRESSION — per-identifier semantics (THE SHARP ONE)

**Three layers, all currently collapsed to "contact-level opt-out":**

1. **Identifier-level store** — `marketing_suppressions` (migration `2026_06_16_190002`): keyed by
   `(agency_id, identifier, identifier_type, lifted_at IS NULL)`, where `identifier` = lowercased email
   or last-9 phone. **NOT** keyed by `contact_id` (contact_id is a nullable annotation). Never hard-deleted; opt-in sets `lifted_at`. → "one opt-out, suppressed everywhere" by *identifier value* (survives re-import/duplicate contacts).
2. **Contact-level flags** — `contacts.messaging_opt_out_at` (+ `_reason/_source/_kind`), `messaging_all_blocked` (latch: "stop ALL" vs "marketing-only"), `messaging_opted_in_at`, and the per-channel booleans `opt_out_email/sms/whatsapp/call`.
3. **Derived state** — `Contact::communicationStatus()` (`Contact.php:668-686`) + the 5-state outreach doctrine derive from layer 2.

**How an opt-out is written (`MarketingConsentService::optOutContact()`, `:62-129`):**
- Iterates `contactIdentifiers($contact)` (`:362-371` → ONE email + ONE phone today, phone via `phone ?? cell_number ?? mobile`) and writes a suppression row **per identifier** (`:116-127`).
- Sets the contact-level `messaging_opt_out_at` (`:73-80`), `messaging_all_blocked` (`:95-97`, raise-only), and the per-channel booleans (`:103-110`).
- Entry points: per-send opt-out link → `PublicOptOutController.php:70` → event → `RecordOptOutOnContact.php:40-47` → `optOutContact()`; generic `/unsubscribe` page → `UnsubscribeController.php:60-65` → `optOutByIdentifier()` (`:139-173`): matched contact → `optOutContact()` (suppress ALL its identifiers); **no** match → writes the ONE typed identifier with `contact_id=null`.

**How the gate reads it (blocks if EITHER fires):**
- Composer pre-flight `SellerOutreachComposerService.php:101-102` — `messaging_opt_out_at !== null || isContactSuppressed($contact)`.
- `MarketingConsentService::marketingBlockReason()` `:296-319` — (1) `isContactSuppressed` (`:233-244`: any of the contact's identifiers has an active suppression) → `suppressed`; (2) `communicationStatus() !== OPTED_IN` → status; (3) `isOutreachPending` → `pending`; (4) `!canSendVia(channel)` → `channel_opted_out`.
- `Contact::canSendVia()` `:548-571` — per-channel boolean, and if `messaging_all_blocked` → `!isContactSuppressed($this)`.
- Re-checked at queue-surface (`SurfaceDueOutreachQueue.php:59-60`) and at send (`SellerOutreachSenderService.php:51-63`).

**The semantics, stated plainly:** today **contact = 1 email + 1 phone**, and an opt-out on *either* suppresses *both* identifiers + sets the contact flag → the whole contact is unreachable on all channels/identifiers. `isContactSuppressed` checks **all** of the contact's identifiers, never the specific one being sent to.

**Implication for AT-125 (the design decision to lock):**
- The privacy-correct DEFAULT the prompt wants — "a 2nd unsuppressed identifier must NOT make an opted-out person reachable" — is **already** the behaviour, *because* the gate checks ALL identifiers and the contact flag. To **preserve** it under multi-identifier, AT-125 should keep `optOutContact()` suppressing **every** identifier (all N emails + N phones) **and** the contact-level flag, and keep `isContactSuppressed()` scanning **all** identifiers. That is the safe default: **contact-level opt-out stays contact-level.**
- Optional future granularity (NOT default): per-identifier reachability (suppress only the identifier that opted out) would need (a) `contactIdentifiers()` to enumerate all rows, (b) the gate to check the **specific recipient identifier** not the whole contact, (c) the opt-out flow to carry *which* identifier opted out, (d) a way to distinguish "stop this address" vs "stop all". The store is already identifier-keyed and ready; only the *application* logic assumes contact=2 identifiers. Recommend shipping AT-125 with contact-level default and leaving per-identifier reachability as an explicit follow-up.

---

## 5. UNIQUENESS / DEDUP — single-column-lookup assumptions (blast radius)

**Uniqueness:** none on `contacts.phone`/`email` today → no constraint to migrate. Dedup is detection-only.

**Match/dedup engines (canonical — must move to joins on the new tables):**
- `ContactDuplicateService::findDuplicates` `:42-58` + `normalizeDbExpression` `:147-155` (`RIGHT(REGEXP_REPLACE(phone,'[^0-9]',''),9)`, `LOWER(TRIM(email))`) — raw column expressions.
- `ContactIdentifierResolver::resolveEmail/resolvePhone` `:39-59` — same expressions; this is the AT-122 ingestion gate, so multi-identifier matching **directly enables AT-122 to match against ALL identifiers** (the stated goal).
- `ContactDuplicateService::identifyMatch` `:167` — `$existing->{$field}` single-attribute read.

**Ad-hoc single-column lookups that assume the column (each becomes a join / `whereHas`):**
- Search (LIKE): `ContactController.php:79-80`; `PropertyContactController.php:88-89`; `DealV2Controller.php:269-270`; `ESignWizardController.php:1137-1140`; `MobileContactController.php:56-57`; `CalendarController.php:1758-1759,1778`.
- Duplicate/find-by-identifier: `ContactController.php:368,371` (checkDuplicate); `ContactImportController.php:248-253`; `MobileContactController.php:110-112`; `MobilePropertyController.php:950-952`; `EntryPointController.php:479,490` (raw normalised); `PublicPresentationController.php:235-245` (teaser-lead match).
- Signer/identity matching: `ESignWizardController.php:797`; `SignatureService.php:1779,2080` (`Contact::where('email', …)->first()`).
- (Same pattern on non-Contact tables — `P24LeadService.php:242-243` on PortalLead — out of scope but mirrors the shape.)

**Net:** ~20 lookup sites assume `where('phone'|'email')` on the contacts row. Under multi-identifier these must query the child tables (`whereHas('phones'/'emails', …)` or a normalised join). The two **canonical** resolvers (`ContactDuplicateService`, `ContactIdentifierResolver`) are the high-leverage fix — if every lookup is routed through them, the ad-hoc sites collapse to a handful.

---

## 6. PROPOSED MODEL + migration (report only — pick at spec sign-off)

### Option A (recommended) — `contact_phones` + `contact_emails`, singular columns kept as synced-primary mirror
- Two child tables, each: `id`, `agency_id` (BelongsToAgency), `contact_id` (FK cascade), `value` (raw), `normalised` (last-9 phone / lower(trim) email — **indexed**, the match key), `is_primary` (exactly one true per contact per kind), `label`/`type` (nullable: mobile/home/work), `source` (nullable), timestamps + **SoftDeletes** (non-negotiable #1). Unique-ish: `(agency_id, normalised)` indexed for fast resolve (NOT unique — duplicates across contacts are real and intentional, mirroring suppression's non-unique key).
- **Keep `contacts.phone` / `contacts.email`** as **synced mirrors of the primary** (a model observer / sync service writes the primary value back to the singular column on save). → All ~77 readers and the `phone` NOT-NULL constraint keep working **unchanged**; migration is non-breaking; readers move to the relation **incrementally**.
- Point the **canonical resolvers** (`ContactDuplicateService`, `ContactIdentifierResolver`) at the child tables **first** (one change fixes AT-122 ingestion match-against-all + dedup-against-all). Migrate display/search readers to show/search all identifiers over time.
- **Migration of existing data (no loss):** for every contact, insert its current `phone` → one `contact_phones` row `is_primary=true` (skip empty), and `email` → one `contact_emails` row `is_primary=true` (skip null/empty). Idempotent, backfill in chunks, dropping global scopes (cross-agency, console context). Keep the singular columns populated.
- **Blast radius:** LOW to ship (mirror keeps everything green), MEDIUM to fully realise (incrementally rewrite ~20 lookups + ~30 display sites to use all identifiers). The mirror is a deliberate, documented bridge — not a shortcut — with a clear deprecation path.

### Option B — unified `contact_identifiers` (kind enum email|phone) single table
- One table, `kind` discriminator, `is_primary` per (contact, kind). Fewer tables, but every query filters `kind` and the normalisation differs per kind (phone last-9 vs email lower) — slightly messier indexes and validation. Same migration + mirror approach. Marginally less clear than A; A's two-table shape matches the two distinct normalisation rules and the existing `marketing_suppressions.identifier_type` split.

### Option C — full deprecation of singular columns now
- Drop `contacts.phone`/`email`, rewrite all ~77 readers + ~20 lookups + NOT-NULL constraint in one pass. **Highest blast radius**, touches FICA/e-sign/exports/mobile-API/portal simultaneously, and fights non-negotiable #6 "no half-built". **Not recommended for the first cut** — do A now, schedule C as a later cleanup once readers are migrated.

### Back-compat tradeoff (the decision)
- **Mirror (A/B): safe, incremental, larger surface kept alive temporarily.** The singular column lies slightly (only the primary) but every existing consumer is correct for the primary, which is the status-quo today. Recommended.
- **Full deprecate (C): clean end-state, huge single-PR blast radius, high regression risk across compliance-critical paths.** Defer.

### Cross-cutting must-dos for the build (from this audit)
- **Consent**: keep contact-level opt-out (suppress ALL identifiers + flag); `optOutContact()` iterates the new child tables; `isContactSuppressed()` scans all rows. Do NOT silently enable per-identifier reachability (separate, explicit ticket).
- **AT-122 tie-in**: route ingestion matching through the child-table resolver so a message matches **any** of a contact's identifiers (the stated motivation).
- **NOT NULL**: preserve a non-null primary-phone mirror (or relax `contacts.phone` to nullable in the same migration) — several create paths pass `''`/omit phone (`ESignWizardController` signer create has email only).
- **Sends are safe**: `SellerOutreachSend` already snapshots `recipient_phone_snapshot`/`recipient_email_snapshot` — historical sends are immune to identifier edits; no migration needed there.
- **Exports/import**: the import format already carries `cell`+`phone_secondary` merged into one column (`ContactImportController.php:229-235`) — the new model can finally store both instead of collapsing.

---

## Open questions for spec sign-off
1. **Default suppression scope** — confirm contact-level (recommended) vs per-identifier reachability for v1.
2. **Mirror vs deprecate** — confirm Option A (keep synced-primary singular columns) for the first cut.
3. **`contacts.phone` NOT NULL** — keep (mirror always populated) or relax to nullable now.
4. **Primary-change UX** — how the contact edit form sets which phone/email is primary (and whether removing the primary auto-promotes the next).
5. **Dedup uniqueness** — introduce a soft unique index on `(agency_id, normalised)` per kind for faster resolve, or keep non-unique (duplicates are legitimate)?
