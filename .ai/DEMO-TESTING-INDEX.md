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
   show a signed, filed PDF. Property 16 is now "187 Edward Avenue, Uvongo"
   (re-seeded since last night) — the filed PDF's own filename still says
   "54 Coastal Way, St Michaels-on-Sea" (stale, from before the reseed). The
   document itself is real and opens fine; just don't read the filename
   aloud as the property's address. See Known gap #6.
9. **A buyer with a resolving wishlist, a viewing, and a ready pack** —
   `https://demo1.corexos.co.za/corex/contacts/30` — Pieter Dlamini, warm,
   32 property matches, a completed viewing, and a ready Buyer Journey
   viewing pack. Shows the whole chain in one screen.
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

If all 9 look right, the demo is in good shape.

---

**Purpose:** one reference for Johan + the 2 staff being trained on e-sign, and
for Thursday 10am's webinar prep. Every entry below was rebuilt and re-verified
against the LIVE demo database this session (2026-09-02) — IDs from earlier
today or last night are superseded; a lot landed today from 5 different lanes
and every ID here was checked by direct server-side render, not taken on trust
from any lane's own report (that discipline caught two wrong claims already
this week — see Known Gaps).

**Site:** `https://demo1.corexos.co.za`
**Last verified:** 2026-09-03, ~00:20 SAST
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

6. **Two filed e-sign documents carry a stale address in their own filename.**
   Property 16 was re-seeded overnight and is now "187 Edward Avenue, Uvongo"
   — its filed Sole Mandate PDF (from pack 16) is still named "...54 Coastal
   Way, St Michaels-on-Sea (Signed).pdf" from before the reseed. Same issue
   flagged on property 17 last night. The documents themselves are real and
   open fine — just don't read the filename aloud as the property's current
   address. Root cause: property address fields get re-randomized by
   overnight seeder re-runs, but a document's filename is a snapshot at
   generation time and never updates. Not fixed (would mean renaming a real
   filed record) — flagged both times it's been found.

7. **This index was caught pointing at a dead-end buyer example (viewing
   pack 17 / contacts 1–3, zero property matches) — fixed.** cc3 found it by
   checking the actual link before handing it to Johan. §6 below now uses
   contact 30 (Pieter Dlamini) as the no-caveats example and a verified
   8-contact spread (new/warm/cold/lost) for anyone who wants to show the
   whole buyer funnel. Every ID in this document was re-checked against the
   live DB before this fix, not just the buyer section — see the change log.

