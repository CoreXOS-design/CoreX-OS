#!/usr/bin/env node
/**
 * Prints an HTML file with Chromium exactly the way the signed-PDF route does (A4, backgrounds on, no external requests) and
 * writes the PDF; prints the PDF's page count. Used by SourcePageBreaksAllRoutesTest.
 * Usage: node scripts/print-shell-pdf.mjs <in.html> <out.pdf>
 */
import fs from 'node:fs';
import puppeteer from 'puppeteer-core';

const [input, output] = process.argv.slice(2);
const browser = await puppeteer.launch({executablePath: '/usr/bin/chromium', headless: true, args: ['--no-sandbox', '--disable-gpu']});
try {
    const page = await browser.newPage();
    await page.setRequestInterception(true);
    page.on('request', r => (r.url().startsWith('file:') || r.url().startsWith('data:')) ? r.continue() : r.abort());
    await page.goto('file://' + input, {waitUntil: 'load', timeout: 60000});
    await page.pdf({path: output, format: 'A4', printBackground: true, preferCSSPageSize: true});
} finally {
    await browser.close();
}
const bytes = fs.readFileSync(output).toString('latin1');
console.log((bytes.match(/\/Type\s*\/Page[^s]/g) || []).length);
