# Universal Signature Audit — CoreX OS
**Date:** 2026-05-25
**Scope:** Map every signature capture surface in CoreX, confirm whether Layer 1 (upload tab) and Layer 2 (stored signature + pre-fill) were built per `.ai/specs/claude_esignature_v2_spec.md`.

---

## Executive summary

| Question | Answer |
|----------|--------|
| Was Layer 1 (upload tab in signing modal) built? | **No.** Modal has Draw + Type only; no Upload tab. Spec §16 lists upload option as parked. |
| Was Layer 2 (stored signature on profile + pre-fill) built? | **No.** No `signature_*` column on `users`; no profile UI; zero pre-fill logic anywhere. |
| Total signature capture surfaces | **8** |
| Surfaces using authenticated user identity | **3** (agent sign, RMCP, amendment acceptance) |
| Surfaces capturing fresh | **All 8** — no reuse, no pre-fill |
| Stored signatures encrypted at rest? | **No.** Plaintext base64 in DB columns. |

---

## 1. Stored signature infrastructure

- `users` table: 82 columns. **Zero signature-related columns.** No `signature_image_path`, `signature_data`, `signature_b64`, `signature_type`, etc.
- `app/Models/User.php`: no fillable / accessor / mutator for signatures.
- No rows to count — column doesn't exist.

## 2. Profile UI

- `resources/views/profile/update-profile-information-form.blade.php`: shows only Name/Email. **No "My Signature" section.**
- No controller method handling profile signature upload/save.

## 3. DocuPerfect signing pipeline

- **Capture component:** `resources/views/docuperfect/signatures/partials/signature-modal.blade.php` lines 34–44 — only two tabs: **Draw** and **Type**. Zero file-input elements.
- **Agent sign flow:** no "use my saved signature" option; canvas always fresh.
- **External sign flow:** `resources/views/docuperfect/signatures/external/sign.blade.php` — same modal, token-authenticated party.
- **Spec §16:** "Upload signature option" is listed as a parked feature, not built.

## 4. Every signature capture surface in CoreX

| # | File:Line | Module | Who signs | How stored |
|---|-----------|--------|-----------|------------|
| 1 | `resources/views/fica/form.blade.php:704` | FICA public | External via token | base64 in `fica_submissions.form_data` JSON |
| 2 | `resources/views/docuperfect/signatures/partials/signature-modal.blade.php:51, 81` | DocuPerfect agent | Authenticated agent | `signatures.signature_data` |
| 3 | `resources/views/docuperfect/signatures/external/sign.blade.php:905, 929` | DocuPerfect external | External via token | `signatures.signature_data` |
| 4 | `resources/views/compliance/rmcp-ack/sign.blade.php:67` | RMCP acknowledgement | Authenticated agent | `rmcp_acknowledgements.form_data` JSON |
| 5 | `resources/views/docuperfect/signatures/external/amendment-review.blade.php:89` | Amendment initial | External via token | `signatures.signature_data` |
| 6 | `resources/views/compliance/fica/compliance-review.blade.php:158` | FICA CO review | Authenticated CO | base64 (route TBD; CO workflow) |
| 7 | `resources/views/docuperfect/signatures/external/sign.blade.php:1256` | Web template interactive zones | External via token | `signatures.signature_data` (via modal) |
| 8 | `resources/views/docuperfect/signatures/partials/signature-modal.blade.php` (shared) | Shared component | All of the above | (consumed by the per-surface POST) |

**Breakdown:**
- Authenticated identity (agent/officer): 3 surfaces (agent sign, RMCP, CO review)
- External/token-based: 4 surfaces (FICA, ext sign, amendment, web template zones)
- All 8 capture fresh from canvas/type input — zero pre-fill or reuse.

## 5. Controller endpoints receiving signature data

| Endpoint | File:Line | What it does |
|----------|-----------|--------------|
| `POST /fica/submit/{token}` | `app/Http/Controllers/FicaPublicController.php:74–185` | Saves `signature_data` into `fica_submissions.form_data` JSON |
| `POST /signatures/sign` | `app/Http/Controllers/Docuperfect/SignatureController.php:1015–1050` | Writes new `signatures` row keyed to signature_marker |
| `POST /signing/{token}/capture-signature` | `app/Http/Controllers/Docuperfect/SigningController.php:909–921` | Same — external signer path |
| `POST /rmcp/ack/submit` | `app/Http/Controllers/Compliance/RmcpAcknowledgementController.php:222–247` | Saves into `rmcp_acknowledgements.form_data` |

