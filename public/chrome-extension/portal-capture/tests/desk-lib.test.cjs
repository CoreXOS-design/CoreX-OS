/**
 * CoreX Desk (extension) - unit tests for desk-lib.js: the allowed-site rule, the sensitive-field masking rules for third-party
 * sites, the frame codec (must round-trip through the SAME wire format the CoreX console decodes - resources/js/desk/frames.js),
 * and the Pusher framing helpers. Spec .ai/specs/corex-desk.md sections 7.6 and 13.
 *
 * Plain node, no jsdom, no network:   node public/chrome-extension/portal-capture/tests/desk-lib.test.cjs
 */
'use strict';

const assert = require('assert');
const path = require('path');
const fs = require('fs');
const vm = require('vm');

// The repo's package.json says "type": "module", which would make node read desk-lib.js as ESM (no `module`). The extension loads it as a
// classic script (importScripts / content script), so run it the same way: as a script, with a `module` to export into.
const sandbox = { module: { exports: {} }, console, TextEncoder, TextDecoder, Blob, Response, CompressionStream, DecompressionStream, btoa: (b) => Buffer.from(b, 'binary').toString('base64'), URL, Date, Math, crypto: require('crypto').webcrypto };
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(path.join(__dirname, '..', 'desk-lib.js'), 'utf8'), sandbox, { filename: 'desk-lib.js' });
const Lib = sandbox.module.exports;

// objects built inside the vm context have another realm's prototypes; compare them as plain JSON
const plain = (x) => JSON.parse(JSON.stringify(x));
let passed = 0;
async function test(name, fn) {
  try { await fn(); passed++; console.log('PASS ' + name); } catch (e) { console.log('FAIL ' + name + '\n  ' + (e && e.stack || e)); process.exitCode = 1; }
}

/** A minimal stand-in for a form control: attributes, labels, and an ancestor with data-desk-mask when asked. */
function field(attrs, opts) {
  opts = opts || {};
  return {
    nodeType: 1,
    type: attrs.type || 'text',
    getAttribute: (k) => (attrs[k] === undefined ? null : attrs[k]),
    labels: opts.label ? [{ textContent: opts.label }] : [],
    closest: (sel) => (sel === '[data-desk-mask]' && opts.masked ? {} : null),
  };
}

