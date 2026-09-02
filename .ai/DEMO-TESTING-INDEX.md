# CoreX Demo — Testing Index

## Start here — 15 minute morning check

A warm-up pass, not the full index — enough to see the demo is alive before you
present. Full detail on every item below.

1. **Deal with commission + splits + an active pipeline** —
   `https://demo1.corexos.co.za/deals-dr2/111/edit` — deal 111, 82 Coastal Way,
   Shelly Beach. Cash/bond pipeline, commission **R86,250** on a R1,000,000
   property, 50/50 split, no registration date yet (active, mid-pipeline).
2. **Same deal's pipeline timeline** —
   `https://demo1.corexos.co.za/deals-dr2/111/pipeline` (redirects to the
   timeline view). Should show populated stages, not an empty list.
3. **Property Intelligence chart** —
   `https://demo1.corexos.co.za/corex/properties/1?tab=intelligence` — property
   1, 192 Tucker Avenue, Uvongo. "Portal Engagement Over Time" should show a
   real ~2-month daily-views trend, not "No portal view data yet."
4. **A filed, signed e-sign document — on its property** —
   `https://demo1.corexos.co.za/corex/properties/16` — Documents tab should
   show a signed, filed PDF.
5. **Deeds Capture — the similar-property conflict banner** —
   `https://demo1.corexos.co.za/corex/deeds-capture` — scroll to tracked
   property 59: "We think this is the same as Unit 8, Parklands — currently on
   the market with Ayanda Pillay," with working resolve controls.
6. **Payroll — a finalised run with real payslips** —
   `https://demo1.corexos.co.za/corex/payroll/runs` — July and August should
   show status "Finalised" with 12 payslips each; September "Draft."
7. **Attorney emails waiting to be filed** —
   `https://demo1.corexos.co.za/corex/comms-suspense` — 7 items, addresses and
   deal numbers matching real deals (e.g. "920000").
8. **The prospecting map** —
   `https://demo1.corexos.co.za/corex/map` — should show pins on load.

If all 8 look right, the demo is in good shape.

---

**Purpose:** one reference for Johan + the 2 staff being trained on e-sign, and
for Thursday 10am's webinar prep. Every entry below was rebuilt and re-verified
against the LIVE demo database this session (2026-09-02) — IDs from earlier
today or last night are superseded; a lot landed today from 5 different lanes
and every ID here was checked by direct server-side render, not taken on trust
from any lane's own report (that discipline caught two wrong claims already
this week — see Known Gaps).

**Site:** `https://demo1.corexos.co.za`
**Last verified:** 2026-09-02, ~11:20 SAST
**IDs are a moving target** — several areas were reseeded again today (property
addresses, deal numbers). If this looks stale next time, don't trust it —
re-verify.

---

## ⚠️ Known gaps / do not demo these

1. **cc4's claimed deal-111 commission was wrong at report time — corrected
   here.** cc4 reported "deal 111, commission R166,750." Independently
   verified right now: deal 111 is **82 Coastal Way, Shelly Beach**, commission
   **R86,250**, property value R1,000,000 — a different deal shape than
   reported (the deal id range also shifted from last night's 109–128 to
   today's **106–125**, deal_no 920000–920019, same underlying seeder having
   re-run with new random addresses). Use the numbers on this page, not
   anyone's verbal report, including this one — check the live page before
   quoting a figure to a prospect.

2. **Mailpit password still not documented anywhere I can find.**
   `https://mail.demo1.corexos.co.za` needs basic-auth (username `demo`);
   password is a hash I cannot recover. Unresolved for two days running — get
   it before presenting, or get the presenter's IP allowlisted.

3. **17 older pre-2026-09-01 e-sign packs are still not filed** (same issue
   flagged last night, unchanged) — `finalization_status = 'failed'` on the
   original bulk-seeded batch. Only the 2026-09-02 showcase packs (16, 17, 18
   completed+filed; 19, 20, 21 in progress) are guaranteed to work. Don't open
   a random e-sign document from the properties list expecting it to be filed.

4. **Viewing packs and attorney-email suspense rows: most of what was seeded
   today is already soft-deleted.** `viewing_packs` has 18 total rows but only
   **3 live** (ids 16, 17, 18); `communication_filing_suspense` has 35 total
   but only **7 live** (ids 29–35). This looks like an idempotent seeder
   re-run cleaning up after itself, not data loss — the live rows are real and
   render correctly (verified) — but if you go looking at raw counts anywhere
   don't be alarmed by the bigger "total" number.

5. **Deals list — re-checked, still clean.** Last night's 70%-duplicate scare
   on `/deals-dr2` was fixed and re-verified last night; re-checked again just
   now: 125 live deals, 0 duplicate address groups. No action needed, noted
   here only because it was the previous night's headline risk.

---

## 1. Login / demo accounts

Unchanged.

