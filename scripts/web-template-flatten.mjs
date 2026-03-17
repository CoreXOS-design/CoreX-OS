/**
 * Flatten a web template HTML to PDF and measure client field positions.
 *
 * Does two things in one Puppeteer session:
 * 1. Measures bounding rects of client fields (data-field elements)
 * 2. Generates PDF from the same HTML
 *
 * Usage:
 *   node scripts/web-template-flatten.mjs <input.html> <output.pdf> [fields.json]
 *
 * Arguments:
 *   input.html   — path to the HTML file
 *   output.pdf   — path for the generated PDF
 *   fields.json  — (optional) path to JSON file with fields to measure:
 *                   [{ "field_id": "abc", "data_field": "lessor_name" }, ...]
 *
 * Output (stdout JSON):
 *   {
 *     "success": true,
 *     "pages": 3,
 *     "page_height_px": 1123,
 *     "page_width_px": 794,
 *     "fields": [
 *       { "field_id": "abc", "data_field": "lessor_name", "x": 12.5, "y": 34.2, "width": 20, "height": 2.1, "pageIndex": 0 }
 *     ]
 *   }
 *
 * Field positions are returned as PERCENTAGES (0-100) of the page dimensions.
 *
 * Set PUPPETEER_BROWSER_PATH to the browser executable path.
 * Falls back to common Edge paths on Windows, then /usr/bin/chromium-browser on Linux.
 */
import puppeteer from 'puppeteer-core';
import { existsSync, readFileSync } from 'fs';
import { resolve } from 'path';
import { pathToFileURL } from 'url';

const [,, inputArg, outputArg, fieldsArg] = process.argv;

if (!inputArg || !outputArg) {
    console.error('Usage: node scripts/web-template-flatten.mjs <input.html> <output.pdf> [fields.json]');
    process.exit(1);
}

const inputPath  = resolve(inputArg);
const outputPath = resolve(outputArg);

if (!existsSync(inputPath)) {
    console.error(`Input file not found: ${inputPath}`);
    process.exit(1);
}

// Load fields to measure (optional)
let fieldsToMeasure = [];
if (fieldsArg) {
    const fieldsPath = resolve(fieldsArg);
    if (existsSync(fieldsPath)) {
        try {
            fieldsToMeasure = JSON.parse(readFileSync(fieldsPath, 'utf8'));
        } catch (e) {
            console.error(`Failed to parse fields JSON: ${e.message}`);
        }
    }
}

// Locate browser — env var first, then Windows Edge paths, then Linux Chromium
const candidatePaths = [
    process.env.PUPPETEER_BROWSER_PATH,
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    '/usr/bin/chromium-browser',
    '/usr/bin/chromium',
    '/usr/bin/google-chrome',
].filter(Boolean);

const executablePath = candidatePaths.find(p => existsSync(p));
if (!executablePath) {
    console.error('No Chromium-based browser found. Set PUPPETEER_BROWSER_PATH environment variable.');
    process.exit(1);
}

// A4 dimensions at 96 DPI (CSS pixels)
const A4_WIDTH_PX  = 794;
const A4_HEIGHT_PX = 1123;

let browser;
try {
    browser = await puppeteer.launch({
        executablePath,
        headless: true,
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-gpu',
            '--disable-software-rasterizer',
        ],
    });

    const page = await browser.newPage();

    // Set viewport to A4 width so layout matches print output
    await page.setViewport({
        width: A4_WIDTH_PX,
        height: A4_HEIGHT_PX,
        deviceScaleFactor: 1,
    });

    // Navigate to the local HTML file
    const fileUrl = pathToFileURL(inputPath).href;
    await page.goto(fileUrl, { waitUntil: 'networkidle0', timeout: 120000 });

    // ===== PASS 1: Measure field positions =====
    const measuredFields = [];

    if (fieldsToMeasure.length > 0) {
        // Measure the full document body height to determine page boundaries
        const bodyHeight = await page.evaluate(() => document.body.scrollHeight);
        const pageHeightPx = A4_HEIGHT_PX;

        for (const field of fieldsToMeasure) {
            const dataField = field.data_field;
            if (!dataField) continue;

            // Find the element with matching data-field attribute
            const rect = await page.evaluate((df) => {
                // Try exact match first
                let el = document.querySelector(`[data-field="${df}"]`);

                // If not found, try partial match (some fields use compound names)
                if (!el) {
                    const allFields = document.querySelectorAll('[data-field]');
                    for (const candidate of allFields) {
                        if (candidate.getAttribute('data-field') === df) {
                            el = candidate;
                            break;
                        }
                    }
                }

                if (!el) return null;

                const r = el.getBoundingClientRect();
                // Include scroll offset for absolute position in document
                return {
                    x: r.x,
                    y: r.y + window.scrollY,
                    width: r.width,
                    height: r.height,
                };
            }, dataField);

            if (!rect || rect.width === 0 || rect.height === 0) continue;

            // Calculate which page this field falls on
            const pageIndex = Math.floor(rect.y / pageHeightPx);
            // Y position relative to the page it's on
            const yOnPage = rect.y - (pageIndex * pageHeightPx);

            // Convert to percentages of page dimensions
            measuredFields.push({
                field_id:   field.field_id,
                data_field: dataField,
                x:          parseFloat(((rect.x / A4_WIDTH_PX) * 100).toFixed(2)),
                y:          parseFloat(((yOnPage / pageHeightPx) * 100).toFixed(2)),
                width:      parseFloat(((rect.width / A4_WIDTH_PX) * 100).toFixed(2)),
                height:     parseFloat(((rect.height / pageHeightPx) * 100).toFixed(2)),
                pageIndex:  pageIndex,
            });
        }
    }

    // ===== PASS 2: Generate PDF =====
    await page.emulateMediaType('print');

    await page.pdf({
        path: outputPath,
        format: 'A4',
        margin: {
            top:    '15mm',
            right:  '18mm',
            bottom: '20mm',
            left:   '18mm',
        },
        printBackground: true,
        preferCSSPageSize: true,
    });

    // Count pages from the generated PDF (estimate from body height)
    const pageCount = Math.max(1, Math.ceil(bodyHeight / A4_HEIGHT_PX));

    // Calculate actual page count from the measured fields if available
    let maxPageFromFields = 0;
    for (const f of measuredFields) {
        if (f.pageIndex > maxPageFromFields) {
            maxPageFromFields = f.pageIndex;
        }
    }
    const finalPageCount = Math.max(pageCount, maxPageFromFields + 1);

    // Output result as JSON
    console.log(JSON.stringify({
        success: true,
        pages: finalPageCount,
        page_height_px: A4_HEIGHT_PX,
        page_width_px: A4_WIDTH_PX,
        fields: measuredFields,
    }));

} catch (err) {
    console.error(JSON.stringify({ success: false, error: err.message }));
    process.exit(1);
} finally {
    if (browser) await browser.close();
}
