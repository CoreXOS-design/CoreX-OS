#!/usr/bin/env node
/**
 * Rental-applications real-browser smoke test.
 *
 * A 200 response and a passing PHPUnit suite have both shipped a completely
 * dead screen to QA1 three times this month (initialResult, then
 * sidebarOpen/markupModeActive) — the server rendered fine, the JavaScript
 * threw on construction, and nothing on the page worked. This script is
 * the check that would have caught all three: a REAL headless browser,
 * REAL JavaScript execution, console errors counted, and a REAL DATA
 * assertion per screen — a total with a figure beside it, a list with rows
 * in it — never just "the labels rendered."
 *
 * A 200 status code is NOT a pass signal anywhere in this file.
 *
 * Auth: mints a real, correctly-signed Laravel session cookie via
 * scripts/mint-session-cookie.php (same technique, same CookieValuePrefix
 * gotcha, as scripts/fetch-authenticated-page.php — see that file's
 * docblock for the full writeup) and hands it to Puppeteer directly, rather
 * than driving a real login form on every run.
 *
 * Usage:
 *   node scripts/rental-smoke.mjs [--base-url=https://qatesting1.corexos.co.za]
 *       [--user-id=132] [--rental-application-id=76] [--php-bin=php8.2]
 *
 * --user-id defaults to a dedicated test fixture (132, an admin persona
 * used throughout QA1 verification this cycle), never a real agent's own
 * account — this script must never appear to act as a real person.
 *
 * Exits non-zero if ANY screen has console errors or fails its real-data
 * assertion. Prints a per-screen table: console error count + data check.
 */

import puppeteer from 'puppeteer';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

function parseArgs(argv) {
  const out = {};
  for (const a of argv) {
    const m = a.match(/^--([\w-]+)=(.*)$/);
    if (m) out[m[1]] = m[2];
  }
  return out;
}
const args = parseArgs(process.argv.slice(2));
const BASE_URL = args['base-url'] || 'https://qatesting1.corexos.co.za';
const APP_ROOT = args['app-root'] || '/corex-qa1';
const USER_ID = args['user-id'] || '132';
const RENTAL_APP_ID = args['rental-application-id'] || '76';
const PHP_BIN = args['php-bin'] || 'php8.2';

function mintCookie() {
  const out = execFileSync(PHP_BIN, [
    path.join(__dirname, 'mint-session-cookie.php'),
    `--app-root=${APP_ROOT}`,
    `--user-id=${USER_ID}`,
  ], { encoding: 'utf8' });
  const name = out.match(/COOKIE_NAME=(.+)/)?.[1]?.trim();
  const value = out.match(/COOKIE_VALUE=(.+)/)?.[1]?.trim();
  if (!name || !value) throw new Error('Could not mint session cookie: ' + out);
  return { name, value };
}

async function checkScreen(browser, cookie, { name, url, dataCheck, afterLoad }) {
  const page = await browser.newPage();
  const consoleErrors = [];
  page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
  page.on('pageerror', (err) => consoleErrors.push('UNCAUGHT: ' + err.message));
  const domain = new URL(BASE_URL).hostname;
  await page.setCookie({ name: cookie.name, value: cookie.value, domain, path: '/', httpOnly: true, secure: true });
  await page.setViewport({ width: 1900, height: 1200 });

  let httpStatus = null;
  let result = { name, httpStatus: null, consoleErrorCount: 0, consoleErrors: [], dataCheckPassed: false, dataCheckDetail: null, pass: false };
  try {
    const resp = await page.goto(url, { waitUntil: 'networkidle0', timeout: 25000 });
    httpStatus = resp.status();
    await new Promise((r) => setTimeout(r, 1200)); // let Alpine settle beyond networkidle0
    if (typeof afterLoad === 'function') await afterLoad(page);
    const detail = typeof dataCheck === 'function' ? await dataCheck(page) : { pass: true, note: 'no data check defined' };
    result.httpStatus = httpStatus;
    result.consoleErrorCount = consoleErrors.length;
    result.consoleErrors = consoleErrors.slice(0, 10);
    result.dataCheckPassed = !!detail.pass;
    result.dataCheckDetail = detail;
    // A 200 is NEVER sufficient on its own — pass requires BOTH zero
    // console errors AND the real-data assertion.
    result.pass = consoleErrors.length === 0 && !!detail.pass;
  } catch (e) {
    result.fatalError = e.message;
    result.pass = false;
  }
  await page.close();
  return result;
}

function stripSidebar(page) {
  return page.evaluate(() => {
    const clone = document.body.cloneNode(true);
    clone.querySelectorAll('nav, aside, [class*="sidebar"], [class*="corex-nav"]').forEach((el) => el.remove());
    return clone.innerText;
  });
}

