// AT-252 — Ad render kernel checks (the repo has no JS test runner, so this is a
// standalone node sim in the tests/js convention).
//
// It loads the ACTUAL shipped kernel — public/js/corex-ad-render.js — and asserts the
// contract that all three ad surfaces (Ad Builder, single-property generator, bulk Ad
// Manager) now depend on. Before the kernel each surface had its own copy of this logic
// and they drifted; the first block below pins the four bugs that drift had already put
// in front of agents on a real bulk ad.
//
// Run:  node tests/js/ad-render-kernel.mjs      (exit 0 = pass, 1 = fail)
//
// The structural half of this guarantee — "no ad view may grow its own renderer again" —
// is enforced in PHPUnit by tests/Feature/Properties/AdRenderKernelTest.php.

import fs from 'fs';
import path from 'path';
import vm from 'vm';
import { fileURLToPath } from 'url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const code = fs.readFileSync(path.join(root, 'public/js/corex-ad-render.js'), 'utf8');

const sandbox = { window: {} };
vm.createContext(sandbox);
vm.runInContext(code, sandbox);
const K = sandbox.window.CoreXAd;

let pass = 0, fail = 0;
const ok = (name, cond, got) => {
    if (cond) { pass++; console.log('  PASS  ' + name); }
    else { fail++; console.log('  FAIL  ' + name + (got ? '\n          got: ' + got : '')); }
};
const section = (t) => console.log('\n── ' + t + ' ──');

// A single-agent listing (no co-agent) carrying three amenities.
const prop = {
    title: 'Seaside Villa', price: 'R 4,250,000', suburb: 'Umkomaas',
    image_1: '/storage/p/1.jpg', logo: '/storage/agency.png',
    features_list: ['Sea View', 'Pool', 'Solar'],
    features: '4 Bed · 3 Bath',
    agent_name: 'JANE DOE',
    agent_2_name: '', agent_2_avatar: null,
};

const el = (o) => Object.assign(K.makeElement(o.field, 0, 0, 1), o);

section('The four drift bugs the bulk Ad Manager was shipping');

const star = el({ field: 'shape', shapeType: 'star', bg: '#ff0000' });
ok('star shape emits its clip-path (was rendering as a rounded blob)',
    K.contentHtml(star, prop, {}).includes('clip-path:polygon(50% 0,61% 35%'));

ok('custom_image renders its uploaded <img> (was an empty box)',
    /<img src="\/storage\/ad-media\/1\/x\.jpg"/.test(
        K.contentHtml(el({ field: 'custom_image', src: '/storage/ad-media/1/x.jpg' }), prop, {})));

ok('custom_video renders its uploaded <video> (was an empty box)',
    /<video src="\/storage\/ad-media\/1\/v\.mp4"/.test(
        K.contentHtml(el({ field: 'custom_video', src: '/storage/ad-media/1/v.mp4' }), prop, {})));

const featHtml = K.contentHtml(el({ field: 'features', selectedFeatures: ['Pool'] }), prop, {});
ok('features honours selectedFeatures (chooser was a no-op)',
    featHtml.includes('Pool') && !featHtml.includes('Sea View'), featHtml);
ok('a null selection still means "show them all"',
    K.contentHtml(el({ field: 'features', selectedFeatures: null }), prop, {}).includes('Sea View'));

// The worst of the four: a single-agent listing printed the words "CO-AGENT NAME" on the ad.
const a2 = el({ field: 'agent_2_name', preview: 'CO-AGENT NAME' });
ok('agent_2 renders EMPTY on a single-agent listing',
    !K.contentHtml(a2, prop, {}).includes('CO-AGENT NAME'), K.contentHtml(a2, prop, {}));
ok('...but the BUILDER still previews the co-agent placeholder',
    K.contentHtml(a2, prop, { placeholders: true }).includes('CO-AGENT NAME'));
ok('agent_2 avatar leaves an empty slot, never a placeholder box',
    K.contentHtml(el({ field: 'agent_2_avatar' }), prop, {}) === '');

section('New design properties');

const txt = el({ field: 'title', shadowOn: true, shadowY: 6, shadowBlur: 10, shadowColor: '#000000', shadowOpacity: 0.5 });
ok('a text element shadows with text-shadow', K.textStyle(txt).includes('text-shadow:0px 6px 10px rgba(0,0,0,0.5)'));
ok('...and NOT with a box-shadow on its frame', !K.frameStyle(txt).includes('box-shadow'));
ok('an image element shadows with box-shadow on its frame',
    K.frameStyle(el({ field: 'image_1', shadowOn: true })).includes('box-shadow:0px 4px 12px'));
