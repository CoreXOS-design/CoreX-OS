// AT-525 / AT-527 - render the hook / brand-line / end cards of a video ad as PNGs, at each format's size (headless Chromium). Input: a job json
// { chromium, items: [{ html: "<file>", out: "<png>", width, height }] }. The HTML is written by AdCards.php (brand colours, Inter from resources/fonts/inter).
import fs from 'node:fs';
import puppeteer from 'puppeteer-core';

const job = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const browser = await puppeteer.launch({ executablePath: job.chromium, headless: true, args: ['--no-sandbox', '--disable-gpu', '--allow-file-access-from-files'] });
try {
  for (const it of job.items) {
    const page = await browser.newPage();
    await page.setViewport({ width: it.width, height: it.height, deviceScaleFactor: 1 });
    await page.goto('file://' + it.html, { waitUntil: 'networkidle0', timeout: 60000 });
    await page.evaluate(() => document.fonts.ready);
    await page.screenshot({ path: it.out, type: 'png', omitBackground: !!it.transparent });
    await page.close();
  }
} finally {
  await browser.close();
}
console.log('cards ok: ' + job.items.length);
