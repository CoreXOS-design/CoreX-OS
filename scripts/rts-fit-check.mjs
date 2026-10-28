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

// The production line (spec 22.24): 18 stations on screen with no sideways scroll, the line pinned while the chips scroll, chips inside their
// columns, the flow figures not clipped, a station click that filters, a chip that opens the card with the whole line, Escape that closes it.
async function lineChecks(page, vh) {
    const fails = [];
    const g = await page.evaluate(() => {
        const grow = document.querySelector('[data-qa="prospect-board"]');
        const line = document.querySelector('[data-qa="production-line"]');
        if (!grow || !line) return { missing: true };
        const gb = grow.getBoundingClientRect();
        const stations = [...line.querySelectorAll('[data-station]')];
        const stationsOut = stations.filter((el) => { const b = el.getBoundingClientRect(); return b.left < gb.left - 1 || b.right > gb.right + 1; }).length;
        const chips = [...document.querySelectorAll('[data-qa="chips"] .rts-ch')];
        const chipBox = document.querySelector('[data-qa="chips"]')?.getBoundingClientRect();
        const chipsOut = chipBox ? chips.filter((el) => { const b = el.getBoundingClientRect(); return b.right > chipBox.right + 1 || b.left < chipBox.left - 1; }).length : 0;
        // the all-stations view is readable cards (not 18 narrow columns): each wide enough to read, several to a row, a slim heading only for stations that have properties
        const narrow = chips.filter((el) => el.getBoundingClientRect().width < 200).length;
        const firstTop = chips.length ? Math.round(chips[0].getBoundingClientRect().top) : 0;
        const perRow = chips.filter((el) => Math.abs(Math.round(el.getBoundingClientRect().top) - firstTop) <= 2).length;
        const cut = chips.filter((el) => { const a = el.querySelector('.a'); return a && a.scrollWidth - a.clientWidth > 1 && chips.length > 0 && a.clientWidth < 120; }).length;
        const headings = [...document.querySelectorAll('[data-qa="station-heading"]')].map((h) => h.dataset.chipsFor);
        const headingsWithout = headings.filter((k) => !document.querySelector(`[data-qa="chips"] .rts-ch[data-stage="${k}"]`)).length;
        const gridCols = chipBox ? getComputedStyle(document.querySelector('[data-qa="chips"]')).gridTemplateColumns.split(' ').length : 0;
        const phases = [...line.querySelectorAll('.rts-phase')];
        const phasesClipped = phases.filter((el) => el.scrollWidth - el.clientWidth > 1).length;
        const names = stations.filter((el) => { const n = el.querySelector('.nm'); return n && n.scrollWidth - n.clientWidth > 1 && getComputedStyle(n).whiteSpace === 'nowrap'; }).length;
        grow.scrollTop = grow.scrollHeight;
        const pinned = Math.abs(line.getBoundingClientRect().top - gb.top) <= 1;
        const scrolls = grow.scrollHeight - grow.clientHeight;
        grow.scrollTop = 0;
        return { stationView: !!document.querySelector('a[data-qa=all-stations]'), narrow, perRow, cut, headings: headings.length, headingsWithout, gridCols, vw: window.innerWidth, stations: stations.length, stationsOut, sideways: grow.scrollWidth - grow.clientWidth, chipsOut, chips: chips.length, phases: phases.length, phasesClipped, shortNamesCut: names, pinned, scrolls, flows: line.querySelectorAll('[data-flow]').length };
    });
    if (g.missing) return ['the production line is missing'];
    if (g.stations !== 18) fails.push(`${g.stations} stations drawn, expected 18`);
    if (g.phases !== 5) fails.push(`${g.phases} phases drawn, expected 5`);
    if (g.sideways > 1) fails.push(`the line scrolls sideways by ${g.sideways}px`);
    if (g.stationsOut > 0) fails.push(`${g.stationsOut} stations stick out of the line`);
    if (g.chipsOut > 0) fails.push(`${g.chipsOut} chips stick out of their column`);
    if (g.phasesClipped > 0) fails.push(`${g.phasesClipped} phase headers clip their flow figures`);
    if (g.shortNamesCut > 0) fails.push(`${g.shortNamesCut} station names are cut off`);
    if (g.narrow > 0) fails.push(`${g.narrow} property cards are narrower than 200px`);
    if (g.cut > 0) fails.push(`${g.cut} property cards cut the address to nothing`);
    if (g.gridCols > 8) fails.push(`the properties are laid out in ${g.gridCols} columns`);
    if (g.chips >= 4 && g.vw <= 1400 && g.perRow < 4) fails.push(`only ${g.perRow} cards in a row at ${g.vw}px wide, expected 4`);
    if (g.headingsWithout > 0) fails.push(`${g.headingsWithout} station headings have no properties under them`);
    if (g.chips > 0 && !g.stationView && g.headings === 0) fails.push('the all-stations view has no station headings');
    if (g.scrolls > 1 && !g.pinned) fails.push('the line does not stay put while the chips scroll');
    if (g.chips > 0) {
        await page.click('[data-qa="chips"] .rts-ch');
        await sleep(300);
        const card = await page.evaluate((vh) => {
            const c = document.querySelector('[data-qa="prospect-card"] > div');
            if (!c) return null;
            const b = c.getBoundingClientRect();
            return { minis: document.querySelectorAll('[data-qa="card-line"] [data-mini]').length, lit: document.querySelectorAll('[data-qa="card-line"] .is-here').length, here: (document.querySelector('[data-qa="card-here"]')?.textContent || '').replace(/\s+/g, ' ').trim(), top: Math.round(b.top), bottom: Math.round(b.bottom), inside: b.top >= 0 && b.bottom <= vh + 1, sideways: c.scrollWidth - c.clientWidth };
        }, vh);
        if (!card) fails.push('clicking a chip did not open the card');
        else {
            if (card.minis !== 18) fails.push(`the card draws ${card.minis} stations, expected 18`);
            if (card.lit !== 1) fails.push(`the card lights ${card.lit} stations, expected 1`);
            if (!/Here:.*next:/.test(card.here)) fails.push(`the card line reads "${card.here}"`);
            if (!card.inside) fails.push(`the card is off screen (${card.top}-${card.bottom} of ${vh})`);
            if (card.sideways > 1) fails.push('the card scrolls sideways');
        }
        await page.keyboard.press('Escape');
        await sleep(200);
        if (await page.$('[data-qa="prospect-card"]')) fails.push('Escape did not close the card');
    }
    const station = await page.$('[data-station][data-count]:not([data-count="0"])');
    if (station) {
        const key = await station.evaluate((el) => el.dataset.station);
        await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), station.click()]);
        await sleep(300);
        const one = await page.evaluate((key) => ({ lit: document.querySelectorAll('.rts-st.is-on').length, onlyThis: [...document.querySelectorAll('[data-prospect]')].every((el) => el.dataset.stage === key), wide: !!document.querySelector('.rts-wide'), back: !!document.querySelector('[data-qa="all-stations"]'), pageOverflow: (() => { const sc = document.querySelector('#appScroll'); return sc.scrollHeight - sc.clientHeight; })() }), key);
        if (one.lit !== 1 || !one.onlyThis || !one.wide || !one.back) fails.push(`a station click did not filter to ${key} (${JSON.stringify(one)})`);
        if (one.pageOverflow > 1) fails.push(`the page scrolls by ${one.pageOverflow}px after a station click`);
        await page.goBack({ waitUntil: 'networkidle2' });
    }
    return fails;
}

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
            if (block !== 'prospects' && (g.growOverflow ?? 0) > 1) fails.push(`the list scrolls by ${g.growOverflow}px (${g.rows ?? g.cards ?? g.parkedRows} rows)`);
            if (block === 'intake') {
                if ((g.growOverflowX ?? 0) > 1) fails.push(`the intake table scrolls sideways by ${g.growOverflowX}px`);
                if (g.actionCellsClipped > 0) fails.push(`${g.actionCellsClipped} of ${g.actionCells} rows have their actions off the right edge`);
                if (!g.table || g.table.h < 120) fails.push(`intake table is ${g.table ? g.table.h : 'missing'}px high`);
                else if (g.table.bottom > g.vh + 1 || g.table.top < 0) fails.push(`intake table is off screen (${g.table.top}-${g.table.bottom} of ${g.vh})`);
                if (g.formBottom !== null && g.table && g.table.top - g.formBottom > 60) fails.push('the table is not right under the filters');
            }
            if (block === 'prospects' && (!g.growBox || g.growBox.bottom > g.vh + 1)) fails.push('the production line runs off the screen');
            if (block === 'prospects') { await page.screenshot({ path: `${OUT}/${person.who}-${w}x${h}-prospects.png` }); fails.push(...await lineChecks(page, g.vh)); }
            results.push({ label, pass: fails.length === 0, fails, g });
            console.log(`${fails.length ? 'FAIL' : 'PASS'}  ${label}  table=${JSON.stringify(g.table)} rows=${g.rows} cards=${g.cards} parked=${g.parkedRows}${fails.length ? '  - ' + fails.join('; ') : ''}`);
            if (block === 'intake' || (fails.length && block !== 'prospects')) await page.screenshot({ path: `${OUT}/${person.who}-${w}x${h}-${block}.png` });
        }
        if (errors.length) { results.push({ label: `${person.who} ${w}x${h} script errors`, pass: false, fails: [...new Set(errors)].slice(0, 3) }); console.log('FAIL  script errors: ' + [...new Set(errors)].slice(0, 3).join(' | ')); }
        await page.close();
    }
}
await browser.close();
const bad = results.filter((r) => !r.pass);
console.log(`\n${results.length - bad.length}/${results.length} checks passed; screenshots in ${OUT}`);
process.exit(bad.length ? 1 : 0);