ok('a rounded shape shadows the shape node, so the shadow follows the radius',
    K.shapeCss(el({ field: 'shape', shapeType: 'rounded', shadowOn: true })).includes('box-shadow'));
ok('a clip-path shape emits NO shadow — clip-path would cut it away',
    !K.shapeCss(el({ field: 'shape', shapeType: 'star', shadowOn: true })).includes('box-shadow'));
ok('canShadow() is what gates the control off for clip shapes',
    K.canShadow(star) === false && K.canShadow(el({ field: 'shape', shapeType: 'circle' })) === true);

ok('elOpacity lands on the frame', K.frameStyle(el({ field: 'title', elOpacity: 0.4 })).includes('opacity:0.4'));
ok('a fully opaque element emits no opacity at all', !K.frameStyle(el({ field: 'title', elOpacity: 1 })).includes('opacity:'));

ok('fontFamily resolves to a real stack',
    K.textStyle(el({ field: 'title', fontFamily: 'Bebas Neue' })).includes("font-family:'Bebas Neue',Impact,sans-serif"));
ok('an unknown font falls back to Figtree', K.fontStack('Comic Sans') === "'Figtree',Arial,sans-serif");

ok('verticalAlign top',     K.textStyle(el({ field: 'title', verticalAlign: 'top' })).includes('align-items:flex-start'));
ok('verticalAlign bottom',  K.textStyle(el({ field: 'title', verticalAlign: 'bottom' })).includes('align-items:flex-end'));
ok('verticalAlign default', K.textStyle(el({ field: 'title' })).includes('align-items:center'));

ok('a hidden element is display:none (so it is absent from the ad AND the PNG)',
    K.frameStyle(el({ field: 'title', hidden: true })).includes('display:none'));

section('Beds/Baths/Garages/Parking — Number + Label display + icon (ad-manager.md §14)');

const bedsProp = { ...prop, beds: '3', baths: '1.5', garages: '1', parking: '0' };

ok('default (no numberFormat) stays a bare number — legacy behaviour',
    K.textValue(el({ field: 'beds' }), bedsProp, {}) === '3');
ok('"label" format pluralises for a count > 1', K.textValue(el({ field: 'beds', numberFormat: 'label' }), bedsProp, {}) === '3 Bedrooms');
ok('"label" format singularises for a count of exactly 1',
    K.textValue(el({ field: 'garages', numberFormat: 'label' }), bedsProp, {}) === '1 Garage');
ok('"label" format keeps a real half (baths) and pluralises it',
    K.textValue(el({ field: 'baths', numberFormat: 'label' }), bedsProp, {}) === '1.5 Bathrooms');
ok('"Parking" never pluralises/singularises, even at count 1',
    K.featureWord('parking', 1) === 'Parking' && K.featureWord('parking', 3) === 'Parking');
ok('a whole-number decimal string ("3.0") drops the trailing zero', K.formatFeatureNumber(3.0) === '3');
ok('label format on the GENERATOR with an empty value renders nothing (never "undefined Bedrooms")',
    K.textValue(el({ field: 'beds', numberFormat: 'label' }), { ...bedsProp, beds: '' }, {}) === '');
ok('label format on the BUILDER with an empty value falls back to the preview, still pluralised',
    K.textValue(el({ field: 'beds', numberFormat: 'label' }), { ...bedsProp, beds: '' }, { placeholders: true }) === '4 Bedrooms');
ok('non-numeric garbage never throws — falls back to the raw string',
    K.textValue(el({ field: 'beds', numberFormat: 'label' }), { ...bedsProp, beds: 'N/A' }, {}) === 'N/A');

const iconEl = el({ field: 'beds', icon: 'bed', numberFormat: 'label' });
const renderedIconHtml = K.contentHtml(iconEl, bedsProp, {});
ok('an element with an icon renders its SVG inline before the value',
    renderedIconHtml.includes('<svg') && renderedIconHtml.indexOf('<svg') < renderedIconHtml.indexOf('3 Bedrooms'), renderedIconHtml);
