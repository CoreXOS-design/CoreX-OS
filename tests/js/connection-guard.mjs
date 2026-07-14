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
const document = {
  _popup: null,
  getElementById(id) { return id === 'corex-conn-popup' ? this._popup : null; },
  createElement() { return mockNode(); },
  addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
  body: mockNode(),
};
const window = {
  fetch: () => Promise.resolve({ status: 200, json: () => Promise.resolve({ token: 't' }) }),
  addEventListener() {}, dispatchEvent() {}, showToast: null,
};
const sandbox = {
  window, document, navigator: { onLine: true }, HTMLFormElement,
  setTimeout: () => 0, clearTimeout: () => {}, setInterval: () => 0,
  AbortController: class { constructor() { this.signal = {}; } abort() {} },
  Promise, console, CustomEvent: class {},
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

  console.log(fails === 0 ? '\nALL PASS' : `\n${fails} FAIL(S)`);
  process.exit(fails === 0 ? 0 : 1);
};
run();
