// .ai/specs/rental-portal-access.md §22 — runs the tenant / owner portal page's OWN script (resources/views/rentals/portal/shell.blade.php)
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
function boot({ fetchImpl, search = '', storage = {} } = {}) {
  const calls = { xhr: [], fetch: [], reloaded: false };
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
    window: {
      location: { search, reload: () => { calls.reloaded = true; } }, crypto: null, confirm: () => true,
      localStorage: { getItem: (k) => (k in storage ? storage[k] : null), setItem: (k, v) => { storage[k] = String(v); } },
    },
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
  p.$nextTick = () => {};   // Alpine's helper: the page scrolls after render, nothing to do in Node
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

// ── Johan, 8 Oct 2026: who is signed in, links made for somebody else, and the Tenant / Owner switch ──
function portalApi({ me, leases = [], properties = [], faultOk = true, calls = [] } = {}) {
  return async (url, opts) => {
    calls.push(url);
    const reply = (ok, data, status = ok ? 200 : 404) => ({ ok, status, json: async () => data });
    if (url.endsWith('/api/v1/client/me')) return me ? reply(true, me) : reply(false, {}, 401);
    if (url.endsWith('/rentals/leases')) return reply(true, { leases });
    if (url.endsWith('/rentals/landlord/properties')) return reply(true, { properties });
    if (/\/landlord\/fault-reports\/\d+$/.test(url)) return faultOk ? reply(true, { fault_report: { id: 50, title: 'Power tripping', awaiting_decision: true } }) : reply(false, { message: 'not found' });
    return reply(true, { fault_reports: [], work_orders: [], variations: [], documents: [], homes: [], branding: null });
  };
}
const TENANT_ME = { client: { id: 29, email: 'tina@example.com' }, contact: { id: 1, full_name: 'Tina Tenant' } };
const OWNER_ME = { client: { id: 30, email: 'ndlovu5308@gmail.com' }, contact: { id: 2, full_name: 'Siyabonga Simamane' } };

test('the header always says who is signed in and which side is on screen', async () => {
  const { p } = boot({ fetchImpl: portalApi({ me: TENANT_ME, leases: [{ id: 94 }] }) });
  await p.init(); await settle();
  assert.equal(p.whoName(), 'Tina Tenant');
  assert.equal(p.roleLabel(), 'Tenant');
  const o = boot({ fetchImpl: portalApi({ me: OWNER_ME, properties: [{ id: 6 }] }) });
  await o.p.init(); await settle();
  assert.equal(o.p.whoName(), 'Siyabonga Simamane');
  assert.equal(o.p.roleLabel(), 'Owner');
});

test('a link for ANOTHER email never shows the signed-in person\'s portal: it says who is signed in and whose link it is', async () => {
  const calls = [];
  const { p } = boot({ search: '?email=ndlovu5308%40gmail.com', fetchImpl: portalApi({ me: TENANT_ME, leases: [{ id: 94 }], calls }) });
  await p.init(); await settle();
  assert.equal(p.linkIssue.kind, 'email');
  assert.equal(p.linkIssue.masked, 'n******8@gmail.com');
  assert.equal(p.roles.length, 0, 'no portal data was loaded for the wrong person');
  assert.ok(!calls.some((u) => u.endsWith('/rentals/leases')), 'the tenant\'s leases were not even fetched');
  assert.equal(p.whoName(), 'Tina Tenant');
  assert.equal(p.roleLabel(), '', 'no role is claimed while the link does not match');
});

test('the same email on the link (any capitals) is the normal portal', async () => {
  const { p } = boot({ search: '?email=TINA%40example.com', fetchImpl: portalApi({ me: TENANT_ME, leases: [{ id: 94 }] }) });
  await p.init(); await settle();
  assert.equal(p.linkIssue, null);
  assert.equal(p.roles.join(','), 'tenant');
});

test('nobody signed in: the link\'s email is pre-filled on the sign-in form', async () => {
  const { p } = boot({ search: '?email=ndlovu5308%40gmail.com', fetchImpl: portalApi({ me: null }) });
  await p.init();
  assert.equal(p.session.authenticated, false);
  assert.equal(p.login.email, 'ndlovu5308@gmail.com');
});

test('signing out from the mismatch card reloads on the same link, so the sign-in opens pre-filled', async () => {
  const { p, calls } = boot({ search: '?email=ndlovu5308%40gmail.com', fetchImpl: portalApi({ me: TENANT_ME, leases: [{ id: 94 }] }) });
  await p.init(); await settle();
  await p.logout();
  assert.ok(calls.fetch.some((c) => c.url.endsWith('/client-auth/logout') && c.opts.method === 'POST'));
  assert.equal(calls.reloaded, true);
});

test('an owner fault link for a person with no part in that repair is told so, not shown their own portal', async () => {
  const { p } = boot({ search: '?fault=50', fetchImpl: portalApi({ me: TENANT_ME, leases: [{ id: 94 }] }) });
  await p.init(); await settle();
  assert.equal(p.linkIssue.kind, 'fault');
  assert.equal(p.faultDetail, null);
  const o = boot({ search: '?fault=999&email=ndlovu5308%40gmail.com', fetchImpl: portalApi({ me: OWNER_ME, properties: [{ id: 6 }], faultOk: false }) });
  await o.p.init(); await settle();
  assert.equal(o.p.linkIssue.kind, 'fault', 'an owner whose property does not include that fault is told so too');
});

test('one login that is both tenant and owner: the last side is remembered, and the owner\'s fault link always lands on the owner side', async () => {
  const both = { me: { client: { id: 31, email: 'can.assurance@gmail.com' }, contact: { id: 3, full_name: 'Test Both' } }, leases: [{ id: 21 }], properties: [{ id: 5 }] };
  const storage = {};
  const a = boot({ storage, fetchImpl: portalApi(both) });
  await a.p.init(); await settle();
  assert.equal(a.p.roles.join(','), 'tenant,landlord');
  assert.equal(a.p.activeRole, 'tenant', 'first visit: the first side');
  a.p.setRole('landlord'); await settle();
  assert.equal(storage['portal.role.31'], 'landlord');
  // next visit opens on the Owner side (the choice was remembered)
  const b = boot({ storage, fetchImpl: portalApi(both) });
  await b.p.init(); await settle();
  assert.equal(b.p.activeRole, 'landlord');
  // Tenant was last used, but the owner email's link goes to the Owner view on that fault
  storage['portal.role.31'] = 'tenant';
  const c = boot({ storage, search: '?fault=50&email=can.assurance%40gmail.com', fetchImpl: portalApi(both) });
  await c.p.init(); await settle();
  assert.equal(c.p.activeRole, 'landlord');
  assert.equal(c.p.landlordTab, 'faults');
  assert.equal(c.p.faultDetail.id, 50);
  assert.equal(storage['portal.role.31'], 'landlord', 'and Owner is now the remembered side');
});

test('the Properties list is there when the second side is switched to', async () => {
  const both = { me: TENANT_ME, leases: [{ id: 21 }], properties: [{ id: 5, address: 'x' }] };
  const { p } = boot({ fetchImpl: portalApi(both) });
  await p.init(); await settle();
  p.setRole('landlord');
  assert.equal(p.landlordProperties.length, 1);
});


// First-load freeze report (8 Oct 2026, QA1): Alpine calls an x-data object's own init() by itself (alpinejs module.cjs.js:
// `reactiveData["init"] && evaluate(el, reactiveData["init"])`) AND the page also said x-init="init()", so every load ran init()
// twice at the same moment - two /me checks, two role detections, two copies of every list request racing each other on a cold
// server. One run is enough; the second call must do nothing, whichever way the link and the session race.
test('init() runs once even though Alpine AND x-init both call it: one /me check, one role detection', async () => {
  const urls = [];
  const { p } = boot({ fetchImpl: portalApi({ me: TENANT_ME, leases: [{ id: 94 }], calls: urls }) });
  await Promise.all([p.init(), p.init()]); await settle();
  assert.equal(urls.filter((u) => u.endsWith('/api/v1/client/me')).length, 1, 'one session check');
  assert.equal(urls.filter((u) => u.endsWith('/rentals/leases')).length, 1, 'one role detection');
  assert.equal(p.loading, false);
  assert.equal(p.roles.join(','), 'tenant');
});

test('a link for another email, with the page\'s double init and a slow session check, still ends on the card - never on a blank page', async () => {
  const slowApi = portalApi({ me: TENANT_ME, leases: [{ id: 94 }] });
  const { p } = boot({ search: '?email=ndlovu5308%40gmail.com', fetchImpl: async (url, opts) => { await new Promise((r) => setTimeout(r, 120)); return slowApi(url, opts); } });
  const done = Promise.all([p.init(), p.init()]);
  const first = await Promise.race([done.then(() => 'done'), new Promise((r) => setTimeout(() => r('hung'), 3000))]);
  assert.equal(first, 'done', 'init() settles - nothing waits forever');
  assert.equal(p.loading, false);
  assert.equal(p.linkIssue.kind, 'email');
  assert.equal(p.whoName(), 'Tina Tenant');
});

// ── 8 Oct 2026, 20:05 — Johan could not sign in: the Continue button was DISABLED on every signed-out page ──
// Cause: `:disabled="busy.login"`. Alpine turns an UNDEFINED result of an expression that contains a dot into "" (alpinejs
// module.cjs.js, x-bind handler: `result === void 0 && expression.match(/\./) → ""`), and "" on a boolean attribute means SET.
// `busy` starts as {} (only the first press creates `busy.login`), so every button bound like that was disabled until pressed - and
// a disabled button can never be pressed. These tests apply Alpine's own rule to the page's bindings.
const bladeSrc = fs.readFileSync(path.join(here, '../../resources/views/rentals/portal/shell.blade.php'), 'utf8');
const detail = fs.readFileSync(path.join(here, '../../resources/views/rentals/portal/_fault-detail.blade.php'), 'utf8');

/** Does Alpine leave a `disabled` attribute on a button bound with this expression, given this page state? */
function alpineSetsDisabled(expr, state, extras = {}) {
  let result;
  try { result = new Function('state', 'extras', 'with (extras) { with (state) { return (' + expr + '); } }')(state, extras); } catch (e) { return null; }
  if (result === undefined && /\./.test(expr)) result = '';          // Alpine's own coercion
  return ![null, undefined, false].includes(result);
}

function authApi({ me = null, requiresPassword = true, exists = true, leases = [], properties = [], calls = [] } = {}) {
  const base = portalApi({ me, leases, properties, calls });
  return async (url, opts) => {
    const reply = (ok, data, status = ok ? 200 : 422) => ({ ok, status, json: async () => data });
    const body = opts && opts.body ? JSON.parse(opts.body) : {};
    if (url.endsWith('/client-auth/lookup')) { calls.push(url); return exists ? reply(true, { exists: true, requires_password: requiresPassword }) : reply(false, { message: 'We could not find that email.' }, 404); }
    if (url.endsWith('/client-auth/otp/send')) { calls.push(url); return reply(true, { sent: true }); }
    if (url.endsWith('/client-auth/otp/verify')) { calls.push(url); return body.code === '123456' ? reply(true, { activation_token: 'tok' }) : reply(false, { message: 'Invalid code.' }); }
    if (url.endsWith('/client-auth/password/set') || url.endsWith('/client-auth/login') || url.endsWith('/client-auth/logout')) { calls.push(url); return reply(true, {}); }
    return base(url, opts);
  };
}

const continueExpr = () => bladeSrc.match(/<button[^>]*:disabled="([^"]+)"[^>]*@click="lookup\(\)"/)[1];

