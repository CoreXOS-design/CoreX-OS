# Signed Document Distribution — shared service

**Status:** Built, 2026-09-28 (Johan's ruling, §41 of `rental-inspections.md`). First consumer:
rental inspections. Next consumer: Inventory (cc2) — read this file before wiring it up; you should
not need to read `SignedDocumentDistributionService`'s own source to use it.

## What this is

One shared service — `App\Services\Distribution\SignedDocumentDistributionService` — for any module
that needs to, on a completed/signed document: file it to the property's Document store, email it to
every party from the completing agent's own mailbox (with a real Sent-Items copy and the agent CC'd),
and offer a public share link. It knows nothing about rental inspections, leases, or Inventory items —
every module-specific decision lives on your model, via one interface: `App\Contracts\
SignedDocumentDistributable`.

## The contract your model implements

```php
interface SignedDocumentDistributable
{
    public function distributionProperty(): ?Property;
    /** @return array<int, array{contact_id: int|null, name: string, email: string, role: string}> */
    public function distributionRecipients(): array;
    public function distributionAgent(): ?User; // whose mailbox sends by default
    public function distributionSubject(): string;
    public function distributionDocumentLabel(): string; // e.g. "In-inspection report"
    public function distributionSourceType(): string; // fixed string, e.g. 'rental_inspection_report'
    public function distributionSourceId(): int;
    public function hasValidPublicLink(): bool;
    public function generatePublicLink(): string; // ALWAYS overwrites — see note below
    public function publicShareUrl(): ?string;
}
```

`RentalInspection` (`app/Models/RentalInspection.php`) is the reference implementation — read its
own `// ── SignedDocumentDistributable ──` section for a worked example of every method, including
how it derives recipients from `lease.tenants.contact` + `property.sellerOwnerContact()`.

**`generatePublicLink()`/`hasValidPublicLink()`/`publicShareUrl()` are YOUR model's own
responsibility to implement correctly** — the service only ever calls `generatePublicLink()` when
`hasValidPublicLink()` returned false (see `ensurePublicLink()` below). If your model's own
`generatePublicLink()` unconditionally overwrites/invalidates a prior token (as `RentalInspection`'s
does — that IS its revoke mechanism), this ordering is what keeps the service from silently breaking
a link already forwarded to someone. Get this ordering right on your own model too if you add a
manual regenerate/revoke action.

## The service's three public methods

### `fileToProperty(SignedDocumentDistributable $doc, string $pdfBytes, string $filename): ?Document`

Files the PDF to `$doc->distributionProperty()`'s Document store. **Idempotent** — keyed on
`(source_type, source_id)`; a second call for the same document returns the already-filed `Document`
unchanged, never a duplicate. Safe to call from both an automatic hook and a backfill command.

### `ensurePublicLink(SignedDocumentDistributable $doc): ?string`

Returns a currently-valid public share URL, generating one only if none exists / the existing one
expired. Never regenerates an already-valid link (see the note above). Safe to call repeatedly.

### `emailParties(SignedDocumentDistributable $doc, string $pdfPath, string $pdfFilename, string $mode, ?User $sendAs = null, ?User $triggeredBy = null): array`

Emails every recipient `$doc->distributionRecipients()` names, from `$sendAs` (or
`$doc->distributionAgent()` if null) via the agent's own resolved mailbox — falling back to the
shared CoreX mailer when the agent has none configured — with the agent CC'd and a real Sent-Items
copy appended when a mailbox exists. `$mode` is `'auto'` or `'manual'`, logged verbatim, never
inferred. `$pdfPath` must be a real file on disk (the Mailable attaches it by path) — write your PDF
bytes to a temp file first; see `RentalInspectionRecordingController::fileAndMaybeEmailReport()` for
the exact pattern (generate once, pass bytes to `fileToProperty()`, write the SAME bytes to a temp
file for the attachment, `@unlink()` it in a `finally`).

Returns `array<int, array{role, email, status, message_id, error}>` — one entry per recipient,
success or failure, for you to show the agent (or ignore).

## The audit trail

Every `fileToProperty()`/`ensurePublicLink()`/`emailParties()` call writes one row per event to
`signed_document_distribution_logs` (`App\Models\SignedDocumentDistributionLog`) — polymorphic
(`distributable_type`/`distributable_id`), append-only (no `updated_at`). Query it directly for a
document's own history; there is no separate "get history" method on the service.

## Test-mail safety rail

`emailParties()` redirects **every** recipient (and the CC) to `can.assurance@gmail.com` whenever
`app()->environment('production')` is false — this is not optional and needs no flag to enable. It
exists because `PerMailboxMailTransportBuilder` connects directly to the agent's own configured SMTP
server, bypassing Laravel's default `MAIL_MAILER` entirely — QA's existing "outbound is neutralised"
protection (BUILD_STANDARD §8) does **not** cover this path. Do not build a second override for your
own consumer; this one already covers you.

## What the rental-inspections build does NOT ask the service to do

- **Deciding WHEN to auto-send** is the caller's job, not the service's. `RentalInspectionSetting::
  auto_send_report_enabled` (agency setting, default on) is checked in
  `RentalInspectionRecordingController::complete()` before calling `emailParties()` — the service
  itself has no concept of "auto-send enabled." If Inventory wants the same on/off toggle, add your
  own agency setting and check it the same way, at your own call site.
