/**
 * CoreX — deeds-capture "true clean slate" regression harness (v3.4.1 -> v3.4.4).
 *
 * Test A/B/C/D — repro for Johan's ORIGINAL bug: capturing a SECTIONAL
 * (complex) property then a FREEHOLD still showed the freehold with the
 * previous complex's scheme/section, because cmainfo never re-renders the
 * sectional-only fields (Scheme name/no, Section number, Flat/Unit no,
 * Section extent, Situated at) when the current property is a freehold —
 * they sit frozen in the live DOM. v3.4.0's fix (clear sectional fields when
 * byte-identical to the PREVIOUS capture) still misses the case where there
 * is no previous capture in THIS script instance to diff against — see
 * content-cmainfo.js's own v3.4.0 comment: "the very FIRST capture in a
 * page session has no previous capture to diff against ... isn't caught."
 * That is exactly what Test A reproduces. Fixed in v3.4.2.
 *
 * Test E — repro for the v3.4.2 REGRESSION Johan reported next: capturing
 * two DIFFERENT properties in sequence silently dropped the 2nd. v3.4.2's
 * clean-slate rewrite over-applied "no memory of the previous scrape" to
 * lastCaptureCompletedAt — a TIMESTAMP, not an extracted value — which
 * broke domIsSettled()'s ability to tell "quiet because the postback
 * finished" from "quiet because the postback for THIS property hasn't
 * started yet". A capture requested soon after selecting a new property
 * could read the PREVIOUS property's still-frozen Sale Information
 * (including its title_deed, which source_ref is built from), causing the
 * server to exact-match and silently enrich the previous capture's
 * TrackedProperty instead of creating a new, distinctly-visible one. Fixed
 * in v3.4.3 by restoring lastCaptureCompletedAt (timing memory — safe) while
 * keeping the address-bleed fix's removal of VALUE memory intact.
 *
 * Test F — repro for Johan's live 3.4.3 STILL-bleeds-address report (62 Bairn
 * Street captured with Address = "20 Lilliecrona Drive", the PREVIOUS
 * capture's address, while Erf no/Title Deed/Sale Price/Owner all read
 * correctly). Distinct from Test E: here the rest of the panel — AND the
 * entirely separate Sale Information section — updates PROMPTLY; only the
 * Address cell's own DOM node lands on a later, separate mutation. domIsSettled()/
 * sectionHasPopulatedValues() only prove SOME row changed and things went
 * quiet — never that Address's OWN cell specifically finished updating — so
 * the panel can look "settled" while Address is still one mutation away.
 * Fixed in v3.4.4 by waitForAddressStable(): poll the Address cell for two
 * consecutive identical reads (mirrors revealOwnerIdIfNeeded()'s own
 * poll-until-true shape) before trusting it, instead of trusting the
 * whole-panel settle check alone.
 *
 * Test G — repro for Johan's live 3.4.3 owner-name-mangle report
 * ("SMIT & WESSELS TRUST-TRUSTEES" stored as "& Wessels Trust-trustees
 * Smit"). parsePersonName() unconditionally treated every owner string as a
 * surname-first NATURAL PERSON name and reordered/title-cased it — with no
 * detection for a juristic entity (trust/company/CC/joint "A & B") name,
 * which has no surname/first-name split to begin with. Fixed in v3.4.4 by
 * looksLikeEntityName(): detect TRUST/TRUSTEE(S)/CC/PTY/LTD/BK/&/etc FIRST
 * and skip the reorder entirely, keeping the raw string verbatim.
 *
 * Runs the REAL content-cmainfo.js source (unmodified — no test-only hooks)
 * inside a Node `vm` sandbox with a minimal mock DOM + chrome API, driving
 * extraction through the exact same chrome.runtime.onMessage('getDeedDetail')
 * entry point background.js/popup.js use in production (Test G instead drives
 * the real on-page Capture button + a chrome.runtime.sendMessage capture hook,
 * since owner-name parsing only happens in buildDeedsCapturePayload(), which
 * onCaptureClick() calls — not the getDeedDetail message handler). Test E/F
 * additionally drive the sandbox's real MutationObserver callback
 * (fireMutation()) on a delayed timer to simulate cmainfo's async postback
 * landing AFTER the capture is requested — the actual race that causes the
 * regression/bleed.
 *
 * Usage: node tests/deeds-cleanslate.test.cjs
 * Exits 0 if every check passes (old/regression fixtures reproduce their
 * bug, current working-tree file is fixed and has no regressions), 1
 * otherwise.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { setFieldValue, buildCmaInfoDocument } = require('./mock-dom.cjs');

const OLD_FILE = path.join(__dirname, 'fixtures', 'content-cmainfo.v3.4.1.js');
const REGRESSION_FILE = path.join(__dirname, 'fixtures', 'content-cmainfo.v3.4.2.js');
const OLD_343_FILE = path.join(__dirname, 'fixtures', 'content-cmainfo.v3.4.3.js');
const NEW_FILE = path.join(__dirname, '..', 'content-cmainfo.js');

function makeChromeMock() {
  let listener = null;
  return {
    runtime: {
      onMessage: { addListener: (fn) => { listener = fn; } },
      sendMessage: () => Promise.resolve({}),
    },
    _getListener: () => listener,
  };
}

/**
 * Same shape as makeChromeMock(), but also records whatever payload
 * onCaptureClick() sends via chrome.runtime.sendMessage({action:'captureDeed',
 * payload}) — needed for Test G, since owner-name parsing (buildOwnersArray/
 * parsePersonName) only runs inside buildDeedsCapturePayload(), which is
 * reached from the on-page button click, not from the getDeedDetail message
 * handler the other tests drive.
 */