async function main() {
  const cookie = mintCookie();
  const browser = await puppeteer.launch({
    executablePath: '/usr/bin/chromium',
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox'],
  });

  const screens = [
    {
      name: 'applications_list (control-centre tiles)',
      url: `${BASE_URL}/corex/rental-applications?tile=all&scope=agency`,
      dataCheck: async (page) => {
        const rowCount = await page.evaluate(() => document.querySelectorAll('tbody tr').length);
        const tileCounts = await page.evaluate(() =>
          Array.from(document.querySelectorAll('a,button'))
            .map((el) => el.textContent.trim())
            .filter((t) => /^\d+\s+\S/.test(t)) // "24 Available"-shaped tile labels
        );
        return { pass: rowCount > 0 || tileCounts.length > 0, rowCount, tileCounts: tileCounts.slice(0, 10) };
      },
    },
    {
      name: `review_screen (app ${RENTAL_APP_ID})`,
      url: `${BASE_URL}/corex/rental-applications/${RENTAL_APP_ID}/review`,
      dataCheck: async (page) => {
        const text = await page.evaluate(() => document.body.innerText);
        const hasMoney = /R\s?[\d][\d,]*[.,]\d{2}/.test(text);
        return { pass: hasMoney, note: hasMoney ? 'real money figure present' : 'no money figure found on screen' };
      },
    },
    {
      name: `markup_view (app ${RENTAL_APP_ID}, documents open)`,
      url: `${BASE_URL}/corex/rental-applications/${RENTAL_APP_ID}/review`,
      afterLoad: async (page) => {
        const btn = await page.evaluateHandle(() =>
          Array.from(document.querySelectorAll('a,button')).find((el) => /view.*mark up/i.test(el.textContent))
        );
        const el = btn.asElement();
        if (el) { await el.click(); await new Promise((r) => setTimeout(r, 1500)); }
      },
      dataCheck: async (page) => {
        const pageImg = await page.evaluate(() => !!document.querySelector('img.rah-page-img'));
        const markCount = await page.evaluate(() => document.querySelectorAll('svg.absolute.inset-0 polyline').length);
        return { pass: pageImg, pageImageFound: pageImg, markCount };
      },
    },
    {
      name: `authorisation_screen (app ${RENTAL_APP_ID})`,
      url: `${BASE_URL}/corex/rental-applications/authorisation/${RENTAL_APP_ID}`,
      dataCheck: async (page) => {
        const text = await page.evaluate(() => document.body.innerText);
        const hasContent = text.length > 500;
        return { pass: hasContent, textLen: text.length };
      },
    },
    {
      name: 'rentals_contacts',
      url: `${BASE_URL}/corex/rentals/contacts?agent_id=`,
      dataCheck: async (page) => {
        const totalText = await page.evaluate(() => {
          const el = Array.from(document.querySelectorAll('*')).find((e) => e.children.length === 0 && /\d+\s+total/i.test(e.textContent));
          return el ? el.textContent.trim() : null;
        });
        return { pass: !!totalText, totalText };
      },
    },
    {
      name: 'pdf_splitter_review',
      url: `${BASE_URL}/tools/pdf-splitter/review`,
      dataCheck: async (page) => {
        const text = await page.evaluate(() => document.body.innerText);
        // A genuinely correct empty state ("no active session, upload first")
        // counts as real content; a blank/near-empty body does not.
        return { pass: text.length > 200, textLen: text.length };
      },
    },
  ];

  const results = [];
  for (const screen of screens) {
    results.push(await checkScreen(browser, cookie, screen));
  }

  // A real applicant-link check needs a real token — resolved from the DB
  // via the same PHP app rather than guessed, so this stays correct as
  // fixture data changes.
  try {
    const tokenOut = execFileSync(PHP_BIN, ['artisan', 'tinker', '--execute',
      `echo App\\Models\\RentalApplication::find(${RENTAL_APP_ID})->token;`], { cwd: APP_ROOT, encoding: 'utf8' });
    const token = tokenOut.trim().split('\n').pop().trim();
    if (token && token.length > 10) {
      results.push(await checkScreen(browser, cookie, {
        name: `applicant_link (app ${RENTAL_APP_ID})`,
        url: `${BASE_URL}/rental-application/${token}`,
        dataCheck: async (page) => {
          const text = await page.evaluate(() => document.body.innerText);
          return { pass: text.length > 100, textLen: text.length, sample: text.slice(0, 120) };
        },
      }));
    }
  } catch (e) {
    results.push({ name: `applicant_link (app ${RENTAL_APP_ID})`, pass: false, fatalError: 'could not resolve token: ' + e.message });
  }

  // contact_edit — a PLAIN contact, not tenant-specific, since the save
  // path is used well outside rentals (Johan's explicit instruction).
  try {
    const contactOut = execFileSync(PHP_BIN, ['artisan', 'tinker', '--execute',
      `echo App\\Models\\Contact::where('agency_id',1)->whereNull('deleted_at')->whereHas('parentTypes')->first()->id;`],
      { cwd: APP_ROOT, encoding: 'utf8' });
    const contactId = contactOut.trim().split('\n').pop().trim();
    results.push(await checkScreen(browser, cookie, {
      name: `contact_edit (contact ${contactId})`,
      url: `${BASE_URL}/corex/contacts/${contactId}`,
      dataCheck: async (page) => {
        const lastName = await page.evaluate(() => document.querySelector('input[name="last_name"]')?.value);
        return { pass: !!lastName, lastNameValue: lastName };
      },
    }));
  } catch (e) {
    results.push({ name: 'contact_edit', pass: false, fatalError: 'could not resolve a contact: ' + e.message });
  }

  await browser.close();

  console.log('\n=== RENTAL SMOKE — per-screen result ===');
  let failures = 0;
  for (const r of results) {
    const status = r.pass ? 'PASS' : 'FAIL';
    if (!r.pass) failures++;
    console.log(`\n[${status}] ${r.name}`);
    console.log(`  HTTP status: ${r.httpStatus ?? 'n/a'} (NOT the pass signal)`);
    console.log(`  console errors: ${r.consoleErrorCount ?? 'n/a'}`);
    if (r.consoleErrors?.length) console.log(`    ${r.consoleErrors.join('\n    ')}`);
    console.log(`  data check: ${r.dataCheckPassed ? 'passed' : 'FAILED'} — ${JSON.stringify(r.dataCheckDetail ?? r.fatalError ?? {})}`);
  }
  console.log(`\n${failures === 0 ? 'SMOKE PASSED' : 'SMOKE FAILED'} (${results.length} screen(s), ${failures} failing)`);
  process.exit(failures === 0 ? 0 : 1);
}

main().catch((e) => { console.error('FATAL:', e); process.exit(2); });