ok('an element with icon=null renders no <svg> at all (default/legacy)',
    !K.contentHtml(el({ field: 'beds' }), bedsProp, {}).includes('<svg'));
ok('an unknown icon key is ignored rather than emitting a broken tag',
    !K.contentHtml(el({ field: 'beds', icon: 'not-a-real-icon' }), bedsProp, {}).includes('<svg'));
ok('ICON_LIST only offers keys that actually exist in ICONS',
    K.ICON_LIST.every((ic) => !!K.ICONS[ic.key]));

ok('parking is a real builder field with its own default size',
    K.FIELD_DEFAULTS.parking && K.FIELD_DEFAULTS.parking.w === 80);

section('"Garages / Parking" combined field — for bulk ad runs across mixed listings');

const withGarage  = { garages: '2', parking: '5' };   // has both — garages wins
const parkOnly    = { garages: '0', parking: '3' };   // no garage (explicit 0) — falls to parking
const parkOnly2   = { garages: '', parking: '4' };    // no garage (absent) — falls to parking
const neitherProp = { garages: '0', parking: '' };    // has neither — hidden like any other zero spec

ok('garages present and > 0 → garages wins over parking',
    K.resolveGaragesOrParking(withGarage).field === 'garages' && K.resolveGaragesOrParking(withGarage).num === 2);
ok('garages explicitly "0" → falls back to parking', K.resolveGaragesOrParking(parkOnly).field === 'parking');
ok('garages absent/empty string → falls back to parking', K.resolveGaragesOrParking(parkOnly2).field === 'parking');
ok('neither present → resolves to null (hidden, not "0 Garages")', K.resolveGaragesOrParking(neitherProp) === null);

const gp = (o) => el({ field: 'garages_or_parking', ...o });
ok('bare number mode shows the resolved garages count',
    K.textValue(gp({}), withGarage, {}) === '2');
ok('bare number mode falls back to the resolved parking count on a garage-less listing',
    K.textValue(gp({}), parkOnly, {}) === '3');
ok('label mode reads "Garage"/"Garages" when resolved to garages',
    K.textValue(gp({ numberFormat: 'label' }), { garages: '1', parking: '5' }, {}) === '1 Garage');
ok('label mode reads "Parking" (never pluralised) when it falls back',
    K.textValue(gp({ numberFormat: 'label' }), parkOnly, {}) === '3 Parking');
ok('a listing with neither renders EMPTY on the generator (never "0 Garages")',
    K.textValue(gp({ numberFormat: 'label' }), neitherProp, {}) === '');
ok('the BUILDER preview (no real property yet) defaults to garages wording',
    K.textValue(gp({ numberFormat: 'label' }), {}, { placeholders: true }) === '2 Garages');
ok('the field carries an icon like any other numeric feature field',
    K.contentHtml(gp({ icon: 'garage' }), withGarage, {}).includes('<svg'));
ok('is a real draggable builder field, in the numeric-feature set',
    !!K.FIELD_DEFAULTS.garages_or_parking && K.NUMERIC_FEATURE_FIELDS.includes('garages_or_parking'));

section('"Size / Land Size" combined field — vacant land has no floor size, only an erf size');

const withFloor = { floor_size_m2: '450', land_size_m2: '1200' };  // has both — floor wins
const landOnly1  = { floor_size_m2: '0', land_size_m2: '850' };    // vacant land, explicit 0
const landOnly2  = { floor_size_m2: '', land_size_m2: '2500' };    // vacant land, absent
const neitherSize = { floor_size_m2: '0', land_size_m2: '' };      // has neither

ok('floor size present and > 0 → floor wins over land',
    K.resolveSizeOrLand(withFloor).kind === 'floor' && K.resolveSizeOrLand(withFloor).num === 450);
ok('floor size explicitly "0" (vacant land) → falls back to land/erf size', K.resolveSizeOrLand(landOnly1).kind === 'land');
ok('floor size absent/empty string → falls back to land/erf size', K.resolveSizeOrLand(landOnly2).kind === 'land');
ok('neither present → resolves to null (hidden, not "0 M²")', K.resolveSizeOrLand(neitherSize) === null);

const sl = (o) => el({ field: 'size_or_land', ...o });
ok('shows the floor size, formatted with a thousands separator, no "Erf" suffix',
    K.textValue(sl({}), withFloor, {}) === '450 M²');
