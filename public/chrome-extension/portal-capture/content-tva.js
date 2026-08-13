/**
 * CoreX — TVA (The Virtual Agent) Content Script
 *
 * Scoped to app.thevirtualagent.co.za's person page
 * (/Search/Person/{id}?idNumber={id}#tab_1_2) — a KYC lookup tool. The agent
 * pastes an SA ID (copied via the "Copy ID" button on CoreX's Deeds Capture
 * screen), searches, opens the person, and lands on the Contact tab, which
 * renders a grid of phone/email rows (Type / value / Date / Link Date).
 *
 * STATUS (2026-08-13): confirmed working live end-to-end (Jessica Fawcett) —
 * Contact tab grid extraction, red/opted-out detection, and ID-match dedup
 * all verified in Johan's browser. Still open:
 *   - Identity (first name / surname) — CONFIRMED LIVE (2026-08-13): the
 *     page heading "SURNAME, Firstname" ("FAWCETT, JESSICA"), not a
 *     labelled field. See parsePersonHeading()/extractIdentity().
 *   - Opted-out (red) detection — implemented per Johan's confirmed
 *     computed-color values (shared logic with content-cmainfo.js's
 *     isOptedOutStyled), but not yet exercised against a real opted-out row.
 *
 * Spec: .ai/specs/TVA_CONTACT_CAPTURE.md.
 */

