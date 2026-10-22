// Run: node --test tests/js/wizard-step6-proxy.test.mjs
// AT-498 — Step 6 (Signing Order) must list only REAL signers: a party a proxy signs for collapses to one line under the proxy,
// numbering and Move up/down run across real signers only. The methods live inside the wizard's Alpine component, so this slices
// them out of the Blade source (between two stable markers) and runs them against fixture recipients.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const src = readFileSync(new URL('../../resources/views/docuperfect/esign/wizard.blade.php', import.meta.url), 'utf8');
const from = src.indexOf('isCoveredRecipient(r) {');
const to = src.indexOf('nextButtonLabel() {');
assert.ok(from > 0 && to > from, 'step 6 proxy methods found in wizard.blade.php');
const methods = src.slice(from, to).replace(/^[\s\S]*?(?=isCoveredRecipient)/, '');

function wizard(recipients) {
    // eslint-disable-next-line no-new-func
    const o = new Function(`return { recipients: [], signingActions: [], ${methods} };`)();
    o.recipients = recipients;
    o.signingActions = recipients.map(() => 'send_after');
    return o;
}

const agent = { role: 'agent', name: 'Agent' };
const paula = { role: 'seller', name: 'Paula Partner', _recipient_local_key: 'paula' };
const sam = { role: 'seller', name: 'Sam Separate', _recipient_local_key: 'sam' };
const peter = { role: 'seller', name: 'Peter Proxy', _recipient_local_key: 'peter', _is_proxy: true, _proxy_excludes: ['sam'] };

test('partial proxy: only the ticked party is covered; the un-ticked one and the proxy stay signers', () => {
    const w = wizard([agent, paula, sam, peter]);
    assert.deepEqual(w.recipients.map(r => w.isCoveredRecipient(r)), [false, true, false, false]);
    assert.deepEqual(w.coveredBy(peter).map(r => r.name), ['Paula Partner']);
});

test('numbering counts real signers only', () => {
    const w = wizard([agent, paula, sam, peter]);
    assert.equal(w.signerNumber(2), 2, 'Sam is signer 2 (agent is 1; Paula is not a signer)');
    assert.equal(w.signerNumber(3), 3, 'Peter is signer 3');
});

test('Move up / down never lands on a covered party or above the agent', () => {
    const w = wizard([agent, paula, sam, peter]);
    assert.equal(w.adjacentSignerIndex(2, 'up'), null, 'Sam cannot move above the agent (Paula is skipped)');
    assert.equal(w.adjacentSignerIndex(2, 'down'), 3);
    w.moveRecipient(3, 'up');
    assert.deepEqual(w.recipients.map(r => r.name), ['Agent', 'Paula Partner', 'Peter Proxy', 'Sam Separate']);
});

test('proxy with nothing un-ticked covers everyone else in the role (the old behaviour)', () => {
    const full = { ...peter, _proxy_excludes: [] };
    const w = wizard([agent, paula, sam, full]);
    assert.deepEqual(w.recipients.map(r => w.isCoveredRecipient(r)), [false, true, true, false]);
});

test('no proxy: nobody is covered and numbering is plain', () => {
    const w = wizard([agent, paula, sam]);
    assert.deepEqual(w.recipients.map(r => w.isCoveredRecipient(r)), [false, false, false]);
    assert.deepEqual(w.recipients.map((r, i) => w.signerNumber(i)), [1, 2, 3]);
});

test('a proxy only affects its own role; a company row is never collapsed', () => {
    const buyer = { role: 'buyer', name: 'Bea Buyer', _recipient_local_key: 'bea' };
    const company = { role: 'seller', name: 'Co (Pty) Ltd', _recipient_local_key: 'co', _is_entity: true };
    const w = wizard([agent, buyer, company, peter]);
    assert.equal(w.isCoveredRecipient(buyer), false);
    assert.equal(w.isCoveredRecipient(company), false);
});
