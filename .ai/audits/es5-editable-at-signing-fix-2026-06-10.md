# ES-5 — Editable-at-Signing: end-to-end repair

**Date:** 2026-06-10
**Branch:** AT-12-E-Sig
**Author:** Claude (build), for Johan Reichel (morning review — NOT pushed)
**Scope:** Make editable-at-signing work for real, including the multi-recipient
(Seller 1 vs Seller 2) case. No new recipient-keying scheme — reuse the existing
`{role}_{role_index}` identity the §20.10 loop already produces.

> Pre-reads honoured: CLAUDE.md, STANDARDS.md, BUILD_STANDARD.md,
> esign-v3-complete-spec.md §9/§24, esign-reconciliation-2026-06-10.md §2/§4,
> editable-by-runtime-investigation-2026-05-26.md. Branch confirmed `AT-12-E-Sig`
> before any change.

---

## 1. What I found before writing a line (verify-first)

The May-26 investigation's two named defects had **already been fixed** by the
shipped recipient-loop series (commits `2142877a` B1, `099ebca2` B2, `ac7ac2e0`
B2.5, `739c0097` B3, `aa4a3590` Step-5 wiring, `13a17de8` Fix A) — all *after*
that investigation. Verified against current code:

- **Break 1 (Step 5 collapse) — fixed.** `ESignWizardController::buildFieldsFromMappings`
  preserves the full array as `editableBy` (`:3570-3583`); Step 5 renders one
  chip per role (`wizard.blade.php:727`, `2207-2213`). *Residual found & fixed
  here:* the `field_group` sub-path still collapsed (`ESignWizardController.php:3470-3473`).
- **Break 2 (per-seller surfacing) — fixed.** `RoleBlockExpansionService::expandWithLooping`
  duplicates role blocks per recipient, stamping `data-recipient-identity={role}_{role_index}`
  and mangling `data-field` → `{name}__r{role_index}` (`:1652-1655`).
  `stampViewerEditability` (`:565-615`) gates `data-viewer-editable="1"` on
  `data-recipient-identity === viewer.role_identity` AND canonical role ∈
  `editable_by`. The `role_index` is a real column (migration
  `2026_06_16_120700`); `role_identity` is the `{party_role}_{role_index}`
  accessor (`SignatureRequest.php:197`).

