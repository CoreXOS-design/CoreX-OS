// AT-452 (.ai/specs/rentals-faults-work-orders.md §18.2) — the shared photo viewer's pure maths (fit, zoom about a point, pan limits, swipe,
// drawing and moving marks in normalised coordinates) and the file's page-level promises. The browser behaviour (popup, keys, focus trap,
// swipe, draw) is exercised for real in scripts/photo-viewer-browser-check.mjs.
//   node --test tests/js/photo-viewer.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const blade = fs.readFileSync(path.join(here, '../../resources/views/partials/photo-viewer.blade.php'), 'utf8');
const script = blade.slice(blade.indexOf('<script>') + 8, blade.lastIndexOf('</script>'));
const sandbox = { window: {}, document: {}, Image: function () {}, setTimeout, clearTimeout, console, Math, JSON, Object, Date, Array, parseInt, parseFloat, isNaN };
sandbox.window = sandbox;
vm.createContext(sandbox);
vm.runInContext(script, sandbox);
const V = sandbox.CorexPhotoViewer;
const M = V._math;
const near = (a, b, eps = 1e-6) => assert.ok(Math.abs(a - b) < eps, `${a} !~ ${b}`);

test('the viewer loads without touching the page and exposes open / close / isOpen', () => {
  assert.equal(typeof V.open, 'function');
  assert.equal(V.isOpen(), false);
  assert.doesNotThrow(() => V.open({ photos: [] }), 'nothing to show opens nothing');
});

test('a photo is fitted whole into the box, never stretched', () => {
  assert.deepEqual({ ...M.fitSize(2000, 1000, 500, 500) }, { w: 500, h: 250 });
  assert.deepEqual({ ...M.fitSize(1000, 2000, 500, 500) }, { w: 250, h: 500 });
  assert.deepEqual({ ...M.fitSize(0, 0, 500, 500) }, { w: 0, h: 0 });
});

test('zooming keeps the point under the finger fixed, stays within 1x-8x, and the picture never leaves the box', () => {
  const fig = { w: 500, h: 250 }, box = { w: 500, h: 500 };
  const v = M.zoomAt(fig, box, 1, 0, 0, 2, 250, 250);
  assert.equal(v.z, 2);
  // the centre of the picture is still at the centre of the box
  near((box.w - fig.w) / 2 + v.x + 250 * 2, 250);   // figure-left + pan + (figure point 250) * z = screen x of figure point 250: still the box centre
  assert.equal(M.zoomAt(fig, box, 1, 0, 0, 99, 0, 0).z, 8);
  assert.equal(M.zoomAt(fig, box, 2, 0, 0, 0.1, 0, 0).z, 1);
  const edge = M.zoomAt(fig, box, 1, 0, 0, 4, 0, 0);
  const left = (box.w - fig.w) / 2 + edge.x;
  assert.ok(left <= 0 && left >= box.w - fig.w * 4, 'the left edge cannot be dragged inside the box or past the far edge');
});

test('at 1x the picture is centred and cannot be panned away', () => {
  const p = M.clampPan({ w: 500, h: 250 }, { w: 500, h: 500 }, 1, 120, -80);
  assert.deepEqual({ ...p }, { x: 0, y: 0 });
});

test('a point on screen becomes 0-1 inside the picture, clamped to its edges', () => {
  const rect = { left: 100, top: 50, width: 400, height: 200 };
  assert.deepEqual({ ...M.toNormalised(rect, 300, 150) }, { x: 0.5, y: 0.5 });
  assert.deepEqual({ ...M.toNormalised(rect, 0, 9999) }, { x: 0, y: 1 });
});

test('a circle is dragged out from its centre with a radius relative to the picture WIDTH; an arrow from its tail', () => {
  // 1200 x 800 photo: aspect 800/1200. A drag of 0.1 across and 0.15 down is a radius of 0.1 wide-units (0.15*0.6667=0.1)
  const c = M.shapeFromDrag('circle', { x: 0.5, y: 0.5 }, { x: 0.6, y: 0.65 }, 800 / 1200);
  near(c.r, Math.sqrt(0.1 * 0.1 + 0.1 * 0.1), 1e-4);
  assert.deepEqual({ ...M.shapeFromDrag('arrow', { x: 0.1, y: 0.2 }, { x: 0.7, y: 0.9 }, 0.5) }, { x1: 0.1, y1: 0.2, x2: 0.7, y2: 0.9 });
});

test('moving a mark keeps all of it inside the picture and rounds to 4 decimals', () => {
  assert.deepEqual({ ...M.moveGeometry('circle', { cx: 0.5, cy: 0.5, r: 0.1 }, 0.2, -0.7) }, { cx: 0.7, cy: 0, r: 0.1 });
  const a = M.moveGeometry('arrow', { x1: 0.1, y1: 0.1, x2: 0.5, y2: 0.4 }, -0.3, 0.9);
  near(a.x1, 0); near(a.x2, 0.4); near(a.y2, 1); near(a.y1, 0.7);   // the arrow keeps its shape and stops at the edge
});

test('a swipe is a mostly-horizontal drag of 50px or more', () => {
  assert.equal(M.swipeDirection(-80, 5), 1);
  assert.equal(M.swipeDirection(80, 5), -1);
  assert.equal(M.swipeDirection(30, 0), 0);
  assert.equal(M.swipeDirection(60, 90), 0, 'a mostly-vertical drag is not a swipe');
});

test('the page-level promises are in the file: close on X / Esc / backdrop, arrows, counter, zoom, toggle, focus trap, no browser dialogs', () => {
  for (const needle of ["e.key === 'Escape'", "e.key === 'ArrowLeft'", "e.key === 'ArrowRight'", "e.key === 'Tab'", "' of ' + n", 'data-pv-marks-toggle', "Agent's marks and notes", 'data-pv-close', 'data-pv-zoom-in', 'data-pv-zoom-out', 'data-pv-zoom-reset', "addEventListener('wheel'", "addEventListener('pointerdown'", 'aria-modal', 'returnFocus', 'swipeDirection(']) {
    assert.ok(script.includes(needle), needle);
  }
  assert.doesNotMatch(script, /\b(window\.)?(alert|confirm|prompt)\(/, 'no browser dialogs');
  assert.doesNotMatch(script, /window\.open\(|target=.?_blank/, 'never a new tab');
  assert.ok(script.includes('thumb_url'), 'the strip and first paint use the existing small copies');
});

test('the tick is on by default and it hides marks AND the note when off', () => {
  assert.match(script, /checked: 'checked', 'data-pv-marks-toggle'/);
  assert.match(script, /if \(!s\.marksOn\) return;/, 'marks are not drawn when off');
  assert.match(script, /if \(!s\.marksOn\) \{ box\.hidden = true; return; \}/, 'the description is hidden when off');
});
