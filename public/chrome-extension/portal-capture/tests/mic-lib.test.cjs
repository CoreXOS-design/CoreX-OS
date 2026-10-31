/**
 * CoreX — MIC capture helpers (mic-lib.js) + the two content scripts' MIC wiring, run under jsdom against
 *   - the 7 REAL saved Property24 listing pages in tests/fixtures/p24/ (listing-page facts), and
 *   - synthetic tiles built from the selectors seen in Chrome on 31 Oct 2026 (.ai/specs/mic-work-tab.md section 5).
 * No live scraping. Needs jsdom (see the header of p24-oas-extract.test.cjs):
 *     NODE_PATH=/mnt/HC_Volume_103099143/tools/jsdom-test/node_modules node tests/mic-lib.test.cjs
 * Without jsdom the harness says so and exits 0.
 */
'use strict';
const fs = require('fs');
const path = require('path');
const assert = require('assert');
const crypto = require('crypto');

let JSDOM;
try { ({ JSDOM } = require('jsdom')); } catch (e) { console.log('SKIPPED: jsdom is not installed'); process.exit(0); }

const EXT = path.join(__dirname, '..');
// mic-lib.js is a browser script (no module system): run it against a plain object standing in for `self`
const MIC = (() => { const sandbox = {}; new Function('self', fs.readFileSync(path.join(EXT, 'mic-lib.js'), 'utf8'))(sandbox); return sandbox.CorexMic; })();
const FIX = path.join(__dirname, 'fixtures', 'p24');
let passed = 0;
function t(name, fn) { try { fn(); passed++; console.log('  ok  ' + name); } catch (e) { console.error('FAIL  ' + name + '\n      ' + (e && e.stack || e)); process.exitCode = 1; } }
const doc = (html, url) => new JSDOM(html, { url: url || 'https://www.property24.com/' }).window.document;

console.log('mic-lib.js');

t('sha1hex matches node crypto (ascii, unicode, long)', () => {
  ['', 'abc', 'Margate 3 bedroom — sea view ✓', 'x'.repeat(5000)].forEach((s) => {
    assert.strictEqual(MIC.sha1hex(s), crypto.createHash('sha1').update(s, 'utf8').digest('hex'));
  });
});

t('wording fingerprint ignores punctuation/case and needs enough text', () => {
  const a = 'Ideally positioned close to the beach, this well presented property offers relaxed no fuss coastal living.';
  const b = 'IDEALLY positioned close to the beach -- this well presented property offers relaxed, no-fuss coastal living!';
  assert.strictEqual(MIC.wordingFingerprint(a), MIC.wordingFingerprint(b));
  assert.match(MIC.wordingFingerprint(a), /^[0-9a-f]{40}$/);
  assert.strictEqual(MIC.wordingFingerprint('Nice house'), null);
});

t('parseRand / parseM2 / parseListingDate', () => {
  assert.strictEqual(MIC.parseRand('R 1 323'), 1323);
  assert.strictEqual(MIC.parseRand('R1,590.00'), 1590);
  assert.strictEqual(MIC.parseRand('R 3 700 per month'), 3700);
  assert.strictEqual(MIC.parseRand('n/a'), null);
  assert.strictEqual(MIC.parseM2('169 m²'), 169);
  assert.strictEqual(MIC.parseM2('1 375.5 m²'), 1375.5);
  assert.strictEqual(MIC.parseM2('66,474 m2'), 66474);
  assert.strictEqual(MIC.parseListingDate('01 September 2026'), '2026-09-01');
  assert.strictEqual(MIC.parseListingDate('2026-09-14T08:00:00Z'), '2026-09-14');
  assert.strictEqual(MIC.parseListingDate('17 Jul 2026'), '2026-07-17');
  assert.strictEqual(MIC.parseListingDate('yesterday'), null);
});

t('group listing numbers come out of the GroupedListings link', () => {
  assert.deepStrictEqual(MIC.listingNumbersFromHref('/Results/GroupedListings/5551?listingNumbers=116922912,116922999'), ['116922912', '116922999']);
  assert.deepStrictEqual(MIC.listingNumbersFromHref('/Results/GroupedListings/5551?listingNumbers=116922912%2C118000001&x=1'), ['116922912', '118000001']);
  // the real shape seen in Chrome: URL-encoded brackets
  assert.deepStrictEqual(MIC.listingNumbersFromHref('/Results/GroupedListings/1815781?showFavourites=True&listingNumbers=%5b116219508%2c117029515%5d&pageNumber=4'), ['116219508', '117029515']);
  assert.deepStrictEqual(MIC.listingNumbersFromHref('/x'), []);
});

