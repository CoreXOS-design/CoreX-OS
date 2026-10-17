// AT-456 §B.9-2 — the six polygon shapes (triangle, diamond, pentagon, hexagon, star, chevron) must EXPORT
// like they look on the canvas.
//
// The bug: the exported picture is made by html2canvas 1.4.1, which silently IGNORES `clip-path`. The six
// shapes were drawn with clip-path, so they looked right on the canvas but came out as plain rectangles in
// the posted ad — as a Shape element AND as an Agent Image mask.
//
// The fix (public/js/corex-ad-render.js):
//   - a Shape element draws the polygon as an inline SVG <polygon> (same points as the old clip-path);
//   - an Agent Image keeps its frame clip-path on the canvas (so the canvas is unchanged) and the export step
//     (prepareImagesForCapture) bakes the very same polygon into the pixels it already pre-crops;
//   - a missing agent photo on a real ad inside a polygon mask is drawn as that polygon too.
//
// This check proves the geometry and the bake with a fake DOM. The pixel-level proof (live canvas vs real
// html2canvas export, in real Chromium) was done with a throw-away harness; its result is in the spec.
//
// Run:  node tests/js/ad-export-shapes.mjs      (exit 0 = pass, 1 = fail)

import fs from 'fs';
import path from 'path';
import vm from 'vm';
import { fileURLToPath } from 'url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const code = fs.readFileSync(path.join(root, 'public/js/corex-ad-render.js'), 'utf8');

function load(extra = {}) {
    const sandbox = Object.assign({ window: {} }, extra);
    if (extra.document) sandbox.window.document = extra.document;
    vm.createContext(sandbox);
    vm.runInContext(code, sandbox);
    return sandbox.window.CoreXAd;
}
const K = load();

let pass = 0, fail = 0;
const ok = (name, cond, got) => {
    if (cond) { pass++; console.log('  PASS  ' + name); }
    else { fail++; console.log('  FAIL  ' + name + (got !== undefined ? '\n          got: ' + String(got).slice(0, 400) : '')); }
};
const section = (t) => console.log('\n── ' + t + ' ──');
const el = (o) => Object.assign(K.makeElement(o.field, 0, 0, 1), { w: 200, h: 100 }, o);

const TYPES = ['triangle', 'diamond', 'pentagon', 'hexagon', 'star', 'chevron'];

section('Shape element: an SVG polygon, never clip-path');
for (const t of TYPES) {
    const h = K.contentHtml(el({ field: 'shape', shapeType: t, bg: '#336699', opacity: 0.75 }), {}, {});
    const want = K.SHAPE_CLIPS[t].replace(/^polygon\(|\)$/g, '').split(',')
        .map((p) => p.trim().split(/\s+/).map((v) => parseFloat(v)).join(',')).join(' ');
    ok(t + ': inline SVG polygon, no clip-path', h.startsWith('<svg') && h.includes('<polygon') && !h.includes('clip-path'), h);
    ok(t + ': exactly the points the clip-path used', h.includes('points="' + want + '"'), h);
    ok(t + ': fill colour and opacity carried', h.includes('fill="#336699"') && h.includes('fill-opacity="0.75"'), h);
}
ok('SHAPE_POINTS covers exactly the six clip shapes', Object.keys(K.SHAPE_POINTS).sort().join() === TYPES.slice().sort().join());
ok('rounded / circle / pill / rectangle are still plain CSS boxes (border-radius exports fine)',
    ['rounded', 'circle', 'pill', 'rectangle'].every((t) => K.contentHtml(el({ field: 'shape', shapeType: t }), {}, {}).startsWith('<div')));
ok('a shape with no shapeType (a very old template) still renders as before',
    K.contentHtml(el({ field: 'shape', shapeType: undefined, borderRadius: 30 }), {}, {}).includes('border-radius:30%'));

section('Agent Image: the canvas keeps its clip-path; a missing photo is drawn as the polygon');
for (const t of TYPES) {
    ok(t + ': the frame still carries the clip-path on the canvas (canvas output unchanged)',
        K.frameStyle(el({ field: 'agent_avatar', shapeType: t })).includes('clip-path:' + K.SHAPE_CLIPS[t] + ';'));
    const empty = K.contentHtml(el({ field: 'agent_avatar', shapeType: t }), {}, { placeholders: false });
    ok(t + ': no photo on a real ad -> tinted polygon, not a box', empty.includes('<polygon points="' + K.SHAPE_POINTS[t] + '"') && !empty.includes('clip-path'), empty);
}
ok('no photo + a circle mask is still the plain tinted box (border-radius on the frame exports fine)',
    !K.contentHtml(el({ field: 'agent_avatar', shapeType: 'circle' }), {}, { placeholders: false }).includes('<polygon'));
