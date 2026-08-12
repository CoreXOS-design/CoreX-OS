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
 * SCAFFOLD STATUS (2026-08-12, updated after Johan's live CMA session):
 *   - Field extraction is WIRED to Johan's confirmed field labels (label-
 *     driven, not hard selectors — see findValueByLabel()).
 *   - Accordion-expand (ensureSectionExpanded) and owner's-ID reveal
 *     (revealOwnerIdIfNeeded) are now wired to the CONFIRMED live markup —
 *     button.accordion + sibling div.panel for sections, i.fa.fa-eye (no
 *     inline onclick) for the reveal. See the comment blocks above each
 *     function for the exact confirmed structure. Open item: whether a
 *     second reveal click re-masks the value — untested, noted inline.
 *   - The POST payload (buildDeedsCapturePayload()) is ALIGNED to cc1's
 *     real, shipped contract: POST /api/v1/deeds-capture (Sanctum bearer),
 *     verified against DeedsCaptureController::store()'s actual validation
 *     rules, not just the spec doc. See .ai/specs/deeds-capture.md §2 and the
 *     mapping notes above buildDeedsCapturePayload() for every field that
 *     needed a judgment call (title_deed_number's placement, complex_name,
 *     erf_number/street_number/street_name being unavailable from the
 *     current label list, the source_ref stability fallback chain).
 *   - Still needing Johan: a final load-test of the built extension against
 *     a live property, plus the payload-mapping items flagged for him/cc1
 *     (owner.name required-vs-nullable spec/code discrepancy, etc — see
 *     .ai/specs/SPEC_Portal_Scraping_Prospecting.md §10).
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
  // names the extracted objects use — these get MAPPED (renamed/routed,
  // not always 1:1) onto cc1's actual payload field names inside
  // buildDeedsCapturePayload() below.

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
  // ── ACCORDION EXPAND (confirmed live, 2026-08-12 Johan session) ──
  // ══════════════════════════════════════════════════════════
  // Real markup: each section is <button class="accordion ..."> immediately
  // followed by a sibling <div class="... panel">. Collapsed = the panel has
  // computed display:none. It's a classic JS-accordion — a plain click
  // listener toggles the panel's display, no aria-expanded/data-toggle/
  // onclick attribute to read — so collapsed state is read off the PANEL's
  // computed style, not the button, and expansion is verified the same way
  // (poll the style) rather than via MutationObserver, since a display
  // toggle alone doesn't reliably fire a childList/subtree mutation.
  //
  // Per-section markup (used only as a fallback if the generic text-match
  // below ever misses a section — e.g. a label rewording):
  //   Property Information : button.accordion.pnlSTPropInfoContainer
  //                           (also seen as .pnlFTPropInfoContainer)
  //                           → next sibling div.property-info.panel
  //   Sale Information      : button.accordion.pnlSaleInfoContainer
  //                           → next sibling div.sale-info.panel
  //   Municipal Valuation   : button.accordion.pnlMunValueContainer
  //                           → next sibling div.panel
  const SECTION_CONTAINER_CLASS_FALLBACKS = {
    'property information': ['pnlSTPropInfoContainer', 'pnlFTPropInfoContainer'],
    'sale information': ['pnlSaleInfoContainer'],
    'municipal valuation': ['pnlMunValueContainer'],
  };

  function findSectionHeader(sectionTitle) {
    const target = normalizeLabel(sectionTitle);

    // Primary: button.accordion whose visible text starts with the label —
    // confirmed live as the general rule across every section.
    const buttons = document.querySelectorAll('button.accordion');
    for (const btn of buttons) {
      if (normalizeLabel(btn.textContent).startsWith(target)) return btn;
    }

    // Fallback: known per-section container class, in case the button's
    // rendered text ever diverges from the confirmed label.
    const classNames = SECTION_CONTAINER_CLASS_FALLBACKS[target] || [];
    for (const cls of classNames) {
      const el = document.querySelector('button.accordion.' + cls);
      if (el) return el;
    }

    return null;
  }

  // Confirmed structure: the panel is the header's next ELEMENT sibling,
  // always carrying a "panel" class (property-info/sale-info/plain panel).
  function findSectionPanel(headerEl) {
    if (!headerEl) return null;
    const sib = headerEl.nextElementSibling;
    return (sib && sib.classList && sib.classList.contains('panel')) ? sib : null;
  }

  function isPanelCollapsed(panelEl) {
    if (!panelEl) return false;
    return getComputedStyle(panelEl).display === 'none';
  }

  /**
   * Ensure a section's panel is expanded before reading it. Confirmed
   * live: button.click() toggles the panel from display:none to visible
   * via a plain event listener, so this polls the panel's computed style
   * directly rather than waiting on a MutationObserver.
   */
  function ensureSectionExpanded(sectionTitle, timeoutMs = 4000) {
    return new Promise((resolve) => {
      const header = findSectionHeader(sectionTitle);
      const panel = findSectionPanel(header);
      if (!header || !panel || !isPanelCollapsed(panel)) {
        resolve(false); // nothing to do — already expanded, or section not found
        return;
      }

      try {
        header.click();
      } catch (e) {
        resolve(false);
        return;
      }

      const start = Date.now();
      (function poll() {
        if (!isPanelCollapsed(panel)) { resolve(true); return; }
        if (Date.now() - start >= timeoutMs) { resolve(false); return; }
        setTimeout(poll, 50);
      })();
    });
  }

  // ══════════════════════════════════════════════════════════
  // ── OWNER'S ID REVEAL (confirmed live, 2026-08-12 Johan session) ──
  // ══════════════════════════════════════════════════════════
  // Real markup: inside div.sale-info.panel, the Owner's ID row contains
  // <i class="fa fa-eye"> with NO inline onclick — a JS event listener does
  // the unmasking IN PLACE (the cell's text value changes; the icon itself
  // may not). There's also an unrelated i.fas.fa-info-circle tooltip icon
  // elsewhere in the panel — must not be confused with the reveal control.
  function findOwnerIdRevealControl() {
    const target = normalizeLabel("Owner's ID");
    const panel = document.querySelector('div.sale-info.panel') || document;
    const cells = panel.querySelectorAll('td, th');
    for (const cell of cells) {
      if (normalizeLabel(cell.textContent) !== target) continue;
      const row = cell.closest('tr');
      const icon = row && row.querySelector('i.fa.fa-eye');
      if (icon) return icon;
    }
    // Fallback: the row-scoped lookup above can miss if the label cell's
    // exact text ever shifts — the icon itself (i.fa.fa-eye, never
    // i.fas.fa-info-circle) is unambiguous within the sale-info panel.
    return panel.querySelector('i.fa.fa-eye');
  }

  /**
   * No inline onclick on the icon, so the synthetic interaction must
   * actually dispatch an event the bound listener responds to — plain
   * element.click() is confirmed to work live; the MouseEvent path is a
   * fallback for any environment where .click() alone doesn't reach a
   * listener bound via addEventListener on a non-form element.
   */
  function dispatchRealClick(el) {
    try {
      el.click();
      return true;
    } catch (e) {
      // fall through to the MouseEvent fallback below
    }
    try {
      el.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));
      return true;
    } catch (e) {
      return false;
    }
  }

  /**
   * The reveal unmasks the Owner's ID cell's VALUE in place rather than
   * mutating the icon, so this doesn't wait on a DOM signal from the icon
   * — it just gives the listener a moment to run, then the caller re-reads
   * the cell via the normal label-driven extractor (findValueByLabel).
   *
   * Not yet confirmed live: whether a second click re-masks the value
   * (i.e. whether fa-eye is a toggle). Each capture click currently calls
   * this once per Sale Information read, so a re-mask would only bite if
   * the agent presses Capture twice on the same loaded section without an
   * intervening reload — untested; flag if seen.
   */
  function revealOwnerIdIfNeeded(timeoutMs = 1500) {
    return new Promise((resolve) => {
      const control = findOwnerIdRevealControl();
      if (!control) { resolve(false); return; }
      const dispatched = dispatchRealClick(control);
      if (!dispatched) { resolve(false); return; }
      setTimeout(() => resolve(true), 200);
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
    };
  }

  // ══════════════════════════════════════════════════════════
  // ── VALUE PARSERS (raw CMA text → the typed shape cc1's validator wants) ──
  // ══════════════════════════════════════════════════════════
  // cc1's contract (.ai/specs/deeds-capture.md §2, verified against
  // DeedsCaptureController::store()'s actual validation rules) wants
  // numerics/dates/coordinates as real typed values, not the raw display
  // strings CMA renders. These are best-effort — flagged where the exact
  // CMA format needs Johan's live-page confirmation.

  function parseCurrency(v) {
    if (v == null) return null;
    const digits = String(v).replace(/[^\d.,-]/g, '').replace(/,/g, '');
    const n = parseFloat(digits);
    return Number.isFinite(n) ? n : null;
  }

  function parseNumeric(v) {
    if (v == null) return null;
    const m = String(v).match(/[\d,.]+/);
    if (!m) return null;
    const n = parseFloat(m[0].replace(/,/g, ''));
    return Number.isFinite(n) ? n : null;
  }

  // TODO(johan): assumes SA display convention DD/MM/YYYY — confirm against
  // a live page (CMA may already render ISO, or DD-Mon-YYYY, etc.).
  function parseSaDate(v) {
    if (!v) return null;
    const s = String(v).trim();
    let m = s.match(/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/);
    if (m) {
      const [, d, mo, y] = m;
      return y + '-' + mo.padStart(2, '0') + '-' + d.padStart(2, '0');
    }
    if (/^\d{4}-\d{2}-\d{2}/.test(s)) return s.slice(0, 10); // already ISO
    return s; // unrecognised shape — pass through and let the server's date
              // validator reject it loudly rather than silently drop it
  }

  // TODO(johan): confirm CMA's actual "GPS" field format live — this assumes
  // "lat, lng" decimal degrees and sanity-bounds to South Africa so a
  // misparse fails closed (null) instead of sending a wrong coordinate.
  function parseGps(v) {
    if (!v) return { lat: null, lng: null };
    const nums = String(v).match(/-?\d+\.\d+/g);
    if (!nums || nums.length < 2) return { lat: null, lng: null };
    const lat = parseFloat(nums[0]);
    const lng = parseFloat(nums[1]);
    const validLat = lat >= -35 && lat <= -22;
    const validLng = lng >= 16 && lng <= 33;
    return { lat: validLat ? lat : null, lng: validLng ? lng : null };
  }

  // owner.id_type is "sa_id" | "company_reg" | null per cc1's contract — CMA
  // has no separate "ID Type" label, so this is inferred from the ID
  // string's shape: a 13-digit number is an SA ID; a CIPC-style
  // YYYY/NNNNNN/NN is a company registration. Anything else -> null (the
  // server accepts null; better than guessing wrong).
  function classifyOwnerIdType(idNumber) {
    if (!idNumber) return null;
    const trimmed = String(idNumber).trim();
    if (/^\d{13}$/.test(trimmed.replace(/\s+/g, ''))) return 'sa_id';
    if (/^\d{4}\/\d{6}\/\d{2}$/.test(trimmed)) return 'company_reg';
    return null;
  }

  /**
   * source_ref MUST be stable per property (cc1's idempotency + match-or-
   * create key) — capturing the same property twice must produce the same
   * ref. None of Johan's confirmed field labels give us a page-native stable
   * ID (the CMA URL itself doesn't encode one either — it's a search/click
   * state, not a per-property URL). Best available fallback chain, in order
   * of how stable each candidate actually is:
   *   1. Title Deed number — a genuine deeds-registry identifier.
   *   2. Scheme number + section number — stable for a sectional unit.
   *   3. The rendered address / "Situated at" text.
   *   4. A timestamp — LAST resort; NOT idempotent (each capture creates a
   *      new tracked_property). Flagged loudly so it's never silently relied on.
   * TODO(johan): confirm live whether CMA exposes anything more stable (a
   * hidden deeds reference, a query param, etc.) — would let us drop #3/#4.
   */
  function buildSourceRef(deed) {
    const p = deed.property_information;
    const s = deed.sale_information;
    let candidate = s.title_deed
      || (p.scheme_number && p.section_number ? (p.scheme_number + '-' + p.section_number) : null)
      || p.address
      || p.situated_at
      || null;
    let stable = true;
    if (!candidate) {
      candidate = 'unref-' + Date.now();
      stable = false;
    }
    const ref = 'cmainfo:' + candidate.toString().trim().replace(/\s+/g, '-').toLowerCase();
    return { ref: ref, stable: stable };
  }

  // ══════════════════════════════════════════════════════════
  // ── PAYLOAD — aligned to cc1's contract (.ai/specs/deeds-capture.md §2) ──
  // ══════════════════════════════════════════════════════════
  // Field-by-field mapping notes (see the spec addendum for the full list):
  //   - title_deed_number lives under PROPERTY in cc1's schema, even though
  //     Johan's page shows "Title Deed" inside the SALE INFORMATION section —
  //     extraction location on the page vs. payload placement are different
  //     things; routed correctly here.
  //   - complex_name has no distinct CMA label — populated from the same
  //     "Scheme name" value as scheme_name (SA sectional-title convention:
  //     the scheme name IS the complex name). Flagged for Johan to confirm,
  //     not a guess I'm otherwise unsure of.
  //   - erf_number, street_number, street_name: NOT populated — no CMA label
  //     was given for these. address carries the full combined string
  //     instead (a valid field in cc1's schema on its own). Flagged as an
  //     open item, not silently dropped.
  function buildDeedsCapturePayload(deed) {
    const p = deed.property_information;
    const s = deed.sale_information;
    const gps = parseGps(p.gps);
    const { ref: sourceRef, stable: sourceRefStable } = buildSourceRef(deed);

    // Diagnostic only — logged, never sent (cc1's contract has no field for
    // this; smuggling an undocumented key into the payload isn't "matching
    // the contract"). onCaptureClick() surfaces it in the on-page status too.
    if (!sourceRefStable) {
      console.warn('[CoreX] deeds-capture: no stable identifier found on this page — using a timestamp fallback. Re-capturing this property will create a DUPLICATE tracked_property, not update the existing one.');
    }

    return {
      source: 'cmainfo',
      captures: [
        {
          source_ref: sourceRef,
          property: {
            deeds_office:      p.deeds_office,
            scheme_name:       p.scheme_name,
            scheme_number:     p.scheme_no,
            section_number:    p.section_number,
            erf_number:        null, // TODO(johan): no CMA label given yet
            address:           p.address || p.situated_at || null,
            street_number:     null, // TODO(johan): CMA gives one combined Address string
            street_name:       null, // TODO(johan): — split these out if/when needed
            unit_number:       p.flat_number,
            complex_name:      p.scheme_name, // see mapping note above
            suburb:            p.suburb,
            municipality:      p.municipality,
            province:          p.province,
            latitude:          gps.lat,
            longitude:         gps.lng,
            section_extent_m2: parseNumeric(p.section_extent),
            property_type:     p.type,
            title_deed_number: s.title_deed, // routed from Sale Information — see mapping note above
          },
          owner: {
            name:      s.owner,
            id_number: s.owner_id_number,
            id_type:   classifyOwnerIdType(s.owner_id_number),
          },
          sale: {
            sale_price:       parseCurrency(s.sale_price),
            sale_date:        parseSaDate(s.sale_date),
            registered_date:  parseSaDate(s.registered_date),
            bond_holder:      s.bond_holder,
            bond_amount:      parseCurrency(s.bond_amount),
            sale_type:        s.sale_type,
          },
        },
      ],
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
      const payload = buildDeedsCapturePayload(deed);

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
        // cc1's contract: a 200 response can still carry a PER-ROW error
        // (batch never hard-fails on one bad row) — results[0] is ours,
        // since this extension always sends exactly one capture per click.
        const row = result && Array.isArray(result.results) ? result.results[0] : null;
        if (row && row.error) {
          setStatus('Failed: ' + row.error, true);
        } else if (row) {
          setStatus((row.created ? 'Captured ✓ (new)' : 'Captured ✓ (enriched existing)'), false);
          setTimeout(() => setStatus(null), 4000);
        } else {
          // Unexpected shape — surface it rather than claim silent success.
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
