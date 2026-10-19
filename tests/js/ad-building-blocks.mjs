// AT-456 §B.9-1 — template builder building blocks (render kernel checks).
//
// Loads the ACTUAL shipped kernel — public/js/corex-ad-render.js — and pins the contract of every block
// B.9-1 added: 47 icons, 20 fonts (12 lazy), decorative shapes, line styles, photo masks, photo effects,
// collage, corner ribbon, QR code, feature pills and the listing-fact fields. The rule they all share is that
// the picture must be the same on the builder canvas and in the exported image; the export-specific half
// (html2canvas) is proven separately with a real browser (see the B.9-1 notes in the spec).
//
// Run:  node tests/js/ad-building-blocks.mjs      (exit 0 = pass, 1 = fail)

import fs from 'fs';
import path from 'path';
import vm from 'vm';
import { fileURLToPath } from 'url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const code = fs.readFileSync(path.join(root, 'public/js/corex-ad-render.js'), 'utf8');

function load(extra = {}) {
    const sandbox = Object.assign({ window: {} }, extra);
    // the kernel reads document/window through its own `root` (= window) and `typeof document`
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

const prop = {
    image_1: '/p/1.jpg', image_2: '/p/2.jpg', image_3: '/p/3.jpg', image_4: '/p/4.jpg', image_5: '/p/5.jpg',
    features_list: ['Sea View', 'Pool', 'Solar', 'Security', 'Garden'],
    erf_size: '800 m²', levy: 'R 1 250 pm', rates_taxes: 'R 900 pm', price_per_m2: 'R 7 656 per m²', pet_friendly: 'Pet friendly',
    available_from: 'Available now', rent_per_month: 'R 12 500 per month', show_day: 'Sat 25 Oct · 10:00–13:00',
    auction_date: '12 Nov 2026 · 11:00', auction_venue: 'Sample Venue',
    qr_matrix: JSON.parse(fs.readFileSync(path.join(root, 'tests/js/fixtures/qr-sample-matrix.json'), 'utf8')),
};

section('Icons (35 more, same single-path family, lower_snake_case keys, no brand marks)');
{
    const keys = K.ICON_LIST.map(i => i.key);
    ok('47 icons in the picker (12 original + 35 new)', keys.length === 47, keys.length);
    ok('every picker key has an SVG and every SVG is in the picker', keys.every(k => K.ICONS[k]) && Object.keys(K.ICONS).length === keys.length);
    ok('keys are lower_snake_case', keys.every(k => /^[a-z]+(_[a-z]+)*$/.test(k)), keys.filter(k => !/^[a-z]+(_[a-z]+)*$/.test(k)).join());
    ok('every icon follows the text colour (fill=currentColor) and fills its box',
        keys.every(k => K.ICONS[k].includes('fill="currentColor"') && K.ICONS[k].includes('width="100%"')));
    const wanted = ['sea_view', 'mountain_view', 'pet_friendly', 'solar', 'security', 'cctv', 'alarm', 'braai', 'fireplace', 'air_con', 'garden', 'flatlet',
        'wifi', 'golf_estate', 'school', 'shopping', 'beach', 'estate_gated', 'borehole', 'backup_power', 'lift', 'gym', 'study', 'kitchen', 'laundry',
        'balcony', 'calendar', 'clock', 'phone', 'email', 'chat', 'star', 'heart', 'check', 'sparkle'];
    ok('all 35 icons from the spec are present', wanted.every(k => keys.includes(k)), wanted.filter(k => !keys.includes(k)).join());
    ok('no brand marks (no WhatsApp / Facebook / Instagram / Google keys or labels)',
        !K.ICON_LIST.some(i => /whatsapp|facebook|instagram|twitter|google|youtube|tiktok|linkedin/i.test(i.key + ' ' + i.label)));
    const t = K.contentHtml(el({ field: 'agent_phone', icon: 'phone', iconSize: 20 }), { agent_phone: '082 000 0000' }, {});
    ok('a new icon renders inline before the text of ANY text element', t.includes('<svg') && t.includes('082 000 0000'), t);
}

section('Fonts (12 more, lazy)');
{
    const names = K.FONTS.map(f => f.name);
    const added = ['Cormorant Garamond', 'DM Serif Display', 'Libre Baskerville', 'Great Vibes', 'Dancing Script', 'Barlow Condensed', 'Anton', 'Nunito', 'Quicksand', 'Raleway', 'Work Sans', 'Space Grotesk'];
    ok('20 fonts: the original 8 untouched and first, then the 12 new', names.length === 20 && added.every(n => names.includes(n)) && names[0] === 'Figtree' && names[7] === 'Lora', names.join());
    ok('exactly the 12 new fonts are lazy', K.FONTS.filter(f => f.lazy).map(f => f.name).sort().join() === added.slice().sort().join());
    ok('script fonts carry a "headlines only" note', ['Great Vibes', 'Dancing Script'].every(n => /headline/i.test(K.FONTS.find(f => f.name === n).note || '')));

    const links = [];
    const doc = {
        head: { appendChild: (l) => { links.push(l); setTimeout(() => l.onload && l.onload(), 0); } },
        createElement: () => ({}),
        fonts: { load: () => Promise.resolve([]) },
    };
    const K2 = load({ document: doc, setTimeout, Promise });
    K2.fontStack('Inter'); K2.fontStack('Playfair Display');
    ok('a template that uses only the original fonts loads nothing extra', links.length === 0);
    // the lazy map comes from _ad-fonts.blade.php
    const K3 = (() => { const sb = { window: { CoreXAdLazyFonts: { 'Great Vibes': 'great-vibes:400', 'Raleway': 'raleway:400,700' } }, document: doc, setTimeout, Promise };
        sb.window.document = doc; vm.createContext(sb); vm.runInContext(code, sb); return sb.window.CoreXAd; })();
    const stack = K3.fontStack('Great Vibes'); K3.fontStack('Great Vibes'); K3.fontStack('Great Vibes');
    ok('a lazy font adds its stylesheet the first time it is drawn, once only', links.length === 1 && /family=great-vibes:400/.test(links[0].href), JSON.stringify(links.map(l => l.href)));
    ok('and its stack is returned straight away (no wait to draw)', stack.includes("'Great Vibes'"));
    K3.fontStack('Raleway');
    ok('a second lazy font adds its own stylesheet', links.length === 2);
    ok('fontStack of an unknown name still falls back to Figtree', K3.fontStack('Comic Sans') === K3.FONTS[0].stack);
}

section('Decorative shapes (SVG, so the export matches) and line styles');
{
    for (const t of ['wave', 'curve_top', 'curve_bottom', 'arc', 'blob_1', 'blob_2', 'blob_3']) {
        const h = K.contentHtml(el({ field: 'shape', shapeType: t, bg: '#2a9d8f', opacity: 0.5 }), prop, {});
        ok(t + ' draws an inline SVG path in the fill colour and opacity', h.startsWith('<svg') && h.includes('d="') && h.includes('fill="#2a9d8f"') && h.includes('fill-opacity="0.5"'), h);
    }
    ok('the picker lists the 7 decorative shapes (3 of them blobs)', K.DECOR_SHAPE_LIST.length === 7 && K.DECOR_SHAPE_LIST.filter(s => /^blob/.test(s.type)).length === 3);
    ok('the existing SHAPES list (also the avatar mask picker) is unchanged', K.SHAPES.length === 10 && !K.SHAPES.some(s => K.DECOR_SHAPES[s.type]));
    ok('a decorative shape cannot carry a drop shadow (like a clip shape)', K.canShadow(el({ field: 'shape', shapeType: 'wave' })) === false);
    ok('a hostile fill colour never reaches the markup',
        !K.contentHtml(el({ field: 'shape', shapeType: 'wave', bg: 'red;"><script>alert(1)</script>' }), prop, {}).includes('script'));

    const dashed = K.contentHtml(el({ field: 'line', lineStyle: 'dashed', borderWidth: 4, color: '#ffffff', w: 300 }), prop, {});
    const dotted = K.contentHtml(el({ field: 'line', lineStyle: 'dotted', borderWidth: 6, color: '#ffffff', w: 300 }), prop, {});
    ok('a dashed line is an SVG line with a dash pattern', dashed.includes('stroke-dasharray="12 8"') && dashed.includes('stroke-linecap="butt"'), dashed);
    ok('a dotted line is round dots', dotted.includes('stroke-dasharray="0 12"') && dotted.includes('stroke-linecap="round"'), dotted);
    ok('a solid (or unset) line is the original bar, byte for byte',
        K.contentHtml(el({ field: 'line', lineStyle: 'solid' }), prop, {}) === K.contentHtml(el({ field: 'line', lineStyle: undefined }), prop, {})
        && !K.contentHtml(el({ field: 'line', lineStyle: 'solid' }), prop, {}).includes('<svg'));
    ok('a dashed line has no drop shadow option', K.canShadow(el({ field: 'line', lineStyle: 'dashed' })) === false && K.canShadow(el({ field: 'line', lineStyle: 'solid' })) === true);
}

section('Photo masks (rounded corners only: the export draws them exactly)');
{
    const f = (o) => K.frameStyle(el(Object.assign({ field: 'image_1', w: 400, h: 300 }, o)));
    ok('none / unset leaves the frame as it was', f({ photoMask: 'none', borderRadius: 12 }).includes('border-radius:12px;') && f({}).includes('border-radius:0px;'));
    ok('rounded uses the element radius, 24px when it is 0', f({ photoMask: 'rounded', borderRadius: 40 }).includes('border-radius:40px;') && f({ photoMask: 'rounded', borderRadius: 0 }).includes('border-radius:24px;'));
    ok('circle', f({ photoMask: 'circle' }).includes('border-radius:50%;'));
    ok('arch = a half-width curve on the top two corners only', f({ photoMask: 'arch' }).includes('border-radius:200px 200px 0 0 / 200px 200px 0 0;'), f({ photoMask: 'arch' }));
    ok('arch on a short box never curves more than its height', f({ photoMask: 'arch', h: 90 }).includes('/ 90px 90px 0 0;'));
    ok('blob', f({ photoMask: 'blob' }).includes('border-radius:63% 37% 54% 46% / 55% 48% 52% 45%;'));
    ok('works on a custom image too', K.frameStyle(el({ field: 'custom_image', photoMask: 'circle' })).includes('50%'));
    ok('a mask on a text element is ignored', !K.frameStyle(el({ field: 'title', photoMask: 'circle' })).includes('50%'));
    ok('a frame border sits on the same frame, so it follows the curve',
        f({ photoMask: 'arch', frameBorderWidth: 6, frameBorderColor: '#ffffff' }).includes('border:6px solid #ffffff;'));
}

section('Photo effects (one colour matrix: SVG filter live, same numbers on the export pixels)');
{
    ok('none / unset has no effect markup at all',
        !K.contentHtml(el({ field: 'image_1', photoEffect: 'none' }), prop, {}).includes('filter') && !K.contentHtml(el({ field: 'image_1' }), prop, {}).includes('data-fxm'));
    for (const e of ['bw', 'duotone', 'warm', 'cool', 'brighter']) {
        const m = K.effectMatrix(el({ field: 'image_1', photoEffect: e }));
        const html = K.contentHtml(el({ field: 'image_1', photoEffect: e }), prop, {});
        const carried = (/data-fxm="([^"]+)"/.exec(html) || [])[1];
        ok(e + ': a 4×5 matrix, carried on the <img> for the export and in the live SVG filter',
            m && m.length === 20 && carried && carried.split(' ').map(Number).every((n, i) => Math.abs(n - m[i]) < 1e-4)
            && html.includes('<feColorMatrix type="matrix" values="' + carried + '"') && html.includes('color-interpolation-filters="sRGB"') && /filter:url\(#cxfx\d+\)/.test(html), html);
    }
    // the export half: same matrix on pixels
    const px = (rgb, m) => Array.from(K.applyColorMatrix(new Uint8ClampedArray([rgb[0], rgb[1], rgb[2], 255]), m)).slice(0, 3);
    const bw = K.effectMatrix(el({ field: 'image_1', photoEffect: 'bw' }));
    const r = px([255, 0, 0], bw);
    ok('black & white turns pure red into its luminance grey on all three channels', r[0] === r[1] && r[1] === r[2] && Math.abs(r[0] - 54) <= 1, r.join());
    const duo = K.effectMatrix(el({ field: 'image_1', photoEffect: 'duotone', duoColor1: '#102030', duoColor2: '#f0e0d0' }));
    ok('duotone maps black to the dark colour and white to the light colour', px([0, 0, 0], duo).join() === '16,32,48' && px([255, 255, 255], duo).join() === '240,224,208', px([0, 0, 0], duo).join() + ' / ' + px([255, 255, 255], duo).join());
    ok('warm lifts red and lowers blue; cool does the opposite',
        px([128, 128, 128], K.effectMatrix(el({ field: 'image_1', photoEffect: 'warm' })))[0] > 128 && px([128, 128, 128], K.effectMatrix(el({ field: 'image_1', photoEffect: 'warm' })))[2] < 128
        && px([128, 128, 128], K.effectMatrix(el({ field: 'image_1', photoEffect: 'cool' })))[2] > 128 && px([128, 128, 128], K.effectMatrix(el({ field: 'image_1', photoEffect: 'cool' })))[0] < 128);
    ok('brighter lightens and clamps at white', px([128, 128, 128], K.effectMatrix(el({ field: 'image_1', photoEffect: 'brighter' })))[0] > 128 && px([250, 250, 250], K.effectMatrix(el({ field: 'image_1', photoEffect: 'brighter' })))[0] === 255);
    ok('alpha is never touched', K.applyColorMatrix(new Uint8ClampedArray([10, 20, 30, 77]), bw)[3] === 77);
    ok('a hostile duotone colour falls back to the default pair',
        K.effectMatrix(el({ field: 'image_1', photoEffect: 'duotone', duoColor1: 'red;"><x>', duoColor2: 'url(x)' })).join() === K.effectMatrix(el({ field: 'image_1', photoEffect: 'duotone' })).join());
    ok('an effect on a text element is ignored', !K.contentHtml(el({ field: 'title', photoEffect: 'bw' }), prop, {}).includes('data-fxm'));
    const two = K.contentHtml(el({ field: 'image_1', photoEffect: 'bw' }), prop, {}) + K.contentHtml(el({ field: 'image_1', photoEffect: 'bw' }), prop, {});
    ok('each drawn element gets its own filter id (a hidden twin can never break a visible one)', new Set(two.match(/id="cxfx\d+"/g)).size === 2);
}

section('Collage (2–5 listing photos, six presets)');
{
    const presets = K.COLLAGE_PRESETS;
    ok('the six presets from the spec', presets.map(p => p.key).join() === '2_side,2_stack,3_hero_left,3_hero_top,4_grid,5_hero' && presets.map(p => p.count).join() === '2,2,3,3,4,5');
    for (const p of presets) {
        const cells = K.collageCells(p.key, 400, 300, 10);
        const inside = cells.every(c => c.x >= 0 && c.y >= 0 && c.x + c.w <= 400 && c.y + c.h <= 300 && c.w > 0 && c.h > 0);
        let overlap = false;
        for (let i = 0; i < cells.length; i++) for (let j = i + 1; j < cells.length; j++) {
            const a = cells[i], b = cells[j];
            if (a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h) overlap = true;
        }
        ok(p.key + ': ' + p.count + ' cells, all inside the box, none overlapping', cells.length === p.count && inside && !overlap, JSON.stringify(cells));
    }
    const side = K.collageCells('2_side', 400, 300, 10);
    ok('the gap really is the gap (2 side by side: 10px between)', side[1].x - (side[0].x + side[0].w) === 10 && side[0].x === 0 && side[1].x + side[1].w === 400, JSON.stringify(side));
    const hero = K.collageCells('3_hero_left', 400, 300, 10);
    ok('hero-left: the big photo is on the left and full height', hero[0].h === 300 && hero[0].w > hero[1].w && hero[1].x === hero[2].x);
    ok('a silly gap is capped so photos never vanish', K.collageCells('4_grid', 100, 100, 500).every(c => c.w > 10 && c.h > 10));

    ok('with enough photos the chosen preset is used', K.collageEffectivePreset('5_hero', 5) === '5_hero' && K.collageEffectivePreset('4_grid', 5) === '4_grid');
    ok('fewer photos than the preset needs → the nearest smaller layout, never empty holes',
        K.collageEffectivePreset('5_hero', 4) === '4_grid' && K.collageEffectivePreset('5_hero', 3) === '3_hero_left' && K.collageEffectivePreset('3_hero_top', 2) === '2_stack'
        && K.collageEffectivePreset('4_grid', 2) === '2_side' && K.collageEffectivePreset('3_hero_left', 1) === 'single' && K.collageEffectivePreset('2_side', 0) === 'single');

    const html = K.contentHtml(el({ field: 'collage', collagePreset: '4_grid', collageGap: 8, borderRadius: 14, w: 400, h: 300 }), prop, {});
    ok('4_grid draws four cover-cropped photos with the corner radius', (html.match(/<img /g) || []).length === 4 && (html.match(/border-radius:14px/g) || []).length === 4 && html.includes('object-fit:cover'), html);
    const first = K.contentHtml(el({ field: 'collage', collagePreset: '2_side', collageFirst: 4 }), prop, {});
    ok('"Start from photo 4" uses image_4 then image_5', first.includes('src="/p/4.jpg"') && first.includes('src="/p/5.jpg"') && !first.includes('/p/1.jpg'));
    const few = K.contentHtml(el({ field: 'collage', collagePreset: '5_hero' }), { image_1: '/p/1.jpg', image_2: '/p/2.jpg' }, {});
    ok('a listing with two photos gets a two-photo layout from a five-photo preset', (few.match(/<img /g) || []).length === 2);
    ok('a listing with no photos: the collage is left off the finished ad', K.isBlankElement(el({ field: 'collage' }), {}, {}) === true && K.contentHtml(el({ field: 'collage' }), {}, {}) === '');
    ok('the builder still shows the chosen preset (empty boxes) while designing', (K.contentHtml(el({ field: 'collage', collagePreset: '3_hero_top' }), {}, { placeholders: true }).match(/position:absolute/g) || []).length === 3);
    const fx = K.contentHtml(el({ field: 'collage', collagePreset: '2_side', photoEffect: 'cool' }), prop, {});
    ok('a collage takes a photo effect: every photo carries it, one shared filter', (fx.match(/data-fxm=/g) || []).length === 2 && (fx.match(/<filter /g) || []).length === 1);
    const tagged = K.contentHtml(el({ field: 'collage', id: 9, collagePreset: '2_side' }), prop, { tagPhotos: true, overrides: { '9_c1': '/p/swap.jpg' } });
    ok('the generator\'s change-photo works per collage cell (override wins, original kept for reset)',
        tagged.includes('data-el-id="9_c0"') && tagged.includes('data-el-id="9_c1"') && tagged.includes('src="/p/swap.jpg"') && tagged.includes('data-orig-src="/p/2.jpg"'), tagged);
    ok('a hostile photo URL is escaped', K.contentHtml(el({ field: 'collage', collagePreset: '2_side' }), { image_1: '"><script>x</script>', image_2: '/p/2.jpg' }, {}).indexOf('<script>') === -1);
}

section('Corner ribbon');
{
    const rib = (o) => K.contentHtml(el(Object.assign({ field: 'ribbon', w: 200, h: 200, text: 'JUST LISTED' }, o)), prop, {});
    ok('top-left runs up to the right (-45°), top-right down to the right (+45°)', rib({ ribbonCorner: 'tl' }).includes('rotate(-45deg)') && rib({ ribbonCorner: 'tr' }).includes('rotate(45deg)'));
    ok('bottom-left +45°, bottom-right -45°', rib({ ribbonCorner: 'bl' }).includes('rotate(45deg)') && rib({ ribbonCorner: 'br' }).includes('rotate(-45deg)'));
    ok('the band is centred on the right corner region of the box', (() => {
        const m = /left:(-?\d+)px;top:(-?\d+)px;width:(\d+)px;height:(\d+)px/.exec(rib({ ribbonCorner: 'tr' }));
        const cx = +m[1] + m[3] / 2, cy = +m[2] + m[4] / 2; return Math.abs(cx - 125) <= 1 && Math.abs(cy - 75) <= 1; })());
    ok('the ribbon text is escaped', rib({ text: '<img src=x onerror=1>' }).includes('&lt;img') && !rib({ text: '<img src=x onerror=1>' }).includes('<img'));
    ok('colours go through the strict gate', !rib({ bgColor: 'red;"><script>', color: 'url(x)' }).includes('script') && rib({ bgColor: 'red;"><script>' }).includes('rgba(230,57,70,1)'));
    ok('no words → nothing to draw on a finished ad', K.isBlankElement(el({ field: 'ribbon', text: '  ' }), prop, {}) === true && K.isBlankElement(el({ field: 'ribbon', text: 'NEW' }), prop, {}) === false);
    ok('a ribbon has no drop shadow option', K.canShadow(el({ field: 'ribbon' })) === false);
    ok('its frame clips the band (overflow hidden)', K.frameStyle(el({ field: 'ribbon' })).includes('overflow:hidden'));
}

section('QR code to the listing');
{
    const q = (o, p, opts) => K.contentHtml(el(Object.assign({ field: 'qr', w: 180, h: 180 }, o)), p === undefined ? prop : p, opts || {});
    const html = q({});
    const n = prop.qr_matrix.length;
    ok('one inline SVG sized to the box, with a 4-module quiet zone', html.includes('viewBox="0 0 ' + (n + 8) + ' ' + (n + 8) + '"') && html.includes('width="180" height="180"') && html.includes('shape-rendering="crispEdges"'), html.slice(0, 300));
    ok('dark and light colours', html.includes('fill="#ffffff"') && html.includes('fill="#000000"') && q({ qrDark: '#12263f', qrLight: '#f4d58d' }).includes('fill="#f4d58d"'));
    // every dark module is in the path
    const dark = (prop.qr_matrix.join('').match(/1/g) || []).length;
    const d = /<path d="([^"]+)"/.exec(html)[1];
    const area = (d.match(/h(\d+)v1/g) || []).reduce((a, s) => a + parseInt(s.slice(1), 10), 0);
    ok('the path covers exactly the dark modules of the matrix', area === dark, area + ' vs ' + dark);
    ok('draws at the SMALLER side of a non-square box, centred', q({ w: 300, h: 200 }).includes('width="200" height="200"'));
    ok('a hostile colour never reaches the markup', !q({ qrDark: '"><script>' }).includes('script'));
    ok('a broken matrix draws nothing rather than a broken code', q({}, { qr_matrix: ['101', '010'] }) === '' && q({}, { qr_matrix: ['x'.repeat(21)].concat([]) }) === '');
    ok('no link → left off a finished ad', K.isBlankElement(el({ field: 'qr' }), {}, {}) === true && q({}, {}) === '');
    ok('the builder with no property shows a neutral sample code', q({}, {}, { placeholders: true }).includes('<svg') && K.isBlankElement(el({ field: 'qr' }), {}, { placeholders: true }) === false);
    ok('minimum size 120 px for a QR, 8 for everything else', K.minSize(el({ field: 'qr' })) === 120 && K.minSize(el({ field: 'title' })) === 8);
}

