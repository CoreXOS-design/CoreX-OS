/**
 * CoreX - the property type named in an advert title (3.16.2). cc1 found that "2 Bedroom Townhouse" was stored as a House (640 adverts agency-wide): the reader used
 * title.includes() over a list that starts with 'house'. No jsdom needed:  node tests/property-type.test.cjs
 */
'use strict';
const fs = require('fs');
const path = require('path');
const assert = require('assert');

const EXT = path.join(__dirname, '..');
const MIC = (() => { const sandbox = {}; new Function('self', fs.readFileSync(path.join(EXT, 'mic-lib.js'), 'utf8'))(sandbox); return sandbox.CorexMic; })();
let passed = 0;
function t(name, fn) { try { fn(); passed++; console.log('  ok  ' + name); } catch (e) { console.error('FAIL  ' + name + '\n      ' + (e && e.stack || e)); process.exitCode = 1; } }
const pt = MIC.propertyTypeFromTitle;

t('the five cases from cc1: Townhouse, Penthouse, House, Apartment / Flat, Duplex', () => {
  assert.strictEqual(pt('2 Bedroom Townhouse'), 'Townhouse', 'a townhouse is not a house');
  assert.strictEqual(pt('3 Bedroom Penthouse'), 'Penthouse', 'a penthouse is not a house');
  assert.strictEqual(pt('4 Bedroom House'), 'House');
  assert.strictEqual(pt('2 Bedroom Apartment / Flat'), 'Apartment', 'the first type named wins');
  assert.strictEqual(pt('3 Bedroom Duplex'), 'Duplex');
});

t('whole words, longer names first, the first type named wins', () => {
  assert.strictEqual(pt('Vacant Land / Plot'), 'Vacant Land');
  assert.strictEqual(pt('Land'), 'Land');
  assert.strictEqual(pt('1 Bedroom Garden Cottage'), 'Garden Cottage');
  assert.strictEqual(pt('1 Bedroom Granny Flat'), 'Granny Flat');
  assert.strictEqual(pt('3 Bedroom House with a Granny Flat'), 'House', 'a house that has a granny flat is a house');
  assert.strictEqual(pt('1 Bedroom Flat'), 'Flat');
  assert.strictEqual(pt('Simplex in Margate'), 'Simplex');
  assert.strictEqual(pt('Smallholding'), 'Smallholding');
  assert.strictEqual(pt('TOWNHOUSE'), 'Townhouse', 'case does not matter');
  assert.strictEqual(pt('2 Bedroom Town-house'), 'Townhouse', 'written with a hyphen or a space it is still a townhouse');
  assert.strictEqual(pt('2 Bedroom Hoose'), null, 'a word we do not know is not guessed at');
});

t('nothing in, nothing out - and no word is found inside another word', () => {
  assert.strictEqual(pt(''), null);
  assert.strictEqual(pt(null), null);
  assert.strictEqual(pt(undefined), null);
  assert.strictEqual(pt('Housebreaking alert'), null);
  assert.strictEqual(pt('Farmhouse style home'), null, 'farmhouse is not a farm and not a house');
  assert.strictEqual(pt('Landscaped garden home'), null, 'landscaped is not land');
  assert.strictEqual(pt('Flatlet'), null);
});

t('the P24 reader asks this function and does not carry its own list any more', () => {
  const src = fs.readFileSync(path.join(EXT, 'content-p24-detail.js'), 'utf8');
  assert.ok(!/PROPERTY_TYPES/.test(src), 'no private list of types');
  assert.ok(!/tl\.includes\(pt\)/.test(src), 'no includes() over a list');
  assert.strictEqual(src.split('CorexMic.propertyTypeFromTitle(').length - 1, 2, 'both places (the search tile and the advert page) use it');
  // mic-lib.js is loaded before the reader in the manifest
  const m = JSON.parse(fs.readFileSync(path.join(EXT, 'manifest.json'), 'utf8'));
  const cs = m.content_scripts.find((c) => c.js.includes('content-p24-detail.js'));
  assert.ok(cs.js.indexOf('mic-lib.js') > -1 && cs.js.indexOf('mic-lib.js') < cs.js.indexOf('content-p24-detail.js'));
});

t('the PP reader has no includes() list: it reads the word after "Bedroom" whole', () => {
  const pp = fs.readFileSync(path.join(EXT, 'content-pp.js'), 'utf8');
  assert.ok(!/PROPERTY_TYPES|\.includes\(pt\)/.test(pp));
  assert.ok(/Bedroom\\s\+\(House\|Apartment\|Townhouse\|Flat\|Duplex\|Simplex\)/.test(pp));
});

console.log('\n' + passed + ' passed' + (process.exitCode ? ' (with failures)' : ''));
