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
| ~~**HD-3**~~ **✅ COMPLETE** | **P1-2b · Send-time variant picker + pack composition** | Picker proven at the HTTP boundary (6 tests). **Pack composition RESOLVED (D-1, Johan 2026-07-15): the web-pack system IS the spine — no seeder.** Investigation found the whole chain already built (builder → picker → resolver → boundary); reworked to spec the mechanism (`esign-ceremony-v3.md` §1.1) + prove Johan's exact Sales Mandate Pack (Mandate one-of · Disclosure required · FICA one-of — `WebPackSlotResolutionTest`, 15 green). See §10 D-1. |
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

---

## 10. Open decisions — parked here rather than stalling a slice (m6, night of 2026-07-14)

### D-1 — ✅ RESOLVED (Johan, 2026-07-15). There is NO seeder; the web-pack system is the spine.

Johan's ruling, verbatim: *"in documents we created web packs. this should be in the spec. web packs
were made for esign where one can pick — a mandate pack consist of mandate, mandatory disclosure,
fica — this allows the user to then pick — open or exclusive mandate, mandatory, and which fica is
applicable."*

**The Sales Mandate Pack is not seeded — it is BUILT by a human through Documents → Web Packs**, as
agency data, using the slot vocabulary that already exists. Composition = **Mandate** (selectable:
Open/Exclusive) · **Mandatory Disclosure** (required) · **FICA** (selectable: applicable one). The
agent's picks are made at send time through the existing wizard picker.

**Investigation finding: the entire chain was already built and needed NO new code.**
Documents → Web Packs (`WebPackController`, full CRUD, soft-delete, `access_docuperfect_packs`,
sidebar entry) → the builder (`web-packs/form.blade.php`, required/selectable-grouped/optional +
label) → the wizard picker (`esign/wizard.blade.php` step 1: radios per selectable group, checkbox
per optional) → `WebPackSlotResolver` (HD-2) → proven at the HTTP boundary (HD-3). The web-pack
system predates Phase 1 exactly as Johan said. HD-3's rework was therefore: **drop the seeder**,
**spec the mechanism** (`esign-ceremony-v3.md` §1.1 — the builder is the surface + the canonical
Sales Mandate Pack recipe), and **prove Johan's exact composition** (two independent selectable
groups + one required, `WebPackSlotResolutionTest` — 15 tests green).

_Original blocker text kept below for the record._

### ~~D-1 (HD-3 seeder) — what IS the Sales Mandate Pack? Needs Johan or m5. Not a lane call.~~ (superseded above)

The picker and the server-side resolver are done and proven. The seeder that creates the actual pack
is **blocked on a fact nobody has written down**: which templates the pack contains, and which of
them are the `selectable` variants of a mandate choice.

Why I did not simply pick:

- The spec's stated composition (**templates 116 / 117 / 119**) **exists in no database** — repo
  blade + seeder artifacts only. §8 gotcha 2 already warns about exactly this, and hard-coding those
  ids is how they became phantoms in the first place. Writing a seeder against them would produce a
  pack that resolves to nothing on every environment.
- m5's P1-1 import produced the real subject (**template #69, "EATS — WALK TEST (not production)"**,
  on qa1). That is a *walk-test* template by its own name. Seeding the launch Sales Mandate Pack out
  of a document labelled "not production" is a decision, not an inference.
- The composition is a **business** statement (sole vs open vs dual mandate; which disclosure; which
  FICA consent; what is required vs optional), and the slot vocabulary now makes that statement
  precisely — so it must come from Johan, not be guessed at by the lane and then discovered wrong in
  front of a seller.

**What is needed to unblock (small):** a list of the pack's documents in order, each marked
`required` / `selectable(group, label)` / `optional`. The seeder is then ~30 minutes, resolves its
templates **by document_type + name** (never by hard-coded id), registers in
`deploy:sync-reference-data` (AT-162 — seeders do not run on a `git pull`), and is idempotent.

**Not blocking anything else.** Track B (HD-5 → HD-8) is independent of the pack's contents and is
where the night went.

### D-2 (HD-6) — `agencies.esign_checkpoint_mode` is a SETTING, and settings have an obligation

Plan §6 wants `esign_checkpoint_mode` (mandatory | auto_advance). It is **not built**, and that is a
decision rather than an omission.

