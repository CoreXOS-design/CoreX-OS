/**
 * CoreX — TVA COMPANY DIRECTORSHIP capture.
 *
 * Scoped to app.thevirtualagent.co.za's Company page
 * (/Search/Company/-1?registrationNumber={regno}). A company has no numbers of
 * its own — its people are its DIRECTORS. This script scrapes the on-screen
 * directorship table and sends each director (SA ID + name + gender) plus the
 * company (reg-no from the URL, name from the table) to CoreX, which creates
 * the directors as natural-person contacts LINKED to the company entity contact
 * (matched on reg-no). It does NOT scrape the directors' phone numbers — the
 * agent runs the normal TVA person scrape on each director afterward.
 *
 * DOM (captured live 2026-08-14):
 *   pane #tab_1_3 → table#directorshipTable (jQuery DataTable, paginates)
 *   per row: td:nth-child(1)=Gender icon, (2)=ID Number, (3)=Full Name
 *   ("SURNAME, INITIALS"), (4)=Company, (5)=Status, (6)=Date.
 * The DataTable only keeps the CURRENT page's <tr> in the DOM, so we read ALL
 * rows via the page's DataTable API (main-world inject); if that is blocked we
 * fall back to expanding the page-length control and scraping the DOM.
 */
(function () {
  'use strict';

  const BUTTON_ID = 'corex-tva-company-btn';
  const STATUS_ID = 'corex-tva-company-status';

  function regNoFromUrl() {
    const m = location.search.match(/[?&]registrationNumber=([^&]+)/i);
    return m ? decodeURIComponent(m[1]).trim() : null;
  }

  // Gender cell → 'M' | 'F' | null (icon ♂/♀, or fa-mars/fa-venus, or text).
  function genderFrom(html) {
    const s = String(html || '').toLowerCase();
    if (s.indexOf('♂') !== -1 || s.indexOf('mars') !== -1 || /\bmale\b/.test(s)) return 'M';
    if (s.indexOf('♀') !== -1 || s.indexOf('venus') !== -1 || /\bfemale\b/.test(s)) return 'F';
    return null;
  }

  function rowToDirector(cellHtmls) {
    return {
      gender: genderFrom(cellHtmls[0]),
      id_number: stripTags(cellHtmls[1]).replace(/\s+/g, ''),
      full_name: stripTags(cellHtmls[2]).trim(),
      company: stripTags(cellHtmls[3]).trim(),
    };
  }

  function stripTags(html) {
    const d = document.createElement('div');
    d.innerHTML = String(html || '');
    return (d.textContent || '').trim();
  }

  // ── ALL-ROWS extraction ─────────────────────────────────────────────
  // Primary: ask the page's own DataTable instance for every row (across
  // pages) by injecting a tiny script into the page's main world, which posts
  // the rows back. Falls back to a DOM scrape (with page-length expanded).
  function extractViaDataTableApi() {
    return new Promise(function (resolve) {
      let done = false;
      function onMsg(ev) {
        if (ev.source !== window || !ev.data || !ev.data.__corexTvaDirectors) return;
        window.removeEventListener('message', onMsg);
        done = true;
        resolve(ev.data.__corexTvaDirectors);
      }
      window.addEventListener('message', onMsg);

      const code = `(function () {
        try {
          var out = { ok: false, rows: [], via: null };
          var $ = window.jQuery || window.$;
          var el = document.getElementById('directorshipTable');
          if (el && $ && $.fn && $.fn.dataTable && $.fn.dataTable.isDataTable('#directorshipTable')) {
            var dt = $('#directorshipTable').DataTable();
            var nodes = dt.rows().nodes();
            for (var i = 0; i < nodes.length; i++) {
              var tds = nodes[i].getElementsByTagName('td');
              if (tds.length < 4) continue;
              out.rows.push([tds[0].innerHTML, tds[1].innerHTML, tds[2].innerHTML, tds[3].innerHTML]);
            }
            out.ok = true; out.via = 'datatable-api';
          }
          window.postMessage({ __corexTvaDirectors: out }, '*');
        } catch (e) {
          window.postMessage({ __corexTvaDirectors: { ok: false, rows: [], via: 'error', reason: String(e) } }, '*');
        }
      })();`;

      try {
        const s = document.createElement('script');
        s.textContent = code;
        (document.head || document.documentElement).appendChild(s);
        s.remove();
      } catch (e) { /* CSP may block — the timeout falls through to DOM */ }

      setTimeout(function () {
        if (done) return;
        window.removeEventListener('message', onMsg);
        resolve({ ok: false, rows: [], via: 'timeout' });
      }, 1500);
    });
  }

  // Fallback: expand the DataTable page length to its max option so more rows
  // render, then read the DOM. Best-effort under CSP where inject is blocked.
  async function extractViaDom() {
    const sel = document.querySelector('select[name="directorshipTable_length"]');
    if (sel && sel.options && sel.options.length) {
      let maxVal = sel.value, maxNum = parseInt(sel.value, 10) || 0;
      for (const opt of sel.options) {
        const n = parseInt(opt.value, 10);
        if (opt.value === '-1') { maxVal = '-1'; maxNum = Infinity; break; }
        if (!isNaN(n) && n > maxNum) { maxNum = n; maxVal = opt.value; }
      }
      if (sel.value !== maxVal) {
        sel.value = maxVal;
        sel.dispatchEvent(new Event('change', { bubbles: true }));
        await new Promise(function (r) { setTimeout(r, 700); });
      }
    }
    const rows = [];
    const trs = document.querySelectorAll('#directorshipTable tbody tr');
    trs.forEach(function (tr) {
      const tds = tr.getElementsByTagName('td');
      if (tds.length < 4) return;
      rows.push([tds[0].innerHTML, tds[1].innerHTML, tds[2].innerHTML, tds[3].innerHTML]);
    });
    return { ok: rows.length > 0, rows: rows, via: 'dom' };
  }

  async function collectDirectors() {
    let res = await extractViaDataTableApi();
    let via = res.via;
    if (!res.ok || res.rows.length === 0) {
      res = await extractViaDom();
      via = res.via;
    }
    const seen = {};
    const directors = [];
    let company = '';
    res.rows.forEach(function (cells) {
      const d = rowToDirector(cells);
      if (!company && d.company) company = d.company;
      const key = d.id_number || d.full_name;
      if (!key || seen[key]) return;
      seen[key] = true;
      if (d.id_number === '' && d.full_name === '') return;
      directors.push({ id_number: d.id_number || null, full_name: d.full_name || null, gender: d.gender });
    });
    return { directors: directors, company: company, via: via };
  }

  async function buildPayload() {
    const regNo = regNoFromUrl();
    if (!regNo) return { error: 'No company registration number in the page URL — open the company via Search, not this URL directly.' };
    if (!document.getElementById('directorshipTable')) {
      return { error: 'Directorship table not found — open the Directorship tab and let it load, then capture.' };
    }
    const collected = await collectDirectors();
    if (collected.directors.length === 0) {
      return { error: 'No directors found in the directorship table — make sure the tab has loaded.' };
    }
    return {
      payload: {
        source: 'tva_company',
        company: { registration_number: regNo, name: collected.company || null },
        directors: collected.directors,
      },
      via: collected.via,
      count: collected.directors.length,
    };
  }

  // ── UI ───────────────────────────────────────────────────────────────
  function injectStyles() {
    if (document.getElementById('corex-tva-company-style')) return;
    const style = document.createElement('style');
    style.id = 'corex-tva-company-style';
    style.textContent =
      '#' + BUTTON_ID + '{all:initial!important;position:fixed!important;top:12px!important;right:12px!important;z-index:2147483647!important;font-family:-apple-system,BlinkMacSystemFont,sans-serif!important;font-size:13px!important;font-weight:600!important;color:#fff!important;background:#0b2a4a!important;border:1px solid #0ea5e9!important;border-radius:6px!important;padding:8px 14px!important;cursor:pointer!important;box-shadow:0 2px 8px rgba(0,0,0,.25)!important;}' +
      '#' + BUTTON_ID + ':hover{background:#0ea5e9!important;}' +
      '#' + BUTTON_ID + '[disabled]{opacity:.6!important;cursor:not-allowed!important;}' +
      '#' + STATUS_ID + '{all:initial!important;position:fixed!important;top:50px!important;right:12px!important;z-index:2147483647!important;font-family:-apple-system,BlinkMacSystemFont,sans-serif!important;font-size:12px!important;color:#fff!important;background:#111827!important;border-radius:6px!important;padding:6px 10px!important;max-width:280px!important;}';
    document.head.appendChild(style);
  }

  function setStatus(text, isError) {
    let el = document.getElementById(STATUS_ID);
    if (!text) { if (el) el.remove(); return; }
    if (!el) { el = document.createElement('div'); el.id = STATUS_ID; document.body.appendChild(el); }
    el.style.background = isError ? '#7f1d1d' : '#111827';
    el.textContent = text;
  }

  async function onCaptureClick() {
    const btn = document.getElementById(BUTTON_ID);
    if (btn) { btn.disabled = true; btn.textContent = 'Capturing…'; }
    try {
      setStatus('Reading directors…', false);
      const built = await buildPayload();
      if (built.error) { setStatus('Failed: ' + built.error, true); return; }

      setStatus('Sending ' + built.count + ' director' + (built.count === 1 ? '' : 's') + ' to CoreX…', false);
      const result = await chrome.runtime.sendMessage({ action: 'captureTvaCompanyDirectors', payload: built.payload });
      if (result && result.error) {
        setStatus('Failed: ' + result.error, true);
      } else if (result && result.ok) {
        const n = Array.isArray(result.directors) ? result.directors.filter(function (d) { return !d.error; }).length : built.count;
        setStatus('Captured ✓ ' + n + ' director' + (n === 1 ? '' : 's') + ' linked to the company. Now run the person search on each for their numbers.', false);
        setTimeout(function () { setStatus(null); }, 8000);
      } else {
        setStatus('Sent, but response shape was unexpected — check CoreX.', true);
      }
    } catch (e) {
      setStatus('Failed: ' + (e && e.message ? e.message : 'unknown error'), true);
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = 'Capture directors to CoreX'; }
    }
  }

  function injectButton() {
    if (document.getElementById(BUTTON_ID)) return;
    injectStyles();
    const btn = document.createElement('button');
    btn.id = BUTTON_ID;
    btn.type = 'button';
    btn.textContent = 'Capture directors to CoreX';
    btn.addEventListener('click', onCaptureClick);
    document.body.appendChild(btn);
  }

  function removeButton() {
    const btn = document.getElementById(BUTTON_ID);
    if (btn) btn.remove();
    setStatus(null);
  }

  // Button shows only once the directorship table is present on the page.
  function syncButtonToPageState() {
    if (regNoFromUrl() && document.getElementById('directorshipTable')) injectButton();
    else removeButton();
  }

  const mo = new MutationObserver(function () { syncButtonToPageState(); });
  mo.observe(document.documentElement, { childList: true, subtree: true });
  syncButtonToPageState();
})();
