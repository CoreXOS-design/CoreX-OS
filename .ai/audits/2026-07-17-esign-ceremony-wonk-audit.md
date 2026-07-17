# E-sign signing-ceremony — WONK AUDIT (fix-session agenda)

_2026-07-17 evening. INVESTIGATION only, no code. Johan tested the live ceremony tonight; this is
tomorrow's fix agenda. Historical: the ceremony WORKED pre-rebuild (wonky) — we fix wonk, not greenfield.
Grounded in `.ai/specs/ESIGN-CANON.md` (governing doctrine) + `esign-ceremony-v3.md`. m4 walks the
ceremony in-browser as the seller for pixel evidence in parallel._

Flagship live document = **template 111 "EXCLUSIVE AUTHORITY TO SELL"** (`.../cds/template-111.blade.php`),
`render_type=pdf`-era legacy path (canon §0: the web/CDS engine is built but dormant; live docs take the
legacy path).

---

## PART 1 — RENDER TRUTH: why the seller renders WITHOUT the ID number

### Root cause (single, confirmed)
The per-recipient signing render **re-sources the seller's identity from the linked `Contact` row and
discards the value the wizard baked into `merged_html`.** When the seller's `Contact.id_number` is empty
— which the wizard **fails to backfill** for pre-linked / matched-existing contacts — the ID is dropped.
**Fires whenever a role has ≥2 recipients (a couple)**; a single seller is unaffected.

The exact break:
- **Present** (wizard-captured): `WebTemplateDataService::resolve()` sets `seller_id_number` from the
  wizard step-data (`WebTemplateDataService.php:216`), the blade prints it
  (`template-111.blade.php:17`), and it is persisted into `web_template_data['merged_html']` at wizard
  save (`ESignWizardController.php:1915`).
- **Destroyed / absent** (signing render): the legacy clustering path (data-role-block backfill never ran
  → canon §1a) reaches the **inline-list branch** `RoleBlockExpansionService::applyBoundary():787-795`
  → `inlineListClusterForRecipients():1387` → it **removes the original spans** (`:1443-1447`) and
  rebuilds a composite via `buildRecipientCompositeSpan()`, whose `"(ID: …)"` suffix is emitted **only if
  `Contact::find(contact_id)->id_number` is non-empty** (`:1469`, `:1479-1481`). The previously-correct
  inline text is overwritten.
- **The wizard gap that makes the Contact empty:** pre-linked (`_contact_id`) or matched-existing
  contacts are linked WITHOUT writing the typed `id_number` back to the Contact row
  (`ESignWizardController.php:754-756`, `:776-781`, `:812-815`); only freshly auto-created contacts get it
  (`:817-824`). So the loop re-sources an empty ID.

### It is a field CLASS, not one field
The whole **seller contact-attribute class** is re-sourced from the Contact through the multi-recipient
loop (`resolveContact():1757` → `resolveContactValue():1817-1851`): **name, ID, address, phone, email**.
ID is the most visible casualty because `id_number` is the attribute most often missing on a pre-linked
contact. Property / price / price-in-words / dates are **safe** — they are not role-looped.

| Field | Multi-recipient render | file:line | Same root cause |
|---|---|---|---|
| **Seller ID number** | **DROPS** | present `WebTemplateDataService.php:216`; destroyed `RoleBlockExpansionService.php:1443-1481` | **ROOT** |
| Seller name | binds (Contact-sourced, `signer_name` fallback) | `:1467-1476` | vulnerable, rarely empty |
| Seller phone | **DROPS (two ways)** — (a) merge-key mismatch: blade wants `seller_phone`, map only defines `seller_cell` (`WebTemplateDataService.php:219` vs blade `:28`); (b) loop re-sources `Contact.phone` (`:1841-1844`) | as noted | partly (the key mismatch is a SEPARATE merge-time bug) |
| Seller email | binds | `:218`, `:1839` | no |
| Seller address block | binds if `Contact.address` set (else same class) | `:217`, `:1845-1849` | same class |
| Property addr/erf/price/words/dates | **bind (safe)** — not role-looped | `WebTemplateDataService.php:264-293` | no |
| Other/additional conditions | binds (editable) | `SigningController.php:332-340` | no |

### Two adjacent latent drop mechanisms (not the ID cause, but same family — for the fix list)
- **Seller phone key mismatch** — `seller_phone` (blade) vs `seller_cell` (map): phone blanks even
  single-seller. `WebTemplateDataService.php:219`, blade `:28`. **FIX-NOW (cheap).**
- **Freshness guard re-render drops injected fields** — `MergedHtmlFreshnessGuard::ensureFresh():81-135`
  re-renders the blade from `web_template_data` **without** re-running `injectFieldValues`/
  `resolveSignatureNames` (`ESignWizardController.php:1866, :3395`), so any field that depends on the
  post-render injection pass (empty `data-contact-type` spans) is **blanked** on rerender. Not 111's ID
  (a populated `{{ $seller_id_number }}` view var), but a real loss vector for other templates. **POLISH→FIX.**

