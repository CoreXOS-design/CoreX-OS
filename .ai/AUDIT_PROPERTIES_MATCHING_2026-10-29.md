# Audit leg 1 — Properties and matching (QA1, 2026-10-29)

> Read-only investigation of QA1 (`8dfee589f`). Nothing was built or changed. Template and plan: `.ai/AUDIT_PLAN_2026-11.md`.
> Technical detail (files, lines, tables, columns, SQL) is in the separate appendix:
> `.ai/AUDIT_PROPERTIES_MATCHING_2026-10-29_APPENDIX.md`.
>
> Why this leg matters (Johan): the start of Road to Sold depends on data that is linked with confidence. Correct MIC and
> deeds data also feed presentations and property evaluation accuracy.
>
> Every source below answers three questions in this order: **(a)** where do we get the data, **(b)** what does it mean,
> **(c)** how do we put it to best use for an agent, to give clients better service.

## Words used in this report

- **Listing** — one advert on a portal (Property24 or Private Property). It has an advert number. One house can have many listings over time.
- **Property file** — CoreX's single shared record of one real-world property (technically a "tracked property"). Road to Sold holds, flags and outcomes already hang on it.
- **Stock** — properties the agency itself holds (the Properties screen).
- **Match** — deciding that two records describe the same real-world property.
- **MIC** — Market Intelligence: other agencies' listings, scraped by the web extension.

---

## 1. The short version

**The good news.** CoreX already has the right backbone: one shared "property file" per real property, one careful address-comparison
engine with confidence levels, an agent veto ("not the same property"), and Road to Sold already hangs its holds and outcomes on the
property file. We do not need a new system. We need every source to feed that backbone the same way.

**The bad news, in five numbers (QA1, all HFC):**

| What | Number | What it means |
|---|---|---|
| Our own properties that are not on the shared property file | **8,051 of 9,920 (81%)** | Road to Sold, the map and deeds cannot see most of our own stock as part of the shared picture. 88 of HFC's 185 on-market properties are among them. |
| The same Property24 advert held as separate property files | **163 of 173** own-vs-MIC overlaps, and **4,561 of 5,433** portal-capture-vs-MIC overlaps | The same house exists twice (or three times) because each source writes the advert number in a different format. |
| MIC listings that have a street address | **14,216 of 45,440 (31%)**; with GPS only **6,529 (14%)** | Most MIC rows can only be matched by advert number, not by where the house is. |
| MIC listings recognised as ours (claimed / in stock) | **1,072 (2.4%)** | The "claimed by an agent" flag fires on very few rows because it relies on exact address text. |
| Deals linked to a property | **30 of 177 (17%)** | 147 deals are only a typed address. They never reach the map, deeds or Road to Sold. |

**Two other things Johan should know:**

1. **The matching engine also merges too much.** 68 property files carry 10 or more portal adverts (one carries 75). These are
   apartment blocks and estates where different units were squashed into one file. 28,302 property files sit on a "suburb centre"
   map pin instead of the real building, and the engine treats pins that close together as the same place.
2. **Email alerts are not linked to any property.** The alert importer only keeps the advert number, price and suburb in a separate
   table. It can be lined up with a MIC listing by advert number (8,226 of 14,092 alerts today) but only for display, never saved.

**Plain answers to Johan's questions:**

- *Do we capture the portal reference on web-extension and email imports?* **Yes, on every row** (45,440 of 45,440 extension rows;
  14,092 of 14,092 P24 alert emails). Private Property has no email import at all.
- *Can an email alert be matched to a P24 import?* **Yes, by advert number, but only when the MIC screen is drawn.** Nothing is saved.
- *What do a deeds record and a MIC scrape have in common?* **Only the street address and suburb** (and GPS where both are good).
  MIC saves no erf number, deed number, SG code or owner. The deeds side has erf on 48% and a deed number on 99.8%.
- *Map pins vs properties?* A pin is a **view** over three tables (our stock, the shared property files, MIC listings). They are lined
  up by address at the moment the map is drawn. Nothing is saved, so the same house can show as two pins.
- *Presentation / CMA comps vs properties and MIC?* **Not linked at all.** Comps have no property, no advert number and no erf.