t('PP search address is cleaned of the sold/under-offer filter', () => {
  const r = MIC.cleanPpSearchUrl('https://www.privateproperty.co.za/for-sale/kwazulu-natal/margate/123?ps=ExcludeSoldOrUnderOffer&page=2');
  assert.strictEqual(r.changed, true);
  assert.ok(!/ps=/.test(r.url) && /page=2/.test(r.url));
  assert.strictEqual(MIC.cleanPpSearchUrl('https://www.privateproperty.co.za/for-sale/x/1?ps=Other').changed, false);
});

console.log('P24 search tile');
const tile = (inner, attrs) => doc('<div data-listing-number="116922912" ' + (attrs || '') + '>' + inner + '</div>').querySelector('[data-listing-number]');

t('owner tile: listed_by_owner image -> owner, agency blank, never "Sale"', () => {
  const l = MIC.p24Tile(tile('<div class="p24_branding"><img src="https://x/Content/Images/Listing/listed_by_owner.png" alt="Sale"></div>'), { agency_name: 'Sale' });
  assert.strictEqual(l.advertiser_type, 'owner');
  assert.strictEqual(l.agency_name, null);
});

t('agency tile stays an agency; reduced banner, mandate id, badges', () => {
  const l = MIC.p24Tile(tile(
    '<div class="p24_branding"><img src="https://images.prop24.com/1/Fit" alt="Auction Alliance"></div>'
    + '<span class="p24_reducedBanner">Reduced</span><span class="p24_badge">Retirement</span><div class="p24_tag">On Show</div>'
    + '<span class="p24_ribbon">No Transfer Duty</span>', 'data-mandate-id="40121632"'), { agency_name: 'Auction Alliance' });
  assert.strictEqual(l.advertiser_type, 'agency');
  assert.strictEqual(l.agency_name, 'Auction Alliance');
  assert.strictEqual(l.is_reduced, true);
  assert.strictEqual(l.p24_mandate_id, '40121632');
  assert.deepStrictEqual(l.badges.sort(), ['no_transfer_duty', 'on_show', 'retirement']);   // the logo alt is NOT an auction badge
});

t('a tile without the reduced banner says is_reduced false', () => {
  assert.strictEqual(MIC.p24Tile(tile('<div class="p24_branding"><img src="a.png" alt="Pam Golding"></div>'), {}).is_reduced, false);
});

t('P24 group: id, "N Estate Agencies", listing numbers', () => {
  const l = MIC.p24Tile(tile(
    '<div data-group-listing-id="5551" data-listing-group-text="3 Estate Agencies"><a href="/Results/GroupedListings/5551?listingNumbers=116922912,116922999,118000001">3 Estate Agencies</a></div>'), {});
  assert.strictEqual(l.p24_group_id, '5551');
  assert.strictEqual(l.p24_group_size, 3);
  assert.deepStrictEqual(l.p24_group_listing_numbers, ['116922912', '116922999', '118000001']);
});

t('P24 group, real markup: the span carries id, text and data-href', () => {
  const l = MIC.p24Tile(tile('<span data-href="/Results/GroupedListings/1815781?showFavourites=True&amp;listingNumbers=%5b116219508%2c117029515%5d&amp;pageNumber=4" class="js_groupedListingsLink" data-listing-group-text="2 Estate Agencies" data-group-listing-id="1815781">Listed by <span>2 Estate Agencies</span></span>'), {});
  assert.deepStrictEqual([l.p24_group_id, l.p24_group_size, l.p24_group_listing_numbers], ['1815781', 2, ['116219508', '117029515']]);
});

t('size icon decides floor vs erf', () => {
  const floor = MIC.p24Tile(tile('<div class="p24_size"><img alt="Floor Size"><span>96 m²</span></div>'), { erf_size_m2: 96 });
  assert.strictEqual(floor.property_size_m2, 96);
  assert.strictEqual(floor.erf_size_m2, null);
  const erf = MIC.p24Tile(tile('<div class="p24_size"><img alt="Erf Size"><span>1 200 m²</span></div>'), { erf_size_m2: 1200 });
  assert.strictEqual(erf.erf_size_m2, 1200);
  assert.ok(!('property_size_m2' in erf) || erf.property_size_m2 === null);
  const unknown = MIC.p24Tile(tile('<div class="p24_size"><span>500 m²</span></div>'), { erf_size_m2: 500 });
  assert.strictEqual(unknown.erf_size_m2, 500);     // old behaviour kept when the icon is unreadable
});

