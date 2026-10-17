// AT-456 §B.9-1 — "existing saved templates render exactly as before".
//
// tests/js/fixtures/ad-legacy-snapshot.json holds a frozen set of OLD-shape elements (exactly what the
// Ad Builder's makeElement() produced before the B.9-1 building blocks existed, plus bare objects
// that lack every newer key, like a template saved months ago) together with what the render kernel
// printed for each of them BEFORE B.9-1 was written. This check re-renders them with the shipped
// kernel and fails if a single character differs.
//
// Run:    node tests/js/ad-legacy-snapshot.mjs                    (exit 0 = identical)
// Re-record (only ever with the PRE-change kernel):
//         node tests/js/ad-legacy-snapshot.mjs --write <path-to-old-kernel.js>
//
// AT-456 §B.9-2 — ONE deliberate change to old output, and only this one. html2canvas (the export) ignores
// `clip-path`, so the six polygon shapes (triangle, diamond, pentagon, hexagon, star, chevron) came out as plain
// rectangles in the exported ad. A Shape element is now an inline SVG <polygon>, and an Agent Image with no photo
// inside a polygon mask is the same polygon. Those renderings (and only those) are recorded under
// `exportSafe` in the fixture, and this check refuses them unless
//   - the element is a Shape / Agent Image whose shapeType is one of those six, and
//   - the polygon's points are EXACTLY the old clip-path's points (same geometry, so the canvas looks the same).
// Everything else must still be identical to the pre-B.9-1 kernel, character for character.
//         node tests/js/ad-legacy-snapshot.mjs --record-export-safe     (writes `exportSafe` from the current kernel)

import fs from 'fs';
import path from 'path';
import vm from 'vm';
import { fileURLToPath } from 'url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const fixturePath = path.join(root, 'tests/js/fixtures/ad-legacy-snapshot.json');

function loadKernel(file) {
    const sandbox = { window: {} };
    vm.createContext(sandbox);
    vm.runInContext(fs.readFileSync(file, 'utf8'), sandbox);
    return sandbox.window.CoreXAd;
}

const PROPS = {
    full: {
        title: 'SEASIDE VILLA', price: 'R 4 250 000', suburb: 'UMKOMAAS, KZN', property_type: 'HOUSE',
        features: '4 Bed · 3 Bath', features_list: ['Sea View', 'Pool', 'Solar'],
        beds: '4', baths: '3.0', garages: '2', parking: '1', size_m2: '320 M²', floor_size_m2: '320', land_size_m2: '800',
        reference: 'REF 12', address: '12 Marine Drive', status_badge: 'FOR SALE',
        image_1: '/storage/p/1.jpg', image_2: '/storage/p/2.jpg', image_3: '/storage/p/3.jpg', image_4: '/storage/p/4.jpg', image_5: '/storage/p/5.jpg',
        agent_name: 'JANE DOE', agent_email: 'jane@example.test', agent_phone: '082 000 0000', agent_designation: 'Property Practitioner',
        agent_avatar: '/storage/a/1.jpg', agent_2_name: '', agent_2_avatar: null, agency_name: 'EXAMPLE AGENCY', website: 'www.example.test',
        logo: '/storage/agency.png', watermark: 'EXAMPLE AGENCY',
    },
    sparse: { title: 'VACANT STAND', price: 'R 900 000', land_size_m2: '1200', floor_size_m2: '' },
    empty: {},
};

function build(K, elements) {
    const out = [];
    for (const el of elements) {
        for (const [pk, p] of Object.entries(PROPS)) {
            for (const ph of [true, false]) {
                const opts = { placeholders: ph };
                out.push({
                    key: `${el.field}#${el.id}|${pk}|${ph ? 'ph' : 'real'}`,
                    frame: K.frameStyle(el, {}),
                    content: K.contentHtml(el, p, opts),
                    text: K.textValue(el, p, opts),
                });
            }
        }
    }
    return out;
}