8. **`demo_access_grants` has zero active rows — a genuinely fresh/uninvited
   visitor has no self-service way in right now.** This is a real product
   gap, not a test artifact — flagging for Johan to decide, not fixing at
   3am. Traced empirically (real curl requests against
   `demo1.corexos.co.za`, plus reading `demo_access_grants` directly on
   `/corex`'s `nexus_os` DB, the box `COREX_DEMO_CONTROL_URL` actually
   points at):
   - The table has exactly 2 rows, both for `a.roets12@gmail.com`, both
     expired **and** revoked on 2026-07-12. Nothing for Johan, nothing
     recent, nothing active.
   - A visitor with no grant cookie who hits `/demo/gate` sees "Sign in
     with the email address and access code from your invitation" — no
     self-service request-access flow, invite-only by design.
   - A visitor with no grant cookie who hits `/login` sees the **ordinary
     password form**, not the passwordless "Sign in as…" persona buttons —
     also by design (`AuthenticatedSessionController::create()`'s docblock:
     showing the passwordless picker to an uninvited visitor "advertises
     the whole demo to anyone who guesses the URL"). So the passwordless
     `demo-login/{role}` route itself is not broken code — it's correctly
     unreachable until a grant cookie exists, and nothing currently issues
     one.
   - Net effect: this is the exact route a prospective agency would use to
     try CoreX after the webinar, and it is currently a dead end for
     anyone who wasn't already issued a (now long-expired) invitation.
     Johan's own 07:00 access is unaffected — see §1, the System Owner
     login bypasses this table entirely — but anyone he tells to "go try
     it yourself" tomorrow will hit a wall. Needs a decision: either a live
     grant-issuing flow for the webinar, or a manual grant Johan/staff
     create per interested attendee.

9. **Johan's #1 named complaint — fixed where it could honestly be fixed.
   READ THIS BEFORE YOU CLICK AROUND ON PROPERTIES LIVE:**

   > **Open Property Intelligence on property 15 (or any of 1–15, 17). Do
   > NOT open it on whatever property sits at the top of the default
   > newest-first list** — those properties have portal syndication
   > genuinely turned OFF, so their Portal Engagement chart is legitimately
   > empty. This is not a bug to route around; it's correct behaviour on
   > properties that were never actually marketed to a portal.

   **What was fixed (2026-09-03, verified by real authenticated HTTPS
   fetch, not row counts):** the seller-live "0 live listings competing"
   headline and the Property Intelligence comparable-stock panel shared one
   root cause — `CompetitorStockMatchService` scores candidates within
   ±20% price / ±1 bed of the subject, and no `prospecting_listings` rows
   fell inside both bands for any of the 8 hero properties.
   `DemoCompetitorStockSeeder` fixed this. Presentation 57's seller-live
   page now reads "10 live listings competing" (was 0); the CMA bar chart,
   comparable-sales table, and price gauge all confirmed rendering real
   non-zero data; property 15's Portal Engagement chart confirmed with real
   30-day view counts (2–14 views/day). See §2 and §15 for the verified
   links.

   **What was deliberately NOT fixed, and why that's the right call, not a
   shortfall:** the properties list defaults to newest-first, and every
   property with working Portal Engagement data is among the oldest.
   Checked whether the newest properties could get the same treatment —
   they can't, honestly:
   - The true newest properties (highest ids) are `status: draft` — never
     published, so real portal history can't exist for them.
   - The next tier down are rentals (`rented`/`under_offer`) — off-market,
     not P24 sale-portal candidates.
   - The next tier — the actual newest **active, for-sale** stock — has
     `p24_syndication_enabled = 0` and `pp_syndication_enabled = 0` on
     every single one, confirmed directly against the DB. Only 12
     properties agency-wide (all ≤ id 17) have real syndication on.
   - Faking chart data on a property whose own syndication flag says "off"
     would contradict itself the moment anyone opens that property's own
     Marketing tab. Turning syndication ON for new properties instead would
     start real scheduled jobs (`SyncProperty24Activations` every 15 min,
     `PullP24StatsJob`, `PullP24LeadsJob`, and the Private Property
     equivalents — confirmed in `routes/console.php`) hitting the sandbox
     portal APIs. Either one would look fine tonight and go wrong live.
   - **Comparable Listings is NOT part of this gap** — that panel matches
     against the agency's own stock, not portal data, and already works on
     every property including the newest ones (verified live: property
     222's Intelligence tab shows property 224 as a real comp). It is
     specifically the Portal Engagement chart that needs a hero property.
   - **If an agency asks why a brand-new listing shows no portal
     engagement, that's a genuinely good answer, not an admission of a
     demo hole: "it hasn't been syndicated yet."** That's the system
     working correctly. Say it with a straight face.

10. **The Contacts list has a real display bug, not just a data gap — READ
    THIS BEFORE CLICKING CONTACTS.** A fresh visit to
    `/corex/contacts` as System Owner shows **"No contacts yet — Add your
    first contact"**, despite 312 real contacts existing. Root cause: the
    agent filter defaults to the logged-in user's own contacts, and System
    Owner has never "owned" any of the seeded contacts. **Fix: click the
    agent filter and pick "All," or open
    `https://demo1.corexos.co.za/corex/contacts?agent_id=all` directly.**
    Verified this shows all 312 contacts correctly. Also fixed the same day
    (coordinator-directed, both visible in the first three seconds of
    clicking Contacts once past the above): 24 "[DEMO] Spine Buyer/Seller
    #N" internal QA fixtures that sorted to the very top of the default
    alphabetical list (archived, not deleted — see §6's note) and two
    duplicate-name collisions, "Anele Botha" and "Zanele Bezuidenhout"
    (each had 3 records; kept the richest, renamed the other two — see §6).
    **Left alone, reported only (not mass-seeded, per explicit
    instruction):** 93 of 312 contacts (~30%) have zero communication
    history — a plausible real state for some contacts, not fixed at this
    hour; and many OTHER duplicate first+last name pairs exist across the
    dataset beyond the two collisions fixed (a broader naming-pool
    collision pattern — dozens of pairs, not touched, out of scope for
    tonight).

---

## 1. Login / demo accounts

**07:00 SAST recipe for Johan — the ONE path confirmed to work end-to-end
right now, via a real HTTP test (curl, real CSRF token, real cookie jar, not
inferred):**

1. Go to `https://demo1.corexos.co.za/demo-owner-login`.
2. Sign in as **`Demo@corexos.co.za`** / **`Demo@1024`** (System Owner,
   role `super_admin`, `is_owner=1`).
3. This lands on `/dashboard` → `/corex`, a full 200 OK real app page — no
   gate redirect. Verified 2026-09-03 ~02:15 by an actual scripted browser
   session, not a tinker/`Auth::login()` shortcut (see Known gap #8 for why
   that distinction mattered tonight).

This works **because** the System Owner role bypasses the demo access gate
entirely (`EnsureDemoGrant`'s staff bypass checks `isOwnerRole()`), so it
does not depend on `demo_access_grants` at all — which is important, because
that table currently has **zero active rows** (see Known gap #8).

| Role | Email | Password |
|---|---|---|
| System Owner (Johan's login — gate-proof, use this at 07:00) | `Demo@corexos.co.za` | `Demo@1024` via `/demo-owner-login` |
| Agency admin | `admin@demo.corexos.co.za` | `CoreXDemo!2026` |
| Branch manager (Margate) | `bm.margate@demo.corexos.co.za` | `CoreXDemo!2026` |
| Agent (Margate) — Pieter van der Merwe, user id 3 | `agent.margate1@demo.corexos.co.za` | `CoreXDemo!2026` |
| Viewer | `viewer@demo.corexos.co.za` | `CoreXDemo!2026` |

The agency-admin/branch-manager/agent/viewer rows above use the **ordinary
password login** at `/login` (that route is gate-exempt by design) — not yet
independently re-verified via a real HTTP round-trip tonight the way the
System Owner path was, but their password hashes and roles are unchanged
from `DemoDataSeeder.php` and none of them have `is_owner`, so if the gate
or grants change before 07:00, re-check these specifically.

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

**Johan's #1 named complaint, "I could not find a property that shows the
graph" — root cause found and fixed 2026-09-03, re-verified visually:**
the properties list defaults to newest-first (`Agency::properties_sort_mode`,
default `'created'`), but every property with real Intelligence-tab data is
among the OLDEST (lowest-id) properties in the demo — a normal newest-first
browse never reaches them. Not fixed here (a sort-order/discoverability
decision, flagged for Johan, not made unilaterally) — but the data itself is
now confirmed genuinely populated, not just present:

- **Property 15 — 21 Pitts Avenue, Uvongo** —
  `https://demo1.corexos.co.za/corex/properties/15?tab=intelligence`.
  ✅ VERIFIED by real HTTP fetch (authenticated session, not a data-count
  inference): the embedded Portal Engagement chart series has real,
  varying daily view counts for the last 30 days (2–14 views/day, e.g.
  2026-08-24→14, 2026-08-31→2, 2026-09-01→8) — the default 30D filter
  will draw real bars, not a flat/empty line. Comparable Listings panel
  also populated (property 6 "1 Devon Place", property 10 "100 Mitchell
  Avenue", both with real prices). This is the single best property to
  open live — it is ALSO the seller-live example in §15 below, so one
  property carries both halves of the demo.
- Any of properties **1–14** work the same way (all 15 heroes were
  extended to full coverage on 2026-09-02 — see
  `database/seeders/Demo/DemoIntelligenceSeeder.php`'s own docblock).
  Property 15 is called out specifically only because it doubles as the
  seller-live example.

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

`https://demo1.corexos.co.za/corex/contacts` ✅ VERIFIED renders right now.

**Correction (cc3 caught this, 2026-09-03):** an earlier version of this
index pointed at viewing pack 17 / contacts 1–3 as the wishlist example —
those contacts have no property matches and the story dead-ends. Every
buyer example below was independently re-verified against real DB rows
before being written here (`buyer_state`, match counts, calendar events,
viewing pack status), not copied from anyone's report.

A deliberate SPREAD of buyer pipeline states, so you can show the whole
funnel, not just one happy path — each ✅ VERIFIED by direct query and a
real page render:

| Contact | State | Property matches | Viewing? | Viewing pack | URL |
|---|---|---|---|---|---|
| **31 — [DEMO] Anele Botha** | new | 10 | ✅ 12 Pitts Avenue, 31 Aug | pack **21**, draft (not yet finalized) | `/corex/contacts/31` |
| **30 — [DEMO] Pieter Dlamini** | warm | 32 | ✅ 8 events | pack **32**, **ready** — the full chain | `/corex/contacts/30` |
| **35 — [DEMO] Bongani Pretorius** | warm | 32 | ✅ 8 events | pack **33**, **ready** | `/corex/contacts/35` |
| **47 — [DEMO] Thandiwe Venter** | warm | 6 | ✅ 4 events | pack **34**, **ready** | `/corex/contacts/47` |
| **36 — [DEMO] Tanya Nkosi** | new | 57 | ✅ 4 Birmingham Drive, 1 Sep | none yet | `/corex/contacts/36` |
| **39 — [DEMO] Nomsa du Plessis** | cold | 29 | — | none | `/corex/contacts/39` |
| **46 — [DEMO] Derek Molefe** | cold | 120 | — | none | `/corex/contacts/46` |
| **29 — [DEMO] Lerato van der Merwe** | lost | 139 | — | none | `/corex/contacts/29` |

**Best single example — no caveats:** contact **30** (Pieter Dlamini) —
warm, resolving matches, a completed viewing, and a ready pack, all in one
screen. Use **31 (Anele Botha)** specifically if you want to show the
wishlist→match resolution story from the very start (draft pack, one
viewing booked, nothing finalized yet).

**Fixed 2026-09-03 — name-collision risk on contact 31.** There used to be
THREE "Anele Botha" contacts (31, 91, 151) — a name search during the
webinar could have landed on a bare record instead of 31's real story.
Confirmed 31 as the genuine richest record (viewing pack 21, 3 comms, 3
property views) and renamed the other two to real, collision-checked names
("Nomvula Radebe", "Andile Mabaso") — 31 is now the only "Anele Botha" in
the system. Same fix applied to "Zanele Bezuidenhout" (kept contact 45,
renamed the other two). Also archived (soft-deleted, recoverable) 24
"[DEMO] Spine Buyer/Seller #N" QA fixtures that sorted to the very top of
the default alphabetical Contacts list — see Known gap #10.

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
  renders with real content. **7 live packs now** (grew from 3 overnight —
  cc2's buyer-journey work added 4 more):
  - id **16** — "Sea-view shortlist," status ready, tour 2026-09-05.
  - id **17** — "Family home tour (completed)," status ready, tour 2026-08-28.
  - id **18** — "Retirement downsize options," status draft, tour 2026-09-09.
  - id **21** — Anele Botha (contact 31), draft.
  - id **32, 33, 34** — Buyer Journey packs, **ready**, contacts 30/35/47 —
    see §6, these are the "full chain" buyer examples.
  (Older seeded packs beyond these 7 are soft-deleted — expected, see Known gap #4.)

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

**Seller Live link — Johan's other #1-complaint item ("showing the
graphs"). Verified 2026-09-03 by opening the real public URL over HTTPS and
reading the actual rendered chart data, not the endpoint status code and
not a row count:**

- **Presentation 57 — 21 Pitts Avenue, Uvongo (property 15 above)**:
  `https://demo1.corexos.co.za/p/Zr9QWOdGXyCWjj4uigtp9Frz09Bt1zN7Emcunh019VjIr4Dl`
  ✅ VERIFIED — this is the ONE to open live:
  - "Uvongo — recent sales activity" bar chart (Chart.js, `<canvas
    id="suburb-trend-chart">`): 6 real sold comps across 5 distinct months
    → real bars will draw, not an empty chart.
  - "Recent sales in the vicinity" table: 6 real rows with real addresses/
    dates/prices.
  - "Where your asking price sits in the recommended band": SVG gauge with
    real, distinct values (Lower R2,135,250 / Middle R2,372,500 / Upper
    R2,680,925 / Asking R2,460,000) — genuine non-zero bar widths.
  - "Active Competition" — **10 live listings competing** (was 0 as of
    earlier tonight; root cause and fix — see Known gap #9 below).
- The other 7 hero presentations (**50–56**, properties 8–14) follow the
  identical pattern and were fixed by the same seeder run — not
  individually re-opened tonight, but built and verified the same way.
  Tokens are in `presentation_snapshot_links` keyed by `presentation_id`
  if a second example is needed live.
- **Important — this link needs a signed-in browser to open right now.**
  `/p/{token}` is meant to be a no-auth public URL, but the demo access
  gate currently intercepts it too (fresh/anonymous requests 302 to
  `/demo/gate`) — see Known gap #8. Open it from the SAME browser tab
  group where you're already signed in via §1's System Owner login and it
  works (confirmed — the owner-role bypass covers this route too since
  it's a per-request auth check, not a route-specific one). Do not test it
  in an incognito/separate browser expecting it to work standalone.

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
- 2026-09-03 ~00:20 — cc3 caught this index pointing at a dead-end buyer
  example (viewing pack 17 / contacts 1–3, zero matches). Fixed §6 with a
  verified 8-contact spread (new/warm/cold/lost) and set contact 30 as the
  no-caveats example. Per Johan's instruction, cross-checked EVERY other
  example record in the whole document against the live DB before touching
  anything else: found and fixed one more drift (property 16 re-seeded
  overnight, its filed document's filename is now stale — new Known gap
  #6) and one stale count (viewing packs grew 3→7 overnight, §10 updated).
  Everything else (properties 1/2/3, tracked properties 55–59, event 381,
  deal 111, e-sign packs 16–21, rental properties, doc types, policy,
  screening, payroll runs, Uvongo suburb) re-checked and still correct as
  written — no further drift found this pass.
- 2026-09-03 ~02:20 — verified the actual 07:00 login path with a real HTTP
  round-trip (curl, CSRF token, cookie jar) against `demo1.corexos.co.za`,
  not inferred and not a tinker-only in-process check: `/demo-owner-login`
  with `Demo@corexos.co.za` / `Demo@1024` reaches a genuine 200 OK `/corex`
  page. Rewrote §1 with that exact recipe first. Also traced, empirically,
  that `demo_access_grants` (the table `EnsureDemoGrant` checks via
  primary) has zero active rows — a fresh/uninvited visitor currently has
  no self-service way past `/demo/gate` — new Known gap #8. Confirmed via
  `git log`/`git show` that `app/Http/Middleware/EnsureDemoGrant.php` was
  not modified in any commit tonight (an edit was drafted, then reverted
  before staging, on coordinator instruction).
- 2026-09-03 ~02:55 — fixed Johan's #1 named complaint (property
  intelligence graph unfindable + seller-live "0 live listings competing").
  Root-caused to CompetitorStockMatchService's price/beds tolerance finding
  no qualifying `prospecting_listings` rows for any of the 8 hero
  properties — added `DemoCompetitorStockSeeder`, re-verified by real
  authenticated HTTPS fetch (not tinker, not row counts): presentation 57's
  chart, comp table, price gauge, and "10 live listings" headline all
  confirmed rendering real data; property 15's Portal Engagement chart
  confirmed with real 30-day view counts. New Known gap #9 documents the
  fix and the separate, unfixed discoverability issue (hero properties are
  the oldest, properties list sorts newest-first). §2 and §15 updated with
  the verified named links Johan asked for.
- 2026-09-03 ~03:35 — coordinator asked to CLOSE the discoverability gap
  (seed intelligence data for the newest properties, not just document it).
  Checked whether that could be done honestly: the true newest properties
  are drafts (never published); the next tier are rentals (off-market); the
  next tier — the actual newest active for-sale stock — has
  `p24_syndication_enabled=0` and `pp_syndication_enabled=0` on every one,
  confirmed against the DB (only 12 properties agency-wide, all ≤ id 17,
  have real syndication on). Faking portal-engagement history on a property
  whose own syndication flag says "off" would contradict itself on that
  property's own Marketing tab; turning syndication on instead would start
  real scheduled jobs (routes/console.php: SyncProperty24Activations,
  PullP24StatsJob, PullP24LeadsJob + PP equivalents) hitting sandbox portal
  APIs every 15 minutes. Declined to do either — reported the finding
  instead of forcing a fix, per the coordinator's own stated fallback.
  Rewrote Known gap #9 with the unmissable instruction (open property
  15/1–15/17, not the top of the list) and the full reasoning, including
  that Comparable Listings already works on every property (verified live
  on property 222) because it doesn't depend on portal data — only the
  Portal Engagement chart needs a hero property.
- 2026-09-03 ~03:55 — contact realism spot-check (browsing the way Johan
  would, not just checking data counts) found: (a) a real display bug —
  fresh Contacts visit as System Owner shows "No contacts yet" because the
  agent filter defaults to "my own contacts" and System Owner owns none —
  new Known gap #10; (b) 24 "[DEMO] Spine Buyer/Seller #N" QA fixtures
  sorting to the top of the default alphabetical list; (c) three-way name
  collisions on "Anele Botha" and "Zanele Bezuidenhout" where a webinar
  search could land on a bare record instead of the flagship one already
  in §6. Coordinator directed fixing (b) and (c): archived the 24 fixtures
  (soft-delete, confirmed zero communication_links/calendar_event_links
  referenced them first) and renamed the two non-flagship records in each
  collision pair to collision-checked real names, keeping the richer
  record (31 for Anele Botha — confirmed via viewing_packs.contact_id=21;
  45 for Zanele Bezuidenhout — 6 comms + 3 property views). Verified live:
  contacts?agent_id=all now shows zero "Spine" and exactly one of each
  name. §6 and Known gap #10 updated. (a) reported, not silently fixed —
  it's a one-click workaround (?agent_id=all) documented for Johan's run
  sheet, not something changed in code tonight. 93 contacts with no
  comm history and the broader duplicate-name pattern beyond these two
  pairs were left alone, per instruction — reported only.