console.log('P24 listing pages (7 real saved pages)');
const EXPECT = {
  'townhouse-uvongo-117580701': { listed_date: '2026-09-01', agency_name: 'Just Property Living', agent_name: 'Nadia Beattie', agent_portal_id: '365493', agency_portal_id: '12312', rates_amount: 1323, property_size_m2: 169, advertiser_type: 'agency' },
  'house-umhlali-golf-estate-117621889': { listed_date: '2026-09-14', agency_name: 'Local Real Estate', rates_amount: 3700, levies_amount: 1590, property_size_m2: 287, advertiser_type: 'agency' },
};
fs.readdirSync(FIX).filter((f) => f.endsWith('.html')).forEach((f) => {
  const name = f.replace(/\.html$/, '');
  t('listing page ' + name, () => {
    const facts = MIC.p24ListingPage(doc(fs.readFileSync(path.join(FIX, f), 'utf8'), 'https://www.property24.com/for-sale/x/y/z/1/' + (name.match(/(\d{6,})$/) || [])[1]), 'https://www.property24.com/for-sale/x/y/z/1/' + (name.match(/(\d{6,})$/) || [])[1]);
    assert.strictEqual(facts.page_read, true);
    // sale pages carry a listing date; P24 rental pages show "Occupation Date" instead, so none is read there
    if (!/^rental-/.test(name)) assert.match(facts.listed_date || '', /^\d{4}-\d{2}-\d{2}$/, 'a real listing date is read');
    assert.ok(['agency', 'owner'].includes(facts.advertiser_type));
    assert.ok(!('phone' in facts) && !JSON.stringify(facts).match(/\b0\d{2}\s?\d{3}\s?\d{4}\b/), 'no phone number is read');
    if (facts.wording_fingerprint) assert.match(facts.wording_fingerprint, /^[0-9a-f]{40}$/);
    const want = EXPECT[name] || {};
    Object.keys(want).forEach((k) => assert.strictEqual(facts[k], want[k], k));
  });
});

t('a listing page for a PRIVATE seller: owner, no agency', () => {
  const html = '<html><body><script>window.listingLeadFormContext = {"isPrivateListing":true,"listingNumber":{"number":116922912,"isValid":true}};</script>'
    + '<button>Contact Seller</button></body></html>';
  const f = MIC.p24ListingPage(doc(html), 'https://www.property24.com/for-sale/margate/x/1/116922912');
  assert.strictEqual(f.advertiser_type, 'owner');
  assert.strictEqual(f.agency_name, null);
  assert.ok(!f.agent_portal_id);
});

t('JSON-LD map point is read when present', () => {
  const html = '<html><body><script type="application/ld+json">{"@graph":[{"@type":"RealEstateListing","datePosted":"2026-09-01","about":{"@type":"House","geo":{"latitude":-30.8588,"longitude":30.3711}}}]}</script></body></html>';
  const f = MIC.p24ListingPage(doc(html), 'https://www.property24.com/x/116922912');
  assert.strictEqual(f.portal_latitude, -30.8588);
  assert.strictEqual(f.portal_longitude, 30.3711);
});

console.log('PP');
const ppLink = (inner) => doc('<a href="/for-sale/kwazulu-natal/margate/margate/12-main-road/T1234567">' + inner + '</a>', 'https://www.privateproperty.co.za/').querySelector('a');

t('PP tile: LISTED PRIVATELY -> owner, no agency', () => {
  const l = MIC.ppTile(ppLink('<span class="listing-result__listed-privately">LISTED PRIVATELY</span> R 1 500 000'), { agency_name: 'whatever alt' });
  assert.strictEqual(l.advertiser_type, 'owner');
  assert.strictEqual(l.agency_name, null);
  assert.strictEqual(l.portal_status, 'active');
});