test('EVERY :disabled binding on the portal pages is a real boolean (never an undefined property Alpine would turn into "disabled")', () => {
  const all = [...bladeSrc.matchAll(/:disabled="([^"]*)"/g), ...detail.matchAll(/:disabled="([^"]*)"/g)].map((m) => m[1]);
  assert.ok(all.length >= 20, 'the scan found the bindings');
  for (const expr of all) assert.match(expr, /^!!\(.*\)$/s, `:disabled="${expr}" must be wrapped in !!( ) - an undefined result is treated as ON`);
});

test('first load, signed out: no button on the page starts disabled - Continue is pressable (fresh browser, typed email)', async () => {
  const { p } = boot({ fetchImpl: authApi() });
  await p.init(); await settle();
  assert.equal(p.session.authenticated, false);
  assert.equal(p.loading, false);
  assert.equal(p.login.step, 'email');
  assert.equal(alpineSetsDisabled(continueExpr(), p), false, 'Continue is enabled');
  for (const expr of [...bladeSrc.matchAll(/:disabled="([^"]*)"/g)].map((m) => m[1])) {
    const on = alpineSetsDisabled(expr, p, { w: { id: 1 }, v: { id: 1 }, f: { id: 1 } });
    assert.notEqual(on, true, `:disabled="${expr}" is ON at first load`);
  }
});

