/**
 * CoreX — Property24 "Import as Other Agency Stock" extractor regression harness.
 *
 * Runs the EXACT p24ExtractOasFn shipped in popup.js (sliced out between the
 * <<P24_OAS_EXTRACT_BEGIN>> / <<P24_OAS_EXTRACT_END>> markers) against SAVED
 * Property24 pages in tests/fixtures/p24/ — no live scraping, ever. The saved
 * pages are trimmed copies of six real public listings (a house, an apartment,
 * a townhouse, vacant land, a rental, a commercial unit) fetched on 2026-10-07.
 *
 * Each page also has a golden payload (<name>.payload.json) — what the
 * extractor must send to POST /api/v1/other-agency-stock/import. The PHP
 * feature test tests/Feature/Properties/OtherAgencyStockP24FixtureImportTest.php
 * feeds those same golden payloads through the real import endpoint, so the
 * chain "saved page -> payload -> stored property" is covered end to end
 * without a browser.
 *
 * Needs jsdom (a real HTML parser — the repo ships none). It is NOT a project
 * dependency; install it anywhere and point at it:
 *     NODE_PATH=/mnt/HC_Volume_103099143/tools/jsdom-test/node_modules \
 *       node public/chrome-extension/portal-capture/tests/p24-oas-extract.test.cjs
 * Refresh the golden payloads after an intended extractor change with
 *     UPDATE_GOLDEN=1 node ...   (then read the git diff before committing).
 * Without jsdom the harness says so and exits 0 — the PHP golden test still runs.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const assert = require('assert');

let JSDOM;
try {
  ({ JSDOM } = require('jsdom'));
} catch (e) {
  console.log('SKIPPED: jsdom is not installed (see the header of this file). PHP golden-payload test still covers the server half.');
  process.exit(0);
}

const POPUP = path.join(__dirname, '..', 'popup.js');
const FIXTURES = path.join(__dirname, 'fixtures', 'p24');
const UPDATE = process.env.UPDATE_GOLDEN === '1';

function loadExtractorSource() {
  const src = fs.readFileSync(POPUP, 'utf8');
  const a = src.indexOf('// <<P24_OAS_EXTRACT_BEGIN>>');
  const b = src.indexOf('// <<P24_OAS_EXTRACT_END>>');
  assert(a !== -1 && b > a, 'P24 extractor markers not found in popup.js');
  return src.slice(a, b);
}
const EXTRACTOR_SRC = loadExtractorSource();

function run(html, url) {
  const dom = new JSDOM(html, { url, runScripts: 'outside-only' });
  // Same as chrome.scripting.executeScript({func}): the function is serialised
  // and run in the page — here, evaluated inside the jsdom window.
  const result = dom.window.eval('(function(){' + EXTRACTOR_SRC + '\nreturn p24ExtractOasFn();})()');
  // Plain Node objects (the page's own Array/Object are another realm) — and it is
  // exactly what chrome.scripting hands back: a JSON-serialised copy.
  return JSON.parse(JSON.stringify(result));
}

function page(file, url) {
  return run(fs.readFileSync(path.join(FIXTURES, file), 'utf8'), url);
}

// A tiny synthetic overview table for the number-format cases (units, thousands
// separators, decimals) — the shapes the six real pages do not all happen to cover.
function synthetic(rows, keyFeatures) {
  const r = rows.map(([k, v]) => `<div class="row p24_propertyOverviewRow"><div class="col-6 p24_propertyOverviewKey">${k}</div><div class="col-6 p24_propertyOverviewResult"><div class="p24_info">${v}</div></div></div>`).join('');
  const kf = (keyFeatures || []).map(([k, v]) => `<div class="p24_listingFeatures"><span class="p24_feature">${k}:</span><span class="p24_featureAmount">${v}</span></div>`).join('');
  return run(`<html><head><title>x - P24-111111111</title></head><body><h1>t</h1>${kf}${r}</body></html>`,
    'https://www.property24.com/for-sale/somewhere/town/province/123/111111111');
}

const CASES = [
  {
    name: 'townhouse-uvongo-117580701',
    url: 'https://www.property24.com/for-sale/uvongo/margate/kwazulu-natal/6359/117580701',
    // The listing Johan re-imports (QA1 property 21181): the stored title was P24's generic line, and
    // "Furnished | Yes" (a Property Overview row) was never ticked.
    expect: {
      listing_ref: '117580701', listing_title: 'Beautifully situated Coastal Property with sea views', _title: 'Beautifully situated Coastal Property with sea views',
      beds: 3, baths: 2.5, garages: 1, size_m2: 169, erf_size_m2: 6294, pets_allowed: false, pool: true, garden: true,
    },
    imageIdsHead: null, imageIdsTail: null,
  },
  {
    name: 'house-umhlali-golf-estate-117621889',
    url: 'https://www.property24.com/for-sale/umhlali-golf-estate/ballito/kwazulu-natal/15100/117621889',
    // The listing Johan imported on QA1 on 2026-10-07 — the four wrong fields.
    expect: {
      listing_ref: '117621889', listing_type: 'sale', price: 4999000, beds: 3, baths: 2.5, garages: 2,
      size_m2: 287, erf_size_m2: 664, street_address: '42 Springwood', suburb: 'Umhlali Golf Estate',
      latitude: -29.51193, longitude: 31.192514, levy: 'R 1 590', rates_taxes: 'R 3 700',
      date_posted: '14 September 2026', pets_allowed: true, p24_suburb_external_id: 15100,
      source_agency_name: 'Local Real Estate', source_agent_name: 'Rory Anderson', image_count: 27,
      // This advert has no heading of its own: P24 prints its generic line in the heading slot, so the title stays the generic line.
      listing_title: '3 Bedroom House for sale in Umhlali Golf Estate',
    },
    imageIdsHead: [386597648, 386597651, 386597652], imageIdsTail: [386597674, 386597675, 386597676],
  },
  {
    name: 'apartment-shakas-rock-117608849',
    url: 'https://www.property24.com/for-sale/shakas-rock/ballito/kwazulu-natal/7654/117608849',
    // "Floor | Tiled Floors" used to wipe the floor size to nothing; garage 1 AND parking 1 are separate.
    expect: { price: 3795000, beds: 3, baths: 2, garages: 1, size_m2: 154, erf_size_m2: null, parking_count: 1, pool: true, street_address: null, image_count: 24, listing_title: 'Spacious coastal living with elevated ocean views' },
    imageIdsHead: [386350291, 386350292], imageIdsTail: [386350312, 386321393, 386321392],
  },
  {
    name: 'townhouse-elaleni-117675379',
    url: 'https://www.property24.com/for-sale/elaleni-coastal-forest-estate/ballito/kwazulu-natal/25221/117675379',
    // JSON-LD says @type Apartment; the label hint keeps it a Townhouse. "Pool | Pool" is a yes.
    expect: { beds: 3, baths: 2, garages: 1, parking_count: 2, size_m2: 152, street_address: '36 The Woods', pool: true, garden: true, property_type_label_hint: 'Townhouse', image_count: 25, listing_title: 'Your 3-Bedroom Garden Home in the Heart of Elaleni' },
    imageIdsHead: [387575968, 387575969], imageIdsTail: [387575987, 387575988, 387639984],
  },
  {
    name: 'vacant-land-lalela-117674295',
    url: 'https://www.property24.com/for-sale/lalela-estate/ballito/kwazulu-natal/33617/117674295',
    // Land: an erf size and nothing a house has — no floor size, never a stray number.
    expect: { price: 895000, beds: null, baths: null, garages: null, size_m2: null, erf_size_m2: 593, street_address: '1750 Argus Drive', latitude: -29.457987, longitude: 31.23582, image_count: 14, listing_title: 'Make Your Move to Lalela' },
    imageIdsHead: [387082312, 387082313, 387020605], imageIdsTail: [387011109, 387554779, 387272776],
  },
  {
    name: 'rental-apartment-ballito-116824433',
    url: 'https://www.property24.com/to-rent/ballito-central/ballito/kwazulu-natal/17817/116824433',
    // "Floor Number | 3", "Floor | Tiled Floors" and "Number of floors | 1" all sit after "Floor Size | 45".
    expect: { listing_type: 'rental', price: 7500, beds: 1, baths: 1, garages: null, size_m2: 45, parking_count: 1, street_address: '4339 Lee Barnes', date_posted: null, image_count: 24, listing_title: '1 Bedroom Apartment / flat to rent in Ballito Central' },
    imageIdsHead: [372152743, 387161920], imageIdsTail: [372152789, 372152791, 372152793],
  },
  {
    name: 'commercial-ballito-central-117324987',
    url: 'https://www.property24.com/for-sale/ballito-central/ballito/kwazulu-natal/17817/117324987',
    expect: { size_m2: 74, baths: 1, parking_count: 1, image_count: 17, listing_title: 'Conveniently situated prime commercial space in a popular and sought after boutique business park behind Lifestyle and within the business hub' },
    imageIdsHead: [381406652, 381406655], imageIdsTail: [381406666, 381406667, 381406668],
  },
];

let failed = 0;
function check(label, fn) {
  try { fn(); console.log('  ok   ' + label); } catch (e) { failed++; console.log('  FAIL ' + label + '\n       ' + String(e.message).split('\n').join('\n       ')); }
}

for (const c of CASES) {
  console.log(c.name);
  const out = page(c.name + '.html', c.url);

  for (const [k, v] of Object.entries(c.expect)) {
    check(k + ' = ' + JSON.stringify(v), () => assert.deepStrictEqual(out[k], v));
  }
  if (c.imageIdsHead) check('image_ids: P24\'s own order, ' + c.expect.image_count + ' unique ids, head/tail as on the page', () => {
    assert.strictEqual(out.image_ids.length, c.expect.image_count);
    assert.strictEqual(new Set(out.image_ids).size, out.image_ids.length);
    assert.deepStrictEqual(out.image_ids.slice(0, c.imageIdsHead.length), c.imageIdsHead);
    assert.deepStrictEqual(out.image_ids.slice(-c.imageIdsTail.length), c.imageIdsTail);
    assert.strictEqual(out.first_image_id, c.imageIdsHead[0]);
    assert.strictEqual(out.image_count, out.image_ids.length);
  });

  const goldenPath = path.join(FIXTURES, c.name + '.payload.json');
  if (UPDATE) {
    fs.writeFileSync(goldenPath, JSON.stringify(out, null, 2) + '\n');
    console.log('  wrote ' + path.basename(goldenPath));
  } else {
    check('whole payload equals the golden payload', () => assert.deepStrictEqual(JSON.parse(JSON.stringify(out)), JSON.parse(fs.readFileSync(goldenPath, 'utf8'))));
  }
}

console.log('features (rows + tags sent raw for the server to map)');
check('reference listing: every accordion row travels with its section, and the icon-strip tags', () => {
  const out = page('townhouse-uvongo-117580701.html', 'https://www.property24.com/for-sale/uvongo/margate/kwazulu-natal/6359/117580701');
  const row = (k) => out.feature_rows.find((r) => r.k === k);
  assert.deepStrictEqual(row('Furnished'), { s: 'Property Overview', k: 'Furnished', v: ['Yes'] });
  assert.deepStrictEqual(row('Pets Allowed'), { s: 'Property Overview', k: 'Pets Allowed', v: ['No'] });
  assert.deepStrictEqual(row('Reception Rooms'), { s: 'Rooms', k: 'Reception Rooms', v: ['2'] });
  assert.deepStrictEqual(row('Backup Water'), { s: 'Building', k: 'Backup Water', v: ['Water Tank'] });
  assert.deepStrictEqual(row('Garden'), { s: 'External Features', k: 'Garden', v: ['Yes'] });
  assert.deepStrictEqual(out.strip_tags, ['No Pets Allowed', 'Furnished', 'Pool', 'Garden', 'Water Tank']);
  assert.ok(!out.feature_rows.some((r) => /points of interest/i.test(r.s)), 'Points of Interest is loaded on demand — not part of the advert');
});
check('a multi-line Security block keeps its line breaks (server splits on them)', () => {
  const out = run('<html><head><title>x - P24-111111111</title></head><body><h1>t</h1><div class="panel"><div class="panel-heading"><span>Other Features</span></div><div class="row p24_propertyOverviewRow"><div class="p24_propertyOverviewKey">Security</div><div class="p24_propertyOverviewResult"><div class="p24_info">Electric Gate\nSecurity Gate</div></div></div></div></body></html>',
    'https://www.property24.com/for-sale/a/b/c/1/111111111');
  assert.deepStrictEqual(out.feature_rows, [{ s: 'Other Features', k: 'Security', v: ['Electric Gate\nSecurity Gate'] }]);
});

console.log('advert heading vs the generic line');
const withHeading = (h) => run('<html><head><title>x - P24-111111111</title></head><body><h1>3 Bedroom House for Sale in Uvongo</h1><section class="p24_listingAbout"><h5>' + h + '</h5><div class="p24_descriptionContainer">d</div></section></body></html>',
  'https://www.property24.com/for-sale/a/b/c/1/111111111').listing_title;
check('a real heading wins, even one that says "for sale in" (it does not end in a province)', () => {
  assert.strictEqual(withHeading('Immaculate family home for sale in Uvongo, a stroll from the beach'), 'Immaculate family home for sale in Uvongo, a stroll from the beach');
});
check('P24\'s own generic heading (type + for sale/to rent in place, ending in a province) is no heading', () => {
  assert.strictEqual(withHeading('House For Sale in Umhlali Golf Estate Ballito KwaZulu Natal'), '3 Bedroom House for Sale in Uvongo');
  assert.strictEqual(withHeading('Apartment To Rent in Ballito Central, Ballito, KwaZulu Natal'), '3 Bedroom House for Sale in Uvongo');
  assert.strictEqual(withHeading('Flat For Sale in Rosebank Johannesburg Gauteng'), '3 Bedroom House for Sale in Uvongo');
});
check('no heading element at all -> the generic line (unchanged behaviour)', () => {
  const out = run('<html><head><title>x - P24-111111111</title></head><body><h1>3 Bedroom House for Sale in Uvongo</h1></body></html>', 'https://www.property24.com/for-sale/a/b/c/1/111111111');
  assert.strictEqual(out.listing_title, '3 Bedroom House for Sale in Uvongo');
});

console.log('number formats (synthetic overview rows)');
check('bathrooms "2.5" keeps its decimal; "2,5" too', () => {
  assert.strictEqual(synthetic([['Bathrooms', '2.5']]).baths, 2.5);
  assert.strictEqual(synthetic([['Bathrooms', '2,5']]).baths, 2.5);
  assert.strictEqual(synthetic([['Bathrooms', '3']]).baths, 3);
});
check('sizes: "1 375 m²" / "1,375 m²" / "1 375.5 m²" / "1.2 ha"', () => {
  assert.strictEqual(synthetic([['Floor Size', '1 375 m²']]).size_m2, 1375);
  assert.strictEqual(synthetic([['Floor Size', '1,375 m²']]).size_m2, 1375);
  assert.strictEqual(synthetic([['Floor Size', '1 375.5 m²']]).size_m2, 1375.5);
  assert.strictEqual(synthetic([['Erf Size', '1.2 ha']]).erf_size_m2, 12000);
  assert.strictEqual(synthetic([['Erf Size', '664 m²']]).erf_size_m2, 664);
});
check('floor size only from the "Floor Size" row — "Floor", "Floor Number", "Number of floors" never count', () => {
  assert.strictEqual(synthetic([['Floor Number', '3'], ['Floor', 'Tiled Floors'], ['Number of floors', '1']]).size_m2, null);
  assert.strictEqual(synthetic([['Floor Size', '287 m²'], ['Floor Number', '3'], ['Floor', 'Tiled Floors'], ['Number of floors', '1']]).size_m2, 287);
});
check('garage / parking / covered parking stay separate', () => {
  const a = synthetic([['Garage', '2']]);
  assert.strictEqual(a.garages, 2); assert.strictEqual(a.parking_count, null);
  const b = synthetic([['Garage', '1'], ['Parking', '2']]);
  assert.strictEqual(b.garages, 1); assert.strictEqual(b.parking_count, 2);
  const c2 = synthetic([['Parking', '2'], ['Covered Parking', '1']]);
  assert.strictEqual(c2.garages, null); assert.strictEqual(c2.parking_count, 3);
  const d = synthetic([], [['Garages', '2'], ['Parking', '1']]);
  assert.strictEqual(d.garages, 2); assert.strictEqual(d.parking_count, 1);
});
check('prices: "4999000", "4999000.00", "R 4 999 000" all read as 4999000', () => {
  const mk = (p) => run('<html><head><title>P24-111111111</title><script type="application/ld&#x2B;json">' + JSON.stringify({ '@graph': [{ '@type': 'RealEstateListing', offers: { priceSpecification: { price: p } } }] }) + '</script></head><body></body></html>', 'https://www.property24.com/for-sale/a/b/c/1/111111111').price;
  assert.strictEqual(mk('4999000'), 4999000);
  assert.strictEqual(mk('4999000.00'), 4999000);
  assert.strictEqual(mk('R 4 999 000'), 4999000);
});

console.log(failed ? '\n' + failed + ' FAILED' : '\nall passed');
process.exit(failed ? 1 : 0);