t('PP tile: Under Offer banner, exclusive flag, floor and land sizes', () => {
  const l = MIC.ppTile(ppLink('<div class="listing-banner listing-banner--offer-pending">Under Offer</div><span>Only on Private Property</span>'
    + '<span title="Floor size">96 m²</span><span title="Land size">1 200 m²</span>'), { agency_name: 'Seeff' });
  assert.strictEqual(l.portal_status, 'under_offer');
  assert.strictEqual(l.is_exclusive, true);
  assert.strictEqual(l.property_size_m2, 96);
  assert.strictEqual(l.erf_size_m2, 1200);
  assert.strictEqual(l.advertiser_type, 'agency');
});

t('PP listing page: details rows + serverVariables subset, no phone', () => {
  const html = '<html><body><ul>'
    + '<li class="property-details__list-item"><span class="property-details__name-value">Listing date</span><span class="property-details__value">17 July 2026</span></li>'
    + '<li class="property-details__list-item"><span class="property-details__name-value">Floor size</span><span class="property-details__value">96 m²</span></li>'
    + '<li class="property-details__list-item"><span class="property-details__name-value">Land size</span><span class="property-details__value">1 200 m²</span></li>'
    + '<li class="property-details__list-item"><span class="property-details__name-value">Rates and taxes</span><span class="property-details__value">R 950</span></li>'
    + '<li class="property-details__list-item"><span class="property-details__name-value">Levies</span><span class="property-details__value">R 1 200</span></li>'
    + '</ul><a>Contact Agent</a></body></html>';
  const f = MIC.ppListingPage(doc(html), 'https://www.privateproperty.co.za/for-sale/x/T1234567', {
    hasAgency: true, agencyName: 'Seeff Margate', agencyId: 77, contactName: 'Jane Smith', agentId: 9, lat: -30.86, lng: 30.37,
    description: 'A very well presented family home close to the beach with a spacious garden and a double garage for the cars.' });
  assert.deepStrictEqual([f.listed_date, f.property_size_m2, f.erf_size_m2, f.rates_amount, f.levies_amount], ['2026-07-17', 96, 1200, 950, 1200]);
  assert.deepStrictEqual([f.advertiser_type, f.agency_name, f.agency_portal_id, f.agent_name, f.agent_portal_id], ['agency', 'Seeff Margate', '77', 'Jane Smith', '9']);
  assert.strictEqual(f.portal_latitude, -30.86);
  assert.match(f.wording_fingerprint, /^[0-9a-f]{40}$/);
  assert.ok(!('description' in f));
});

t('PP listing page for a private seller (no agencyInfo): owner', () => {
  const f = MIC.ppListingPage(doc('<html><body><a>Contact Seller</a></body></html>'), 'https://www.privateproperty.co.za/for-sale/x/T7654321', { hasAgency: false });
  assert.strictEqual(f.advertiser_type, 'owner');
  assert.strictEqual(f.agency_name, null);
});

console.log('P24 search page (a REAL saved results page, 21 tiles)');
t('real search page: owner, groups with members, mandate ids, reduced, badges, floor vs erf', () => {
  const html = fs.readFileSync(path.join(__dirname, 'fixtures', 'p24-search', 'margate-search-owner-groups-reduced.html'), 'utf8');
  const dom = new JSDOM(html, { url: 'https://www.property24.com/for-sale/margate/kwazulu-natal/6359', runScripts: 'outside-only' });
  const w = dom.window; let handler = null;
  w.chrome = { runtime: { onMessage: { addListener: (fn) => { handler = fn; } } } };
  w.eval(fs.readFileSync(path.join(EXT, 'mic-lib.js'), 'utf8'));
  w.eval(fs.readFileSync(path.join(EXT, 'content-p24-detail.js'), 'utf8'));
  let out; handler({ action: 'getListings' }, null, (r) => { out = r.listings; });
  const n = (fn) => out.filter(fn).length;
  assert.strictEqual(out.length, 21);
  assert.strictEqual(n((l) => l.advertiser_type === 'owner'), 1);
  assert.strictEqual(n((l) => l.advertiser_type === 'agency'), 20);
  assert.strictEqual(n((l) => l.agency_name === 'Sale'), 0, 'the owner tile is never an agency called Sale');
  assert.strictEqual(out.find((l) => l.advertiser_type === 'owner').agency_name, null);
  assert.strictEqual(n((l) => l.p24_mandate_id), 21);
  assert.strictEqual(n((l) => l.is_reduced === true), 2);
  const groups = out.filter((l) => l.p24_group_id);
  assert.strictEqual(groups.length, 2);
  groups.forEach((g) => { assert.ok(g.p24_group_listing_numbers.length >= 2); assert.ok(g.p24_group_listing_numbers.includes(g.portal_ref.replace('P24-', ''))); });
  assert.ok(n((l) => l.property_size_m2) >= 10 && n((l) => l.erf_size_m2) >= 5, 'floor and erf are separated');
  assert.ok(n((l) => l.badges && l.badges.length) >= 1);
});