ok('the builder placeholder is unchanged', K.contentHtml(el({ field: 'agent_avatar', shapeType: 'hexagon' }), {}, { placeholders: true }).includes('Agent 1'));
ok('a photo inside a polygon mask is still just an <img> (the export bakes the polygon, see below)',
    /^<img /.test(K.contentHtml(el({ field: 'agent_avatar', shapeType: 'star' }), { agent_avatar: '/a.jpg' }, {})));

section('Export bake: prepareImagesForCapture draws the polygon into the pixels');
{
    // A fake DOM: one frame (with the polygon clip-path) holding one <img>; a recording 2D context.
    const calls = [];
    const ctx = new Proxy({}, {
        get: (_, k) => (k === 'getImageData' ? () => ({ data: new Uint8ClampedArray(4) }) : (...a) => { calls.push([k, ...a]); }),
        set: () => true,
    });
    const canvases = [];
    const doc = {
        createElement: (tag) => { const c = { tag, getContext: () => ctx, toDataURL: () => 'data:image/png;base64,AAAA' }; canvases.push(c); return c; },
    };
    const K2 = load({ document: doc, Promise, setTimeout });

    const frame = { style: { clipPath: K.SHAPE_CLIPS.hexagon }, getBoundingClientRect: () => ({ left: 100, top: 50, width: 200, height: 100 }) };
    const img = {
        style: { objectFit: 'cover' }, naturalWidth: 400, naturalHeight: 400, parentElement: frame, src: '/a.jpg',
        getAttribute: () => null, getBoundingClientRect: () => ({ left: 100, top: 50, width: 200, height: 100 }), decode: () => Promise.resolve(),
    };
    const rootEl = { querySelectorAll: () => [img] };
    frame.parentElement = rootEl;

    const restore = await K2.prepareImagesForCapture(rootEl);
    const names = calls.map((c) => c[0]);
    const moves = calls.filter((c) => c[0] === 'moveTo' || c[0] === 'lineTo');
    ok('the clip is applied BEFORE the photo is drawn', names.indexOf('clip') !== -1 && names.indexOf('clip') < names.indexOf('drawImage'), names.join());
    ok('the polygon has the six hexagon corners', moves.length === 6 && calls.filter((c) => c[0] === 'moveTo').length === 1, JSON.stringify(moves));
    ok('corner 1 is 25% across / top; corner 3 is the right-hand point (100% across, 50% down)',
        moves[0][1] === 50 && moves[0][2] === 0 && moves[2][1] === 200 && moves[2][2] === 50, JSON.stringify(moves));
    ok('the baked picture replaces the src for the capture', img.src === 'data:image/png;base64,AAAA');
    restore();
    ok('and the original src is put back afterwards', img.src === '/a.jpg');

    // No polygon clip + a plain object-fit image: no clip is applied (nothing else changed for ordinary photos).
    calls.length = 0;
    const frame2 = { style: { clipPath: '' }, parentElement: rootEl };
    const img2 = Object.assign({}, img, { parentElement: frame2, src: '/b.jpg' });
    await K2.prepareImagesForCapture({ querySelectorAll: () => [img2] });
    ok('an ordinary photo is baked without any clip', !calls.some((c) => c[0] === 'clip') && calls.some((c) => c[0] === 'drawImage'));

    // A frame whose clip-path is not a polygon is ignored, never an error.
    const frame3 = { style: { clipPath: 'circle(50%)' }, parentElement: rootEl };
    const img3 = Object.assign({}, img, { parentElement: frame3, src: '/c.jpg' });
    calls.length = 0;
    await K2.prepareImagesForCapture({ querySelectorAll: () => [img3] });
    ok('a non-polygon clip-path is ignored', !calls.some((c) => c[0] === 'clip'));
}

section('Nothing else in the kernel still depends on clip-path for a shape that is exported');
{
    const kernelShapes = TYPES.map((t) => K.contentHtml(el({ field: 'shape', shapeType: t }), {}, {})).join('');
    const maskedPhotos = ['arch', 'blob', 'circle', 'rounded'].map((m) => K.frameStyle(el({ field: 'image_1', photoMask: m }))).join('');
    ok('Shape elements and the B.9-1 photo masks contain no clip-path', !/clip-path/.test(kernelShapes + maskedPhotos));
}

console.log('\n' + (fail ? 'FAILED ' + fail + ' of ' : 'ALL ') + (pass + fail) + ' checks' + (fail ? '' : ' passed'));
process.exit(fail ? 1 : 0);