**The smallest change that unlocks the most:** make every source write the **advert number in one common format** and let the matcher
compare it across sources (item 1 in section 6), and make **every new or imported property go through the shared property file** (item 2).
Those two alone fix most of the duplication and give Road to Sold a trustworthy start.

---

## 2. Source by source

### 2.1 Properties (our own stock)

**(a) Where we get the data.**
Typed in on the web form or wizard, created on the mobile app, imported from a Property24 export file (8,145 of our 9,920 properties
came this way), pulled from a portal page with the extension, imported from a sold spreadsheet or from another agency's stock, or promoted
from deeds / tracked properties / a Road to Sold list. Of these routes, **only the promote routes (and one MIC pitch route) check for an existing match first**.
The typed, mobile, pulled and imported routes each look for a duplicate in their own private way (advert number, or nothing at all).

What we save: advert number on 83% (Property24) and 2% (Private Property); **no portal web address at all**; erf number on 7.5%; title
deed number on 16%; **SG code on none**; street number + street + suburb on 39%; GPS on 91% (but 6,179 were never geocoded by us, they came from
the import file, and 1,105 are suburb-centre only). No owner on the property row (owners hang off contacts).

**(b) What it means.**
These rows are our truth about what we hold, but they are an island. A property typed in on the web form has no link to anything the deeds
screen or MIC knows. 234 groups (about 500 rows) share the same street number, street and unit, which are probably duplicates we
created ourselves, but nobody can tell real second units from duplicates without a review.

