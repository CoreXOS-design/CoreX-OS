// AT-463 / spec §61 - the ONE signature pad and the in-place "Sign now" on the inspection screens.
// Loads the SHIPPED files (resources/js/corex-signature-pad-mixin.js and resources/js/rental-inspection-signing.js) into a
// sandbox with a fake pad library and a recording fetch, and proves:
//   - the pad is built once, waits for a late library, and re-fits keeping the strokes (the §46.11 behaviour, now shared);
//   - the agent's "Sign now": blocked until signing is open and everyone else has an outcome; posts the drawn image to the
//     signatures route as a drawn agent signature; an empty pad is refused before anything is sent;
//   - a party's "Sign now on this device": a live link is issued first, then the device-submit route gets the name, the image
//     and the read confirmation; name / read tick / drawing are all required; a server refusal is shown and nothing closes.
//
// Run:  node tests/js/inspection-sign-now.mjs      (exit 0 = pass, 1 = fail)

import fs from 'fs';
import path from 'path';
import vm from 'vm';
import { fileURLToPath } from 'url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (f) => fs.readFileSync(path.join(root, f), 'utf8');

let fails = 0;
const ok = (cond, msg) => { console.log((cond ? 'PASS ' : 'FAIL ') + msg); if (!cond) fails++; };

// ── sandbox ──────────────────────────────────────────────────────────────
function sandbox() {
    const calls = [];
    const listeners = {};
    const sb = {
        calls, listeners,
        document: {
            querySelector: (sel) => (sel.includes('csrf') ? { content: 'tok' } : null),
            addEventListener: (ev, fn) => { (listeners['doc:' + ev] ||= []).push(fn); },
        },
        setInterval: (fn, ms) => { const id = setInterval(fn, ms); return id; },
        clearInterval: (id) => clearInterval(id),
        console,
        Promise, JSON, Math, Date,
        devicePixelRatio: 1,
        navigator: {},
        fetchPlan: [],
    };
    sb.window = sb;
    sb.addEventListener = (ev, fn) => { (listeners['win:' + ev] ||= []).push(fn); };
    sb.fetch = async (url, opts = {}) => {
        const body = opts.body ? JSON.parse(opts.body) : undefined;
        calls.push({ method: opts.method || 'GET', url, body });
        const next = sb.fetchPlan.shift() || { status: 200, json: {} };
        return { ok: next.status < 400, status: next.status, json: async () => next.json };
    };
    sb.corexConfirm = async () => true;
    vm.createContext(sb);
    vm.runInContext(read('resources/js/corex-signature-pad-mixin.js'), sb);
    vm.runInContext(read('resources/js/rental-inspection-signing.js'), sb);
    return sb;
}

class FakePad {
    constructor(canvas) { this.canvas = canvas; this.strokes = []; FakePad.built++; }
    isEmpty() { return this.strokes.length === 0; }
    toData() { return JSON.parse(JSON.stringify(this.strokes)); }
    fromData(d) { this.strokes = d; }
    clear() { this.strokes = []; }
    toDataURL() { return 'data:image/png;base64,SIGNATURE'; }
    draw() { this.strokes.push({ points: [{ x: 10, y: 10 }, { x: 50, y: 30 }] }); }
}
FakePad.built = 0;

const canvas = (w = 300, h = 180) => ({ offsetWidth: w, offsetHeight: h, width: 0, height: 0, getContext: () => ({ scale() {} }) });

function component(sb, cv) {
    const c = sb.inspectionSigningLinks({ inspectionId: 77, base: '/corex/rental-inspections' });
    c.$refs = { pad: cv };
    c.$nextTick = (fn) => fn();
    c.$dispatch = (name) => { c._dispatched = (c._dispatched || []).concat(name); };
    return c;
}

const row = (over) => Object.assign({ key: 'tenant:5', role: 'tenant', role_label: 'Tenant', name: 'Naledi Dlamini', contact_id: 5, is_agent: false, recorded: null, link: null, can_issue: true }, over);
const agent = (over) => Object.assign({ key: 'agent', role: 'agent', role_label: 'Agent', name: 'Aileen Agent', contact_id: null, is_agent: true, recorded: null, link: null, can_issue: true }, over);
const signedRow = (over) => row(Object.assign({ recorded: { disposition: 'signed', label: 'Signed', via: null, at: null } }, over));