### Fix direction (decide at the session — NOT built)
Two candidate fixes for the ID class:
1. **Loop prefers merge-data over an empty Contact value** — `buildRecipientCompositeSpan`/
   `mutateCloneForInstance` fall back to the wizard-baked value when `Contact.{attr}` is empty; or
2. **Wizard backfills the typed identity to the linked Contact at save** (`ESignWizardController.php`
   ~754-824) so the loop's Contact source is populated.
Recommendation: **(1)** (render-time, non-destructive, fixes historical data too) as the primary, with
**(2)** as the durable data fix. Plus the cheap `seller_phone`/`seller_cell` key fix.

---

## PART 2 — FULL CEREMONY WONK LIST (send → completion → evidence)

**[FIX-NOW]** blocks a clean ceremony · **[POLISH]** design · **[BROKEN]** dead-ends/no-artifact.

### Canon divergences — re-verified, still true
- **§3 Other-Conditions MISSING on the AGENT (main) signing view** — `SignatureController::sign():857-946`
  never calls `InsertableBlockRenderer`/`RoleBlockExpansionService`; the `~~~~OTHER_CONDITIONS~~~~` marker
  renders literally/blank. External recipient path is wired (`SigningController.php:332-359`). **[FIX-NOW]**
- **§4 disclosure machinery inert on main view + completion gate is CLIENT-JS ONLY** —
  `disclosure-logic.blade.php:11-13` flags the converter "external-only"; `SigningController::completeWeb()`
  validates only `consented` (`:1343-1346`) before writing `STATUS_COMPLETED` (`:1518-1525`) — no
  server-side re-validate of mandatory disclosure/required fields. (The non-web `complete():1635-1668`
  DOES validate — the web/CDS path, THE path, does not.) **[FIX-NOW]**
- **§6 FICA gate lifts on `submitted`, not `approved`** — `SigningController.php:125-127`
  (`whereIn status ['submitted','under_review','agent_approved','approved']`). Unvetted submission opens
  the gate. **[FIX-NOW / compliance]**

### A. SEND (`ESignWizardController` / `SignatureService`)
- **A1 [BROKEN/FIX-NOW]** Empty-email / skip-email recipient (default `send_after`) parks the template in
  `awaiting_*` with no link and no agent-visible error — `Mail::to('')` throws and is swallowed
  (`SignatureService.php:1544, 2887, 2896-2902`; deferred only on `sign_later` `ESignWizardController.php:2238-2241`).
- **A2 [POLISH]** No WhatsApp dispatch in the e-sign path — every send/reminder is `Mail::` only
  (`SignatureService.php:2878-2989`). Spec names WhatsApp; unwired.
- **A3 [POLISH]** Link TTL hard-coded 14 days (`SignatureService.php:860,891,3326,3426`), not agency-configurable.
- **A4 [POLISH]** FICA auto-create silently no-ops if agency unresolved (`ESignWizardController.php:2204-2208`).
- OK: token uniqueness loops until unique (`:3198-3205`); ordering strictly sequential (no parallel signing).

### B. RECIPIENT LINK (public route + token)
- **B1 [FIX-NOW if cross-device]** Public POSTs (`routes/web.php:3734-3778`) carry full `web` CSRF with
  **no exemption**; gates are session-keyed (`session("signing_verified_{$token}")` `SigningController.php:115,570,943`)
  with `SESSION_LIFETIME=480`. Idle >8h, or an in-app webview (WhatsApp/Gmail) / Safari-ITP cookie strip →
  **419 Page Expired** on submit. The known "public-link expired = CSRF 419" class.
- **B2 [OK]** already-signed / declined / expired / not-your-turn handled; tampered token → 404.

### C. GATEWAY (ID verify + consent + FICA)
- **C1 [POLISH/security]** Not an OTP — it's a knowledge-based ID-number match (`verify():489-527`) with
  **no throttle/lockout** → unlimited brute-force of a semi-public SA ID.
- **C2 [FIX-NOW/evidence]** Gateway + immutable consent are **skipped entirely when no ID on file**
  (`if(!empty(signer_id_number))` `SigningController.php:114`) → no identity verification and **no
  `ESignConsentLog`** for that signer.
- **C3 [POLISH]** FICA gate skipped when `contact_id` null even if `fica_required` (`:124`).
- OK: guest FICA query unscoped by AgencyScope early-return (no hard trap); `fica.form` null-token 500 already defended.

### D. PER-RECIPIENT VIEW
- **D1 [FIX-NOW]** The two-path split (canon §9): external parties get the fully-wired `SigningController::show()`;
  the **agent** gets the degraded `SignatureController::sign()` (Other-Conditions §3 + disclosure §4 inert).
  Drive the morning test through the **external recipient** path, not the agent internal view.