(async () => {
  await test('hostOf / tabKey', () => {
    assert.strictEqual(Lib.hostOf('https://WWW.CMAInfo.co.za/Mapping/x?a=1'), 'www.cmainfo.co.za');
    assert.strictEqual(Lib.hostOf('not a url'), '');
    assert.strictEqual(Lib.tabKey(42), 'x42');
  });

  await test('hostAllowed matches the host and its subdomains only', () => {
    const allowed = ['cmainfo.co.za', 'loom.com', 'myagency.co.za'];
    assert.ok(Lib.hostAllowed('www.cmainfo.co.za', allowed));
    assert.ok(Lib.hostAllowed('cmainfo.co.za', allowed));
    assert.ok(Lib.hostAllowed('LOOM.com', allowed));
    assert.ok(!Lib.hostAllowed('evilcmainfo.co.za', allowed));
    assert.ok(!Lib.hostAllowed('cmainfo.co.za.evil.test', allowed));
    assert.ok(!Lib.hostAllowed('mail.google.com', allowed));
    assert.ok(!Lib.hostAllowed('', allowed));
    assert.ok(!Lib.hostAllowed('loom.com', []));
  });

  await test('passwords and [data-desk-mask] are always masked', () => {
    assert.ok(Lib.isSensitiveField(field({ type: 'password', name: 'q' })));
    assert.ok(Lib.isSensitiveField(field({ type: 'text', name: 'q' }, { masked: true })));
    assert.ok(!Lib.isSensitiveField(field({ type: 'text', name: 'search' })));
    assert.ok(!Lib.isSensitiveField(null));
  });

  await test('card, ID, bank and secret looking inputs are masked by name, id, autocomplete, placeholder, aria-label and label text', () => {
    const sensitive = [
      field({ name: 'cardNumber' }), field({ id: 'cc-number' }), field({ autocomplete: 'cc-csc' }), field({ name: 'CVV' }),
      field({ name: 'iban' }), field({ placeholder: 'Account number' }), field({ 'aria-label': 'Branch code' }), field({ name: 'acc_no' }),
      field({ name: 'id_number' }), field({ name: 'passportNo' }), field({ name: 'f1' }, { label: 'South African ID number' }),
      field({ name: 'tax_ref' }), field({ autocomplete: 'one-time-code' }), field({ autocomplete: 'new-password' }), field({ name: 'otp' }),
    ];
    sensitive.forEach((f, i) => assert.ok(Lib.isSensitiveField(f), 'should mask #' + i));
    const fine = [
      field({ name: 'q', placeholder: 'Search address' }), field({ name: 'erf_number' }), field({ name: 'suburb' }),
      field({ name: 'price_from' }), field({ id: 'bedrooms' }), field({ name: 'f2' }, { label: 'Street name' }),
    ];
    fine.forEach((f, i) => assert.ok(!Lib.isSensitiveField(f), 'should NOT mask #' + i));
  });

  await test('camelCase, dob, bank name, branch code and hidden inputs are masked; ordinary words that merely contain a short term are not', () => {
    const sensitive = [
      field({ name: 'userIdNumber' }), field({ id: 'SAIdNumber' }), field({ name: 'idNumber' }), field({ name: 'customer_id_no' }), field({ name: 'dob' }),
      field({ name: 'dateOfBirth' }), field({ name: 'bank_name' }), field({ name: 'bankName' }), field({ name: 'branchCode' }), field({ placeholder: 'Branch code' }),
      field({ name: 'api_key' }), field({ name: 'apiKey' }), field({ name: 'csrf_token' }), field({ name: 'f' }, { label: 'Date of birth' }), field({ name: 'taxNumber' }),
      field({ name: 'vat_no' }), field({ name: 'PIN' }), field({ type: 'hidden', name: '_token' }),
    ];
    sensitive.forEach((f, i) => assert.ok(Lib.isSensitiveField(f), 'should mask #' + i));
    const fine = [
      field({ name: 'keyword' }), field({ name: 'monkey_count' }), field({ name: 'spinner' }), field({ name: 'syntax_note' }), field({ name: 'android_note' }),
      field({ name: 'suburb' }), field({ name: 'street_number' }), field({ name: 'idea' }), field({ name: 'first_name' }),
    ];
    fine.forEach((f, i) => assert.ok(!Lib.isSensitiveField(f), 'should NOT mask #' + i));
  });

  await test('urlAllowed: https only; the platform sites with subdomains; the agency website EXACTLY (no subdomains, no parent)', () => {
    const allowed = ['cmainfo.co.za', 'loom.com'];
    const exact = ['myagency.co.za', 'www.myagency.co.za'];
    assert.ok(Lib.urlAllowed('https://www.cmainfo.co.za/x', allowed, exact));
    assert.ok(!Lib.urlAllowed('http://www.cmainfo.co.za/x', allowed, exact), 'http is refused');
    assert.ok(Lib.urlAllowed('https://myagency.co.za/', allowed, exact));
    assert.ok(Lib.urlAllowed('https://www.myagency.co.za/', allowed, exact));
    assert.ok(!Lib.urlAllowed('https://mail.myagency.co.za/', allowed, exact), 'a subdomain of the agency site is not the agency site');
    assert.ok(!Lib.urlAllowed('https://co.za/', allowed, exact));
    assert.ok(!Lib.urlAllowed('not a url', allowed, exact));
    assert.ok(!Lib.hostAllowed('mail.myagency.co.za', allowed, exact));
  });

  await test('signed actions: a valid signature in order is accepted; a replay, a bad signature, a tampered payload, a wrong key and a missing key are not', async () => {
    const key = 'a'.repeat(64);
    const signed = await Lib.signAction(key, 1, { t: 'main', id: 'x1', type: 'click', s: '#a' });
    assert.ok(signed && signed.sig.length === 64 && signed.seq === 1);
    const ok = await Lib.verifyAction(key, signed, 0);
    assert.ok(ok.ok); assert.strictEqual(ok.action.type, 'click'); assert.strictEqual(ok.action.t, 'main');
    assert.strictEqual((await Lib.verifyAction(key, signed, 1)).ok, false, 'the same seq again (replay)');
    assert.strictEqual((await Lib.verifyAction(key, signed, 5)).ok, false, 'an older seq');
    assert.strictEqual((await Lib.verifyAction('b'.repeat(64), signed, 0)).ok, false, 'wrong key');
    assert.strictEqual((await Lib.verifyAction(key, { ...signed, p: signed.p.replace('#a', '#b') }, 0)).ok, false, 'tampered payload');
    assert.strictEqual((await Lib.verifyAction(key, { ...signed, seq: 2 }, 0)).ok, false, 'the seq is part of what is signed');
    assert.strictEqual((await Lib.verifyAction(key, { ...signed, sig: '0'.repeat(64) }, 0)).ok, false, 'bad signature');
    assert.strictEqual((await Lib.verifyAction(key, { seq: 1, p: '{}' }, 0)).ok, false, 'unsigned');
    assert.strictEqual((await Lib.verifyAction(key, null, 0)).ok, false);
    assert.strictEqual((await Lib.verifyAction('', signed, 0)).ok, false, 'no key');
    assert.strictEqual(await Lib.signAction('', 1, {}), null, 'no key: nothing is signed');
  });

  await test('signing fails closed without WebCrypto', async () => {
    const bare = { module: { exports: {} }, console, TextEncoder, TextDecoder, URL, Date, Math };
    vm.createContext(bare);
    vm.runInContext(fs.readFileSync(path.join(__dirname, '..', 'desk-lib.js'), 'utf8'), bare, { filename: 'desk-lib.js' });
    const L = bare.module.exports;
    assert.strictEqual(await L.signAction('a'.repeat(64), 1, { t: 'main' }), null);
    const v = await L.verifyAction('a'.repeat(64), { seq: 1, p: '{}', sig: 'a'.repeat(64) }, 0);
    assert.strictEqual(v.ok, false);
  });

  await test('the extension and the CoreX page sign the same way (resources/js/desk/sign.js verifies what desk-lib.js signed, and the reverse)', async () => {
    const mod = await import(require('url').pathToFileURL(path.join(__dirname, '..', '..', '..', '..', 'resources', 'js', 'desk', 'sign.js')).href);
    const key = 'c'.repeat(64);
    const fromLib = await Lib.signAction(key, 7, { t: 'x5', type: 'key', k: 'Enter' });
    const v = await mod.verifyAction(key, fromLib, 6);
    assert.ok(v.ok); assert.strictEqual(v.action.k, 'Enter');
    const fromPage = await mod.signAction(key, 8, { t: 'x5', type: 'click' });
    const v2 = await Lib.verifyAction(key, fromPage, 7);
    assert.ok(v2.ok); assert.strictEqual(v2.action.type, 'click');
  });

  await test('maskInputFn keeps ordinary text and stars out the rest (length preserved)', () => {
    assert.strictEqual(Lib.maskInputFn('hello', field({ name: 'q' })), 'hello');
    assert.strictEqual(Lib.maskInputFn('hunter2', field({ type: 'password' })), '*******');
    assert.strictEqual(Lib.maskInputFn('62012345678', field({ name: 'bank_account' })), '***********');
    assert.strictEqual(Lib.maskInputFn('', field({ type: 'password' })), '');
  });

  await test('encodeBatch round-trips the way the console decodes it (gzip + base64 + chunks <= 7000, tab key on every chunk)', async () => {
    const events = [];
    for (let i = 0; i < 400; i++) events.push({ type: 3, data: { source: 0, text: 'row ' + i + ' ' + 'x'.repeat(60) }, timestamp: 1000 + i });
    const chunks = await Lib.encodeBatch(events, 'e7', 'x7');
    assert.ok(chunks.length >= 1);
    chunks.forEach((c, i) => {
      assert.strictEqual(c.i, i); assert.strictEqual(c.n, chunks.length); assert.strictEqual(c.t, 'x7');
      assert.ok(c.d.length <= Lib.CHUNK); assert.strictEqual(c.id, chunks[0].id);
    });
    assert.strictEqual(chunks[0].z, 1, 'gzip is used when the runtime has CompressionStream');

    // decode exactly as resources/js/desk/frames.js does
    const bin = Buffer.from(chunks.map((c) => c.d).join(''), 'base64');
    const out = await new Response(new Blob([bin]).stream().pipeThrough(new DecompressionStream('gzip'))).arrayBuffer();
    assert.deepStrictEqual(JSON.parse(new TextDecoder().decode(new Uint8Array(out))), events);
  });

  await test('a big batch is split into several chunks and a tiny one into exactly one', async () => {
    const noise = Array.from({ length: 2000 }, (_, i) => ({ type: 2, data: { n: Math.random().toString(36).repeat(8), i }, timestamp: i }));
    assert.ok((await Lib.encodeBatch(noise, 'e1', 'x1')).length > 1);
    assert.strictEqual((await Lib.encodeBatch([{ type: 4, data: {}, timestamp: 1 }], 'e1', 'x1')).length, 1);
  });

  await test('batch ids are unique per batch and carry the sender tag', async () => {
    const a = await Lib.encodeBatch([{ a: 1 }], 'e9', 'x9');
    const b = await Lib.encodeBatch([{ a: 1 }], 'e9', 'x9');
    assert.notStrictEqual(a[0].id, b[0].id);
    assert.ok(a[0].id.startsWith('e9'));
  });

  await test('Pusher framing: channel name, socket url, frame parsing of server events and whispers', () => {
    assert.strictEqual(Lib.channelName(12), 'private-desk.session.12');
    assert.strictEqual(Lib.socketUrl({ scheme: 'https', host: 'qa.example.test', port: 443, key: 'abc' }), 'wss://qa.example.test/app/abc?protocol=7&client=corex-extension&version=1.1');
    assert.strictEqual(Lib.socketUrl({ scheme: 'http', host: '127.0.0.1', port: 8085, key: 'k' }), 'ws://127.0.0.1:8085/app/k?protocol=7&client=corex-extension&version=1.1');
    const server = Lib.parseFrame(JSON.stringify({ event: 'desk.highlight', channel: 'private-desk.session.1', data: JSON.stringify({ selector: '#a', note: null, tab: 'x3' }) }));
    assert.deepStrictEqual(plain(server.data), { selector: '#a', note: null, tab: 'x3' });
    const whisper = Lib.parseFrame(JSON.stringify({ event: 'client-pointer', channel: 'c', data: { t: 'x3', x: 0.5, y: 0.25 } }));
    assert.deepStrictEqual(plain(whisper.data), { t: 'x3', x: 0.5, y: 0.25 });
    assert.strictEqual(Lib.parseFrame('{"event":"pusher:ping","data":"{}"}').event, 'pusher:ping');
  });

  console.log('\n' + passed + ' passed' + (process.exitCode ? ', some FAILED' : ''));
})();
