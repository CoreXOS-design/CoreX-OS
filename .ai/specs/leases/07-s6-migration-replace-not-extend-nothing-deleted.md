## 6. Migration — "replace, not extend," nothing deleted

Every one of the 58 `rentals` rows, and every `lease_records`/`rental_properties` row that genuinely
represents a tenancy, migrates into `leases` — none are deleted, matching non-negotiable #1 and
Johan's own framing.

**The real difficulty, named plainly rather than hand-waved:** `rentals.lease_address` and
`lease_records.property_address`/`rental_properties`' address lines are **free text with no FK to
`properties`**. Migrating 58+ rows into a schema where `property_id` is required means resolving free
text to a real property first. CoreX already has a formal mechanism built for exactly this class of
problem — `TrackedPropertyMatchOrCreateService` (non-negotiable #10, the Universal Match-or-Create
Rule) and `Property::scopeSearchAddress()`'s token-matching — and this migration should reuse that
matching discipline rather than inventing new fuzzy-matching logic. Where a confident match can't be
made, the row should NOT be silently guessed into the wrong property — it should be queued for a human
agent to confirm before becoming a real `leases` row. This spec does not assume every legacy row
resolves automatically; some manual confirmation pass should be expected and budgeted for at build
time.

**One exclusion, flagged not silently folded in**: `rental_properties` (schema D) has no tenant and no
lease dates at all — it is a landlord-contact prefill helper, not a tenancy record in substance. I'd
recommend it stay as-is (untouched, still serving DocuPerfect prefill) rather than being forced into
the `leases` migration — but this is a scoping call for whoever builds this, not decided unilaterally
here.

**`properties.rental_amount` is the property's advertised/asking rent — do not conflate it with
`leases.rental_amount`, the actual agreed rent for a specific tenancy.** They will usually be close but
are not the same fact, and giving them the same column name in two tables (as happens informally today)
is exactly the ambiguity this spine is meant to remove. The 6 properties with `lease_start_date`/4
with `lease_end_date` populated are one-off manual entries reflecting some historical tenancy — worth
reviewing as migration candidates individually, not a systematic source.

**Existing consumers that must be repointed when this is built** (named so nothing is silently
forgotten): `app/Http/Controllers/Docuperfect/LeaseController.php`,
`app/Console/Commands/CheckLeaseExpiry.php`, `app/Notifications/LeaseExpirationAlert.php`,
`app/Mail/Signatures/LeaseExpirationMail.php`, `app/Services/CommandCenter/Calendar/Sources/
RentalCalendarSource.php` — all currently read `lease_records`.

---
