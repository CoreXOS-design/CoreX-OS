/**
 * CoreX - one tab, three searches (3.16.1): P24 run -> PP run -> P24 run without reopening the tab (Johan, 2 Nov: "did a search in a tab - p24. imported. once done
 * in the same tab did the pp search and clicked the button. kept going imported. closed the tab, opened a new tab for pp and then only did it start to work").
 *
 * Cause: the background keeps ONE capture record and a finished run stays in it as "complete". The popup showed "capturing" at the click, then sent the queued batches
 * BEFORE telling the background to start, and polled the record meanwhile - so it read the LAST run's "complete" and showed ITS numbers; the new run was never followed.
 * (A new tab only worked because the idle service worker had been unloaded by then, wiping the record.)
 *
 * background.js runs in a vm with a stubbed chrome.* API and ONE tab; the popup's side is played here exactly as popup.js does it (run id, a busy flush, polling through
 * run-status.js).  node tests/run-sequence.test.cjs
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert');

const EXT = path.join(__dirname, '..');
const rsBox = { self: null }; rsBox.self = rsBox;
vm.runInNewContext(fs.readFileSync(path.join(EXT, 'run-status.js'), 'utf8'), rsBox);
const RunStatus = rsBox.CorexRunStatus;
let passed = 0;
function t(name, fn) {
  return Promise.resolve().then(fn).then(() => { passed++; console.log('  ok  ' + name); })
    .catch((e) => { console.error('FAIL  ' + name + '\n      ' + (e && e.stack || e)); process.exitCode = 1; });
}

// the popup as it was in 3.16.0 - kept here so the test shows the bug it fixes
const oldDecide = (status) => (status.active ? 'capturing' : status.complete ? 'complete' : (status.error && !status.active) ? 'error' : 'ignore');

function boot() {
  const nav = [];
  let onMessage = null;
  const updated = [];
  const store = {};
  let pageCounter = 0;
  const TAB = 7;
  const chrome = {
    runtime: { lastError: undefined, onMessage: { addListener: (fn) => { onMessage = fn; } }, onInstalled: { addListener() {} }, onStartup: { addListener() {} }, sendMessage() {}, getManifest: () => ({ version: '3.16.1' }) },
    tabs: {
      onUpdated: { addListener: (fn) => updated.push(fn), removeListener: (fn) => { const i = updated.indexOf(fn); if (i >= 0) updated.splice(i, 1); } },
      onRemoved: { addListener() {} },
      update: (tabId, props) => { if (props && props.url) { nav.push(tabId + ' ' + props.url); setImmediate(() => updated.slice().forEach((fn) => fn(tabId, { status: 'complete' }))); } },
      sendMessage: (tabId, msg, cb) => {
        if (msg.action === 'getListings') {
          const n = ++pageCounter;
          const pp = chrome._portal === 'pp';
          return setImmediate(() => cb({ listings: [1, 2].map((i) => pp
            ? { portal_ref: 'PP-T' + n + '00' + i, portal_url: 'https://www.privateproperty.co.za/for-sale/x/y/' + n + '00' + i, price: 1200000, suburb: 'Margate' }
            : { portal_ref: 'P24-' + n + '00' + i, portal_url: 'https://www.property24.com/for-sale/x/y/kwazulu-natal/1/' + n + '00' + i, price: 1000000, suburb: 'Uvongo' }) }));
        }
        setImmediate(() => cb({}));
      },
    },
    storage: { local: { get: (k, cb) => { const r = {}; (Array.isArray(k) ? k : [k]).forEach((x) => { r[x] = store[x]; }); cb && cb(r); return Promise.resolve(r); }, set: (o, cb) => { Object.assign(store, o); cb && cb(); return Promise.resolve(); }, remove: (k, cb) => { cb && cb(); return Promise.resolve(); } } },
    notifications: { create() {} }, scripting: { executeScript: () => Promise.resolve([]) }, action: { setBadgeText() {}, setBadgeBackgroundColor() {} }, alarms: { create() {}, onAlarm: { addListener() {} } },
    _portal: 'p24',
  };
  const posts = [];
  const sandbox = {
    chrome, console, URL, Date, Math, JSON, Promise, Array, Object, String, Number, RegExp, Error, Map, Set,
    setTimeout: (fn) => setImmediate(fn), clearTimeout() {},
    fetch: async (url, init) => { const b = init && init.body ? JSON.parse(init.body) : null; posts.push(b); return { ok: true, status: 200, json: async () => ({ imported: b ? b.listings.length : 0, updated: 0, success: true }) }; },
    crypto: { randomUUID: (() => { let n = 0; return () => '00000000-0000-4000-8000-' + String(++n).padStart(12, '0'); })() },
    importScripts: (f) => { if (f === 'mic-lib.js') vm.runInContext(fs.readFileSync(path.join(EXT, f), 'utf8'), sandbox); },
    DOMParser: function () {}, indexedDB: undefined,
  };
  sandbox.self = sandbox; sandbox.globalThis = sandbox;
  vm.createContext(sandbox);
  vm.runInContext(fs.readFileSync(path.join(EXT, 'background.js'), 'utf8'), sandbox);
  const ask = (msg) => new Promise((resolve) => onMessage(msg, {}, resolve));
  return { ask, nav, posts, TAB, setPortal: (p) => { chrome._portal = p; } };
}

// one click of "Capture" in a popup, played as popup.js plays it. `decide` is the popup's rule; `flushPolls` = polls that happen while the queued batches are being sent
async function clickCapture(bg, portal, runId, decide, flushPolls) {
  bg.setPortal(portal);
  const seenBeforeStart = [];
  for (let i = 0; i < flushPolls; i++) seenBeforeStart.push(decide(await bg.ask({ action: 'getCaptureStatus' }), runId));   // the popup polls while it flushes
  const base = portal === 'pp' ? 'https://www.privateproperty.co.za/for-sale/margate/kwazulu-natal/3' : 'https://www.property24.com/for-sale/uvongo/margate/kwazulu-natal/6359';
  bg.ask({ action: 'startCapture', portal, baseUrl: base, searchTerm: portal === 'pp' ? 'Margate' : 'Uvongo', totalPages: 2, totalResults: 4, apiUrl: 'https://qatesting1.corexos.co.za', apiToken: 'x', tabId: bg.TAB, runId });
  let verdict = 'ignore', status = null, started = Date.now();
  while (verdict !== 'complete') {
    await new Promise((r) => setImmediate(r));
    status = await bg.ask({ action: 'getCaptureStatus' });
    verdict = decide(status, runId);
    if (Date.now() - started > 8000) throw new Error('the popup never saw the run complete: ' + JSON.stringify(status));
  }
  return { status, seenBeforeStart };
}

(async () => {
  await t('the 3.16.0 popup rule read the LAST run as complete while the next one was waiting to start (the bug)', async () => {
    const bg = boot();
    const first = await clickCapture(bg, 'p24', 'A', (s) => oldDecide(s), 0);
    assert.strictEqual(first.status.portal, 'p24');
    // same tab, now on a Private Property search: the click, then the flush, with the poller already running
    bg.setPortal('pp');
    const stale = oldDecide(await bg.ask({ action: 'getCaptureStatus' }));
    assert.strictEqual(stale, 'complete', 'the old rule shows the finished P24 run as the answer to the PP click');
    const pp = await clickCapture(bg, 'pp', 'B', (s) => oldDecide(s), 3);
    assert.ok(pp.seenBeforeStart.includes('complete'), 'while the PP click waited to start, the old popup was told "complete" (with the P24 numbers)');
  });

  await t('3.16.1: a status that is not this popup\'s own run is ignored', () => {
    assert.strictEqual(RunStatus.decide({ active: false, complete: true, runId: 'A' }, 'B'), 'ignore');
    assert.strictEqual(RunStatus.decide({ active: true, runId: 'B' }, 'B'), 'capturing');
    assert.strictEqual(RunStatus.decide({ active: false, complete: true, runId: 'B' }, 'B'), 'complete');
    assert.strictEqual(RunStatus.decide({ active: false, complete: false, error: 'Capture failed', runId: 'B' }, 'B'), 'error');
    assert.strictEqual(RunStatus.decide({ active: false, complete: true, runId: 'A' }, 'B'), 'ignore');
    assert.strictEqual(RunStatus.decide({ active: true, runId: 'A' }, null), 'capturing', 'a popup opened onto a run that is going follows it');
    assert.strictEqual(RunStatus.decide(null, 'B'), 'ignore');
  });

  await t('one tab: P24 run -> PP run -> P24 run, each seen through to its own result, nothing stale shown in between', async () => {
    const bg = boot();
    const out = [];
    for (const [i, portal] of [[1, 'p24'], [2, 'pp'], [3, 'p24']]) {
      const run = await clickCapture(bg, portal, 'run-' + i, RunStatus.decide, 4);
      out.push(run);
      assert.ok(run.seenBeforeStart.every((v) => v === 'ignore'), `run ${i}: while the click waits to start, the previous run's record is ignored (${run.seenBeforeStart})`);
      assert.strictEqual(run.status.runId, 'run-' + i, `run ${i}: the result is this run's`);
      assert.strictEqual(run.status.portal, portal, `run ${i}: it is the ${portal} run`);
      assert.strictEqual(run.status.tabId, bg.TAB, 'the same tab every time');
      assert.strictEqual(run.status.complete, true);
      assert.strictEqual(run.status.active, false);
      assert.strictEqual(run.status.importedCount, 4, `run ${i}: its own count (2 pages x 2 adverts), not the previous run's`);
    }
    // what each run sent: the right portal, never mixed
    const sources = bg.posts.filter((p) => p && p.listings).map((p) => p.source);
    assert.deepStrictEqual(sources.slice(0, 1), ['p24']);
    assert.ok(sources.includes('pp'), 'the PP run posted as pp');
    assert.strictEqual(bg.nav.filter((n) => /privateproperty/.test(n)).length > 0, true, 'the PP pages were opened in the same tab');
    assert.ok(bg.nav.every((n) => n.startsWith('7 ')), 'every navigation was in the one tab');
  });

  await t('the P24 and PP pages of the SAME tab are each read by the content script of that page (detection is per page, not per tab)', () => {
    const m = JSON.parse(fs.readFileSync(path.join(EXT, 'manifest.json'), 'utf8'));
    const hosts = (js) => m.content_scripts.filter((c) => c.js.includes(js)).flatMap((c) => c.matches);
    assert.ok(hosts('content-p24-detail.js').every((h) => /property24\.com/.test(h)));
    assert.ok(hosts('content-pp.js').every((h) => /privateproperty\.co\.za/.test(h)));
    const popup = fs.readFileSync(path.join(EXT, 'popup.js'), 'utf8');
    assert.ok(/const portal = detectPortal\(tab\.url\)/.test(popup), 'the popup works the portal out of the tab\'s CURRENT address every time it opens');
    const html = fs.readFileSync(path.join(EXT, 'popup.html'), 'utf8');
    assert.ok(html.indexOf('<script src="run-status.js">') > -1 && html.indexOf('<script src="run-status.js">') < html.indexOf('<script src="popup.js">'), 'run-status.js is loaded before popup.js');
  });

  console.log('\n' + passed + ' passed' + (process.exitCode ? ' (with failures)' : ''));
})();