console.log('P24 area pages (REAL saved pages, 31 Oct 2026)');
const AREA = path.join(__dirname, 'fixtures', 'p24-area');
const UPDATE = process.env.UPDATE_GOLDEN === '1';
const areaCases = [
  ['uvongo-property-trends-6359', 'https://www.property24.com/margate/uvongo/property-trends/6359#corex-area'],
  ['uvongo-estate-agencies-6359', 'https://www.property24.com/estate-agencies/uvongo/margate/kwazulu-natal/6359#corex-area'],
  ['uvongo-marine-drive-values', 'https://www.property24.com/property-values/marine-drive/uvongo/margate/kwazulu-natal/6359'],
];
const areaPayload = (name, url) => MIC.p24AreaPage(doc(fs.readFileSync(path.join(AREA, name + '.html'), 'utf8'), url), url);
areaCases.forEach(([name, url]) => t('golden payload ' + name, () => {
  const got = areaPayload(name, url);
  const file = path.join(AREA, name + '.payload.json');
  if (UPDATE || !fs.existsSync(file)) fs.writeFileSync(file, JSON.stringify(got, null, 2) + '\n');
  assert.deepStrictEqual(JSON.parse(JSON.stringify(got)), JSON.parse(fs.readFileSync(file, 'utf8')));
}));

t('area kind from the address', () => {
  assert.strictEqual(MIC.p24AreaKind('https://www.property24.com/margate/uvongo/property-trends/6359').kind, 'trends');
  assert.strictEqual(MIC.p24AreaKind('https://www.property24.com/estate-agencies/uvongo/margate/kwazulu-natal/6359/p3').page, 3);
  assert.strictEqual(MIC.p24AreaKind('https://www.property24.com/property-values/uvongo/margate/kwazulu-natal/6359').kind, 'street_index');
  assert.strictEqual(MIC.p24AreaKind('https://www.property24.com/property-values/marine-drive/uvongo/margate/kwazulu-natal/6359').kind, 'street');
  assert.strictEqual(MIC.p24AreaKind('https://www.property24.com/property-values/1214-alexander-avenue/uvongo/margate/kwazulu-natal/gsxdiolg3x6vbsvsq'), null, 'a single property page (paid) is not read');
  assert.strictEqual(MIC.p24AreaKind('https://www.property24.com/for-sale/uvongo/margate/kwazulu-natal/6359'), null);
});

t('area chain: one click reads trends, then agencies, then the street list', () => {
  assert.deepStrictEqual(MIC.p24AreaHash('#corex-area'), { on: true, all: false, province: null });
  assert.deepStrictEqual(MIC.p24AreaHash('#corex-area=all&province=kwazulu-natal'), { on: true, all: true, province: 'kwazulu-natal' });
  assert.strictEqual(MIC.p24AreaHash('#something').on, false);
  assert.strictEqual(MIC.p24AreaHash('#corex-area=all&province=<script>').all, false, 'a hostile province is refused');
  const t1 = 'https://www.property24.com/margate/uvongo/property-trends/6359#corex-area=all&province=kwazulu-natal';
  const a = MIC.p24AreaChainNext(t1, 'kwazulu-natal');
  assert.strictEqual(a, 'https://www.property24.com/estate-agencies/uvongo/margate/kwazulu-natal/6359#corex-area=all&province=kwazulu-natal');
  assert.strictEqual(MIC.p24AreaChainNext(a, 'kwazulu-natal'), 'https://www.property24.com/property-values/uvongo/margate/kwazulu-natal/6359#corex-area=all&province=kwazulu-natal');
  assert.strictEqual(MIC.p24AreaChainNext('https://www.property24.com/property-values/uvongo/margate/kwazulu-natal/6359', 'kwazulu-natal'), null, 'the street list is the last stop');
  assert.strictEqual(MIC.p24AreaChainNext(t1, null), null);
});