**(c) Best use for the agent.**
The moment an agent captures a property, CoreX should say "we already know this one": the deeds owner, the MIC adverts for it (ours
and competitors'), previous listings, and whether another agent already holds it. That needs the property to join the shared property
file on creation. Today the agent can create the same house twice and nothing warns them (except when they start from a contact).

### 2.2 MIC scrapes (web extension, other agencies' Property24 / Private Property stock)

**(a) Where we get the data.**
The extension reads the search result tiles and sends them to CoreX (45,440 listings; P24 24,332, PP 21,108). We save: the **advert
number (always)**, the advert web address (always), the address text **only when the portal shows it (31%)**, suburb (always), price,
beds/baths/garages, erf **size** in m² (about 90%), the agency name (93%), and on Property24 the status (active / under offer / sold /
withdrawn). We do **not** save: erf number, deed number, SG code, owner, GPS at capture (GPS arrives later from our geocoder, 14%), the agent's
name (0 rows), or the mandate type (0 rows). A relisted advert gets a new number and becomes a new row.

**(b) What it means.**
An advert number identifies **an advert, not a property**. When the agent relists, the number changes and the old row stays behind (about
1,450 duplicate groups on the same address already). The address on a MIC row is often only partly shown by the portal, and what we keep
is a single text string, with no split into number and street. When the street number is read badly (for example "303" which is the *unit* in
"303 Pebble Beach, 10 Heleza Boulevard") the engine files 75 different adverts onto one property file.

**(c) Best use for the agent.**
MIC is the agent's market radar, but only if each row is tied to **the real property**. Done right, the agent sees on one property: every
advert it has ever had (all relists), price history, how long on market, whether it is ours, and what deeds says about the owner. Today
the tie is made by exact address text, which is why only 2.4% show as claimed. Reading erf size + suburb + street together (all of which we
have on most rows) would let many more rows tie to a deed.

### 2.3 Property24 email alerts (and Private Property)

**(a) Where we get the data.**
An hourly job reads the alert emails (15,529 emails since February). From each it takes the advert number, price, property type,
beds/baths/garages, suburb and a "mandated" word. Alerts **never contain the street address**. 14,092 alerts are saved, in their own table.
Private Property has **no alert import at all**. Portal enquiries (leads: 626 P24, 243 PP) also carry only an advert number and no address.

**(b) What it means.**
An alert is the earliest and cheapest signal that something is new or its price moved, but it is *only* an advert number plus a
suburb. It cannot be matched by place, so its entire value depends on the advert number lining up with another record. Today it lines up
for 8,226 alerts (58%) with a MIC row, and for 270 with one of our own properties; 5,866 alerts have no MIC row yet.

**(c) Best use for the agent.**
"New in your area this morning", "price dropped on the house you pitched", "a seller you spoke to just listed with someone else". That needs
the alert saved against the property file (not just displayed), and the 5,866 alert-only adverts queued for the extension to visit so they
get an address. Enquiries on our own listings would then attach to the right property and contact automatically; 22 enquiries today
do not resolve to a property.

### 2.4 Our own portal imports (Property24 / Private Property syndication)

**(a) Where we get the data.**
When we publish or refresh one of our listings, CoreX records the portal advert number against the property. Property24: bare number
("117740465"). Private Property: "T5663557".

**(b) What it means.**
This is the **only place where the portal tells us for certain that an advert is ours**, and it is the strongest key we have.
But it is recorded in a different spelling from the extension's ("P24-117740465"), so CoreX never recognises that the competitor view
and our own listing are the same advert.

**(c) Best use for the agent.**
Our own listings should appear in MIC marked "ours", with the agent's name, automatically and on day one. That is the base for
"claimed by" and for avoiding pitching a seller whose home we already hold.

### 2.5 The map

**(a) Where we get the data.**
The map draws pins from our stock, the shared property files, MIC adverts, sold comparables and CMA report subjects. Clicking "start
prospecting" on a pin creates a property file from the **address text and GPS alone** (9 so far). No map action creates stock.
GPS comes from our geocoder (Google, with a daily limit). 937 of our properties have no usable pin; 28,302 property files (of 37,080 with a pin)
sit on the middle of their suburb; 85% of MIC adverts have no GPS of their own.

**(b) What it means.**
A pin is a **picture, not an identity**. The map lines up our stock with the shared property files by comparing address text when it draws,
so a mismatch gives two pins for one house. A suburb-centre pin says "somewhere in Uvongo" but looks like a street-level dot.

**(c) Best use for the agent.**
The map is how an agent plans a day (door-knock a street, see who owns, what's on the market). It only helps if each pin is one property
with its deed, MIC and stock state behind it, and if suburb-centre pins are clearly marked as approximate.

### 2.6 Presentations and CMA comparable data

**(a) Where we get the data.**
When a presentation is made, CoreX copies matching rows from the CMA reports into the presentation (2,180 sold comparables, 5,635
active). Rows also come from uploaded PDFs, agent picks, and the extension's page captures (8,767 Property24 pages, 69% pointed at a property
file; but nearly none of those files has a suburb, because of a one-word bug where the suburb sits under another name).
A comparable keeps: sale date and price, a suburb (free text, sometimes polluted with "(Unit 43)"), beds/baths/size, and the address inside
a hidden text blob. It has **no property link, no advert number, no erf, no GPS column.** The presentation itself is tied to a property in
136 of 222 cases and never to a property file.

**(b) What it means.**
A comparable is a **sale somewhere nearby**, not a known property. We cannot say "this is the same house as the MIC advert, or as
the deed we captured last month". The same sale also appears several times (130 duplicate groups inside presentations; 252 across
CMA reports).

**(c) Best use for the agent.**
Property evaluations are only as sharp as their comparables. If a comparable is tied to its property file, the agent gets the
registered price from deeds, the asking-price history from MIC, the CMA sale and the municipal value in one place, and can prove
"this is what it actually sold for". Without the tie, the same sale can be counted twice or a stale asking price used.

### 2.7 CMA report imports (the bulk importer)

**(a) Where we get the data.**
Agents upload CMA reports (436 reports, 3,557 comparable rows). The importer reads each row's address, scheme, section, price,
date, size and sometimes GPS (50%). Only a **subset of addresses** (the part the parser hands over) is passed to the shared property
file: 702 property files from 133 reports. A report is considered "already imported" only if it is the exact same file.

**(b) What it means.**
These are real sales, but most never join the property file, and the erf number (when present, about one row in five) is buried in a
hidden field. The suburb on a row is the **report's** suburb, not the comparable's own, so a vicinity report taken in Uvongo labels its
neighbouring-suburb sales as Uvongo.

**(c) Best use for the agent.**
Every comparable with a usable address should update its property file with the sale price and date. Then "last sold" is correct
in the Road to Sold list and the evaluation, and an agent can see who bought and when without opening the report.

### 2.8 Deals

