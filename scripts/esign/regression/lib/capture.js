// E-sign regression harness — page-state capture and parsing.
//
// Every function here reads what the REAL RENDERED PAGE shows (innerText of
// the live preview pane, or live DOM input values on the signing screen) —
// never an internal API response, never a database row. That is the entire
// point: prove what the screen shows, the way Johan tests.

// The document preview container's class differs by screen: the wizard's
// own steps (Recipients through Sign & Send) use `.web-template-preview`;
// the live signing screen (`/documents/{id}/sign`) runs client-side
// pagination (paginateDocument()) that rebuilds the DOM under
// `.corex-document-wrapper` / `.corex-a4-page` instead, once pagination has
// run. Try both, in the order a given screen is likely to have.
async function extractPreviewText(page) {
    return page.evaluate(() => {
        const el = document.querySelector('.web-template-preview')
            || document.querySelector('.corex-document-wrapper')
            || document.querySelector('.corex-a4-page')?.parentElement;
        return el ? el.innerText : '';
    });
}

// Parses the Domicilium block into one entry per "<Role> - <Name>" row.
// Returns { found, raw, entries: [{ role, name, address, tel, email }] }.
function parseDomicilium(fullText) {
    const startIdx = fullText.indexOf('DOMICIL');
    if (startIdx < 0) return { found: false, raw: '', entries: [] };

    // Section ends at the next numbered clause heading ("2.  TERMS...") or,
    // failing that, a generous cutoff so a template with different clause
    // numbering still gets a usable (if slightly long) slice.
    let endIdx = fullText.length;
    const endRe = /\n\s*2\.\s+[A-Z]/;
    const m = endRe.exec(fullText.slice(startIdx));
    if (m) endIdx = startIdx + m.index;

    const section = fullText.slice(startIdx, endIdx);

    const entries = [];
    const entryRe = /(Seller|Buyer|Landlord|Tenant)\s*-\s*([^\n]+)\r?\nPhysical address\s*([^\n]*)\r?\nTel:\s*(.*?)\s+Email:\s*([^\n]*)/g;
    let em;
    while ((em = entryRe.exec(section)) !== null) {
        entries.push({
            role: em[1].trim(),
            name: em[2].trim(),
            address: em[3].trim(),
            tel: em[4].trim(),
            email: em[5].trim(),
        });
    }
    return { found: true, raw: section, entries };
}

// Extracts the clause's opening sentence — "I / We <description> the
// undersigned" — where deceased/executor/proxy/entity phrasing lives.
function parseClauseOpening(fullText) {
    const idx = fullText.search(/I\s*\/\s*We\s/);
    if (idx < 0) return { found: false, text: '' };
    const endIdx = fullText.indexOf('the undersigned', idx);
    const text = endIdx > idx
        ? fullText.slice(idx, endIdx + 'the undersigned'.length)
        : fullText.slice(idx, idx + 500);
    return { found: true, text: text.replace(/\s+/g, ' ').trim() };
}

// Static-preview signature block summary (Fill & Review / Sign & Send
// stages, before any interactive signing UI exists). Counts blocks and
// pulls the role, and the signer name when the template prints one in
// parentheses (the live signing screen does; the earlier draft stages
// often don't — that's a legitimate template difference, not tracked as
// a mismatch by the assertions).
function parseSignatureBlockSummary(fullText) {
    const idx = fullText.indexOf('Signatures');
    const section = idx >= 0 ? fullText.slice(idx) : fullText;
    const blocks = [];
    const blockRe = /Thus done and signed by the (\w+)\s*\(?([^)\n]*)\)?\s*at/g;
    let bm;
    while ((bm = blockRe.exec(section)) !== null) {
        blocks.push({ role: bm[1].trim(), name: (bm[2] || '').trim() });
    }
    return { count: blocks.length, blocks };
}

