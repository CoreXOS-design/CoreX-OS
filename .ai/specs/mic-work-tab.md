# MIC Work tab — spec v1 (approved by Johan 31 Oct 2026)

## The goal
An agent opens MIC on their first day and one screen tells them which properties to chase today and why. Every P24 and PP advert is matched into one property record, and the list is ordered so the top rows are the ones we know most about and that are most likely to become a mandate.
- One screen: the Work tab is where the agent works. The other MIC tabs stay for now; nothing in this spec needs them.
- One row per PROPERTY (tracked property file), not per advert. Both portals, our own stock, P24 alert emails, deeds and CMA data on the same line.
- Tiles on top, not a greeting: "Hi Johan, here's your week" is removed. Hot-pocket tiles replace it; one click filters the list to that pocket.
- Ordered by opportunity score, not last seen (section 4).
- Agency walls: public portal facts are shared between agencies; claims, notes, contacts, deeds, CMA reports stay with the agency that made them.
- Numbers say how sure they are. Days on market = portal listed date when known; else first-seen date, shown "~45 days" (accurate to 7 days when the suburb had a complete crawl in the 7 days before first seen); if the advert was already there on our first read of the suburb (or after a crawl gap > 7 days) shown as a minimum "45+ days".

## 1. Tiles (11) across the top
Each tile: a count + one-line reason; click = filter the list to that pocket (or open its panel). One row of tiles fits the screen (no scrolling). Agency picks which tiles show (Settings); default all 11. A tile with too little data says so ("3 sales — too few to rank"), never invents.
1 Stuck on the market — long DOM, price cuts, re-listed (dead advert + new advert on same property). Data: 1,566 active adverts with a cut (847 last 30 d, 127 cut 2+); 659 re-list pairs; 2,456 P24 alert cuts. DOM per rule above.
2 Owner selling privately — advertiser_type = owner (P24 listed_by_owner tile image; PP "LISTED PRIVATELY"). Not captured yet.
3 Under offer, sold or gone — P24 ribbon already captured (1,048 under offer, 251 sold, 351 turned in last 30 d); PP "Under Offer" banner not sent yet; "Gone" = missing from a complete suburb crawl (section 6).
4 Where our stock sits — own properties by suburb/type/price band (165 on market, 518 sold, 2,564 withdrawn, 991 expired). Needs suburb ids.
5 What sells fastest for us + lookalikes — own sales listed→sold days (74 real-dated sales, avg ~30 d) by pocket (suburb+type+beds+price band); lookalike market adverts (37 pockets → ≥1,938 adverts). Show "based on N sales".
6 Where our leads come from — portal_leads (922, 900 linked) + property_portal_metrics (41,204 daily rows): leads per listing / per listing-week by pocket.
7 Buyers waiting, stock short — active sale wishlists (369) vs own stock vs market stock per pocket (31 pockets with 3+ buyers).
8 New from P24 alerts — alert adverts this week + alert price drops (14,092 alert rows, 63% matched to a MIC advert). Envelope icon.
9 Market prices here — avg sold vs asking, sales per year, stock trend per suburb: CMA comps (1,726 sales last 24 m), deeds, P24 suburb trends pages.
10 Neighbours of a sale (Elize's canvass rule) — trigger = deeds registration, our own sale, CMA sale, or a portal advert turning Under Offer/Sold. Neighbours = same street, numbers within 5 on the same side (same odd/even) + nearest numbers on the opposite side; sectional = other units in the same complex/scheme (or same complex land size in the suburb). Live from day one; drops off after 6 months (setting). Click the tile → panel of trigger properties, newest first, time filter (this month, 1,2,3,4,5,6 months), trigger-type filter (registered, sold, under offer, our sale), suburb filter. Click a trigger → the homes around it: address, deeds owner if held, on market?, our last contact; actions: claim, call, marketing drop to the block.
11 Market data last refreshed — oldest full refresh across the agency's suburbs ("Margate: P24 12 days ago"), red past the refresh interval (default 7 days). Click → every suburb the agency works, last full P24 read + last full PP read, oldest first, "Open in P24" / "Open in PP" buttons. Agents see their suburbs first; managers see all.

## 2. The property record (Excel-style row, expand in place, no cards)
Columns: Sources (icons: P24, PP, envelope = P24 alert, our stock, deeds, CMA comps, Owner) · Address (one address rule shared with filter + search; no address → "Suburb · type · complex N m²") · Property (type, beds, baths, garages, floor m², erf m², best value across adverts) · Price (asking + move since first seen "▼ R150k, 2 cuts") · Against the market (asking vs suburb avg sold for type/beds; R/m² vs suburb) · Who's selling (agency count + names, or Owner) · Time (DOM per rule; last seen) · Flags (Under offer, Reduced, Re-listed, Gone, Exclusive to PP) · Buyers (strong/mid) · Our work (claimed by, status, not-on-market reason, last contact — own agency only) · Next action (one button: Claim / Call owner / Pitch / Investigate).
Expand in place: adverts (portal, agency, agent, price, listed date, link) · P24 alerts (date, price, envelope) · price history · deeds (owner, years owned, last sale price/date, bond) · recent sales nearby (CMA) · matched buyers · our history (claims, contacts, notes, not-on-market reasons; kept when the property comes back on market).
Finding: one search box (must also match the file's resolved address — today "Newport" misses PP-T4203603), sortable columns, a filter per tile.

## 3. Opportunity score
Score per property; each row shows its top 2 reasons as chips. Default weights (agency settings): Seller signals 30% (cuts, re-listed, long DOM incl. first-seen estimate, owner advertising, mandate ended elsewhere) · Buyer demand 25% (strong/mid matches, buyers-waiting pocket) · Pocket heat 20% (fast-selling pocket, lead pocket, under-offer/sold activity in same suburb+type+band) · What we know 15% (address, deeds owner, alert history, several adverts agreeing, full facts) · Reachable 10% (owner contact from deeds/advert, nobody in our agency working it, no opt-out).
Rules: claimed by a colleague → down for others with the colleague's name; marked not on market → down until cleared or back on market, then up with "Back on the market" chip + old history; do-not-contact/opted out → no call action. Other sorts one click away: price, newest, biggest cut, most buyers.

## 4. Matching and grouping (evidence only; else review list; never delete)
R1 Same address (number+street+unit, suburb optional) — auto; flats with no unit → review. Built 31 Oct (333 merged).
R2 Our agency linked it (deeds capture, Edit address, prospecting capture) — address shows on all adverts on the file. Built 31 Oct (8e46774c3).
R3 P24 group ("Listed by N Estate Agencies": tile data-group-listing-id + listingNumbers[] in the GroupedListings link; listing page isGroupedListing) — all members join one file if nothing contradicts (street number, unit, beds, type, price spread > 1.5×); contradicting members → review. 1,551 groups in stored pages; 835 on separate files; 704 no-address adverts gain an address. This is the ONLY cross-agency link (P24's statement, not our inference); only public facts cross.
R4 Same agency, other portal — same agency, type, beds, baths, price within 1%, sizes; auto-link needs 2 of: same agent, same wording fingerprint, same first-photo fingerprint, same size. Johan: review-only until agent/wording/photo are captured, then auto-link with one-click undo.
R5 Same complex — hidden-address flats carry the complex land size (P24 erf e.g. 66,474 m²; PP land e.g. 4,128 m² + levies). Same suburb + type + land size = same complex → group units under the complex; same floor size + beds = likely twin → review.
R6 Map point — JSON-LD lat/lng (only when the address is shown) confirms or flags an address match.
Merges keep claims, statuses, not-on-market reasons, contacts, deeds, comments, alerts on the survivor. Before ANY further merge: fix the row-level merge journal (empty on 31 Oct) and combine two work records into one when both files hold our agency's work.
80 files hold 5+ adverts with > 2× price spread (e.g. 1 Ocean Club Drive, Zimbali, 33 adverts) → unit-split review.

## 5. Portal data to capture (checked in Chrome 31 Oct)
Today the extension (3.12.0) sends tiles only: ref, url, address (mostly null), suburb, price, beds, baths, garages, one size, type, agency name (img alt), thumbnail, P24 status ribbon. Add:
- Listed date: P24 listing page "Listing Date" + JSON-LD datePosted; PP listing page "Listing date".
- Agent + agency: P24 JSON-LD offers.offeredBy.name / worksFor.name (+ agentId in page JSON); PP listing page "Contact Agent".
- Floor and erf size separately: P24 tile shows one size with img alt "Erf Size"/"Floor Size"; listing pages show both; PP "Land size"/"Floor size". Back-fill ~3,800 floor sizes from portal_listings.current_fields_json.size_m2.
- Reduced: P24 tile .p24_reducedBanner.
- Under offer/sold: P24 ribbon (captured); PP .listing-banner--offer-pending "Under Offer"; PP search must include under offer & sold (default; never send ps=ExcludeSoldOrUnderOffer).
- Private seller: P24 tile .p24_branding img src ".../Listing/listed_by_owner.png" alt "Sale" (so today agency_name is stored as "Sale" — fix + back-fill), listing page "Contact Seller", no offeredBy in JSON-LD (example P24-116922912). PP tile .listing-result__listed-privately "LISTED PRIVATELY"; listing page "Contact Seller" + owner name; serverVariables.bundleParams.agencyInfo null. Store contact only as the advert shows it; never click "Show contact number".
- P24 group: tile data-group-listing-id, data-listing-group-text "N Estate Agencies", listingNumbers in /Results/GroupedListings/<id> link; listing page isGroupedListing. Back-fill the 1,551 groups from stored pages.
- P24 mandate id: tile data-mandate-id, listing page mandateId (test whether it links re-lists).
- Map point: P24 JSON-LD about.latitude/longitude; PP JSON-LD geo — only present when the address is shown.
- Rates and taxes, levies (both listing pages). Badges: P24 retirement, auction, repossessed, on show, no transfer duty; PP exclusive ("Only on Private Property"), on show, bank sales. Features: pool, garden, sea view, security, pets.
- Wording + first photo: store a fingerprint only, never the text or image.
- Listing pages are opened only for new adverts or a changed price, capped per session.
Free P24 area pages, monthly per suburb (via the extension from the tile 11 panel): trends (/<town>/<suburb>/property-trends/<id>: annual deeds sales + avg asking vs avg sold, sectional vs erven, avg asking by beds, monthly new/total stock, seller/buyer/owner age profile); street pages (/property-values/<street>/<suburb>/.../<id>: every house number → tile 10; prices are paid, don't use); agencies (/estate-agencies/<suburb>/...: listings per agency). SA Home Traders = P24 mirror (same listing numbers) → ignore.

## 6. Portal searches + weekly refresh
1 Areas: each agency lists its areas once in Settings (suburbs or groups); CoreX builds P24 + PP search URLs per area (PP incl. under offer & sold).
2 Tile 11 panel (above).
3 Crawl record per suburb + portal: started/finished, by whom, pages read of total, adverts seen; complete only when every results page was read.
4 Listing pages for new/changed adverts only (section 5), capped per session.
5 Gone rule: advert missing from a complete crawl of its suburb → "Gone"; if its last status was Under Offer → "Probably sold"; reappears → clears. Fix: 4,286 PP adverts stuck "withdrawn" though seen again.
6 Monthly area pages.
Fixes: 8,897 P24 adverts active but unseen 14+ days; 937 past their suburb's last crawl.

## 7. Data CoreX already holds (use it)
CMA comps (market_report_comp_rows; 3,545 with price+date; 1,726 last 24 m; 42 suburbs) → avg sold per suburb; R/m² separately for erf (freehold) and floor (sectional); recent sales near a property; tile 10 triggers. Clean: 310 duplicates across reports (flag, don't delete); "uvongo" vs "uvongo beach" mapping via suburb ids; comps have no beds; presentation_sold_comps are mostly copies — don't double count.
Deeds captures (1,912): sold price 1,803, registered date 1,908, bond 475; years owned (avg 10.3) → row + "long-held" signal + tile 10 triggers.
P24 alerts (14,092; p24_listings, p24_price_changes): envelope, alert price drops, tile 8. All rows "active" → needs an end date.
portal_leads (922) + property_portal_metrics (41,204) → tile 6. Sold stock: real date only on ~74–86 → record offer-accepted / sold date on status change from now on.
Buyers: contacts.is_buyer 717; contact_matches 369 active sale → tile 7 (join on p24_suburb_ids).
Team activity (manager view): CMA reports by agent, deeds captures by agent, capture sessions by agent.

## 8. Fix first (data)
DOM rule (above) · sold date capture · stale actives (crawl record + gone rule) · PP stuck-withdrawn · ONE suburb list + suburb ids everywhere (properties.suburb mixed case; collations differ — join on ids) · ONE property-type mapping table · CMA duplicate flagging · merge journal + work-record combine · speed: Work page 5.9 s cold / 169 queries, buyer sort 4.8 s; slowest: buyer count 0.77 s, listing count per load 0.43 s, strong-match count 0.35 s → nightly mic_pocket_stats + stored counts; target < 1.5 s.

## 9. Settings (Settings page only, agency-configurable, defaults)
Areas; refresh interval (7 d); tiles shown (all 11); score weights (30/25/20/15/10); neighbour window (6 months) and radius (5 each side + across); opt-out registry check before contacting an owner (OFF until NCC guidance); after-import same-address sweep (ON); P24 group linking (ON) with price-spread limit (1.5×); twin auto-link (OFF until capture exists).

## 10. Decisions (Johan, 31 Oct 2026)
Score weights as above, adjustable per agency · P24 groups linked where nothing contradicts, rest to review · Same-agency twins review-only until capture exists, then auto-link with one-click undo · Opt-out registry check = Settings switch, OFF for now · Weekly refresh = tile 11 · Neighbours: 5 each side + across, from day one, time filter up to 6 months, then drop off.

## 11. Lane split (conductor, 31 Oct)
cc5 — data foundation + numbers: suburb ids + type mapping + CMA duplicates; sold/offer date capture; nightly mic_pocket_stats + stored counts + speed; tile data services 1, 4, 5, 6, 7, 8, 9; opportunity score service.
cc4 — identity + crawl: crawl record + gone rule + PP stuck-withdrawn fix; merge journal + work-record combine + after-import sweep (M4); matching R3–R6 + review-list kinds; unit-split review; tile data services 3, 10, 11.
cc3 — capture: extension release with everything in section 5; ingest API + storage columns; back-fills (P24 groups from stored pages, floor sizes, "Sale" agency → owner); monthly area-pages reader (trends, street numbers, agencies).
cc6 — the screen: new Work tab (greeting removed, 11 tiles + panels, Excel row per property, expand in place, icons + envelope, score chips, search on resolved address, sortable columns, tile filters) + the MIC Settings page; consumes the services above.

### 11.1 cc5 contracts (written 31 Oct 2026, BEFORE build; cc6 codes the screen against these; change only by editing this section in a commit)

**Namespaces / files (all new, cc5 owns):** `app/Services/MarketIntelligence/Pockets/*`, `app/Services/MarketIntelligence/Score/*`, `app/Services/MarketIntelligence/Dom/*`, `app/Models/MicFileStat.php`, `app/Models/MicPocketStat.php`, `app/Models/MicAgencySetting.php`.

**Shared keys.** `suburb_id` = `p24_suburbs.id` (the existing P24 suburb table; 5.1). `type_key` = canonical property type slug from table `property_type_map` (5.2): `house, apartment, townhouse, land, farm, commercial, industrial, other`. `band` = price band slug `lt1m, 1to2m, 2to3m, 3to5m, gte5m` (edges are agency settings, defaults 1m/2m/3m/5m). `beds` = integer, 0 = none/land, capped at 5 (5 = "5+").

**Settings (storage = `mic_agency_settings`, one row per agency, JSON-cast columns; cc6 builds the Settings page, cc5 owns the model).** `MicAgencySetting::for(int $agencyId): MicAgencySetting` (creates defaults in memory, never writes on read). Getters: `scoreWeights(): array{seller:int,buyer:int,pocket:int,known:int,reachable:int}` default 30/25/20/15/10 (must sum to 100, else defaults used + logged); `tilesShown(): int[]` default 1..11; `priceBandEdges(): int[]` default [1000000,2000000,3000000,5000000]; `domApproxWindowDays(): int` default 7; `fastSaleDays(): int` default 45; `staleActiveDays(): int` default 14; `minSalesForPocket(): int` default 3.

**Tile result (every tile service returns this).** `App\Services\MarketIntelligence\Pockets\TileResult` (final, readonly): `int $tile` (spec tile number) · `string $key` · `?int $count` · `string $reason` (one line, plain English, already includes "based on N" where relevant) · `array $filter` (query params the Work list applies, exactly `['tile' => '<key>']` plus optional extra params) · `bool $tooLittleData` · `?string $tooLittleMessage` (shown instead of the count when true, e.g. "3 sales — too few to rank") · `array $meta` (tile-specific numbers, see below). `toArray()` returns the same keys.
**Entry point:** `App\Services\MarketIntelligence\Pockets\TileService::forViewer(\App\Models\User $viewer): array<int,TileResult>` — keyed by tile number, only the tiles in `tilesShown()`, only tiles 1,4,5,6,7,8,9 from cc5 (2, 3, 10, 11 are cc4's; cc6 merges). Agency = `$viewer->effectiveAgencyId()`; own/branch/agency visibility is applied for the viewer's `market_intelligence` scope. One tile: `TileService::tile(int $n, User $viewer): TileResult`.
**Tile keys:** 1 `stuck` · 4 `our_stock` · 5 `fast_sellers` · 6 `lead_sources` · 7 `buyers_waiting` · 8 `p24_alerts` · 9 `market_prices`.
**List filter:** the Work list reads `?tile=<key>` and calls `TileService::fileIds(string $key, User $viewer): \Illuminate\Database\Query\Builder` — a query selecting `tracked_property_id` (agency-scoped) for files in that pocket. Tile 9 has no file filter (returns null; it opens a suburb panel). Extra params in `$filter` (e.g. `suburb_id`, `type_key`, `beds`, `band`) narrow the same query.
**`meta` per tile:** 1 `{with_cut:int, cut_30d:int, cut_2plus:int, relisted:int, long_dom:int}` · 4 `{on_market:int, sold:int, withdrawn:int, expired:int, by_suburb:[{suburb_id,name,on_market,sold}]}` · 5 `{sales:int, avg_days:?float, pockets:[{suburb_id,type_key,beds,band,sales,avg_days,lookalikes}] , lookalike_adverts:int}` · 6 `{leads:int, listings:int, per_listing:float, per_listing_week:?float, pockets:[…]}` · 7 `{pockets_3plus:int, pockets:[{suburb_id,type_key,beds,band,buyers,own_stock,market_stock}]}` · 8 `{alerts_week:int, price_drops_week:int, matched_pct:float}` · 9 `{suburbs:[{suburb_id,name,avg_sold,avg_asking,sales_12m,r_per_m2_erf,r_per_m2_floor,stock_trend}]}`.

**Stored per-file counts.** Table `mic_file_stats` — PK (`agency_id`,`tracked_property_id`); columns: `buyers_strong`, `buyers_mid`, `leads_total`, `leads_30d`, `cuts_count`, `cut_amount`, `last_cut_at`, `relisted` (bool), `dom_days` (?int), `dom_mode` (`listed|approx|minimum|unknown`), `score` (?decimal 5,2), `score_seller|score_buyer|score_pocket|score_known|score_reachable` (0–100 each), `reason_1`, `reason_2` (reason codes), `computed_at`. Written nightly (command `mic:refresh-stats`) and refreshed for one file on change by `FileStatsRefresher::refresh(int $agencyId, int $trackedPropertyId)`. The Work list reads these columns; it must not recompute them per page.
**Pocket stats.** Table `mic_pocket_stats` — unique (`agency_id`,`stat_date`,`suburb_id`,`type_key`,`beds`,`band`); columns: `market_stock`, `aged_90`, `aged_180`, `cut_30`, `cut_90`, `relisted`, `own_on_market`, `own_sold`, `own_fast_sales`, `own_avg_days_to_sale`, `buyers`, `leads_30d`. Latest `stat_date` per agency is "current".

**DOM rule (5.6), `App\Services\MarketIntelligence\Dom\DomEstimator::forFile(int $agencyId, int $trackedPropertyId): DomResult` and `::forAdvert(ProspectingListing $l): DomResult`.** `DomResult` (final, readonly): `?int $days` · `string $mode` = `listed` (portal listed date known → exact "45 days") | `approx` ("~45 days": first_seen, and the suburb+portal had a COMPLETE crawl within `domApproxWindowDays` before first_seen) | `minimum` ("45+ days": no listed date and no such crawl, or the advert was already there on our first read of the suburb, or a crawl gap > the window) | `unknown` (no first_seen) · `string $label` (the exact text to print) · `?string $source` (`portal_listed_date|first_seen`). File-level days = the OLDEST advert's days among its adverts. **Crawl dependency (cc4):** interface `App\Contracts\MarketIntelligence\CrawlCompletenessChecker::completeCrawlWithin(int $agencyId, string $portal, int $suburbId, \Carbon\CarbonInterface $at, int $windowDays): bool` and `::firstReadOfSuburb(int $agencyId, string $portal, int $suburbId): ?\Carbon\CarbonInterface`. cc5 ships a default `NullCrawlCompletenessChecker` (always false / null → every advert "N+") bound in `AppServiceProvider`; cc4 replaces the binding when the crawl record lands. No other change is needed on either side.

**Opportunity score (5.7), `App\Services\MarketIntelligence\Score\OpportunityScoreService`.** `score(int $agencyId, int $trackedPropertyId): ScoreResult` (final, readonly): `float $score` (0–100) · `array $parts` = `{seller,buyer,pocket,known,reachable}` each 0–100 · `array $reasons` = top 2, each `{code:string,label:string}` · `\Carbon\CarbonInterface $computedAt`. Part inputs per spec §3. **Rules applied by the service:** claimed by a colleague → `$score` multiplied by 0.4 for every other viewer is NOT stored (viewer-specific); cc6 applies the viewer rule using `mic_file_stats` + the claim, the stored score is viewer-neutral. Reason codes (stable, cc6 maps to chips): `price_cuts`, `relisted`, `long_dom`, `owner_selling`, `mandate_ended`, `strong_buyers`, `buyers_waiting`, `fast_pocket`, `lead_pocket`, `pocket_activity`, `well_known`, `deeds_owner`, `alert_history`, `reachable`, `back_on_market`, `unworked`. Sorting: `mic_file_stats.score DESC, tracked_property_id ASC`.

**Not in cc5 (for the avoidance of doubt):** tiles 2, 3, 10, 11, crawl record, gone rule, merges (cc4); capture + ingest (cc3); the screen and Settings page (cc6).
