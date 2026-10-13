// AT-263 — Connection Guard behavioural sim (tests/js convention: no runner, node vm).
// Loads the ACTUAL shipped guard (public/js/corex-connection-guard.js) into a minimal
// DOM/browser sandbox and pins the per-family contract: which saves are gated, that
// offline BLOCKS a mutating write (the dino-killer), that reads/opt-outs pass, and that
// our own failure FAILS OPEN (never the reason a save is lost).
//
// Run:  node tests/js/connection-guard.mjs      (exit 0 = pass, 1 = fail)
// Structural half ("guard mounts on every authed screen, light removed"): PHPUnit
// tests/Feature/Session/ConnectionGuardMountTest.php.

import fs from 'fs';
import path from 'path';
import vm from 'vm';
import { fileURLToPath } from 'url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const code = fs.readFileSync(path.join(root, 'public/js/corex-connection-guard.js'), 'utf8');

let fails = 0;
const ok = (cond, msg) => { console.log((cond ? 'PASS ' : 'FAIL ') + msg); if (!cond) fails++; };

// ── minimal DOM/browser sandbox ──
function mockNode() {
  return {
    style: {}, dataset: {}, _attrs: {}, children: [],
    setAttribute(k, v) { this._attrs[k] = v; }, getAttribute(k) { return this._attrs[k] ?? null; },
    hasAttribute(k) { return k in this._attrs; }, appendChild(c) { this.children.push(c); return c; },
    addEventListener() {}, removeEventListener() {}, remove() {}, querySelector() { return null; },
    set innerHTML(v) { this._html = v; }, get innerHTML() { return this._html || ''; },
  };
}
class HTMLFormElement { constructor() { Object.assign(this, mockNode()); this.__q = {}; }
  getAttribute(k) { return this._attrs[k] ?? null; } setAttribute(k, v) { this._attrs[k] = v; }
  hasAttribute(k) { return k in this._attrs; } querySelector(sel) { return this.__q[sel] || null; }
  requestSubmit() { this.__requested = true; } submit() { this.__submitted = true; } }

const listeners = {};
const meta = { _c: 'PAGE-TOKEN', getAttribute(k) { return k === 'content' ? this._c : null; }, setAttribute(k, v) { if (k === 'content') this._c = v; } };
class FakeResponse { constructor(body, init) { this._b = body; this.status = (init && init.status) || 200; this.ok = this.status < 400; }
  json() { return Promise.resolve(JSON.parse(this._b)); } }
class FakeXHR {
  open(m, u, a) { this.opened = { m, u, a }; this.sent = false; }
  setRequestHeader(k, v) { (this.headers = this.headers || {})[k] = v; }
  send(b) { this.sent = true; this.body = b; }
  abort() {}
}
const nativeCalls = [];
const script = [];   // statuses the fake server answers, in order; the csrf-token GET answers with `nextToken`
let nextToken = 't';
const fakeNative = (url, init) => {
  nativeCalls.push({ url, init });
  if (String(url).indexOf('/api/v1/csrf-token') === 0) return Promise.resolve(new FakeResponse(JSON.stringify({ token: nextToken }), { status: nextToken ? 200 : 401 }));
  return Promise.resolve(new FakeResponse('{}', { status: script.shift() || 200 }));
};
const document = {
  _popup: null,
  querySelector(sel) { return sel === 'meta[name="csrf-token"]' ? meta : null; },
  hidden: false,
  getElementById(id) { return id === 'corex-conn-popup' ? this._popup : null; },
  createElement() { return mockNode(); },
  addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
  body: mockNode(),
};
const window = {
  fetch: fakeNative,
  addEventListener() {}, dispatchEvent() {}, showToast: null, XMLHttpRequest: FakeXHR,
};
const sandbox = {
  window, document, navigator: { onLine: true }, HTMLFormElement,
  setTimeout: () => 0, clearTimeout: () => {}, setInterval: () => 0,
  AbortController: class { constructor() { this.signal = {}; } abort() {} },
  Promise, console, CustomEvent: class {}, Response: FakeResponse, Date,
};
vm.createContext(sandbox);
vm.runInContext(code, sandbox);
const G = window.CoreXConnectionGuard;

