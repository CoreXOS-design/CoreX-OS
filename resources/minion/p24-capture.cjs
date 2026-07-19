// AT-284 Chrome minion — Property24 PUBLIC search-results fetcher.
// Pure browser fetch: navigate a public P24 for-sale search URL, grab the rendered
// HTML, write it to outFile. NO login, NO credentials, NO ingest POST here.
// Boundary (AT-284 build rule): if P24 serves a block/challenge we DO NOT attempt to
// solve or bypass it — we report blocked:true and exit so the run alerts and backs off.
const fs = require('fs');
const puppeteer = require('puppeteer');

(async () => {
  const args = JSON.parse(process.argv[2] || '{}');
  const { url, outFile, navTimeoutMs = 45000, chromiumPath } = args;
  const out = { ok: false, blocked: false, finalUrl: null, title: null, htmlBytes: 0, error: null };
  let browser;
  try {
    browser = await puppeteer.launch({
      headless: 'new',
      executablePath: chromiumPath || undefined,
      args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu', '--disable-dev-shm-usage'],
    });
    const page = await browser.newPage();
    await page.setViewport({ width: 1366, height: 900 });
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: navTimeoutMs });
    await page.waitForSelector('body', { timeout: 5000 }).catch(() => {});
    const html = await page.content();
    const title = await page.title();
    const finalUrl = page.url();
    const blockSignals = /captcha|unusual traffic|access denied|are you a human|verify you are|cf-challenge|px-captcha/i;
    if (blockSignals.test(title || '') || blockSignals.test((html || '').slice(0, 4000))) {
      out.blocked = true; out.finalUrl = finalUrl; out.title = title;
    } else {
      fs.writeFileSync(outFile, JSON.stringify({ source_url: url, final_url: finalUrl, page_title: title, html }));
      out.ok = true; out.finalUrl = finalUrl; out.title = title; out.htmlBytes = (html || '').length;
    }
  } catch (e) {
    out.error = String(e && e.message ? e.message : e);
  } finally {
    if (browser) { try { await browser.close(); } catch (_) {} }
  }
  process.stdout.write(JSON.stringify(out));
})();
