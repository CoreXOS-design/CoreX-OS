#!/usr/bin/env node
/**
 * End-to-end proof of "Get full details" (spec .ai/specs/mic-work-tab.md 13.16 / 13.16b) on a real CoreX site with the REAL extension loaded in Chromium.
 *
 *   node scripts/mic-full-details-e2e.mjs --setup=/tmp/x/setup.json --user-id=22 [--base=https://qatesting1.corexos.co.za] [--shots=DIR]
 *
 * The portal is never contacted: www.property24.com is mapped to this machine (host-resolver-rules) and answered with a SAVED advert page from the extension's test fixtures.
 * The advert it reads is an invented test row (setup.json: id, ref, token), so nothing real is written. Needs: openssl, chromium (the stand-in portal listens on 127.0.0.1:8443).
 */
import fs from 'node:fs';
import path from 'node:path';
import https from 'node:https';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import puppeteer from '/corex-qa1/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const arg = (k, d) => { const a = process.argv.find((x) => x.startsWith(`--${k}=`)); return a ? a.split('=').slice(1).join('=') : d; };
const BASE = arg('base', 'https://qatesting1.corexos.co.za'), USER_ID = arg('user-id', '22'), SHOTS = arg('shots', '/tmp/mic-cc2/e2e/shots');
const SETUP = JSON.parse(fs.readFileSync(arg('setup', '/tmp/mic-cc2/e2e/setup.json'), 'utf8'));
const WORK = '/tmp/mic-cc2/e2e';
const PART = arg('part', 'AB');   // B (no extension) needs the advert unread, so run B before A
fs.mkdirSync(SHOTS, { recursive: true });

let fails = 0;
const ok = (c, m) => { console.log((c ? '  ok   ' : '  FAIL ') + m); if (!c) fails++; };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ---- the stand-in portal ----
const EXT = path.join(WORK, 'ext');
fs.rmSync(EXT, { recursive: true, force: true });
execFileSync('unzip', ['-q', path.join(__dirname, '../public/downloads/portal-capture-extension-3.16.0.zip'), '-d', EXT]);
execFileSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', path.join(WORK, 'k.pem'), '-out', path.join(WORK, 'c.pem'), '-days', '1', '-subj', '/CN=www.property24.com'], { stdio: 'ignore' });
const fixture = fs.readFileSync(path.join(__dirname, '../public/chrome-extension/portal-capture/tests/fixtures/p24/house-ramsgate-sole-mandate-117752073.html'));
const hits = [];
const portal = https.createServer({ key: fs.readFileSync(path.join(WORK, 'k.pem')), cert: fs.readFileSync(path.join(WORK, 'c.pem')) }, (req, res) => {
    hits.push(req.url);
    res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
    res.end(fixture);
});
await new Promise((r) => portal.listen(8443, '127.0.0.1', r));

const out = execFileSync('php8.2', [path.join(__dirname, 'mint-session-cookie.php'), '--app-root=/corex-qa1', `--user-id=${USER_ID}`], { encoding: 'utf8' });
const cookie = { name: out.match(/COOKIE_NAME=(.+)/)[1].trim(), value: out.match(/COOKIE_VALUE=(.+)/)[1].trim() };
const host = new URL(BASE).hostname;

async function launch(withExt) {
    const args = ['--no-sandbox', '--disable-gpu', '--host-resolver-rules=MAP www.property24.com 127.0.0.1:8443', '--ignore-certificate-errors'];
    if (withExt) args.push(`--disable-extensions-except=${EXT}`, `--load-extension=${EXT}`);
    return puppeteer.launch({ executablePath: '/usr/bin/chromium', headless: 'new', args, ignoreDefaultArgs: withExt ? ['--disable-extensions'] : [] });
}
async function openWork(browser, query) {
    const page = await browser.newPage();
    await page.setViewport({ width: 1500, height: 950 });
    await page.setCookie({ name: cookie.name, value: cookie.value, domain: host, path: '/', httpOnly: true, secure: true });
    await page.goto(`${BASE}/corex/market-intelligence?view=adverts&clear=1&${query}`, { waitUntil: 'networkidle2', timeout: 120000 });
    return page;
}
const card = (page) => page.$(`[data-qa="advert-level"][data-listing="${SETUP.id}"]`);

