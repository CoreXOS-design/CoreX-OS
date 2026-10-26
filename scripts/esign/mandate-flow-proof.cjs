// Real-Chromium proof for the Subscription Agreement Netcash mandate (spec agency-timeline-and-platform-esign.md 11.24) — FAILS (exit 1) if, on the
// recipient signing page (or the owner's read-only review) at each width,
//   - a 6-line account-holder Address in the mandate overlaps the next row (Bank Name): every row must start at or below the bottom of the one above;
//   - the mandate Date, Part A Date, first payment date or collection day is editable by the signer.
// usage: NODE_PATH=<app>/node_modules node mandate-flow-proof.cjs <url> <out-prefix> [width ...]     (default widths 1280 390)
const puppeteer = require('puppeteer');
const SIX = 'Block C, Unit 14\n15 Marine Drive\nSouthbroom\nMargate\nKwaZulu-Natal\n4275';
(async () => {
  const [url, out, ...w] = process.argv.slice(2);
  const widths = (w.length ? w : ['1280', '390']).map(Number);
  const failures = [];
  const br = await puppeteer.launch({ headless: 'new', executablePath: '/usr/bin/chromium', args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-gpu'] });
  try {
    for (const width of widths) {
      const pg = await br.newPage();
      await pg.setViewport({ width, height: 1000 });
      await pg.setRequestInterception(true); // the proof types into the real form: nothing it does may ever be saved
      pg.on('request', r => { if (r.method() !== 'GET' && r.method() !== 'HEAD') { r.abort('blockedbyclient'); } else { r.continue(); } });
      pg.on('dialog', async d => { failures.push(width + ': native dialog ' + d.type()); await d.dismiss(); });
      await pg.goto(url, { waitUntil: 'networkidle2', timeout: 45000 });
      const res = await pg.evaluate((six) => {
        const q = s => document.querySelector(s);
        const row = el => el && el.closest('p.mf');
        const addr = q('textarea[data-field="m_address"]');
        const r = { typeable: [], rows: null };
        if (addr && !addr.readOnly && !addr.disabled) { addr.value = six; addr.dispatchEvent(new Event('input', { bubbles: true })); }
        else if (addr) { addr.value = six; addr.dispatchEvent(new Event('input', { bubbles: true })); } // read-only review: still measure the box
        const rows = [...document.querySelectorAll('p.mf')].filter(p => p.querySelector('[data-field="m_address"], .val, [data-field]'));
        const a = row(addr);
        const bank = row(q('[data-field="m_bank"]'));
        if (a && bank) {
          const ra = a.getBoundingClientRect(), rb = bank.getBoundingClientRect(), ta = addr.getBoundingClientRect();
          r.rows = { addrRowBottom: Math.round(ra.bottom), bankRowTop: Math.round(rb.top), addrBoxBottom: Math.round(ta.bottom), addrBoxHeight: Math.round(ta.height) };
        }
        // Every mandate row (editable form OR read-only review): the content of a row must end above the next row's top.
        r.overlaps = [];
        const all = [...document.querySelectorAll('p.mf')];
        all.forEach((p, i) => {
          const next = all[i + 1]; if (!next || p.parentElement !== next.parentElement) return;
          let bottom = p.getBoundingClientRect().bottom;
          p.querySelectorAll('*').forEach(c => { const b = c.getBoundingClientRect(); if (b.height > 0) bottom = Math.max(bottom, b.bottom); });
          const top = next.getBoundingClientRect().top;
          if (bottom > top + 0.5) r.overlaps.push((p.textContent || '').trim().slice(0, 40) + ' → ' + Math.round(bottom - top) + 'px into the next row');
        });
        for (const k of ['sig_date', 'm_date', 'm_first_payment', 'm_day']) {
          const el = q('[data-field="' + k + '"]');
          if (el && !(el.readOnly || el.disabled)) r.typeable.push(k);
        }
        return r;
      }, SIX);
      await pg.screenshot({ path: out + '-' + width + '.png' });
      if (res.rows) {
        console.log(width + 'px', JSON.stringify(res.rows));
        if (res.rows.addrBoxBottom > res.rows.bankRowTop + 0.5) failures.push(width + ': the address box (' + res.rows.addrBoxBottom + ') runs into the Bank Name row (starts ' + res.rows.bankRowTop + ')');
        if (res.rows.addrRowBottom > res.rows.bankRowTop + 0.5) failures.push(width + ': the address row (' + res.rows.addrRowBottom + ') overlaps the Bank Name row (' + res.rows.bankRowTop + ')');
        if (res.rows.addrBoxHeight < 60) failures.push(width + ': the address box did not grow for six lines (' + res.rows.addrBoxHeight + 'px)');
      } else {
        console.log(width + 'px: no editable address row on this page (read-only view) — layout not measured');
      }
      if (res.overlaps.length) failures.push(width + ': rows overlap — ' + res.overlaps.join('; '));
      if (res.typeable.length) failures.push(width + ': signer can type ' + res.typeable.join(', '));
      await pg.close();
    }
  } finally { await br.close(); }
  if (failures.length) { console.error('FAIL\n' + failures.join('\n')); process.exit(1); }
  console.log('OK');
})();
