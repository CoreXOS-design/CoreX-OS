/**
 * CoreX - MIC capture run, background worker (3.15.2 / 3.16.0): a search run reads the search-results pages and then STOPS.
 * background.js is run in a vm with a stubbed chrome.* API; every tab navigation and every message to a page is recorded.
 * No jsdom, no network:  node tests/background-run.test.cjs
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert');

const EXT = path.join(__dirname, '..');
let passed = 0;
function t(name, fn) {
  return Promise.resolve().then(fn).then(() => { passed++; console.log('  ok  ' + name); })
    .catch((e) => { console.error('FAIL  ' + name + '\n      ' + (e && e.stack || e)); process.exitCode = 1; });
}

function runSearch(opts) {
  const nav = [];       // every tab navigation
  const toPage = [];    // every message sent to a page
  const posts = [];     // every POST to CoreX
  let onMessage = null;
  const updatedListeners = [];
  const store = {};
  const chrome = {
    runtime: {
      lastError: undefined,
      onMessage: { addListener: (fn) => { onMessage = fn; } },
      onInstalled: { addListener: () => {} },
      onStartup: { addListener: () => {} },
      sendMessage: () => {},
      getManifest: () => ({ version: '3.16.0' }),
    },
    tabs: {
      onUpdated: { addListener: (fn) => updatedListeners.push(fn), removeListener: (fn) => { const i = updatedListeners.indexOf(fn); if (i >= 0) updatedListeners.splice(i, 1); } },
      onRemoved: { addListener: () => {} },
      update: (tabId, props) => {
        if (props && props.url) { nav.push(props.url); setImmediate(() => updatedListeners.slice().forEach((fn) => fn(tabId, { status: 'complete' }))); }
      },
      sendMessage: (tabId, msg, cb) => {
        toPage.push(msg.action);
        if (msg.action === 'getListings') {
          const n = ++chrome._page;
          return setImmediate(() => cb({ listings: [1, 2].map((i) => ({ portal_ref: 'P24-' + n + '00' + i, portal_url: 'https://www.property24.com/for-sale/x/y/kwazulu-natal/1/' + n + '00' + i, price: 1000000, suburb: 'Uvongo' })) }));
        }
        if (msg.action === 'getPageInfo') return setImmediate(() => cb({ totalPages: opts.pages }));
        setImmediate(() => cb({}));
      },
    },
    storage: { local: { get: (k, cb) => { const r = {}; (Array.isArray(k) ? k : [k]).forEach((x) => { r[x] = store[x]; }); cb && cb(r); return Promise.resolve(r); }, set: (o, cb) => { Object.assign(store, o); cb && cb(); return Promise.resolve(); }, remove: (k, cb) => { cb && cb(); return Promise.resolve(); } } },
    notifications: { create: () => {} },
    scripting: { executeScript: () => Promise.resolve([]) },
    action: { setBadgeText: () => {}, setBadgeBackgroundColor: () => {} },
    alarms: { create: () => {}, onAlarm: { addListener: () => {} } },
    _page: 0,
  };
  const sandbox = {
    chrome, console, URL, Date, Math, JSON, Promise, Array, Object, String, Number, RegExp, Error, Map, Set,
    setTimeout: (fn) => setImmediate(fn), clearTimeout: () => {},
    fetch: async (url, init) => { posts.push({ url, body: init && init.body ? JSON.parse(init.body) : null }); return { ok: true, status: 200, json: async () => ({ imported: 2, updated: 0 }), text: async () => '' }; },
    crypto: { randomUUID: () => '11111111-1111-4111-8111-111111111111' },
    importScripts: (f) => { if (f === 'mic-lib.js') vm.runInContext(fs.readFileSync(path.join(EXT, f), 'utf8'), sandbox); },
    DOMParser: function () { this.parseFromString = () => ({ querySelectorAll: () => [], querySelector: () => null }); },
    indexedDB: undefined,
  };
  sandbox.self = sandbox;
  sandbox.globalThis = sandbox;
  vm.createContext(sandbox);
  vm.runInContext(fs.readFileSync(path.join(EXT, 'background.js'), 'utf8'), sandbox);
  assert.ok(onMessage, 'background.js registered its message handler');

  return new Promise((resolve) => {
    onMessage({
      action: 'startCapture', portal: 'p24', baseUrl: 'https://www.property24.com/for-sale/uvongo/margate/kwazulu-natal/6359',
      searchTerm: 'Uvongo', totalPages: opts.pages, totalResults: opts.pages * 20, apiUrl: 'https://qatesting1.corexos.co.za', apiToken: 'x', tabId: 7,
      readAdverts: opts.tick === true,
    }, {}, () => {});
    const started = Date.now();
    const poll = () => {
      onMessage({ action: 'getCaptureStatus' }, {}, (st) => {
        if (st && st.complete) return setTimeout0(() => resolve({ nav, toPage, posts, st }));
        if (Date.now() - started > 8000) return resolve({ nav, toPage, posts, st, timedOut: true });
        setImmediate(poll);
      });
    };
    const setTimeout0 = (fn) => global.setTimeout(fn, 50);
    poll();
  });
}

(async () => {
  await t('a search run reads the search pages and then stops: no advert page is opened, whatever the tick or the server says', async () => {
    for (const tick of [false, true]) {
      const r = await runSearch({ pages: 3, tick });
      assert.ok(!r.timedOut, 'the run finished');
      if (process.env.SHOW) console.log(JSON.stringify({ tick, nav: r.nav, toPage: r.toPage, posts: r.posts.map((p) => p.body.listings.length) }));
      const before = r.nav.length;
      await new Promise((res) => global.setTimeout(res, 300));
      assert.strictEqual(r.nav.length, before, 'nothing navigates after the run is complete');
      // page 1 is the user's own page; pages 2 and 3 are navigated to - nothing else, and never an advert address
      assert.ok(r.nav.length >= 2 && r.nav.length <= 3, 'search pages only: ' + r.nav.join(' | '));
      r.nav.forEach((u) => assert.match(u, /\/6359(\/p\d+)?$|[?&]Page=\d+|\/p\d+$/i, 'a search-results address: ' + u));
      assert.ok(!r.nav.some((u) => /\/\d{8,9}$/.test(u)), 'no individual advert address was opened');
      assert.ok(!r.toPage.includes('getListingFacts'), 'no advert page was read');
      assert.ok(r.posts.length >= 1 && r.posts.every((p) => /\/api\/prospecting\/import$/.test(p.url) && !(p.body.search_context && p.body.search_context.detail_only)), 'only search-capture batches were posted');
    }
  });

  await t('the extension has no advert-page step left: no detail phase, no tick box, no needs_detail handling', async () => {
    const bg = fs.readFileSync(path.join(EXT, 'background.js'), 'utf8');
    for (const gone of ['runDetailPhase', 'postDetailBatch', 'needsDetail', 'needs_detail', 'detailCap', 'readAdverts', 'photoFingerprint']) {
      assert.ok(!bg.includes(gone), gone + ' is gone from background.js');
    }
    // 3.16.0: the single-advert reader is reachable ONLY from the agent's own click (micReadAdvert), never from the search run
    const body = bg.slice(bg.indexOf('async function runReadAdvert'), bg.indexOf('function ppServerVariablesFn'));
    assert.strictEqual(bg.split('getListingFacts').length - 1, 1, 'getListingFacts is asked for in exactly one place');
    assert.ok(body.includes('getListingFacts'), '...and that place is the button flow');
    const html = fs.readFileSync(path.join(EXT, 'popup.html'), 'utf8');
    assert.ok(!/readAdverts|Also read new advert pages/.test(html), 'no tick box in the popup');
    assert.ok(!/readAdverts|detailMessage|detailPhase/.test(fs.readFileSync(path.join(EXT, 'popup.js'), 'utf8')));
  });

  console.log('\n' + passed + ' passed' + (process.exitCode ? ' (with failures)' : ''));
})();
