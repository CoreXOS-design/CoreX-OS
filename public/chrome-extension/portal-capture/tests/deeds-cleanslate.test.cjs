/**
 * CoreX — deeds-capture "true clean slate" regression harness (v3.4.2).
 *
 * Repro for Johan's bug: capturing a SECTIONAL (complex) property then a
 * FREEHOLD still showed the freehold with the previous complex's
 * scheme/section, because cmainfo never re-renders the sectional-only
 * fields (Scheme name/no, Section number, Flat/Unit no, Section extent,
 * Situated at) when the current property is a freehold — they sit frozen
 * in the live DOM. v3.4.0's fix (clear sectional fields when byte-identical
 * to the PREVIOUS capture) still misses the case where there is no previous
 * capture in THIS script instance to diff against (e.g. the extension was
 * reloaded mid-session — the page's DOM keeps its frozen sectional content
 * regardless, but the script's own module-level memory does not) — see
 * content-cmainfo.js's own v3.4.0 comment: "the very FIRST capture in a
 * page session has no previous capture to diff against ... isn't caught."
 * That is exactly what Test A below reproduces.
 *
 * Runs the REAL content-cmainfo.js source (unmodified — no test-only hooks)
 * inside a Node `vm` sandbox with a minimal mock DOM + chrome API, driving
 * extraction through the exact same chrome.runtime.onMessage('getDeedDetail')
 * entry point background.js/popup.js use in production.
 *
 * Usage: node tests/deeds-cleanslate.test.js
 * Exits 0 if every check passes (both the "old file must reproduce the bug"
 * check and the "new file must be fixed" checks), 1 otherwise.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { setFieldValue, buildCmaInfoDocument } = require('./mock-dom.cjs');

const OLD_FILE = path.join(__dirname, 'fixtures', 'content-cmainfo.v3.4.1.js');
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

/** Loads the real content script source into a fresh sandboxed vm context (fresh module-level state every call) bound to the given mock document/chrome. */
function loadContentScript(filePath, doc, chromeMock) {
  const src = fs.readFileSync(filePath, 'utf8');
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
    MutationObserver: class { observe() {} disconnect() {} },
    getComputedStyle: (elm) => ({ display: (elm && elm.style && elm.style.display) || '', color: (elm && elm._color) || 'rgb(51, 122, 183)' }),
    MouseEvent: class { constructor(type, opts) { this.type = type; Object.assign(this, opts || {}); } },
  };
  sandbox.window = { location: { href: 'https://www.cmainfo.co.za/Mapping/PropSearch.aspx' }, alert: () => {}, document: doc };
  vm.createContext(sandbox);
  vm.runInContext(src, sandbox, { filename: filePath });
  return sandbox;
}

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

async function main() {
  console.log('=== Running against OLD file (pre-fix, v3.4.1 fixture) — Test A is EXPECTED to FAIL (bug reproduction) ===');
  await testA_frozenFreehold_firstCaptureInInstance(OLD_FILE, 'OLD 3.4.1');

  console.log('=== Running against NEW file (working tree, v3.4.2) — everything EXPECTED to PASS ===');
  await testA_frozenFreehold_firstCaptureInInstance(NEW_FILE, 'NEW 3.4.2');
  await testB_consecutiveRealCaptures(NEW_FILE, 'NEW 3.4.2');
  await testC_legitimateSectionalWithAddress_noRegression(NEW_FILE, 'NEW 3.4.2');
  await testD_erfOnlyFallbackSignal(NEW_FILE, 'NEW 3.4.2');

  console.log('');
  let overallOk = true;
  for (const r of results) {
    const ok = r.pass === r.expectPass;
    console.log((ok ? 'OK  ' : 'FAIL') + '  ' + r.name + (r.pass ? ' -> true' : ' -> false') + (!ok ? '  [UNEXPECTED]' : '') + (r.detail ? '\n       ' + r.detail : ''));
    if (!ok) overallOk = false;
  }

  console.log('');
  if (overallOk) {
    console.log('ALL CHECKS OK — bug reproduced on 3.4.1, fixed on 3.4.2, no regressions.');
    process.exit(0);
  } else {
    console.log('SOME CHECKS FAILED — see [UNEXPECTED] lines above.');
    process.exit(1);
  }
}

main().catch((e) => { console.error(e); process.exit(1); });
