/**
 * CoreX — deeds-capture regression harness.
 *
 * v3.4.1 -> v3.5.0 history: see git log on this file / the previous version
 * of this docblock for the LPI-transition and type-aware-composite-anchor
 * eras. Tests E and G below are the survivors from that history (freehold-
 * to-freehold settle timing, entity-owner-name parsing) — both unaffected by
 * the v3.6.0 rewrite.
 *
 * v3.6.0 (2026-08-19) — VISIBLE-PANEL-ONLY REWRITE. Root cause found live
 * (Johan, offsetParent DOM dump on SEESKULP section 1 -> Erf 668 MARINE
 * DRIVE): cmainfo keeps MULTIPLE property-type templates in the DOM
 * SIMULTANEOUSLY and toggles which is visible; the hidden one holds
 * whatever it last showed, forever, with no further mutation. This was
 * never a timing problem — every prior mechanism (DOM settle wait, the
 * v3.4.5 LPI-transition gate, the v3.5.0 per-field freshness-proof +
 * chrome.storage.local anchor persistence) was solving the wrong model of
 * the bug. Fixed by making EVERY label lookup visible-scoped by default
 * (findValueByLabel/findValueElementByLabel — see content-cmainfo.js's own
 * v3.6.0 note above isVisible()).
 *
 * RETIRED this round, with reasoning (not silently deleted — see
 * content-cmainfo.js's own removal note in the same place the old TYPE-AWARE
 * IDENTITY ANCHOR block used to be):
 *   - The v3.5.0 per-field freshness-proof mechanism (FRESHNESS_GATED_FIELDS/
 *     waitForFieldsProof) and its chrome.storage.local anchor persistence
 *     (loadLastAnchor/saveLastAnchor) — built to answer "has Address
 *     genuinely changed since the property switched", a question visible-
 *     scoping no longer needs asked (Address is always read from the
 *     visible template, correctly, unconditionally).
 *   - Test F (Gamma/Delta, "Address lags the rest of a SINGLE panel by
 *     400ms") — its whole premise (a genuine INTRA-template lag) has no
 *     remaining supporting evidence; every incident investigated, including
 *     the one Test F was originally built to reproduce, is now understood
 *     to have been a cross-TEMPLATE read, not an intra-panel one. Retired
 *     rather than kept "just in case" per Johan's explicit preference —
 *     re-add only if a genuine same-template lag is ever observed live.
 *   - testSStoFH_genuineAddressChangePasses / testFHtoSS_genuineAddressChangePasses
 *     / testSameAnchor_sectionalRecaptureNoGating / testDurability_* — all
 *     tested the retired freshness-proof/persistence machinery directly;
 *     removed along with it. Their coverage intent ("a real property switch
 *     captures correctly", "re-capturing the same property is unaffected")
 *     is now covered by the DUAL-TEMPLATE SS<->FH tests below, which is a
 *     stronger, more realistic test than either — it reproduces cmainfo's
 *     ACTUAL structure instead of a single mutated-in-place panel.
 *
 * New in v3.6.0:
 *   - testSStoFH_capturesVisibleNotHidden / testFHtoSS_capturesVisibleNotHidden
 *     — the real SEESKULP section 1 (sectional, hidden) / Erf 668 MARINE
 *     DRIVE (freehold, visible) DOM shape Johan dumped live, both
 *     directions, using mock-dom's new buildDualTemplateDocument() (which
 *     puts the HIDDEN template first in DOM order — the worst case for a
 *     naive first-match reader — so passing here proves the fix is robust
 *     to DOM order, not lucky).
 *   - testErf668_municipalityOwnerNoIdNoSale — the real edge case Johan hit
 *     capturing Erf 668 itself: owner "HIBISCUS COAST MUNICIPALITY", no
 *     Owner's ID, no sale price/date. Must not refuse, must not mangle the
 *     owner name, must send null (not fabricate) for the missing sale data.
 *
 * Runs the REAL content-cmainfo.js source (unmodified — no test-only hooks)
 * inside a Node `vm` sandbox with a minimal mock DOM + chrome API.
 *
 * Usage: node tests/deeds-cleanslate.test.cjs
 * Exits 0 if every check passes, 1 otherwise.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { setFieldValue, buildCmaInfoDocument, buildDualTemplateDocument } = require('./mock-dom.cjs');

const OLD_FILE = path.join(__dirname, 'fixtures', 'content-cmainfo.v3.4.1.js');
const REGRESSION_FILE = path.join(__dirname, 'fixtures', 'content-cmainfo.v3.4.2.js');
const OLD_343_FILE = path.join(__dirname, 'fixtures', 'content-cmainfo.v3.4.3.js');
const PRE_FIX_FILE = path.join(__dirname, 'fixtures', 'content-cmainfo.v3.4.5.js'); // frozen before the GPS parser fix — still the right comparison target for that one check
const NEW_FILE = path.join(__dirname, '..', 'content-cmainfo.js');

function makeStorageLocal(backingStore) {
  const store = backingStore || {};
  return {
    get: (keys, cb) => {
      const out = {};
      (Array.isArray(keys) ? keys : [keys]).forEach((k) => { if (store[k] !== undefined) out[k] = store[k]; });
      cb(out);
    },
    set: (obj, cb) => {
      Object.assign(store, obj);
      if (cb) cb();
    },
  };
}

function makeChromeMock(storageBackingStore) {
  let listener = null;
  return {
    runtime: {
      onMessage: { addListener: (fn) => { listener = fn; } },
      sendMessage: () => Promise.resolve({}),
    },
    storage: { local: makeStorageLocal(storageBackingStore) },
    _getListener: () => listener,
  };
}

/** Also records the payload sent via chrome.runtime.sendMessage({action:'captureDeed', ...}) — needed for GPS/owner end-to-end checks, which only run inside buildDeedsCapturePayload(), reached from the on-page button click. */
function makeChromeMockWithCapture(storageBackingStore) {
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
    storage: { local: makeStorageLocal(storageBackingStore) },
    _getListener: () => listener,
    _captured: captured,
  };
}

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