ok('vacant land shows the erf/land size instead, WITH the "Erf" suffix — never confused for floor size',
    K.textValue(sl({}), landOnly1, {}) === '850 M² Erf');
ok('a large erf size gets the same thousands separator as the plain Size m² field (number_format parity)',
    K.textValue(sl({}), landOnly2, {}) === '2,500 M² Erf');
ok('a listing with neither renders EMPTY on the generator (never a fabricated "450 M²")',
    K.textValue(sl({}), neitherSize, {}) === '');
ok('the BUILDER preview (no real property yet) still shows a placeholder',
    K.textValue(sl({}), {}, { placeholders: true }) === '450 M²');
ok('formatSizeNumber() matches PHP\'s number_format() thousands-separator convention',
    K.formatSizeNumber(1250) === '1,250' && K.formatSizeNumber(450) === '450');

section('Placeholder-leak fix — a missing field must NEVER fabricate its design-time preview on a real ad');

// This was a real, pre-existing bug found while investigating the size-field
// report: the GENERIC textValue() fallback returned el.preview on ANY field
// with no real data, regardless of opts.placeholders — so a property missing
// (say) a floor size, phone number, reference, or address rendered that
// field's PREVIEW TEXT on the actual generated ad as if it were real data.
const emptyProp = {};
ok('a missing generic text field (e.g. reference) renders EMPTY on the generator, not its preview',
    K.textValue(el({ field: 'reference' }), emptyProp, {}) === '');
ok('...but the BUILDER still shows the preview, so designing against an incomplete property still looks right',
    K.textValue(el({ field: 'reference' }), emptyProp, { placeholders: true }) === 'REF 12345');
ok('a missing address never fabricates the placeholder street address onto a real ad',
    K.textValue(el({ field: 'address' }), emptyProp, {}) === '');
ok('a missing agent phone never fabricates a placeholder phone number onto a real ad',
    K.textValue(el({ field: 'agent_phone' }), emptyProp, {}) === '');
ok('a field WITH real data is completely unaffected by the fix',
    K.textValue(el({ field: 'title' }), { title: 'SEASIDE VILLA' }, {}) === 'SEASIDE VILLA');

section('Agent Image — renamed from "Avatar", + shape picker (mirrors the Shape element)');

ok('the catalogue label reads "Agent 1 / 2 · Image", not "Avatar"',
    K.FIELDS.find((f) => f.type === 'agent_avatar').label === 'Agent 1 · Image' &&
    K.FIELDS.find((f) => f.type === 'agent_2_avatar').label === 'Agent 2 · Image');
ok('a brand-new Agent Image element defaults to a circle (same look as before this change)',
    K.frameStyle(K.makeElement('agent_avatar', 0, 0, 1)).includes('border-radius:50%'));
ok('picking "rounded" uses el.borderRadius as a real corner radius, not a giant forced circle',
    K.frameStyle(el({ field: 'agent_avatar', shapeType: 'rounded', borderRadius: 20 })).includes('border-radius:20px'));
ok('picking a clip-path shape (e.g. hexagon) masks the PHOTO, same geometry as the decorative Shape element',
    K.frameStyle(el({ field: 'agent_avatar', shapeType: 'hexagon' })).includes(K.SHAPE_CLIPS.hexagon));
ok('a clip-path shape zeroes the border-radius (clip-path and border-radius fighting would look wrong)',
    K.frameStyle(el({ field: 'agent_avatar', shapeType: 'hexagon' })).includes('border-radius:0'));
ok('"pill" and "circle" are distinct — pill is not hard-coded to 50%',
    K.avatarShapeCss('pill') === 'border-radius:9999px;' && K.avatarShapeCss('circle') === 'border-radius:50%;');
ok('the shape picker applies to Agent 2 as well as Agent 1',
    K.frameStyle(el({ field: 'agent_2_avatar', shapeType: 'star' })).includes(K.SHAPE_CLIPS.star));
ok('isAgentAvatarField() does not falsely match an unrelated image field',
    !K.isAgentAvatarField('image_1') && !K.isAgentAvatarField('agency_logo'));

section('Remove Background — flood-fill cutout (pure pixel logic, no real Canvas needed)');

