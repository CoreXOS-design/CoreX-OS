#!/usr/bin/env node
/**
 * Runs the real e-sign paginator (a4-page-styles.blade.php: paginateDocument) in a real Chromium on a rendered document and
 * reports how it paginated, then prints the paginated DOM through the signed-PDF shell and counts the PDF's pages.
 * Used by tests/Feature/Docuperfect/SigningView/SourcePageBreaksAllRoutesTest.php (spec cds-reimport-from-word.md section 10).
 *
 * Input  (argv[2]): path to a JSON file {"partial": "<rendered a4-page-styles partial>", "html": "<document html>", "raw": true when html already carries its own .corex-document-wrapper,
 *                   "shell": "<path to write the paginated DOM to>", "pdf": "<path to print the signed-PDF shell to>"}
 * Output (stdout) : JSON {pages, headers, footers, pageNumbers[], corexPageNumbers, initialsRows, firstPageHasHeader, pdfShellHtml}
 */
import fs from 'node:fs';
import puppeteer from '/corex-qa1/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js';

const cfg = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const browser = await puppeteer.launch({executablePath: '/usr/bin/chromium', headless: true, args: ['--no-sandbox', '--disable-gpu']});
try {
    const page = await browser.newPage();
    await page.setViewport({width: 1000, height: 1200});
    await page.setContent('<!doctype html><html><head><meta charset="utf-8"></head><body>' + cfg.partial
        + (cfg.raw ? '<div id="c">' + cfg.html + '</div>' : '<div id="c"><div class="corex-document-wrapper corex-look-source"><div class="corex-page">' + cfg.html + '</div></div></div>')
        + '</body></html>', {waitUntil: 'load'});
    const result = await page.evaluate((cfgParties) => {
        window.__parties = cfgParties;
        paginateDocument(document.getElementById('c'), window.__parties);
        const pages = [...document.querySelectorAll('#c .corex-a4-page')];
        return {
            pages: pages.length,
            headers: document.querySelectorAll('#c .corex-a4-page .corex-page-header').length,
            footers: document.querySelectorAll('#c .corex-a4-page .corex-page-footer').length,
            pageNumbers: pages.map(p => (p.querySelector('.corex-page-footer .corex-page-number') || {textContent: ''}).textContent),
            corexPageNumbers: document.querySelectorAll('#c .corex-a4-page .page-number').length,
            initialsRows: document.querySelectorAll('#c .corex-a4-page .corex-page-initials-row').length,
            firstPageHasHeader: !!pages[0].querySelector('.corex-page-header'),
            breakMarkersLeft: document.querySelectorAll('#c .corex-src-page-break').length,
            paginatedHtml: document.getElementById('c').innerHTML,
        };
    }, cfg.parties || []);
    fs.writeFileSync(cfg.shell, result.paginatedHtml);
    delete result.paginatedHtml;
    console.log(JSON.stringify(result));
} finally {
    await browser.close();
}
