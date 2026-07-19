// AT-284 Chrome minion — Property24 PUBLIC search-results fetcher.
// Pure browser fetch: navigate a public P24 for-sale search URL, grab the rendered
// HTML, write it to outFile. NO login, NO credentials, NO ingest POST here.
// Boundary (AT-284 build rule): if P24 serves a REAL block/challenge we DO NOT attempt to
// solve or bypass it — we report blocked:true and exit so the run alerts and backs off.
const fs = require('fs');
const os = require('os');
const path = require('path');
const puppeteer = require('puppeteer');

(async () => {
  const args = JSON.parse(process.argv[2] || '{}');
  const { url, outFile, navTimeoutMs = 45000, chromiumPath } = args;
  const out = { ok: false, blocked: false, finalUrl: null, title: null, htmlBytes: 0, error: null };

  // Self-contained writable profile + HOME so Chromium's crashpad and puppeteer's config
  // lookup never depend on the caller's HOME (works under any queue-worker user).
  const udd = fs.mkdtempSync(path.join(os.tmpdir(), 'minion-'));
  process.env.HOME = udd;

  let browser;
  try {
    browser = await puppeteer.launch({
      headless: 'new',
      executablePath: chromiumPath || undefined,
      userDataDir: udd,
      args: [
        '--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
        '--disable-crash-reporter', '--disable-breakpad', '--crash-dumps-dir=' + udd,
      ],
    });
    const page = await browser.newPage();
    await page.setViewport({ width: 1366, height: 900 });
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: navTimeoutMs });
    await page.waitForSelector('body', { timeout: 5000 }).catch(() => {});
    const html = await page.content();
    const title = await page.title();
    const finalUrl = page.url();

    // Only a REAL block/challenge counts — NOT the mere presence of anti-bot vendor scripts
    // (P24 ships those on every normal page). Check the title + active-challenge body copy.
    const titleBlock = /access denied|attention required|just a moment|unusual traffic|are you (a )?human|verify you are (a )?human/i;
    const bodyBlock  = /enable javascript and cookies to continue|verify you are a human|complete the security check/i;
    if (titleBlock.test(title || '') || bodyBlock.test((html || '').slice(0, 4000))) {
      out.blocked = true; out.finalUrl = finalUrl; out.title = title;
    } else {
      fs.writeFileSync(outFile, JSON.stringify({ source_url: url, final_url: finalUrl, page_title: title, html }));
      out.ok = true; out.finalUrl = finalUrl; out.title = title; out.htmlBytes = (html || '').length;
    }
  } catch (e) {
    out.error = String(e && e.message ? e.message : e);
  } finally {
    if (browser) { try { await browser.close(); } catch (_) {} }
    try { fs.rmSync(udd, { recursive: true, force: true }); } catch (_) {}
  }
  process.stdout.write(JSON.stringify(out));
})();
