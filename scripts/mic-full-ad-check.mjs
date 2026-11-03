#!/usr/bin/env node
/**
 * Look at the "Show full ad" window on a real, already-read QA1 advert as a signed-in user (spec 13.16b item 5). Read-only: it loads pages and clicks open / close.
 *
 *   node scripts/mic-full-ad-check.mjs --search=Emtunzi --user-id=22 [--base=https://qatesting1.corexos.co.za] [--shots=DIR]
 */
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import puppeteer from '/corex-qa1/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const arg = (k, d) => { const a = process.argv.find((x) => x.startsWith(`--${k}=`)); return a ? a.split('=').slice(1).join('=') : d; };
const BASE = arg('base', 'https://qatesting1.corexos.co.za'), USER_ID = arg('user-id', '22'), SEARCH = arg('search', 'Emtunzi'), SHOTS = arg('shots', '/tmp/mic-cc2/e2e/shots2');
fs.mkdirSync(SHOTS, { recursive: true });
let fails = 0;
const ok = (c, m) => { console.log((c ? '  ok   ' : '  FAIL ') + m); if (!c) fails++; };
const out = execFileSync('php8.2', [path.join(__dirname, 'mint-session-cookie.php'), '--app-root=/corex-qa1', `--user-id=${USER_ID}`], { encoding: 'utf8' });
const cookie = { name: out.match(/COOKIE_NAME=(.+)/)[1].trim(), value: out.match(/COOKIE_VALUE=(.+)/)[1].trim() };

const browser = await puppeteer.launch({ executablePath: '/usr/bin/chromium', headless: 'new', args: ['--no-sandbox', '--disable-gpu'] });
const page = await browser.newPage();
await page.setViewport({ width: 1500, height: 950 });
await page.setCookie({ name: cookie.name, value: cookie.value, domain: new URL(BASE).hostname, path: '/', httpOnly: true, secure: true });
await page.setCacheEnabled(false);   // a hard reload
await page.goto(`${BASE}/corex/market-intelligence?view=adverts&clear=1&search=${encodeURIComponent(SEARCH)}`, { waitUntil: 'networkidle2', timeout: 120000 });

const card = await page.evaluate(() => {
    const c = [...document.querySelectorAll('[data-qa="advert-card"]')].find((x) => x.querySelector('[data-qa="advert-level"][data-level="full"], [data-qa="advert-level"][data-level="changed"]'));
    if (!c) return null;
    const lines = [...c.querySelectorAll('[data-qa="advert-source"], [data-qa="advert-dates"], [data-qa="advert-description"]')].map((e) => ({ qa: e.getAttribute('data-qa'), text: e.innerText.replace(/\s+/g, ' ').trim(), h: Math.round(e.getBoundingClientRect().height), visible: e.getBoundingClientRect().bottom <= c.querySelector('[data-qa="advert-left"]').getBoundingClientRect().bottom + 1 }));
    return { lines, stats: document.querySelector('[data-qa="stats-line"]')?.innerText.replace(/\s+/g, ' ') };
});
ok(!!card, 'a read advert is on the page for "' + SEARCH + '"');
if (card) {
    console.log('  card lines:', JSON.stringify(card.lines));
    const dates = card.lines.find((l) => l.qa === 'advert-dates');
    ok(dates && dates.visible && /First seen/.test(dates.text), 'the "First seen ... days" line is there and not cut: "' + (dates && dates.text) + '"');
    ok(/with full details/.test(card.stats || '') && !/ 0 with full details/.test(card.stats), 'stats line: ' + (card.stats || '').slice(-60));
}
const cardEl = await page.$('[data-qa="advert-card"]:has([data-qa="advert-level"][data-level="full"]), [data-qa="advert-card"]:has([data-qa="advert-level"][data-level="changed"])');
if (cardEl) await cardEl.screenshot({ path: path.join(SHOTS, 'a-card.png') });

await page.click('[data-qa="advert-level"] [data-advert-open]');
await page.waitForSelector('[data-qa="full-ad"]', { timeout: 30000 });
await new Promise((r) => setTimeout(r, 1500));
await page.screenshot({ path: path.join(SHOTS, 'b-window-top.png') });