// Freehold panel whose sectional-only fields carry FROZEN residue, ALL in
// ONE flat table (no hidden sub-template) — this specifically tests the
// applyTypeCoherence() DEFENSE-IN-DEPTH path (both signals technically
// present-and-visible at once), not the visible-scoping fix itself.
const PARK_ST_PROPERTY_FIELDS_FROZEN = [
  ['LPI Code', 'N0ET04520000045200001'],
  ['Deeds Office', 'Port Shepstone'],
  ['Scheme no', '123/2005'],
  ['Scheme name', 'SKIPPERS OF SHELLY'],
  ['Situated at', 'Section 3 Skippers Of Shelly Shelly Beach'],
  ['Section number', '3'],
  ['Flat/Unit no', '3'],
  ['Street number', '12'],
  ['Estate', ''],
  ['Address', '12 Park Street'],
  ['Erf no', '4521'],
  ['Suburb', 'Margate'],
  ['Municipality', 'Hibiscus Coast'],
  ['Province', 'KwaZulu-Natal'],
  ['GPS', '30.35°E   30.85°S'],
  ['Section extent', '72'],
  ['Cadastral extent', ''],
  ['Type', 'Freehold'],
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

const LILLIECRONA_PROPERTY_FIELDS = [
  ['LPI Code', ''],
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
  ['GPS', '30.41°E   30.77°S'],
  ['Section extent', '95'],
  ['Cadastral extent', ''],
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

const NO_ANCHOR_PROPERTY_FIELDS = PARK_ST_PROPERTY_FIELDS_FROZEN.map(([label, value]) => {
  if (label === 'LPI Code' || label === 'Erf no' || label === 'Scheme no' || label === 'Section number') return [label, ''];
  return [label, value];
});

const CONTRADICTORY_PROPERTY_FIELDS = PARK_ST_PROPERTY_FIELDS_FROZEN.map(([label, value]) => {
  if (label === 'Type') return [label, ''];
  return [label, value];
});

// ── REAL DATA — SEESKULP section 1 (sectional) <-> Erf 668 MARINE DRIVE
// (freehold). Field values transcribed from Johan's live cmainfo DOM dump,
// 2026-08-19 — the exact case that produced both the stale-address leak and
// the false "not a recognisable property" refusal.

const SEESKULP_PROPERTY_FIELDS = [
  ['LPI Code', ''],
  ['Deeds Office', 'Port Shepstone'],
  ['Scheme no', '257/1987'],
  ['Scheme name', 'SEESKULP'],
  ['Situated at', 'Section 1 Seeskulp Uvongo'],
  ['Section number', '1'],
  ['Flat/Unit no', ''],
  ['Street number', '60'],
  ['Estate', ''],
  ['Address', '60 COLIN DRIVE'],
  ['Erf no', ''],
  ['Suburb', 'Uvongo Beach'],
  ['Municipality', 'Hibiscus Coast'],
  ['Province', 'KwaZulu-Natal'],
  ['GPS', '30.398586°E 30.830687°S'],
  ['Section extent', '63'],
  ['Cadastral extent', ''],
  ['Type', 'Sectional Title'],
  ['Usage', 'Residential'],
];

const SEESKULP_SALE_FIELDS = [
  ['Owner', 'BOTHA MARTHA MARIA 50% ; BOTHA MARTHA MARIA 50%'],
  ["Owner's ID", '5305240097087 ; 5305240097087'],
  ['Sale Price', 'R 275 000'],
  ['Sale Date', '26/10/2004'],
  ['Registered Date', '07/04/2005'],
  ['Title Deed', 'ST16006/2005'],
  ['Bond Holder', ''],
  ['Bond Amount', ''],
  ['Sale Type', 'Normal Sale'],
];

// Real values, exactly as Johan's live session reported them: Type is
// literally "-" (Issue 2), Extent is "9 480" with a space thousands-
// separator (Issue 3).
const ERF_668_PROPERTY_FIELDS = [
  ['LPI Code', 'N0ET03630000066800000'],
  ['Deeds Office', 'Port Shepstone'],
  ['Scheme no', ''],
  ['Scheme name', ''],
  ['Situated at', ''],
  ['Section number', ''],
  ['Flat/Unit no', ''],
  ['Street number', ''],
  ['Estate', ''],
  ['Address', 'MARINE DRIVE'],
  ['Erf no', '668'],
  ['Suburb', 'Uvongo Beach'],
  ['Municipality', 'Hibiscus Coast'],
  ['Province', 'KwaZulu-Natal'],
  ['GPS', '30.398908°E 30.830085°S'],
  ['Section extent', ''],
  ['Extent', '9 480 m²'],
  ['Cadastral extent', '9 480 m²'],
  ['Type', '-'],
  ['Usage', 'Residential'],
];

// Real values, exactly as reported: a ten-entry, semicolon-joined deed/share
// list (Issue 1's actual trigger).
const SEESKULP_S4_PROPERTY_FIELDS = [
  ['LPI Code', ''],
  ['Deeds Office', 'Port Shepstone'],
  ['Scheme no', '257/1987'],
  ['Scheme name', 'SEESKULP'],
  ['Situated at', 'Section 4 Seeskulp Uvongo'],
  ['Section number', '4'],
  ['Flat/Unit no', ''],
  ['Street number', '60'],
  ['Estate', ''],
  ['Address', '60 COLIN DRIVE'],
  ['Erf no', ''],
  ['Suburb', 'Uvongo Beach'],
  ['Municipality', 'Hibiscus Coast'],
  ['Province', 'KwaZulu-Natal'],
  ['GPS', '30.398586°E 30.830687°S'],
  ['Section extent', '64'],
  ['Extent', ''],
  ['Cadastral extent', ''],
  ['Type', 'Sectional Title'],
  ['Usage', 'Residential'],
];

// Real multi-owner shape, taken verbatim from the approved spec's own §7.3
// example (.ai/specs/deeds-capture.md) — ten semicolon-joined entries per
// cell, matching what Johan actually hit on SEESKULP section 4. Used for
// both Issue 1 (source_ref shape, unaffected by Owner content) and the new
// ownership_history_raw contract tests.
const SEESKULP_S4_SALE_FIELDS = [
  ['Owner', 'WILKEN JOHAN 82.7397% ; WILKEN HESTER JOHANNA CATHARINA ; SOMEONE ELSE 15.3424% ; ANOTHER PERSON 1.9178% ; THIRD PERSON 1.9178% ; FOURTH PERSON ; FIFTH PERSON 98.0822% ; SIXTH PERSON 0.9589% ; SEE-SKULP TRUST-TRUSTEES ;'],
  ["Owner's ID", '581111******* ; 620117******* ; 620117******* ; 581111******* ; IT 1203/91 ; 581111******* ; 620117******* ; 290527******* ; 340427******* ;'],
  ['Sale Price', 'R 450 000'],
  ['Sale Date', '01/03/2003'],
  ['Registered Date', '15/06/2003'],
  ['Title Deed', 'ST39075/2003 82.7397% ; ST39075/2003 ; ST39074/2003 ; ST39074/2003 15.3424% ; ST39073/2003 1.9178% ; ST6815/1993 1.9178% ; ST6815/1993 ; ST4830/1993 98.0822% ; ST4830/1993 0.9589% ; ST257-4'],
  ['Bond Holder', ''],
  ['Bond Amount', ''],
  ['Sale Type', 'Normal Sale'],
];

const SINGLE_OWNER_SALE_FIELDS = [
  ['Owner', 'SMITH JOHN'],
  ["Owner's ID", '6001015800089'],
  ['Sale Price', 'R 450 000'],
  ['Sale Date', '01/03/2003'],
  ['Registered Date', '15/06/2003'],
  ['Title Deed', 'ST39075/2003'],
  ['Bond Holder', ''],
  ['Bond Amount', ''],
  ['Sale Type', 'Normal Sale'],
];

// Real edge case: owner is a MUNICIPALITY, no Owner's ID at all, no sale
// price/date recorded (cmainfo shows a "Note: The Sale Price is inclusive
// of 6 properties on this Title Deed" banner instead — not itself a
// label/value row, so not modelled here).
const ERF_668_SALE_FIELDS = [
  ['Owner', 'HIBISCUS COAST MUNICIPALITY'],
  ["Owner's ID", ''],
  ['Sale Price', ''],
  ['Sale Date', ''],
  ['Registered Date', ''],
  ['Title Deed', 'T14042/2018'],
  ['Bond Holder', ''],
  ['Bond Amount', ''],
  ['Sale Type', ''],
];

const ENTITY_OWNER_PROPERTY_FIELDS = [
  ['LPI Code', 'N0ET03630000063200000'],
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
  ['GPS', '30.40°E   30.79°S'],
  ['Section extent', ''],
  ['Cadastral extent', ''],
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

// Alpha/Beta — two DISTINCT freehold properties, freehold-to-freehold,
// single flat table (no hidden template involved) — tests that the settle-
// timing machinery (ensureSectionExpanded/domIsSettled/mutation observer)
// still correctly waits for a genuine postback to land before reading.
const ALPHA_PROPERTY_FIELDS = [
  ['LPI Code', 'N0ET01000000010000001'],
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
  ['GPS', '30.38°E   30.83°S'],
  ['Section extent', ''],
  ['Cadastral extent', ''],
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
  'LPI Code': 'N0ET02000000020000002',
  'Street number': '99',
  'Address': '99 Beach Road',
  'Erf no': '200',
  'Suburb': 'Margate',
  'GPS': '30.36°E   30.86°S',
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

// ── Test runner ──────────────────────────────────────────────────────────

const results = [];

function check(name, condition, detail, expectPass) {
  results.push({ name, pass: !!condition, detail: detail || '', expectPass: expectPass !== false });
}

// ══════════════════════════════════════════════════════════
// ── TYPE COHERENCE + HARD FAIL-CLOSED (defense-in-depth path) ──
// ══════════════════════════════════════════════════════════

async function testA_typeCoherenceDropsForeignResidue(filePath, label) {
  const doc = buildCmaInfoDocument(PARK_ST_PROPERTY_FIELDS_FROZEN, PARK_ST_SALE_FIELDS);
  const chromeMock = makeChromeMock();
  loadContentScript(filePath, doc, chromeMock);
  const deed = await captureViaMessageHandler(chromeMock);
  const p = deed.property_information;

  const sectionalClean = !p.scheme_name && !p.scheme_no && !p.section_number && !p.flat_number && !p.section_extent && !p.situated_at;
  const addressCorrect = p.address === '12 Park Street' && p.erf_no === '4521' && p.suburb === 'Margate';

  check(`[${label}] Test A — type coherence drops sectional residue (both signals visible+present, Type tiebreaks freehold)`, sectionalClean,
    `scheme_name=${JSON.stringify(p.scheme_name)} scheme_no=${JSON.stringify(p.scheme_no)} section_number=${JSON.stringify(p.section_number)}`);
  check(`[${label}] Test A — freehold's own address/erf/suburb preserved`, addressCorrect,
    `address=${JSON.stringify(p.address)} erf_no=${JSON.stringify(p.erf_no)} suburb=${JSON.stringify(p.suburb)}`);
}

async function testC_sectionalWithOwnAddressNotDropped(filePath, label) {
  const doc = buildCmaInfoDocument(LILLIECRONA_PROPERTY_FIELDS, LILLIECRONA_SALE_FIELDS);
  const chromeMock = makeChromeMock();
  loadContentScript(filePath, doc, chromeMock);
  const deed = await captureViaMessageHandler(chromeMock);
  const p = deed.property_information;

  const schemePreserved = p.scheme_name === 'NATSPAT' && p.section_number === '4' && p.address === '60 Lilliecrona Boulevard';
  const noForeignFields = !p.lpi_code && !p.erf_no;
  check(`[${label}] Test C — legitimate sectional-with-address NOT dropped`, schemePreserved,
    `scheme_name=${JSON.stringify(p.scheme_name)} section_number=${JSON.stringify(p.section_number)} address=${JSON.stringify(p.address)}`);
  check(`[${label}] Test C — sectional capture carries no freehold anchor fields`, noForeignFields,
    `lpi_code=${JSON.stringify(p.lpi_code)} erf_no=${JSON.stringify(p.erf_no)}`);
}

async function testD1_hardFailClosed_noAnchorAtAll(filePath, label) {
  const doc = buildCmaInfoDocument(NO_ANCHOR_PROPERTY_FIELDS, PARK_ST_SALE_FIELDS);
  const chromeMock = makeChromeMock();
  loadContentScript(filePath, doc, chromeMock);
  let threw = false;
  let message = '';
  try {
    await captureViaMessageHandler(chromeMock);
  } catch (e) {
    threw = true;
    message = e.message;
  }
  check(`[${label}] Test D1 — no anchor visible at all refuses the capture`, threw,
    `threw=${threw} message=${JSON.stringify(message)}`);
}

async function testD2_hardFailClosed_contradictoryAnchor(filePath, label) {
  const doc = buildCmaInfoDocument(CONTRADICTORY_PROPERTY_FIELDS, PARK_ST_SALE_FIELDS);
  const chromeMock = makeChromeMock();
  loadContentScript(filePath, doc, chromeMock);
  let threw = false;
  let message = '';
  try {
    await captureViaMessageHandler(chromeMock);
  } catch (e) {
    threw = true;
    message = e.message;
  }
  check(`[${label}] Test D2 — both anchors visible AND Type blank (genuinely unreadable) refuses the capture`, threw,
    `threw=${threw} message=${JSON.stringify(message)}`);
}

// ══════════════════════════════════════════════════════════
// ── DUAL-TEMPLATE SS<->FH — the real 2026-08-19 incident, both directions ──
// ══════════════════════════════════════════════════════════
// buildDualTemplateDocument(hidden, visible, sale) puts the HIDDEN
// template's DOM nodes FIRST — the worst case for a naive first-match
// reader. The pre-v3.6.0 mechanism would read whichever template's cells
// happen to come first in raw DOM order, regardless of visibility; passing
// here on the WORST possible order proves the fix, not a lucky one.

async function testSStoFH_capturesVisibleNotHidden(filePath, label) {
  // SEESKULP (sectional) hidden, Erf 668 (freehold) visible — exactly
  // Johan's real DOM state when Erf 668 falsely refused.
  const doc = buildDualTemplateDocument(SEESKULP_PROPERTY_FIELDS, ERF_668_PROPERTY_FIELDS, ERF_668_SALE_FIELDS);
  const chromeMock = makeChromeMock();
  loadContentScript(filePath, doc, chromeMock);
  const deed = await captureViaMessageHandler(chromeMock);
  const p = deed.property_information;
  const s = deed.sale_information;

  check(`[${label}] SS(hidden)->FH(visible) — captures Erf 668's OWN address, not SEESKULP's "60 COLIN DRIVE"`,
    p.address === 'MARINE DRIVE',
    `address=${JSON.stringify(p.address)}`);
  check(`[${label}] SS(hidden)->FH(visible) — erf/LPI are Erf 668's own, no sectional residue`,
    p.erf_no === '668' && p.lpi_code === 'N0ET03630000066800000' && !p.scheme_name && !p.section_number,
    `erf_no=${JSON.stringify(p.erf_no)} lpi_code=${JSON.stringify(p.lpi_code)} scheme_name=${JSON.stringify(p.scheme_name)} section_number=${JSON.stringify(p.section_number)}`);
  check(`[${label}] SS(hidden)->FH(visible) — GPS is Erf 668's own reading`,
    p.gps === '30.398908°E 30.830085°S',
    `gps=${JSON.stringify(p.gps)}`);
  check(`[${label}] SS(hidden)->FH(visible) — sale info is Erf 668's own (title deed)`,
    s.title_deed === 'T14042/2018',
    `title_deed=${JSON.stringify(s.title_deed)}`);
}

async function testFHtoSS_capturesVisibleNotHidden(filePath, label) {
  // Reverse direction: Erf 668 (freehold) hidden, SEESKULP (sectional) visible.
  const doc = buildDualTemplateDocument(ERF_668_PROPERTY_FIELDS, SEESKULP_PROPERTY_FIELDS, SEESKULP_SALE_FIELDS);
  const chromeMock = makeChromeMock();
  loadContentScript(filePath, doc, chromeMock);
  const deed = await captureViaMessageHandler(chromeMock);
  const p = deed.property_information;
  const s = deed.sale_information;

  check(`[${label}] FH(hidden)->SS(visible) — captures SEESKULP's OWN address, not Erf 668's "MARINE DRIVE"`,
    p.address === '60 COLIN DRIVE',
    `address=${JSON.stringify(p.address)}`);
  check(`[${label}] FH(hidden)->SS(visible) — scheme/section are SEESKULP's own, no freehold residue (erf_number will never be "668")`,
    p.scheme_name === 'SEESKULP' && p.section_number === '1' && !p.erf_no && !p.lpi_code,
    `scheme_name=${JSON.stringify(p.scheme_name)} section_number=${JSON.stringify(p.section_number)} erf_no=${JSON.stringify(p.erf_no)} lpi_code=${JSON.stringify(p.lpi_code)}`);
  check(`[${label}] FH(hidden)->SS(visible) — GPS is SEESKULP's own reading`,
    p.gps === '30.398586°E 30.830687°S',
    `gps=${JSON.stringify(p.gps)}`);
  check(`[${label}] FH(hidden)->SS(visible) — sale info is SEESKULP's own (title deed)`,
    s.title_deed === 'ST16006/2005',
    `title_deed=${JSON.stringify(s.title_deed)}`);
}

// ══════════════════════════════════════════════════════════
// ── ERF 668 EDGE CASE — municipality owner, no ID, no sale ──
// ══════════════════════════════════════════════════════════

async function testErf668_municipalityOwnerNoIdNoSale(filePath, label) {
  const doc = buildCmaInfoDocument(ERF_668_PROPERTY_FIELDS, ERF_668_SALE_FIELDS);
  const chromeMock = makeChromeMockWithCapture();
  loadContentScript(filePath, doc, chromeMock);

  const btn = doc.getElementById('corex-deeds-capture-btn');
  let threw = false;
  if (!btn) {
    check(`[${label}] Erf 668 edge case — capture button injected`, false, 'no button found on a loaded property page');
    return;
  }
  try {
    btn.click();
  } catch (e) {
    threw = true;
  }

  const payload = await Promise.race([chromeMock._captured, sleep(3000).then(() => null)]);
  const capture = payload && payload.captures && payload.captures[0];
  const owner = capture && capture.owners && capture.owners[0];
  const sale = capture && capture.sale;

  check(`[${label}] Erf 668 edge case — capture did not throw / did not refuse`, !threw && !!payload,
    `threw=${threw} payload=${JSON.stringify(!!payload)}`);
  check(`[${label}] Erf 668 edge case — municipality owner kept VERBATIM, not surname-mangled`,
    !!owner && owner.name === 'HIBISCUS COAST MUNICIPALITY' && owner.surname === null && owner.first_names === null,
    `owner=${JSON.stringify(owner)}`);
  check(`[${label}] Erf 668 edge case — missing Owner's ID sent as null, not fabricated`,
    !!owner && owner.id_number === null && owner.id_type === null,
    `owner=${JSON.stringify(owner)}`);
  check(`[${label}] Erf 668 edge case — missing sale price/date sent as null, not fabricated`,
    !!sale && sale.sale_price === null && sale.sale_date === null,
    `sale=${JSON.stringify(sale)}`);
  check(`[${label}] Erf 668 edge case — title deed still captured correctly despite the blank sale fields around it`,
    capture && capture.property && capture.property.title_deed_number === 'T14042/2018',
    `title_deed_number=${JSON.stringify(capture && capture.property && capture.property.title_deed_number)}`);

  // Issue 2 — Type "-" must normalise to null, not the literal dash.
  check(`[${label}] Issue 2 — Type "-" normalises to null, not the literal dash`,
    capture && capture.property && capture.property.property_type === null,
    `property_type=${JSON.stringify(capture && capture.property && capture.property.property_type)}`);

  // Extent contract (2026-08-20, Johan's own field names, cc3 implementing
  // the server side in parallel) — three separate, independent fields.
  // Erf 668: Extent AND Cadastral extent are both "9 480 m²" on the real
  // panel -> both erf_extent_m2 and cadastral_extent_m2 must be 9480 (space-
  // grouping fix proven on BOTH); section_extent_m2 must be ABSENT (null) —
  // a freehold panel has no Section extent row at all.
  check(`[${label}] Extent contract — erf_extent_m2 = 9480 (not 9 — space-grouping fix)`,
    capture && capture.property && capture.property.erf_extent_m2 === 9480,
    `erf_extent_m2=${JSON.stringify(capture && capture.property && capture.property.erf_extent_m2)}`);
  check(`[${label}] Extent contract — cadastral_extent_m2 = 9480 (its own separate value, not substituted)`,
    capture && capture.property && capture.property.cadastral_extent_m2 === 9480,
    `cadastral_extent_m2=${JSON.stringify(capture && capture.property && capture.property.cadastral_extent_m2)}`);
  check(`[${label}] Extent contract — section_extent_m2 ABSENT for a freehold capture (no Section extent row exists)`,
    capture && capture.property && capture.property.section_extent_m2 === null,
    `section_extent_m2=${JSON.stringify(capture && capture.property && capture.property.section_extent_m2)}`);
}

async function testSEESKULPS4_sourceRefShapeFix(filePath, label) {
  const doc = buildCmaInfoDocument(SEESKULP_S4_PROPERTY_FIELDS, SEESKULP_S4_SALE_FIELDS);
  const chromeMock = makeChromeMockWithCapture();
  loadContentScript(filePath, doc, chromeMock);

  const btn = doc.getElementById('corex-deeds-capture-btn');
  if (!btn) {
    check(`[${label}] Issue 1 — capture button injected`, false, 'no button found on a loaded property page');
    return;
  }
  btn.click();

  const payload = await Promise.race([chromeMock._captured, sleep(3000).then(() => null)]);
  const capture = payload && payload.captures && payload.captures[0];
  const ref = capture && capture.source_ref;

  check(`[${label}] Issue 1 — capture did not refuse (ten-entry title-deed list on screen)`, !!payload,
    `payload=${JSON.stringify(!!payload)}`);
  check(`[${label}] Issue 1 — source_ref is well under the server's 200-char limit`,
    !!ref && ref.length <= 200,
    `ref=${JSON.stringify(ref)} length=${ref ? ref.length : null}`);
  check(`[${label}] Issue 1 — source_ref is built from Scheme no + Section number, NOT the deed list`,
    ref === 'cmainfo:257/1987-4',
    `ref=${JSON.stringify(ref)}`);
  check(`[${label}] Issue 1 — the (huge) title deed field is still captured correctly on the property record itself`,
    capture && capture.property && capture.property.title_deed_number === SEESKULP_S4_SALE_FIELDS[5][1],
    `title_deed_number length=${capture && capture.property && capture.property.title_deed_number ? capture.property.title_deed_number.length : null}`);

  // Extent contract — SEESKULP section 4: section_extent_m2 = 64 (its own
  // real value), the other two ABSENT — a sectional panel has no Extent and
  // no Cadastral extent row at all.
  check(`[${label}] Extent contract — section_extent_m2 = 64`,
    capture && capture.property && capture.property.section_extent_m2 === 64,
    `section_extent_m2=${JSON.stringify(capture && capture.property && capture.property.section_extent_m2)}`);
  check(`[${label}] Extent contract — erf_extent_m2 ABSENT for a sectional capture`,
    capture && capture.property && capture.property.erf_extent_m2 === null,
    `erf_extent_m2=${JSON.stringify(capture && capture.property && capture.property.erf_extent_m2)}`);
  check(`[${label}] Extent contract — cadastral_extent_m2 ABSENT for a sectional capture`,
    capture && capture.property && capture.property.cadastral_extent_m2 === null,
    `cadastral_extent_m2=${JSON.stringify(capture && capture.property && capture.property.cadastral_extent_m2)}`);
}

async function testSEESKULPS4_singleDeedBuildsSameRefShape(filePath, label) {
  // Same property, but the deed field happens to be a single short value —
  // the ref must be built the SAME way (scheme+section), never
  // sometimes-deed/sometimes-scheme for the same physical unit.
  const singleDeedSale = SEESKULP_S4_SALE_FIELDS.map(([l, v]) => (l === 'Title Deed' ? [l, 'ST39075/2003'] : [l, v]));
  const doc = buildCmaInfoDocument(SEESKULP_S4_PROPERTY_FIELDS, singleDeedSale);
  const chromeMock = makeChromeMockWithCapture();
  loadContentScript(filePath, doc, chromeMock);
  doc.getElementById('corex-deeds-capture-btn').click();
  const payload = await Promise.race([chromeMock._captured, sleep(3000).then(() => null)]);
  const ref = payload && payload.captures && payload.captures[0] && payload.captures[0].source_ref;
  check(`[${label}] Issue 1 — single-deed sectional builds the IDENTICAL ref shape as the ten-entry-deed one`,
    ref === 'cmainfo:257/1987-4',
    `ref=${JSON.stringify(ref)}`);
}

// ══════════════════════════════════════════════════════════
// ── ownership_history_raw — spec §7.2/§7.3 (cc4, approved) ──
// ══════════════════════════════════════════════════════════

async function testOwnershipHistoryRaw_sentVerbatimWhenMultiOwner(filePath, label) {
  const doc = buildCmaInfoDocument(SEESKULP_S4_PROPERTY_FIELDS, SEESKULP_S4_SALE_FIELDS);
  const chromeMock = makeChromeMockWithCapture();
  loadContentScript(filePath, doc, chromeMock);
  doc.getElementById('corex-deeds-capture-btn').click();
  const payload = await Promise.race([chromeMock._captured, sleep(3000).then(() => null)]);
  const capture = payload && payload.captures && payload.captures[0];
  const raw = capture && capture.ownership_history_raw;

  const expectedOwnerNames = SEESKULP_S4_SALE_FIELDS[0][1];
  const expectedOwnerIds = SEESKULP_S4_SALE_FIELDS[1][1];
  const expectedTitleDeeds = SEESKULP_S4_SALE_FIELDS[5][1];

  check(`[${label}] ownership_history_raw — present when Owner cell has multiple ";"-joined entries`, !!raw,
    `raw=${JSON.stringify(raw)}`);
  check(`[${label}] ownership_history_raw — owner_names sent VERBATIM (no splitting, no share-stripping)`,
    !!raw && raw.owner_names === expectedOwnerNames,
    `owner_names=${JSON.stringify(raw && raw.owner_names)}`);
  check(`[${label}] ownership_history_raw — owner_ids sent VERBATIM (masked "IT 1203/91" entry untouched, not stripped)`,
    !!raw && raw.owner_ids === expectedOwnerIds && raw.owner_ids.indexOf('IT 1203/91') !== -1,
    `owner_ids=${JSON.stringify(raw && raw.owner_ids)}`);
  check(`[${label}] ownership_history_raw — title_deeds sent VERBATIM (the same long list Issue 1 had to stop using as the ref)`,
    !!raw && raw.title_deeds === expectedTitleDeeds,
    `title_deeds=${JSON.stringify(raw && raw.title_deeds)}`);
  check(`[${label}] owners[] (existing path) is STILL built, unchanged, alongside the new raw field`,
    !!capture.owners && capture.owners.length > 1,
    `owners.length=${capture.owners && capture.owners.length}`);
}

async function testOwnershipHistoryRaw_absentWhenSingleOwner(filePath, label) {
  const doc = buildCmaInfoDocument(SEESKULP_S4_PROPERTY_FIELDS, SINGLE_OWNER_SALE_FIELDS);
  const chromeMock = makeChromeMockWithCapture();
  loadContentScript(filePath, doc, chromeMock);
  doc.getElementById('corex-deeds-capture-btn').click();
  const payload = await Promise.race([chromeMock._captured, sleep(3000).then(() => null)]);
  const capture = payload && payload.captures && payload.captures[0];

  check(`[${label}] ownership_history_raw — OMITTED entirely for a single-entry Owner cell (additive/optional, zero risk to the simple case)`,
    capture && !('ownership_history_raw' in capture),
    `has_key=${capture && ('ownership_history_raw' in capture)}`);
  check(`[${label}] owners[] (existing path) still correct for the simple single-owner case`,
    !!capture && capture.owners && capture.owners.length === 1 && capture.owners[0].name === 'John Smith',
    `owners=${JSON.stringify(capture && capture.owners)}`);
}

/**
 * Black-box proof (drives the real onCaptureClick() -> extractDeed() ->
 * revealOwnerIdIfNeeded() flow, no internals poked) that ALL reveal icons
 * in the Owner's ID row get clicked, not just the first. Two fa-eye icons
 * are wired so EACH must fire for the cell to end up fully unmasked — if
 * revealOwnerIdIfNeeded() only clicked the first (the pre-v3.6.2 bug this
 * fixes), the second half of the cell would still show an asterisk when
 * the payload is built.
 */
async function testRevealOwnerIdIfNeeded_clicksEveryIcon(filePath, label) {
  const maskedFields = SEESKULP_S4_SALE_FIELDS.map(([l, v]) => (l === "Owner's ID" ? [l, 'AAA******* ; BBB*******'] : [l, v]));
  const doc = buildCmaInfoDocument(SEESKULP_S4_PROPERTY_FIELDS, maskedFields);

  const rows = doc._salePanel.querySelectorAll('tr');
  let idValueCell = null;
  for (const tr of rows) {
    if (tr.children[0] && String(tr.children[0].textContent).trim().toLowerCase() === "owner's id") { idValueCell = tr.children[1]; break; }
  }
  if (!idValueCell) {
    check(`[${label}] reveal-every-icon — Owner's ID row found in fixture`, false, 'row not found');
    return;
  }

  const chromeMock = makeChromeMockWithCapture();
  const { sandbox } = loadContentScript(filePath, doc, chromeMock);

  // Two icons, appended to the value cell (a valid, if unconfirmed, per-
  // position reveal shape) — icon1's click reveals only the FIRST half;
  // BOTH must fire for zero asterisks to remain.
  const icon1 = sandbox.document.createElement('i');
  icon1.addClass('fa fa-eye');
  icon1.addEventListener('click', () => { idValueCell.textContent = 'AAA111111AAA ; BBB*******'; });
  const icon2 = sandbox.document.createElement('i');
  icon2.addClass('fa fa-eye');
  icon2.addEventListener('click', () => { idValueCell.textContent = 'AAA111111AAA ; BBB222222BBB'; });
  idValueCell.parentNode.appendChild(icon1);
  idValueCell.parentNode.appendChild(icon2);

  doc.getElementById('corex-deeds-capture-btn').click();
  const payload = await Promise.race([chromeMock._captured, sleep(3000).then(() => null)]);
  const rawIds = payload && payload.captures && payload.captures[0] && payload.captures[0].ownership_history_raw && payload.captures[0].ownership_history_raw.owner_ids;

  check(`[${label}] reveal-every-icon — BOTH reveal icons fired, cell ends fully unmasked (no asterisk left)`,
    rawIds === 'AAA111111AAA ; BBB222222BBB',
    `owner_ids=${JSON.stringify(rawIds)}`);
}

// ══════════════════════════════════════════════════════════
// ── KEPT — freehold-to-freehold settle timing, entity owner name ──
// ══════════════════════════════════════════════════════════

async function testE_twoDistinctPropertiesInSequence(filePath, label, expectDropRegression) {
  const doc = buildCmaInfoDocument(ALPHA_PROPERTY_FIELDS, ALPHA_SALE_FIELDS);
  const chromeMock = makeChromeMock();
  const { fireMutation } = loadContentScript(filePath, doc, chromeMock);

  fireMutation();
  await sleep(900);
  const alpha = await captureViaMessageHandler(chromeMock);

  check(`[${label}] Test E — Alpha capture is Alpha's own data`,
    alpha.property_information.address === '5 Main Road' && alpha.sale_information.title_deed === 'T1111/2019',
    `address=${JSON.stringify(alpha.property_information.address)} title_deed=${JSON.stringify(alpha.sale_information.title_deed)}`);

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

// ══════════════════════════════════════════════════════════
// ── GPS PARSER — direct old-vs-new comparison (unchanged since Fix 1) ──
// ══════════════════════════════════════════════════════════

function extractFunctionSource(fileSrc, fnName) {
  const startMarker = 'function ' + fnName + '(';
  const startIdx = fileSrc.indexOf(startMarker);
  if (startIdx === -1) throw new Error('function ' + fnName + ' not found in source');
  const braceStart = fileSrc.indexOf('{', startIdx);
  let depth = 0;
  let i = braceStart;
  for (; i < fileSrc.length; i++) {
    if (fileSrc[i] === '{') depth++;
    else if (fileSrc[i] === '}') { depth--; if (depth === 0) { i++; break; } }
  }
  return fileSrc.slice(startIdx, i);
}

function loadStandaloneFunction(filePath, fnName) {
  const src = fs.readFileSync(filePath, 'utf8');
  const fnSrc = extractFunctionSource(src, fnName);
  const sandbox = {};
  vm.createContext(sandbox);
  vm.runInContext(fnSrc + '\nthis.__fn = ' + fnName + ';', sandbox);
  return sandbox.__fn;
}

// ══════════════════════════════════════════════════════════
// ── parseNumericValue — thousands-separator truncation fix ──
// ══════════════════════════════════════════════════════════
// Direct unit-level proof (Erf 668's own real values): plain space, NBSP
// (U+00A0), and thin space (U+2009) all collapse correctly; a genuinely
// unparseable value fails loud (returns null); a genuinely blank one does
// not (silent null, not a "failure").

function runNumericParserTests() {
  const parseNumericValue = loadStandaloneFunction(NEW_FILE, 'parseNumericValue');

  const cases = [
    { name: 'real live sample — Extent, plain space ("9 480 m²")', input: '9 480 m²', expected: 9480 },
    { name: 'real live sample — Cadastral extent, plain space ("9 480 m²")', input: '9 480 m²', expected: 9480 },
    { name: 'NBSP (U+00A0) as the thousands separator — explicit non-breaking space char', input: '9\u00a0480 m²', expected: 9480 },
    { name: 'thin space (U+2009) as the thousands separator — explicit thin-space char', input: '9\u2009480 m²', expected: 9480 },
    { name: 'money with plain-space thousands ("R 1 575 000")', input: 'R 1 575 000', expected: 1575000 },
    { name: 'money with NBSP thousands — explicit non-breaking space char', input: 'R 1\u00a0575\u00a0000', expected: 1575000 },
    { name: 'small value under 1000 (no separator at all — the case that already worked)', input: '63 m²', expected: 63 },
    { name: 'decimal value survives (a share percentage-shaped number, not currency)', input: '82.7397', expected: 82.7397 },
  ];

  console.log('');
  console.log('=== parseNumericValue() — unified money/area parser, thousands-separator fix ===');
  cases.forEach((c) => {
    const result = parseNumericValue(c.input);
    console.log('  input: ' + JSON.stringify(c.input) + '  ->  ' + JSON.stringify(result));
    check(`parseNumericValue — ${c.name}`, result === c.expected,
      `result=${JSON.stringify(result)} expected=${JSON.stringify(c.expected)}`);
  });

  // Fail-loud: real content that can't reduce to a clean number -> null,
  // never a truncated/guessed value.
  const garbage = parseNumericValue('TBC');
  check('parseNumericValue — fail-loud: unparseable non-blank value returns null (not a guess)',
    garbage === null,
    `result=${JSON.stringify(garbage)}`);

  // Silent (not a "failure"): genuinely blank/null input.
  const blank = parseNumericValue(null);
  check('parseNumericValue — genuinely blank input is silent null (not treated as a parse failure)',
    blank === null,
    `result=${JSON.stringify(blank)}`);
}

function runGpsComparison() {
  const oldParseGps = loadStandaloneFunction(PRE_FIX_FILE, 'parseGps');
  const newParseGps = loadStandaloneFunction(NEW_FILE, 'parseGps');

  const cases = [
    {
      name: 'real live sample (39 Bairn Street)',
      input: '30.391273°E   30.842466°S',
      oldExpected: { lat: null, lng: 30.842466 },
      newExpected: { lat: -30.842466, lng: 30.391273 },
    },
    {
      name: 'real live sample (Erf 668 MARINE DRIVE)',
      input: '30.398908°E 30.830085°S',
      oldExpected: { lat: null, lng: 30.830085 },
      newExpected: { lat: -30.830085, lng: 30.398908 },
    },
    {
      name: 'missing letters entirely',
      input: '30.391273, 30.842466',
      oldExpected: { lat: null, lng: 30.842466 },
      newExpected: { lat: null, lng: null },
    },
    {
      name: 'duplicate/ambiguous letter (two E, no S)',
      input: '30.391273°E   30.842466°E',
      oldExpected: { lat: null, lng: 30.842466 },
      newExpected: { lat: null, lng: null },
    },
    {
      name: 'garbage input',
      input: 'not a coordinate at all',
      oldExpected: { lat: null, lng: null },
      newExpected: { lat: null, lng: null },
    },
  ];

  console.log('');
  console.log('=== parseGps() OLD (pre-fix, positional) vs NEW (hemisphere-letter) ===');
  cases.forEach((c) => {
    const oldResult = oldParseGps(c.input);
    const newResult = newParseGps(c.input);
    console.log('  input: ' + JSON.stringify(c.input));
    console.log('    OLD -> ' + JSON.stringify(oldResult));
    console.log('    NEW -> ' + JSON.stringify(newResult));

    check(`GPS parser — OLD reproduces the documented bug: ${c.name}`,
      JSON.stringify(oldResult) === JSON.stringify(c.oldExpected),
      `old=${JSON.stringify(oldResult)} expected=${JSON.stringify(c.oldExpected)}`);
    check(`GPS parser — NEW is correct: ${c.name}`,
      JSON.stringify(newResult) === JSON.stringify(c.newExpected),
      `new=${JSON.stringify(newResult)} expected=${JSON.stringify(c.newExpected)}`);
  });
}

async function main() {
  runGpsComparison();
  runNumericParserTests();

  console.log('');
  console.log('=== Type coherence + hard fail-closed (defense-in-depth path) — NEW file ===');
  await testA_typeCoherenceDropsForeignResidue(NEW_FILE, 'NEW 3.6.0');
  await testC_sectionalWithOwnAddressNotDropped(NEW_FILE, 'NEW 3.6.0');
  await testD1_hardFailClosed_noAnchorAtAll(NEW_FILE, 'NEW 3.6.0');
  await testD2_hardFailClosed_contradictoryAnchor(NEW_FILE, 'NEW 3.6.0');

  console.log('=== DUAL-TEMPLATE SS<->FH — real 2026-08-19 incident (SEESKULP / Erf 668) ===');
  await testSStoFH_capturesVisibleNotHidden(NEW_FILE, 'NEW 3.6.0');
  await testFHtoSS_capturesVisibleNotHidden(NEW_FILE, 'NEW 3.6.0');

  console.log('=== Erf 668 edge case — municipality owner, no ID, no sale, Type "-", Extent "9 480 m²" ===');
  await testErf668_municipalityOwnerNoIdNoSale(NEW_FILE, 'NEW 3.6.1');

  console.log('=== Issue 1 — SEESKULP section 4, ten-entry title-deed list ===');
  await testSEESKULPS4_sourceRefShapeFix(NEW_FILE, 'NEW 3.6.1');
  await testSEESKULPS4_singleDeedBuildsSameRefShape(NEW_FILE, 'NEW 3.6.1');

  console.log('=== ownership_history_raw — spec §7.2/§7.3 (cc4, approved) ===');
  await testOwnershipHistoryRaw_sentVerbatimWhenMultiOwner(NEW_FILE, 'NEW 3.6.2');
  await testOwnershipHistoryRaw_absentWhenSingleOwner(NEW_FILE, 'NEW 3.6.2');
  await testRevealOwnerIdIfNeeded_clicksEveryIcon(NEW_FILE, 'NEW 3.6.2');

  console.log('=== Running against OLD file (pre-fix, v3.4.2 REGRESSION fixture) — Test E expected to FAIL (regression reproduction) ===');
  await testE_twoDistinctPropertiesInSequence(REGRESSION_FILE, 'REGRESSION 3.4.2', true);

  console.log('=== Running against OLD file (pre-fix, v3.4.3 fixture) — Test G expected to FAIL (bug reproduction) ===');
  await testG_entityOwnerNameKeptVerbatim(OLD_343_FILE, 'OLD 3.4.3', true);

  console.log('=== Running against NEW file (working tree, v3.6.0) — freehold-to-freehold, everything EXPECTED to PASS ===');
  await testE_twoDistinctPropertiesInSequence(NEW_FILE, 'NEW 3.6.0', false);
  await testG_entityOwnerNameKeptVerbatim(NEW_FILE, 'NEW 3.6.0', false);

  console.log('');
  let overallOk = true;
  for (const r of results) {
    const ok = r.pass === r.expectPass;
    console.log((ok ? 'OK  ' : 'FAIL') + '  ' + r.name + (r.pass ? ' -> true' : ' -> false') + (!ok ? '  [UNEXPECTED]' : '') + (r.detail ? '\n       ' + r.detail : ''));
    if (!ok) overallOk = false;
  }

  console.log('');
  console.log('Total checks: ' + results.length);
  if (overallOk) {
    console.log('ALL CHECKS OK.');
    process.exit(0);
  } else {
    console.log('SOME CHECKS FAILED — see [UNEXPECTED] lines above.');
    process.exit(1);
  }
}

main().catch((e) => { console.error(e); process.exit(1); });
