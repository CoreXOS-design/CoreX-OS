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

console.log('\n' + (fail ? 'FAILED ' + fail + ' of ' : 'ALL ') + (pass + fail) + ' checks' + (fail ? '' : ' passed'));
process.exit(fail ? 1 : 0);