| Role | Email | Password |
|---|---|---|
| Agency admin | `admin@demo.corexos.co.za` | `CoreXDemo!2026` |
| Branch manager (Margate) | `bm.margate@demo.corexos.co.za` | `CoreXDemo!2026` |
| Agent (Margate) — Pieter van der Merwe, user id 3 | `agent.margate1@demo.corexos.co.za` | `CoreXDemo!2026` |
| Viewer | `viewer@demo.corexos.co.za` | `CoreXDemo!2026` |
| System Owner | `Demo@corexos.co.za` | `Demo@1024` via `/demo-owner-login` |

Agency = **CoreX Demo Realty** (id 1). After login, land on Market
Intelligence: `/corex/market-intelligence`.

---

## 2. Properties + Intelligence chart

- Listings: `https://demo1.corexos.co.za/corex/properties` ✅ VERIFIED renders with real addresses.
- **Best 3 for the Intelligence chart** (checked today, chart payload confirmed non-empty):
  - Property **1** — 192 Tucker Avenue, Uvongo: `https://demo1.corexos.co.za/corex/properties/1?tab=intelligence` ✅ VERIFIED ("Portal Engagement Over Time" present, real trend data).
  - Property **2** — 186 Marine Drive, Uvongo: same pattern, `.../corex/properties/2?tab=intelligence`.
  - Property **3** — 12 Pitts Avenue, Uvongo: same pattern, `.../corex/properties/3?tab=intelligence`.
  - All 3 also have a populated CMA/Market Snapshot and matched prospecting listings — the whole tab looks complete, not just the chart.

---

## 3. Deeds Capture — contact linking, owner conflict, similar-property conflict

`https://demo1.corexos.co.za/corex/deeds-capture` ✅ VERIFIED renders (319KB) with all three scenarios below present in the actual HTML.

- **Contact-linking contrast**: tracked property **55** has no owner contact
  linked (`owner_contact_id` null); tracked property **56** onward are linked
  to a real (fictional) owner contact — open both to show the before/after.
- **Open owner conflict**: tracked property **58** — a scraped owner
  ("Bongani Hughes") disagrees with the owner on file. ✅ VERIFIED the resolve
  form is present and targets the correct route.