**(a) Where we get the data.**
Deals are captured on the deal register: a typed address and, if the agent picks one, a property. 177 deals; **30 linked to a
property**; 147 are only an address typed by hand. A deal carries no erf, deed number, structured address, GPS or advert number. A tool to
link deals to properties exists but has to be run by hand and has not been scheduled.

**(b) What it means.**
A deal is a **transaction on some property**, but CoreX does not know which one for 83% of deals. These deals never appear as sold pins,
never update the property file's last sold price, and cannot be tied to the deed that registered the transfer.

**(c) Best use for the agent.**
A signed deal is the best proof that a seller chose us. It should settle the property file immediately: status sold, price,
date, the agent. That feeds the leaderboard, the "who sold on this street" pitch to neighbours, and the Road to Sold outcome.

### 2.9 Deeds capture

**(a) Where we get the data.**
The agent opens the property on the deeds site; the extension reads it and sends it. 1,912 property files (1,640 promoted to
stock). We save erf number (48%; sectional units have none of their own), deed number (99.8%), scheme/section, extent, municipal value
(1%), registration date, bond holder, sale price and date, owners with ID numbers (1,911), the address (88% structured) and GPS (99.8%).
The 21-character SG code arrives on only **5** records, because the deeds site gives us a scheme-style reference and the extension does not
read the code.

**(b) What it means.**
Deeds is the **most trustworthy** source: it is the registered truth. But a title deed number is the number of *this transfer* and changes
every time the property is sold, so it identifies the property only at a moment in time (general property-law knowledge, not tested in
the code). The erf + portion or the SG code is the stable key for a freehold stand; scheme + section for a sectional unit. We store the
deed number on 99.8% of captures yet **never use it to match anything**.

**(c) Best use for the agent.**
Owner name and bond tell the agent who to call, and whether the owner may be ready to sell. Matching deeds to MIC lets CoreX answer
"this competitor advert is owned by X, bought in 2019 for R1.2m, bond with Y, R300k left." Today the link needs the same erf+suburb,
the same street+suburb, or a pin within 5 m, or an agent to link it by hand (11 manual links).

---

## 3. What is matched to what today

| From \ to | Our stock | Shared property file | MIC adverts | Email alerts | Deeds | Map pins | Comps (CMA/presentation) | Deals |
|---|---|---|---|---|---|---|---|---|
| **Our stock** | n/a | 19% (promote only) | by exact address text or advert number | 270 by number | by address (when promoted) | drawn together by address | no | 17% |
| **MIC adverts** | 2.4% | 94.5% (many wrong, see §1) | n/a | by number, display only | by address/erf/GPS, 11 manual | drawn by address | no | no |
| **Email alerts** | 270 | **none** | by number, display only | n/a | no | no | no | no |
| **Deeds** | when promoted | yes (they are the file) | yes, address only | no | n/a | yes | no | no |
| **Comps** | no | 702 files fed from a subset of rows | no | no | no | partly (GPS in hidden field) | n/a | no |
| **Deals** | 17% | no | no | no | no | no | no | n/a |

---

## 4. The proposed single matching model

### 4.1 One identity, one rule

The **property file stays the one identity** (no new "property" table). Everything in CoreX that is "about a property" points at it:
our stock, MIC adverts, email alerts, deeds, comps, deals, map pins. Road to Sold already hangs its holds, flags and outcomes on it.

### 4.2 Identity keys, strongest first

| Rank | Key | Meaning | Confidence |
|---|---|---|---|
| 1 | **Portal advert number** (normalised: portal + number, whatever spelling the source used) | This advert *is* that advert | Certain for "same advert". Confident for "same property" (a relist has a new number) |
| 2 | **SG code**, or **erf + portion + suburb** (freehold); **scheme + section** (sectional) | Same registered stand or unit | Certain |
| 3 | **Title deed number + deeds office** | Same registered transfer. Use as a corroborator, not a standalone key, because it changes at each sale | Confident |
| 4 | **Street number + street + suburb (+ unit)**, read by the one address reader | Same address as written | Confident when exact; Possible if the suburb is a neighbour (Uvongo / Uvongo Beach) |
| 5 | **Erf size + suburb + street**, **GPS within 25 m with a second signal** | Corroboration | Possible only |
| 6 | Word overlap / street only | Hint | Weak, shown to agent only, never auto-linked |