- **PDF generation** is entirely the caller's job — `RentalInspectionReportPdfService::generate()`
  (unrelated to this service) produces the `Barryvdh\DomPDF\PDF` object; the caller calls `->output()`
  for bytes. The distribution service never generates a PDF itself.
  **Johan's ruling, 2026-09-28** (report-fixes round, after verifying this service's own auto-email path
  end to end): the PDF this service emails out must carry the actual signature image, not a text-only
  "Signed" — it is the record a recipient with no CoreX login receives, and it has to stand on its own as
  evidence. The existing "no photos in the PDF" rule (room/item photos still live behind the QR/public
  link only, §15.9/§36 of `rental-inspections.md`) is explicitly **unchanged — it applies to line/room
  photos, never to signature images.** Both PDF services (inspection + inventory) now embed
  `party_signature_path` for a `disposition = signed` row via the shared
  `App\Support\StorageDataUri::fromPublicStoragePath()` helper — base64 `data:` URI, read straight off
  the 'public' disk, so DomPDF never fetches it over HTTP. Wet-ink evidence (`RentalInspectionSignature::
  DISPOSITION_WET_INK`) is deliberately excluded from this — its own docblock: "never presentable as [a
  signature] on screen" — it stays text + a link, exactly as before. Full writeup:
  `rental-inspections.md` §38, `rental-inventory.md` §19.
- **Print** is not a service method — it's `@media print` CSS on whatever public page your module
  already serves (see `resources/views/rental-inspections/public/show.blade.php`'s own `<style>`
  block for the pattern: hide screen-only card chrome, `break-inside:avoid` on each content section,
  a `.no-print` class on the one interactive `window.print()` button).
- **The confirm-modal UI** (listing recipients before a manual resend) is not part of the service —
  it's plain Alpine/Blade in the consuming module's own view. See
  `resources/views/corex/properties/show.blade.php`'s `reportRecipients()`/`sendReportResend()`/
  `publicShareUrl()` methods and the "Resend report" popover markup next to them for the pattern to
  copy — it reads recipients client-side from data already in the page's own payload (no extra
  round-trip needed if your module's own tab payload already eager-loads the same relations).

## One-off filings with no natural distributable object — `FileableDocumentAdapter`

Conductor brief 2026-09-29 — the wet-ink build (rental-inventory.md §21 / rental-inspections.md §39)
needed to file a per-PARTY wet-ink scan to the property's Document store, not the module's whole signed
report. Filing a single evidence file has no natural "distributable" domain object the way a completed
inspection/inventory does — building a full `SignedDocumentDistributable` implementation on
`RentalInspectionSignature`/`RentalInventorySignature` themselves would mean stubbing out
`distributionRecipients()`/`distributionAgent()`/`distributionSubject()`/the public-link methods with no
real meaning at the per-signature level.

`App\Support\Distribution\FileableDocumentAdapter` (new) is a minimal, generic, reusable
`SignedDocumentDistributable` for exactly this case — **use it whenever you need `fileToProperty()` and
nothing else from this service** (no email, no public link):

```php
$adapter = new FileableDocumentAdapter($property, 'your_source_type', $sourceId);
$document = $distributionService->fileToProperty($adapter, $pdfBytes, $filename);
```

`fileToProperty()` only ever calls `distributionProperty()`, `distributionSourceType()`, and
`distributionSourceId()` on the object it's handed — confirmed by reading the method itself, not assumed
— so the adapter's other interface methods (`distributionRecipients()` returns `[]`,
`distributionAgent()` returns `null`, `generatePublicLink()` throws) are safe, inert stand-ins, never
meant to be called through this adapter. **Do not use `FileableDocumentAdapter` for anything that emails
parties or generates a public share link** — build a real distributable object (or extend an existing
one) for that; this adapter exists only for the narrow "file this PDF, keyed on this source_type/id"
case.

`fileToProperty()` itself still has no way to set `document_type_id` — the caller sets it on the returned
`Document` directly, after the call, only if not already set (idempotent — a second call for the same
source_type/id returns the same Document, so this never double-writes). See
`RentalInventoryRecordingController::fileInventoryWetInkScan()` (or its inspections-side sibling
`fileInspectionWetInkScan()`) for the full worked pattern, including wrapping a non-PDF upload
(`fileToProperty()` always writes `mime_type: 'application/pdf'`) into a one-page PDF first via
`resources/views/corex/rental-signatures/wet-ink-scan-pdf.blade.php`.

## Files, so cc2 doesn't have to grep for them

| Piece | File |
|---|---|
| Contract | `app/Contracts/SignedDocumentDistributable.php` |
| Service | `app/Services/Distribution/SignedDocumentDistributionService.php` |
| Mailable | `app/Mail/Distribution/SignedDocumentDistributionMail.php` |
| Email view | `resources/views/emails/distribution/signed-document.blade.php` |
| Audit log model | `app/Models/SignedDocumentDistributionLog.php` |
| Audit log migration | `database/migrations/2026_10_04_100000_create_signed_document_distribution_logs_table.php` |
| One-off filing adapter (2026-09-29) | `app/Support/Distribution/FileableDocumentAdapter.php` — see above |
| Reference consumer (model) | `app/Models/RentalInspection.php` — `// ── SignedDocumentDistributable ──` section |
| Reference consumer (controller) | `app/Http/Controllers/CoreX/RentalInspectionRecordingController.php` — `complete()`, `resendReport()`, `fileAndMaybeEmailReport()` |
| Reference consumer (backfill command) | `app/Console/Commands/BackfillRentalInspectionReportFiling.php` |
| Reference consumer (settings) | `app/Models/RentalInspectionSetting.php` — `auto_send_report_enabled` / `autoSendReportEnabledFor()` |
| Reference consumer (UI) | `resources/views/corex/properties/show.blade.php` — the "Resend report" popover, and `resources/views/corex/properties/partials/rental-inspection-recording.blade.php` for where it's anchored |
| One-off filing consumer (2026-09-29) | `RentalInventoryRecordingController::fileInventoryWetInkScan()` / `RentalInspectionRecordingController::fileInspectionWetInkScan()` — per-party wet-ink scan filing, via `FileableDocumentAdapter` |