const mode = process.argv[2];
if (mode === '--write') {
    const K = loadKernel(path.resolve(process.argv[3]));
    const elements = [];
    let n = 0;
    // Everything the builder could place BEFORE B.9-1, freshly made.
    for (const f of K.FIELDS) {
        const e = K.makeElement(f.type, 10, 10, 1);
        e.id = ++n;
        elements.push(e);
    }
    // Variants that exercised the existing property surface.
    const v = (field, patch) => { const e = Object.assign(K.makeElement(field, 5, 5, 2), patch); e.id = ++n; elements.push(e); };
    for (const s of K.SHAPES) v('shape', { shapeType: s.type, bg: '#336699' });
    for (const s of K.SHAPES) v('agent_avatar', { shapeType: s.type });
    v('shape', { shapeType: undefined, borderRadius: 30 });
    v('shape', { shapeType: 'rounded', borderRadius: 12, shadowOn: true });
    v('line', { borderWidth: 6, color: '#ff0000', shadowOn: true });
    v('image_1', { borderRadius: 24, frameBorderWidth: 4, frameBorderColor: '#fff', shadowOn: true, rotation: 3, elOpacity: 0.8 });
    v('image_2', { objectFit: 'contain', borderRadius: 0 });
    v('custom_image', { src: '/storage/x.png', borderRadius: 10 });
    v('custom_video', { src: '/storage/x.mp4' });
    v('beds', { numberFormat: 'label', icon: 'bed', iconSize: 22 });
    v('baths', { numberFormat: 'label' });
    v('garages_or_parking', { numberFormat: 'label', icon: 'garage' });
    v('size_or_land', {});
    v('features', { selectedFeatures: ['Pool'] });
    v('features', { selectedFeatures: [] });
    v('title', { fontFamily: 'Playfair Display', shadowOn: true, bgOpacity: 0.7, bgColor: '#000000', borderRadius: 8, textAlign: 'center', verticalAlign: 'bottom' });
    v('price', { fontFamily: 'Bebas Neue', letterSpacing: 0.05, lineHeight: 1.1 });
    v('badge', { text: 'SOLD <b>NOW</b>' });
    v('custom_text', { text: 'Hello "world" & friends' });
    v('watermark', { text: '' });
    v('logo', {});
    v('agency_logo', {});
    v('gradient', { gradAngle: 45 });
    v('color_block', { borderRadius: 12 });
    // Bare, minimal objects — what a template saved long ago looks like (none of the later keys).
    for (const field of ['price', 'title', 'image_1', 'shape', 'line', 'badge', 'agent_avatar', 'color_block', 'gradient', 'beds']) {
        elements.push({ id: ++n, field, x: 12, y: 14, w: 220, h: 80, zIndex: 3, fontSize: 20, color: '#ffffff', text: field === 'badge' ? 'NEW' : undefined });
    }
    // JSON round-trip so the stored elements are exactly what a saved layout_json would hand back.
    const frozen = JSON.parse(JSON.stringify(elements));
    fs.writeFileSync(fixturePath, JSON.stringify({ props: PROPS, elements: frozen, expected: build(K, frozen) }, null, 1));
    console.log('wrote ' + frozen.length + ' elements, ' + build(K, frozen).length + ' renderings → ' + path.relative(root, fixturePath));
    process.exit(0);
}

const K = loadKernel(path.join(root, 'public/js/corex-ad-render.js'));
const fx = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));
const now = build(K, fx.elements);

const POLY_TYPES = ['triangle', 'diamond', 'pentagon', 'hexagon', 'star', 'chevron'];
const elementOf = (key) => fx.elements.find((e) => key.startsWith(`${e.field}#${e.id}|`));
/** "polygon(50% 0,100% 100%,0 100%)" -> "50,0 100,100 0,100" */
const clipPoints = (str) => {
    const m = /polygon\(([^)]*)\)/.exec(str);
    return m ? m[1].split(',').map((p) => p.trim().split(/\s+/).map(parseFloat).join(',')).join(' ') : null;
};
const svgPoints = (str) => { const m = /<polygon points="([^"]*)"/.exec(str); return m ? m[1] : null; };

if (mode === '--record-export-safe') {
    const exportSafe = {};
    now.forEach((r, i) => {
        const e = fx.expected[i];
        if (['frame', 'content', 'text'].some((k) => r[k] !== e[k])) exportSafe[r.key] = { frame: r.frame, content: r.content, text: r.text };
    });
    fs.writeFileSync(fixturePath, JSON.stringify({ props: fx.props, elements: fx.elements, expected: fx.expected, exportSafe }, null, 1));
    console.log('recorded ' + Object.keys(exportSafe).length + ' export-safe renderings');
    process.exit(0);
}

const exportSafe = fx.exportSafe || {};
let bad = 0;
if (now.length !== fx.expected.length) { console.log('FAIL  rendering count changed'); bad++; }
now.forEach((r, i) => {
    const e = fx.expected[i];
    const safe = exportSafe[r.key];
    if (safe) {
        // The one allowed change: a polygon drawn as SVG instead of clip-path, with the very same points.
        const el = elementOf(r.key);
        const old = e.frame + e.content;
        const legit = el && ['shape', 'agent_avatar'].includes(el.field) && POLY_TYPES.includes(el.shapeType)
            && clipPoints(old) !== null && clipPoints(old) === svgPoints(r.content)
            && !/clip-path/.test(r.content)
            && r.frame === e.frame && r.text === e.text;           // the canvas frame (incl. an avatar's clip-path) is untouched
        if (!legit) { bad++; console.log(`FAIL  ${r.key}: not an allowed export-safe change\n   was: ${String(old).slice(0, 300)}\n   now: ${String(r.content).slice(0, 300)}`); }
        for (const k of ['frame', 'content', 'text']) {
            if (r[k] !== safe[k]) { bad++; console.log(`FAIL  ${r.key} (${k}) differs from the recorded export-safe rendering`); }
        }
        return;
    }
    for (const k of ['frame', 'content', 'text']) {
        if (r[k] !== e[k]) {
            bad++;
            if (bad <= 8) console.log(`FAIL  ${r.key} (${k})\n   was: ${String(e[k]).slice(0, 300)}\n   now: ${String(r[k]).slice(0, 300)}`);
        }
    }
});
const nSafe = Object.keys(exportSafe).length;
// The polygon shapes must actually be covered (a fixture without them would pass this check for the wrong reason).
const coveredTypes = new Set(Object.keys(exportSafe).map((k) => (elementOf(k) || {}).shapeType));
for (const t of POLY_TYPES) if (!coveredTypes.has(t)) { bad++; console.log(`FAIL  no export-safe rendering covers the ${t} shape`); }
console.log(bad ? `FAILED: ${bad} difference(s) across ${now.length} renderings`
    : `ALL ${now.length} legacy renderings identical to the pre-B.9-1 kernel (${nSafe} polygon-shape renderings changed on purpose: same points, drawn as SVG so the export matches)`);
process.exit(bad ? 1 : 0);
