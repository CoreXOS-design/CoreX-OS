# CoreX browser extension 3.13.0 - release note and real-browser test steps

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
1. Download the new zip from CoreX QA1: profile menu > the Chrome extension download (file `corex-extension.zip`, version **3.13.0**). Unzip it to a new folder (not over the old one).
2. Chrome > `chrome://extensions` > turn on **Developer mode** > **Remove** the old CoreX extension > **Load unpacked** > pick the new folder. Check the card says **3.13.0**.
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

## What is verified, and how
- Property24 tiles: run against a REAL saved Property24 results page (21 tiles; tests/fixtures/p24-search) and three more real stored pages: owner adverts, price-reduced flags, 21/21 mandate ids, groups with every member number, floor vs erf separated, badges - all read correctly.
- Property24 listing pages: run against the 7 real saved listing pages (listing date, agent and agency names and ids, rates, levies, floor size, wording fingerprint).
- Private Property: written to the selectors seen in Chrome and covered by synthetic pages only - needs the real-browser check above.
- Server: `MicCaptureIngestTest` (16 tests), `MicCaptureStorageTest` (4); extension: `node tests/mic-lib.test.cjs` (27 checks, needs jsdom).