section('Feature pills');
{
    const pills = (o, p, opts) => K.contentHtml(el(Object.assign({ field: 'feature_pills', w: 520, h: 50 }, o)), p === undefined ? prop : p, opts || {});
    const count = (h) => (h.match(/<span /g) || []).length;
    ok('default: the first 4 of the listing\'s own features', K.pillsFor(el({ field: 'feature_pills' }), prop, {}).join() === 'Sea View,Pool,Solar,Security' && count(pills({})) === 4);
    ok('pill count is capped at 4 and at least 1', K.pillsFor(el({ field: 'feature_pills', pillCount: 9 }), prop, {}).length === 4 && K.pillsFor(el({ field: 'feature_pills', pillCount: 0 }), prop, {}).length === 4 && K.pillsFor(el({ field: 'feature_pills', pillCount: 2 }), prop, {}).length === 2);
    ok('the agent chooses which (selectedFeatures)', K.pillsFor(el({ field: 'feature_pills', selectedFeatures: ['Garden', 'Pool'] }), prop, {}).join() === 'Pool,Garden');
    ok('a choice of nothing draws nothing', K.pillsFor(el({ field: 'feature_pills', selectedFeatures: [] }), prop, {}).length === 0 && K.isBlankElement(el({ field: 'feature_pills', selectedFeatures: [] }), prop, {}) === true);
    ok('a listing with no features: left off the finished ad', K.isBlankElement(el({ field: 'feature_pills' }), { features_list: [] }, {}) === true);
    ok('the builder with no features shows sample pills so the design is visible', count(pills({ pillCount: 3 }, {}, { placeholders: true })) === 3);
    ok('row vs column', pills({}).includes('flex-direction:row') && pills({ pillDirection: 'column' }).includes('flex-direction:column'));
    ok('gap and badge styling', pills({ pillGap: 14, bgColor: '#e63946', color: '#ffffff', borderRadius: 8 }).includes('gap:14px') && pills({ bgColor: '#e63946' }).includes('background:rgba(230,57,70,1)') && pills({ borderRadius: 8 }).includes('border-radius:8px'));
    ok('pill text is escaped', !pills({}, { features_list: ['<b>x</b>'] }).includes('<b>') && pills({}, { features_list: ['<b>x</b>'] }).includes('&lt;b&gt;'));
    ok('hostile colours are replaced', !pills({ bgColor: 'red;"><script>' }).includes('script'));
    ok('pills have no drop shadow option', K.canShadow(el({ field: 'feature_pills' })) === false);
}

