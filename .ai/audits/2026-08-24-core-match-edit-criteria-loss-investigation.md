# Core Match edit "only price persists" — investigation, closed 2026-08-24

**Reported:** Retha + Johan, live — editing an existing Core Match appeared to drop every
criterion except price. **Status: closed, no data lost, not reproduced.** Johan re-checked
Retha's own account directly and it now shows correctly; compared against staging and it
matched. Filed here so a recurrence starts from evidence, not from zero.

**What was proven, not assumed:**
- The 2026-08-23 perf commit (`a5070ed3e`/`5fec5057e`) never touched `edit()`/`update()`/
  `validatePayload()` — confirmed by diffing the actual commit, not reading its message; the
  entire change is inside the unrelated `propertyCountsFor()` oversight-list method.
- The save path cannot null an omitted criterion: `$match->update()` only writes keys present
  in the validated payload — tested directly, including payloads that genuinely omit
  `property_types`/`p24_suburb_ids`/`must_have_features`; every omission left the existing
  DB value untouched, never wiped.
- Match 238 (Johan's own reproduction case, contact 16638) has `updated_at` identical to
  `created_at` (2026-07-16) — it has never been saved since creation, so whatever was seen on
  screen never reached the database.
- Real-browser (Puppeteer) reproduction against Johan's exact record, via both URLs he gave
  (the dedicated `/edit` page and the Buyer Pipeline drawer, real button click, live, read-only),
  rendered every field correctly.
- Tested and ruled out: the criteria-save-triggers-a-recompute hypothesis
  (`ContactMatchObserver::saved()` → `RegenerateBuyerMatchesJob(truncate: true)`) — that job only
  touches `prospecting_buyer_matches`/`property_buyer_matches`; `ClientMatchResolver::resolve()`
  (what the results page actually reads) queries live SQL directly and never touches those
  tables. Empirically confirmed: hid a property, did a real criteria-only save, `hidden_property_ids`
  survived byte-identical.

**Unturned stone, flagged not chased:** `MobileCoreMatchController::update()` was identified but
never examined in a live/real-client sense — its validation rules accept a narrower field set
than the web form (`property_type` singular only, no `property_types` array; missing
`bedrooms_max`, `parking_min`, floor/erf sizes, `nice_to_have_features`, `deal_breakers`,
`is_primary`). Not proven to cause data loss (omitted fields don't get nulled, per above), but
if a real reproduction returns, check whether the reporter was on a mobile client before
anywhere else.
