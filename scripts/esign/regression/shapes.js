// E-sign regression harness — the 6 flow shapes.
//
// Each shape's `build()` drives Template -> Property -> Recipients on a
// FRESH flow and returns { flowId, expected, liveExpectations }. `expected`
// is what THIS shape asserts against — not a guess, the literal names/roles
// a correct document must show, derived from the fixture data the shape
// itself just picked. `liveExpectations` (2026-08-27, Gap 1 — Johan found
// three live-rendering bugs on the Recipients screen itself by hand: a
// joined-with-"and" Domicilium blob, a company showing only its first
// director, a picked proxy not updating the seller section) is an ordered
// list of { label, names, clauseContains?, compareRawChangedFrom? }
// checkpoints — what the LIVE, no-reload preview must already show
// immediately after each action `snap()` is called for. See run.js for how
// `snap` is wired and assertions.js #8 for how these are checked.

const driver = require('./lib/driver');

const PROPERTY_SEARCH = 'Regression Harness Way';
const PROPERTY_MATCH = 'Regression Harness Way';
const TEMPLATE_BUTTON = 'EXCLUSIVE AUTHORITY TO SELL';

function shapeList(fixtures) {
    return [
        {
            key: 'A',
            label: 'Two ordinary natural-person sellers, nothing ticked',
            async build(page, warnings, snap) {
                const liveExpectations = [];
                await driver.addRecipientBySearch(page, { searchTerm: 'RegSellerOne HarnessFixture', matchText: 'RegSellerOne', role: 'seller', warnings });
                await snap('A: after RegSellerOne added');
                liveExpectations.push({ label: 'A: after RegSellerOne added', names: ['RegSellerOne HarnessFixture'] });

                await driver.addRecipientBySearch(page, { searchTerm: 'RegSellerTwo HarnessFixture', matchText: 'RegSellerTwo', role: 'seller', warnings });
                await snap('A: after RegSellerTwo added');
                liveExpectations.push({ label: 'A: after RegSellerTwo added', names: ['RegSellerOne HarnessFixture', 'RegSellerTwo HarnessFixture'] });

                await driver.saveDraft(page);
                return {
                    liveExpectations,
                    expected: {
                        domiciliumNames: ['RegSellerOne HarnessFixture', 'RegSellerTwo HarnessFixture'],
                        deceasedNames: [],
                        signingOrderCount: 3, // agent + 2 sellers
                    },
                };
            },
        },
        {
            key: 'B',
            label: 'One seller deceased, executor a natural person from Contacts (the shipping flow)',
            async build(page, warnings, snap) {
                const liveExpectations = [];
                await driver.addRecipientBySearch(page, { searchTerm: 'RegDeceased HarnessFixture', matchText: 'RegDeceased', role: 'seller', warnings });
                await snap('B: after RegDeceased added');
                liveExpectations.push({ label: 'B: after RegDeceased added', names: ['RegDeceased HarnessFixture'] });

                await driver.addRecipientBySearch(page, { searchTerm: 'RegSellerOne HarnessFixture', matchText: 'RegSellerOne', role: 'seller', warnings });
                await snap('B: after RegSellerOne added');
                liveExpectations.push({ label: 'B: after RegSellerOne added', names: ['RegDeceased HarnessFixture', 'RegSellerOne HarnessFixture'] });

                await driver.tickDeceasedAndBindExecutor(page, {
                    namePart: 'RegDeceased',
                    templateName: 'Late Estate',
                    executorSource: 'contact',
                    executorSearchTerm: 'RegExecutor HarnessFixture',
                    executorMatchText: 'RegExecutor',
                });
                await snap('B: after deceased ticked + executor bound');
                liveExpectations.push({
                    label: 'B: after deceased ticked + executor bound',
                    names: ['RegSellerOne HarnessFixture', 'RegExecutor HarnessFixture'],
                    compareRawChangedFrom: 'B: after RegSellerOne added',
                });

                return {
                    liveExpectations,
                    expected: {
                        domiciliumNames: ['RegSellerOne HarnessFixture', 'RegExecutor HarnessFixture'],
                        deceasedNames: ['RegDeceased HarnessFixture'],
                        // 2026-08-27 — corrected per Johan's explicit, authoritative
                        // statement: "the deceased appears in the seller clause and
                        // in no signing element anywhere." She gets NO initial
                        // block, NO signature block, and NO signing-order card.
                        // Previously set to 4 based on an earlier read of a
                        // "still displays, never receives a signing request"
                        // note that turned out to describe a bug, not a design.
                        signingOrderCount: 3, // agent + living seller + executor
                    },
                };
            },
        },
        {
            key: 'C',
            label: 'One seller deceased, executor from the Supplier directory',
            async build(page, warnings, snap) {
                const liveExpectations = [];
                await driver.addRecipientBySearch(page, { searchTerm: 'RegDeceased HarnessFixture', matchText: 'RegDeceased', role: 'seller', warnings });
                await snap('C: after RegDeceased added');
                liveExpectations.push({ label: 'C: after RegDeceased added', names: ['RegDeceased HarnessFixture'] });

                await driver.addRecipientBySearch(page, { searchTerm: 'RegSellerTwo HarnessFixture', matchText: 'RegSellerTwo', role: 'seller', warnings });
                await snap('C: after RegSellerTwo added');
                liveExpectations.push({ label: 'C: after RegSellerTwo added', names: ['RegDeceased HarnessFixture', 'RegSellerTwo HarnessFixture'] });

                await driver.tickDeceasedAndBindExecutor(page, {
                    namePart: 'RegDeceased',
                    templateName: 'Late Estate',
                    executorSource: 'supplier',
                    executorSearchTerm: 'RegSupplierExecutor',
                    executorMatchText: 'RegSupplierExecutor',
                });
                await snap('C: after deceased ticked + supplier executor bound');
                liveExpectations.push({
                    label: 'C: after deceased ticked + supplier executor bound',
                    names: ['RegSellerTwo HarnessFixture', 'RegSupplierExecutor HarnessFixture'],
                    compareRawChangedFrom: 'C: after RegSellerTwo added',
                });

                return {
                    liveExpectations,
                    expected: {
                        domiciliumNames: ['RegSellerTwo HarnessFixture', 'RegSupplierExecutor HarnessFixture'],
                        deceasedNames: ['RegDeceased HarnessFixture'],
                        signingOrderCount: 3, // agent + living seller + executor (see shape B's note)
                    },
                };
            },
        },
        {
            key: 'D',
            label: 'Company with three directors, NO proxy (Johan\'s signed-off flow)',
            async build(page, warnings, snap) {
                const liveExpectations = [];
                await driver.addRecipientBySearch(page, { searchTerm: 'REG Proxy Test CC', matchText: 'REG Proxy Test CC', role: 'seller', warnings });
                await snap('D: after company added');
                liveExpectations.push({
                    label: 'D: after company added',
                    names: ['RegDirectorOne HarnessFixture', 'RegDirectorTwo HarnessFixture', 'RegDirectorThree HarnessFixture'],
                });

                // 2026-08-27 — Johan found by hand: "Reordering the directors
                // on the left does not update the seller block or the
                // Domicilium on the right." Real click on the "Move down"
                // arrow (moveEntityRep), same as an agent uses.
                await driver.moveEntityRepDown(page, 'REG Proxy Test CC');
                await snap('D: after reordering directors (1 moved below 2)');
                liveExpectations.push({
                    label: 'D: after reordering directors (1 moved below 2)',
                    names: ['RegDirectorTwo HarnessFixture', 'RegDirectorOne HarnessFixture', 'RegDirectorThree HarnessFixture'],
                    compareRawChangedFrom: 'D: after company added',
                });

                await driver.saveDraft(page);
                return {
                    liveExpectations,
                    expected: {
                        domiciliumNames: ['RegDirectorOne HarnessFixture', 'RegDirectorTwo HarnessFixture', 'RegDirectorThree HarnessFixture'],
                        deceasedNames: [],
                        signingOrderCount: 4, // agent + 3 directors, none proxied
                    },
                };
            },
        },
        {
            key: 'E',
            label: 'The same company WITH a proxy picked',
            async build(page, warnings, snap) {
                const liveExpectations = [];
                await driver.addRecipientBySearch(page, { searchTerm: 'REG Proxy Test CC', matchText: 'REG Proxy Test CC', role: 'seller', warnings });
                await snap('E: after company added');
                liveExpectations.push({
                    label: 'E: after company added',
                    names: ['RegDirectorOne HarnessFixture', 'RegDirectorTwo HarnessFixture', 'RegDirectorThree HarnessFixture'],
                });

                // 2026-08-27 — Johan found by hand: "picking a proxy does not
                // update the seller section, and the proxy clause does not
                // appear." Real two-click sequence (tick + pick a
                // representative radio), no settle time, captured
                // immediately — see driver.tickProxy()'s own comment for why
                // no delay is deliberate here.
                await driver.tickProxy(page, 'REG Proxy Test CC');
                await snap('E: after proxy picked');
                liveExpectations.push({
                    label: 'E: after proxy picked',
                    // All three directors must still appear (assertion 5) —
                    // but the document must visibly change (the proxy
                    // marker/clause), which compareRawChangedFrom checks.
                    names: ['RegDirectorOne HarnessFixture', 'RegDirectorTwo HarnessFixture', 'RegDirectorThree HarnessFixture'],
                    compareRawChangedFrom: 'E: after company added',
                });

                await driver.saveDraft(page);
                return {
                    liveExpectations,
                    expected: {
                        // All three directors must still appear in Domicilium
                        // (assertion 5) even though only one signs.
                        domiciliumNames: ['RegDirectorOne HarnessFixture', 'RegDirectorTwo HarnessFixture', 'RegDirectorThree HarnessFixture'],
                        deceasedNames: [],
                        signingOrderCount: 2, // agent + ONE proxy signer, not all 3 directors
                    },
                };
            },
        },
        {
            key: 'F',
            label: 'A recipient with hand-typed details and no linked contact',
            async build(page, warnings, snap) {
                const liveExpectations = [];
                await driver.addRecipientManual(page, {
                    name: 'RegManualEntry HarnessFixture',
                    idNumber: '8006015800110',
                    email: 'reg.manual.entry@harness.test',
                    cell: '0821000099',
                    address: '9 Manual Entry Close, Ramsgate',
                    role: 'seller',
                });
                await snap('F: after manual recipient added');
                liveExpectations.push({ label: 'F: after manual recipient added', names: ['RegManualEntry HarnessFixture'] });

                await driver.saveDraft(page);
                return {
                    liveExpectations,
                    expected: {
                        domiciliumNames: ['RegManualEntry HarnessFixture'],
                        deceasedNames: [],
                        signingOrderCount: 2, // agent + manual recipient
                        manualValues: { tel: '0821000099', address: '9 Manual Entry Close, Ramsgate' },
                    },
                };
            },
        },
    ];
}

module.exports = { shapeList };