section('Listing facts (erf size, levy, rates, price per m², pet friendly, available from, rent, show day, auction)');
{
    const fv = (field, p, o, opts) => K.textValue(el(Object.assign({ field }, o || {})), p === undefined ? prop : p, opts || {});
    ok('every fact is a plain text field of the Property group', K.FACT_FIELDS.every(f => K.FIELDS.some(x => x.type === f && x.group === 'property') && K.isTextField(f)));
    ok('labelled where a label helps: "Levy: R 1 250 pm", "Erf: 800 m²", "Rates & taxes: R 900 pm"', fv('levy') === 'Levy: R 1 250 pm' && fv('erf_size') === 'Erf: 800 m²' && fv('rates_taxes') === 'Rates & taxes: R 900 pm');
    ok('"Show day: …", "Auction: …", "Venue: …"', fv('show_day') === 'Show day: Sat 25 Oct · 10:00–13:00' && fv('auction_date') === 'Auction: 12 Nov 2026 · 11:00' && fv('auction_venue') === 'Venue: Sample Venue');
    ok('self-explanatory values carry no label', fv('price_per_m2') === 'R 7 656 per m²' && fv('pet_friendly') === 'Pet friendly' && fv('available_from') === 'Available now' && fv('rent_per_month') === 'R 12 500 per month');
    ok('showLabel off prints the bare value', fv('levy', prop, { showLabel: false }) === 'R 1 250 pm');
    for (const f of K.FACT_FIELDS) {
        const v = fv(f, {});
        ok(f + ': a listing without a value prints NOTHING on a real ad (no "null", no "R 0")', v === '' && K.isBlankElement(el({ field: f }), {}, {}) === true, v);
    }
    ok('"" / null / undefined from the server all count as no value', ['', null, undefined].every(x => fv('levy', { levy: x }) === ''));
    ok('the builder shows the sample text while designing, and never hides the element', fv('levy', {}, {}, { placeholders: true }) === 'Levy: R 1 250 pm' && K.isBlankElement(el({ field: 'levy' }), {}, { placeholders: true }) === false);
    ok('a fact with a value is never blank', K.isBlankElement(el({ field: 'levy' }), prop, {}) === false);
    ok('elements that existed before B.9-1 are never treated as blank (behaviour unchanged)', ['price', 'title', 'agent_name', 'image_1', 'shape'].every(f => K.isBlankElement(el({ field: f }), {}, {}) === false));
    ok('fact text is escaped when drawn', K.contentHtml(el({ field: 'levy' }), { levy: '<b>R 1</b>' }, {}).includes('&lt;b&gt;'));
}

