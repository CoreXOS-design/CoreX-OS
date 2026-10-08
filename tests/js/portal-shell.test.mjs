// .ai/specs/rental-portal-access.md §21 — runs the tenant / owner portal page's OWN script (resources/views/rentals/portal/shell.blade.php)
// in Node with a stub browser, so the parts that cannot be seen from PHP are tested: several photos (add, dedupe, limit, remove),
// one press = one request, the done state, retry after a failure, the inline lease details and the fault-type step logic.
//   node --test tests/js/portal-shell.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const blade = fs.readFileSync(path.join(here, '../../resources/views/rentals/portal/shell.blade.php'), 'utf8');
const script = blade.slice(blade.lastIndexOf('<script>') + 8, blade.lastIndexOf('</script>')).replace('@json($branding ?? null)', 'null');

/** A stub browser; `calls` records every network request. */
function boot({ fetchImpl } = {}) {
  const calls = { xhr: [], fetch: [] };
  const urls = new Set();
  class FakeXHR {
    constructor() { this.upload = {}; this.headers = {}; calls.xhr.push(this); }
    open(method, url) { this.method = method; this.url = url; }
    setRequestHeader(k, v) { this.headers[k] = v; }
    send(body) { this.body = body; if (FakeXHR.auto) setTimeout(() => this.finish(FakeXHR.status, FakeXHR.reply), 5); }
    finish(status, reply) { this.status = status; this.responseText = JSON.stringify(reply); if (status === 0) this.onerror(); else this.onload(); }
  }
  FakeXHR.auto = true; FakeXHR.status = 201; FakeXHR.reply = { fault_report: { id: 7 } };
  const sandbox = {
    window: { location: { search: '' }, crypto: null, confirm: () => true },
    document: { cookie: 'XSRF-TOKEN=tok', createElement: () => ({}) },
    fetch: async (url, opts) => { calls.fetch.push({ url, opts }); return fetchImpl ? fetchImpl(url, opts) : { ok: true, status: 200, json: async () => ({}) }; },
    XMLHttpRequest: FakeXHR, URL: Object.assign(function () {}, { createObjectURL: () => { const u = 'blob:' + urls.size; urls.add(u); return u; }, revokeObjectURL: (u) => urls.delete(u) }),
    File, FormData, URLSearchParams, setTimeout, Date, Math, console, alert: () => {}, JSON, Promise, Array, Object, String, Number, Error, parseInt,
  };
  sandbox.window.URL = sandbox.URL;
  vm.createContext(sandbox);
  vm.runInContext(script + '\nthis.__portal = rentalsPortal();', sandbox);
  const p = sandbox.__portal;
  p.photoLimits = { max_photos: 3, max_photo_mb: 1 };
  return { p, calls, FakeXHR, urls };
}

const img = (name, size = 1000, mod = 1) => new File([new Uint8Array(size)], name, { type: 'image/jpeg', lastModified: mod });
const pick = (...files) => ({ target: { files, value: 'x' } });
const settle = () => new Promise((r) => setTimeout(r, 30));

test('each pick ADDS to the photos: camera then gallery then gallery again keeps all of them', async () => {
  const { p } = boot();
  const w = p.faultWizard;
  await p.addPhotos(w, pick(img('cam.jpg', 100, 1)));
  await p.addPhotos(w, pick(img('g1.jpg', 100, 2), img('g2.jpg', 100, 3)));
  assert.equal(w.photos.length, 3);
  assert.equal(w.photos.map((x) => x.name).join(','), 'cam.jpg,g1.jpg,g2.jpg');
  assert.ok(w.photos.every((x) => x.url.startsWith('blob:')), 'every photo has a preview');
  assert.equal(p.photoCountLabel(w), '3 of 3 photos');
});

test('the same picture twice is one picture; a fourth photo over the limit is refused with a plain message', async () => {
  const { p } = boot();
  const w = p.faultWizard;
  await p.addPhotos(w, pick(img('a.jpg', 100, 1), img('a.jpg', 100, 1)));
  assert.equal(w.photos.length, 1);
  await p.addPhotos(w, pick(img('b.jpg', 100, 2), img('c.jpg', 100, 3), img('d.jpg', 100, 4)));
  assert.equal(w.photos.length, 3);
  assert.equal(w.photoError, 'You can add up to 3 photos.');
});

test('a non-photo and an oversized photo are refused and nothing else is lost', async () => {
  const { p } = boot();
  const w = p.faultWizard;
  await p.addPhotos(w, pick(img('ok.jpg', 100, 1)));
  await p.addPhotos(w, pick(new File(['x'], 'notes.pdf', { type: 'application/pdf' })));
  assert.equal(w.photoError, 'Only photos can be added.');
  await p.addPhotos(w, pick(img('huge.jpg', 2 * 1024 * 1024, 9)));
  assert.match(w.photoError, /too large/);
  assert.equal(w.photos.length, 1);
});