// A synthetic W×H RGBA buffer: a solid backdrop colour everywhere, with an
// inset "person" square of a different colour, and a same-backdrop-coloured
// "collar" square placed in the MIDDLE (not touching the border) — the one
// case a naive global colour-threshold would get wrong.
function makeFrame(w, h, bg, subjectRect, collarRect) {
    const data = new Uint8ClampedArray(w * h * 4);
    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            const i = (y * w + x) * 4;
            let [r, g, b] = bg;
            if (subjectRect && x >= subjectRect.x && x < subjectRect.x + subjectRect.w
                            && y >= subjectRect.y && y < subjectRect.y + subjectRect.h) {
                [r, g, b] = subjectRect.color;
            }
            if (collarRect && x >= collarRect.x && x < collarRect.x + collarRect.w
                            && y >= collarRect.y && y < collarRect.y + collarRect.h) {
                [r, g, b] = bg; // same colour as the backdrop, but NOT touching the border
            }
            data[i] = r; data[i + 1] = g; data[i + 2] = b; data[i + 3] = 255;
        }
    }
    return { data };
}
const alphaAt = (frame, w, x, y) => frame.data[(y * w + x) * 4 + 3];

const W = 40, H = 40;
const white = [255, 255, 255];
const subject = { x: 10, y: 10, w: 20, h: 20, color: [40, 90, 160] }; // a blue "person"
const collar  = { x: 17, y: 15, w: 6, h: 4 };                        // white patch INSIDE the subject

const frame = makeFrame(W, H, white, subject, collar);
K.cornerColor(frame.data, W, H); // must not throw on the exported entry point
K.floodFillTransparent(frame, W, H);

ok('a corner (background) pixel becomes transparent', alphaAt(frame, W, 0, 0) === 0);
ok('a border-edge background pixel becomes transparent', alphaAt(frame, W, Math.floor(W / 2), 0) === 0);
ok('the subject (a different colour, filling out from the middle) stays fully opaque',
    alphaAt(frame, W, 20, 20) === 255);
ok('a white patch INSIDE the subject — not connected to the border — survives (the whole point of flood-fill vs a naive colour threshold)',
    alphaAt(frame, W, 19, 16) === 255);

const noisyBg = makeFrame(W, H, [250, 248, 252], subject, null); // near-white, not pure white
K.floodFillTransparent(noisyBg, W, H);
ok('a near-white (not pure #fff) backdrop is still recognised within tolerance',
    alphaAt(noisyBg, W, 0, 0) === 0);

const cc = K.cornerColor(frame.data, W, H);
ok('cornerColor() reads the actual corner pixels', cc[0] > 200 && cc[1] > 200 && cc[2] > 200);

// REGRESSION #1 (first production report): Retha's photo had a white shirt
// swept away along with the white studio backdrop. Root cause — the shirt
// touches the BOTTOM edge of the frame, so seeding the flood fill from every
// border (including the bottom) let it flow straight from the backdrop into
// the shirt. A first fix ALSO added a hard "never erase the bottom ~18%"
// floor — which caused REGRESSION #2: real backdrop genuinely visible low in
// the frame (not every crop has the garment reaching the very last pixel) was
// left un-removed, reported as "it left about 20% at the bottom". The floor
// is gone; propagation is unrestricted by y-position once seeded, so genuine
// connected backdrop is now correctly cleared all the way down. What actually
// stops a real garment from being eaten is a colour difference — even a
// modest one — between it and the backdrop, caught by the tolerance.
function paintRect(frame, w, rect, color) {
    for (let y = rect.y; y < rect.y + rect.h; y++) {
        for (let x = rect.x; x < rect.x + rect.w; x++) {
            const i = (y * w + x) * 4;
            frame.data[i] = color[0]; frame.data[i + 1] = color[1]; frame.data[i + 2] = color[2];
        }
    }
}

const H2 = 40;
const head = { x: 12, y: 4, w: 16, h: 16, color: [40, 90, 160] };

// A garment that differs from the backdrop by a real (if modest) amount —
// the realistic case: almost no photo has a garment that is bit-for-bit
// identical to the backdrop's paint. Touches the bottom edge.
const garmentColor = [210, 215, 225]; // a light grey-blue, NOT pure white
const distinct = makeFrame(W, H2, white, head, null);
paintRect(distinct, W, { x: 5, y: 34, w: 30, h: 6 }, garmentColor);
K.floodFillTransparent(distinct, W, H2);

ok('a garment that differs from the backdrop by a real amount is preserved, even touching the bottom edge (the reported bug, realistic case)',
    alphaAt(distinct, W, 10, 37) === 255 && alphaAt(distinct, W, 20, 38) === 255);