- **D2 [POLISH]** Dormant third path `CompiledServingResolver/CompiledSigningRenderer` (`:259-267`), AT-177 held.

### E. FILL-AT-SIGNING (editable_by isolation)
- **E1 [OK — strength]** Server-side cross-recipient write isolation holds — `saveWebFields` re-checks
  `editable_by`+identity server-side (`SigningController.php:1300-1322`).
- **E2 [POLISH]** `stampViewerEditability` inert on un-expanded/legacy templates (`RoleBlockExpansionService.php:606-608`)
  → editability leans on name-based fallback; inconsistent between paths.
- **E3 [FIX-NOW for real-doc onboarding]** legacy indexed fields hard-capped at 4 recipients
  (`WebTemplateDataService.php:213-238`); seller_3/4 have no `id_number` entries.

### F. SIGNATURE CAPTURE + PDF
- **F1 [BROKEN/FIX-NOW — top risk]** Signed-PDF generation is Puppeteer/Chromium
  (`SignaturePdfService.php:40,110-146`); on failure `completeDocument()` leaves `signed_pdf_path` null
  and **still emails a completion with nothing attached** (`SignatureService.php:1806-1835`). A ceremony
  that completes but yields **no signed artifact**. **Verify Puppeteer runs on qa1 before the test.**
- **F2 [POLISH]** Internal (evidence) PDF silently falls back to the client copy with **no audit
  certificate** on internal-render failure (`SignaturePdfService.php:140-146, 61-64`).
- **F3 [POLISH]** Signatures are raster base64 (`SigningController.php:1414-1419`) — legally fine, image not vector.

### G. COMPLETION (`completeWeb()`)
- **G1 [FIX-NOW]** No server-side mandatory/required gate — validates only `consented` (`:1343-1346`) then
  `COMPLETED` (`:1518-1525`). = canon §4; a crafted/JS-failed POST completes with blank statutory items.
- **G2 [FIX-NOW/compliance]** FICA release timing — because the gate opened on `submitted` (§6), completion
  can finalize a party whose FICA was never vetted.
- **G3 [POLISH]** All-role-complete + status writes un-locked (`:1544-1558`), double-writes
  `PENDING_AGENT_APPROVAL`; mitigated by the sequential `WAITING` gate (low risk), but sloppy.

### H. EVIDENCE / AUDIT TRAIL
- **H1 [OK — strength]** Core chain solid — `SignatureAuditLog` (created/sent/viewed/signed/consent/completed
  + IP/UA/timestamp `:201-210,1527-1542`); `document_hash` at send + re-derived per advance; audit certificate
  appended to the internal PDF (`SignaturePdfService.php:138-140,270-280`).
- **H2 [FIX-NOW]** Consent-evidence gap for no-ID signers (ties to C2) — immutable `ESignConsentLog` only
  written when the ID gateway ran; party copy carries no evidence certificate.
- **H3 [POLISH/POPIA]** Completion over-shares in packs — `sendCompletionEmails` attaches the ENTIRE signed
  set to EVERY signer (`SignatureService.php:3097-3138`) → party A gets party B's signed instruments.
  Single-mandate morning test unaffected; pack flows leak.
- **H4 [OK]** §11-B merged-mega-PDF gap CLOSED (per-doc filing, `is_signed_document` filter, no double-attach).
- **§7 pack-entry residual** appears closed (`!$isPackFlow && !$pdfPackId` + WebPackSlotResolver re-check).

---

## RANKED FIX-NOW SHORTLIST (tomorrow's agenda — the blockers to a clean ceremony)

1. **Seller ID-drop (Part 1)** — couples' mandates render the seller without their ID. Root cause + fix
   direction above. **The headline bug Johan hit.** Cheap sibling: the `seller_phone`/`seller_cell` key mismatch.
2. **F1 — verify Puppeteer signed-PDF generation on qa1.** If it fails, the ceremony completes with **no
   artifact** and emails an empty copy. Single most likely thing to silently ruin the test.
3. **A1 — empty-email recipient dead-ends the flow** (no link, no error). Ensure test recipients have real emails.
4. **D1 / §3 / §4 — the AGENT signing view is degraded** (Other-Conditions + disclosure inert). Drive the
   test through the external recipient path; fixing the split is the real work.
5. **G1 / §4 — no server-side mandatory/required gate in `completeWeb`** (JS-only enforcement).
6. **B1 — CSRF/419 on the public POSTs** if the link is opened in a phone webview or left idle.
7. **C2 / H2 — no-ID recipients skip the gateway + immutable consent evidence** (defensibility gap).
8. **§6 / G2 — FICA gate lifts on `submitted` not `approved`** (compliance).

**Strengths to preserve (do not regress):** server-side write isolation (E1), the audit chain + document
hash + certificate (H1), the closed mega-PDF/pack-entry gaps (H4/§7), FICA no-prefill bright-line (canon §6).
