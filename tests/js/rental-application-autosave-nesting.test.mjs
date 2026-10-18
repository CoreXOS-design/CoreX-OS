// Run: node --test tests/js/rental-application-autosave-nesting.test.mjs
// The public rental-application form autosaves as JSON. PHP only expands "a[b]" names for a form-encoded body, so the
// custom "Additional Questions" answers (custom_field_values[key]) were sent as flat literal keys and never saved.
// corexNestFormEntries (inline in the Blade, between BEGIN/END markers) rebuilds the nesting. This test loads that exact
// code from the Blade file.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const blade = readFileSync(join(here, '../../resources/views/rental-applications/public/show.blade.php'), 'utf8');
const m = blade.match(/\/\/ BEGIN corexNestFormEntries[^\n]*\n([\s\S]*?)\/\/ END corexNestFormEntries/);
assert.ok(m, 'corexNestFormEntries markers not found in the Blade');
const corexNestFormEntries = new Function(`${m[1]}; return corexNestFormEntries;`)();

test('plain names stay flat', () => {
    assert.deepEqual(corexNestFormEntries([['full_name', 'Thandi'], ['email', 'a@b.test']]), { full_name: 'Thandi', email: 'a@b.test' });
});

test('custom_field_values[key] becomes custom_field_values: { key }', () => {
    const out = corexNestFormEntries([['custom_field_values[pets]', 'One cat'], ['custom_field_values[reference_name]', 'Mr Botha'], ['full_name', 'Thandi']]);
    assert.deepEqual(out, { custom_field_values: { pets: 'One cat', reference_name: 'Mr Botha' }, full_name: 'Thandi' });
});

test('empty brackets push onto an array, deeper nesting works', () => {
    const out = corexNestFormEntries([['tags[]', 'a'], ['tags[]', 'b'], ['x[y][z]', '1']]);
    assert.deepEqual(out, { tags: ['a', 'b'], x: { y: { z: '1' } } });
});

test('a file entry is skipped (documents upload on selection)', () => {
    const file = new File(['x'], 'id.pdf');
    assert.deepEqual(corexNestFormEntries([['a', '1'], ['upload', file]]), { a: '1' });
});

test('a later value for the same custom key wins, like a form post', () => {
    assert.deepEqual(corexNestFormEntries([['custom_field_values[k]', 'old'], ['custom_field_values[k]', 'new']]), { custom_field_values: { k: 'new' } });
});