// ===== A. with the extension =====
let browser;
if (PART.includes('A')) {
console.log('A. with the CoreX extension loaded');
browser = await launch(true);
try {
    const sw = await browser.waitForTarget((t) => t.type() === 'service_worker' && t.url().startsWith('chrome-extension://'), { timeout: 20000 });
    const worker = await sw.worker();
    await worker.evaluate((s) => new Promise((r) => chrome.storage.local.set(s, r)), { apiUrl: BASE, apiToken: SETUP.token });
    ok(true, 'extension service worker is up and signed in to ' + BASE);

    const page = await openWork(browser, 'search=' + SETUP.ref);
    ok(!!(await card(page)), 'the test advert is on the Work page');
    let label = await page.$eval(`[data-qa="advert-level"][data-listing="${SETUP.id}"]`, (e) => e.innerText);
    ok(/Search view/.test(label) && /Get full details/.test(label), 'before: "' + label.replace(/\s+/g, ' ') + '"');
    await page.screenshot({ path: path.join(SHOTS, '1-search-view.png') });
    const pagesBefore = (await browser.pages()).length;

    await page.click(`[data-advert-fetch-wrap][data-listing="${SETUP.id}"] [data-advert-fetch]`);
    await sleep(600);
    const reading = await page.$eval(`[data-advert-fetch-wrap][data-listing="${SETUP.id}"]`, (e) => e.innerText);
    ok(/Reading|Checking|Waiting/.test(reading), 'the wheel shows while it reads: "' + reading.replace(/\s+/g, ' ') + '"');
    await page.screenshot({ path: path.join(SHOTS, '2-reading.png') });
    const tabsDuring = (await browser.pages()).length;

    await page.waitForFunction((id) => { const e = document.querySelector(`[data-qa="advert-level"][data-listing="${id}"]`); return e && /Full details ·/.test(e.innerText); }, { timeout: 60000 }, SETUP.id).catch(() => {});
    label = await page.$eval(`[data-qa="advert-level"][data-listing="${SETUP.id}"]`, (e) => e.innerText).catch(() => '(row gone)');
    ok(/Full details · \d+ \w+ · \w+/.test(label), 'after: "' + label.replace(/\s+/g, ' ') + '"');
    const pageHits = hits.filter((u) => !/^\/(bundles|Content|blank\.gif|manifest\.json|favicon)/.test(u));
    ok(pageHits.length === 1, 'the portal was asked for exactly ONE advert page: ' + pageHits.join(',') + ' (+' + (hits.length - pageHits.length) + ' of its own pictures and scripts)');
    await sleep(800);
    ok((await browser.pages()).length === pagesBefore, `the background tab was closed (tabs: ${pagesBefore} before, ${tabsDuring} during, ${(await browser.pages()).length} after)`);
    await page.screenshot({ path: path.join(SHOTS, '3-full-details.png') });

    const info = await page.evaluate((id) => {
        const w = document.querySelector(`[data-qa="advert-level"][data-listing="${id}"]`); const c = w.closest('[data-qa="advert-card"]');
        return { photos: c.querySelector('[data-qa="photo-count"]')?.textContent, arrows: !!c.querySelector('[data-gallery-next]'), readMore: !!c.querySelector('[data-qa="read-more"]'), desc: c.querySelector('[data-qa="advert-description"]')?.innerText.slice(0, 80) };
    }, SETUP.id);
    ok(/^\d+ photos$/.test(info.photos || ''), 'photo count on the tile: ' + info.photos);
    ok(info.arrows, 'gallery arrows present');
    ok(info.readMore, 'description has "Read more": ' + (info.desc || '').replace(/\s+/g, ' '));

    // gallery: hover, click the arrow, keyboard
    const tile = await page.$(`[data-advert-fetch-wrap][data-listing="${SETUP.id}"]`).then((h) => h.evaluateHandle((e) => e.closest('[data-qa="advert-card"]').querySelector('.mi-advert-photo')));
    await tile.hover();
    await sleep(300);
    await page.screenshot({ path: path.join(SHOTS, '4-gallery-hover.png') });
    await (await tile.evaluateHandle((t) => t.querySelector('[data-gallery-next]'))).click();
    const afterArrow = await tile.evaluate((t) => t.querySelector('[data-qa="photo-count"]').textContent);
    ok(/^2 of \d+$/.test(afterArrow), 'arrow moves the gallery: ' + afterArrow);
    await page.keyboard.press('ArrowRight');
    const afterKey = await tile.evaluate((t) => t.querySelector('[data-qa="photo-count"]').textContent);
    ok(/^3 of \d+$/.test(afterKey), 'keyboard arrow moves it too: ' + afterKey);

    // the full-ad window
    await page.click(`[data-qa="advert-level"][data-listing="${SETUP.id}"] [data-advert-open]`);
    await page.waitForSelector('[data-qa="full-ad"]', { timeout: 30000 });
    await sleep(500);
    await page.screenshot({ path: path.join(SHOTS, '5-full-ad-window.png') });
    const ad = await page.evaluate(() => {
        const w = document.querySelector('[data-qa="full-ad-window"]'); const r = w.getBoundingClientRect();
        const right = w.querySelector('[data-fullad-right]');
        return { w: Math.round(r.width), wording: (w.querySelector('[data-qa="full-ad-wording"]')?.innerText || '').length, features: w.querySelectorAll('[data-qa="full-ad-features"] > div').length,
            gallery: !!w.querySelector('[data-fullad-gallery]'), thumbs: w.querySelectorAll('[data-fullad-thumb]').length, adverts: /Adverts/.test(w.innerText), history: /Price history/.test(w.innerText),
            right: !!right.querySelector('.mi-advert-right'), claim: !!right.querySelector('form[action*="/claim"] button, [data-tour="mic-claim"]'), buttons: [...right.querySelectorAll('[data-qa="line-buttons"] button, [data-qa="line-buttons"] a')].map((b) => b.innerText.trim()).filter(Boolean),
            agent: /Agent/.test(w.innerText), noNumber: /Phone numbers are not read/.test(w.innerText) };
    });
    console.log('  full ad:', JSON.stringify(ad));
    ok(ad.w > 1000, 'the window is full width (' + ad.w + ' px)');
    ok(ad.wording > 200, 'full wording shown (' + ad.wording + ' characters)');
    ok(ad.gallery && ad.thumbs > 1, 'gallery with ' + ad.thumbs + ' thumbnails');
    ok(ad.features > 0, 'features: ' + ad.features);
    ok(ad.adverts && ad.history, 'Listed-by entries (Adverts) and price history from the Full card are underneath');
    ok(ad.right && ad.buttons.length >= 3, 'intelligence panel with its buttons on the right: ' + ad.buttons.join(' | '));
    await page.keyboard.press('Escape');
    await sleep(300);
    ok(!(await page.$('[data-qa="full-ad-window"]')), 'Escape closes the window');

    // the filter and the count
    const f = await openWork(browser, 'full_details_only=1');
    const stats = await f.$eval('[data-qa="stats-line"]', (e) => e.innerText.replace(/\s+/g, ' '));
    ok(/with full details/.test(stats), 'stats line: ' + stats.slice(-80));
    ok(!!(await card(f)), 'the read advert is in the "Full details only" list');
    await f.screenshot({ path: path.join(SHOTS, '6-filter.png') });
    await f.close();
} catch (e) { ok(false, 'A crashed: ' + (e && e.stack || e)); }
await browser.close();
}

// ===== B. no extension =====
if (PART.includes('B')) {
console.log('B. without the extension');
hits.length = 0;
browser = await launch(false);
try {
    const page = await openWork(browser, 'search=' + SETUP.ref);
    const btn = await page.$(`[data-advert-fetch-wrap][data-listing="${SETUP.id}"] [data-advert-fetch]`);
    if (!btn) { ok(true, 'skipped (advert already read; run setup again for this part)'); } else {
        const before = (await browser.pages()).length;
        await btn.click();
        await sleep(2500);
        const note = await page.$eval(`[data-advert-fetch-wrap][data-listing="${SETUP.id}"]`, (e) => e.innerText.replace(/\s+/g, ' '));
        ok(/Install the CoreX Chrome extension for one-click details/.test(note), 'note: "' + note + '"');
        ok((await browser.pages()).length === before + 1, 'the advert opened in a new tab');
        await page.screenshot({ path: path.join(SHOTS, '7-no-extension.png') });
    }
} catch (e) { ok(false, 'B crashed: ' + (e && e.stack || e)); }
await browser.close();
}
portal.close();
console.log(fails ? `\n${fails} FAILED` : '\nall passed');
process.exit(fails ? 1 : 0);