t('the Read-area-pages addresses MIC builds are the ones the extension reads, in the order it chains them', () => {
  const trends = 'https://www.property24.com/margate/uvongo/property-trends/6359#corex-area=all&province=kwazulu-natal';
  const agencies = 'https://www.property24.com/estate-agencies/uvongo/margate/kwazulu-natal/6359#corex-area=all&province=kwazulu-natal';
  const streets = 'https://www.property24.com/property-values/uvongo/margate/kwazulu-natal/6359#corex-area=all&province=kwazulu-natal';
  assert.deepStrictEqual([trends, agencies, streets].map((u) => MIC.p24AreaKind(u).kind), ['trends', 'agencies', 'street_index']);
  assert.strictEqual(MIC.p24AreaChainNext(trends, 'kwazulu-natal'), agencies);
  assert.strictEqual(MIC.p24AreaChainNext(agencies, 'kwazulu-natal'), streets);
  // the search links open pages the tile reader knows: P24 sorted newest first, PP newest first
  assert.strictEqual(new URL('https://www.property24.com/for-sale/uvongo/margate/kwazulu-natal/6359?sp=so%3dNewest').searchParams.get('sp'), 'so=Newest');
  assert.strictEqual(MIC.cleanPpSearchUrl('https://www.privateproperty.co.za/for-sale/advanced-search?si=6359&sorttype=Date&sortorder=Descending').changed, false, 'the newest-first PP link keeps its sort');
});

t('trends: every chart table and the bedroom table are read', () => {
  const p = areaPayload('uvongo-property-trends-6359', areaCases[0][1]);
  assert.strictEqual(p.p24_suburb_id, 6359);
  assert.strictEqual(p.suburb, 'Uvongo');
  const d = p.trends.datasets;
  assert.strictEqual(d.annual.rows.length, 10);
  assert.strictEqual(d.stock.rows.length, 12);
  assert.strictEqual(d.age.rows.length, 3);
  assert.match(p.trends.sold_labels.popular1, /Erven/i);
  assert.match(p.trends.sold_labels.popular2, /Sectional/i);
  assert.strictEqual(p.trends.stock_by_beds.months.length, 6);
  assert.ok(p.trends.stock_by_beds.rows.some((r) => r.beds === '3 bed') && p.trends.stock_by_beds.rows.some((r) => /^all$/i.test(r.beds)));
});

t('agencies: id, name and the two listing counts per tile', () => {
  const p = areaPayload('uvongo-estate-agencies-6359', areaCases[1][1]);
  assert.strictEqual(p.agencies.length, 20);
  assert.deepStrictEqual(p.agencies[0], { agency_portal_id: '32381', agency_name: 'PdH Properties', listings_in_suburb: 78, total_listings: 461 });
  assert.ok(p.pages_total >= 2, 'the pager links to further pages');
});

t('street index: every street of the suburb, no property (paid) pages', () => {
  const url = 'https://www.property24.com/property-values/uvongo/margate/kwazulu-natal/6359';
  const p = MIC.p24AreaPage(doc(fs.readFileSync(path.join(AREA, 'uvongo-property-values-6359.html'), 'utf8'), url), url);
  assert.strictEqual(p.kind, 'street_index');
  assert.strictEqual(p.streets.length, 53);
  assert.ok(p.streets.every((s) => /^\/property-values\/[a-z0-9-]+\/uvongo\/margate\/kwazulu-natal\/6359$/.test(s.path)));
});

t('street page: house numbers only - no price anywhere in the payload', () => {
  const p = areaPayload('uvongo-marine-drive-values', areaCases[2][1]);
  const a = p.streets[0].addresses;
  assert.strictEqual(p.streets[0].street_slug, 'marine-drive');
  assert.ok(a.length >= 6);
  assert.deepStrictEqual(a.slice(0, 2).map((x) => x.house_number), ['96', '98']);
  assert.ok(!/\bR\s?\d/.test(JSON.stringify(p)), 'no rand amount');
});

console.log('content scripts (wiring)');
function loadContent(file, html, url) {
  const dom = new JSDOM(html, { url, runScripts: 'outside-only' });
  const w = dom.window;
  let handler = null;
  w.chrome = { runtime: { onMessage: { addListener: (fn) => { handler = fn; } } } };
  w.eval(fs.readFileSync(path.join(EXT, 'mic-lib.js'), 'utf8'));
  w.eval(fs.readFileSync(path.join(EXT, file), 'utf8'));
  return (msg) => new Promise((resolve) => handler(msg, null, resolve));
}