**Rules:** certain and confident matches link automatically. Possible matches are shown to the agent to confirm ("same property? yes / no"),
and "no" is remembered forever (the veto already exists for deeds; extend it to every source). GPS alone never links. A pin at suburb centre
never counts as GPS. A unit or section on one side only is Possible, never Confident.

### 4.3 What each source must start saving

| Source | Must start saving |
|---|---|
| Extension (MIC) | Advert number in the common format; address **not overwritten by blank**; GPS and erf size sent when the tile shows them; **agent name and mandate** if shown (0 today); the address read with the one reader, not the first-number rule |
| Email alerts | Advert number against the property file (when one exists); first-seen and price-change history on it; PP alerts if PP offers them |
| Own stock | Advert number in the common format, **portal web address**, a link to the property file at creation, erf number, SG code where known |
| Portal captures | Suburb read from the right field; same advert key as MIC; agency stamped on each row (today global, a problem for the second agency) |
| Deeds | SG code (extension to read it); title deed number used as a match corroborator; on promote, copy SG code / portion / township to the stock row |
| CMA reports | Erf into a real field; property file id on **every** comparable row; cross-report duplicate check on address+date+price |
| Presentation comps | Property file id on each comparable (never written today); the "MIC row" id that is never filled |
| Deals | Pick-or-match a property at save; erf and structured address; set last sold on the property file |
| Map | Create the property file from the full address read, not the raw text; show suburb-centre pins as approximate |

**Structure to add:** (1) a normalised advert key (portal + number) on the existing list of references against each property file, compared across
all sources instead of the source's own spelling; (2) a property-file id on stock, comps and deals. No other new table is needed.

### 4.4 How a match should be used (all feeding Road to Sold)

1. **Claimed stock.** When an agent captures or imports a property, or deeds are promoted to stock, every MIC advert on the same property file
   (including old relists) is automatically marked "claimed by that agent". Confident matches claim automatically; Possible matches show a
   "confirm" chip.
2. **Deeds enrich MIC.** A deed linked to a property file writes a dated note against it (registered date, price, bond holder, year bought).
   The owner's name and ID are shown only to agents allowed to see them (the existing owner-privacy rules apply, they must not leak to the
   map or to other agencies).
3. **Prospecting status writes back.** "Not selling", "mandate elsewhere", "do not contact" are stored once on the property file (the Road to Sold
   hold and flag records already live there). MIC, the map, the Road to Sold list and deeds all read the same record.
4. **Email attaches to the property.** An alert or enquiry on a matched advert adds its price change, first-seen date and mandated flag to
   the property file's history, and counts as a signal in Road to Sold ("new to market", "price drop", "back on market").
5. **Sales settle the file.** A signed deal or a CMA sale updates last sold price and date on the property file, closing the Road to Sold
   cycle with proof.

**Why this serves Road to Sold:** one property, one record, one count. Lists stop showing the same house twice; holds placed on one advert
protect the house; the "sold" outcome is proven by a deed or a deal, not by a typed address.

---

## 5. Evidence (QA1 row counts)

| Source (table) | Rows | Advert no. | Erf no. | Structured address | GPS | Linked to property file | Duplicates seen |
|---|---|---|---|---|---|---|---|
| Own stock (properties) | 9,920 | 83% (P24), 2% (PP) | 7.5% | 39% | 91% (suburb-centre 1,105) | 19% | 234 address groups (~500 rows) |
| MIC adverts (prospecting listings) | 45,440 | **100%** | none stored | none stored (31% have address text) | 14% | 94.5% (quality low) | 1,458 same-address groups (rotating refs); 68 files with 10+ adverts |
| Email alerts (p24 listings) | 14,092 | **100%** | none | none (suburb only) | none | **0%** | 0 |
| Portal captures (portal listings) | 8,767 | 100% | none | none | none | 69% (3 with a suburb) | 4,561 split from MIC |
| Presentation comps | 2,180 sold / 5,635 active | none | none | none (in text blob) | in text blob | **0%** | 130 groups |
| CMA comparable rows | 3,557 | none | ~20% (in text blob) | none | 50% | 702 files fed from a subset of rows | 252 address groups |
| Deals | 177 | none | none | none | none | 17% | repeated typed addresses |
| Deeds (property files with deeds) | 1,912 | none | 48% | 88% | 99.8% | 100% | 0 |
| Property files (all) | 46,356 | via 10 different ref spellings | 2.7% | 18% | 80% (**28,302 suburb-centre**) | n/a | 97 address groups (773 rows) |