All four accept a `data:image/png;base64` payload, base64-decode, and persist. None check for a pre-existing stored signature on the authenticated user.

## 6. BaseSignatureMail + agent-footer

- `app/Mail/Signatures/BaseSignatureMail.php`: assembles the `$agentFooter` payload with name, FFC, phone, email, logo — **no signature image field referenced.**
- `resources/views/emails/signatures/partials/agent-footer.blade.php`: renders text-only signoff. **No stored signature image rendered.**

---

## Signature storage architecture

Current model is **per-document-per-signer**, not per-user:

- `signatures` table columns: `signature_data` (base64), `text_value` (typed alt), bound to `signature_marker_id` + `signature_template_id`.
- Each signing event creates a new row with timestamp + IP audit metadata.
- No user-level signature storage or profile signature management.
- Signatures embedded into `merged_html` → PDF flattening pipeline.

## Encryption at rest

- All `signature_data` stored as plaintext base64 in DB columns.
- To implement encryption at rest:
  1. Add `->encrypted()` modifier on the relevant migrations (or convert to Laravel encrypted cast in model)
  2. Declare `protected $casts = ['signature_data' => 'encrypted']` in `Signature`, `FicaSubmission`, `RmcpAcknowledgement`
  3. Ensure `APP_KEY` backup + rotation policy
- Scope: S (3–4 model/migration changes). Performance impact negligible.

---

## What it would take to wire stored-signature pre-fill everywhere

### Step 1 — Infrastructure
- Migration: add `signature_image_path` (nullable string) + `signature_type` (enum: drawn|typed) to `users`
- User model: fillable + accessor
- New route: `PATCH /profile/signature`

### Step 2 — Profile capture UI
- New section in `resources/views/profile/` (or dedicated `my-signature.blade.php`)
- Reuse `signature-modal` canvas + draw/type modes
- Storage: `storage/app/signatures/{user_id}.png` OR base64 column

### Step 3 — Authenticated signing screens
For agent sign, RMCP, amendment review when signatory is the authenticated user:
- Add third tab "Use My Saved Signature" to `signature-modal.blade.php`
- Check `auth()->check() && auth()->user()->signature_image_path`
- Pre-fill canvas with stored image; allow confirm or redraw

### Step 4 — Controller routing
- `SignatureController::sign()` — if `use_saved_signature=1`, copy stored signature into new `signatures` row (still creates an audit row per event)
- Same logic in `RmcpAcknowledgementController::submit()`
- Fresh capture always available as fallback

### Files to modify (10 total)
1. `database/migrations/YYYY_MM_DD_add_signature_to_users_table.php` — new
2. `app/Models/User.php`
3. `app/Http/Controllers/ProfileController.php` (add `updateSignature()`)
4. `resources/views/profile/` (new signature section)
5. `resources/views/docuperfect/signatures/partials/signature-modal.blade.php`
6. `resources/views/compliance/rmcp-ack/sign.blade.php`
7. `resources/views/docuperfect/signatures/external/amendment-review.blade.php`
8. `app/Http/Controllers/Docuperfect/SignatureController.php`
9. `app/Http/Controllers/Compliance/RmcpAcknowledgementController.php`
10. `routes/web.php`

### Scope + risk
- Size: M (1–2 dev-days)
- Risk: Low — pre-fill is optional convenience; fresh capture always available
- Breaking changes: none (pure addition)
- Compliance impact: none (every signing event still creates a per-event `Signature` row with timestamp + IP)

---

## Notes for Johan

1. **Both layers remain unbuilt.** Spec exists, no implementation.
2. **Encryption at rest is a separate S-fix** — independent of pre-fill work. Worth doing for FICA/POPIA audit posture regardless.
3. **External/token surfaces (FICA public, external sign, amendment) can't pre-fill** — the signatory isn't authenticated. Layer 2 only benefits the 3 authenticated surfaces.
4. **Storage choice for pre-fill: file path vs base64 column.** File path (Storage::disk('private')) is cleaner for size + GC; base64 column is simpler for replication but bloats `users` rows.