ok('genuine backdrop is still removed all the way down when nothing blocks it — no more blind height floor (regression #2, "left ~20% at the bottom")',
    alphaAt(distinct, W, 0, 38) === 0 && alphaAt(distinct, W, 0, 39) === 0);
ok('the subject/head is untouched', alphaAt(distinct, W, 20, 10) === 255);

// Documented, known, ACCEPTED limit — not a silent regression: a garment with
// literally zero colour difference from the backdrop has no signal any
// colour-based algorithm can use, and is correctly expected to be swept in
// too. This is the inherent boundary of the technique (§15.1), not something
// a position-based rule can safely patch without breaking the case above.
const identical = makeFrame(W, H2, white, head, null);
paintRect(identical, W, { x: 5, y: 34, w: 30, h: 6 }, white);
K.floodFillTransparent(identical, W, H2);
ok('a garment truly colour-identical to the backdrop is NOT distinguishable — accepted limit, asserted explicitly rather than left as an undocumented surprise',
    alphaAt(identical, W, 20, 38) === 0);

const photoProp = { agent_avatar: '/storage/agents/1.jpg', agent_2_avatar: '/storage/agents/2.jpg' };
ok('removeBackground=true on an Agent Image emits the onload hook',
    K.contentHtml(el({ field: 'agent_avatar', removeBackground: true }), photoProp, {})
        .includes('onload="window.CoreXAd.stripBackground(this)"'));
ok('removeBackground=false (the default) emits NO onload hook — a legacy avatar is untouched',
    !K.contentHtml(el({ field: 'agent_avatar' }), photoProp, {}).includes('stripBackground'));
ok('applies to Agent 2 as well as Agent 1',
    K.contentHtml(el({ field: 'agent_2_avatar', removeBackground: true }), photoProp, {}).includes('stripBackground'));
ok('an unrelated image field ignores removeBackground even if somehow set (scoped to Agent Image only)',
    !K.contentHtml(el({ field: 'image_1', removeBackground: true }), { image_1: '/storage/p/1.jpg' }, {}).includes('stripBackground'));

// REGRESSION #3 (property 3080, agent Elize Reichel): two hard-edged white
// discs at the ear/earring positions and a pale block near the collar were
// left completely untouched — genuine backdrop colour, but never reached by
// the border-seeded flood fill because they were fully enclosed pockets (a
// hoop earring, a lapel gap). _fillEnclosedHoles() sweeps these up in a
// second pass, gated on two things: (a) the pocket must never touch any
// frame border — anything that does is exactly the "shirt/garment" case
// regressions #1/#2 already protect, so it's left strictly alone; (b) the
// pocket must be at least HOLE_MIN_PX (30, at the real 500px working
// resolution) — an eye catch-light is ALSO an enclosed near-white pocket but
// measured 19px and under on a real photo, so the size floor is what keeps
// this from eating a specular highlight while still catching a 46-851px
// earring/clothing hole.
function makeHoleFrame(w, h, bg, subjectRect, holeRect) {
    const frame = makeFrame(w, h, bg, subjectRect, null);
    if (holeRect) paintRect(frame, w, holeRect, bg);
    return frame;
}

const holeSubject = { x: 5, y: 5, w: 30, h: 30, color: [40, 90, 160] };

// A 6x6 = 36px enclosed hole — above the 30px floor, should be filled.
const earringFrame = makeHoleFrame(W, H, white, holeSubject, { x: 15, y: 15, w: 6, h: 6 });
K.floodFillTransparent(earringFrame, W, H);
K.fillEnclosedHoles(earringFrame, W, H);
ok('a 36px enclosed backdrop-coloured hole (earring-sized) is filled', alphaAt(earringFrame, W, 17, 17) === 0);

// A 3x3 = 9px enclosed hole — well under the floor, simulating an eye
// catch-light; must survive.
const catchlightFrame = makeHoleFrame(W, H, white, holeSubject, { x: 18, y: 18, w: 3, h: 3 });
K.floodFillTransparent(catchlightFrame, W, H);
K.fillEnclosedHoles(catchlightFrame, W, H);
ok('a 9px enclosed backdrop-coloured pocket (catch-light-sized) is NOT filled — stays below the safety floor',
    alphaAt(catchlightFrame, W, 19, 19) === 255);

