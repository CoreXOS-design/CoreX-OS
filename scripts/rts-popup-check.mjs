// Pop-up check for Road to Sold (spec 22.9 / 22.23): every step a prospect row can open loads INSIDE the pop-up (an iframe of the real screen,
// embed=1, no sidebar / header chrome), has the "Done - back to Road to Sold" bar, and pressing Done reaches the page. Reads only.
//   node scripts/rts-popup-check.mjs [--base=https://qatesting1.corexos.co.za] [--user=24] [--prospect=11] [--prospect-with-property=20 --property-user=29]
// Needs scripts/mint-session-cookie.php, puppeteer-core and /usr/bin/chromium.
import { execFileSync } from 'node:child_process';
import puppeteer from 'puppeteer-core';

const arg = (k, d) => (process.argv.find((a) => a.startsWith(`--${k}=`)) || `--${k}=${d}`).split('=').slice(1).join('=');
const BASE = arg('base', 'https://qatesting1.corexos.co.za');
const APP = arg('app-root', '/corex-qa1');
const USER = arg('user', '24'), PROSPECT = arg('prospect', '11');
const STEPS = ['find_owner', 'pitch', 'evaluation', 'presentation', 'mandate', 'listing', 'advertise', 'lead', 'core_match', 'viewing', 'viewing_pack', 'offer', 'deal', 'property'];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const out = execFileSync('php8.2', [`${APP}/scripts/mint-session-cookie.php`, `--app-root=${APP}`, `--user-id=${USER}`], { encoding: 'utf8' });
const cookie = { name: out.match(/COOKIE_NAME=(.+)/)[1].trim(), value: out.match(/COOKIE_VALUE=(.+)/)[1].trim() };
const browser = await puppeteer.launch({ executablePath: '/usr/bin/chromium', headless: true, args: ['--no-sandbox', '--disable-gpu'] });
const page = await browser.newPage();
await page.setViewport({ width: 1523, height: 784 });
await page.setCookie({ ...cookie, domain: new URL(BASE).hostname, path: '/', httpOnly: true, secure: true });
await page.goto(`${BASE}/corex/road-to-sold?block=prospects`, { waitUntil: 'networkidle2', timeout: 90000 });
await page.evaluate(() => { window.__rtsDone = 0; window.addEventListener('message', (e) => { if (e.data && e.data.rts === 'done') window.__rtsDone++; }); });

let bad = 0;
for (const step of STEPS) {
    // the pop-up does exactly this: an iframe whose address is the step route
    const r = await page.evaluate(async (url) => {
        const f = document.createElement('iframe');
        f.style.cssText = 'position:fixed;left:0;top:0;width:1100px;height:600px;z-index:99999;background:#fff';
        document.body.appendChild(f);
        await new Promise((res) => { f.onload = res; f.src = url; setTimeout(res, 25000); });
        await new Promise((res) => setTimeout(res, 700));
        const d = f.contentDocument, w = f.contentWindow;
        const vis = (el) => !!el && el.getBoundingClientRect().width > 0 && el.getBoundingClientRect().height > 0;
        const aside = d.querySelector('aside.inset-y-0'), bar = d.querySelector('#corex-embed-bar'), btn = d.querySelector('[data-embed-done]');
        const res = {
            url: w.location.pathname + w.location.search.slice(0, 80), status: d.title, embed: /[?&]embed=1/.test(w.location.search),
            aside: vis(aside), sidebar: vis(d.querySelector('#corex-sidebar, nav[aria-label="Sidebar"]')), bar: vis(bar), body: d.body ? d.body.innerText.slice(0, 80).replace(/\s+/g, ' ') : '',
            error: /Whoops|Server Error|404|403|Not Found|Forbidden/i.test(d.title + ' ' + (d.body ? d.body.innerText.slice(0, 200) : '')),
        };
        if (btn) btn.click();
        await new Promise((x) => setTimeout(x, 300));
        f.remove();
        return res;
    }, `${BASE}/corex/road-to-sold/prospects/${PROSPECT}/step/${step}`);
    const fails = [];
    if (r.aside || r.sidebar) fails.push('the sidebar shows');
    if (!r.bar) fails.push('no Done bar');
    if (!r.embed) fails.push('address lost embed=1');
    if (r.error) fails.push('error page: ' + r.body);
    if (fails.length) bad++;
    console.log(`${fails.length ? 'FAIL' : 'PASS'}  ${step.padEnd(13)} -> ${r.url}${fails.length ? '  - ' + fails.join('; ') : ''}`);
}
const done = await page.evaluate(() => window.__rtsDone);
console.log(`\nDone presses that reached Road to Sold: ${done} of ${STEPS.length - bad}`);
await browser.close();
process.exit(bad || done < STEPS.length - bad ? 1 : 0);