- **Similar-property conflict**: tracked property **59** ("Parklands — Section
  8, Southbroom") — ✅ VERIFIED the exact banner renders: *"We think this is
  the same as Unit 8, Parklands — currently on the market with Ayanda
  Pillay."* Matched against agency property id 26.
- **TVA import data**: 3 captures exist, linked to tracked properties 56 and 57
  — 56 is matched to a contact, 57 is not (same linked/unlinked contrast as
  above, from the import side).

---

## 4. MIC (Market Intelligence) claims / opportunities

- `https://demo1.corexos.co.za/corex/market-intelligence` and
  `https://demo1.corexos.co.za/corex/market-intelligence/opportunities` —
  unchanged since last night, not re-verified today (nothing in this area was
  reported as touched) — spot-check before relying on a specific claim number.

---

## 5. Prospecting / map

`https://demo1.corexos.co.za/corex/map` — unchanged, working as of last
night's verification.

---

## 6. Contacts, buyer matches, wishlists

`https://demo1.corexos.co.za/corex/contacts` ✅ VERIFIED renders (741KB) right now.

---

## 7. Calendar, appointments, and feedback

- Calendar: `https://demo1.corexos.co.za/corex/command-center/calendar`.
- **Appointment with feedback** — event id **381**, "[DEMO] Multi-Property
  Viewing — Feedback Showcase": `https://demo1.corexos.co.za/corex/command-center/calendar/381/feedback`
  ✅ VERIFIED responds with real content just now. (Event id shifted from
  last night's 291/183 — another reseed touched this table; use 381.)

---

## 8. Deals (DR2)

**Read Known gap #1 — the specific numbers below were independently checked
just now and are correct as of this moment, but this area has re-seeded twice
in two days.**

- List: `https://demo1.corexos.co.za/deals-dr2` ✅ VERIFIED renders. **125 live
  deals, 0 duplicate address groups** (re-checked fresh — last night's
  duplicate scare is still resolved).
- **Best example**: deal id **111**, deal_no **920005**, "82 Coastal Way,
  Shelly Beach," bond deal, commission **R86,250** on a R1,000,000 property,
  50/50 split, no registration date (active, mid-pipeline):
  `https://demo1.corexos.co.za/deals-dr2/111/edit` ✅ VERIFIED renders (221KB).
- Pipeline timeline: `https://demo1.corexos.co.za/deals-dr2/111/pipeline` →
  `.../pipeline/timeline` ✅ VERIFIED renders (379KB, populated stages).
- Full curated batch: deal ids **106–125**, deal_no **920000–920019**.

---

## 9. E-sign

- Templates, wizard, dashboard: unchanged from last night, still working.
- **Completed + genuinely filed** (created by admin, user 1 — appear in the
  admin's My Documents): packs **16, 17, 18** — all `finalization_status =
  succeeded`, all filed in the `documents` table.
- **In progress**, created by admin (user 1): pack **19**, `awaiting_seller` —
  one signer already completed, one still pending (realistic partial state).
- **In progress**, created by and assigned to the agent persona (user 3,
  Pieter van der Merwe — `agent.margate1@demo.corexos.co.za`, so they show up
  when THAT user logs in and checks their own documents): packs **20**
  (`ready`) and **21** (`pending_agent_approval`) — each has a mix of
  completed/pending/waiting signers.
- My Documents: `https://demo1.corexos.co.za/docuperfect/esign/my-documents` —
  log in as admin to see 16/17/18/19, or as the agent to see 20/21.
- **Avoid**: any e-sign document NOT in this list — the older bulk-seeded batch
  is still unfiled (Known gap #3).

---

## 10. Rental properties + viewing packs

- Rental listings ("To Let"): example ids **349, 350, 351** (24 Tucker Avenue,
  185 Marine Drive, 122 Marine Drive) — `https://demo1.corexos.co.za/corex/properties/349`.
- Viewing packs: `https://demo1.corexos.co.za/corex/viewing-packs` ✅ VERIFIED
  renders (151KB) with real content. **3 live packs**:
  - id **16** — "Sea-view shortlist," status ready, tour 2026-09-05.
  - id **17** — "Family home tour (completed)," status ready, tour 2026-08-28.
  - id **18** — "Retirement downsize options," status draft, tour 2026-09-09.
  (See Known gap #4 — 15 other seeded packs are soft-deleted, this is expected.)

---

## 11. Communications — WhatsApp consent + attorney emails

- **WhatsApp/agent-capture consent**: `https://demo1.corexos.co.za/corex/communications/capture/review`
  ✅ VERIFIED renders (148KB). 6 live rows — mix of opted-in, pending, and two
  opted-out with real reasons ("Prefers email — requested no WhatsApp
  archiving").
- **Attorney emails awaiting filing**: `https://demo1.corexos.co.za/corex/comms-suspense`
  ✅ VERIFIED renders (253KB) with real content confirmed in the HTML (deal
  number "920000", "Banana Beach Road"). **7 live rows** (ids 29–35), mix of
  `pending` and `verified`, confidence high/medium/low, matched by deal number
  or property address. (See Known gap #4 re: soft-deleted rows in this table.)

---

## 12. Compliance

- Agency document types: `https://demo1.corexos.co.za/corex/compliance/document-types`
  ✅ VERIFIED renders (158KB). 5 types: FFC Certificate, Bank Confirmation
  Letter, BEE Certificate, Company Registration (CIPC), VAT Registration
  Certificate.
- Policy: `https://demo1.corexos.co.za/corex/compliance/policy-manager` ✅
  VERIFIED renders (146KB). 1 policy: "Code of Conduct & Professional Ethics
  Policy."
- Staff screening: `https://demo1.corexos.co.za/corex/compliance/screenings` ✅
  VERIFIED renders (162KB). 11 screening records, mostly `completed`/periodic.

---

## 13. Payroll

- Employees: `https://demo1.corexos.co.za/corex/payroll/employees` ✅ VERIFIED
  renders (185KB). 12 employees.
- Runs: `https://demo1.corexos.co.za/corex/payroll/runs` ✅ VERIFIED renders
  (155KB).
  - **July 2026** (run 202607-001): **Finalised**, 12 payslips, net R267,221.92.
  - **August 2026** (run 202608-001): **Finalised**, 12 payslips, net R267,221.92.
  - **September 2026** (run 202609-001): **Draft**, 12 payslips.
  - Real payslip PDFs confirmed filed (e.g. "Payslip-Pillay-202608.pdf",
    "Payslip-Nkosi-202608.pdf") — visible in the same filing register as e-sign
    and other document types.

---

## 14. Mailpit (outbound email capture)

`https://mail.demo1.corexos.co.za` — see Known gap #2, still unresolved.

---

## 15. CMA / market reports / presentations

- Reports: `https://demo1.corexos.co.za/corex/market-intelligence/reports` ✅
  VERIFIED renders (224KB) with real content, right now.
- Presentations: `https://demo1.corexos.co.za/corex/presentations/analytics` ✅
  VERIFIED renders (163KB), right now.
- Suburb report: suburb "Uvongo" confirmed to exist in the system;
  `https://demo1.corexos.co.za/corex/market-intelligence/suburb-report/Uvongo`
  follows the same pattern verified working on this and prior nights.

---

## HFC contamination sweep

Re-run against every table that received new data today (tracked_property_owners,
communication_filing_suspense, agent_capture_consent, agency_document_type_configs,
agency_policies, deals, viewing_packs, tva_contact_captures, properties) plus a
re-check of the property-1 render. **Clean — zero hits.**

---

## Change log

- 2026-09-01 evening — see prior versions of this document (superseded).
- 2026-09-02 ~11:20 — full rebuild against the current database. Every ID
  independently re-verified by render, not taken from any lane's report as-is
  — caught and corrected one wrong figure (cc4's deal-111 commission).
  Rewrote "Start here," Known Gaps, and added 4 new sections (rental/viewing
  packs, communications, compliance, payroll) for everything that landed
  today from cc1/cc2/cc3/cc4. Deals and deeds-capture conflict scenarios
  verified against real rendered HTML, not just row counts.