// ── 1. the shared pad (the §46.11 behaviour) ─────────────────────────────
{
    const sb = sandbox();
    sb.SignaturePad = FakePad;
    FakePad.built = 0;
    const cv = canvas();
    const c = sb.corexSignaturePadMixin();
    c.$refs = { pad: cv }; c.$nextTick = (fn) => fn();
    c.watchPad(); c.watchPad();
    ok(FakePad.built === 1, 'the pad is built once as soon as the canvas can be drawn on (watchPad twice builds one)');
    ok(cv.width === 300 && cv.height === 180, 'the canvas is sized to its real size');
    c.pad.draw();
    cv.offsetWidth = 600; cv.offsetHeight = 360;
    c.syncPad();
    ok(FakePad.built === 1 && !c.pad.isEmpty(), 'a resize re-fits the same pad and keeps the strokes');
    ok(c.pad.strokes[0].points[1].x === 100, 'the kept strokes are scaled to the new size');
    ok(c.padIsEmpty() === false && c.padDataUrl().startsWith('data:image/png'), 'the drawing can be read out as a PNG');
    c.clearPad();
    ok(c.padIsEmpty(), 'clear empties it');
}
{
    const sb = sandbox();            // library not there yet
    FakePad.built = 0;
    const cv = canvas();
    const c = sb.corexSignaturePadMixin();
    c.$refs = { pad: cv }; c.$nextTick = (fn) => fn();
    c.watchPad();
    ok(FakePad.built === 0 && c.libTimer !== null, 'with no library yet one watcher waits for it');
    sb.SignaturePad = FakePad;
    await new Promise((r) => setTimeout(r, 250));
    ok(FakePad.built === 1 && c.libTimer === null, 'the pad appears by itself once the library arrives, and the watcher stops');
}
{
    const sb = sandbox();
    sb.SignaturePad = FakePad; FakePad.built = 0;
    const cv = canvas(0, 0);          // hidden overlay: not laid out
    const c = sb.corexSignaturePadMixin();
    c.$refs = { pad: cv }; c.$nextTick = (fn) => fn();
    c.watchPad();
    ok(FakePad.built === 0, 'a hidden canvas builds nothing yet');
    cv.offsetWidth = 300; cv.offsetHeight = 180; c.syncPad();
    ok(FakePad.built === 1, 'it is built the moment the canvas is shown (the overlay opening)');
}

// ── 2. the agent's Sign now ──────────────────────────────────────────────
{
    const sb = sandbox(); sb.SignaturePad = FakePad;
    const c = component(sb, canvas());
    const panel = (over) => Object.assign({ ready_to_sign: true, rows: [signedRow(), agent()] }, over);

    c.panel = panel({ ready_to_sign: false });
    ok(/not open yet/.test(c.agentBlock), 'agent: blocked while signing is not open ("press Ready to sign")');
    c.panel = panel({ rows: [row({ name: 'Naledi Dlamini' }), signedRow({ key: 'tenant:6', name: 'Sipho Khumalo' }), agent()] });
    ok(/waiting for Naledi Dlamini/.test(c.agentBlock) && !/Sipho/.test(c.agentBlock), 'agent: blocked until everyone else has an outcome, naming who is outstanding');
    c.panel = panel({ rows: [row({ recorded: { disposition: 'awaiting_wet_ink', label: 'Awaiting paper signature' } }), agent()] });
    ok(/waiting for/.test(c.agentBlock), 'agent: a party still awaiting a paper signature is not an outcome yet');
    c.panel = panel();
    ok(c.agentBlock === '', 'agent: free to sign once signing is open and everyone else has an outcome');

    await c.openSignNow(agent());
    ok(c.signNow.open && c.signNow.kind === 'agent' && /Aileen Agent/.test(c.signNow.title), 'agent: the overlay opens, titled with who is signing');
    ok(c.calls === undefined && sb.calls.length === 0, 'agent: opening it sends nothing');

    await c.submitSignNow();
    ok(/draw the signature/.test(c.signNow.error) && sb.calls.length === 0, 'agent: an empty pad is refused before anything is sent');

    c.pad.draw();
    sb.fetchPlan.push({ status: 201, json: {} }, { status: 200, json: { rows: [signedRow(), agent({ recorded: { disposition: 'signed', label: 'Signed', via: 'on screen by the agent (Aileen Agent)' } })], ready_to_sign: true } });
    await c.submitSignNow();
    const post = sb.calls[0];
    ok(post.method === 'POST' && post.url === '/corex/rental-inspections/77/signatures', 'agent: posts to the existing signatures route');
    ok(post.body.party_role === 'agent' && post.body.disposition === 'signed' && post.body.signing_method === 'drawn' && post.body.signature_image.startsWith('data:image/png'), 'agent: sends the drawn image as a drawn agent signature');
    ok(c.signNow.open === false && c.notice === 'Your signature is recorded.', 'agent: the overlay closes and says it is recorded');
    ok(c.padIsEmpty(), 'agent: the pad is cleared for next time');
    ok(sb.calls[1].method === 'GET', 'agent: the panel is re-read afterwards');
    ok((c._dispatched || []).includes('signing-links-changed'), 'agent: the host screen is told a signature was recorded');
}
{
    const sb = sandbox(); sb.SignaturePad = FakePad;
    const c = component(sb, canvas());
    c.panel = { ready_to_sign: false, rows: [row(), agent()] };
    await c.openSignNow(agent());
    ok(c.signNow.open === false && /not open yet/.test(c.error), 'agent: pressing it while blocked shows the reason and opens nothing');
}
{
    const sb = sandbox(); sb.SignaturePad = FakePad;
    const c = component(sb, canvas());
    c.panel = { ready_to_sign: true, rows: [signedRow(), agent()] };
    await c.openSignNow(agent()); c.pad.draw();
    sb.fetchPlan.push({ status: 422, json: { message: "Cannot record the agent's signature until every tenant and the landlord has a disposition recorded." } });
    await c.submitSignNow();
    ok(c.signNow.open === true && /Cannot record the agent/.test(c.signNow.error), 'agent: a server refusal is shown in the overlay and nothing closes');
    ok(!c.padIsEmpty(), 'agent: the drawing is kept so it can be sent again');
}