(function () {
  'use strict';

  // ══════════════════════════════════════════════════════════
  // ── OPTED-OUT (RED) DETECTION — same rule as content-cmainfo.js ──
  // ══════════════════════════════════════════════════════════
  // Duplicated rather than shared as a module — these content scripts are
  // independent IIFEs per manifest content_scripts entry, matching the
  // existing pattern (content-p24-detail.js / content-pp.js / content-
  // cmainfo.js each carry their own copy of anything this small).
  const OPTED_OUT_REDS = [[231, 61, 74], [215, 27, 41]];
  function isOptedOutStyled(el) {
    if (!el) return false;
    if (el.classList && el.classList.contains('text-danger')) return true;
    const color = getComputedStyle(el).color;
    const m = color && color.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
    if (!m) return false;
    const r = parseInt(m[1], 10), g = parseInt(m[2], 10), b = parseInt(m[3], 10);
    return OPTED_OUT_REDS.some(function (rgb) {
      return Math.abs(r - rgb[0]) <= 6 && Math.abs(g - rgb[1]) <= 6 && Math.abs(b - rgb[2]) <= 6;
    });
  }

  // ══════════════════════════════════════════════════════════
  // ── IDENTITY ────────────────────────────────────────────────
  // ══════════════════════════════════════════════════════════

  // The ID is reliably in the URL — confirmed live (recon: idNumber query
  // param) — no DOM dependency for this one field.
  function idNumberFromUrl() {
    const m = location.search.match(/[?&]idNumber=([^&]+)/);
    return m ? decodeURIComponent(m[1]) : null;
  }

  function normalizeLabel(s) {
    return (s || '').replace(/ /g, ' ').replace(/\s+/g, ' ').trim().replace(/[:\s]+$/, '').toLowerCase();
  }

  function findLabelledValue(labelText) {
    const target = normalizeLabel(labelText);
    const cells = document.querySelectorAll('td, th, dt, label');
    for (const cell of cells) {
      if (normalizeLabel(cell.textContent) !== target) continue;
      let sib = cell.nextElementSibling;
      while (sib) {
        const t = (sib.textContent || '').trim();
        if (t) return t;
        sib = sib.nextElementSibling;
      }
    }
    return null;
  }

  function toTitleCase(s) {
    return s.trim().split(/\s+/).map(function (w) {
      return w.charAt(0).toUpperCase() + w.slice(1).toLowerCase();
    }).join(' ');
  }

  // CONFIRMED LIVE (2026-08-13, Jessica Fawcett): the name isn't a labelled
  // field on the Contact tab at all — it's the page heading, "SURNAME,
  // Firstname" ("FAWCETT, JESSICA"). Present in the DOM even while viewing
  // the Contact tab (the person's tabs share one page, capture never needs
  // to switch tabs). Title-cased on the way in — TVA's all-caps display
  // convention isn't how CoreX stores contact names elsewhere (matches
  // Johan's own "Jessica Fawcett" ground truth, not "JESSICA FAWCETT").
  function parsePersonHeading(text) {
    const m = (text || '').trim().match(/^([A-Za-z][A-Za-z\-'\s]*?),\s*([A-Za-z][A-Za-z\-'\s]*)$/);
    if (!m) return null;
    return { surname: toTitleCase(m[1]), first_name: toTitleCase(m[2]) };
  }

  function extractIdentity() {
    const headings = document.querySelectorAll('h1, h2, h3, h4, .panel-heading, .card-header, .page-header');
    for (const h of headings) {
      const parsed = parsePersonHeading(h.textContent);
      if (parsed) return parsed;
    }

    // Fallback: a labelled field, in case a different page layout has one —
    // TODO(johan): the heading above is confirmed live; this fallback is
    // still best-effort/unconfirmed.
    const firstName = findLabelledValue('First Name') || findLabelledValue('Names') || findLabelledValue('First Names');
    const surname = findLabelledValue('Surname') || findLabelledValue('Last Name');
    return {
      first_name: firstName ? firstName.trim() : null,
      surname: surname ? surname.trim() : null,
    };
  }

  // ══════════════════════════════════════════════════════════
  // ── CONTACT GRID ────────────────────────────────────────────
  // ══════════════════════════════════════════════════════════
  // Header-driven, not a hardcoded selector: find the table whose header row
  // contains "Type", then map Date/Link Date columns by header text too.
  // TODO(johan): confirm this actually locates the Contact-tab grid live —
  // if the grid isn't a real <table>/<thead> (e.g. a div-grid), this needs
  // the real markup to adapt.

  function findContactTable() {
    const tables = document.querySelectorAll('table');
    for (const table of tables) {
      const headerCells = table.querySelectorAll('thead th, thead td');
      for (const h of headerCells) {
        if (normalizeLabel(h.textContent) === 'type') return table;
      }
    }
    return null;
  }

  function headerColumnMap(table) {
    const headerCells = Array.from(table.querySelectorAll('thead th, thead td'));
    const map = {};
    headerCells.forEach(function (cell, i) {
      const label = normalizeLabel(cell.textContent);
      if (label === 'type') map.type = i;
      else if (label === 'date') map.date = i;
      else if (label === 'link date') map.linkDate = i;
      else if (label === 'value' || label === 'number' || label === 'contact') map.value = i;
    });
    return map;
  }

  function classifyType(typeText, rowEl) {
    const t = normalizeLabel(typeText);
    if (t.indexOf('email') !== -1) return 'email';
    if (t.indexOf('cell') !== -1) return 'cell';
    if (t.indexOf('tel') !== -1) return 'tel';
    // Fall back to sniffing the row's own link, if the Type column text is
    // unrecognised (e.g. blank, or a value CoreX hasn't seen yet).
    if (rowEl && rowEl.querySelector('a[href^="mailto:"]')) return 'email';
    if (rowEl && rowEl.querySelector('a[href^="tel:"]')) return 'cell';
    return null;
  }

  // Recon-confirmed live: contact rows render the value as <a href="tel:...">
  // for numbers — prefer that (strips formatting noise), fall back to the
  // cell's own text (covers email rows, and any row without a tel: link).
  function extractRowValue(cell) {
    const link = cell.querySelector('a[href^="tel:"], a[href^="mailto:"]');
    if (link) {
      const href = link.getAttribute('href') || '';
      const stripped = href.replace(/^tel:/, '').replace(/^mailto:/, '');
      return (stripped || link.textContent || '').trim();
    }
    return (cell.textContent || '').trim();
  }

  function parseGridDate(text) {
    const t = (text || '').trim();
    if (!t) return null;
    // TODO(johan): confirm TVA's actual date format live — assumes SA
    // DD/MM/YYYY display, same assumption as the CMA build, same risk.
    const m = t.match(/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/);
    if (m) return m[3] + '-' + m[2].padStart(2, '0') + '-' + m[1].padStart(2, '0');
    if (/^\d{4}-\d{2}-\d{2}/.test(t)) return t.slice(0, 10);
    return null; // unrecognised shape — omit rather than send a wrong date
  }

  function extractContactItems() {
    const table = findContactTable();
    if (!table) return [];
    const cols = headerColumnMap(table);
    if (cols.type === undefined) return [];

    const rows = table.querySelectorAll('tbody tr');
    const items = [];
    rows.forEach(function (row) {
      const cells = row.querySelectorAll('td');
      if (!cells.length) return;

      const typeCell = cells[cols.type];
      const type = classifyType(typeCell ? typeCell.textContent : '', row);
      if (!type) return; // unrecognised row shape — skip, don't guess

      const valueCell = cols.value !== undefined ? cells[cols.value] : row;
      const value = extractRowValue(valueCell || row);
      if (!value) return;

      // Opted-out (red) — Johan's rule: only THIS value is dropped, the
      // person's other non-red values still capture. Checked on the value
      // cell/link itself (the rendered number/email), not the Type cell.
      const valueEl = (valueCell && valueCell.querySelector('a')) || valueCell || row;
      if (isOptedOutStyled(valueEl)) {
        console.warn('[CoreX] tva-capture: value "' + value + '" is styled opted-out (red) — dropped, not sent.');
        return;
      }

      items.push({
        type: type,
        value: value,
        date: cols.date !== undefined ? parseGridDate(cells[cols.date] ? cells[cols.date].textContent : '') : null,
        link_date: cols.linkDate !== undefined ? parseGridDate(cells[cols.linkDate] ? cells[cols.linkDate].textContent : '') : null,
        opted_out: false,
      });
    });
    return items;
  }

  // Whole-person opted-out check — Johan's rule: ID number styled red means
  // do NOT capture the person at all. TODO(johan): confirm where the ID is
  // actually displayed on this page (not just in the URL) — best-effort:
  // find any element whose exact trimmed text equals the URL's ID number.
  function isPersonOptedOut(idNumber) {
    if (!idNumber) return false;
    const candidates = document.querySelectorAll('h1, h2, h3, h4, td, th, span, div');
    for (const el of candidates) {
      if (el.children.length > 0) continue; // leaf nodes only — avoid matching a wrapping container
      if ((el.textContent || '').trim() === idNumber) {
        return isOptedOutStyled(el);
      }
    }
    return false;
  }

  // ══════════════════════════════════════════════════════════
  // ── PAYLOAD ──────────────────────────────────────────────────
  // ══════════════════════════════════════════════════════════

  function buildPayload() {
    const idNumber = idNumberFromUrl();
    if (!idNumber) return { error: 'No ID number found in the page URL — open a person via Find, not this URL directly.' };

    if (isPersonOptedOut(idNumber)) {
      return { error: 'This person is marked opted-out (ID shown in red) — not captured, per compliance policy.' };
    }

    const identity = extractIdentity();
    const items = extractContactItems();
    if (items.length === 0) {
      return { error: 'No contact rows found on this page — make sure you\'re on the Contact tab and it has loaded.' };
    }

    return {
      payload: {
        source: 'tva',
        people: [
          {
            id_number: idNumber,
            first_name: identity.first_name,
            surname: identity.surname,
            // Johan: the consent modal ("Keeping you Compliant") is answered
            // BEFORE this page loads, always "I will obtain consent during
            // my next contact" — nothing on the Contact tab itself carries
            // this signal to re-scrape, so it's recorded as a constant.
            consent_status: 'will_obtain_consent_during_next_contact',
            contacts: items,
          },
        ],
      },
    };
  }

  // ══════════════════════════════════════════════════════════
  // ── CAPTURE BUTTON ──────────────────────────────────────────
  // ══════════════════════════════════════════════════════════

  const BUTTON_ID = 'corex-tva-capture-btn';
  const STATUS_ID = 'corex-tva-capture-status';

  function injectStyles() {
    if (document.getElementById('corex-tva-capture-style')) return;
    const style = document.createElement('style');
    style.id = 'corex-tva-capture-style';
    style.textContent = `
      #${BUTTON_ID} {
        all: initial !important;
        position: fixed !important;
        top: 12px !important;
        right: 12px !important;
        z-index: 2147483647 !important;
        font-family: -apple-system, BlinkMacSystemFont, sans-serif !important;
        font-size: 13px !important;
        font-weight: 600 !important;
        color: #ffffff !important;
        background: #0b2a4a !important;
        border: 1px solid #0ea5e9 !important;
        border-radius: 6px !important;
        padding: 8px 14px !important;
        cursor: pointer !important;
        box-shadow: 0 2px 8px rgba(0,0,0,0.25) !important;
      }
      #${BUTTON_ID}:hover { background: #0ea5e9 !important; }
      #${BUTTON_ID}[disabled] { opacity: 0.6 !important; cursor: not-allowed !important; }
      #${STATUS_ID} {
        all: initial !important;
        position: fixed !important;
        top: 50px !important;
        right: 12px !important;
        z-index: 2147483647 !important;
        font-family: -apple-system, BlinkMacSystemFont, sans-serif !important;
        font-size: 12px !important;
        color: #ffffff !important;
        background: #111827 !important;
        border-radius: 6px !important;
        padding: 6px 10px !important;
        max-width: 280px !important;
      }
    `;
    document.head.appendChild(style);
  }

  function setStatus(text, isError) {
    let el = document.getElementById(STATUS_ID);
    if (!text) { if (el) el.remove(); return; }
    if (!el) {
      el = document.createElement('div');
      el.id = STATUS_ID;
      document.body.appendChild(el);
    }
    el.style.background = isError ? '#7f1d1d' : '#111827';
    el.textContent = text;
  }

  async function onCaptureClick() {
    const btn = document.getElementById(BUTTON_ID);
    if (btn) { btn.disabled = true; btn.textContent = 'Capturing…'; }
    try {
      const built = buildPayload();
      if (built.error) {
        setStatus('Failed: ' + built.error, true);
        return;
      }
      setStatus('Sending to CoreX…', false);
      const result = await chrome.runtime.sendMessage({ action: 'captureTvaContacts', payload: built.payload });
      if (result && result.error) {
        setStatus('Failed: ' + result.error, true);
      } else {
        const row = result && Array.isArray(result.results) ? result.results[0] : null;
        if (row && row.error) {
          setStatus('Failed: ' + row.error, true);
        } else if (row) {
          setStatus('Captured ✓ (' + row.items_count + ' contact value' + (row.items_count === 1 ? '' : 's') + ')', false);
          setTimeout(() => setStatus(null), 4000);
        } else {
          setStatus('Sent, but response shape was unexpected — check CoreX.', true);
        }
      }
    } catch (e) {
      setStatus('Failed: ' + (e && e.message ? e.message : 'unknown error'), true);
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = 'Capture to CoreX'; }
    }
  }

  function injectButton() {
    if (document.getElementById(BUTTON_ID)) return;
    injectStyles();
    const btn = document.createElement('button');
    btn.id = BUTTON_ID;
    btn.type = 'button';
    btn.textContent = 'Capture to CoreX';
    btn.addEventListener('click', onCaptureClick);
    document.body.appendChild(btn);
  }

  function removeButton() {
    const btn = document.getElementById(BUTTON_ID);
    if (btn) btn.remove();
    setStatus(null);
  }

  // ══════════════════════════════════════════════════════════
  // ── LIFECYCLE ────────────────────────────────────────────────
  // ══════════════════════════════════════════════════════════
  // Button shows only once a Contact-tab grid is actually present — no
  // point offering capture on the wrong tab or before the page has loaded.

  function syncButtonToPageState() {
    if (idNumberFromUrl() && findContactTable()) injectButton();
    else removeButton();
  }

  let syncTimer = null;
  function scheduleSync() {
    clearTimeout(syncTimer);
    syncTimer = setTimeout(syncButtonToPageState, 300);
  }

  const pageObserver = new MutationObserver(scheduleSync);
  pageObserver.observe(document.body, { childList: true, subtree: true });
  syncButtonToPageState();

  // ══════════════════════════════════════════════════════════
  // ── MESSAGE HANDLER (popup parity) ──────────────────────────
  // ══════════════════════════════════════════════════════════

  chrome.runtime.onMessage.addListener(function (message, sender, sendResponse) {
    if (message && message.action === 'getPageType') {
      sendResponse({ pageType: findContactTable() ? 'tva-person-contact' : 'tva-other' });
      return true;
    }
    return false;
  });
})();
