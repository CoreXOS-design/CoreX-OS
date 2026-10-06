// Real-browser alignment check (spec §11.21): the Agency and RR Technologies signature blocks are equal columns with every row on the same line, and the
// mandate's Label: field rows share one grid. Measures element positions on the recipient page (optionally also an authed path, e.g. the RR countersign screen).
// Usage: node scripts/verify-agreement-alignment.cjs <recipient-url> [authedPath cookieName cookieValue baseUrl]
let puppeteer;
for (const p of [process.env.PUPPETEER_PATH, 'puppeteer', '/corex-qa1/node_modules/puppeteer']) {
  if (!p) continue;
  try { puppeteer = require(p); break; } catch (e) { /* next */ }
}
if (!puppeteer) { console.error('puppeteer not found'); process.exit(2); }
const [url, authedPath, cname, cvalue, base] = process.argv.slice(2);
let bad = 0;
const check = (ok, s) => { if (!ok) bad++; console.log((ok ? 'OK   ' : 'FAIL ') + s); };
const near = (a, b, tol = 1.5) => Math.abs(a - b) <= tol;

async function measure(page, label) {
  const m = await page.evaluate(() => {
    const r = (e) => { const x = e.getBoundingClientRect(); return { left: x.left, top: x.top + scrollY, width: x.width, height: x.height, bottom: x.bottom + scrollY }; };
    const t = document.querySelector('table.sigtable');
    const tds = [...t.querySelectorAll('td')];
    const rows = tds.map((td) => [...td.querySelectorAll('p.sr')].map((p) => ({ cls: p.className.replace('sr ', ''), ...r(p),
      box: (p.querySelector('.sigpad, .sigline, .sigimg, .blank.sigline') ? r(p.querySelector('.sigpad, .sigline, .sigimg, .blank.sigline')) : null),
      field: (p.querySelector('input, .val, .blank') ? r(p.querySelector('input, .val, .blank')) : null) })));
    const mf = [...document.querySelectorAll('p.mf')].map((p) => ({ label: p.querySelector('.mf-l').textContent.trim(), l: r(p.querySelector('.mf-l')), v: r(p.querySelector('.mf-v')), row: r(p), field: p.querySelector('.mf-v .fld') ? r(p.querySelector('.mf-v .fld')) : null }));
    return { ths: [...t.querySelectorAll('th')].map(r), rows, mf };
  });
  check(m.ths.length === 2 && near(m.ths[0].width, m.ths[1].width) && near(m.ths[0].top, m.ths[1].top), label + ': the two columns are equal width and start on the same line (' + Math.round(m.ths[0].width) + ' / ' + Math.round(m.ths[1].width) + ' px)');
  const [a, b] = m.rows;
  check(a.length === 5 && b.length === 5, label + ': five rows in each column');
  a.forEach((row, i) => {
    const o = b[i];
    check(row.cls === o.cls && near(row.top, o.top) && near(row.height, o.height), label + ': row "' + row.cls.replace('sr-', '') + '" — same top (' + Math.round(row.top) + ' / ' + Math.round(o.top) + ') and height (' + Math.round(row.height) + ' / ' + Math.round(o.height) + ')');
    if (row.box && o.box) check(near(row.box.top, o.box.top) && near(row.box.height, o.box.height) && near(row.box.width, o.box.width, 2), label + ': signature boxes equal (top ' + Math.round(row.box.top) + '/' + Math.round(o.box.top) + ', ' + Math.round(row.box.width) + '×' + Math.round(row.box.height) + ' vs ' + Math.round(o.box.width) + '×' + Math.round(o.box.height) + ')');
    if (row.field && o.field && !row.box) check(near(row.field.left - row.left, o.field.left - o.left, 6) && near(row.field.top, o.field.top, 4) && near(row.field.width, o.field.width, 4), label + ': "' + row.cls.replace('sr-', '') + '" fields start/length/line up');
  });
  // mandate grid
  check(m.mf.length === 13, label + ': 13 mandate rows on the grid (found ' + m.mf.length + ')');
  const L = m.mf.map((x) => Math.round(x.l.left)), V = m.mf.map((x) => Math.round(x.v.left));
  check(new Set(L).size === 1, label + ': all labels start on one left edge (' + [...new Set(L)] + ')');
  check(new Set(V).size === 1, label + ': all fields start on one left edge (' + [...new Set(V)] + ')');
  const W = [...new Set(m.mf.filter((x) => x.field).map((x) => Math.round(x.field.width)))];
  check(W.length <= 1, label + ': every field box has one width (' + (W.length ? W + ' px' : 'static values, no boxes') + ')');
  const H = [...new Set(m.mf.map((x) => Math.round(x.row.height)))];
  check(H.length === 1, label + ': every mandate row has the same height (' + H + ' px)');
}

(async () => {
  const browser = await puppeteer.launch({ executablePath: process.env.CHROME || '/usr/bin/chromium', args: ['--no-sandbox'] });
  let page = await browser.newPage();
  await page.setViewport({ width: 1100, height: 1400 });
  await page.goto(url, { waitUntil: 'networkidle2' }); await new Promise((r) => setTimeout(r, 1200));
  await measure(page, 'recipient page');
  if (authedPath) {
    page = await browser.newPage(); await page.setViewport({ width: 1100, height: 1400 });
    await page.setCookie({ name: cname, value: cvalue, url: base });
    await page.goto(base + authedPath, { waitUntil: 'networkidle2' }); await new Promise((r) => setTimeout(r, 1200));
    await measure(page, authedPath.includes('countersign') ? 'RR countersign screen' : 'owner preview');
  }
  // narrow phone width: the grid may stack, but each block keeps its internal layout
  page = await browser.newPage(); await page.setViewport({ width: 390, height: 900 });
  await page.goto(url, { waitUntil: 'networkidle2' }); await new Promise((r) => setTimeout(r, 1200));
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth);
  check(overflow <= 2, 'phone width (390px): no sideways scrolling (overflow ' + overflow + ' px)');
  await browser.close();
  console.log(bad ? bad + ' FAILURE(S)' : 'ALL ALIGNED');
  process.exit(bad ? 1 : 0);
})();