// DOM-level capture of the interactive agent-signing screen: for each
// "Thus done and signed by the ROLE(NAME)" block, reads the actual place/
// day/month/year/time INPUT VALUES next to it — these are editable fields,
// not static text, and this is the exact place cc1's 522a84107 fix (and the
// bug it fixed) lived: one signer's place/time showing on another's block.
async function captureSignPageBlocks(page) {
    return page.evaluate(() => {
        const body = document.body.innerText;
        const results = [];
        // Walk every element whose own text starts the "Thus done and signed"
        // sentence, then read the <input> elements that follow it in the DOM
        // up to the next such sentence or the signature pad itself.
        const all = Array.from(document.querySelectorAll('*'));
        const starts = all.filter(el =>
            el.children.length === 0 &&
            el.textContent && el.textContent.trim().startsWith('Thus done and signed by the')
        );
        starts.forEach((el, i) => {
            const roleMatch = el.textContent.match(/Thus done and signed by the (\w+)\s*\(?([^)]*)\)?/);
            const role = roleMatch ? roleMatch[1] : null;
            const name = roleMatch && roleMatch[2] ? roleMatch[2].trim() : null;
            // Collect input values within the same containing block (walk up
            // to a reasonably-scoped ancestor, then take the first N inputs
            // after this element in document order, before the NEXT "Thus
            // done" element).
            let container = el.closest('div');
            let hops = 0;
            while (container && container.parentElement && hops < 6) {
                container = container.parentElement;
                hops++;
            }
            const inputs = container ? Array.from(container.querySelectorAll('input')) : [];
            // Restrict to inputs that appear AFTER this element and BEFORE
            // the next "Thus done" element, by DOM position comparison.
            const nextStart = starts[i + 1];
            const scoped = inputs.filter(inp => {
                const afterThis = !!(el.compareDocumentPosition(inp) & Node.DOCUMENT_POSITION_FOLLOWING);
                const beforeNext = !nextStart || !!(nextStart.compareDocumentPosition(inp) & Node.DOCUMENT_POSITION_FOLLOWING);
                return afterThis && beforeNext;
            });
            results.push({
                role, name,
                inputValues: scoped.map(inp => inp.value || ''),
            });
        });
        return results;
    });
}

// Counts the cards under "SIGNING ORDER" on the Sign & Send step —
// "Agent: Johan Reichel", "Seller: X", etc. Used for assertion 5 (only ONE
// proxy signer, not all representatives, ends up in the signing order).
function parseSigningOrderCount(fullText) {
    const idx = fullText.indexOf('SIGNING ORDER');
    if (idx < 0) return null;
    const section = fullText.slice(idx, idx + 6000);
    const matches = section.match(/(Agent|Seller|Buyer|Landlord|Tenant):\s*[A-Z]/g);
    return matches ? matches.length : 0;
}

// 2026-08-27 — Gap 2 (Johan, by hand): on Preview, a deceased party showed
// THREE seller initial blocks where there should be two; on Agent Signing,
// three initial blocks but only two seller signature blocks mid-document,
// while the final signing list was correct — three different counts of the
// same thing on one document. Page-break initial boxes are built client-side
// by paginateDocument()'s _buildInitialsRow() (a4-page-styles.blade.php) —
// one `.corex-page-initials` box per entry in `signingParties`, one
// `.corex-page-initials-row` per page break (none exist on a single-page
// document — reported honestly as rowsFound:0, not a failure). Every row
// should carry the same party set, so the first row is representative.
async function countInitialsRow(page) {
    return page.evaluate(() => {
        const rows = document.querySelectorAll('.corex-page-initials-row');
        if (rows.length === 0) return { rowsFound: 0, firstRowCount: null, firstRowLabels: [] };
        const boxes = Array.from(rows[0].querySelectorAll('.corex-page-initials'));
        return {
            rowsFound: rows.length,
            firstRowCount: boxes.length,
            firstRowLabels: boxes.map(b => ({
                role: b.getAttribute('data-marker-party') || '',
                label: (b.textContent || '').trim(),
            })),
        };
    });
}

module.exports = {
    extractPreviewText,
    parseDomicilium,
    parseClauseOpening,
    parseSignatureBlockSummary,
    captureSignPageBlocks,
    parseSigningOrderCount,
    countInitialsRow,
};
