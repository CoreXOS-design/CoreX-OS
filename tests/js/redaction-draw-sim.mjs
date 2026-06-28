// AT-110 — Redaction draw-handler simulation (closest the stack supports to a
// browser test; the repo has no JS test runner). It extracts the ACTUAL
// startDraw/moveDraw/endDraw/prepareSubmit handler bodies from the live blade
// (so it can never drift from the shipped code) and drives a simulated
// pointerdown -> pointermove -> pointerup sequence, asserting:
//   1. a box is added to the component's flat `boxes` array on release, and
//   2. prepareSubmit() maps that display box to the correct RASTER pixels that
//      feed the (server-proven) redact POST.
//
// Run:  node tests/js/redaction-draw-sim.mjs   (exit 0 = pass, 1 = fail)

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const blade = fs.readFileSync(path.join(root, 'resources/views/command-center/viewing-packs/show.blade.php'), 'utf8');

function body(name) {
    const m = blade.match(new RegExp(name + '\\(([^)]*)\\)\\s*\\{([\\s\\S]*?)\\n        \\},'));
    if (!m) throw new Error('could not extract handler: ' + name);
    return new Function(...m[1].split(',').map(s => s.trim()).filter(Boolean), m[2]);
}
const startDraw = body('startDraw'), moveDraw = body('moveDraw'), endDraw = body('endDraw'), prepareSubmit = body('prepareSubmit');

const ctx = {
    pages: [{ index: 0, width: 1240, height: 1754 }],
    boxes: [],
    drag: { active: false, page: null, startX: 0, startY: 0, x: 0, y: 0, w: 0, h: 0 },
    boxesFor(p) { return this.boxes.filter(b => b.page === p); },
};
// image rendered on screen at (100,50), displayed 800x1131 (raster is 1240x1754)
const overlay = { getBoundingClientRect: () => ({ left: 100, top: 50, width: 800, height: 1131 }), setPointerCapture() {}, releasePointerCapture() {} };
const ev = (x, y) => ({ clientX: x, clientY: y, pointerId: 1, currentTarget: overlay });

// press (200,150) -> drag to (500,250) -> release
startDraw.call(ctx, ev(200, 150), 0);
moveDraw.call(ctx, ev(500, 250), 0);
endDraw.call(ctx, ev(500, 250), 0);

// prepareSubmit with a faked DOM (img clientWidth 800 -> scale 1.55)
const emitted = [];
globalThis.document = {
    createElement: () => ({}),
    querySelector: (sel) => sel.includes('vp-redact-page-img') ? { clientWidth: 800, clientHeight: 1131 } : null,
};
ctx.$refs = { boxesFields: { innerHTML: '', appendChild(n) { emitted.push(n); } } };
prepareSubmit.call(ctx);
const f = {};
emitted.forEach(n => { f[n.name] = n.value; });

const checks = [
    ['box added on release', ctx.boxes.length === 1],
    ['box on correct page', ctx.boxes[0] && ctx.boxes[0].page === 0],
    ['box display coords', ctx.boxes[0] && ctx.boxes[0].x === 100 && ctx.boxes[0].y === 100 && ctx.boxes[0].w === 300 && ctx.boxes[0].h === 100],
    ['raster x = 155 (100*1.55)', f['boxes[0][0][x]'] === 155],
    ['raster w = 465 (300*1.55)', f['boxes[0][0][w]'] === 465],
];
let ok = true;
for (const [label, pass] of checks) { console.log((pass ? 'PASS' : 'FAIL') + ' — ' + label); ok = ok && pass; }
console.log(ok ? '\nOK: drag draws a box and feeds correct raster coords to the redact POST.' : '\nFAILED');
process.exit(ok ? 0 : 1);
