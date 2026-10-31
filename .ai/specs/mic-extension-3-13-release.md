# CoreX browser extension 3.14.0 (3.13 capture + 3.14 area pages) - release note and real-browser test steps

_MIC Work tab, capture lane (cc3). Built 31 Oct 2026 on QA1. Spec: `mic-work-tab.md` sections 5 and 11.2._

## Release note for agents (plain language)
**What is new.** When you capture a Property24 or Private Property search, CoreX now also notes, for every advert on the page:
- who is selling: an estate agency or the owner privately (Property24 "listed by owner", Private Property "Listed privately"). Private adverts no longer show up with an agency called "Sale".
- price cut ("Reduced") flag, and the labels the portal shows on the advert: retirement, auction, repossessed, on show, no transfer duty.
- the floor size and the erf size as two separate figures.
- on Private Property, "Under Offer" adverts are now recognised, and "Only on Private Property" (exclusive) adverts are marked. Private Property searches now include under-offer and sold adverts.
- when Property24 says one property is advertised by several agencies ("Listed by 3 Estate Agencies"), CoreX remembers which adverts belong together.

**New: CoreX reads a few listing pages for you.** After a capture finishes, the extension opens - one after another, a few seconds apart, in the same tab - the pages of adverts that are new or whose price changed (never more than your agency's limit, 40 by default). It reads what the page shows: the date the advert was listed, the agent and agency, rates and levies, the map point when the portal shows one. It then returns your tab to the search. It never clicks "Show contact number" and never reads phone numbers or e-mail addresses. Of the advert wording and first photo it keeps only a fingerprint (a short code) so the same property on both portals can be recognised - never the text or the picture.

**What you do.** Nothing different: open a search, press Capture Listings as before. Leave the tab alone until the notification says it is complete (it now says how many listing pages were read).

## Install / update (QA1 test build)
1. Download the new zip from CoreX QA1: sign in, then open `https://qatesting1.corexos.co.za/corex/extension/download` (there is no menu link; the file is `corex-extension.zip`). Unzip it to a new folder (not over the old one).
2. Chrome > `chrome://extensions` > turn on **Developer mode** > **Remove** the old CoreX extension > **Load unpacked** > pick the new folder. Check the card says **3.14.0**.
3. Open the extension popup > Settings: the CoreX address must be `https://qatesting1.corexos.co.za` and the token must be a QA1 token (QA1 only - do not point this build at live).

## Test steps for the real-browser check (Johan, Chrome)
Each step says what to look at. "QA1 check" = the proof on the QA1 side (the conductor can read these from the database).

**A. Property24 search with owner adverts.** Search for-sale in a suburb that has "by owner" adverts (e.g. Margate, Uvongo). Press Capture Listings on page 1. Let it run.
 - QA1 check: owner adverts have `advertiser_type = owner` and no agency name (none called "Sale"); agency adverts have `advertiser_type = agency`; reduced tiles have `is_reduced = 1`; badges appear on tiles that show them.
**B. P24 groups.** On a results page that shows "Listed by N Estate Agencies", capture it.
 - QA1 check: rows in `p24_group_members` for that group id, with every member's listing number; `p24_group_id` on the adverts.
**C. Floor vs erf.** A flat/townhouse tile (floor icon) and a house tile (erf icon).
 - QA1 check: flat has `property_size_m2` (floor) and no erf; house has `erf_size_m2`.
**D. Listing pages.** After the capture ends the tab should walk through a handful of listing pages (only new / re-priced adverts - capture a suburb never captured before to see it), then return to the search.
 - QA1 check: those adverts have `listed_date`, `agent_name`, `agent_portal_id`, `agency_portal_id`, `rates_amount` / `levies_amount` where shown, `details_read_at` set, a 40-hex `wording_fingerprint`; `first_photo_fingerprint` is filled **only if Property24's image server allows the read** (if it is empty for every advert, tell the conductor - the fix is a small permission change, not a rebuild).
 - Check the limit: at most the agency limit of listing pages is opened.
 - Nothing on screen asks for or reveals a phone number; the extension never clicks "Show contact number".
**E. Private Property search.** Open a PP search (add a status filter if the site offers "exclude sold / under offer" - the extension removes it). Capture it.
 - QA1 check: "Listed privately" adverts are `owner`; "Under Offer" adverts have `portal_status = under_offer`; "Only on Private Property" adverts have `is_exclusive = 1`; floor and land size are separate; the capture covers sold / under-offer adverts too.
 - **The Private Property page structure could not be checked from the build machine (no saved PP search or listing page exists in the repo).** The selectors follow what was seen in Chrome on 31 Oct (`.listing-result__listed-privately`, `.listing-banner--offer-pending`, "Only on Private Property", `[title="Floor size"]` / `[title="Land size"]`). If any PP step shows nothing, send the page (File > Save as, "Webpage, HTML only") - a saved page lets the reader be fixed and tested the same way the P24 one was.
**F. Old data intact.** The adverts you re-captured keep their earlier address, price history and claims; a tile that lacks an agent or agency does not wipe the stored one.

## Johan's checklist for the 3.15.0 Chrome check (about 10 lines; same steps for the conductor's summary)
1. Sign in to QA1 in Chrome, then open `https://qatesting1.corexos.co.za/corex/extension/download` - it saves `corex-extension.zip` (version 3.15.0; checked: the address answers for user 22). Unzip it into a NEW folder.
2. Go to `chrome://extensions`, switch on Developer mode, Remove the old CoreX, press Load unpacked and pick the new folder. The card must say 3.15.0.
3. Click the CoreX icon > Settings: address `https://qatesting1.corexos.co.za` and your QA1 API token (QA1 only - never live).
4. In MIC open the Work tab > "Market data last refreshed". Pick one suburb row (Uvongo is fine) and note its age, e.g. "30 d".
5. **Page 1 - Property24:** on that row press "Open in P24" (it opens newest first). Click the CoreX icon > Capture Listings. Let it finish (you get a notification; afterwards it opens a few adverts one by one and comes back - leave the tab alone).
6. **Page 2 - Private Property:** on the same row press "Open in PP" and find a page-1 result that says LISTED PRIVATELY (try another suburb's PP link if this one has none). Click the CoreX icon > Capture Listings and let it finish.
7. **Page 3 - Read area pages:** on the same row press "Read area pages". A small blue note appears bottom-right: "CoreX: saved". The tab then moves on by itself - trends, then agencies, then every street (a few minutes; do not close it).
8. Back in MIC, reload the Work tab: that suburb's age is now **0 d**; the **Owner selling** tile shows more owners than before (63 today) and the private advert you saw is in its list; reduced and under-offer adverts are flagged.
9. The suburb snapshot (sales, average prices, stock, agencies) has no screen yet - tell the conductor the suburb name and they read the figures back from the database.
10. If something fails, send the conductor: which step number, a screenshot of the blue note or the CoreX popup message, and - for anything on Private Property - the page saved with File > Save as > "Webpage, HTML only" (that lets us fix the reader the way the Property24 one was fixed).

**What the capture adds today (QA1, HFC, live adverts, 31 Oct 2026, before your check):** see the coverage report in the Jira comment on AT-522 - almost every figure above is still 0 because only this extension fills them.

## What is verified, and how
- Property24 tiles: run against a REAL saved Property24 results page (21 tiles; tests/fixtures/p24-search) and three more real stored pages: owner adverts, price-reduced flags, 21/21 mandate ids, groups with every member number, floor vs erf separated, badges - all read correctly.
- Property24 listing pages: run against the 7 real saved listing pages (listing date, agent and agency names and ids, rates, levies, floor size, wording fingerprint).
- Private Property: written to the selectors seen in Chrome and covered by synthetic pages only - needs the real-browser check above.
- Server: `MicCaptureIngestTest` (16 tests), `MicCaptureStorageTest` (4); extension: `node tests/mic-lib.test.cjs` (27 checks, needs jsdom).

## 3.14.0 - monthly area pages (new)
**For agents.** CoreX can now keep the free Property24 suburb statistics up to date for you. When CoreX opens a Property24 "trends", "estate agencies" or "property values" page for one of your suburbs (the button on the Market data last refreshed panel), the extension reads that page - and only that page; ordinary browsing is never sent - and shows a small blue note in the corner: "CoreX: saved". For the agencies list it moves through the next pages by itself (up to your agency's limit, 10 pages), and for the street list it visits each street a few seconds apart (up to your agency's limit, 150 streets), then returns your tab. Prices on the street pages are paid information and are never read; only the house numbers and the year are kept.

**Test steps (Johan, Chrome, extension 3.14.0).** Use a suburb you know. Open each address yourself with `#corex-area` on the end (the Work-tab button will do this once built):
 1. Trends: `https://www.property24.com/margate/uvongo/property-trends/6359#corex-area` - the blue note should say "saved. ~150 figures saved". QA1 check: rows in `p24_suburb_trends` for suburb 6359 (annual sales and prices, monthly stock, stock by bedrooms, sold erven vs sectional, age profile), all dated today.
 2. Agencies: `https://www.property24.com/estate-agencies/uvongo/margate/kwazulu-natal/6359#corex-area` - the note walks "page 2 of 10" and so on; QA1 check: `p24_suburb_agencies` has one row per agency with its listings in Uvongo and total.
 3. Streets: `https://www.property24.com/property-values/uvongo/margate/kwazulu-natal/6359#corex-area` - the tab visits each street (about 50 for Uvongo, one every 3-4 seconds), then comes back; QA1 check: `p24_street_numbers` has the house numbers per street and no price anywhere.
 4. Open one of the pages WITHOUT `#corex-area`: nothing is sent and no blue note appears.
**Verified on real pages (saved 31 Oct 2026, tests/fixtures/p24-area):** Uvongo trends (10 years of sales and prices, 12 months of stock, stock by bedrooms, erven vs sectional, age profile), agencies (20 tiles, with the pager), street list (53 streets), Marine Drive (house numbers). The walking between pages (next agency page, next street) uses the same page reader but could not be exercised without a browser.