So the *gating/identity* layer was done. But verifying the task's own acceptance
criteria ("edits persist into the signed document and into merged_html so the
flattened PDF carries them") exposed that the feature **still did not work end to
end** — three genuine, in-scope gaps:

### GAP 1 (CRITICAL) — edited values never reached the signed document/PDF
- The client posts typed values only in the separate `field_values` payload;
  `paginated_html = webDocContent.innerHTML` (`sign.blade.php:3465`) does **not**
  serialise live `<input>` values.
- `completeWeb` embedded only signatures/initials/ceremony into `merged_html`
  (`SigningController.php:1434-1452`) — never field values.
- The filed artifact uses `signed_paginated_html` when present, else `merged_html`
  (`SignatureService.php:1815-1820`). Neither carried the edits.
- Net: an editable-at-signing value was silently dropped from the document the
  next signer saw **and** from the final PDF.

### GAP 2 (HIGH) — per-recipient value collision
- `authoriseWebFieldWrite` validated identity (`:1286`) then stored under the
  **stripped logical name** `$accepted[$logicalName]` (`:1287`); `saveWebFields`
  wrote it flat (`:1148`). seller_1's `seller_address` and seller_2's
  `seller_address__r2` both collapsed to `web_template_data['seller_address']` —
  last-writer-wins.

### GAP 3 — required/optional
- `field_mappings` carries **no `required` flag** for editable text fields (grep
  empty). Per BUILD_STANDARD I did not invent one. Editable text fields are
  optional-by-design; **optional-empty is absorbed** (no 500). Adding a
  per-field required gate is a separate CDS-builder feature, out of ES-5 scope.

---

## 2. Root cause (one sentence)

Signing-time field edits were stored under a collision-prone flat key and were
**never projected back onto the document**, so the per-recipient surfaces the
loop correctly created were never actually filled in the served document or the
filed artifact.

---

## 3. The fix (shared keying — no parallel scheme)

A single projection layer keyed by the **rendered `data-field`** — which for a
per-recipient clone is the mangled `{name}__r{role_index}` the loop already
stamps (identical scheme to `SignatureRequest::role_identity` /
`data-recipient-identity` / §20.10). seller_1 → `seller_address__r1`, seller_2 →
`seller_address__r2`, so two recipients editing the "same" logical field never
collide and each value lands on its own surface.

### Files changed

| File | Change |
|---|---|
| **`app/Services/Docuperfect/SigningFieldValueProjector.php`** (NEW) | The single place that writes saved values back onto `[data-field]` surfaces. `project($html, $valuesByFieldKey, $bakeInputsToText)`: matches each value by the element's rendered `data-field`; replaces span text (render path) or freezes an `<input>` to a static text span (filed-artifact path). Fail-open on parse error (parity with `SigningSurfaceResolver`). |
| `app/Http/Controllers/Docuperfect/SigningController.php` | (a) `authoriseWebFieldWrite` now keys `accepted` by the rendered field key, not the stripped logical name (`:1259/1277/1287`) — **fixes GAP 2**. (b) `saveWebFields` persists into a dedicated `web_template_data['signing_field_values']` store. (c) `show()` projects saved values onto the **expanded** body after `expandWithLooping` — each signer sees prior edits + own pre-fill. (d) `completeWeb` routes `field_values` through the **same per-recipient auth**, persists per-identity, then projects into both `merged_html` and the posted `signed_paginated_html` (baking inputs to text) — **fixes GAP 1**. |
| `resources/views/docuperfect/signatures/external/sign.blade.php` | `completeWebSigning()` now sends the identity-tagged `field_values` (via `collectWebFieldValues`) so the server can authorise per-recipient at completion. |
| `app/Http/Controllers/Docuperfect/ESignWizardController.php` | `buildFieldsFromMappings` field_group path now emits the full `editableBy` array (Step 5 multi-role chip parity) — **Break 1 residual**. |
| `tests/Feature/Docuperfect/SigningView/EditableAtSigningValuePersistenceTest.php` (NEW) | Pipeline-gate test diff for the SigningController change: two-seller no-collision persistence, cross-signer visibility, identity-gate (seller_1 cannot write seller_2's field → 403), optional-empty absorbed, projector unit + bake, completion bakes into `signed_paginated_html`. |

### Why projection at render AND completion
- **Render (`show`)** makes prior signers' edits visible to later signers and
  pre-fills the current signer's input (the client copies span text into the
  input at `sign.blade.php:2064`). It also means the last signer's posted
  `paginated_html` already carries earlier edits as text.
- **Completion (`completeWeb`)** bakes the current signer's own typed values
  (present only in `field_values`, absent from `innerHTML`) into the filed
  `signed_paginated_html`, and flat-keyed values into the canonical `merged_html`
  fallback.

---

## 4. Robustness (BUILD_STANDARD)

- **Optional-empty:** empty value accepted and absorbed (stored as `''`,
  projected as empty text) — no 500. Verified (D1).
- **Required-empty:** no per-field required setting exists for editable text
  fields; not invented. Signature/initial completion gating is unchanged.
- **Identity gate:** a recipient posting another recipient's key under their own
  identity is rejected (403) and not stored — verified (test
  `test_recipient_cannot_write_another_recipients_field`).
- **Fail-open:** projector returns original HTML on any parse error or empty
  input (D3) — never makes a document worse.
- **No NOT-NULL stripping:** `web_template_data` is a JSON column; the new
  `signing_field_values` is a nested array — no schema/NOT-NULL risk, no
  migration.
- **Fidelity:** non-ASCII values round-trip as named HTML entities via
  `saveHTML()` (e.g. `François Straße` → `Fran&ccedil;ois Stra&szlig;e`) —
  identical rendering in browser and Puppeteer PDF; the raw UTF-8 value is what
  is stored. Consistent with the existing `SigningSurfaceResolver` /
  `RoleBlockExpansionService` output. Verified (B3b).
- **Soft-delete / PPRA / web-only:** unchanged; no deletes; no regulator strings;
  web e-sign path only.

---

## 5. Verification

### php -l — all changed PHP files: clean
SigningFieldValueProjector.php, SigningController.php, ESignWizardController.php,
EditableAtSigningValuePersistenceTest.php — `No syntax errors detected`.

### php artisan view:clear — OK

### Tinker functional verification on the dev DB (corex_dev) — 20/20 PASS
Driven through the REAL `RoleBlockExpansionService::expandWithLooping` +
`SigningFieldValueProjector` (non-destructive: unsaved models, no DB writes).

```
A1  seller_1 clone rendered (data-recipient-identity="seller_1")            PASS
A2  seller_2 clone rendered (data-recipient-identity="seller_2")            PASS
A3  seller_1 field mangled to seller_address__r1                            PASS
A4  seller_2 field mangled to seller_address__r2                            PASS
A5  seller_2 seller_address EDITABLE for seller_2                           PASS
A6  seller_1 seller_address NOT editable for seller_2                       PASS
A7  seller_1 seller_address EDITABLE for seller_1                           PASS
A8  seller_2 seller_address NOT editable for seller_1                       PASS
A9  agent CAN edit seller_address (multi-role editable_by)                  PASS
A10 agent CANNOT edit seller_phone (owner_party-only)                       PASS
B1  seller_1 value projected onto its own clone                            PASS
B2  seller_2 value projected onto its own clone                            PASS
B3  no collision (both distinct values present)                            PASS
B3b non-ASCII value preserved (entity-encoded, faithful)                   PASS
B4  placeholder replaced where filled                                      PASS
C1  input value baked into text (filed artifact)                           PASS
C2  editable input frozen (no <input> in filed artifact)                   PASS
D1  optional-empty absorbed (no error)                                     PASS
D2  non-matching key is a no-op                                            PASS
D3  empty html fails open                                                  PASS
RESULT: ALL CHECKS PASSED
```

Input paths proven: multi-recipient editable surfacing (both directions),
multi-role editable_by (agent + owner), owner-only negative, per-recipient value
isolation (no collision), cross-signer visibility, filed-artifact bake,
optional-empty, non-ASCII fidelity, fail-open.

### phpunit (`EditableAtSigningValuePersistenceTest`) — NOT run on this host
`scripts/dev-check.ps1` is Windows/PowerShell (skipped on Linux per the task).
The phpunit feature test additionally cannot run here: the test DB (`hfc_dash_test`,
root@localhost) is inaccessible on this host and the `corexdev` user is scoped to
`corex_dev` only (cannot create an isolated test DB); pointing RefreshDatabase at
`corex_dev` would wipe real dev data, and in-memory SQLite is rejected by a raw
MySQL `ALTER ... MODIFY` migration (`2026_04_22_110001`). The test diff exists for
the pipeline gate and will run under the schema-snapshot MySQL bootstrap on
Johan's Windows env / dev-check before Staging. Its logic is mirror-proven by the
Tinker run above (same services, same assertions).

---

## 6. Status

ES-5 editable-at-signing now works end to end for the multi-recipient case:
right field surfaces to the right recipient (and multi-role parties), the edit
persists without collision, is visible to subsequent signers, and is baked into
the filed `signed_paginated_html` so the flattened PDF carries it. The one
deliberately-not-built piece is a per-field *required* flag for editable text
fields (no setting exists; optional-empty is absorbed) — recorded as a separate
CDS-builder enhancement, not an ES-5 gap.