// ── 3. a party's Sign now on this device ────────────────────────────────
{
    const sb = sandbox(); sb.SignaturePad = FakePad;
    const c = component(sb, canvas());
    const panel = { ready_to_sign: true, rows: [row(), agent()] };
    c.panel = panel;
    sb.fetchPlan.push({ status: 200, json: { link_id: 901, url: 'x', device_url: 'x', panel } });
    await c.openSignNow(row());
    ok(sb.calls[0].method === 'POST' && sb.calls[0].url === '/corex/rental-inspections/77/signing-links' && sb.calls[0].body.channel === 'device' && sb.calls[0].body.party_contact_id === 5, 'party: a live link is issued first, marked as used on this device');
    ok(c.signNow.open && c.signNow.kind === 'party' && c.signNow.linkId === 901, 'party: the overlay opens for that link');
    ok(/Naledi Dlamini/.test(c.signNow.title) && /on Aileen Agent's device, in Aileen Agent's presence/.test(c.signNow.sub), 'party: labelled with who is signing, on whose device, in whose presence');
    ok(c.signNow.typedName === 'Naledi Dlamini', "party: the signer's name is filled in, correctable");

    await c.submitSignNow();
    ok(/draw the signature/.test(c.signNow.error), 'party: a blank pad is refused');
    c.pad.draw(); c.signNow.typedName = ' ';
    await c.submitSignNow();
    ok(/full name/.test(c.signNow.error), 'party: a missing name is refused');
    c.signNow.typedName = 'Naledi Dlamini';
    await c.submitSignNow();
    ok(/read this inspection report/.test(c.signNow.error) && sb.calls.length === 1, 'party: the read confirmation is required, nothing sent yet');

    c.signNow.readConfirmed = true;
    sb.fetchPlan.push({ status: 201, json: { ok: true, outcome: 'signed' } }, { status: 200, json: panel });
    await c.submitSignNow();
    const post = sb.calls[1];
    ok(post.method === 'POST' && post.url === '/corex/rental-inspections/77/signing-links/901/device', 'party: posts to the existing device-submit route');
    ok(post.body.action === 'sign' && post.body.typed_name === 'Naledi Dlamini' && post.body.read_confirmed === true && post.body.signature_image.startsWith('data:image/png'), 'party: sends the name, the drawing and the read confirmation');
    ok(c.signNow.open === false && c.notice === 'Signature recorded.', 'party: closes and says it is recorded');
}
{
    const sb = sandbox(); sb.SignaturePad = FakePad;
    const c = component(sb, canvas());
    c.panel = { ready_to_sign: false, rows: [row(), agent()] };
    sb.fetchPlan.push({ status: 409, json: { message: '3 items are not recorded yet.', readiness: { state: 'blocked', unrecorded: 3, rooms: [] } } });
    await c.openSignNow(row());
    ok(c.signNow.open === false && /not recorded yet/.test(c.error), 'party: if signing cannot open yet, the reason is shown and no overlay opens');
}

process.exit(fails ? 1 : 0);