It is a **setting**, so non-negotiable **#10a** applies: the same prompt that adds it must surface it
in the Agency Onboarding Setup Wizard (`config/agency-onboarding-copy.php`) with its `explain`, its
`affects`, and a saver guarded per `agency-onboarding-setup.md` §6.1 (a wizard step posts a SUBSET of
the saver's fields — an unguarded boolean write silently wipes settings the step never rendered).
That is a proper piece of work, not a column bolted on at 01:00 next to a ceremony change.

**The checkpoint is correct without it:** `mandatory` is the default and the safe posture, and it is
what HD-5/HD-6 implement. `auto_advance` only ever *removes* a control. Nothing is blocked.

**When it is built:** it is one column + one wizard control + one saver + the §6.1 guard, and it
belongs in a settings-shaped prompt, not a ceremony-shaped one. Johan's call whether it is launch
scope at all — an agency that wants no agent checkpoint is asking for less oversight than the
doctrine's default, so it may legitimately be a "deliberately NOT in the wizard" item (spec §5.1).

### D-3 (HD-4, amount-in-words) — needs Johan's word on the LEGAL WORDING. One line unblocks it.

`WebTemplateDataService::numberToWords()` has two defects and one duplicate:
  - **Drops the cents.** Every call site casts `(int)`, so R 1,250,000.50 renders as "one million two
    hundred and fifty thousand" — the 50 cents vanish from the controlling legal figure.
  - **No currency word.** Output is a bare number-in-words, no "rand".
  - **Duplicated byte-for-byte** in `ESignWizardController::numberToWords()` (line 3016) — a latent
    divergence (BUILD_STANDARD §6). Fix once, in a shared converter both call.

**Why this is NOT a 1am lane call.** The amount-in-words is the document's controlling figure, and
STANDARDS' Document-Fidelity law is absolute: *"If a word changes, the document is legally
compromised."* The exact wording is a genuine choice, and the codebase's OWN sample data contradicts
itself — `WebTemplateController` seeds both `'Eight thousand five hundred Rand'` (capital, no cents)
AND `'Eight thousand five hundred'` (no currency word). Guessing between them is exactly the
word-change the law forbids.

**The one decision needed from Johan** (everything else is mechanical, ~20 min once he answers):

> For R 1,250,000.50, which rendering is legally correct on HFC's mandate/OTP?
> - **(A, recommended)** `One million two hundred and fifty thousand rand and fifty cents`
> - (B) `One million two hundred and fifty thousand Rand and fifty cents` (capital "Rand")
> - (C) `... rand only` when cents are zero; `... rand and fifty cents` otherwise
>
> And: whole-rand amounts (cents = 00) → append `only`, or nothing?

**Ready to build the moment he answers:** one `App\Support\AmountInWords` converter (rands + cents,
his wording), both `numberToWords` copies deleted and delegated to it, call sites pass the real
decimal (not `(int)`), a test matrix (whole rand · rand+cents · cents-only · zero · millions).

**Separately parked — the CDS reconciliation (plan HD-4 "two vocabularies, one concept").**
`deal.amount_words` is a field the CDS parser EXTRACTS from the document text (what the page literally
says); `price_in_words` is COMPUTED from the number. When both exist for one document they can
disagree. Which wins — the parsed text or the computed value — is a doctrine call (fidelity vs
correctness), not a lane call. Belongs with D-3 in front of Johan.

### HD-8 (tracker) — ✅ the screen ALREADY EXISTS; closed the one doctrine gap

Like HD-3, the plan claimed a build that was already done. The signing tracker is
`ESignWizardController::myDocuments()` → `docuperfect/esign/my-documents.blade.php`, with TWO sidebar
entries ("My E-Sign Documents" + "Authorise Documents"). It already groups by status
(pending_approval / draft / ready / awaiting / completed / cancelled), shows the per-party holder and
their state (sent / viewed / signing / waiting / completed / FICA), and offers **Send Reminder**
(`docuperfect.signatures.sendReminder`), View Progress, and Cancel. `isAwaitingAgentReview()` — which
the plan said the tracker "reads" — **does not exist**; the status grouping is the real signal.

**The one genuine gap (§6: "who holds it, and FOR HOW LONG")** was that the active holder showed
"sent"/"viewed" with no elapsed time, so a stalled party was invisible at a glance. Closed with a
display-only add: `for {{ heldSince->diffForHumans(null, true) }}` on the active-holder line, timed
from `viewed_at` once opened else `sent_at`. View-only, no new data, no fidelity risk; all views
compile. No new screen (STANDARDS: no duplication), nav + permission already present.

### D-3 — ✅ RESOLVED (Johan, 2026-07-15) → HD-4 (amount-in-words) BUILT.

**House rule, verbatim:** *"we dont use cents ever. figures get rounded to rands. never cents.
numerical or words. no cents… The only time we ever use cents is on rental invoices for utilities.
but thats accounting side not here."*

Built: `App\Support\AmountInWords::rands()` — round-HALF-UP to whole rands, append " Rand", never a
cents clause, never "only". Both legacy `numberToWords()` copies (WebTemplateDataService +
ESignWizardController) were byte-identical; both now delegate to the one converter (BUILD_STANDARD
§6). The `(int)` floor was stripped from all 11 call sites so the raw value reaches the converter and
round-half-up actually applies (a lease escalation of R9,222.50 → R9,223, not R9,222). Rounding rule
is stated in the converter docblock and marked a DOCUMENT-LAYER rule (utility invoices, accounting
side, keep cents — not this). `tests/Unit/AmountInWordsTest.php` — 7 tests / 35 assertions.

**"and" placement — ✅ RESOLVED (Johan, 2026-07-15): "always with the and".** British/SA legal
style throughout ("two thousand and fifty Rand", "one million two hundred and three Rand"). The
converter now inserts "and" before a trailing sub-100 group at every magnitude level; tests pin
Johan's examples. One converter, both call sites inherit it.

**CDS reconciliation (parsed vs computed):** per Johan, compare `deal.amount_words` (parsed) vs the
computed value AT RAND PRECISION — both are whole rands now, so a sub-rand delta is never a real
disagreement. Noted in the converter docblock; no active reconciliation engine exists to wire yet.

### Track C status (2026-07-15, m6) — SPINE COMPLETE; HD-12 (revival) parked as a fresh block

| Leg | Status |
|---|---|
| HD-9 legal deadline (armed at dispatch, mandate = property expiry) | ✅ QA1 `9157f1ee` |
| HD-10 hard block (isLapsed computed · isSigningBlocked · 21 SigningController sites swept) | ✅ QA1 `9157f1ee` |
| HD-11 sweeper records the lapse (lapsed / re_lapsed + audit, idempotent) | ✅ QA1 `fb54a541` |
| HD-12 strike-and-fill extension → revival | ⏳ PARKED — the last leg |

**The system is now strictly safer than before even without HD-12:** a lapsed mandate cannot be
signed (system-enforced, was human-discipline) and the lapse is visible/recorded. The only thing
missing is the *self-service* revival path; the fallback today is exactly the pre-Track-C reality —
re-send a fresh ceremony. So parking HD-12 leaves no regression, only an un-built convenience.

**HD-12 is a fresh multi-file block, not a quick add** — budget it as its own session:
- `DocumentAmendment::TYPE_DEADLINE_EXTENSION` + the flow `lapsed → extension_proposed → revived`
  (states already in the enum from HD-9's migration).
- Rides `SignatureService::requeueAllPartiesForInitialing()` (P0-6 removed the void path, so it's
  safe) — ALL parties initial the new date; all-party initial is FIXED, not agency-configurable (§11-A.2).
- Strike-and-fill on the document surface: old date struck and PRESERVED on the page, new date
  alongside — touches the recipient signing surface (pipeline-gated: SigningController /
  InsertableBlockRenderer), so it carries a `tests/Feature/Docuperfect/SigningView/` diff duty.
- On all-party initial → `revived` + new `legal_deadline_at` (stampLegalDeadline already refuses to
  overwrite a set deadline, so revival owns it cleanly).

---

## ⏸ PARKED — E-SIGN FULLY PAUSED (Johan, 2026-07-15, usage-cap protection)

No further e-sign work until Johan lifts this. Weekly-cap protection: dev stops at 90%, budget
reserved for fixes. Everything below is on `origin/QA1` (QA host only — nothing on Staging/live).

### Landed this phase (QA1)
HD-1 property-link recipients · HD-2 web-pack slot resolver (+ECTA hole closed) · HD-3 picker +
D-1 (web-pack IS the spine) · HD-3b compose-sales-mandate-pack command · HD-4 amount-in-words
(rand-rounded, "always with the and") · HD-5/6 group checkpoints + mandate order · HD-7 per-document
distribution (+POPIA guard) · HD-8 tracker "for how long" · HD-9/10/11 Track C lapse spine
(deadline + hard block + recorded sweeper). AT-265 (fail-closed) + reconcile evidence pack also this
session.

### REMAINING when resumed
- **HD-12** — strike-and-fill extension → revival. The 4 lapse states are already in the enum
  (HD-9 migration); `stampLegalDeadline()` already refuses to overwrite so revival owns the deadline
  cleanly. Rides `requeueAllPartiesForInitialing()` (all-party initial, FIXED not configurable,
  §11-A.2). Touches the pipeline-gated signing surface (visible strike-and-fill) → carries a
  `tests/Feature/Docuperfect/SigningView/` diff duty. Fresh multi-file block.
- **HD-13** — evidence report. A READ over the immutable SignatureAuditLog, including the
  `ceremony_lapsed` rows HD-11 now writes. Reachable from the tracker row + owning deal/listing;
  auto-generates on lapse.
- **D-2** (parked) — `esign_checkpoint_mode` setting; owes the onboarding-wizard obligation (#10a).
  Mandatory default is correct, nothing blocked.

### PACK DECISIONS — Johan's rulings (2026-07-15), apply when e-sign resumes
- **FICA → COMPLIANCE FLOW (chosen).** The pack's FICA is handled via the FICA Compliance module
  (Schedule 4-7), NOT a DocuPerfect template slot. So the Sales Mandate Pack has **no FICA slot** —
  `compose-sales-mandate-pack` already composes without one, correctly. **Future enhancement:** a
  wet-ink-upload-AT-THE-GATE step (upload the applicable FICA at the compliance gate) — spec when
  resumed.
- **Open mandate = a SEPARATE source doc Johan supplies.** Not a clause-variant of the EATS. Until
  he provides it, the Mandate slot carries only the Exclusive variant (command folds Open in on a
  re-run once its template exists).
- **Exclusive candidate = Johan's OWN re-import** through the corrected builder when e-sign resumes
  (not #69, not the static #111 seeder).
- **FFC warranty clause = awaiting attorney wording** (the EATS source carries the explicit
  "Johan to insert verbatim statutory text" placeholder). Hard content gap; production-blocks the
  mandate template until supplied.

### RESUME ENTRY POINT
Branch `esign-phase1` (tracks `origin/QA1`). Pick up at HD-12, or wire the pack once Johan supplies
the Open source + re-imports the Exclusive. Everything green; no half-built state.

---

## AT-177 Wizard Walk package (2026-07-17) — bugs fixed, design specced

**Bugs (fixed, QA1 `5e149af4`):** B1 township town/city fallback · B2 party-bound attribute-from-label
(Johan's keyword map — the fix for his real doc) · B3 "in words" spot → AmountInWords · B4 commission
server re-render (needs live-walk confirm on step-4 persist timing).

### Design specs (build after bugs)

**D1 — Address display picker (property step).** Left panel lists address COMPONENTS (unit, complex,
street nr, street name, erf, suburb, township, region) each with a tick → the ticked set composes the
displayed address. Blank components are editable inline on the left (this is the real fix for B1's
township — the agent fills/toggles it). Composed value flows to `property_address` / the address
data-fields. Reuses the component-tick model shared with D-B2/B3.

**D2 — Document sections step = the builder's Sig/Ini visual tagging, reused.** Do NOT build a
parallel signature mechanism. The signing-surface markers already come from the blade's
signature-line / signature-block includes (SignatureService::countSignatureLocationsPerRole +
createMarkersFromBlocks). The wizard's "document sections" tagging surfaces the SAME builder Sig/Ini
tool so a spot tagged in the builder is the spot that signs — one mechanism, one truth. (This is also
the mechanism for the EATS's 3 generic mid-doc signatures — tag them all-parties.)

**D3 — Fill & review = one last-look surface.** Every field from every pane (property, recipients,
details, custom) rendered together, each editable, as the final pre-send review. Reads the resolved
field set; writes back through the same autosave the render uses.

**D4 — FICA gate: asked ONCE PER CONTACT, never per document (Johan LOCKED 2026-07-17).**
Scenario (his words): a client gets 3 docs to sign; on the 1st they complete FICA; on the rest we
already know FICA is pending, so we do NOT ask again.
Gate states, keyed on the CONTACT (not the document):
  - **no submission** → questionnaire at the first document's gate.
  - **submission pending** → every other document's gate shows a HELD state "FICA awaiting approval"
    — NO re-ask.
  - **approved** → ALL held ceremonies for that contact RELEASE automatically (a gateway event on
    approval fires the gate-release).
This is also the canon-divergence fix: the gate keys on **APPROVED**, with **pending as a HELD state**
rather than an open one. Build the FICA gate exactly to these three states + the on-approval release
event. FICA remains the Compliance-module flow (not a pack template) — the future wet-ink-upload-at-
gate enhancement plugs in here.

---

## E-SIGN PROGRAMME METHODOLOGY — LAW (Johan, 2026-07-17)

**"E-sign starts with the CDS import. Only once we pass CDS imports do we test further. If we change
a template importer anywhere down the line, testing restarts from the top."**

### The stage gate
1. **STAGE 1 — CDS IMPORT (the gate).** Nothing wizard-side is worked or tested until the import
   stage is signed off. Sign-off loop:
   fix → m2 deploys (`scripts/qa-deploy.sh`) → m4 camera-imports the marked EATS replica FRESH and
   verifies bindings render auto-correct (screenshot-2 state) → **Johan's own import + binding
   verify-pass = the stage sign-off.**
2. **STAGE 2 — WIZARD.** Only entered AFTER Stage 1 sign-off.

### THE TOP-DOWN RESET RULE
**Any future change to the template importer resets Stage 2 (wizard) testing from scratch.** A
wizard-stage pass is only valid against a frozen importer. Touch the importer → re-sign-off Stage 1
→ re-test Stage 2 top to bottom.

### CURRENT GATE STATE
- Import fix READY on QA1 (`c213363a`): `DocxParserService::bindAttributesFromContext` (the .docx
  Claude-AI path) + `CdsParserService::attributeFieldFromContext` (deterministic).
- **CDS-PATH CONVERGENCE READY on QA1 (AT-177, 2026-07-18) — this is the path Johan's EATS actually
  uses (`import/cds` → `CdsParserService::parse` → `CdsDraft.cds_json` → cds-builder), and it was NOT
  covered by `c213363a` (which patched the `import/parse`/DocxParser path). See "CDS-PATH IMPORT
  BINDING CONVERGENCE" below. Proven binding-equal to Johan's hand-fixed #70.** Awaiting m2 deploy →
  fresh-import verify → Johan sign-off.

### CDS-PATH IMPORT BINDING CONVERGENCE (AT-177, 2026-07-18) — SPEC

**Target = template #70 "EXCLUSIVE AUTHORITY TO SELL - Johan fixed manually" — his hand-setup IS the
importer specification.** Johan's imported documents mark fill-ins with an explicit
`~~~~{Party} - {Attribute}~~~~` token convention, captured by the CDS parser as
`insertable_block_placeholder`s with a canonical `block_id` (`seller_physical_address`). Because that
convention is DETERMINISTIC, the binding is resolved up front so the builder shows every field bound
out of the box — the vet CONFIRMS, never REPAIRS.

**Mechanism — `App\Services\Docuperfect\CdsBindingSuggester`** (the one convergence point for the
CDS path): walks the cds_json input tokens in document order and returns an ordered binding per
token. `TemplateController::cdsBuilder()` attaches each to its `$fields[i]['binding']`; the
cds-builder JS (`_mappingFromServerBinding`) consumes it FIRST, falling back to the legacy
substring matcher only when a token is not confidently resolvable. Nothing overrides a saved binding.

- **D1 — identity token → FIELD GROUP.** `Seller - Full name and surname` → the party's identity
  field group (`fg:7` "Seller full" = first name + last name + ID) → renders the single shared
  "I / We Name (ID) and Name (ID)" clause via `autoFillFieldGroupDisplay`. NOT the bare party-name field.
- **D2 — attribute token → its OWN column, editable_by populated.** address→`contact.address`,
  telephone→`contact.phone`, email→`contact.email`, property street/township/district/complex/erf →
  the property columns, price→`property.price`, "in words"→`computed.price_in_words`, expiry→
  `property.expiry_date`, "Other conditions"→manual. `editable_by` rules: identity fg =
  `[party, agent, witness]`; contact email = `[party]`; other contact + property-location fields =
  `[party, agent]`; price/words/expiry (pure auto-fill) = `[]`; manual = `[agent, party]`. Duplicate
  columns disambiguate by name-word overlap so a `Rental Complex` never wins a sale doc.
- **D4 — `CdsParserService::detectUnderscoreSignatureLines`.** A literal "underscore-run + `Signature`"
  section pair (GUARDED: pure underscore run ≥6 + next section exactly "signature") becomes a shared
  `[Seller, Agent]` sig_only placeholder; the renderer emits `data-sig-parties` / `data-sig-variant`
  and the builder JS pre-binds the sig tag. The end `signature_section` (Agent, sig_full) is untouched.
- **D5 → R2 (BUILT on-site 2026-07-18)** — commission % is now tokenised from body text. See R2 below.

**ON-SITE RE-TEST DEFECTS (Johan, deployed `ac580cb6`, 2026-07-18): "the rest imported great actually" — 3 residual import-strip defects, all fixed.** Rule adopted: DONE = verified on the deployed qa1 site (re-import, browser-visible render), not tests alone.

- **R1 — source letterhead double-header.** The imported doc's own `company_header` rendered UNDER
  CoreX's auto-injected header. Fix: `CdsRendererService::renderSection` returns `''` for
  `company_header` — CoreX always injects its own via `generateCdsBladeView` (line 953). Class-level
  (all imported docs); body content untouched.
- **R2 — commission % detection (was D5/AT-290).** `CdsParserService::detectCommissionField` tokenises
  the FIRST body percentage sitting within ~40 chars after a commission keyword ("professional fee"/
  "commission") → `insertable_block_placeholder block_id=document_commission_percentage`; the suggester
  binds it to `property.commission_percent`, editable `[owner_party, agent]`. GUARDED: an ordinary
  percentage (VAT 15%) with no commission keyword is never tokenised; only the first occurrence.
- **R3 — source signature block double-sig.** The imported doc's end `signature_section` rendered ABOVE
  CoreX's auto-injected `signature-block` (line 980). Fix: `CdsRendererService::renderSection` returns
  `''` for `signature_section` — the source frame is REPLACED, not appended. Mid-document
  `inline_signature` and the D4 `signature_placeholder` acknowledgement lines are a different type and
  still render. Class-level.

  **R1/R2/R3 proof (deployed qa1 vs #70):** source letterhead + `THUS DONE AND SIGNED` absent from
  render; commission field injected into clause 2.4 ("Professional Fee of \[field\]% per centum"), binds
  `property.commission_percent` `[owner_party, agent]`; input token count now **14 = #70's 14 inputs**
  (commission was the last divergence → now ZERO). Tests: `tests/Unit/CdsImportStripTest.php`
  (R1/R2/R3, DB-free) + commission-binding case in the Feature test.

**Proof:** running the suggester against #70's real `cds_json` (qa1 DB) reproduces #70's input
`field_mappings` target-for-target with matching `editable_by` sets — the ONLY difference is
`property.commission_percent` (D5, no token). D4 reproduces #70's three `[Seller, Agent]` sig_only
placeholders exactly. Files: `CdsBindingSuggester.php` (new), `TemplateController::cdsBuilder`,
`cds-builder.blade.php` (`_mappingFromServerBinding` + server-binding-first init + sig marker
roster), `CdsParserService::detectUnderscoreSignatureLines`, `CdsRendererService` (sig data attrs).
Tests: `tests/Unit/CdsUnderscoreSignatureDetectionTest.php` (D4, DB-free) +
`tests/Feature/Docuperfect/CdsImportBindingConvergenceTest.php` (D1/D2/disambiguation, DB).

### PARKED — WIZARD-SIDE (no code until Stage 1 signs off)
- **B4 commission persist** — root found (refreshPreviewDebounced re-renders from persisted step_data;
  a step-4 commission edit isn't saved before the re-render). Fix = persist step-4 details before the
  debounced re-render. NOT STARTED (parked).
- **Draft-freeze re-resolve** — old drafts serve frozen snapshot values (`fill_review.fieldValues`
  overlay + property snapshot); re-walks should re-resolve. NOT STARTED (parked).
- **HD-12** strike-and-fill extension → revival (Track C last leg). Parked.
- **HD-13** evidence report (read over SignatureAuditLog incl. ceremony_lapsed). Parked.
- **D1** address component-tick picker · **D2** reuse builder Sig/Ini tagging · **D3** fill & review
  last-look. Parked.
- **D4** FICA once-per-contact gate (LOCKED spec) — parked (wizard/gate stage).

### D-slot design note (Johan, 2026-07-17) — component-tickbox selection
For D1-D3, Johan floated a right-panel component-tickbox model (tick the components → composed
display). He is FLEXIBLE: ticks OR field-groups, decide in build. Parked with the wizard queue.
The builder binding chip ("Seller · Address") is BUILT (QA1 17039678) as the display half.
