// E-sign regression harness — the 6 flow shapes.
//
// Each shape's `build()` drives Template -> Property -> Recipients on a
// FRESH flow and returns { flowId, expected }. `expected` is what THIS shape
// asserts against — not a guess, the literal names/roles a correct document
// must show, derived from the fixture data the shape itself just picked.

const driver = require('./lib/driver');

const PROPERTY_SEARCH = 'Regression Harness Way';
const PROPERTY_MATCH = 'Regression Harness Way';
const TEMPLATE_BUTTON = 'EXCLUSIVE AUTHORITY TO SELL';

function shapeList(fixtures) {
    return [
        {
            key: 'A',
            label: 'Two ordinary natural-person sellers, nothing ticked',
            async build(page, warnings) {
                await driver.addRecipientBySearch(page, { searchTerm: 'RegSellerOne HarnessFixture', matchText: 'RegSellerOne', role: 'seller', warnings });
                await driver.addRecipientBySearch(page, { searchTerm: 'RegSellerTwo HarnessFixture', matchText: 'RegSellerTwo', role: 'seller', warnings });
                await driver.saveDraft(page);
                return {
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
            async build(page, warnings) {
                await driver.addRecipientBySearch(page, { searchTerm: 'RegDeceased HarnessFixture', matchText: 'RegDeceased', role: 'seller', warnings });
                await driver.addRecipientBySearch(page, { searchTerm: 'RegSellerOne HarnessFixture', matchText: 'RegSellerOne', role: 'seller', warnings });
                await driver.tickDeceasedAndBindExecutor(page, {
                    namePart: 'RegDeceased',
                    templateName: 'Late Estate',
                    executorSource: 'contact',
                    executorSearchTerm: 'RegExecutor HarnessFixture',
                    executorMatchText: 'RegExecutor',
                });
                return {
                    expected: {
                        domiciliumNames: ['RegSellerOne HarnessFixture', 'RegExecutor HarnessFixture'],
                        deceasedNames: ['RegDeceased HarnessFixture'],
                        signingOrderCount: 4, // agent + living seller + executor + the deceased herself — she still gets a signing-order CARD (skipEmail excludes her from dispatch, not from the list; matches the already-known, already-flagged "never receives a signing request" design)
                    },
                };
            },
        },
        {
            key: 'C',
            label: 'One seller deceased, executor from the Supplier directory',
            async build(page, warnings) {
                await driver.addRecipientBySearch(page, { searchTerm: 'RegDeceased HarnessFixture', matchText: 'RegDeceased', role: 'seller', warnings });
                await driver.addRecipientBySearch(page, { searchTerm: 'RegSellerTwo HarnessFixture', matchText: 'RegSellerTwo', role: 'seller', warnings });
                await driver.tickDeceasedAndBindExecutor(page, {
                    namePart: 'RegDeceased',
                    templateName: 'Late Estate',
                    executorSource: 'supplier',
                    executorSearchTerm: 'RegSupplierExecutor',
                    executorMatchText: 'RegSupplierExecutor',
                });
                return {
                    expected: {
                        domiciliumNames: ['RegSellerTwo HarnessFixture', 'RegSupplierExecutor HarnessFixture'],
                        deceasedNames: ['RegDeceased HarnessFixture'],
                        signingOrderCount: 4, // agent + living seller + executor + the deceased herself (see shape B's note)
                    },
                };
            },
        },
        {
            key: 'D',
            label: 'Company with three directors, NO proxy (Johan\'s signed-off flow)',
            async build(page, warnings) {
                await driver.addRecipientBySearch(page, { searchTerm: 'REG Proxy Test CC', matchText: 'REG Proxy Test CC', role: 'seller', warnings });
                await driver.saveDraft(page);
                return {
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
            async build(page, warnings) {
                await driver.addRecipientBySearch(page, { searchTerm: 'REG Proxy Test CC', matchText: 'REG Proxy Test CC', role: 'seller', warnings });
                await driver.tickProxy(page, 'REG Proxy Test CC');
                await driver.saveDraft(page);
                return {
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
            async build(page, warnings) {
                await driver.addRecipientManual(page, {
                    name: 'RegManualEntry HarnessFixture',
                    idNumber: '8006015800110',
                    email: 'reg.manual.entry@harness.test',
                    cell: '0821000099',
                    address: '9 Manual Entry Close, Ramsgate',
                    role: 'seller',
                });
                await driver.saveDraft(page);
                return {
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
