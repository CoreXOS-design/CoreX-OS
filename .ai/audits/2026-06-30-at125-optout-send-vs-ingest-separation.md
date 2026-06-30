# AT-125 adjustment — contact opt-out is SEND-suppression, INGEST continues (re-consent evidence)

**Date:** 2026-06-30
**Branch:** `AT-125-optout-sending-only` → Staging.
**POPIA correction (Johan):** when a contact opts out we must STOP SENDING to them (outbound email + WhatsApp) but must NOT stop INGESTING messages they send US — an inbound from an opted-out contact is evidence they re-initiated contact (re-consent), and the archive is the proof.

## Finding (Step 0, before any change): the separation ALREADY HOLDS — no production code change needed.

**Opt-out is implemented purely as a SEND gate; the INGEST path consults none of it.**

### Send path — opt-out blocks it (correct, unchanged)
`MarketingConsentService` (`app/Services/SellerOutreach/MarketingConsentService.php`) is the convergence point. `optOutContact()` (`:65`) sets the `messaging_opt_out_*` triplet + `messaging_all_blocked` latch + per-channel `opt_out_*` booleans + identifier-level `marketing_suppressions`. The single send predicate `canMarketTo()` / `marketingBlockReason()` (`:310-349`) returns blocked for an opted-out contact (`isContactSuppressed` → `'suppressed'`). Every send surface calls it:
- `app/Console/Commands/Outreach/SurfaceDueOutreachQueue.php:59`
- `app/Services/Outreach/OutreachQueueService.php:49`
- `app/Http/Controllers/CoreX/OutreachQueueController.php:158`
- `app/Services/SellerOutreach/SellerOutreachComposerService.php:102`
- `app/Models/Contact.php:707`

### Ingest path — opt-out does NOT touch it (correct, unchanged)
Both adapters resolve the counterpart to a contact and archive on match; **neither consults consent/suppression/opt-out**:
- `app/Services/Communications/WaArchiveIngestor.php:68` — `resolver->resolve(...)`; match → archive (`:136-151`), no-match → `dropped` (`:70-82`). No opt-out check.
- `app/Services/Communications/EmailArchiveIngestor.php:68` — same shape; the `CommunicationIngestFilter` (`:75`) is consulted ONLY in the no-contact branch to *label the drop reason*, never to gate a matched contact.
- `app/Services/Communications/ContactIdentifierResolver.php:29-110` — matches purely on `agency_id` + identifier (email/phone child tables + mirror), excludes only soft-deleted contacts. **No opt-out / suppression consultation.** So an opted-out contact's identifier still resolves → the message archives.
- `MarketingConsentService::isIdentifierSuppressed()` (`:275`) — **zero callers** anywhere; it is not wired into ingestion (or anything).

**Conclusion:** build items 1 (send blocked) and 2 (ingest continues) already hold; item 3 (fix any path where opt-out suppresses ingestion) has **nothing to fix** — no such path exists. The "envelope always ingests" floor is satisfied because ingestion is gated only by contact-match (AT-122), never by opt-out. Body/capture-consent (AT-136) is a separate layer and was not touched.

## Proof

**Tinker (rolled-back tx) — 12/12 PASS.** Opted-out contact: `canMarketTo(whatsapp)`=false AND `canMarketTo(email)`=false (send blocked), yet an inbound WhatsApp from that same number → `RESULT_ARCHIVED`, a `Communication` row is written and linked to the contact, and the contact stays opted-out (ingest does not auto-re-consent). Control non-opted-out contact → marketable + archives. Unknown number → `dropped` (AT-122 match-only floor, unchanged).

**Regression test — `tests/Feature/Communications/OptOutSendSuppressionNotIngestTest.php` (3 tests, 14 assertions, PASS)**, through the real WA ingest route/middleware/controller. This is the structural lock: it fails if a future change ever bolts an opt-out check onto the ingest path.

## Follow-up (flagged, NOT built — not trivial)
Item 4 — tag an inbound from an opted-out contact as **re-consent evidence** so it is findable. Non-trivial: needs (a) an ingest-time check "is the matched contact opted-out?", (b) a persisted marker on the `Communication` (a `re_consent_candidate` flag column or a `CommunicationFlag`), and (c) a UI surface/filter to find them. Recommend a separate ticket. The data is already captured today (the inbound archives); this only adds discoverability.

## Constraints honoured
Opt-out = send-suppression only; inbound ingestion continues for opted-out contacts (re-consent evidence); envelope always ingests (match-only floor); body still per AT-136 (untouched); AgencyScope enforced by the resolver's explicit `agency_id`; opt-out itself remains fully audited via the consent spine (`optOutContact`). No code change to the runtime — the deliverable is the regression guard + this finding.