section('renderLayout (what the generator and the bulk Ad Manager call) leaves off a block with nothing to show');
{
    const drawn = [];
    const fakeDoc = { createElement: () => ({ style: {}, set innerHTML(v) { this._h = v; }, get innerHTML() { return this._h; } }) };
    const K4 = load({ document: fakeDoc });
    const root = { style: {}, appendChild: (d) => drawn.push(d), querySelectorAll: () => [], set innerHTML(v) {}, get innerHTML() { return ''; } };
    const mk = (f, o) => Object.assign(K4.makeElement(f, 0, 0, 1), o || {});
    const layout = { canvasW: 1200, canvasH: 628, elements: [mk('title'), mk('levy'), mk('erf_size'), mk('feature_pills'), mk('qr'), mk('ribbon', { text: '' }), mk('collage')] };
    K4.renderLayout(layout, { title: 'VILLA', erf_size: '800 m²' }, root, {});
    ok('a real ad keeps the title and the erf size, and drops the levy, pills, QR, empty ribbon and photo-less collage', drawn.length === 2, drawn.length);
    drawn.length = 0;
    K4.renderLayout(layout, { title: 'VILLA' }, root, { placeholders: true });
    ok('the builder / template previews (placeholders) draw every element so it can be designed', drawn.length === 7, drawn.length);
}