ok(!!G, 'guard loads → window.CoreXConnectionGuard defined');
ok(window.CoreXSessionGuard === G, 'back-compat alias CoreXSessionGuard set (editors keep working)');

// ── Family: mutating classification (native forms) ──
const postForm = new HTMLFormElement(); postForm.setAttribute('method', 'POST');
const getForm  = new HTMLFormElement(); getForm.setAttribute('method', 'GET');
const delForm  = new HTMLFormElement(); delForm.setAttribute('method', 'POST'); delForm.__q['input[name="_method"]'] = { value: 'DELETE' };
ok(G._isMutatingForm(postForm) === true,  'POST form is mutating (gated)');
ok(G._isMutatingForm(getForm) === false,   'GET form is a read (NOT gated)');
ok(G._isMutatingForm(delForm) === true,    'POST + _method=DELETE is mutating (gated)');

// ── Family: opt-out ──
const blankForm = new HTMLFormElement(); blankForm.setAttribute('method', 'POST'); blankForm.setAttribute('target', '_blank');
const noGuard  = new HTMLFormElement(); noGuard.setAttribute('method', 'POST'); noGuard.setAttribute('data-noguard', '1');
ok(G._optedOut(blankForm) === true, 'target=_blank opts out');
ok(G._optedOut(noGuard) === true,   'data-noguard opts out');