test('the link\'s email is pre-filled and Continue is pressable (both of Johan\'s links), with stale stored state from earlier deploys', async () => {
  const storage = { 'portal.role.29': 'landlord', 'portal.role.': 'tenant', 'portal.role.undefined': 'owner', 'portal.role.30': 'nonsense', unrelated: 'x' };
  for (const email of ['ndlovu5308%40gmail.com', 'mtoloayanda93%40gmail.com']) {
    const { p } = boot({ search: '?email=' + email, storage, fetchImpl: authApi() });
    await p.init(); await settle();
    assert.equal(p.login.email, decodeURIComponent(email));
    assert.equal(p.login.step, 'email');
    assert.equal(p.linkIssue, null);
    assert.equal(alpineSetsDisabled(continueExpr(), p), false);
  }
});

test('a password account (tenant): Continue asks for the password, Sign in works, the portal opens', async () => {
  const calls = [];
  const { p } = boot({ search: '?email=mtoloayanda93%40gmail.com', fetchImpl: authApi({ requiresPassword: true, leases: [{ id: 94 }], calls }) });
  await p.init(); await settle();
  await p.lookup();
  assert.equal(p.login.step, 'password');
  assert.equal(p.login.error, null);
  assert.equal(!!p.busy.login, false, 'the busy flag is released');
  p.login.password = 'secret-pass';
  await p.passwordLogin(); await settle();
  assert.equal(p.session.authenticated, true);
  assert.equal(p.roles.join(','), 'tenant');
});

