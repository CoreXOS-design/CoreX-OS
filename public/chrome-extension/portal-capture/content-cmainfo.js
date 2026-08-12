/**
 * CoreX — CMA Info Content Script (Deeds Capture)
 *
 * Scoped to cmainfo.co.za/Mapping/PropSearch.aspx — an ASP.NET WebForms deeds
 * lookup tool. A user searches/clicks a property; the LEFT PANEL renders
 * accordion sections (Property Information, Sale Information, Municipal
 * Valuation, Servitudes, Accommodation, Renovations — the last four are
 * OUT OF SCOPE for phase 1) as plain HTML <table> label→value rows. There is
 * no JSON API — everything is read from the rendered DOM.
 *
 * SCAFFOLD STATUS (2026-08-12):
 *   - Field extraction is WIRED to Johan's confirmed field labels (label-
 *     driven, not hard selectors — see findValueByLabel()). This should work
 *     against the real page largely as-is.
 *   - Accordion-expand + "view owner's ID" reveal are BEST-EFFORT generic
 *     implementations (no exact selector confirmed yet) — Johan will tune
 *     these against a live page load.
 *   - The POST payload shape (buildDraftPayload()) is a DRAFT — cc1 owns the
 *     real contract for POST /api/deeds-capture. Do not treat field names in
 *     the draft payload as final; only extractPropertyInformation() /
 *     extractSaleInformation()'s OWN keys are meant to be stable, since they
 *     mirror Johan's confirmed label list.
 *
 * Mirrors the existing portal-capture sources' shape (content-p24-detail.js,
 * content-pp.js) as closely as an ASP.NET WebForms accordion tool allows:
 *   - IIFE wrapper, defensive try/catch per field (one bad row never kills
 *     the whole extraction).
 *   - chrome.runtime.onMessage handler for popup-driven use (page type /
 *     manual re-extract), PLUS an on-page injected button, since capture
 *     here is a single-property action a user takes while looking at ONE
 *     loaded property/section — not a paginated bulk-capture loop like
 *     P24/PP search capture. There is no popup step in this flow: the
 *     button messages background.js directly, which owns auth token
 *     handling (chrome.storage.local) and the actual POST — same
 *     separation of concerns as handlePullProperty() in background.js.
 */

