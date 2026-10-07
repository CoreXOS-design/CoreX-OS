/**
 * AT-387 — make every server-rendered PDF print the characters the author meant.
 *
 * Word documents bullet and tick with the Symbol / Wingdings fonts, whose glyphs live in the Unicode
 * PRIVATE USE AREA (U+F0xx). The bytes survive import and the DB perfectly, but the server's Chromium
 * has no font that owns those code points, so they print as an empty box (tofu) on a client-facing
 * document — e.g. the two notice bullets in the Exclusive Authority to Sell.
 *
 * Both Chromium render scripts (html-to-pdf.mjs, web-template-flatten.mjs) call this once the page has
 * loaded and BEFORE anything is measured, so it fixes every PDF and page image CoreX produces in one
 * place instead of per template. Stored content is never touched — only the rendered copy.
 *
 *   - Known Symbol/Wingdings code points are mapped to the real Unicode character they stand for.
 *   - Any OTHER private-use character has no portable meaning and can only ever print as a box, so it
 *     is dropped from the rendered copy (counted and reported to stderr, never silent).
 */

// Symbol/Wingdings private-use code point -> the Unicode character it stands for.
export const PRIVATE_USE_GLYPH_MAP = {
    '': '•', // Symbol bullet            -> •
    '': '▪', // Wingdings small square   -> ▪
    '': '■', // Wingdings black square   -> ■
    '': '□', // Wingdings hollow square  -> □
    '': '❖', // Wingdings diamond cross  -> ❖
    '': '➢', // Wingdings arrowhead      -> ➢
    '': '➔', // Wingdings arrow          -> ➔
    '': '✓', // Wingdings tick           -> ✓
    '': '✗', // Wingdings cross          -> ✗
};

/**
 * Rewrite the text nodes of a loaded Puppeteer page. Returns { mapped, dropped } counts.
 * Never throws: a failure here must not stop a document being produced.
 */
export async function normalisePrivateUseGlyphs(page) {
    try {
        const result = await page.evaluate((map) => {
            const pua = /[-]/g;
            let mapped = 0;
            let dropped = 0;
            const walker = document.createTreeWalker(document.body || document.documentElement, NodeFilter.SHOW_TEXT);
            const skip = new Set(['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEXTAREA']);
            for (let node = walker.nextNode(); node; node = walker.nextNode()) {
                if (skip.has(node.parentNode && node.parentNode.nodeName)) continue;
                const before = node.nodeValue;
                if (!pua.test(before)) { pua.lastIndex = 0; continue; }
                pua.lastIndex = 0;
                node.nodeValue = before.replace(pua, (c) => {
                    if (map[c]) { mapped++; return map[c]; }
                    dropped++;
                    return '';
                });
            }
            return { mapped, dropped };
        }, PRIVATE_USE_GLYPH_MAP);
        if (result.dropped > 0) {
            console.error(JSON.stringify({ warning: 'private-use glyphs with no Unicode equivalent were dropped from the rendered copy', dropped: result.dropped }));
        }
        return result;
    } catch (e) {
        return { mapped: 0, dropped: 0 };
    }
}