// A large backdrop-coloured patch that DOES touch the border — must never be
// filled by this pass, even though it is far bigger than the size floor; this
// is what keeps regressions #1/#2 (the eaten shirt) from coming back via a
// second code path.
const borderTouchFrame = makeFrame(W, H2, white, head, null);
paintRect(borderTouchFrame, W, { x: 5, y: 34, w: 30, h: 6 }, garmentColor);
K.floodFillTransparent(borderTouchFrame, W, H2);
K.fillEnclosedHoles(borderTouchFrame, W, H2);
ok('a border-touching patch is never touched by the enclosed-holes pass, garment stays intact',
    alphaAt(borderTouchFrame, W, 10, 37) === 255 && alphaAt(borderTouchFrame, W, 20, 38) === 255);

// _featherAlpha(): a hard 0/255 edge should soften to an intermediate value
// right at the boundary, without changing fully-interior or fully-exterior
// pixels.
const featherFrame = makeFrame(W, H, white, holeSubject, null);
K.floodFillTransparent(featherFrame, W, H);
const beforeEdge = alphaAt(featherFrame, W, 5, 20); // first column of the subject — right at its edge
K.featherAlpha(featherFrame, W, H);
const afterEdge = alphaAt(featherFrame, W, 5, 20);
const afterInterior = alphaAt(featherFrame, W, 20, 20); // deep inside the subject
const afterOutside = alphaAt(featherFrame, W, 0, 0); // deep in the backdrop
ok('featherAlpha() softens a hard edge pixel to an intermediate alpha (not a binary 0/255 jump)',
    beforeEdge === 255 && afterEdge > 0 && afterEdge < 255, 'before=' + beforeEdge + ' after=' + afterEdge);
ok('featherAlpha() leaves a fully-interior subject pixel opaque', afterInterior === 255);
ok('featherAlpha() leaves a fully-exterior backdrop pixel transparent', afterOutside === 0);

section('Property-type template variants — resolveTemplateLayout() (§18)');

const defaultBeds = el({ field: 'beds', x: 10, y: 10 });
const landSize    = el({ field: 'custom_text', text: 'Land size', x: 10, y: 10 });
const baseLayout = {
    canvasW: 1200, canvasH: 628, canvasBg: '#071325', canvasBgMode: 'solid',
    canvasBgFrom: '#071325', canvasBgTo: '#0b2a4a', canvasBgAngle: 160, canvasPreset: 'facebook',
    elements: [defaultBeds],
    variants: {
        'Vacant Land / Plot': {
            canvasW: 1200, canvasH: 628, canvasBg: '#123', canvasBgMode: 'solid',
            canvasBgFrom: '#123', canvasBgTo: '#456', canvasBgAngle: 90, canvasPreset: 'facebook',
            elements: [landSize],
        },
    },
};

ok('a property whose type matches a variant (exact name) resolves to THAT variant\'s elements',
    K.resolveTemplateLayout(baseLayout, 'Vacant Land / Plot').elements[0] === landSize);
ok('matching is case-insensitive and trims whitespace',
    K.resolveTemplateLayout(baseLayout, '  vacant land / plot  ').elements[0] === landSize);
ok('a variant\'s own canvas background comes along with it, not the Default\'s',
    K.resolveTemplateLayout(baseLayout, 'Vacant Land / Plot').canvasBg === '#123');
ok('a property whose type has no variant falls back to the Default design',
    K.resolveTemplateLayout(baseLayout, 'House').elements[0] === defaultBeds);
ok('a property with no type set at all falls back to the Default design — never a broken/empty render',
    K.resolveTemplateLayout(baseLayout, '').elements[0] === defaultBeds
    && K.resolveTemplateLayout(baseLayout, null).elements[0] === defaultBeds);
ok('a template with no variants object at all (every pre-§18 template) resolves to its own top-level design, unchanged',
    K.resolveTemplateLayout({ canvasW: 1200, elements: [defaultBeds] }, 'Vacant Land / Plot').elements[0] === defaultBeds);

section('Templates saved BEFORE any of this still render unchanged');

ok('a legacy Agent Image (no shapeType at all, old borderRadius:50 baked in) still renders circular',
    K.frameStyle({ id: 9, field: 'agent_avatar', x: 0, y: 0, w: 80, h: 80, zIndex: 1, objectFit: 'cover', borderRadius: 50 }).includes('border-radius:50px'));

