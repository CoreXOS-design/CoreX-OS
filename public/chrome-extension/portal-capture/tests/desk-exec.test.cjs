/**
 * CoreX Desk phase 2 - unit tests for desk-exec.js, the executor that replays support's clicks / typing / keys / scrolling / choices on the
 * agent's page once the agent has pressed Allow (spec .ai/specs/corex-desk.md section 14).
 *
 * The contract under test: private fields are never touched, Desk's own UI is untouchable, a report line never contains a typed value, and each
 * action does what a person's action would do. Needs jsdom (not a project dependency):
 *     NODE_PATH=/mnt/HC_Volume_103099143/tools/jsdom-test/node_modules node public/chrome-extension/portal-capture/tests/desk-exec.test.cjs
 * Without jsdom it says so and exits 0 (the PHP tests still pin the contract).
 */
'use strict';

const fs = require('fs');
const path = require('path');
const assert = require('assert');

let JSDOM;
try { ({ JSDOM } = require('jsdom')); } catch (e) { console.log('jsdom not available - skipping (set NODE_PATH to a jsdom install)'); process.exit(0); }

const source = fs.readFileSync(path.join(__dirname, '..', 'desk-exec.js'), 'utf8');
const libSource = fs.readFileSync(path.join(__dirname, '..', 'desk-lib.js'), 'utf8');

let passed = 0;
async function test(name, fn) {
  try { await fn(); passed++; console.log('PASS ' + name); } catch (e) { console.log('FAIL ' + name + '\n  ' + (e && e.stack || e)); process.exitCode = 1; }
}

const PAGE = `<!doctype html><body>
<button id="save">Save contact</button>
<a id="lnk" href="#x">Open details</a>
<form id="f"><label for="suburb">Suburb</label><input id="suburb" name="suburb" value="">
<input id="pw" type="password" name="pw" value="">
<input id="card" name="cardNumber" value="">
<div data-desk-mask><input id="bank" name="bank" value=""></div>
<input id="chk" type="checkbox"><select id="sel"><option value="a">A</option><option value="b">B</option></select>
<textarea id="notes"></textarea><input id="after" name="after"><button id="sub" type="submit">Go</button></form>
<div id="hidden" style="display:none"><button id="hb">hidden</button></div>
<div id="corex-desk-bar"><button id="allow">Allow</button></div><div class="rr-block"><button id="blocked-ui">x</button></div>
<div id="scroller" style="overflow:auto;height:50px"><div style="height:500px">long</div></div>
</body>`;

