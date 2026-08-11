# E-Sign — Recipient supporting-document uploads (optional)

Status: BUILT on QA1 (cc2). Branch `cc2-esign-recipient-uploads` → origin/QA1.

## Business requirement (Johan, from staff testing)
On the recipient's signing screen, let a recipient optionally upload supporting documents
during signing. It must **never** block or gate signing, and must stay available **after**
they have signed so they can return via their link and add more.

## Placement
The recipient external-signing screen shown AFTER ID verification + ECTA consent — where they
choose to e-sign or download-and-sign (`SigningController::show` → `external/sign.blade.php`,
the `!signingMethod` choice card). Also surfaced on the post-signing screen
(`external/already-completed.blade.php`) reachable via the same access token.

## Rules
1. **Clearly optional** — "You are NOT required to upload anything to sign" cue; signing is
   never gated on an upload. The upload area is a separate card, never a step.
2. **Available after signing** — the token link still lands a completed recipient on
   `already-completed`, which now carries the same upload card ("signed — you can still add
   supporting documents").
3. **Filed through the existing pipeline, office-visible** — each file is filed as a
   `SignedDocumentVersion` (the same table wet-ink recipient uploads use, tied to
   `document_id` + `signature_request_id`, surfaced on the agent audit-log), tagged
   `kind='supporting'` so it is NOT confused with a signed version / wet-ink review item.
   Agent sees them (labelled "Supporting document", with a download link) on the audit-log
   view. Multiple files; `pdf,jpg,jpeg,png,doc,docx`; ≤15 MB each; ≤10 per submit.

## Design decisions (reasonable calls, stated)
- **Reuse `SignedDocumentVersion`** rather than the deal/property `fileClassifiedDocument`
  pipeline: the latter needs a `Property` + an authenticated `User` actor, neither of which a
  token-only external recipient has. `SignedDocumentVersion` is the existing recipient-upload
  filing channel already visible to the office. New nullable `kind` column distinguishes them;
  `version_number = 0` so a supporting doc never pollutes the signed-version max/sequence.
- **Upload guard**: allowed when the recipient is verified (pre-sign) OR the request is
  COMPLETED (post-sign) — matches the trust level of the pages that already render on the
  token. Never mutates `signing_method` / `status` / any wet-ink field.
- **Agent retrieval**: `GET /documents/{document}/supporting/{version}/download`
  (`signatures.supporting.download`, agent-auth) streams the file.

## Files
- migration `..._add_kind_to_signed_document_versions.php` (additive, nullable)
- `app/Models/Docuperfect/SignedDocumentVersion.php` (additive: `kind` fillable + consts + scope)
- `app/Http/Controllers/Docuperfect/SigningController.php` — `uploadSupportingDocuments()` (GATED file → test added)
- `app/Http/Controllers/Docuperfect/SignatureController.php` — `downloadSupportingFile()`
- `app/Services/Docuperfect/SignatureService.php` + `app/Notifications/SignatureActivityNotification.php` — agent nudge
- `routes/web.php` — external upload route + agent download route
- `resources/views/docuperfect/signatures/external/_supporting-upload.blade.php` (new partial)
- `resources/views/docuperfect/signatures/external/sign.blade.php` + `already-completed.blade.php` (include)
- `resources/views/docuperfect/signatures/audit-log.blade.php` (label + download)
- `tests/Feature/Docuperfect/SigningView/RecipientSupportingUploadTest.php`

## Acceptance
- Recipient can upload before signing; signing still proceeds with or without an upload.
- After signing, the token link lands on `already-completed` and the upload card is still there.
- Uploaded files appear on the agent's audit-log as "Supporting document" with a working download.
- No signing state is ever changed by an upload.
