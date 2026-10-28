// Geometry check for the Road to Sold page (spec 22.20): at each viewport and for each person, every block fits without a page scrollbar,
// the intake table is the first thing under the filters and is on screen, and nothing inside the block overflows.
//   node scripts/rts-fit-check.mjs [--base=https://qatesting1.corexos.co.za] [--out=/tmp/rts-fit] [--agent=24] [--manager=25] [--owner=46] [--viewports=1366x768,1920x1080]
// Needs a minted session cookie (scripts/mint-session-cookie.php), puppeteer-core and /usr/bin/chromium. Reads only: it never presses Save.
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import puppeteer from 'puppeteer-core';

const arg = (k, d) => (process.argv.find((a) => a.startsWith(`--${k}=`)) || `--${k}=${d}`).split('=').slice(1).join('=');
const BASE = arg('base', 'https://qatesting1.corexos.co.za');
const OUT = arg('out', '/tmp/rts-fit');
const APP = arg('app-root', '/corex-qa1');
const AGENT = arg('agent', '24'), MANAGER = arg('manager', '25'), OWNER = arg('owner', '46');
fs.mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const people = [
    { who: 'agent', user: AGENT, query: '', switchAgency: false },
    { who: 'manager', user: MANAGER, query: `&agent=${AGENT}`, switchAgency: false },
    { who: 'owner', user: OWNER, query: `&agent=${AGENT}`, switchAgency: true },
];
const viewports = arg('viewports', '1366x768,1920x1080').split(',').map((v) => v.split('x').map(Number));
const blocks = ['intake', 'prospects', 'parked'];
const results = [];
const browser = await puppeteer.launch({ executablePath: '/usr/bin/chromium', headless: true, args: ['--no-sandbox', '--disable-gpu'] });

for (const person of people) {
    const out = execFileSync('php8.2', [`${APP}/scripts/mint-session-cookie.php`, `--app-root=${APP}`, `--user-id=${person.user}`], { encoding: 'utf8' });
    const cookie = { name: out.match(/COOKIE_NAME=(.+)/)[1].trim(), value: out.match(/COOKIE_VALUE=(.+)/)[1].trim() };
    for (const [w, h] of viewports) {
        const page = await browser.newPage();
        await page.setViewport({ width: w, height: h });
        const errors = [];
        page.on('pageerror', (e) => errors.push(e.message));
        page.on('console', (m) => { if (/Alpine Expression Error/.test(m.text())) errors.push(m.text().split('\n')[0]); });
        await page.setCookie({ ...cookie, domain: new URL(BASE).hostname, path: '/', httpOnly: true, secure: true });
        await page.goto(BASE + '/corex/my-portal', { waitUntil: 'networkidle2', timeout: 90000 });
        if (person.switchAgency) {
            await page.evaluate(async () => { const t = document.querySelector('meta[name=csrf-token]')?.content || ''; await fetch('/agency/switch/1', { method: 'POST', headers: { 'X-CSRF-TOKEN': t }, redirect: 'manual' }); });
        }
        for (const block of blocks) {
            await page.goto(`${BASE}/corex/road-to-sold?block=${block}${person.query}`, { waitUntil: 'networkidle2', timeout: 90000 });
            await sleep(900);
            const g = await page.evaluate((block) => {
                const box = (el) => { if (!el) return null; const b = el.getBoundingClientRect(); return { top: Math.round(b.top), bottom: Math.round(b.bottom), h: Math.round(b.height) }; };
                const sc = document.querySelector('#appScroll');
                const body = document.querySelector('.rts-block-body');
                const grow = body && body.querySelector('.rts-grow');
                const table = block === 'intake' ? document.querySelector('[data-qa="intake-table"]') : null;
                const form = body && body.querySelector('form');
                const over = (el) => !el ? null : el.scrollHeight - el.clientHeight;
                const overX = (el) => !el ? null : el.scrollWidth - el.clientWidth;
                const gb = grow ? grow.getBoundingClientRect() : null;
                const actions = block === 'intake' ? [...document.querySelectorAll('[data-qa="intake-table"] tbody td:last-child')] : [];
                const clipped = gb ? actions.filter((td) => td.getBoundingClientRect().right > gb.right + 1).length : 0;
                return {
                    vh: innerHeight, growOverflowX: overX(grow), actionCellsClipped: clipped, actionCells: actions.length, buttons: actions.reduce((n, td) => n + td.querySelectorAll('button, a').length, 0), pageOverflow: over(sc), bodyOverflow: over(body), growOverflow: over(grow), growBox: box(grow), table: box(table),
                    formBottom: form ? box(form).bottom : null, rows: table ? table.querySelectorAll('tbody tr').length : null,
                    cards: document.querySelectorAll('[data-prospect]').length, parkedRows: document.querySelectorAll('[data-parked]').length,
                };
            }, block);
            const label = `${person.who} ${w}x${h} ${block}`;
            const fails = [];
            if ((g.pageOverflow ?? 0) > 1) fails.push(`page scrolls by ${g.pageOverflow}px`);
            if ((g.bodyOverflow ?? 0) > 1) fails.push(`block scrolls by ${g.bodyOverflow}px`);
            if ((g.growOverflow ?? 0) > 1) fails.push(`the list scrolls by ${g.growOverflow}px (${g.rows ?? g.cards ?? g.parkedRows} rows)`);
            if (block === 'intake') {
                if ((g.growOverflowX ?? 0) > 1) fails.push(`the intake table scrolls sideways by ${g.growOverflowX}px`);
                if (g.actionCellsClipped > 0) fails.push(`${g.actionCellsClipped} of ${g.actionCells} rows have their actions off the right edge`);
                if (!g.table || g.table.h < 120) fails.push(`intake table is ${g.table ? g.table.h : 'missing'}px high`);
                else if (g.table.bottom > g.vh + 1 || g.table.top < 0) fails.push(`intake table is off screen (${g.table.top}-${g.table.bottom} of ${g.vh})`);
                if (g.formBottom !== null && g.table && g.table.top - g.formBottom > 60) fails.push('the table is not right under the filters');
            }
            if (block === 'prospects' && g.cards > 0 && (!g.growBox || g.growBox.bottom > g.vh + 1)) fails.push('the prospect board runs off the screen');
            results.push({ label, pass: fails.length === 0, fails, g });
            console.log(`${fails.length ? 'FAIL' : 'PASS'}  ${label}  table=${JSON.stringify(g.table)} rows=${g.rows} cards=${g.cards} parked=${g.parkedRows}${fails.length ? '  - ' + fails.join('; ') : ''}`);
            if (block === 'intake' || fails.length) await page.screenshot({ path: `${OUT}/${person.who}-${w}x${h}-${block}.png` });
        }
        if (errors.length) { results.push({ label: `${person.who} ${w}x${h} script errors`, pass: false, fails: [...new Set(errors)].slice(0, 3) }); console.log('FAIL  script errors: ' + [...new Set(errors)].slice(0, 3).join(' | ')); }
        await page.close();
    }
}
await browser.close();
const bad = results.filter((r) => !r.pass);
console.log(`\n${results.length - bad.length}/${results.length} checks passed; screenshots in ${OUT}`);
process.exit(bad.length ? 1 : 0);