t('P24 content script sends the MIC keys on getListings', () => {
  const tiles = [1, 2, 3].map((i) => '<div data-listing-number="11692291' + i + '"><a class="p24_content" href="/for-sale/margate/margate/kwazulu-natal/6359/11692291' + i + '"></a>'
    + '<span class="p24_price" content="1500000">R 1 500 000</span><span class="p24_location">Margate</span>'
    + (i === 1 ? '<div class="p24_branding"><img src="https://x/listed_by_owner.png" alt="Sale"></div>' : '<div class="p24_branding"><img src="a.png" alt="Pam Golding"></div>')
    + (i === 2 ? '<span class="p24_reducedBanner">Reduced</span>' : '') + '</div>').join('');
  const send = loadContent('content-p24-detail.js', '<html><body>' + tiles + '</body></html>', 'https://www.property24.com/for-sale/margate/kwazulu-natal/6359');
  return send({ action: 'getListings' }).then((res) => {
    const by = Object.fromEntries(res.listings.map((l) => [l.portal_ref, l]));
    assert.strictEqual(by['P24-116922911'].advertiser_type, 'owner');
    assert.strictEqual(by['P24-116922911'].agency_name, null);
    assert.strictEqual(by['P24-116922912'].is_reduced, true);
    assert.strictEqual(by['P24-116922913'].advertiser_type, 'agency');
    assert.strictEqual(by['P24-116922913'].agency_name, 'Pam Golding');
  });
});

t('PP content script sends status/owner/exclusive on getListings and getListingFacts answers', () => {
  const link = (ref, inner) => '<a href="/for-sale/kwazulu-natal/margate/margate/' + ref + '">' + inner + ' 3 Bedroom House R 1 500 000</a>';
  const send = loadContent('content-pp.js', '<html><body>'
    + link('T1000001', '<span class="listing-result__listed-privately">LISTED PRIVATELY</span>')
    + link('T1000002', '<div class="listing-banner--offer-pending">Under Offer</div>') + '</body></html>', 'https://www.privateproperty.co.za/for-sale/kwazulu-natal/margate');
  return send({ action: 'getListings' }).then((res) => {
    const by = Object.fromEntries(res.listings.map((l) => [l.portal_ref, l]));
    assert.strictEqual(by['PP-T1000001'].advertiser_type, 'owner');
    assert.strictEqual(by['PP-T1000002'].portal_status, 'under_offer');
    return send({ action: 'getListingFacts', sv: { hasAgency: false } });
  }).then((r) => { assert.strictEqual(r.facts.advertiser_type, 'owner'); });
});

t('area trigger: #corex-area on a trends page sends it to the background worker; a plain visit sends nothing', () => {
  const html = fs.readFileSync(path.join(AREA, 'uvongo-property-trends-6359.html'), 'utf8');
  const run = (url) => {
    const dom = new JSDOM(html, { url, runScripts: 'outside-only' });
    const w = dom.window; const sent = [];
    w.chrome = { runtime: { onMessage: { addListener: () => {} }, sendMessage: (m, cb) => { sent.push(m); cb && cb({ ok: true, summary: '123 figures saved' }); } } };
    w.eval(fs.readFileSync(path.join(EXT, 'mic-lib.js'), 'utf8'));
    w.eval(fs.readFileSync(path.join(EXT, 'content-p24-detail.js'), 'utf8'));
    return new Promise((resolve) => setTimeout(() => resolve({ sent, banner: (w.document.getElementById('corex-area-banner') || {}).textContent }), 1500));
  };
  return Promise.all([run('https://www.property24.com/margate/uvongo/property-trends/6359#corex-area'), run('https://www.property24.com/margate/uvongo/property-trends/6359')]).then(([withHash, plain]) => {
    assert.strictEqual(withHash.sent.length, 1);
    assert.strictEqual(withHash.sent[0].action, 'captureAreaPage');
    assert.strictEqual(withHash.sent[0].payload.kind, 'trends');
    assert.strictEqual(withHash.sent[0].next, null, 'a plain #corex-area reads this page only');
    assert.match(withHash.banner, /saved/);
    assert.strictEqual(plain.sent.length, 0, 'ordinary browsing sends nothing');
    assert.strictEqual(plain.banner, undefined);
  });
});

setTimeout(() => console.log('\n' + passed + ' passed' + (process.exitCode ? ' (with failures)' : '')), 50);
