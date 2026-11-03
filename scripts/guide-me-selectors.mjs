#!/usr/bin/env node
/**
 * Do the elements a tour script points at really exist on the real screen? (AT-526 prep)
 *
 *   node scripts/guide-me-selectors.mjs --app-root=/corex-qa1 --user-id=22 [--base=https://qatesting1.corexos.co.za] [--only=agency-setup-,role-manager] [--shots=DIR]
 *
 * Reads every script in resources/training/scripts, opens its url as that user in a real Chromium, and for every step reports the selector (and wait_for) as
 *   OK        found and visible on load
 *   HIDDEN    in the page but not showing on load (a tab, a box that opens after an earlier click) - fine for a step that follows a click, a problem otherwise
 *   MISSING   not on the page at all - the step would be skipped
 * Read-only: it loads pages and runs querySelector; it clicks nothing and types nothing.
 */
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import puppeteer from '/corex-qa1/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const arg = (k, d) => { const a = process.argv.find((x) => x.startsWith(`--${k}=`)); return a ? a.split('=').slice(1).join('=') : d; };
const APP_ROOT = arg('app-root', '/corex-qa1'), USER_ID = arg('user-id', '22'), BASE = arg('base', 'https://qatesting1.corexos.co.za');
const ONLY = arg('only', '').split(',').filter(Boolean);
const SHOTS = arg('shots', '');

const scripts = JSON.parse(execFileSync('php8.2', ['-r', '$o=[]; foreach (glob($argv[1]."/*.php") as $f) { $d=require $f; if (is_array($d)) $o[]=$d; } echo json_encode($o);', path.join(__dirname, '../resources/training/scripts')], { encoding: 'utf8' }))
    .filter((s) => !ONLY.length || ONLY.some((o) => s.key.startsWith(o)));
const out = execFileSync('php8.2', [path.join(__dirname, 'mint-session-cookie.php'), `--app-root=${APP_ROOT}`, `--user-id=${USER_ID}`], { encoding: 'utf8' });
const cookie = { name: out.match(/COOKIE_NAME=(.+)/)[1].trim(), value: out.match(/COOKIE_VALUE=(.+)/)[1].trim() };
if (SHOTS) fs.mkdirSync(SHOTS, { recursive: true });

const browser = await puppeteer.launch({ executablePath: '/usr/bin/chromium', headless: true, args: ['--no-sandbox', '--disable-gpu'] });
let missing = 0, hidden = 0, ok = 0;
for (const s of scripts) {
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 900 });
    await page.setCookie({ name: cookie.name, value: cookie.value, domain: new URL(BASE).hostname, path: '/', httpOnly: true, secure: true });
    let status = 0;
    try { const r = await page.goto(BASE + s.url, { waitUntil: 'networkidle2', timeout: 90000 }); status = r ? r.status() : 0; } catch (e) { status = -1; }
    const landed = new URL(page.url()).pathname;
    console.log(`\n${s.key}  ${s.url}  HTTP ${status}${landed !== s.url ? '  (landed on ' + landed + ')' : ''}  ${s.steps.length} steps  narrated=${!!s.narrated} approved=${!!s.approved}`);
    if (status !== 200 || landed !== s.url) { console.log('  !! page did not open as that user - selectors not checked'); await page.close(); continue; }
    let n = 0;
    for (const st of s.steps) {
        n++;
        for (const [label, sel] of [['selector', st.selector], ['wait_for', st.wait_for]]) {
            if (!sel) continue;
            const r = await page.evaluate((sel) => {
                let el = null; try { el = document.querySelector(sel); } catch (e) { return 'BAD-SELECTOR'; }
                if (!el) return 'MISSING';
                const b = el.getBoundingClientRect(), cs = getComputedStyle(el);
                return b.width > 1 && b.height > 1 && cs.visibility !== 'hidden' && cs.display !== 'none' ? 'OK' : 'HIDDEN';
            }, sel);
            if (r === 'OK') ok++; else if (r === 'HIDDEN') hidden++; else missing++;
            if (r !== 'OK') console.log(`  ${r.padEnd(8)} step ${n} ${label}: ${sel}`);
        }
    }
    if (SHOTS) await page.screenshot({ path: path.join(SHOTS, `${s.key}.png`) });
    await page.close();
}
await browser.close();
console.log(`\nselectors: ${ok} OK, ${hidden} hidden on load, ${missing} MISSING`);
process.exit(missing ? 1 : 0);
