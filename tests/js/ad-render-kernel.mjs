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

section('Templates saved BEFORE any of this still render unchanged');

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