function makeChromeMockWithCapture() {
  let listener = null;
  let resolveCaptured;
  const captured = new Promise((resolve) => { resolveCaptured = resolve; });
  return {
    runtime: {
      onMessage: { addListener: (fn) => { listener = fn; } },
      sendMessage: (msg) => {
        if (msg && msg.action === 'captureDeed') resolveCaptured(msg.payload);
        return Promise.resolve({ results: [{ created: true }] });
      },
    },
    _getListener: () => listener,
    _captured: captured,
  };
}

/**
 * Loads the real content script source into a fresh sandboxed vm context
 * (fresh module-level state every call) bound to the given mock document/
 * chrome. Returns { sandbox, fireMutation } — fireMutation() invokes the
 * content script's OWN MutationObserver callback (markDomActivity), the
 * same call a real DOM mutation would trigger, so tests can simulate cmainfo
 * genuinely updating the panel at a controlled point in time instead of the
 * mock DOM's synchronous field writes being (wrongly) treated as "the page
 * was already quiet".
 */
function loadContentScript(filePath, doc, chromeMock) {
  const src = fs.readFileSync(filePath, 'utf8');
  let mutationCallback = null;
  const sandbox = {
    document: doc,
    chrome: chromeMock,
    console,
    setTimeout,
    clearTimeout,
    Promise,
    Date,
    Object,
    Array,
    String,
    Number,
    Math,
    JSON,
    Set,
    RegExp,
    MutationObserver: class { constructor(cb) { mutationCallback = cb; } observe() {} disconnect() {} },
    getComputedStyle: (elm) => ({ display: (elm && elm.style && elm.style.display) || '', color: (elm && elm._color) || 'rgb(51, 122, 183)' }),
    MouseEvent: class { constructor(type, opts) { this.type = type; Object.assign(this, opts || {}); } },
  };
  sandbox.window = { location: { href: 'https://www.cmainfo.co.za/Mapping/PropSearch.aspx' }, alert: () => {}, document: doc };
  vm.createContext(sandbox);
  vm.runInContext(src, sandbox, { filename: filePath });
  return { sandbox, fireMutation: () => { if (mutationCallback) mutationCallback([]); } };
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function captureViaMessageHandler(chromeMock) {
  const listener = chromeMock._getListener();
  if (!listener) throw new Error('content script never registered a chrome.runtime.onMessage listener');
  return new Promise((resolve, reject) => {
    listener({ action: 'getDeedDetail' }, {}, (response) => {
      if (response && response.error) reject(new Error(response.error));
      else resolve(response.deed);
    });
  });
}

// ── Fixture field sets ──────────────────────────────────────────────────

const SKIPPERS_PROPERTY_FIELDS = [
  ['Deeds Office', 'Port Shepstone'],
  ['Scheme no', '123/2005'],
  ['Scheme name', 'SKIPPERS OF SHELLY'],
  ['Situated at', 'Section 3 Skippers Of Shelly Shelly Beach'],
  ['Section number', '3'],
  ['Flat/Unit no', '3'],
  ['Street number', ''],
  ['Estate', ''],
  ['Address', ''],
  ['Erf no', ''],
  ['Suburb', 'Shelly Beach'],
  ['Municipality', 'Hibiscus Coast'],
  ['Province', 'KwaZulu-Natal'],
  ['GPS', '-30.79, 30.39'],
  ['Section extent', '72'],
  ['Type', 'Sectional Title'],
  ['Usage', 'Residential'],
];

const SKIPPERS_SALE_FIELDS = [
  ['Owner', 'SMITH JOHN'],
  ["Owner's ID", '8001015800089'],
  ['Sale Price', 'R 850 000'],
  ['Sale Date', '01/03/2020'],
  ['Registered Date', '15/03/2020'],
  ['Title Deed', 'ST1234/2020'],
  ['Bond Holder', ''],
  ['Bond Amount', ''],
  ['Sale Type', 'Normal Sale'],
];

// Park Street, freehold — Type + Erf/Address/Suburb are genuinely CURRENT
// (this property), but the sectional-only fields are the exact byte-for-byte
// values Skippers had — reproducing cmainfo's confirmed never-re-rendered-
// for-a-freehold rows.
const PARK_ST_PROPERTY_FIELDS_FROZEN = [
  ['Deeds Office', 'Port Shepstone'],
  ['Scheme no', '123/2005'],                                        // FROZEN (Skippers)
  ['Scheme name', 'SKIPPERS OF SHELLY'],                             // FROZEN (Skippers)
  ['Situated at', 'Section 3 Skippers Of Shelly Shelly Beach'],       // FROZEN (Skippers)
  ['Section number', '3'],                                           // FROZEN (Skippers)
  ['Flat/Unit no', '3'],                                             // FROZEN (Skippers)
  ['Street number', '12'],
  ['Estate', ''],
  ['Address', '12 Park Street'],                                     // fresh, current
  ['Erf no', '4521'],                                                // fresh, current
  ['Suburb', 'Margate'],                                             // fresh, current
  ['Municipality', 'Hibiscus Coast'],
  ['Province', 'KwaZulu-Natal'],
  ['GPS', '-30.85, 30.35'],
  ['Section extent', '72'],                                          // FROZEN (Skippers)
  ['Type', 'Freehold'],                                              // fresh, current — the key signal
  ['Usage', 'Residential'],
];

const PARK_ST_SALE_FIELDS = [
  ['Owner', 'JONES MARY'],
  ["Owner's ID", '7505125800088'],
  ['Sale Price', 'R 1 250 000'],
  ['Sale Date', '10/06/2021'],
  ['Registered Date', '20/06/2021'],
  ['Title Deed', 'T5678/2021'],
  ['Bond Holder', 'ABC Bank'],
  ['Bond Amount', 'R 900 000'],
  ['Sale Type', 'Normal Sale'],
];

// 60 Lilliecrona Boulevard — a REAL confirmed-live capture cited in
// content-cmainfo.js's own v3.4.0 comment: a legitimate sectional unit that
// carries a street Address AND a real Scheme name TOGETHER. Guards against
// re-introducing the earlier WRONG "Address present -> freehold" heuristic.
const LILLIECRONA_PROPERTY_FIELDS = [
  ['Deeds Office', 'Port Shepstone'],
  ['Scheme no', '77/1998'],
  ['Scheme name', 'NATSPAT'],
  ['Situated at', 'Section 4 Natspat Manaba Beach'],
  ['Section number', '4'],
  ['Flat/Unit no', '4'],
  ['Street number', '60'],
  ['Estate', ''],
  ['Address', '60 Lilliecrona Boulevard'],
  ['Erf no', ''],
  ['Suburb', 'Manaba Beach'],
  ['Municipality', 'Hibiscus Coast'],
  ['Province', 'KwaZulu-Natal'],
  ['GPS', '-30.77, 30.41'],
  ['Section extent', '95'],
  ['Type', 'Sectional Title'],
  ['Usage', 'Residential'],
];

const LILLIECRONA_SALE_FIELDS = [
  ['Owner', 'ZIETSMAN PHILIPPUS'],
  ["Owner's ID", '5008255027086'],
  ['Sale Price', 'R 1 100 000'],
  ['Sale Date', '01/02/2022'],
  ['Registered Date', '10/02/2022'],
  ['Title Deed', 'T45154/2022'],
  ['Bond Holder', ''],
  ['Bond Amount', ''],
  ['Sale Type', 'Normal Sale'],
];

// Erf-no-only fallback signal — Type left unrecognised (blank), Erf no
// populated. Same frozen-sectional-fields shape as Park Street.
const FALLBACK_FREEHOLD_PROPERTY_FIELDS = PARK_ST_PROPERTY_FIELDS_FROZEN.map(([label, value]) =>
  label === 'Type' ? [label, ''] : [label, value]
);

// v3.4.2 REGRESSION repro — two DISTINCT freehold properties captured back
// to back. Property Alpha is loaded and captured first (its own postback
// long since settled). Property Beta is a genuinely DIFFERENT property
// (different address/erf/title deed) — but the mock DOM is mutated to
// Beta's values ONLY on a delayed timer (simulating cmainfo's async
// postback actually finishing), while the capture is triggered IMMEDIATELY
// (simulating the agent clicking Capture right after selecting Beta, before
// cmainfo has mutated anything yet). A correct extension must wait for the
// genuine update; the v3.4.2 regression reads Alpha's still-frozen Sale
// Information (crucially its Title Deed) for what should be Beta's capture.
const ALPHA_PROPERTY_FIELDS = [
  ['Deeds Office', 'Port Shepstone'],
  ['Scheme no', ''],
  ['Scheme name', ''],
  ['Situated at', ''],
  ['Section number', ''],
  ['Flat/Unit no', ''],
  ['Street number', '5'],
  ['Estate', ''],
  ['Address', '5 Main Road'],
  ['Erf no', '100'],
  ['Suburb', 'Uvongo'],
  ['Municipality', 'Hibiscus Coast'],
  ['Province', 'KwaZulu-Natal'],
  ['GPS', '-30.83, 30.38'],
  ['Section extent', ''],
  ['Type', 'Freehold'],
  ['Usage', 'Residential'],
];

const ALPHA_SALE_FIELDS = [
  ['Owner', 'PETERS ANNA'],
  ["Owner's ID", '6002015800087'],
  ['Sale Price', 'R 900 000'],
  ['Sale Date', '01/01/2019'],
  ['Registered Date', '10/01/2019'],
  ['Title Deed', 'T1111/2019'],
  ['Bond Holder', ''],
  ['Bond Amount', ''],
  ['Sale Type', 'Normal Sale'],
];

const BETA_PROPERTY_UPDATES = {
  'Street number': '99',
  'Address': '99 Beach Road',
  'Erf no': '200',
  'Suburb': 'Margate',
  'GPS': '-30.86, 30.36',
  'Type': 'Freehold',
};

const BETA_SALE_UPDATES = {
  'Owner': 'DLAMINI SIPHO',
  "Owner's ID": '7208015800086',
  'Sale Price': 'R 1 400 000',
  'Sale Date': '05/05/2022',
  'Registered Date': '15/05/2022',
  'Title Deed': 'T2222/2022',
  'Sale Type': 'Normal Sale',
};

// Test F repro — Johan's live 3.4.3 report: Gamma ("20 Lilliecrona Drive")
// captured and settled first. Delta ("62 Bairn Street", Johan's real repro
// address/erf/deed) is a genuinely different property — but unlike Test E,
// its postback delivers Erf no/Suburb/GPS/Type AND the entire Sale
// Information panel PROMPTLY (wave 1, +50ms); the Address cell specifically
// lands separately and LATER (wave 2, +450ms) — reproducing "erf/deed/price/
// owner all refreshed correctly, only the address carried over".
const GAMMA_PROPERTY_FIELDS = [
  ['Deeds Office', 'Port Shepstone'],
  ['Scheme no', ''],
  ['Scheme name', ''],
  ['Situated at', ''],
  ['Section number', ''],
  ['Flat/Unit no', ''],
  ['Street number', '20'],
  ['Estate', ''],
  ['Address', '20 Lilliecrona Drive'],
  ['Erf no', '300'],
  ['Suburb', 'Uvongo'],
  ['Municipality', 'Hibiscus Coast'],
  ['Province', 'KwaZulu-Natal'],
  ['GPS', '-30.82, 30.37'],
  ['Section extent', ''],
  ['Type', 'Freehold'],
  ['Usage', 'Residential'],
];

const GAMMA_SALE_FIELDS = [
  ['Owner', 'BROWN PETER'],
  ["Owner's ID", '7001015800080'],
  ['Sale Price', 'R 700 000'],
  ['Sale Date', '01/01/2018'],
  ['Registered Date', '10/01/2018'],
  ['Title Deed', 'T3000/2018'],
  ['Bond Holder', ''],
  ['Bond Amount', ''],
  ['Sale Type', 'Normal Sale'],
];

// Wave 1 (+50ms) — everything EXCEPT Address updates promptly.
const DELTA_PROPERTY_WAVE1 = {
  'Street number': '62',
  'Erf no': '616',
  'Suburb': 'Margate',
  'GPS': '-30.79, 30.40',
  'Type': 'Freehold',
};

const DELTA_SALE_WAVE1 = {
  'Owner': 'PETERS ANNA',
  "Owner's ID": '6002015800087',
  'Sale Price': 'R 1 100 000',
  'Sale Date': '01/02/2022',
  'Registered Date': '10/02/2022',
  'Title Deed': 'T54685/2008',
  'Sale Type': 'Normal Sale',
};

// Wave 2 (+450ms) — Address lands separately, later than the rest of the panel.
const DELTA_ADDRESS = '62 Bairn Street';

// Test G repro — Johan's live 3.4.3 report: an entity/trust owner name run
// through the surname-first person-name reorder came out mangled. Reuses the
// real repro's erf/deed/price so the fixture reads as one coherent capture.
const ENTITY_OWNER_PROPERTY_FIELDS = [
  ['Deeds Office', 'Port Shepstone'],
  ['Scheme no', ''],
  ['Scheme name', ''],
  ['Situated at', ''],
  ['Section number', ''],
  ['Flat/Unit no', ''],
  ['Street number', '62'],
  ['Estate', ''],
  ['Address', '62 Bairn Street'],
  ['Erf no', '616'],
  ['Suburb', 'Margate'],
  ['Municipality', 'Hibiscus Coast'],
  ['Province', 'KwaZulu-Natal'],
  ['GPS', '-30.79, 30.40'],
  ['Section extent', ''],
  ['Type', 'Freehold'],
  ['Usage', 'Residential'],
];

const ENTITY_OWNER_SALE_FIELDS = [
  ['Owner', 'SMIT & WESSELS TRUST-TRUSTEES'],
  ["Owner's ID", ''],
  ['Sale Price', 'R 1 100 000'],
  ['Sale Date', '01/01/2020'],
  ['Registered Date', '10/01/2020'],
  ['Title Deed', 'T54685/2008'],
  ['Bond Holder', ''],
  ['Bond Amount', ''],
  ['Sale Type', 'Normal Sale'],
];

// ── Test runner ──────────────────────────────────────────────────────────

const results = [];

function check(name, condition, detail, expectPass) {
  results.push({ name, pass: !!condition, detail: detail || '', expectPass: expectPass !== false });
}

async function testA_frozenFreehold_firstCaptureInInstance(filePath, label) {
  // Fresh module instance (mirrors a reloaded/re-injected content script) —
  // the PAGE's DOM already has frozen Skippers sectional content sitting on
  // a genuinely-loaded Park Street freehold, exactly as content-cmainfo.js's
  // own v3.4.0 comment describes as an uncaught gap.
  const doc = buildCmaInfoDocument(PARK_ST_PROPERTY_FIELDS_FROZEN, PARK_ST_SALE_FIELDS);
  const chromeMock = makeChromeMock();
  loadContentScript(filePath, doc, chromeMock);
  const deed = await captureViaMessageHandler(chromeMock);
  const p = deed.property_information;

  const sectionalClean = !p.scheme_name && !p.scheme_no && !p.section_number && !p.flat_number && !p.section_extent && !p.situated_at;
  const addressCorrect = p.address === '12 Park Street' && p.erf_no === '4521' && p.suburb === 'Margate';

  // The bug is specifically that the sectional fields leak through — address/
  // erf/suburb were never broken (cmainfo does render those fresh), so that
  // check is expected to PASS on the OLD file too; only the sectional-clean
  // check is expected to FAIL there (the bug reproduction proof).
  check(`[${label}] Test A — frozen-sectional freehold: scheme/section forced EMPTY`, sectionalClean,
    `scheme_name=${JSON.stringify(p.scheme_name)} scheme_no=${JSON.stringify(p.scheme_no)} section_number=${JSON.stringify(p.section_number)} flat_number=${JSON.stringify(p.flat_number)} section_extent=${JSON.stringify(p.section_extent)} situated_at=${JSON.stringify(p.situated_at)}`,
    label !== 'OLD 3.4.1');
  check(`[${label}] Test A — freehold's own address/erf/suburb preserved`, addressCorrect,
    `address=${JSON.stringify(p.address)} erf_no=${JSON.stringify(p.erf_no)} suburb=${JSON.stringify(p.suburb)}`);
}

async function testB_consecutiveRealCaptures(filePath, label) {
  // One script instance, two real captures in sequence — Skippers first,
  // then the DOM is mutated in place to Park Street (sectional cells left
  // untouched, freehold-native + Type cells updated), matching exactly how
  // cmainfo's own WebForms postback behaves. Sanity/non-regression check for
  // the "normal" multi-capture-in-one-session flow.
  const doc = buildCmaInfoDocument(SKIPPERS_PROPERTY_FIELDS, SKIPPERS_SALE_FIELDS);
  const chromeMock = makeChromeMock();
  loadContentScript(filePath, doc, chromeMock);

  const first = await captureViaMessageHandler(chromeMock);
  check(`[${label}] Test B — first capture (Skippers) keeps its own scheme/section`,
    first.property_information.scheme_name === 'SKIPPERS OF SHELLY' && first.property_information.section_number === '3',
    `scheme_name=${JSON.stringify(first.property_information.scheme_name)} section_number=${JSON.stringify(first.property_information.section_number)}`);

  setFieldValue(doc._propPanel, 'Address', '12 Park Street');
  setFieldValue(doc._propPanel, 'Erf no', '4521');
  setFieldValue(doc._propPanel, 'Suburb', 'Margate');
  setFieldValue(doc._propPanel, 'Street number', '12');
  setFieldValue(doc._propPanel, 'Type', 'Freehold');
  setFieldValue(doc._salePanel, 'Owner', 'JONES MARY');
  setFieldValue(doc._salePanel, "Owner's ID", '7505125800088');
  setFieldValue(doc._salePanel, 'Title Deed', 'T5678/2021');

  const second = await captureViaMessageHandler(chromeMock);
  const p = second.property_information;
  const sectionalClean = !p.scheme_name && !p.scheme_no && !p.section_number && !p.flat_number && !p.section_extent && !p.situated_at;
  check(`[${label}] Test B — second capture (Park St, same session) scheme/section forced EMPTY`, sectionalClean,
    `scheme_name=${JSON.stringify(p.scheme_name)} section_number=${JSON.stringify(p.section_number)}`);
}

async function testC_legitimateSectionalWithAddress_noRegression(filePath, label) {
  // 60 Lilliecrona Boulevard — a genuinely sectional unit with its own
  // street address. Must NOT have its scheme/section nulled.
  const doc = buildCmaInfoDocument(LILLIECRONA_PROPERTY_FIELDS, LILLIECRONA_SALE_FIELDS);
  const chromeMock = makeChromeMock();
  loadContentScript(filePath, doc, chromeMock);
  const deed = await captureViaMessageHandler(chromeMock);
  const p = deed.property_information;

  const schemePreserved = p.scheme_name === 'NATSPAT' && p.section_number === '4' && p.address === '60 Lilliecrona Boulevard';
  check(`[${label}] Test C — legitimate sectional-with-address NOT nulled`, schemePreserved,
    `scheme_name=${JSON.stringify(p.scheme_name)} section_number=${JSON.stringify(p.section_number)} address=${JSON.stringify(p.address)}`);
}

async function testD_erfOnlyFallbackSignal(filePath, label) {
  // Type left blank/unrecognised — only the Erf no fallback signal is
  // available. Same frozen-sectional shape as Test A.
  const doc = buildCmaInfoDocument(FALLBACK_FREEHOLD_PROPERTY_FIELDS, PARK_ST_SALE_FIELDS);
  const chromeMock = makeChromeMock();
  loadContentScript(filePath, doc, chromeMock);
  const deed = await captureViaMessageHandler(chromeMock);
  const p = deed.property_information;

  const sectionalClean = !p.scheme_name && !p.section_number;
  check(`[${label}] Test D — Erf-no-only fallback (Type blank) still forces scheme/section EMPTY`, sectionalClean,
    `type=${JSON.stringify(p.type)} scheme_name=${JSON.stringify(p.scheme_name)} section_number=${JSON.stringify(p.section_number)} erf_no=${JSON.stringify(p.erf_no)}`);
}

async function testE_twoDistinctPropertiesInSequence(filePath, label, expectDropRegression) {
  // Property Alpha: fully captured and settled first — establishes
  // lastCaptureCompletedAt in the content script's own module state.
  const doc = buildCmaInfoDocument(ALPHA_PROPERTY_FIELDS, ALPHA_SALE_FIELDS);
  const chromeMock = makeChromeMock();
  const { fireMutation } = loadContentScript(filePath, doc, chromeMock);

  // A mutation "long ago" (Alpha's own postback finishing) — real elapsed
  // time so the 1st-capture wide settle window (850ms) genuinely elapses.
  fireMutation();
  await sleep(900);
  const alpha = await captureViaMessageHandler(chromeMock);

  check(`[${label}] Test E — Alpha capture is Alpha's own data`,
    alpha.property_information.address === '5 Main Road' && alpha.sale_information.title_deed === 'T1111/2019',
    `address=${JSON.stringify(alpha.property_information.address)} title_deed=${JSON.stringify(alpha.sale_information.title_deed)}`);

  // Property Beta: a genuinely DIFFERENT property. cmainfo's postback for
  // switching to it is scheduled to land 150ms from now (mutating the DOM
  // AND firing the mutation event, exactly like a real async postback) —
  // but the capture is requested IMMEDIATELY, before that happens, exactly
  // as an agent clicking Capture right after selecting a new property.
  let betaLanded = false;
  setTimeout(() => {
    Object.entries(BETA_PROPERTY_UPDATES).forEach(([label2, value]) => setFieldValue(doc._propPanel, label2, value));
    Object.entries(BETA_SALE_UPDATES).forEach(([label2, value]) => setFieldValue(doc._salePanel, label2, value));
    betaLanded = true;
    fireMutation();
  }, 150);

  const beta = await captureViaMessageHandler(chromeMock);
  const p = beta.property_information;
  const s = beta.sale_information;

  const gotBeta = p.address === '99 Beach Road' && p.erf_no === '200' && s.title_deed === 'T2222/2022';
  const gotStaleAlpha = s.title_deed === 'T1111/2019';

  check(`[${label}] Test E — Beta capture waited for the real update (not read before it landed)`, betaLanded,
    'extraction resolved before the scheduled DOM update ever ran — betaLanded=false',
    !expectDropRegression);
  check(`[${label}] Test E — Beta capture returns Beta's OWN distinct, non-empty data`, gotBeta,
    `address=${JSON.stringify(p.address)} erf_no=${JSON.stringify(p.erf_no)} title_deed=${JSON.stringify(s.title_deed)}`,
    !expectDropRegression);
  check(`[${label}] Test E — Beta capture did NOT collide with Alpha's identity (title_deed)`, !gotStaleAlpha,
    `title_deed=${JSON.stringify(s.title_deed)} (Alpha's was T1111/2019)`,
    !expectDropRegression);
  check(`[${label}] Test E — Alpha and Beta captures are distinct source identities`, alpha.sale_information.title_deed !== s.title_deed,
    `alpha.title_deed=${JSON.stringify(alpha.sale_information.title_deed)} beta.title_deed=${JSON.stringify(s.title_deed)}`,
    !expectDropRegression);
}

async function testF_addressLagsRestOfPanel(filePath, label, expectStaleAddressRegression) {
  // Gamma: fully captured and settled first — establishes lastCaptureCompletedAt.
  const doc = buildCmaInfoDocument(GAMMA_PROPERTY_FIELDS, GAMMA_SALE_FIELDS);
  const chromeMock = makeChromeMock();
  const { fireMutation } = loadContentScript(filePath, doc, chromeMock);

  fireMutation();
  await sleep(900);
  const gamma = await captureViaMessageHandler(chromeMock);
  check(`[${label}] Test F — Gamma capture is Gamma's own data`,
    gamma.property_information.address === '20 Lilliecrona Drive',
    `address=${JSON.stringify(gamma.property_information.address)}`);

  // Delta: a genuinely different property (Johan's real repro). Wave 1
  // (+50ms) updates Erf no/Suburb/GPS/Type AND the entire Sale Information
  // panel — everything BUT Address. Wave 2 (+450ms) updates Address alone,
  // separately and later — the exact asymmetry from the live report.
  setTimeout(() => {
    Object.entries(DELTA_PROPERTY_WAVE1).forEach(([l, v]) => setFieldValue(doc._propPanel, l, v));
    Object.entries(DELTA_SALE_WAVE1).forEach(([l, v]) => setFieldValue(doc._salePanel, l, v));
    fireMutation();
  }, 50);
  setTimeout(() => {
    setFieldValue(doc._propPanel, 'Address', DELTA_ADDRESS);
    fireMutation();
  }, 450);

  const delta = await captureViaMessageHandler(chromeMock);
  const p = delta.property_information;
  const s = delta.sale_information;

  const nonAddressFresh = p.erf_no === '616' && p.suburb === 'Margate' && s.title_deed === 'T54685/2008' && s.owner === 'PETERS ANNA';
  const addressFresh = p.address === DELTA_ADDRESS;
  const addressStale = p.address === '20 Lilliecrona Drive';

  check(`[${label}] Test F — Delta capture: erf/deed/price/owner all fresh (never lagged)`, nonAddressFresh,
    `erf_no=${JSON.stringify(p.erf_no)} suburb=${JSON.stringify(p.suburb)} title_deed=${JSON.stringify(s.title_deed)} owner=${JSON.stringify(s.owner)}`);
  check(`[${label}] Test F — Delta capture: address is FRESH (did not carry over from Gamma)`, addressFresh,
    `address=${JSON.stringify(p.address)}`, !expectStaleAddressRegression);
  check(`[${label}] Test F — Delta capture: address did NOT bleed from the previous capture`, !addressStale,
    `address=${JSON.stringify(p.address)} (Gamma's was "20 Lilliecrona Drive")`, !expectStaleAddressRegression);
}

async function testG_entityOwnerNameKeptVerbatim(filePath, label, expectMangleRegression) {
  const doc = buildCmaInfoDocument(ENTITY_OWNER_PROPERTY_FIELDS, ENTITY_OWNER_SALE_FIELDS);
  const chromeMock = makeChromeMockWithCapture();
  loadContentScript(filePath, doc, chromeMock);

  const btn = doc.getElementById('corex-deeds-capture-btn');
  if (!btn) {
    check(`[${label}] Test G — capture button injected`, false, 'no button found on a loaded property page', !expectMangleRegression);
    return;
  }
  btn.click();

  const payload = await Promise.race([chromeMock._captured, sleep(3000).then(() => null)]);
  const owner = payload && payload.captures && payload.captures[0] && payload.captures[0].owners && payload.captures[0].owners[0];

  const verbatim = !!owner && owner.name === 'SMIT & WESSELS TRUST-TRUSTEES' && owner.surname === null && owner.first_names === null;
  check(`[${label}] Test G — trust owner name kept VERBATIM, not surname-reordered`, verbatim,
    `owner=${JSON.stringify(owner)}`, !expectMangleRegression);
}

async function main() {
  console.log('=== Running against OLD file (pre-fix, v3.4.1 fixture) — Test A is EXPECTED to FAIL (bug reproduction) ===');
  await testA_frozenFreehold_firstCaptureInInstance(OLD_FILE, 'OLD 3.4.1');

  console.log('=== Running against v3.4.2 REGRESSION fixture — Test E is EXPECTED to FAIL (regression reproduction) ===');
  await testE_twoDistinctPropertiesInSequence(REGRESSION_FILE, 'REGRESSION 3.4.2', true);

  console.log('=== Running against OLD file (pre-fix, v3.4.3 fixture) — Test F/G are EXPECTED to FAIL (bug reproduction) ===');
  await testF_addressLagsRestOfPanel(OLD_343_FILE, 'OLD 3.4.3', true);
  await testG_entityOwnerNameKeptVerbatim(OLD_343_FILE, 'OLD 3.4.3', true);

  console.log('=== Running against NEW file (working tree, v3.4.4) — everything EXPECTED to PASS ===');
  await testA_frozenFreehold_firstCaptureInInstance(NEW_FILE, 'NEW 3.4.4');
  await testB_consecutiveRealCaptures(NEW_FILE, 'NEW 3.4.4');
  await testC_legitimateSectionalWithAddress_noRegression(NEW_FILE, 'NEW 3.4.4');
  await testD_erfOnlyFallbackSignal(NEW_FILE, 'NEW 3.4.4');
  await testE_twoDistinctPropertiesInSequence(NEW_FILE, 'NEW 3.4.4', false);
  await testF_addressLagsRestOfPanel(NEW_FILE, 'NEW 3.4.4', false);
  await testG_entityOwnerNameKeptVerbatim(NEW_FILE, 'NEW 3.4.4', false);

  console.log('');
  let overallOk = true;
  for (const r of results) {
    const ok = r.pass === r.expectPass;
    console.log((ok ? 'OK  ' : 'FAIL') + '  ' + r.name + (r.pass ? ' -> true' : ' -> false') + (!ok ? '  [UNEXPECTED]' : '') + (r.detail ? '\n       ' + r.detail : ''));
    if (!ok) overallOk = false;
  }

  console.log('');
  if (overallOk) {
    console.log('ALL CHECKS OK — sectional-bleed fixed since 3.4.2, multi-capture regression fixed in 3.4.3, address-lag + owner-entity-mangle fixed in 3.4.4, no regressions.');
    process.exit(0);
  } else {
    console.log('SOME CHECKS FAILED — see [UNEXPECTED] lines above.');
    process.exit(1);
  }
}

main().catch((e) => { console.error(e); process.exit(1); });
