#!/usr/bin/env node
/**
 * .ai/specs/rentals-faults-work-orders.md §2/§8.1 — real-browser proof for
 * the Rental Fault Types catalogue screen (Slice 1). Same discipline as
 * scripts/rental-click-through.mjs: real HTTP through nginx+PHP-FPM, a
 * minted session cookie (no headless-login flow to fake), Puppeteer only
 * to click and read the DOM a real user would see.
 *
 * Checks:
 *   1. The catalogue list loads and shows the nine seeded defaults.
 *   2. An agent can add a custom fault type and see it in the list.
 *   3. Archiving it removes it from the active list; the archived filter
 *      finds it again — never hard-deleted (non-negotiable #1).
 *
 * Usage:
 *   node scripts/rental-fault-types-click-through.mjs
 *     [--app-root=/corex-qa1] [--base-url=https://qatesting1.corexos.co.za]
 *     [--php-bin=php8.2] [--user-id=22]
 */
import puppeteer from 'puppeteer';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

function parseArgs() {
  const out = {};
  for (const arg of process.argv.slice(2)) {
    const m = arg.match(/^--([^=]+)=(.*)$/);
    if (m) out[m[1]] = m[2];
  }
  return out;
}
const args = parseArgs();
const APP_ROOT = args['app-root'] || '/corex-qa1';
const BASE_URL = args['base-url'] || 'https://qatesting1.corexos.co.za';
const PHP_BIN = args['php-bin'] || 'php8.2';
const USER_ID = args['user-id'] || '22';

function runPhp(argv) {
  return execFileSync(PHP_BIN, argv, { encoding: 'utf8' });
}
function mintCookie(userId) {
  const out = runPhp([path.join(__dirname, 'mint-session-cookie.php'), `--app-root=${APP_ROOT}`, `--user-id=${userId}`]);
  const name = out.match(/COOKIE_NAME=(.+)/)?.[1]?.trim();
  const value = out.match(/COOKIE_VALUE=(.+)/)?.[1]?.trim();
  if (!name || !value) throw new Error('Could not mint session cookie: ' + out);
  return { name, value };
}

const results = [];
function record(name, pass, detail) {
  results.push({ name, pass, detail: detail || null });
  console.log(`[${pass ? 'PASS' : 'FAIL'}] ${name}${detail ? ' — ' + detail : ''}`);
}

const browser = await puppeteer.launch({
  executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium',
  headless: true,
  args: ['--no-sandbox', '--disable-setuid-sandbox'],
});

const uniqueName = `Click-through test fault ${Date.now()}`;

try {
  const cookie = mintCookie(USER_ID);
  const page = await browser.newPage();
  const domain = new URL(BASE_URL).hostname;
  await page.setCookie({ name: cookie.name, value: cookie.value, domain, path: '/', httpOnly: true, secure: true });
  await page.setViewport({ width: 1600, height: 1100 });
  // The archive check re-visits the exact same index URL twice in a row via
  // a back()-redirect; without this Chromium can serve the pre-archive
  // response from its HTTP cache instead of re-fetching.
  await page.setCacheEnabled(false);

  // 1. List loads with the seeded defaults.
  await page.goto(`${BASE_URL}/corex/rental-fault-types`, { waitUntil: 'networkidle0', timeout: 25000 });
  const bodyText1 = await page.evaluate(() => document.body.innerText);
  record('1. Catalogue list loads with seeded defaults', bodyText1.includes('Burst pipe') && bodyText1.includes('Power tripping'),
    `page contains 'Burst pipe'=${bodyText1.includes('Burst pipe')}, 'Power tripping'=${bodyText1.includes('Power tripping')}`);

  // 2. Add a custom fault type.
  await page.goto(`${BASE_URL}/corex/rental-fault-types/create`, { waitUntil: 'networkidle0', timeout: 25000 });
  await page.type('input[name="name"]', uniqueName);
  await page.type('input[name="category"]', 'Test Category');
  await page.select('select[name="urgency"]', 'routine');
  await page.type('textarea[name="first_aid_steps"]', 'Test first aid steps.');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 25000 }),
    page.click('form[action*="rental-fault-types"] button[type="submit"]'),
  ]);
  const bodyText2 = await page.evaluate(() => document.body.innerText);
  record('2. Custom fault type appears in the active list', bodyText2.includes(uniqueName), `list contains new name=${bodyText2.includes(uniqueName)}`);

  // 3. Archive it, confirm it leaves the active list and appears under Archived.
  const archiveForm = await page.$$eval('form', (forms, name) => {
    const row = forms.find((f) => f.closest('tr')?.innerText?.includes(name) && f.action.includes('/archive'));
    return row ? true : false;
  }, uniqueName);
  if (archiveForm) {
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 25000 }),
      page.evaluate((name) => {
        const forms = Array.from(document.querySelectorAll('form'));
        const form = forms.find((f) => f.closest('tr')?.innerText?.includes(name) && f.action.includes('/archive'));
        form.submit();
      }, uniqueName),
    ]);
  }
  // Check the TABLE BODY only, not the whole page — the "archived" flash
  // message itself names the fault type, which would false-positive a
  // whole-page substring check right after the archive action.
  const tableText3 = await page.evaluate(() => document.querySelector('tbody')?.innerText ?? '');
  const goneFromActive = !tableText3.includes(uniqueName);

  await page.goto(`${BASE_URL}/corex/rental-fault-types?status=archived`, { waitUntil: 'networkidle0', timeout: 25000 });
  const tableText4 = await page.evaluate(() => document.querySelector('tbody')?.innerText ?? '');
  const presentInArchived = tableText4.includes(uniqueName);

  record('3. Archived fault type leaves the active list and appears under Archived (never hard-deleted)',
    goneFromActive && presentInArchived, `gone_from_active=${goneFromActive}, present_in_archived=${presentInArchived}`);
} finally {
  await browser.close();
}

const failed = results.filter((r) => !r.pass);
console.log(`\n${results.length - failed.length}/${results.length} passed.`);
process.exit(failed.length ? 1 : 0);