section('Builder plumbing: palette, defaults, safe colours');
{
    const types = K.FIELDS.map(f => f.type);
    for (const t of ['collage', 'ribbon', 'qr', 'feature_pills', ...K.FACT_FIELDS]) ok('palette offers ' + t + ' with defaults', types.includes(t) && K.FIELD_DEFAULTS[t] && K.makeElement(t, 1, 2, 3).field === t);
    ok('no duplicate palette entries', new Set(types).size === types.length);
    const e = K.makeElement('shape', 0, 0, 1);
    ok('a new element starts with every new property at its "not set" value (so old templates, which lack them, render the same)',
        e.photoMask === 'none' && e.photoEffect === 'none' && e.lineStyle === 'solid' && e.showLabel === true && e.collagePreset === '3_hero_left');
    ok('safeColor accepts hex / rgb / rgba and nothing else',
        K.safeColor('#fff', 'X') === '#fff' && K.safeColor('#aabbcc', 'X') === '#aabbcc' && K.safeColor('rgba(1, 2, 3, 0.5)', 'X') === 'rgba(1, 2, 3, 0.5)' && K.safeColor('rgb(1,2,3)', 'X') === 'rgb(1,2,3)'
        && ['red', 'url(x)', '#12', 'expression(1)', 'rgb(1,2,3);color:red', '#ggg', '', null].every(v => K.safeColor(v, 'X') === 'X'));
    ok('new blocks never rely on clip-path / filter / backdrop-filter in their own CSS (html2canvas ignores them)', (() => {
        const all = [
            K.contentHtml(el({ field: 'ribbon' }), prop, {}), K.contentHtml(el({ field: 'qr' }), prop, {}), K.contentHtml(el({ field: 'feature_pills' }), prop, {}),
            K.contentHtml(el({ field: 'shape', shapeType: 'blob_1' }), prop, {}), K.contentHtml(el({ field: 'collage' }), prop, {}),
            K.frameStyle(el({ field: 'image_1', photoMask: 'blob' })), K.frameStyle(el({ field: 'image_1', photoMask: 'arch' })),
        ].join('');
        return !/clip-path|backdrop-filter|mask-image|filter:/.test(all);
    })());
}

console.log('\n' + (fail ? 'FAILED ' + fail + ' of ' : 'ALL ') + (pass + fail) + ' checks' + (fail ? '' : ' passed'));
process.exit(fail ? 1 : 0);