test('a never-activated account (owner): Continue sends the code, the code is checked, a password is created, the portal opens', async () => {
  const calls = [];
  const { p } = boot({ search: '?email=ndlovu5308%40gmail.com', fetchImpl: authApi({ requiresPassword: false, properties: [{ id: 6 }], calls }) });
  await p.init(); await settle();
  await p.lookup();
  assert.equal(p.login.step, 'otp-sent');
  assert.ok(calls.some((u) => u.endsWith('/otp/send')));
  p.login.code = '000000';
  await p.verifyOtp();
  assert.equal(p.login.step, 'otp-sent', 'a wrong code keeps the step');
  assert.equal(p.login.error, 'Invalid code.');
  assert.equal(alpineSetsDisabled(bladeSrc.match(/:disabled="([^"]+)"[^>]*@click="verifyOtp\(\)"/)[1], p), false, 'Verify can be pressed again');
  p.login.code = '123456';
  await p.verifyOtp();
  assert.equal(p.login.step, 'set-password');
  p.login.newPassword = 'a-long-password'; p.login.newPasswordConfirm = 'a-long-password';
  await p.setPassword(); await settle();
  assert.equal(p.session.authenticated, true);
  assert.equal(p.roles.join(','), 'landlord');
});

test('an unknown email or a failed lookup shows a message and leaves Continue pressable', async () => {
  const { p } = boot({ fetchImpl: authApi({ exists: false }) });
  await p.init(); await settle();
  p.login.email = 'nobody@example.com';
  await p.lookup();
  assert.equal(p.login.error, 'We could not find that email.');
  assert.equal(alpineSetsDisabled(continueExpr(), p), false);
  const broken = boot({ fetchImpl: async (url) => { if (url.includes('client-auth/lookup')) throw new Error('network'); return authApi()(url); } });
  await broken.p.init(); await settle();
  await assert.rejects(broken.p.lookup());
  assert.equal(!!broken.p.busy.login, false, 'a thrown error still releases the busy flag');
  assert.equal(alpineSetsDisabled(continueExpr(), broken.p), false);
});

test('after Log out, and after "Sign out and sign in as...", the reloaded page is a fresh sign-in with Continue pressable', async () => {
  const calls = [];
  const signed = boot({ search: '?email=ndlovu5308%40gmail.com', fetchImpl: authApi({ me: TENANT_ME, leases: [{ id: 94 }], calls }) });
  await signed.p.init(); await settle();
  assert.equal(signed.p.linkIssue.kind, 'email');
  await signed.p.logout();
  assert.equal(signed.calls.reloaded, true);
  assert.ok(calls.some((u) => u.endsWith('/client-auth/logout')));
  // the reload: same link, no session any more
  const reloaded = boot({ search: '?email=ndlovu5308%40gmail.com', storage: { 'portal.role.29': 'tenant' }, fetchImpl: authApi() });
  await reloaded.p.init(); await settle();
  assert.equal(reloaded.p.session.authenticated, false);
  assert.equal(reloaded.p.login.email, 'ndlovu5308@gmail.com');
  assert.equal(alpineSetsDisabled(continueExpr(), reloaded.p), false);
  // plain Log out from a normal portal
  const normal = boot({ fetchImpl: authApi({ me: TENANT_ME, leases: [{ id: 94 }] }) });
  await normal.p.init(); await settle();
  await normal.p.logout();
  const after = boot({ fetchImpl: authApi() });
  await after.p.init(); await settle();
  assert.equal(alpineSetsDisabled(continueExpr(), after.p), false);
});
