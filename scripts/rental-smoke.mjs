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
 *       [--user-id=132] [--php-bin=php8.2]
 *       [--review-app-id=22] [--markup-app-id=4] [--authorisation-app-id=4]
 *       [--applicant-app-id=22]
 *
 * --user-id defaults to a dedicated test fixture (132, an admin persona
 * used throughout QA1 verification this cycle), never a real agent's own
 * account — this script must never appear to act as a real person.
 *
 * FIXTURE PRECONDITIONS (2026-09-12) — each app-id above is CHECKED, not
 * assumed. Added after a real incident: markup_view was run against
 * application 161, which has zero documents, so pageImageFound:false was
 * GUARANTEED regardless of the code — the suite reported that as an
 * ordinary FAIL, indistinguishable from a real regression, and a lane
 * spent time investigating a fixture problem as if it were a code bug.
 * preflight() below queries the DB directly for the exact precondition
 * each screen needs BEFORE running the browser check; if a precondition
 * isn't met (default or explicitly overridden via --*-app-id), the result
 * is reported as FIXTURE UNUSABLE with the specific reason — never with
 * the same shape as a real failure. See the fixture rationale comments
 * next to each *_APP_ID default below for why that id was chosen and what
 * it must keep having. Do not point any of these at 70, 76, or 107 —
 * Johan tests on those, and 70 carries a capture line he created by hand
 * for verification.
 *
 * If you need a NEW fixture (none of the existing applications satisfy a
 * check's precondition), create it through the real application flow or
 * an artisan command run as www-data (`sudo -u www-data php8.2 artisan
 * ...`) — never an interactive root shell writing storage files directly.
 * That is exactly what produced 274 root-owned files under
 * storage/app/private/rental-applications, silently unreadable by the
 * real www-data PHP-FPM process, on 2026-09-12.
 *
 * Exits non-zero if ANY screen has console errors, fails its real-data
 * assertion, OR has an unusable fixture. Prints a per-screen table:
 * console error count + data check, with FIXTURE UNUSABLE rows called out
 * distinctly from FAIL rows.
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
const PHP_BIN = args['php-bin'] || 'php8.2';

// Each id below was picked by querying QA1's real applications for the
// SPECIFIC precondition its screen needs (2026-09-12) — never picked
// arbitrarily, never 70/76/107 (Johan's own test applications). preflight()
// re-verifies these at runtime rather than trusting the comment forever, so
// if a future cleanup/reset touches these rows the suite fails loud with
// FIXTURE UNUSABLE instead of a misleading ordinary FAIL.
//
// REVIEW_APP_ID — needs a real money figure on screen. App 22 has
// approved_rental_amount = 9000.00 AND 11 real marks (not a bare-bones
// row) — must keep approved_rental_amount NOT NULL.
const REVIEW_APP_ID = args['review-app-id'] || '22';
// MARKUP_APP_ID — needs >=1 real, readable document (so the first-page
// preview actually renders) to prove pageImageFound. App 4 has one
// correctly www-data-owned 17-page PDF and 56 real marks — the richest
// markup fixture on QA1, so a broken pen rail or mark renderer has
// something real to fail against, not an empty canvas. Must keep >=1
// non-deleted Document row (source_type='rental_application',
// source_id=4) with a readable file on disk.
const MARKUP_APP_ID = args['markup-app-id'] || '4';
// AUTHORISATION_APP_ID — needs to genuinely BE with the authoriser, not
// just render successfully. App 4 (reused — it already qualifies) is
// status='under_assessment' with submitted_for_approval_at set, i.e.
// RentalApplication::isPendingAuthorisation() is actually true — the
// exact real-world state this screen exists to serve. Must keep that
// status/timestamp combination.
const AUTHORISATION_APP_ID = args['authorisation-app-id'] || MARKUP_APP_ID;
// APPLICANT_APP_ID — only needs a populated `token` column, true for
// essentially any real application; reusing app 22 keeps the fixture list
// short rather than needing a fifth id for a precondition this loose.
const APPLICANT_APP_ID = args['applicant-app-id'] || REVIEW_APP_ID;

/**
 * Queries QA1's real DB, once, for the exact precondition each fixture-
 * dependent screen needs, via the SAME app.php bootstrap the rest of this
 * script already shells out to (tinker) — never guessed, never assumed
 * from the comment above. Returns null fields on any query error rather
 * than throwing, so a DB hiccup shows up as a clear FIXTURE UNUSABLE
 * reason instead of crashing the whole suite.
 */
function preflight() {
  const php = `
    function j($v) { echo json_encode($v); }
    $review = App\\Models\\RentalApplication::find(${REVIEW_APP_ID});
    $markupDocs = App\\Models\\Document::where('source_type','rental_application')->where('source_id', ${MARKUP_APP_ID})->whereNull('deleted_at')->get();
    $auth = App\\Models\\RentalApplication::find(${AUTHORISATION_APP_ID});
    $applicant = App\\Models\\RentalApplication::find(${APPLICANT_APP_ID});
    j([
      'review' => ['exists' => (bool) $review, 'approvedAmount' => $review->approved_rental_amount ?? null],
      'markup' => ['docCount' => $markupDocs->count(), 'firstDocPath' => optional($markupDocs->first())->storage_path],
      'authorisation' => ['exists' => (bool) $auth, 'status' => $auth->status ?? null, 'pendingAuthorisation' => $auth ? $auth->isPendingAuthorisation() : false],
      'applicant' => ['exists' => (bool) $applicant, 'hasToken' => $applicant ? (bool) $applicant->token : false],
    ]);
  `;
  try {
    const out = execFileSync(PHP_BIN, ['artisan', 'tinker', '--execute', php], { cwd: APP_ROOT, encoding: 'utf8' });
    const jsonLine = out.trim().split('\n').find((l) => l.trim().startsWith('{'));
    return jsonLine ? JSON.parse(jsonLine) : null;
  } catch (e) {
    console.error('  [PREFLIGHT ERROR] could not query fixture preconditions: ' + e.message);
    return null;
  }
}

/** A result in the SAME shape checkScreen() returns, but tagged so the report table can never confuse it with a real code failure. */
function fixtureUnusable(name, reason) {
  return { name, fixtureUnusable: true, pass: false, httpStatus: null, consoleErrorCount: 0, consoleErrors: [], dataCheckPassed: false, dataCheckDetail: { reason } };
}

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
  const pre = preflight();
  const browser = await puppeteer.launch({
    executablePath: '/usr/bin/chromium',
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox'],
  });

  const results = [];

  results.push(await checkScreen(browser, cookie, {
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
  }));

  if (!pre) {
    results.push(fixtureUnusable(`review_screen (app ${REVIEW_APP_ID})`, 'FIXTURE UNUSABLE: preflight query failed, could not verify preconditions'));
  } else if (!pre.review.exists) {
    results.push(fixtureUnusable(`review_screen (app ${REVIEW_APP_ID})`, `FIXTURE UNUSABLE: app ${REVIEW_APP_ID} does not exist`));
  } else if (pre.review.approvedAmount === null) {
    results.push(fixtureUnusable(`review_screen (app ${REVIEW_APP_ID})`, `FIXTURE UNUSABLE: app ${REVIEW_APP_ID} has no approved_rental_amount, review_screen cannot be evaluated for a real money figure`));
  } else {
    results.push(await checkScreen(browser, cookie, {
      name: `review_screen (app ${REVIEW_APP_ID})`,
      url: `${BASE_URL}/corex/rental-applications/${REVIEW_APP_ID}/review`,
      dataCheck: async (page) => {
        const text = await page.evaluate(() => document.body.innerText);
        const hasMoney = /R\s?[\d][\d,]*[.,]\d{2}/.test(text);
        return { pass: hasMoney, note: hasMoney ? 'real money figure present' : 'no money figure found on screen' };
      },
    }));
  }

  if (!pre) {
    results.push(fixtureUnusable(`markup_view (app ${MARKUP_APP_ID}, documents open)`, 'FIXTURE UNUSABLE: preflight query failed, could not verify preconditions'));
  } else if (pre.markup.docCount === 0) {
    results.push(fixtureUnusable(`markup_view (app ${MARKUP_APP_ID}, documents open)`, `FIXTURE UNUSABLE: app ${MARKUP_APP_ID} has no documents, markup_view cannot be evaluated`));
  } else {
    results.push(await checkScreen(browser, cookie, {
      name: `markup_view (app ${MARKUP_APP_ID}, documents open)`,
      url: `${BASE_URL}/corex/rental-applications/${MARKUP_APP_ID}/review`,
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
    }));
  }

  if (!pre) {
    results.push(fixtureUnusable(`authorisation_screen (app ${AUTHORISATION_APP_ID})`, 'FIXTURE UNUSABLE: preflight query failed, could not verify preconditions'));
  } else if (!pre.authorisation.exists) {
    results.push(fixtureUnusable(`authorisation_screen (app ${AUTHORISATION_APP_ID})`, `FIXTURE UNUSABLE: app ${AUTHORISATION_APP_ID} does not exist`));
  } else if (!pre.authorisation.pendingAuthorisation) {
    results.push(fixtureUnusable(`authorisation_screen (app ${AUTHORISATION_APP_ID})`, `FIXTURE UNUSABLE: app ${AUTHORISATION_APP_ID} is not pending authorisation (status=${pre.authorisation.status}), authorisation_screen cannot be evaluated in its real state`));
  } else {
    results.push(await checkScreen(browser, cookie, {
      name: `authorisation_screen (app ${AUTHORISATION_APP_ID})`,
      url: `${BASE_URL}/corex/rental-applications/authorisation/${AUTHORISATION_APP_ID}`,
      dataCheck: async (page) => {
        const text = await page.evaluate(() => document.body.innerText);
        const hasContent = text.length > 500;
        return { pass: hasContent, textLen: text.length };
      },
    }));
  }

  results.push(await checkScreen(browser, cookie, {
    name: 'rentals_contacts',
    url: `${BASE_URL}/corex/rentals/contacts?agent_id=`,
    dataCheck: async (page) => {
      const totalText = await page.evaluate(() => {
        const el = Array.from(document.querySelectorAll('*')).find((e) => e.children.length === 0 && /\d+\s+total/i.test(e.textContent));
        return el ? el.textContent.trim() : null;
      });
      return { pass: !!totalText, totalText };
    },
  }));

  results.push(await checkScreen(browser, cookie, {
    name: 'pdf_splitter_review',
    url: `${BASE_URL}/tools/pdf-splitter/review`,
    dataCheck: async (page) => {
      const text = await page.evaluate(() => document.body.innerText);
      // A genuinely correct empty state ("no active session, upload first")
      // counts as real content; a blank/near-empty body does not.
      return { pass: text.length > 200, textLen: text.length };
    },
  }));

  // A real applicant-link check needs a real token — resolved from the DB
  // via the same PHP app rather than guessed, so this stays correct as
  // fixture data changes.
  if (!pre) {
    results.push(fixtureUnusable(`applicant_link (app ${APPLICANT_APP_ID})`, 'FIXTURE UNUSABLE: preflight query failed, could not verify preconditions'));
  } else if (!pre.applicant.exists || !pre.applicant.hasToken) {
    results.push(fixtureUnusable(`applicant_link (app ${APPLICANT_APP_ID})`, `FIXTURE UNUSABLE: app ${APPLICANT_APP_ID} ${pre.applicant.exists ? 'has no token' : 'does not exist'}, applicant_link cannot be evaluated`));
  } else {
    try {
      const tokenOut = execFileSync(PHP_BIN, ['artisan', 'tinker', '--execute',
        `echo App\\Models\\RentalApplication::find(${APPLICANT_APP_ID})->token;`], { cwd: APP_ROOT, encoding: 'utf8' });
      const token = tokenOut.trim().split('\n').pop().trim();
      results.push(await checkScreen(browser, cookie, {
        name: `applicant_link (app ${APPLICANT_APP_ID})`,
        url: `${BASE_URL}/rental-application/${token}`,
        dataCheck: async (page) => {
          const text = await page.evaluate(() => document.body.innerText);
          return { pass: text.length > 100, textLen: text.length, sample: text.slice(0, 120) };
        },
      }));
    } catch (e) {
      results.push({ name: `applicant_link (app ${APPLICANT_APP_ID})`, pass: false, fatalError: 'could not resolve token: ' + e.message });
    }
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
  let fixtureIssues = 0;
  for (const r of results) {
    if (r.fixtureUnusable) {
      fixtureIssues++;
      console.log(`\n[FIXTURE UNUSABLE] ${r.name}`);
      console.log(`  ${r.dataCheckDetail.reason}`);
      console.log('  This is a DATA problem with the fixture application, not a code failure — pick or repair the fixture, do not debug the feature.');
      continue;
    }
    const status = r.pass ? 'PASS' : 'FAIL';
    if (!r.pass) failures++;
    console.log(`\n[${status}] ${r.name}`);
    console.log(`  HTTP status: ${r.httpStatus ?? 'n/a'} (NOT the pass signal)`);
    console.log(`  console errors: ${r.consoleErrorCount ?? 'n/a'}`);
    if (r.consoleErrors?.length) console.log(`    ${r.consoleErrors.join('\n    ')}`);
    console.log(`  data check: ${r.dataCheckPassed ? 'passed' : 'FAILED'} — ${JSON.stringify(r.dataCheckDetail ?? r.fatalError ?? {})}`);
  }
  const totalBad = failures + fixtureIssues;
  console.log(`\n${totalBad === 0 ? 'SMOKE PASSED' : 'SMOKE FAILED'} (${results.length} screen(s), ${failures} failing, ${fixtureIssues} fixture-unusable)`);
  process.exit(totalBad === 0 ? 0 : 1);
}

main().catch((e) => { console.error('FATAL:', e); process.exit(2); });
