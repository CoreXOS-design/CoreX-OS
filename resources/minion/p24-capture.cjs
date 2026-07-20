// AT-284 Chrome minion — Property24 PUBLIC search-results fetcher (paginated).
// Walks EVERY results page for a suburb search in ONE browser session (P24 paginates by
// path suffix /pN). Pure public fetch: no login, no ingest here, polite gap between pages.
// Boundary: on a REAL block/challenge we STOP (no solve/bypass) and report blocked.
const fs = require('fs'), os = require('os'), path = require('path');
const puppeteer = require('puppeteer');
const rnd = (a, b) => a + Math.floor(Math.random() * (b - a + 1));

(async () => {
  const A = JSON.parse(process.argv[2] || '{}');
  const { baseUrl, outDir, navTimeoutMs = 45000, chromiumPath, maxPages = 60, paceMinMs = 3000, paceMaxMs = 7000 } = A;
  const out = { ok: false, blocked: false, totalPages: 0, pagesFetched: 0, results: [], error: null };
  const udd = fs.mkdtempSync(path.join(os.tmpdir(), 'minion-'));
  process.env.HOME = udd;
  fs.mkdirSync(outDir, { recursive: true });
  const titleBlock = /access denied|attention required|just a moment|unusual traffic|are you (a )?human|verify you are (a )?human/i;
  const bodyBlock  = /enable javascript and cookies to continue|verify you are a human|complete the security check/i;
  let browser;
  async function fetchPage(page, url, n) {
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: navTimeoutMs });
    await page.waitForSelector('body', { timeout: 5000 }).catch(() => {});
    const html = await page.content(), title = await page.title(), finalUrl = page.url();
    if (titleBlock.test(title || '') || bodyBlock.test((html || '').slice(0, 4000))) return { page: n, blocked: true, finalUrl, title };
    const file = path.join(outDir, 'p' + n + '.json');
    fs.writeFileSync(file, JSON.stringify({ source_url: url, final_url: finalUrl, page_title: title, html }));
    return { page: n, blocked: false, finalUrl, title, htmlBytes: html.length, file, html };
  }
  try {
    browser = await puppeteer.launch({
      headless: 'new', executablePath: chromiumPath || undefined, userDataDir: udd,
      args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu', '--disable-dev-shm-usage', '--disable-crash-reporter', '--disable-breakpad', '--crash-dumps-dir=' + udd],
    });
    const page = await browser.newPage();
    await page.setViewport({ width: 1366, height: 900 });
    const r1 = await fetchPage(page, baseUrl, 1);
    if (r1.blocked) { out.blocked = true; out.error = 'blocked on page 1'; }
    else {
      let maxP = 1; const m = (r1.html || '').match(/data-pagenumber=\"(\d+)\"/g);
      if (m) for (const s of m) { const n = parseInt(s.replace(/\D/g, ''), 10); if (n > maxP) maxP = n; }
      maxP = Math.min(maxP, maxPages); out.totalPages = maxP;
      delete r1.html; out.results.push(r1);
      for (let n = 2; n <= maxP; n++) {
        await new Promise(r => setTimeout(r, rnd(paceMinMs, paceMaxMs)));
        const rn = await fetchPage(page, baseUrl + '/p' + n, n);
        if (rn.blocked) { out.results.push({ page: n, blocked: true, finalUrl: rn.finalUrl }); break; }
        delete rn.html; out.results.push(rn);
      }
      out.pagesFetched = out.results.filter(r => !r.blocked).length;
      out.ok = out.pagesFetched > 0;
    }
  } catch (e) { out.error = String(e && e.message ? e.message : e); }
  finally { if (browser) { try { await browser.close(); } catch (_) {} } try { fs.rmSync(udd, { recursive: true, force: true }); } catch (_) {} }
  process.stdout.write(JSON.stringify(out));
})();