test('removing a photo before sending frees its preview and its slot', async () => {
  const { p, urls } = boot();
  const w = p.faultWizard;
  await p.addPhotos(w, pick(img('a.jpg', 100, 1), img('b.jpg', 100, 2), img('c.jpg', 100, 3)));
  const first = w.photos[0];
  p.removePhoto(w, first.id);
  assert.equal(w.photos.length, 2);
  assert.ok(!urls.has(first.url), 'preview released');
  await p.addPhotos(w, pick(img('d.jpg', 100, 4)));
  assert.equal(w.photos.length, 3, 'the freed slot can be used');
});

test('without image support in the browser the original photo is kept as it is', async () => {
  const { p } = boot();
  const f = img('plain.jpg', 5000, 1);
  assert.equal(await p.compressPhoto(f), f);
});

function ready(p) {
  p.faultTypesByProperty = { 5: [{ id: 1, name: 'Geyser', first_aid_steps: 'Switch it off.', photos: [], documents: [] }, { id: 2, name: 'Odd one', first_aid_steps: '', photos: [], documents: [] }] };
  p.tenantLeases = [{ id: 9, property_id: 5 }];
  p.faultWizard = p.blankFaultWizard(5);
  p.faultWizard.faultTypeId = '1';
  p.selectFaultType();
  p.faultWizard.step = 'form';
  return p.faultWizard;
}

test('pressing Submit twice sends ONE request, with the form key, and the second press does nothing', async () => {
  const { p, calls } = boot();
  const w = ready(p);
  w.title = 'Geyser dripping';
  await p.addPhotos(w, pick(img('a.jpg', 100, 1), img('b.jpg', 100, 2)));
  const first = p.submitFault('still_a_problem');
  const second = p.submitFault('still_a_problem');
  await Promise.all([first, second]);
  await settle();
  assert.equal(calls.xhr.length, 1, 'one request');
  const x = calls.xhr[0];
  assert.equal(x.url, '/api/v1/client/rentals/properties/5/fault-reports');
  assert.equal(x.headers['X-Submission-Key'], w.key);
  assert.equal(x.body.get('submission_key'), w.key);
  assert.equal(x.body.getAll('photos[]').length, 2, 'both photos go');
  assert.equal(w.sending, false);
  assert.equal(w.step, 'done');
  assert.equal(w.doneTitle, 'Fault reported');
});

test('while it is sending the button state says so and shows progress', async () => {
  const { p, FakeXHR, calls } = boot();
  FakeXHR.auto = false;
  const w = ready(p);
  const run = p.submitFault('still_a_problem');
  await settle();
  assert.equal(w.sending, true);
  calls.xhr[0].upload.onprogress({ lengthComputable: true, loaded: 40, total: 100 });
  assert.equal(w.progress, 40);
  calls.xhr[0].finish(201, { fault_report: { id: 1 } });
  await run;
  assert.equal(w.sending, false);
});

test('a failed send keeps everything the person typed and the same key, so pressing again is safe', async () => {
  const { p, calls, FakeXHR } = boot();
  const w = ready(p);
  w.description = 'Water everywhere';
  await p.addPhotos(w, pick(img('a.jpg', 100, 1)));
  FakeXHR.status = 0; FakeXHR.reply = null;
  await p.submitFault('still_a_problem');
  assert.match(w.error, /No connection/);
  assert.equal(w.step, 'form');
  assert.equal(w.description, 'Water everywhere');
  assert.equal(w.photos.length, 1);
  FakeXHR.status = 201; FakeXHR.reply = { fault_report: { id: 3 } };
  await p.submitFault('still_a_problem');
  assert.equal(calls.xhr.length, 2);
  assert.equal(calls.xhr[0].headers['X-Submission-Key'], calls.xhr[1].headers['X-Submission-Key']);
  assert.equal(w.step, 'done');
});

test('a refusal from the server is shown in words and the form stays open', async () => {
  const { p, FakeXHR } = boot();
  const w = ready(p);
  FakeXHR.status = 422; FakeXHR.reply = { message: 'You can add up to 3 photos — please remove 1.' };
  await p.submitFault('still_a_problem');
  assert.equal(w.error, 'You can add up to 3 photos — please remove 1.');
  assert.equal(w.step, 'form');
});