const legacyShape = { id: 1, field: 'shape', x: 0, y: 0, w: 100, h: 100, zIndex: 1, bg: '#00b4d8', opacity: 1, borderRadius: 50 };
ok('a legacy shape (no shapeType) still reads borderRadius as a %',
    K.shapeCss(legacyShape).includes('border-radius:50%'), K.shapeCss(legacyShape));

const legacyText = { id: 2, field: 'title', x: 0, y: 0, w: 100, h: 40, zIndex: 1, fontSize: 22, color: '#fff' };
ok('a legacy text element defaults to Figtree', K.textStyle(legacyText).includes("font-family:'Figtree'"));
ok('a legacy element gains no shadow', !K.textStyle(legacyText).includes('text-shadow'));
ok('a legacy frame gains no opacity or shadow',
    !K.frameStyle(legacyText).includes('opacity:') && !K.frameStyle(legacyText).includes('box-shadow'));
ok('a legacy element still resolves its property value', K.textValue(legacyText, prop, {}) === 'Seaside Villa');

section('Safety');

ok('literal text is HTML-escaped',
    K.contentHtml(el({ field: 'custom_text', text: '<img src=x onerror=alert(1)>' }), prop, {})
        .includes('&lt;img src=x onerror=alert(1)&gt;'));

section('The generator\'s "change photo" overrides survive a re-render');

const withOv = K.contentHtml(el({ field: 'image_1', id: 77 }), prop, { overrides: { 77: '/storage/p/9.jpg' }, tagPhotos: true });
ok('an override wins over the slot default', withOv.includes('src="/storage/p/9.jpg"'), withOv);
ok('the original src is kept so "reset to original" can restore it', withOv.includes('data-orig-src="/storage/p/1.jpg"'));
ok('the agency logo is tagged so the gallery picker never swaps it',
    K.contentHtml(el({ field: 'agency_logo' }), prop, { tagPhotos: true }).includes('class="js-ad-logo"'));

section('objectFitRect() — the pre-capture crop math (html2canvas object-fit workaround, ad-manager.md §16)');

// A real reported bug: an Agent Image (180x200 box, object-fit:cover) rendered
// stretched in the downloaded PNG while the live preview was correct. The fix
// pre-bakes the SAME crop cover/contain would produce onto an offscreen
// canvas before html2canvas ever sees the image — this is that crop math,
// tested directly against known photo/box combinations.

// A portrait box (180x200), a landscape source photo (800x600, ratio 4:3) —
// "cover" must crop the sides, never squash the photo to fit.
let r = K.objectFitRect('cover', 180, 200, 800, 600);
ok('cover picks the LARGER scale (fills both dimensions, crops the rest)',
    Math.abs(r.dw - (800 * (200 / 600))) < 0.01 && Math.abs(r.dh - 200) < 0.01, JSON.stringify(r));
ok('cover centres the crop horizontally (dx is negative — image wider than the box)',
    r.dx < 0);
ok('cover fills the full box vertically (dy is 0 — no vertical crop needed here)',
    Math.abs(r.dy) < 0.01);

// Same photo, "contain" — must letterbox, never crop.
r = K.objectFitRect('contain', 180, 200, 800, 600);
ok('contain picks the SMALLER scale (letterboxes, never crops)',
    Math.abs(r.dw - 180) < 0.01, JSON.stringify(r));
ok('contain centres the letterbox vertically (dy is positive — image shorter than the box)',
    r.dy > 0);

// A square photo into a square box — no crop, no letterbox, exact fit either way.
r = K.objectFitRect('cover', 100, 100, 500, 500);
ok('a matching aspect ratio needs no crop at all (cover)', r.dw === 100 && r.dh === 100 && r.dx === 0 && r.dy === 0);

// Robustness — the whole point of this existing rather than crashing mid-capture.
ok('a zero/missing natural size returns null rather than dividing by zero',
    K.objectFitRect('cover', 100, 100, 0, 0) === null);
ok('a zero-size box also returns null rather than producing garbage geometry',
    K.objectFitRect('cover', 0, 0, 500, 500) === null);

console.log('\n' + (fail ? 'FAILED ' + fail + ' of ' : 'ALL ') + (pass + fail) + ' checks' + (fail ? '' : ' passed'));
process.exit(fail ? 1 : 0);
