# E-Sign Phase 1 — file-level implementation plan (mandate-ceremony lane)

> **Purpose:** zero planning delay. The moment m4's EATS walk-test gates green, this file is
> opened and code is written — no design work left to do.
> **Scope:** P1-2 onward (P1-0 walk-test and P1-1 import are m5/m4's).
> **Doctrine:** `.ai/specs/esign-ceremony-v3.md` (canonical — decisions are **§15**, recipient
> sourcing **§2.1**, distribution **§11-B**, lapse/revival **§11-A**).
> **Sizing basis:** `.ai/audits/2026-07-11-esign-v3-gap-analysis.md`.
> **Author:** m6. **Status:** ready to execute. Every path below is verified against `main`.

---

## 0. The gate, and what happens on each outcome

| Walk-test outcome | What this plan does |
|---|---|
| **GREEN** | Execute §7 build order from HD-1. Nothing to re-plan. |
| **RED (contract path fails)** | **STOP.** Phase 1 re-inflates (the mandate long pole goes M → L) and the estimates here are void — the surfaces would have to be built, not just populated. Re-cut with Johan before writing code. |
| **RED (translation-layer only, as of 2026-07-11)** | No impact on this plan. m5's fix is upstream of everything here; the ceremony engine legs were proven green on contracted templates. |

---

## 1. What Phase 0 already handed us (build ON these, don't rebuild)

| Asset | Where | Use in Phase 1 |
|---|---|---|
| ~~`EsignEligibilityService`~~ **DOES NOT EXIST** (corrected 2026-07-14, HD-2) | — | This plan asserted it as a shipped Phase-0 asset. It appears nowhere in the codebase but this file. The canonical "may this be e-signed?" predicate is **`Template::isEsignBlocked()`**, backed by `Template::booted()`, which refuses to persist `is_esign=true` on an alienation document. Call the model. Do not build the service. |
| `SignatureService::isAwaitingAgentReview()` | `SignatureService.php` | The canonical "does the agent owe this document an action?" — the **tracker (P2-2) reads this**, it does not re-derive it. |
| Amendment retains prior marks | `SignatureService::requeueAllPartiesForInitialing()` | **The engine P4's revival rides on.** The void-and-re-sign path was removed in P0-6, so it is now safe to build on. |
| Immutable audit log | `SignatureAuditLog` (P0-4) | The evidence timeline (P2-3) is a **read** over this. Do not add a second log. |
| Server-side consent enforcement | `SigningController` (P0-3, m5) | The substrate for the client consent profile (§4 below). |

---

## 2. The ceremony state machine (design — settle this before any code)

### 2.1 States

`signature_templates.status` is already a 20-value enum. **Four states are NEW** (§11-A):

```
lapsed · extension_proposed · revived · re_lapsed
```

Everything else the ceremony needs already exists: `signing`, `pending_agent_approval`,
`amendment_review`, `amendment_initialing`, `awaiting_supervisor(_final)`, `completed`.

### 2.2 Transitions (the whole machine, one table)

| From | Event | To | Guard |
|---|---|---|---|
| `signing` | last member of a **group** completes | `pending_agent_approval` | group-complete, not party-complete (§3) |
| `pending_agent_approval` | agent `approveAndAdvance()` | `signing` (next group) **or** `completed` | no further groups → complete |
| `pending_agent_approval` | agent returns with notes | `signing` (same group) | existing `returned_notes` |
| *any live state* | **legal deadline passes** | **`lapsed`** | `legal_deadline_at < now()` |
| `lapsed` | party proposes date extension | **`extension_proposed`** | any party (agency-configurable) |
| `extension_proposed` | agent approves + **ALL** parties initial | **`revived`** | all-party initial is **fixed, not configurable** |
| `revived` | new deadline passes incomplete | **`re_lapsed`** | same rules apply again |
| `lapsed` / `re_lapsed` | *(signing attempt)* | **BLOCKED** | hard stop — a post-deadline signature is null and void |

### 2.3 The hard block (§11-A.1)

**Do not write a new guard.** `SigningController` already checks `SignatureRequest::isExpired()` at
~20 entry points (GET → expired view, POST → **410**). That machinery is right; it is keyed to the
wrong clock (a 14-day link TTL, not a legal date).

- **Add** `SignatureTemplate::isLapsed(): bool` — `legal_deadline_at` is past AND status is not terminal.
- **Extend** the existing guard helper in `SigningController` so every entry point tests
  `isExpired() || template->isLapsed()`. One change, ~20 sites inherit it.
- The pen must stop working. **A mark that would be legally worthless is never collected.**

---

## 3. Party groups & agent checkpoints (§4)

**Today:** strictly one-signer-at-a-time by integer `signature_requests.signing_order`, with an
agent checkpoint after **every** external party (`handlePartyCompletion()` → `pending_agent_approval`).
Note this is *more* friction than the doctrine, not less. There is **no group concept anywhere**
(verified: `signing_group`, `party_group`, `group_order` → zero hits).

**Migration (NEW):**

```
signature_requests:  + signing_group  (unsigned tinyint, default 1, indexed with signing_order)
signature_templates: + group_order_json (json, nullable)   -- ordered group definitions for this ceremony
```

**Extend, don't replace:**

- `SignatureService::handlePartyCompletion()` — the checkpoint fires when **every request in the
  current group** is `completed`, not when any single party is. Sequential *within* a group is
  today's behaviour and stays.
- `SignatureService::approveAndAdvance()` — releases the **next group** (all its members at once)
  instead of the next single request.
- `advanceToNextParty()` — selects by `(signing_group ASC, signing_order ASC)`.

**Locked orders (§4):** OTP `purchasers → agent → sellers → agent`; Mandate `sellers → agent`.
Group composition and whether the checkpoint is mandatory are **per-pack-template config** (§6),
default **mandatory**.

---

## 4. Consent profiles (§2) — mostly already true

| Profile | Rule | Status |
|---|---|---|
| **Professional (agents)** | adopt once, apply to all surfaces the agent role owns | **SHIPPED** (FIX 2 — apply-to-all gated on `isAgent`) |
| **Client (parties)** | every surface is a deliberate, recorded consent action; **no silent apply-all** | **Substrate shipped by P0-3** (server-side enforcement). Launch reuses `esign_consent_log` + `ConditionInitial` (both already immutable). |

**Launch scope:** the *gate* is enforced; the **per-surface consent-event ledger is POST-LAUNCH**
(gap #14b, **L**). Do not build the ledger in Phase 1 — the legal essentials are already covered.

`agencies.esign_consent_profile_overrides` (json) is the knob, but **default behaviour needs no
config** — agent = professional, all client parties = gate-per-surface.

---

## 5. Distribution on completion (§11-B) — **read this before touching `completeDocument()`**

Three of the four requirements are **already as-built**: the trigger (only `supervisor_final`
completes a candidate flow), the recipients (completed non-agent signers), and "signed documents
only" (attachments are never touched).

**The one real gap is HOW — and it is a SEQUENCING problem, not a mapping problem.**

`SignatureService::completeDocument()` currently runs, in this order:

```
3. sendCompletionEmails()      ← attaches ONE merged "Signed - {name}.pdf"
4. linkDocumentToContacts()
5. autoFileSignedDocument()    ← THIS is what produces the per-document PDFs
6. createLeaseRecord()
```

**Distribution runs BEFORE the per-document PDFs exist.** That is why it sends a merged file — not
because anyone chose to.

**The fix:**

1. `filePackDocuments()` — change from `void` to **return the per-document paths it wrote**
   (`.../signed-documents/{sigTemplateId}/individual/{templateId}_client.pdf`). It already writes
   them; it just discards the paths.
2. `autoFileSignedDocument()` — return them up.
3. **Reorder `completeDocument()`: file (5) BEFORE email (3).**
4. `sendCompletionEmails()` — attach the **N per-document PDFs**, not the merged one.
   **Guard: attach ONLY documents that were signed.** Pack *attachment slots* must be excluded by
   an explicit filter, not by luck — once this loops the pack, an attachment is one iteration away
   from mailing a party's FICA evidence to every other signer.
5. `SignatureAuditLog::ACTION_SIGNED_PDF_EMAILED` — write it **per document per recipient**, so the
   evidence timeline can answer *"was the Disclosure sent to the purchaser?"* and not merely
   *"was something sent?"*.

**Failure containment:** the post-completion block is already wrapped so a delivery/filing problem
can never roll back a validly completed signing. Keep it that way.

---

## 6. Agency-configurable knobs (§12)

`config/signatures.php` is **global, not per-agency**. Follow the existing `agencies.deal_v2_*`
pattern.

**Launch needs only these** (do not build all 14):

```
agencies: + esign_checkpoint_mode        (enum: mandatory|auto_advance, default 'mandatory')
          + esign_reminder_cadence_json  (json, nullable — overrides config/signatures.php)
          + esign_witnesses_enabled      (bool, default FALSE)   -- §8: default OFF
```

Extension consent is **deliberately NOT configurable** — all-party initial is fixed for legal
integrity (§11-A.2).

---

## 7. Build order — half-day increments

Each HD is one concern, ends green on its own test file, and is committable.

### Track A — the mandate pack can go out (HD-1 → HD-4)

| HD | Ticket | Files |
|---|---|---|
| ~~**HD-1**~~ **✅ LANDED** `8d4b1e9c` | **P1-3 · Recipients from the property-link role** (§2.1) | `Property::esignRoleForPivotRole()` + `PIVOT_NON_SIGNING_ROLES`; `ESignWizardController::resolveLinkedContactRole()` — link role primary, global type demoted to legacy-link fallback. `scopeForEsignRole()` needed no demotion (**zero callers**); the primary source was an inline join. **Extra defect found & closed:** portal lead services link enquirers with `contact_property.role='lead'` and type them "Buyer" — the old global-type read offered them as **signing recipients on the mandate**. 11 tests, both mechanisms RED-proofed. |
| ~~**HD-2**~~ **✅ LANDED** `d20c8f58` | **P1-2a · Web-pack slot resolution** | `WebPackSlotResolver` + `WebPackSlotException`; wired into `store()`. **The client-side slot picker already existed** (`wizard.blade.php::_buildPackSlots`) — the gap was entirely server-side: `store()` fed the client's `resolved_template_ids` straight to `Template::find()`. **Extra defect found & closed — the ECTA §13(1) hole:** the alienation-document block is gated on `!$isPackFlow`, and the web-pack path ran **no** eligibility check at all (not even `is_esign`), so a **sale agreement inside a web pack was one click from being e-signed, and therefore void.** Also closed: any template id in the DB was accepted; a required doc could be dropped by omitting it from the post. 13 tests, RED-proof fails 9/13. |
| **HD-3** | **P1-2b · Send-time variant picker + seed the Sales Mandate Pack** | **Picker UI mostly EXISTS** (see HD-2) — verify it against the resolver's contract rather than rebuilding it; the real work is the **seeder**. **The settled spec's pack composition (templates 116/117/119) DOES NOT EXIST in any database** — compose from m5's P1-1 imports. Register the seeder in `deploy:sync-reference-data` (AT-162 class — seeders do not run on a `git pull`). |
| **HD-4** | **P1-5 · Editable fields + P1-6 amount-in-words** | Populate `field_mappings.editable_by` on the mandate templates (infrastructure exists; **no template uses it**). `WebTemplateDataService::numberToWords()` takes an **`int`** — it **drops cents** and has no "Rand". Fix + reconcile with the CDS parser's `deal.amount_words` binding (two vocabularies, one concept). |

### Track B — the ceremony behaves (HD-5 → HD-8)

| HD | Ticket | Files |
|---|---|---|
| **HD-5** | **P2-1a · Groups (schema + advance)** | Migration (§3). `SignatureService`: `handlePartyCompletion()` fires the checkpoint on **group** completion; `approveAndAdvance()` releases the **next group**; `advanceToNextParty()` orders by `(signing_group, signing_order)`. |
| **HD-6** | **P2-1b · Checkpoint config + locked group orders** | `agencies.esign_checkpoint_mode`; `signature_templates.group_order_json`; seed Mandate = `sellers → agent`. |
| **HD-7** | **P2-4 · Completion distribution** (§5 above) | `SignatureService`: `filePackDocuments()` returns paths → `autoFileSignedDocument()` returns them → **reorder `completeDocument()` (file before email)** → `sendCompletionEmails()` attaches N documents → per-document audit rows. **Explicit attachment-exclusion filter.** |
| **HD-8** | **P2-2 · Signing Tracker screen + nav** | New Blade + controller. Reads `isAwaitingAgentReview()`, `sent_at`/`viewed_at`/`completed_at`, `reminder_count`. Actions: `resendNotification()` / `sendManualReminder()` (both exist). **Nav entry same day** (non-negotiable #2) + permission key in `config/corex-permissions.php`. |

### Track C — lapse, extension, revival (HD-9 → HD-12) — *the launch cut's likeliest casualty*

| HD | Ticket | Files |
|---|---|---|
| **HD-9** | **P4a · Legal deadline** | Migration: `signature_templates` + `legal_deadline_at` (timestamp, null), `+ deadline_source` (varchar: `mandate_expiry`\|`otp_irrevocable`\|`manual`). Source for a mandate = **`properties.expiry_date`** (verified to exist). Populate at dispatch. |
| **HD-10** | **P4b · Hard block** | `SignatureTemplate::isLapsed()`; extend `SigningController`'s existing expiry guard so all ~20 entry points inherit it (§2.3). A lapsed ceremony **will not accept a mark**. |
| **HD-11** | **P4c · Lapse states + sweeper** | Add the 4 statuses. Extend `ExpireSignatureRequests` (`signatures:expire`, daily 07:00) to lapse ceremonies whose legal date has passed — a **recorded transition**, never a silent expiry. |
| **HD-12** | **P4d · Strike-and-fill extension → revival** | New `DocumentAmendment::TYPE_DEADLINE_EXTENSION`. **Rides `requeueAllPartiesForInitialing()`** (all parties initial the new content — exactly the doctrine, and safe now that P0-6 removed the void path). Old date **struck and preserved on the page**, new date alongside. All parties initial → `revived`. |

### Track D — evidence (HD-13)

| HD | Ticket | Files |
|---|---|---|
| **HD-13** | **P2-3 · Evidence report** | A **read** over `SignatureAuditLog` (immutable since P0-4). Attributed timeline: handoffs, views, nudges, deadline breach. Reachable from the tracker row and the owning deal/listing. **Auto-generates on lapse** (§12 default). |

> ### ✂ If the date bites
> **Track A + Track B + HD-13** is the honest minimum. **Track C (lapse/revival) goes first** —
> it is the largest genuinely-new block, and if it slips, mandate expiry stays a human discipline,
> which is exactly what it is today.

---

## 8. Gotcha register (things that will bite, in the order they will bite)

1. **`getChanges()`, never `getDirty()`** in any observer — a nested `updateQuietly()` calls
   `syncOriginal()`, so `getDirty()` is already empty. This silently killed all P24 auto-sync once.
2. **Templates 111 / 116 / 117 / 119 do not exist in any database.** Repo blade + seeder artifacts
   only. Do not build the pack against them.
3. **The e-sign pipeline gate** (`scripts/dev-check.ps1`): touching `Template.php`, `CdsDraft.php`,
   `SigningController.php`, `SignatureSurfaceNormalizer`, `RoleBlock*`, `InsertableBlockRenderer`,
   `MergedHtmlFreshnessGuard` or `LetterheadRefresher` **requires** a test diff in
   `tests/Feature/Docuperfect/SigningView/`. HD-10 touches `SigningController` — **budget for it**.
4. **`splitMergedHtml()` is attribute-order dependent by history** — `stampDisclosureDocKeys()`
   injects an attribute between `<div` and `class=`. P0-5 made a mismatch a hard stop; do not
   re-introduce a fallback.
5. **Never `location.reload()` in a signing view** — it wipes captured-but-unsubmitted signatures.
   Inline mutations return a server-rendered `rendered_row` and patch one node (STANDARDS.md, P0 invariant).
6. **Seeders do not run on a `git pull` deploy** — any global reference row must be backfilled in a
   migration or registered in `deploy:sync-reference-data` (AT-162).
7. **Terminal ≠ removed.** Never write a portal/ceremony "off" state for something that is still live.
8. **First test in a run takes ~3 minutes** (schema-snapshot bootstrap); every test after it ~0.2s.
   Not a hang. Run the **single relevant test file** only — never a broad suite (CLAUDE.md #13).

---

## 9. Definition of done for the lane

- An admin composes a pack of ordered slots; an agent resolves each slot to a variant at send; the
  bundle goes out as **one ceremony**.
- Recipients are offered from the **property-link role** — a global "seller" linked here as a buyer
  is offered as a **buyer**.
- Signing runs **group by group** with an **agent checkpoint between groups**.
- Clients consent at **every** surface; agents sign on the **professional profile**.
- On completion the ceremony **files as many independent documents** and **distributes them as many
  attachments** to the parties who signed — never one merged file, never a supporting document.
- A **lapsed ceremony cannot be signed**; the only way back is a **visible strike-and-fill date
  extension that every party initials**.
- The agent can always see **who holds the document and for how long**, and a lapse produces an
  **evidence report that attributes the delay**.
- Every new screen has a **navigation entry and a permission key**, the day it ships.
