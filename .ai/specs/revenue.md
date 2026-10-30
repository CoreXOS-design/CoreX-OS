# System owner > Revenue (30 Oct 2026, Johan)

**What:** an owner-only section (sidebar: System Developer > Revenue, `/admin/revenue`, `owner_only` route middleware, no permission key BY DESIGN — commercial terms) showing what signed agencies pay CoreX, the prospects still to come, and a 12-month cash flow. Pillar: Agency (reads `agencies` to link a signed record / converted prospect).

**Data (platform level, no agency scope):** `revenue_billing_records` (signed agency: name, optional `agency_id`, users, branches, optional special-deal `override_monthly` + `override_note`, go-live `start_date`, `term_months`, notes), `revenue_prospects` (name, contact, stage talking / agreement sent / agreement back / signed, users min–max, optional `monthly_override`, chance %, expected go-live month, notes, `billing_record_id` once converted), `revenue_price_list` (one row, JSON: the band table, `quote_from`, `branch_fee`). All soft-deleted (archive / restore).

**Price list (owner-editable on the Price list tab; defaults = corexweb.co.za/pricing):** one flat monthly price per band of ACTIVE agents, unlimited within the band: 1 R450 · 2 R900 · 3–5 R1 495 · 6–10 R2 495 · 11–15 R3 295 · 16–20 R3 995 · 21–25 R4 595 · 26–30 R5 095 · 31+ on quotation (no computed price: set a special deal). Extra branches R750 / branch / month, first included. No setup fee. No VAT line (RR Technologies not VAT-registered; prices become VAT-inclusive later, no increase). A per-agency / per-prospect special deal (Founding Partner etc.) replaces the list price.

**Screens (one search box each, every column sortable, archive/restore, totals row):** Signed agencies (agency, users, price per user, monthly charge, go-live, term; totals: agencies, users, monthly recurring) · Pipeline (monthly shown as a range from users min–max; weighted = midpoint × chance %; "Convert to signed" creates the billing record, links the agency, leaves the pipeline; totals: count, users range, weighted monthly) · Cash flow (12 months: signed / pipeline weighted / total, bars).

**Billing starts on the 1st of the month AFTER go-live** (the take-on month is free): the cash flow counts a signed agency from the month after its go-live date and a prospect from the month after its expected go-live month. A signed record with no go-live date counts from now; a prospect with no expected month, or on quotation with no special price, is left out.

**Tests:** `tests/Feature/Admin/RevenueSectionTest.php` (band prices, totals, weighting, billing start, owner-only, CRUD/search/sort/convert, price-list edit).