(function () {
  'use strict';

  // ══════════════════════════════════════════════════════════
  // ── FIELD LABELS (confirmed by Johan, 2026-08-12) ─────────
  // ══════════════════════════════════════════════════════════
  // Exact label text as shown on the CMA panel. Keys are the snake_case
  // names the extracted objects use — NOT necessarily the final POST
  // payload keys (see buildDraftPayload()).

  const PROPERTY_INFORMATION_LABELS = [
    ['deeds_office',    'Deeds Office'],
    ['scheme_no',       'Scheme no'],
    ['scheme_name',     'Scheme name'],
    ['situated_at',     'Situated at'],
    ['section_number',  'Section number'],
    ['flat_number',     'Flat number'],
    ['address',         'Address'],
    ['suburb',          'Suburb'],
    ['municipality',    'Municipality'],
    ['province',        'Province'],
    ['gps',             'GPS'],
    ['section_extent',  'Section extent'],
    ['type',            'Type'],
    ['usage',           'Usage'],
  ];

  const SALE_INFORMATION_LABELS = [
    ['owner',            'Owner'],
    ['owner_id_number',  "Owner's ID"],
    ['sale_price',       'Sale Price'],
    ['sale_date',        'Sale Date'],
    ['registered_date',  'Registered Date'],
    ['title_deed',       'Title Deed'],
    ['bond_holder',      'Bond Holder'],
    ['bond_amount',      'Bond Amount'],
    ['sale_type',        'Sale Type'],
  ];

  // Municipal Valuation / Servitudes / Accommodation / Renovations —
  // deliberately NOT extracted in phase 1 (Johan's explicit scope call).

  // ══════════════════════════════════════════════════════════
  // ── LABEL-DRIVEN VALUE READER ──────────────────────────────
  // ══════════════════════════════════════════════════════════
  // ASP.NET WebForms renders label→value as two-cell rows across ~112
  // <table>s on the page (per Johan). More robust than hard selectors
  // against markup that can shift per deploy/section. Works against HIDDEN
  // DOM too (textContent reads regardless of CSS display) — covers the case
  // where a collapsed accordion section's table is already rendered, just
  // not visible. If the section hasn't rendered AT ALL yet (true lazy
  // postback), ensureSectionExpanded() must run first — see below.

  function normalizeLabel(s) {
    return (s || '')
      .replace(/ /g, ' ')      // &nbsp;
      .replace(/\s+/g, ' ')
      .trim()
      .replace(/[:\s]+$/, '')       // trailing colon/space
      .toLowerCase();
  }

  /**
   * Find the value adjacent to a label cell. Tries, in order:
   *   1. Same-row next sibling cell(s) — the common two-cell row shape.
   *   2. Same-row LAST cell (some rows pad with empty spacer cells between
   *      label and value — walk forward past empties, this is the
   *      fallback for that).
   *   3. The label's own cell's parent row's next ROW's first cell (rare
   *      "label row, then value row" stacked variant some ASP.NET
   *      accordion generators use).
   *
   * Returns the FIRST matching label found on the page. If a page can show
   * more than one row with the same label (hasn't been seen yet, but the
   * pattern below composes fine if it turns out to be needed — see
   * findAllValuesByLabel for the multi-match variant used by section
   * detection).
   */
  function findValueByLabel(label) {
    const target = normalizeLabel(label);
    const cells = document.querySelectorAll('td, th');

    for (const cell of cells) {
      if (normalizeLabel(cell.textContent) !== target) continue;

      // 1 & 2. Walk forward across sibling cells in the same row, skip blanks.
      let sib = cell.nextElementSibling;
      while (sib) {
        const t = (sib.textContent || '').replace(/ /g, ' ').trim();
        if (t) return t;
        sib = sib.nextElementSibling;
      }

      // 3. Stacked label/value row fallback.
      const row = cell.closest('tr');
      const nextRow = row ? row.nextElementSibling : null;
      if (nextRow) {
        const firstCell = nextRow.querySelector('td, th');
        if (firstCell) {
          const t = (firstCell.textContent || '').trim();
          if (t) return t;
        }
      }
    }
    return null;
  }

  /** All (label, value) row matches — used where a page can repeat a label (e.g. per section). */
  function findAllValuesByLabel(label) {
    const target = normalizeLabel(label);
    const cells = document.querySelectorAll('td, th');
    const values = [];
    for (const cell of cells) {
      if (normalizeLabel(cell.textContent) !== target) continue;
      let sib = cell.nextElementSibling;
      while (sib) {
        const t = (sib.textContent || '').replace(/ /g, ' ').trim();
        if (t) { values.push(t); break; }
        sib = sib.nextElementSibling;
      }
    }
    return values;
  }

  function extractByLabelMap(labelPairs) {
    const out = {};
    labelPairs.forEach(([key, label]) => {
      try {
        out[key] = findValueByLabel(label);
      } catch (e) {
        out[key] = null;
      }
    });
    return out;
  }

  // ══════════════════════════════════════════════════════════
  // ── ACCORDION EXPAND (best-effort — Johan to confirm exact markup) ──
  // ══════════════════════════════════════════════════════════
  // TODO(johan): confirm the real header/toggle element + collapsed-state
  // indicator once we load the extension against a live page together.
  // Built as a real best-effort now rather than a stub: tries a handful of
  // common ASP.NET-accordion patterns, and is a safe no-op (never throws,
  // never double-toggles an already-open section) if none match — in which
  // case findValueByLabel() still works fine IF the section's table is
  // merely display:none rather than genuinely absent from the DOM.
  function findSectionHeader(sectionTitle) {
    const target = normalizeLabel(sectionTitle);
    const candidates = document.querySelectorAll(
      '[class*="accordion"] [class*="header"], [class*="Accordion"] [class*="Header"], ' +
      '[class*="panel-heading"], [class*="collapsible"], a[href="#"], span[onclick], div[onclick]'
    );
    for (const el of candidates) {
      if (normalizeLabel(el.textContent).startsWith(target)) return el;
    }
    return null;
  }

  function looksCollapsed(headerEl) {
    if (!headerEl) return false;
    const aria = headerEl.getAttribute('aria-expanded');
    if (aria === 'false') return true;
    if (aria === 'true') return false;
    // "+" / "−" glyph heuristic — common in ASP.NET accordion generators.
    const text = (headerEl.textContent || '');
    if (/\+/.test(text) && !/[−–-]\s*$/.test(text)) return true;
    const cls = headerEl.className || '';
    if (/collapsed/i.test(cls)) return true;
    if (/expanded|open/i.test(cls)) return false;
    return false; // default assumption: already rendered/expanded
  }

  /**
   * Ensure a section's data is in the DOM before reading it. Waits for a
   * MutationObserver signal (ASP.NET UpdatePanel partial postback swaps DOM
   * nodes async) with a hard timeout fallback so a click that doesn't
   * trigger a postback (data was already there) never hangs the capture.
   */
  function ensureSectionExpanded(sectionTitle, timeoutMs = 4000) {
    return new Promise((resolve) => {
      const header = findSectionHeader(sectionTitle);
      if (!header || !looksCollapsed(header)) {
        resolve(false); // nothing to do — already expanded or not found
        return;
      }

      const observer = new MutationObserver(() => {
        clearTimeout(timer);
        observer.disconnect();
        // Small settle delay — ASP.NET UpdatePanel swaps can fire multiple
        // mutation bursts as it rebuilds the section.
        setTimeout(() => resolve(true), 150);
      });
      observer.observe(document.body, { childList: true, subtree: true });

      const timer = setTimeout(() => {
        observer.disconnect();
        resolve(false);
      }, timeoutMs);

      try {
        header.click();
      } catch (e) {
        clearTimeout(timer);
        observer.disconnect();
        resolve(false);
      }
    });
  }

  // ══════════════════════════════════════════════════════════
  // ── OWNER'S ID REVEAL (best-effort — Johan to confirm exact control) ──
  // ══════════════════════════════════════════════════════════
  // TODO(johan): confirm the real "view owner's ID" control. Heuristic:
  // find the Owner's ID label cell, then look for a clickable element
  // (link/button/span with onclick) within that same row whose text
  // suggests a reveal action ("view", "show", "reveal").
  function findOwnerIdRevealControl() {
    const target = normalizeLabel("Owner's ID");
    const cells = document.querySelectorAll('td, th');
    for (const cell of cells) {
      if (normalizeLabel(cell.textContent) !== target) continue;
      const row = cell.closest('tr');
      if (!row) continue;
      const clickable = row.querySelectorAll('a, button, span[onclick], [role="button"]');
      for (const el of clickable) {
        const t = (el.textContent || '').toLowerCase();
        if (/view|show|reveal/.test(t)) return el;
      }
    }
    return null;
  }

  function revealOwnerIdIfNeeded(timeoutMs = 3000) {
    return new Promise((resolve) => {
      const control = findOwnerIdRevealControl();
      if (!control) { resolve(false); return; }

      const observer = new MutationObserver(() => {
        clearTimeout(timer);
        observer.disconnect();
        setTimeout(() => resolve(true), 150);
      });
      observer.observe(document.body, { childList: true, subtree: true });

      const timer = setTimeout(() => { observer.disconnect(); resolve(false); }, timeoutMs);

      try {
        control.click();
      } catch (e) {
        clearTimeout(timer);
        observer.disconnect();
        resolve(false);
      }
    });
  }

  // ══════════════════════════════════════════════════════════
  // ── PROPERTY-LOADED DETECTION ──────────────────────────────
  // ══════════════════════════════════════════════════════════
  // The page itself never navigates (WebForms postback model) — a "loaded
  // property" is a DOM state, not a URL. Heuristic: the Property Information
  // panel has rendered an Address (or Situated at, for sectional schemes
  // with no street address) value. Re-checked on a MutationObserver so the
  // injected button appears/updates without a page reload as the agent
  // clicks through search results.
  function isPropertyLoaded() {
    const address = findValueByLabel('Address');
    const situatedAt = findValueByLabel('Situated at');
    return !!(address || situatedAt);
  }

  // ══════════════════════════════════════════════════════════
  // ── EXTRACTION ──────────────────────────────────────────────
  // ══════════════════════════════════════════════════════════

  function extractPropertyInformation() {
    return extractByLabelMap(PROPERTY_INFORMATION_LABELS);
  }

  async function extractSaleInformation() {
    await revealOwnerIdIfNeeded();
    return extractByLabelMap(SALE_INFORMATION_LABELS);
  }

  /**
   * Sectional-title schemes carry one owner per SECTION — capture is
   * per-loaded-section (Johan's call), keyed on whatever "Section number"
   * currently reads. No multi-section aggregation here: the agent clicks
   * through sections on cmainfo's own UI and captures each one via a
   * separate button click, same mental model as re-running the button.
   */
  async function extractDeed() {
    await ensureSectionExpanded('Property Information');
    const property = extractPropertyInformation();

    await ensureSectionExpanded('Sale Information');
    const sale = await extractSaleInformation();

    return {
      property_information: property,
      sale_information: sale,
      is_sectional: !!property.section_number,
      captured_url: window.location.href,
      captured_at: new Date().toISOString(),
    };
  }

  // ══════════════════════════════════════════════════════════
  // ── DRAFT PAYLOAD (cc1 owns the real contract — see file header) ──
  // ══════════════════════════════════════════════════════════
  function buildDraftPayload(deed) {
    return {
      source: 'cmainfo',
      // TODO(cc1): replace with the confirmed POST /api/deeds-capture
      // contract. This is a reasonable placeholder shape only —
      // property_information/sale_information's OWN keys (see the label
      // maps above) are the stable part; how they nest/rename under the
      // top-level payload is cc1's call.
      deed: deed,
    };
  }

  // ══════════════════════════════════════════════════════════
  // ── CAPTURE BUTTON (on-page — this source has no popup step) ──
  // ══════════════════════════════════════════════════════════

  const BUTTON_ID = 'corex-deeds-capture-btn';
  const STATUS_ID = 'corex-deeds-capture-status';

  function injectStyles() {
    if (document.getElementById('corex-deeds-capture-style')) return;
    const style = document.createElement('style');
    style.id = 'corex-deeds-capture-style';
    // Scoped, high-specificity, !important — a third-party ASP.NET page's
    // own stylesheet must never be able to hide/reshape this.
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
    el.textContent = text;
    el.style.background = isError ? '#7f1d1d' : '#111827';
  }

  async function onCaptureClick() {
    const btn = document.getElementById(BUTTON_ID);
    if (btn) { btn.disabled = true; btn.textContent = 'Capturing…'; }
    setStatus('Reading property + sale information…', false);

    try {
      const deed = await extractDeed();
      const payload = buildDraftPayload(deed);

      setStatus('Sending to CoreX…', false);

      // No popup step in this flow — background.js reads apiUrl/apiToken
      // from chrome.storage.local itself (same source of truth as every
      // other flow, just self-served instead of relayed from the popup).
      const result = await chrome.runtime.sendMessage({
        action: 'captureDeed',
        payload: payload,
      });

      if (result && result.error) {
        setStatus('Failed: ' + result.error, true);
      } else {
        setStatus('Captured ✓', false);
        setTimeout(() => setStatus(null), 4000);
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
  // ── LIFECYCLE — react to WebForms postback DOM swaps ───────
  // ══════════════════════════════════════════════════════════

  function syncButtonToPageState() {
    if (isPropertyLoaded()) injectButton();
    else removeButton();
  }

  // Debounced observer — a postback can fire many mutations in a burst;
  // only re-check once things settle.
  let syncTimer = null;
  function scheduleSync() {
    clearTimeout(syncTimer);
    syncTimer = setTimeout(syncButtonToPageState, 300);
  }

  const pageObserver = new MutationObserver(scheduleSync);
  pageObserver.observe(document.body, { childList: true, subtree: true });
  syncButtonToPageState();

  // ══════════════════════════════════════════════════════════
  // ── MESSAGE HANDLER (popup parity — same shape as other sources) ──
  // ══════════════════════════════════════════════════════════

  chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
    if (msg.action === 'getPageType') {
      sendResponse({ isDeedsPage: true, isPropertyLoaded: isPropertyLoaded(), url: window.location.href });
      return true;
    }

    if (msg.action === 'getDeedDetail') {
      if (!isPropertyLoaded()) {
        sendResponse({ error: 'No property loaded on this page' });
        return true;
      }
      extractDeed().then((deed) => sendResponse({ deed: deed })).catch((e) => sendResponse({ error: e.message }));
      return true; // async
    }

    return false;
  });
})();