// ── Family: preflight semantics (ping is the truth; fail-open) ──
const run = async () => {
  G.ping = () => Promise.resolve(true);   ok((await G.preflight()) === true,  'ping ok → save PROCEEDS');
  G.ping = () => Promise.resolve(null);   ok((await G.preflight()) === true,  'ping ERROR (unknown) → FAIL-OPEN, save proceeds');
  document._popup = null;
  G.ping = () => Promise.resolve(false);
  const blocked = await G.preflight();
  ok(blocked === false, 'ping offline → save BLOCKED');
  ok(document.body.children.length > 0, 'offline → disconnect popup shown');

  // ── Family: fetch wrapper — the offline-save-blocked case ──
  G.ping = () => Promise.resolve(false);
  let threw = null;
  try { await window.fetch('/x', { method: 'POST' }); } catch (e) { threw = e; }
  ok(threw && threw.coreXOffline === true, 'OFFLINE-SAVE-BLOCKED: mutating fetch offline throws coreXOffline');

  // GET read passes through even offline (not a save).
  const getRes = await window.fetch('/x', { method: 'GET' });
  ok(getRes && getRes.status === 200, 'GET read passes through (never gated)');

  // Explicit opt-out header passes through.
  const og = await window.fetch('/x', { method: 'POST', headers: { 'X-CoreX-NoGuard': '1' } });
  ok(og && og.status === 200, 'X-CoreX-NoGuard header opts a mutating fetch out');

  // ── Family: THE SAVE NEVER DIES ON A STALE TOKEN (13 Oct 2026, Sherry demo) ──
  G.ping = () => Promise.resolve(true);
  const G2 = G;
  nextToken = 'FRESH-TOKEN';

  meta._c = 'PAGE-TOKEN';
  script.push(200);
  let r = await window.fetch('/save', { method: 'POST', headers: { 'X-CSRF-TOKEN': 'STALE-RENDER-TOKEN', 'Accept': 'application/json' }, body: '{}' });
  const first = nativeCalls.filter(c => c.url === '/save')[0];
  ok(r.status === 200, 'a save with a good token goes through once');
  ok(first.init.headers['X-CSRF-TOKEN'] === 'PAGE-TOKEN', 'the stale token the page was rendered with is replaced by the live one on the way out');

  nativeCalls.length = 0; meta._c = 'OLD-LIVE'; script.push(419, 200); nextToken = 'FRESH-TOKEN';
  r = await window.fetch('/save', { method: 'POST', headers: { 'X-CSRF-TOKEN': 'x' }, body: '{}' });
  const saves = nativeCalls.filter(c => c.url === '/save');
  ok(saves.length === 2 && r.status === 200, '419 → token refreshed → the same save is sent ONCE more and succeeds');
  ok(saves[1].init.headers['X-CSRF-TOKEN'] === 'FRESH-TOKEN', 'the retry carries the freshly fetched token');
  ok(meta._c === 'FRESH-TOKEN', 'the page-wide token is updated for every later save');

  nativeCalls.length = 0; script.push(419, 419);
  r = await window.fetch('/save', { method: 'PUT', headers: { 'X-CSRF-TOKEN': 'x' }, body: '{}' });
  ok(nativeCalls.filter(c => c.url === '/save').length === 2 && r.status === 419, 'a second 419 is NOT retried again (one retry only)');
  ok((await r.json()).message === G2.EXPIRED_MESSAGE, '...and the caller gets a plain message, not a raw error, so its screen can keep the work');

  nativeCalls.length = 0; script.push(419); nextToken = null;   // the session is really gone: the token endpoint answers 401
  r = await window.fetch('/save', { method: 'POST', headers: {}, body: '{}' });
  ok(nativeCalls.filter(c => c.url === '/save').length === 1 && r.status === 419 && (await r.json()).message.indexOf('session has ended') >= 0,
     'dead session (token refresh refused) → no pointless retry, plain "session has ended" message');
  nextToken = 'FRESH-TOKEN';

  nativeCalls.length = 0; script.push(200);
  await window.fetch('https://example.com/other', { method: 'POST', headers: { 'X-CSRF-TOKEN': 'x' } });
  ok(nativeCalls[0].init.headers['X-CSRF-TOKEN'] === 'x', 'a request to ANOTHER origin never gets our token');

  // XHR (photo uploads): the live token is fetched just before the send; the stale one set by the caller is never used
  nativeCalls.length = 0; meta._c = 'OLD-LIVE'; nextToken = 'UPLOAD-FRESH';
  const x = new FakeXHR();
  x.open('POST', '/corex/rental-inspections/1/photos');
  x.setRequestHeader('X-CSRF-TOKEN', 'STALE-RENDER-TOKEN');
  x.setRequestHeader('Accept', 'application/json');
  x.send('form-data');
  ok(x.sent === false, 'XHR upload: the send waits for the token refresh');
  await new Promise(res => setImmediate(res)); await new Promise(res => setImmediate(res));
  ok(x.sent === true && x.headers['X-CSRF-TOKEN'] === 'UPLOAD-FRESH' && x.headers['Accept'] === 'application/json', 'XHR upload: sent with the fresh token, caller headers kept');
  const g = new FakeXHR(); g.open('GET', '/read'); g.send(); ok(g.sent === true, 'XHR GET is untouched (sent at once)');
  const ab = new FakeXHR(); ab.open('POST', '/u'); ab.send('x'); ab.abort(); await new Promise(res => setImmediate(res)); await new Promise(res => setImmediate(res));
  ok(ab.sent === false, 'XHR aborted before the refresh finished is not sent afterwards');

  // native forms: the hidden _token is synced to the live token on submit
  const tokenInput = { value: 'OLD' };
  const f = { querySelector: (sel) => (sel === 'input[name="_token"]' ? tokenInput : null) }; meta._c = 'LIVE';
  G2._syncFormToken(f);
  ok(tokenInput.value === 'LIVE', 'native form: stale hidden _token is replaced by the live one');

  console.log(fails === 0 ? '\nALL PASS' : `\n${fails} FAIL(S)`);
  process.exit(fails === 0 ? 0 : 1);
};
run();