test('a fault type with something to show goes to "Try this first"; one with nothing goes straight to the form', () => {
  const { p } = boot();
  p.faultTypesByProperty = { 5: [{ id: 1, name: 'Geyser', first_aid_steps: 'Switch it off.', photos: [], documents: [] }, { id: 2, name: 'Odd', first_aid_steps: '', photos: [], documents: [] }, { id: 3, name: 'Gate', first_aid_steps: '', photos: [], documents: [{ type: 'video_link', url: 'https://x.test' }] }] };
  p.faultWizard = p.blankFaultWizard(5);
  p.faultWizard.faultTypeId = '1'; p.selectFaultType();
  assert.equal(p.faultWizard.step, 'aid');
  assert.equal(p.faultWizard.title, 'Geyser');
  p.faultWizard.faultTypeId = '2'; p.selectFaultType();
  assert.equal(p.faultWizard.step, 'form');
  assert.equal(p.faultWizard.title, 'Odd', 'the title follows the type until the person has typed their own');
  p.faultWizard.title = 'My own words'; p.faultWizard.faultTypeId = '3'; p.selectFaultType();
  assert.equal(p.faultWizard.step, 'aid', 'a document alone is enough to show the panel');
  assert.equal(p.faultWizard.title, 'My own words');
});

test('the first-aid "That fixed it" button is guarded the same way', async () => {
  const { p, calls } = boot();
  const w = ready(p);
  w.step = 'aid';
  await Promise.all([p.submitFault('first_aid_resolved'), p.submitFault('first_aid_resolved')]);
  await settle();
  assert.equal(calls.xhr.length, 1);
  assert.equal(w.doneTitle, 'Glad that fixed it');
});

test('the owner request-work form is one press as well, with its own key and photos', async () => {
  const { p, calls } = boot();
  p.landlordFaultTypesByProperty = { 5: [] };
  p.landlordFaultWizard = p.blankLandlordWizard(5);
  const w = p.landlordFaultWizard;
  w.title = 'Gate motor';
  await p.addPhotos(w, pick(img('a.jpg', 100, 1), img('b.jpg', 100, 2)));
  await Promise.all([p.submitLandlordFault(), p.submitLandlordFault()]);
  await settle();
  assert.equal(calls.xhr.length, 1);
  assert.equal(calls.xhr[0].url, '/api/v1/client/rentals/landlord/properties/5/fault-reports');
  assert.equal(calls.xhr[0].body.getAll('photos[]').length, 2);
  assert.equal(w.step, 'done');
});

test('an owner decision pressed twice sends once, keyed to that decision', async () => {
  let release;
  const gate = new Promise((r) => { release = r; });
  const { p, calls } = boot({ fetchImpl: async () => { await gate; return { ok: true, status: 200, json: async () => ({}) }; } });
  const a = p.decideWorkOrder(11, 'approve');
  const b = p.decideWorkOrder(11, 'approve');
  release();
  await Promise.all([a, b]);
  const posts = calls.fetch.filter((c) => c.opts && c.opts.method === 'POST');
  assert.equal(posts.length, 1);
  assert.equal(posts[0].opts.headers['X-Submission-Key'], 'decide-wo-11-approve');
});

test('sign-in buttons cannot be pressed again while a sign-in is running', async () => {
  let release;
  const gate = new Promise((r) => { release = r; });
  const { p, calls } = boot({ fetchImpl: async () => { await gate; return { ok: true, status: 200, json: async () => ({ exists: true, requires_password: true }) }; } });
  p.login.email = 'a@b.test';
  const a = p.lookup();
  const b = p.lookup();
  release();
  await Promise.all([a, b]);
  assert.equal(calls.fetch.filter((c) => c.url.includes('/lookup')).length, 1);
  assert.equal(p.busy.login, false, 'free again afterwards');
});

test('lease details open under their own lease, close again, and are fetched once', async () => {
  const { p, calls } = boot({ fetchImpl: async (url) => ({ ok: true, status: 200, json: async () => ({ lease: { id: Number(url.split('/').pop()), rent_amount: 9500 } }) }) });
  await p.toggleLeaseDetail(4);
  assert.equal(p.openLeaseId, 4);
  assert.equal(p.leaseDetails[4].rent_amount, 9500);
  await p.toggleLeaseDetail(4);
  assert.equal(p.openLeaseId, null);
  await p.toggleLeaseDetail(4);
  assert.equal(calls.fetch.filter((c) => c.url.endsWith('/leases/4')).length, 1, 'cached');
  await p.toggleLeaseDetail(6);
  assert.equal(p.openLeaseId, 6, 'opening another lease moves the open one');
});

test('the Home FAQ opens one answer at a time per question', () => {
  const { p } = boot();
  assert.equal(p.isFaqOpen(5, 'notice'), false);
  p.toggleFaq(5, 'notice');
  assert.equal(p.isFaqOpen(5, 'notice'), true);
  assert.equal(p.isFaqOpen(5, 'early'), false);
  p.toggleFaq(5, 'notice');
  assert.equal(p.isFaqOpen(5, 'notice'), false);
});