Where the same advert lives under different spellings: Property24 adverts are written four ways (`P24-123`, `123`, as a "capture" and as
"property24"), Private Property two ways (`PP-T123`, `T123`). The matcher's advert-number rule needs the **identical** spelling, so it never
links across sources.

All 45,440 MIC rows belong to one agency (HFC). Two tables (email alerts, portal captures) are global rather than per agency, and the
suburb-trimming rules used by presentations are written for the KZN South Coast. **That is a problem for the second agency** (project rule: multi-agency, always).

---

## 6. Gaps ranked by impact, with the smallest change that unlocks the most

| # | Gap | Impact | Smallest change |
|---|---|---|---|
| **1** | Every source writes the advert number in its own spelling, so the matcher's strongest rule (same advert) never fires across sources | Fixes own↔MIC (163/173), capture↔MIC (4,561/5,433), enquiries↔MIC, email↔property file. Biggest single unlock | Normalise the advert number (portal + number) in the matcher and compare that across sources; list candidate merges for review (do not auto-merge existing files) |
| **2** | 81% of our own properties are not on the shared property file; eight of the routes that create stock never check for a match | Road to Sold, map, deeds and MIC cannot see our stock; duplicates are created silently | Attach each new or edited property to its property file through the same match-or-create step (one hook), and a one-off back-fill for the 8,051 |
| **3** | Bad facts go in: MIC overwrites a known address with blank; unit number read as the street number; capture reads the wrong field for suburb (3 of 6,043 files have one); GPS never sent | The engine can only be as good as its input; this is where over-merging starts | Three one-line fixes plus pass the full address to the one reader instead of a first-number guess |
| **4** | The engine treats suburb-centre pins as exact places, and merges apartment blocks into one file | 28,302 files at suburb centre; 68 files with 10+ adverts (1,358 MIC rows) | Ignore suburb-centre pins in the 5 m rule; keep unit and scheme in the key for blocks; list the over-merged files for a split |
| **5** | Deeds keys unused: SG code on 5 of 1,912; deed number never used; SG/portion/township dropped on promote | The strongest keys are thrown away | Extension reads the SG code; promote copies it; add deed number as a corroborator |
| **6** | Comps and CMA rows have no property file id and no erf field (and the id column that exists is never filled) | Evaluations cannot prove or de-duplicate a comparable; the same sale is counted several times | Write the property file id and erf on every comparable and CMA row; stop the same sale entering twice |
| **7** | Deals: 147 of 177 are a typed address only | No sold proof, no last-sold update | Run the existing linker on save and offer the match to the agent |
| **8** | Email alerts not saved against a property; no PP alerts; 5,866 alert-only adverts never captured | New-to-market and price-drop signals lost | Alert importer attaches the advert to its property file; export the alert-only list for the extension to visit |
| **9** | MIC saves no agent name and no mandate (0 rows) | Cannot show "who holds it" | Check why the extension never fills them; read them from the portal tile if shown |
| **10** | Address reading is spread over 26 separate pieces of code; the structured columns are filled for only 284 of 46,356 property files | Matches differ depending on which screen asked | Finish the back-fill, then retire the old readers one at a time |
| **11** | Multi-agency: alert and capture tables are global; suburb-trim list is KZN-only | A second agency would see HFC's rows or mismatch | Add agency to both tables; make the suburb rules per agency |

**What I could not verify** (marked UNVERIFIED in the appendix): why 2,495 MIC rows with an address have no property file; why the structured-address
back-fill coverage on QA1 is so low; whether the map folds CMA comp rows into pins; whether the background job that makes "possible" stock matches is
running on QA1 (zero such matches exist although 6,529 rows have GPS); which extension version is in the field for the SG code.
