/**
 * Convert a self-contained HTML file to PDF using Chromium via puppeteer.
 *
 * Usage: node scripts/html-to-pdf.mjs <input.html> <output.pdf>
 *
 * Puppeteer downloads and manages its own Chromium binary.
 * Override with PUPPETEER_BROWSER_PATH if needed.
 */
import puppeteer from 'puppeteer';
import { existsSync } from 'fs';
import { resolve } from 'path';
import { pathToFileURL } from 'url';

const [,, inputArg, outputArg] = process.argv;

if (!inputArg || !outputArg) {
    console.error('Usage: node scripts/html-to-pdf.mjs <input.html> <output.pdf>');
    process.exit(1);
}

const inputPath  = resolve(inputArg);
const outputPath = resolve(outputArg);

if (!existsSync(inputPath)) {
    console.error(`Input file not found: ${inputPath}`);
    process.exit(1);
}

// Puppeteer ships its own Chromium; allow override via env or common system paths
const launchOptions = {
    headless: 'new',
    args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--disable-gpu',
        '--no-zygote',
        '--single-process',
        '--disable-crash-reporter',
        '--disable-breakpad',
    ],
};

// Use explicit browser path if set via env or found on system
const candidatePaths = [
    process.env.PUPPETEER_BROWSER_PATH,
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
].filter(Boolean);

const executablePath = candidatePaths.find(p => existsSync(p));
if (executablePath) {
    launchOptions.executablePath = executablePath;
}

let browser;
try {
    browser = await puppeteer.launch(launchOptions);

    const page = await browser.newPage();

    // Navigate to the local HTML file. A self-contained local file:// page
    // must render in seconds — 'load' fires when the document + its
    // (embedded, local) resources are parsed. 'networkidle0' is deliberately
    // NOT used: any single stalled request (e.g. an unreachable remote font)
    // makes it never settle and blocks the full timeout. Fonts are embedded
    // (data: URIs) so there are no network requests to wait on. Short
    // timeout = fail fast, never a multi-minute hang.
    const fileUrl = pathToFileURL(inputPath).href;
    await page.goto(fileUrl, { waitUntil: 'load', timeout: 20000 });

    // Ensure embedded @font-face faces are parsed/applied before capture so
    // glyphs are not rendered with a fallback. Guarded so a stuck/older
    // engine cannot hang here either.
    await Promise.race([
        page.evaluate(async () => { try { await document.fonts.ready; } catch (e) {} }),
        new Promise(r => setTimeout(r, 5000)),
    ]);

    // Emulate print media for @media print styles
    await page.emulateMediaType('print');

    // Generate PDF matching browser Ctrl+P output
    await page.pdf({
        path: outputPath,
        format: 'A4',
        margin: {
            top:    '0',
            right:  '0',
            bottom: '0',
            left:   '0',
        },
        printBackground: true,
        preferCSSPageSize: true,
    });

    console.log(JSON.stringify({ success: true, output: outputPath }));
} catch (err) {
    console.error(JSON.stringify({ success: false, error: err.message }));
    process.exit(1);
} finally {
    if (browser) await browser.close();
}
