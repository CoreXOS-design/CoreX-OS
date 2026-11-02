/**
 * CoreX - "Get full details" (3.16.0): the background flow for ONE advert page, with a stubbed chrome.* API.
 * Open a background tab, read, post to CoreX, close the tab, answer the page; the timeout path; one at a time; the guards.
 * No jsdom, no network:  node tests/read-advert.test.cjs
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

const REQ = { action: 'micReadAdvert', portal: 'p24', ref: 'P24-116908708', url: 'https://www.property24.com/for-sale/ramsgate/margate/kwazulu-natal/6359/116908708' };
const PAGE = { url: 'https://qatesting1.corexos.co.za/corex/market-intelligence' };

function boot(o) {
  o = Object.assign({ facts: { page_read: true, page_price: 1495000, page_heading: '3 Bedroom House', description: 'Lovely', photo_urls: ['https://x/1.jpg'] }, load: true, post: { ok: true, status: 200, body: { updated: 1, detail_unknown: 0, detail_unparsed: 0 } }, settings: { apiUrl: 'https://qatesting1.corexos.co.za', apiToken: 'tok' } }, o);
  const log = { created: [], removed: [], asked: [], posts: [], active: [], order: [] };
  let onMessage = null;
  const updated = [];
  let nextTab = 100;
  const chrome = {
    runtime: { lastError: undefined, onMessage: { addListener: (fn) => { onMessage = fn; } }, onInstalled: { addListener() {} }, onStartup: { addListener() {} }, sendMessage() {}, getManifest: () => ({ version: '3.16.1' }) },
    tabs: {
      onUpdated: { addListener: (fn) => updated.push(fn), removeListener: (fn) => { const i = updated.indexOf(fn); if (i >= 0) updated.splice(i, 1); } },
      onRemoved: { addListener() {} },
      create: (props, cb) => {
        const id = nextTab++;
        log.created.push(props); log.order.push('create'); log.active.push(props.active);
        setImmediate(() => cb({ id }));
        if (o.load) setImmediate(() => setImmediate(() => updated.slice().forEach((fn) => fn(id, { status: 'complete' }))));
      },
      remove: (id) => { log.removed.push(id); log.order.push('remove'); },
      sendMessage: (id, msg, cb) => { log.asked.push(msg.action); log.order.push('read'); setImmediate(() => cb({ facts: o.facts ? JSON.parse(JSON.stringify(o.facts)) : null })); },
    },
    storage: { local: { get: (k, cb) => { const r = {}; (Array.isArray(k) ? k : [k]).forEach((x) => { r[x] = o.settings[x]; }); cb && cb(r); return Promise.resolve(r); }, set: (v, cb) => { cb && cb(); return Promise.resolve(); }, remove: (k, cb) => { cb && cb(); } } },
    notifications: { create() {} }, scripting: { executeScript: () => Promise.resolve([]) },
    action: { setBadgeText() {}, setBadgeBackgroundColor() {} }, alarms: { create() {}, onAlarm: { addListener() {} } },
  };
  const sandbox = {
    chrome, console, URL, Date, Math, JSON, Promise, Array, Object, String, Number, RegExp, Error, Map, Set,
    setTimeout: (fn) => setImmediate(fn), clearTimeout() {},
    fetch: async (url, init) => { log.posts.push({ url, auth: init.headers.Authorization, body: JSON.parse(init.body) }); log.order.push('post'); return { ok: o.post.ok, status: o.post.status, json: async () => o.post.body, text: async () => 'denied' }; },
    crypto: { randomUUID: () => '11111111-1111-4111-8111-111111111111' },
    importScripts: (f) => { if (f === 'mic-lib.js') vm.runInContext(fs.readFileSync(path.join(EXT, f), 'utf8'), sandbox); },
    DOMParser: function () {}, indexedDB: undefined,
  };
  sandbox.self = sandbox; sandbox.globalThis = sandbox;
  vm.createContext(sandbox);
  vm.runInContext(fs.readFileSync(path.join(EXT, 'background.js'), 'utf8'), sandbox);
  vm.runInContext('READ_ADVERT_GAP_MS = 0; READ_ADVERT_LOAD_MS = 150;', sandbox);
  const ask = (req, sender) => new Promise((resolve) => { onMessage(req, sender || PAGE, resolve); });
  return { ask, log };
}

(async () => {
  await t('click: a background tab opens on the advert, it is read, posted as a detail-only read, and the tab is closed', async () => {
    const b = boot({});
    const r = await b.ask(REQ);
    assert.strictEqual(JSON.stringify(r), '{"ok":true}');
    assert.strictEqual(JSON.stringify(b.log.created), JSON.stringify([{ url: REQ.url, active: false }]), 'one tab, not the active one');
    assert.deepStrictEqual(b.log.order, ['create', 'read', 'post', 'remove'], 'open, read, post, close - in that order');
    assert.strictEqual(b.log.asked[0], 'getListingFacts');
    const p = b.log.posts[0];
    assert.match(p.url, /\/api\/prospecting\/import$/);
    assert.strictEqual(p.auth, 'Bearer tok');
    assert.strictEqual(p.body.search_context.detail_only, true);
    assert.strictEqual(p.body.listings.length, 1);
    assert.strictEqual(p.body.listings[0].portal_ref, 'P24-116908708');
    assert.strictEqual(p.body.listings[0].page_read, true);
    assert.strictEqual(p.body.listings[0].description, 'Lovely');
  });

  await t('a page that does not parse is not posted at all and the tab still closes', async () => {
    const b = boot({ facts: { page_read: false, unreadable: true } });
    const r = await b.ask(REQ);
    assert.strictEqual(r.ok, false); assert.strictEqual(r.reason, 'unreadable');
    assert.strictEqual(b.log.posts.length, 0);
    assert.strictEqual(b.log.removed.length, 1);
  });

  await t('timeout: a tab that never finishes loading is closed and the answer is "unreadable"', async () => {
    const b = boot({ load: false });
    const r = await b.ask(REQ);
    assert.strictEqual(r.ok, false); assert.strictEqual(r.reason, 'unreadable');
    assert.strictEqual(b.log.posts.length, 0);
    assert.strictEqual(b.log.removed.length, 1, 'tab closed after the timeout');
  });

  await t('guards: not another site, not another portal host, not set up, no permission', async () => {
    let b = boot({});
    assert.strictEqual((await b.ask(REQ, { url: 'https://evil.example/corex' })).reason, 'wrong_site');
    assert.strictEqual((await b.ask(Object.assign({}, REQ, { url: 'https://evil.example/for-sale/1' }))).reason, 'bad_request');
    assert.strictEqual((await b.ask(Object.assign({}, REQ, { url: 'http://www.property24.com/x/1' }))).reason, 'bad_request');
    assert.strictEqual((await b.ask(Object.assign({}, REQ, { ref: '' }))).reason, 'bad_request');
    assert.strictEqual(b.log.created.length, 0, 'no tab was opened for any of them');
    b = boot({ settings: {} });
    assert.strictEqual((await b.ask(REQ)).reason, 'not_set_up');
    b = boot({ post: { ok: false, status: 403, body: {} } });
    assert.strictEqual((await b.ask(REQ)).reason, 'no_permission');
    b = boot({ post: { ok: true, status: 200, body: { updated: 0, detail_unknown: 1 } } });
    assert.strictEqual((await b.ask(REQ)).reason, 'not_found');
  });

  await t('one at a time: a second click waits for the first to finish', async () => {
    const b = boot({});
    const [r1, r2] = await Promise.all([b.ask(REQ), b.ask(Object.assign({}, REQ, { ref: 'P24-2', url: REQ.url.replace('116908708', '2222222') }))]);
    assert.ok(r1.ok && r2.ok);
    assert.deepStrictEqual(b.log.order, ['create', 'read', 'post', 'remove', 'create', 'read', 'post', 'remove'], 'strictly one after the other');
  });

  await t('the bridge only answers its own window, and the manifest loads it on CoreX sites only', async () => {
    const src = fs.readFileSync(path.join(EXT, 'content-corex-bridge.js'), 'utf8');
    assert.ok(/ev\.source !== window \|\| ev\.origin !== window\.location\.origin/.test(src));
    const m = JSON.parse(fs.readFileSync(path.join(EXT, 'manifest.json'), 'utf8'));
    assert.strictEqual(m.version, '3.16.1');
    const cs = m.content_scripts.find((c) => c.js.includes('content-corex-bridge.js'));
    assert.ok(cs && cs.matches.every((x) => /corexos\.co\.za\/\*$/.test(x)));
  });

  console.log('\n' + passed + ' passed' + (process.exitCode ? ' (with failures)' : ''));
})();