function setup() {
  const dom = new JSDOM(PAGE, { runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;
  // jsdom has no layout: everything "has boxes" except what is display:none, and scrollBy does not exist.
  w.HTMLElement.prototype.getClientRects = function () { return this.closest('[style*="display:none"]') ? [] : [{}]; };
  w.Element.prototype.scrollBy = function (o) { this.__scrolled = (this.__scrolled || 0) + (o.top || 0); };
  w.Element.prototype.scrollIntoView = function () {};
  w.eval(libSource);
  w.eval(source);
  const Lib = w.CoreXDeskLib;
  const Exec = w.CoreXDeskExec;
  const blocked = (el) => Lib.isSensitiveField(el);
  const ex = Exec.create({ doc: w.document, win: w, isBlocked: blocked });
  const log = [];
  for (const id of ['save', 'lnk', 'sub']) w.document.getElementById(id).addEventListener('click', (e) => { log.push('click:' + id); if (id === 'sub') e.preventDefault(); });
  return { w, d: w.document, Exec, ex, log };
}

(async () => {
  await test('click: a button is really clicked and the report names it by its text, never a value', () => {
    const { ex, log } = setup();
    const r = ex.run({ type: 'click', s: '#save' });
    assert.ok(r.ok); assert.deepStrictEqual(log, ['click:save']);
    assert.strictEqual(r.report.label, 'Save contact'); assert.strictEqual(r.report.type, 'click'); assert.strictEqual(r.report.ok, true);
  });

  await test('click: a link, and a click found by position when the selector no longer matches', () => {
    const { ex, log, w, d } = setup();
    assert.ok(ex.run({ type: 'click', s: '#lnk' }).ok);
    d.elementFromPoint = () => d.getElementById('save');
    assert.ok(ex.run({ type: 'click', s: '#gone', x: 0.2, y: 0.3 }).ok);
    assert.deepStrictEqual(log, ['click:lnk', 'click:save']);
    assert.strictEqual(ex.run({ type: 'click', s: '#gone' }).reason, 'not_found', 'no position, no element -> not found');
  });

  await test('private fields are never clicked, typed into or changed (password, [data-desk-mask], card-looking names)', () => {
    const { ex, d } = setup();
    for (const id of ['pw', 'bank', 'card']) {
      for (const a of [{ type: 'click', s: '#' + id }, { type: 'focus', s: '#' + id }, { type: 'type', s: '#' + id, v: 'SECRETTEXT' }, { type: 'key', s: '#' + id, k: 'Backspace' }]) {
        const r = ex.run(a);
        assert.strictEqual(r.ok, false, id + ' ' + a.type);
        assert.strictEqual(r.reason, 'private_field');
        assert.ok(!JSON.stringify(r).includes('SECRETTEXT'), 'a refusal never echoes the text');
      }
      assert.strictEqual(d.getElementById(id).value, '', id + ' stays empty');
    }
  });

  await test('Desk\'s own UI cannot be pressed by support (Allow, anything inside .rr-block)', () => {
    const { ex, d } = setup();
    let pressed = false;
    d.getElementById('allow').addEventListener('click', () => { pressed = true; });
    assert.strictEqual(ex.run({ type: 'click', s: '#allow' }).reason, 'desk_ui');
    assert.strictEqual(ex.run({ type: 'click', s: '#blocked-ui' }).reason, 'desk_ui');
    assert.strictEqual(pressed, false);
  });

  await test('hidden and disabled things are refused', () => {
    const { ex, d } = setup();
    assert.strictEqual(ex.run({ type: 'click', s: '#hb' }).reason, 'hidden');
    d.getElementById('save').disabled = true;
    assert.strictEqual(ex.run({ type: 'click', s: '#save' }).reason, 'disabled');
  });

  await test('type: text goes into an ordinary field with an input event; a counted report has no text in it', () => {
    const { ex, d, w } = setup();
    let inputs = 0;
    d.getElementById('suburb').addEventListener('input', () => inputs++);
    const r = ex.run({ type: 'type', s: '#suburb', v: 'Uvongo', n: 6 });
    assert.ok(r.ok); assert.strictEqual(d.getElementById('suburb').value, 'Uvongo'); assert.ok(inputs >= 1);
    assert.strictEqual(r.report.n, 6); assert.strictEqual(r.report.label, 'Suburb');
    assert.ok(!JSON.stringify(r.report).includes('Uvongo'), 'the audit line never holds the typed text');
    ex.run({ type: 'type', s: '#suburb', v: ' Beach' });
    assert.strictEqual(d.getElementById('suburb').value, 'Uvongo Beach');
    assert.strictEqual(ex.run({ type: 'type', s: '#notes', v: 'line' }).ok, true);
    assert.strictEqual(d.getElementById('notes').value, 'line');
  });

  await test('type: not into a checkbox, a button or a plain div; capped at 500 characters', () => {
    const { ex, d } = setup();
    assert.strictEqual(ex.run({ type: 'type', s: '#chk', v: 'x' }).reason, 'not_a_text_field');
    assert.strictEqual(ex.run({ type: 'type', s: '#save', v: 'x' }).reason, 'not_a_text_field');
    ex.run({ type: 'type', s: '#after', v: 'a'.repeat(900) });
    assert.strictEqual(d.getElementById('after').value.length, 500);
  });

  await test('keys: Backspace deletes, Enter submits the form, Tab moves on, unknown keys are refused', () => {
    const { ex, d, w } = setup();
    ex.run({ type: 'type', s: '#suburb', v: 'Margate' });
    assert.ok(ex.run({ type: 'key', s: '#suburb', k: 'Backspace' }).ok);
    assert.strictEqual(d.getElementById('suburb').value, 'Margat');
    let submitted = 0;
    d.getElementById('f').requestSubmit = () => { submitted++; };
    assert.ok(ex.run({ type: 'key', s: '#suburb', k: 'Enter' }).ok);
    assert.strictEqual(submitted, 1);
    d.getElementById('suburb').focus();
    assert.ok(ex.run({ type: 'key', s: '#suburb', k: 'Tab' }).ok);
    assert.notStrictEqual(d.activeElement.id, 'suburb');
    assert.strictEqual(ex.run({ type: 'key', s: '#suburb', k: 'F5' }).reason, 'key_not_allowed');
    assert.strictEqual(ex.run({ type: 'key', s: '#suburb', k: 'Delete' }).ok, true);
  });

  await test('select: a dropdown takes a listed value and fires input + change; an unlisted value is refused', () => {
    const { ex, d } = setup();
    const events = [];
    ['input', 'change'].forEach((t) => d.getElementById('sel').addEventListener(t, () => events.push(t)));
    assert.ok(ex.run({ type: 'select', s: '#sel', v: 'b' }).ok);
    assert.strictEqual(d.getElementById('sel').value, 'b'); assert.deepStrictEqual(events, ['input', 'change']);
    assert.strictEqual(ex.run({ type: 'select', s: '#sel', v: 'zzz' }).reason, 'no_such_choice');
    assert.strictEqual(ex.run({ type: 'select', s: '#suburb', v: 'x' }).reason, 'not_a_dropdown');
  });

  await test('scroll: the page, or a scrolling box, by a clamped number of pixels', () => {
    const { ex, d } = setup();
    assert.ok(ex.run({ type: 'scroll', s: '', dy: 300 }).ok);
    assert.strictEqual((d.scrollingElement || d.documentElement).__scrolled, 300);
    assert.ok(ex.run({ type: 'scroll', s: '#scroller', dy: 99999 }).ok);
    assert.strictEqual(d.getElementById('scroller').__scrolled, 2000, 'clamped');
  });

  await test('unknown actions are refused', () => {
    const { ex } = setup();
    assert.strictEqual(ex.run({ type: 'eval', s: '#save' }).reason, 'unknown_action');
    assert.strictEqual(ex.run({}).ok, false);
  });

  await test('reporter: consecutive keystrokes and scrolls on one element become one counted line; clicks stay separate', async () => {
    const { Exec } = setup();
    const sent = [];
    const rep = Exec.createReporter((b) => sent.push(b), 20);
    const typed = (n) => ({ type: 'type', selector: '#a', label: 'Suburb', ok: true, n });
    rep.add(typed(3)); rep.add(typed(4)); rep.add({ type: 'click', selector: '#go', label: 'Go', ok: true, n: 1 }); rep.add(typed(2));
    await new Promise((r) => setTimeout(r, 60));
    assert.strictEqual(sent.length, 1);
    assert.deepStrictEqual(JSON.parse(JSON.stringify(sent[0].map((x) => [x.type, x.n]))), [['type', 7], ['click', 1], ['type', 2]]);
    rep.add({ type: 'scroll', selector: '', label: '', ok: true, n: 1 }); rep.add({ type: 'scroll', selector: '', label: '', ok: true, n: 1 }); rep.flush();
    assert.strictEqual(sent[1][0].n, 2);
    rep.add({ type: 'type', selector: '#pw', label: 'Password', ok: false, reason: 'private_field', n: 1 }); rep.add({ type: 'type', selector: '#pw', label: 'Password', ok: true, n: 1 }); rep.flush();
    assert.strictEqual(sent[2].length, 2, 'a refusal is never merged into something that ran');
  });

  await test('idle timer fires once when not touched, and touching keeps it alive', async () => {
    const { Exec } = setup();
    let idle = 0;
    const t = Exec.createIdleTimer(0.08, () => idle++);
    t.start();
    await new Promise((r) => setTimeout(r, 50)); t.touch();
    await new Promise((r) => setTimeout(r, 50)); assert.strictEqual(idle, 0, 'touched 50 ms ago');
    await new Promise((r) => setTimeout(r, 90)); assert.strictEqual(idle, 1);
    t.start(); t.stop(); await new Promise((r) => setTimeout(r, 120)); assert.strictEqual(idle, 1, 'stopped');
  });

  console.log('\n' + passed + ' passed' + (process.exitCode ? ', some FAILED' : ''));
})();