const w = await page.evaluate(() => {
    const win = document.querySelector('[data-qa="full-ad-window"]');
    const right = win.querySelector('[data-fullad-right]'), body = win.querySelector('[data-fullad-body]');
    const btns = [...win.querySelectorAll('.mi-fullad-right [data-qa="line-buttons"] > *')].map((b) => { const r = b.getBoundingClientRect(); const t = b.querySelector('button,a') || b; return { t: t.innerText.trim(), top: Math.round(r.top), w: Math.round(r.width), cut: t.scrollWidth > t.clientWidth + 1 }; });
    const strip = win.querySelector('[data-fullad-strip]');
    const headings = [...right.querySelectorAll('[data-qa^="ours-"] > div > div:first-child, [data-qa="ours-history"] > div:first-child')].map((e) => e.innerText.trim()).filter(Boolean);
    return {
        title: win.querySelector('[data-fullad-title]').innerText, width: Math.round(win.firstElementChild.getBoundingClientRect().width),
        facts: [...win.querySelectorAll('[data-qa="fact-tile"]')].map((t) => t.innerText.replace(/\s+/g, ' ').trim()),
        count: win.querySelector('[data-fullad-count]')?.innerText, thumbs: win.querySelectorAll('[data-fullad-thumb]').length,
        stripScrollbar: strip ? (strip.offsetHeight - strip.clientHeight) : null, stripArrows: !!win.querySelector('[data-fullad-strip-next]'),
        paras: win.querySelectorAll('[data-qa="full-ad-wording"] p').length,
        groups: [...win.querySelectorAll('[data-qa="feature-group"]')].map((g) => g.dataset.group + ':' + g.querySelectorAll('[data-qa="feature-row"]').length),
        roomDetails: !!win.querySelector('[data-qa="room-details"]'), roomDetailsOpen: win.querySelector('[data-qa="room-details"]')?.open,
        listedBy: win.querySelector('[data-qa="full-ad-listed-by"]').innerText.replace(/\s+/g, ' '),
        leftHasOurs: /Price history|Matched buyers|Deeds|Portal alert/.test(body.innerText),
        buttons: btns, rightScrolls: right.scrollHeight > right.clientHeight, rightOverflowY: getComputedStyle(right).overflowY, bodyOverflowY: getComputedStyle(body).overflowY,
        rightTop: right.innerText.replace(/\s+/g, ' ').slice(0, 260), headings, openProperty: /Open the property page/.test(right.innerText),
        phoneSentence: /Phone numbers/.test(win.innerText), innerScrollBoxes: [...right.querySelectorAll('[style*="max-height"]')].filter((e) => e.getBoundingClientRect().height > 0).length,
    };
});
console.log('  window:', JSON.stringify(w, null, 1));
ok(/Emtunzi/i.test(w.title) && w.title !== 'The full ad', 'title is the address: "' + w.title + '"');
ok(w.facts.length >= 1 && w.facts.length <= 5, 'key facts row: ' + w.facts.join(' | '));
ok(/^1 of \d+$/.test(w.count || ''), 'gallery counter ' + w.count + ', ' + w.thumbs + ' thumbnails');
ok(w.stripScrollbar === 0, 'no visible scrollbar under the thumbnails (' + w.stripScrollbar + ' px)');
ok(w.stripArrows, 'arrows at the ends of the thumbnail strip');
ok(w.paras >= 2, 'description in ' + w.paras + ' paragraphs');
ok(['Rooms', 'Outside', 'Building', 'Extras'].every((g) => w.groups.some((x) => x.startsWith(g + ':'))), 'feature groups: ' + w.groups.join(', '));
ok(w.roomDetails && !w.roomDetailsOpen, 'Room details collapsed');
ok(/Listed by/.test(w.listedBy) && /Open on P24/.test(w.listedBy) && /Read \d+ \w+ by/.test(w.listedBy), 'Listed by: ' + w.listedBy);
ok(!w.leftHasOurs, 'nothing of ours on the left');
ok(!w.phoneSentence, 'no phone-number sentence');
ok(w.buttons.length >= 5 && new Set(w.buttons.map((b) => b.top)).size === 1 && w.buttons.every((b) => !b.cut), 'five buttons on one row, none cut: ' + w.buttons.map((b) => `${b.t}(${b.w}px)`).join(' | '));
ok(w.openProperty, '"Open the property page" at the end of the right column');
ok(w.rightOverflowY === 'auto' && w.bodyOverflowY === 'auto', 'the two columns scroll on their own');
ok(w.innerScrollBoxes === 0, 'no scroll boxes inside the right column');

await page.evaluate(() => { const r = document.querySelector('[data-fullad-right]'); r.scrollTop = r.scrollHeight; });
await new Promise((r) => setTimeout(r, 300));
await page.screenshot({ path: path.join(SHOTS, 'c-window-right-scrolled.png') });
await page.evaluate(() => { const b = document.querySelector('[data-fullad-body]'); b.scrollTop = 900; });
await new Promise((r) => setTimeout(r, 300));
await page.screenshot({ path: path.join(SHOTS, 'd-window-left-scrolled.png') });
await page.evaluate(() => { const b = document.querySelector('[data-fullad-body]'); b.scrollTop = b.scrollHeight; });
await new Promise((r) => setTimeout(r, 300));
await page.screenshot({ path: path.join(SHOTS, 'e-window-left-bottom.png') });
await page.keyboard.press('Escape');
await new Promise((r) => setTimeout(r, 300));
ok(!(await page.$('[data-qa="full-ad-window"]')), 'Esc closes it');
await browser.close();
console.log(fails ? `\n${fails} FAILED` : '\nall passed');
process.exit(fails ? 1 : 0);
