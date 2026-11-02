#!/usr/bin/env node
/**
 * Real-browser check of the pitch popup (road-to-sold.md 23.23) as a signed-in TEST user. It opens screens and clicks; with --deep it also moves to the
 * Owner step (which writes the pitch lock the old Pitch now button always wrote). Nothing is ever submitted.
 *
 *   node scripts/pitch-popup-check.mjs --user-id=127 --search=Bidstone [--deep] [--base=https://qatesting1.corexos.co.za] [--shots=DIR]
 */
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import puppeteer from '/corex-qa1/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const arg = (k, d) => { const a = process.argv.find((x) => x.startsWith(`--${k}=`)); return a ? a.split('=').slice(1).join('=') : d; };
const BASE = arg('base', 'https://qatesting1.corexos.co.za'), USER_ID = arg('user-id', '127'), SEARCH = arg('search', 'Bidstone'), SHOTS = arg('shots', '/tmp/mic-cc2/pitch-shots');
const DEEP = process.argv.includes('--deep');
fs.mkdirSync(SHOTS, { recursive: true });
let fails = 0;
const ok = (c, m) => { console.log((c ? '  ok   ' : '  FAIL ') + m); if (!c) fails++; };
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const out = execFileSync('php8.2', [path.join(__dirname, 'mint-session-cookie.php'), '--app-root=/corex-qa1', `--user-id=${USER_ID}`], { encoding: 'utf8' });
const cookie = { name: out.match(/COOKIE_NAME=(.+)/)[1].trim(), value: out.match(/COOKIE_VALUE=(.+)/)[1].trim() };

const browser = await puppeteer.launch({ executablePath: '/usr/bin/chromium', headless: 'new', args: ['--no-sandbox', '--disable-gpu'] });
const page = await browser.newPage();
await page.setViewport({ width: 1500, height: 950 });
await page.setCookie({ name: cookie.name, value: cookie.value, domain: new URL(BASE).hostname, path: '/', httpOnly: true, secure: true });
await page.setCacheEnabled(false);
page.on('pageerror', (e) => console.log('  pageerror:', e.message));
await page.goto(`${BASE}/corex/market-intelligence?view=adverts&clear=1&search=${encodeURIComponent(SEARCH)}`, { waitUntil: 'networkidle2', timeout: 120000 });
await page.screenshot({ path: path.join(SHOTS, 'a-list.png') });

const link = await page.evaluate(() => { const a = document.querySelector('a[data-pitch-popup]'); return a ? { href: a.href, listing: a.dataset.listing, label: a.textContent.trim() } : null; });
console.log('  chip:', JSON.stringify(link));
ok(!!link, 'a Pitch link on the card opens the popup');
if (link) {
    await page.$eval('a[data-pitch-popup]', (a) => a.scrollIntoView({ block: 'center' }));
    await wait(400);
    await (await page.$('a[data-pitch-popup]')).click();
    await page.waitForSelector('[data-qa="pitch-popup"]', { timeout: 15000 });
    const frameEl = await page.waitForSelector('[data-qa="pitch-frame"]');
    let frame = await frameEl.contentFrame();
    await frame.waitForSelector('[data-qa="pitch-card"]', { timeout: 60000 });
    await wait(1500);
    await page.screenshot({ path: path.join(SHOTS, 'b-popup-property.png') });
    const s1 = await page.evaluate(() => ({
        steps: [...document.querySelectorAll('[data-qa="pitch-steps"] .cxp-step')].map((b) => b.dataset.step + ':' + b.dataset.state),
        next: (document.querySelector('[data-qa="pitch-next"]') || {}).innerText, nextShown: document.querySelector('[data-qa="pitch-next"]').style.display !== 'none',
    }));
    console.log('  property step:', JSON.stringify(s1));
    ok(s1.steps.join() === 'property:current,owner:todo,pitch:todo,contact:todo', 'step bar: Property is current');
    ok(s1.nextShown && /find the owner/i.test(s1.next), 'Next: ' + s1.next);
    const cards = await frame.evaluate(() => ({ n: document.querySelectorAll('[data-qa="advert-card"]').length, chrome: !!document.querySelector('#corex-sidebar, aside.corex-sidebar, nav[data-qa="sidebar"]') }));
    ok(cards.n === 1, 'the popup shows ONE card (' + cards.n + ')');

    if (DEEP) {
        await page.click('[data-qa="pitch-next"]');
        await wait(500);
        await frame.waitForFunction(() => /outreach\/compose/.test(location.pathname), { timeout: 60000 }).catch(() => {});
        frame = await (await page.$('[data-qa="pitch-frame"]')).contentFrame();
        await wait(2500);
        await page.screenshot({ path: path.join(SHOTS, 'c-popup-owner.png') });
        const s2 = await page.evaluate(() => [...document.querySelectorAll('[data-qa="pitch-steps"] .cxp-step')].map((b) => b.dataset.step + ':' + b.dataset.state).join());
        console.log('  owner step:', s2);
        ok(s2 === 'property:done,owner:current,pitch:todo,contact:todo', 'step bar follows the screen: Owner is current, Property done');
        const hdr = await frame.$('[data-qa="listing-header"][data-pitch-peek]');
        ok(!!hdr, 'the Listing-from header is clickable');
        if (hdr) {
            await hdr.click();
            await frame.waitForSelector('[data-qa="peek-window"]', { timeout: 10000 });
            const peekFrame = await (await frame.$('[data-qa="peek-frame"]')).contentFrame();
            await peekFrame.waitForSelector('[data-qa="advert-card"]', { timeout: 60000 });
            await wait(1200);
            await page.screenshot({ path: path.join(SHOTS, 'd-header-card.png') });
            ok(true, 'the header shows the MIC card over the screen');
            await frame.click('[data-qa="peek-close"]');
            await wait(300);
            ok(!(await frame.$('[data-qa="peek-window"]')), 'Close returns to the compose screen');
            ok(!!(await page.$('[data-qa="pitch-popup"]')), 'the popup is still open behind it');
        }
    }
    await page.click('[data-qa="pitch-close"]');
    await wait(1500);
    ok(!(await page.$('[data-qa="pitch-popup"]')), 'Close shuts the popup');
    ok(/market-intelligence/.test(page.url()) && (await page.$('[data-qa="advert-card"]')) !== null, 'the agent is back on the list they came from');
    await page.screenshot({ path: path.join(SHOTS, 'e-back-on-list.png') });
}
await browser.close();
console.log(fails ? `\n${fails} FAILED` : '\nall passed');
process.exit(fails ? 1 : 0);
