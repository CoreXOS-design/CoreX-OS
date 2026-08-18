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
    // CONFIRMED LIVE (2026-08-13, 58 Avenue Svea) — the real label is
    // "Flat/Unit no:", not "Flat number". The old (wrong) search text never
    // matched anything, which was harmless on its own, but it ALSO meant
    // "Flat/Unit no" was missing from knownLabelTexts() — so when the
    // label-cascade guard (findValueByLabel step 3) hit a blank Scheme name
    // on a full-title property, "Flat/Unit no:" (the next label down) wasn't
    // recognised as a known label and slipped through as a fake value.
    ['flat_number',     'Flat/Unit no'],
    // CONFIRMED LIVE (2026-08-13, Mzobe/Vorster captures) — another two real
    // labels that were missing from this list, so the cascade guard didn't
    // recognise them either: a genuinely blank "Street number:" row leaked
    // into unit_number, and a blank "Estate" row leaked into the address.
    // Not consumed into the payload (no confirmed role beyond existing —
    // splitStreetAddress() derives street_number from Address instead,
    // which IS confirmed) — listed here purely so knownLabelTexts() closes
    // this gap. The scopeRoot fix (below) addresses the deeper cause of why
    // these blank rows were being reached via cross-panel matches at all;
    // this stays as defence-in-depth for genuinely-blank same-panel rows.
    ['cma_street_number', 'Street number'],
    ['estate',          'Estate'],
    ['address',         'Address'],
    // CONFIRMED LIVE (2026-08-13) — full-title properties carry Erf no as
    // its own labelled row here, separate from the sectional-title fields.
    ['erf_no',          'Erf no'],
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
  // Guard for findValueByLabel's row-3 fallback (below) — the full set of
  // label strings we search for, built lazily so it can reference the label
  // arrays regardless of their position in the file (both are assigned at
  // module top-level before any extraction actually runs, which only
  // happens on a later button click).
  let _knownLabelsCache = null;
  function knownLabelTexts() {
    if (_knownLabelsCache) return _knownLabelsCache;
    _knownLabelsCache = new Set(
      PROPERTY_INFORMATION_LABELS.concat(SALE_INFORMATION_LABELS).map(function (pair) { return normalizeLabel(pair[1]); })
    );
    return _knownLabelsCache;
  }

  function findValueByLabel(label, scopeRoot) {
    const root = scopeRoot || document;
    const target = normalizeLabel(label);
    const knownLabels = knownLabelTexts();
    const cells = root.querySelectorAll('td, th');

    for (const cell of cells) {
      if (normalizeLabel(cell.textContent) !== target) continue;

      // 1 & 2. Walk forward across sibling cells in the same row, skip
      // blanks AND skip any cell whose text is itself one of our known
      // field labels — a real value coinciding with another field's exact
      // label text is effectively impossible on a deeds page, so this is a
      // safe guard, not a false-reject risk.
      let sib = cell.nextElementSibling;
      while (sib) {
        const t = (sib.textContent || '').replace(/ /g, ' ').trim();
        if (t && !knownLabels.has(normalizeLabel(t))) return t;
        sib = sib.nextElementSibling;
      }

      // 3. Stacked label/value row fallback — ONLY meaningful when the
      // label's own row had no usable sibling cell. CONFIRMED LIVE
      // (2026-08-12, Johan's ASTOVE + 60 Avenue Svea captures): when a
      // property's value cells are genuinely blank, this fallback used to
      // walk into the NEXT label/value row and return THAT ROW'S LABEL TEXT
      // as if it were this field's value — cascading through the whole
      // label list ("scheme_name" -> "Situated at", "suburb" -> "Municipality",
      // "province" -> "GPS", etc, each one shifted exactly one field down),
      // which then promoted into a garbage "Municipality" placeholder
      // property. Same guard as above: if the candidate is itself a known
      // label, the true value is genuinely blank — return null, not a guess.
      const row = cell.closest('tr');
      const nextRow = row ? row.nextElementSibling : null;
      if (nextRow) {
        const firstCell = nextRow.querySelector('td, th');
        if (firstCell) {
          const t = (firstCell.textContent || '').trim();
          if (t && !knownLabels.has(normalizeLabel(t))) return t;
        }
      }
    }
    return null;
  }

  /**
   * Same traversal as findValueByLabel, but returns the matched VALUE
   * ELEMENT instead of its text — needed to inspect computed style (the
   * opted-out red check can't work off text alone). Best-effort: mirrors
   * findValueByLabel's three fallback steps exactly so the two never
   * disagree about which cell holds "the value".
   */
  function findValueElementByLabel(label, scopeRoot) {
    const root = scopeRoot || document;
    const target = normalizeLabel(label);
    const knownLabels = knownLabelTexts();
    const cells = root.querySelectorAll('td, th');

    for (const cell of cells) {
      if (normalizeLabel(cell.textContent) !== target) continue;

      let sib = cell.nextElementSibling;
      while (sib) {
        const t = (sib.textContent || '').replace(/ /g, ' ').trim();
        if (t && !knownLabels.has(normalizeLabel(t))) return sib;
        sib = sib.nextElementSibling;
      }

      const row = cell.closest('tr');
      const nextRow = row ? row.nextElementSibling : null;
      if (nextRow) {
        const firstCell = nextRow.querySelector('td, th');
        if (firstCell) {
          const t = (firstCell.textContent || '').trim();
          if (t && !knownLabels.has(normalizeLabel(t))) return firstCell;
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

  function extractByLabelMap(labelPairs, scopeRoot) {
    const out = {};
    labelPairs.forEach(([key, label]) => {
      try {
        out[key] = findValueByLabel(label, scopeRoot);
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
   * CONFIRMED LIVE (2026-08-12, 60 Avenue Svea, full-title — NOT a
   * sectional/scheme issue): while a panel is collapsed, its label cells
   * exist but the VALUE cells are genuinely EMPTY in the DOM — populated
   * only once the panel expands (an async postback fill-in, not just a CSS
   * toggle). Collapsed: Address value = "". Expanded: Address value =
   * "60 AVENUE SVEA". Polling only the panel's display (as before) resolves
   * the instant the CSS flips, which can race ahead of that population —
   * this checks for actual content, not just visibility. 2-col TR/TD rows,
   * label = cell[0], value = cell[last] (matches findValueByLabel's own
   * shape) — one non-empty value cell anywhere in the panel is enough to
   * call the section "populated"; a genuinely all-blank section (rare) just
   * rides out to the timeout below, same as before.
   */
  function sectionHasPopulatedValues(panelEl) {
    if (!panelEl) return false;
    const rows = panelEl.querySelectorAll('tr');
    for (const row of rows) {
      const cells = row.querySelectorAll('td, th');
      if (cells.length < 2) continue;
      if ((cells[cells.length - 1].textContent || '').trim()) return true;
    }
    return false;
  }

  // ROOT CAUSE (2026-08-17, Johan — SS-then-FH capture, both wrong address;
  // theorised as a timing race, confirmed here): sectionHasPopulatedValues()
  // proves a value cell is non-empty, but NOT that it belongs to the
  // property currently selected on cmainfo's own results list. The page
  // never navigates (WebForms postback swaps content IN PLACE — see
  // isPropertyLoaded()'s comment) — so when the agent selects a NEW property
  // while a section is ALREADY expanded from viewing the PREVIOUS one, the
  // panel is simultaneously "not collapsed" AND "populated" for the ENTIRE
  // window between the click and the postback finishing, because the OLD
  // property's values are still sitting there, not yet overwritten.
  // ensureSectionExpanded()'s fast path (added in bbfb35b3a to fix a
  // DIFFERENT race — collapsed-panel value cells being genuinely blank —
  // does not cover this one) resolves the instant it sees ANY populated
  // value, which is immediately, and hands extraction stale data. A slow
  // second attempt "worked" simply because enough wall-clock time had
  // passed for the postback to finish before the agent clicked Capture.
  //
  // Fix: track the timestamp of the most recent DOM mutation (markDomActivity(),
  // driven by the SAME pageObserver already used for button-visibility sync —
  // see the LIFECYCLE section below) and require the panel to have been
  // BOTH populated AND quiet for DOM_SETTLE_MS before treating its content
  // as safe to read. A property switch keeps mutating the panel for some
  // number of milliseconds after the values first look "populated" (rows
  // update one at a time, not atomically) — waiting for quiet closes that
  // window without needing any cmainfo-specific "which property is this"
  // signal, which the page's own markup never exposes.
  const DOM_SETTLE_MS = 350;
  let lastMutationAt = 0;

  // v3.3.9 (2026-08-18, Johan — Coniston Road / "Skippers of Shelly" incident)
  // — a REAL confirmed miss the settle-check above didn't catch: a full,
  // internally-consistent capture of the WRONG property (every field —
  // address, scheme, section, erf, deed, sale price/date, owner — coherently
  // matched a DIFFERENT, earlier deed). Server-side evidence (source_chain.ref
  // is built client-side from the extracted title deed) proved this was a
  // clean client-side stale read, not a partial bleed the v3.3.8 signature
  // check would catch. Root cause: "quiet for DOM_SETTLE_MS" only proves
  // nothing has changed RECENTLY — it can't distinguish "quiet because the
  // postback finished" from "quiet because the postback hasn't started yet"
  // (e.g. the agent clicked Capture within milliseconds of selecting a new
  // property, before cmainfo's own postback began mutating anything).
  //
  // Investigated whether cmainfo exposes a per-property identifier the
  // extension could read at click-time and poll the panel against (the
  // airtight fix) — confirmed NOT AVAILABLE: buildSourceRef()'s own
  // docblock above already establishes "the CMA URL itself doesn't encode
  // one either — it's a search/click state, not a per-property URL", and
  // there is no click listener on cmainfo's own search-results list to
  // capture a click-time reference from even if one existed (unconfirmed
  // without live access to cmainfo's search-results markup, which this
  // extension has never needed to interact with before).
  //
  // Fallback (2026-08-18): require GENUINE evidence of a fresh update, not
  // just quiet — widen the settle window rather than trust "quiet" alone,
  // giving a slow-starting postback a wider margin.
  //
  // v3.4.2 — this used to widen the window only for the FIRST capture in a
  // page session, and additionally require a mutation observed AFTER the
  // previous capture completed for every capture thereafter (tracked via
  // lastCaptureCompletedAt). That "since the previous capture" branch was
  // itself a form of cross-capture memory, and Johan's explicit instruction
  // for the true-clean-slate fix is that NO module-level state survives
  // between captures (extractDeed() resets its capture-memory variables at
  // the start of every extraction — see the TRUE CLEAN SLATE block below).
  // With nothing left to remember a "previous capture completed at", every
  // capture now unconditionally takes the wider settle window — a small,
  // constant, always-safe cost instead of a per-capture-history fast path.
  const FIRST_CAPTURE_EXTRA_SETTLE_MS = 500;

  function domIsSettled() {
    // No mutation observed yet this page load (lastMutationAt still 0) counts
    // as settled — otherwise a property loaded before the observer's first
    // callback ever fires would wait out the full timeout for nothing.
    if (lastMutationAt === 0) return true;

    return (Date.now() - lastMutationAt) >= (DOM_SETTLE_MS + FIRST_CAPTURE_EXTRA_SETTLE_MS);
  }

  /**
   * Ensure a section's panel is expanded, populated, AND the page has been
   * quiet for DOM_SETTLE_MS before reading it. button.click() toggles the
   * panel from display:none to visible via a plain event listener, but the
   * VALUE cells can still be empty — or stale from whatever property was
   * previously loaded — for a moment after that; this polls for display,
   * real content, and settle-time together, not any one alone.
   */
  function ensureSectionExpanded(sectionTitle, timeoutMs = 4000) {
    return new Promise((resolve) => {
      const header = findSectionHeader(sectionTitle);
      const panel = findSectionPanel(header);
      if (!header || !panel) {
        resolve(false); // section not found
        return;
      }

      let clicked = false;
      if (isPanelCollapsed(panel)) {
        try {
          header.click();
          clicked = true;
        } catch (e) {
          resolve(false);
          return;
        }
      }

      if (!isPanelCollapsed(panel) && sectionHasPopulatedValues(panel) && domIsSettled()) {
        resolve(clicked);
        return;
      }

      const start = Date.now();
      (function poll() {
        if (!isPanelCollapsed(panel) && sectionHasPopulatedValues(panel) && domIsSettled()) { resolve(true); return; }
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
   * mutating the icon (the cell's text changes; the icon itself may not) —
   * so this polls the SAME cell's text for the mask to actually clear
   * rather than trusting a fixed delay. 2026-08-17 (Johan, live cmainfo
   * confirm): cmainfo masks Owner's ID by default ("560728*******") until
   * this click's listener unmasks it; a FIXED 200ms grace period was
   * unreliable — sometimes the unmask hadn't landed yet, and the STILL-
   * MASKED value got captured and stored verbatim (the "stars" bug). Same
   * settle-by-polling shape as domIsSettled() elsewhere in this file,
   * scoped to this one cell instead of the whole panel.
   *
   * A cell holding multiple owners' IDs joined " ; " (multi-owner) is
   * handled naturally — the poll waits for ZERO asterisks anywhere in the
   * cell's text, i.e. every owner's ID in it, not just the first. An
   * opted-out (permanently red/masked) owner sharing a cell with a
   * revealable one means the poll runs out its full timeoutMs before
   * giving up — buildOwnersArray()'s existing opted-out drop and the
   * masked-value fallback below both still apply correctly after that.
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

      const salePanel = findSectionPanel(findSectionHeader('Sale Information'));
      const cell = findValueElementByLabel("Owner's ID", salePanel || undefined);
      if (!cell) { setTimeout(() => resolve(true), 200); return; } // no cell found — fall back to the old fixed wait rather than resolve instantly

      const start = Date.now();
      (function poll() {
        const stillMasked = /\*/.test(cell.textContent || '');
        if (!stillMasked || Date.now() - start >= timeoutMs) { resolve(true); return; }
        setTimeout(poll, 50);
      })();
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

  // Scoped to the section's own panel (2026-08-13 — see the scopeRoot note
  // above findValueByLabel) so a same-named label anywhere ELSE on this
  // search page (another panel, a filter form) can never be matched instead
  // of the panel's own real row.
  function extractPropertyInformation() {
    const panel = findSectionPanel(findSectionHeader('Property Information'));
    return extractByLabelMap(PROPERTY_INFORMATION_LABELS, panel || undefined);
  }

  async function extractSaleInformation() {
    await revealOwnerIdIfNeeded();
    const panel = findSectionPanel(findSectionHeader('Sale Information'));
    return extractByLabelMap(SALE_INFORMATION_LABELS, panel || undefined);
  }

  /**
   * Sectional-title schemes carry one owner per SECTION — capture is
   * per-loaded-section (Johan's call), keyed on whatever "Section number"
   * currently reads. No multi-section aggregation here: the agent clicks
   * through sections on cmainfo's own UI and captures each one via a
   * separate button click, same mental model as re-running the button.
   */
  // ══════════════════════════════════════════════════════════
  // ── TRUE CLEAN SLATE (v3.4.2, 2026-08-18) ──────────────────
  // ══════════════════════════════════════════════════════════
  // Johan: capturing a SECTIONAL (complex) property, then a FREEHOLD, still
  // showed the freehold with the PREVIOUS complex's scheme/section — after
  // BOTH v3.3.9 (settle-timing) and v3.4.0 (byte-identical-to-prior
  // heuristic, below this block until 2026-08-18). Root cause (confirmed):
  // cmainfo's Property Information panel does NOT re-render the
  // sectional-only fields (Scheme name/no, Section number, Flat/Unit no,
  // Section extent, Situated at) when the CURRENT property is a freehold —
  // no new markup means no postback ever touches that row, so whatever
  // sectional property was viewed BEFORE it sits frozen in the live DOM
  // indefinitely, for the rest of the page session, no matter how long the
  // extension waits or how quiet the DOM has been.
  //
  // v3.4.0's fix compared the CURRENT capture's sectional fields against the
  // PREVIOUS capture's (byte-identical + a freehold-native field changed).
  // Insufficient by its own admission: the FIRST capture in a page session
  // has no previous capture to diff against, so leftover DOM content from
  // before the extension was even opened wasn't caught, and it only fired
  // when EVERY sectional field was byte-identical to last time — one field
  // differing defeated the whole check. Both are symptoms of the same flaw:
  // comparing against MEMORY of a previous capture can never be airtight,
  // because the bug is that the DOM lies about the CURRENT property, not
  // that it changed from the previous one.
  //
  // v3.4.2 fix — two parts, per Johan's explicit instruction that every
  // capture must start truly fresh with NO memory of the previous scrape:
  //   1. All module-level capture memory is reset at the START of every
  //      extraction (see the top of extractDeed() below) — nothing from a
  //      prior capture can influence this one, full stop. The old
  //      byte-identical-to-prior heuristic and its NATSPAT title-deed-
  //      mismatch self-heal (propertySignature/signaturesMatchAddress/
  //      lastCaptureSignature/lastCapturedProperty/
  //      clearFrozenSectionalFields, all formerly here) are REMOVED along
  //      with it — both depended on the exact cross-capture memory Johan
  //      wants gone, and the byte-identical heuristic is the one just
  //      proven insufficient. domIsSettled()'s mutation-based settle gate
  //      (a LIVE DOM signal, not a memory of prior extracted values) is
  //      unaffected and still guards slow-loading panels — see its comment
  //      for the one consequence of this removal (no more "quiet since the
  //      previous capture" fast path; every capture now uses the same,
  //      wider, safer settle window).
  //   2. Sectional-vs-freehold is decided from the CURRENT property's OWN
  //      signal — never from a comparison — and a freehold NEVER
  //      legitimately has a scheme/section (that is definitionally what
  //      sectional title means), so the sectional fields are forced empty
  //      UNCONDITIONALLY whenever the current read says freehold. See
  //      currentPropertyLooksFreehold() / nullSectionalFieldsIfFreehold()
  //      below. This is the airtight rule the byte-identical heuristic
  //      lacked: it doesn't matter what the DOM residue says or what the
  //      previous capture was — a freehold's scheme/section is ALWAYS null.
  const SECTIONAL_ONLY_FIELDS = ['scheme_name', 'scheme_no', 'section_number', 'flat_number', 'section_extent', 'situated_at'];

  /**
   * Decide whether the CURRENTLY loaded property is a freehold, from signals
   * confirmed to describe the property presently on screen — never from a
   * comparison to any previous capture.
   *
   * Primary signal: the panel's own "Type" label (extracted as p.type,
   * PROPERTY_INFORMATION_LABELS above) — cmainfo's direct title-type
   * indicator for the property currently loaded. Pattern-matched rather than
   * exact-matched since the live text hasn't been enumerated exhaustively
   * across every deeds office; recognised sectional wording is authoritative
   * one way, recognised freehold/full-title wording the other.
   *
   * Fallback signal (Type blank/unrecognised): "Erf no" — CONFIRMED LIVE
   * (2026-08-13) as a row that exists for full-title properties specifically
   * ("separate from the sectional-title fields" — see
   * PROPERTY_INFORMATION_LABELS above), and one of the fields cmainfo is
   * confirmed (by the v3.4.0 Park Street repro this replaces) to always
   * render fresh for whichever property is CURRENTLY loaded — unlike the
   * sectional-only fields, it is never left frozen. A populated Erf no is
   * reliable evidence the current property is full-title.
   *
   * Residual gap, honestly flagged (same spirit as v3.4.0's own residual-gap
   * notes it replaces): a freehold with neither a recognised Type string nor
   * an Erf no on the panel would slip past both signals and NOT be forced
   * empty. Narrower than Johan's repro (which has both a real address and an
   * Erf no on the freehold), not eliminated.
   */
  function currentPropertyLooksFreehold(property) {
    const type = normalizeLabel(property.type || '');
    if (type) {
      if (type.indexOf('sectional') !== -1) return false;
      if (type.indexOf('freehold') !== -1 || type.indexOf('full title') !== -1 || type.indexOf('full-title') !== -1 || type === 'erf') {
        return true;
      }
    }
    if (property.erf_no) return true;
    return false;
  }

  /**
   * A freehold never legitimately carries a scheme/section — so when
   * currentPropertyLooksFreehold() says freehold, every sectional-only field
   * is forced null UNCONDITIONALLY, regardless of what value is sitting in
   * the DOM for it. This is independent of the previous capture entirely:
   * it would clear the exact same fields whether the previous capture was
   * sectional, freehold, or there was no previous capture at all.
   */
  function nullSectionalFieldsIfFreehold(property) {
    if (!currentPropertyLooksFreehold(property)) return property;
    const cleared = Object.assign({}, property);
    SECTIONAL_ONLY_FIELDS.forEach((key) => { cleared[key] = null; });
    console.warn('[CoreX] deeds-capture: current property looks like a freehold (Type/Erf no signal) — scheme/section/unit/extent/situated-at forced empty regardless of what is sitting in the DOM, since cmainfo does not re-render those rows for a freehold and a freehold never legitimately has them.');
    return cleared;
  }

  async function extractDeed() {
    // v3.4.2 — TRUE clean slate: this function keeps NO module-level
    // capture-memory variable at all any more (see the TRUE CLEAN SLATE
    // block above) — every extraction reads the DOM fresh, top to bottom,
    // with nothing carried over from whatever the previous capture saw.

    await ensureSectionExpanded('Property Information');
    let property = extractPropertyInformation();

    await ensureSectionExpanded('Sale Information');
    const sale = await extractSaleInformation();

    // v3.4.2 — the airtight rule: decide freehold-vs-sectional from THIS
    // property's own current signal and force the sectional fields empty
    // unconditionally when it says freehold. No comparison to any previous
    // capture, no DOM-residue trust.
    property = nullSectionalFieldsIfFreehold(property);

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
    // CIPC company registration (YYYY/NNNNNN/NN) — checked first (has slashes).
    if (/^\d{4}\/\d{6}\/\d{2}$/.test(trimmed)) return 'company_reg';
    // A bare digit string is an SA ID field — even when it is SHORT/INVALID
    // (e.g. 721135198089, 12 digits, CMA ref 0701). It is still a person's ID,
    // NOT a company. Mirrors the server App\Support\OwnerEntityClassifier, which
    // treats a present bare-digit id as a natural-person signal. Previously this
    // required EXACTLY 13 digits, so a short ID fell through to null and the
    // owner was wrongly dropped as a company.
    if (/^\d{6,13}$/.test(trimmed.replace(/\s+/g, ''))) return 'sa_id';
    return null;
  }

  // NOTE (2026-08-14): the former client-side isCompanyLikeOwner() drop was
  // removed. It flagged any owner whose id was not EXACTLY 13 digits as a
  // company and dropped it before sending — which wrongly dropped natural
  // persons with a short/invalid SA ID (721135198089, CMA ref 0701). Owner
  // classification now lives ONLY on the server (App\Support\OwnerEntityClassifier),
  // which also captures genuine entities as entity Contacts. The extension is a
  // dumb scraper: it sends every owner and lets the server decide.

  // ══════════════════════════════════════════════════════════
  // ── MULTI-OWNER SPLITTING + NAME PARSING (2026-08-12) ──────
  // ══════════════════════════════════════════════════════════
  // CMA lists more than one registered owner on some properties, joining both
  // the "Owner" and "Owner's ID" cells with " ; " — e.g.
  //   "ZIETSMAN PHILIPPUS JACOBUS CHRISTOFFEL BUYS ; ZIETSMAN ELIZABETH MARIE"
  //   "5008255027086 ; 5008280066083"
  // Cramming both into one owner object was the bug that blocked capture (the
  // combined ID string blew past the server's per-owner 20-char limit).

  function splitOwnerField(raw) {
    if (!raw) return [];
    return String(raw).split(/\s*;\s*/).map(function (s) { return s.trim(); }).filter(Boolean);
  }

  function titleCase(word) {
    if (!word) return '';
    return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
  }

  // Compound-surname prefix words CMA leaves trailing at the end of the raw
  // string (e.g. "MERWE FRANCOIS PHILLIPUS VAN DER" -> surname "Van Der Merwe").
  // Matched case-insensitively, one token at a time, walking back from the end.
  const SURNAME_PREFIX_WORDS = new Set(['van', 'der', 'den', 'du', 'de', 'le', 'janse']);

  /**
   * CMA's raw owner-name layout is SURNAME-FIRST ("ZIETSMAN PHILIPPUS JACOBUS
   * ..." -> surname "Zietsman", names "Philippus Jacobus ..."), except for a
   * compound surname, where CMA pulls only the LAST word of the surname to
   * the front and leaves the prefix words trailing at the end ("MERWE
   * FRANCOIS PHILLIPUS VAN DER" -> surname "Van Der Merwe", names "Francois
   * Phillipus").
   *
   * Algorithm: tokenize, then walk backward from the last token collecting a
   * run of known prefix words. If that run is non-empty, the surname is
   * (prefix words, in order) + the FIRST token; the first names are whatever
   * tokens are left in between. No trailing prefix run -> the simple case:
   * surname = first token, names = everything else.
   *
   * Returns { surname, first_names, confident }. confident=false (caller
   * falls back to the raw string) when empty, or when the prefix run would
   * consume every token after the first (no base surname word to anchor on)
   * — captured raw rather than guessed, per Johan's instruction.
   */
  // v3.4.1 (2026-08-18, cc1 handoff — HANDOFF-cc5-deeds-name-share-parse-
  // 20260818.md, Johan repro: staging contact 16399 rendered as "50%").
  // cmainfo's "Owner" cell is surname-first with the ownership share
  // appended per owner ("EADY ROGER GRAEME 50%") — there is no separate
  // share column (SALE_INFORMATION_LABELS only has owner/owner_id_number).
  // Left in the token stream, "50%" both (a) pollutes first_names
  // ("Roger Graeme 50%") and (b) defeats the compound-surname prefix
  // walk-back just below — it starts scanning from the LAST token, hits
  // "50%" (not a prefix word), and stops immediately, so "RUIT DOUGLAS
  // PETER VAN DER 50%" resolves to surname="Ruit" instead of "Van Der
  // Ruit". Strip trailing share tokens BEFORE the walk-back runs so both
  // are fixed by the same change. Share is discarded, not stored — the
  // server has no share field today (a possible follow-up if Johan wants
  // it kept structured; never belongs in a name field regardless).
  const OWNERSHIP_SHARE_TOKEN = /^(\d{1,3}([.,]\d+)?%|\d+\/\d+)$/; // 50% 100% 50.00% 1/2 3/4

  function parsePersonName(raw) {
    const tokens = String(raw || '').trim().split(/\s+/).filter(Boolean);
    while (tokens.length > 1 && OWNERSHIP_SHARE_TOKEN.test(tokens[tokens.length - 1])) {
      tokens.pop();
    }
    if (tokens.length === 0) {
      return { surname: null, first_names: null, confident: false };
    }
    if (tokens.length === 1) {
      return { surname: titleCase(tokens[0]), first_names: '', confident: true };
    }

    let prefixCount = 0;
    for (let i = tokens.length - 1; i > 0; i--) {
      if (SURNAME_PREFIX_WORDS.has(tokens[i].toLowerCase())) {
        prefixCount++;
      } else {
        break;
      }
    }

    if (prefixCount >= tokens.length) {
      return { surname: null, first_names: null, confident: false };
    }

    const firstToken = tokens[0];
    const prefixTokens = prefixCount > 0 ? tokens.slice(tokens.length - prefixCount) : [];
    const nameTokens = tokens.slice(1, tokens.length - prefixCount);

    const surname = prefixTokens.concat([firstToken]).map(titleCase).join(' ');
    const firstNames = nameTokens.map(titleCase).join(' ');

    return { surname: surname, first_names: firstNames, confident: true };
  }

  // ══════════════════════════════════════════════════════════
  // ── OPTED-OUT (RED) DETECTION — shared with content-tva.js ──
  // ══════════════════════════════════════════════════════════
  // TVA/CMA render opted-out IDs/values in red. Confirmed live DOM: Bootstrap
  // text-danger, computed color ~rgb(231,61,74) (hover rgb(215,27,41)) vs
  // normal link-blue rgb(51,122,183). Check computed color, not class alone —
  // a themed/renamed class would silently defeat a class-only check; the
  // rendered color is the real signal per Johan's instruction.
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

  /**
   * Owner's-ID opted-out check (2026-08-12, best-effort — TODO(johan):
   * confirm against a live opted-out record, none seen yet). The ID cell can
   * hold multiple owners' IDs joined " ; " in one cell (multi-owner). If CMA
   * wraps each ID in its own child element (a/span), each is checked
   * independently — a per-index array aligned with splitOwnerField()'s
   * output. Without that per-ID structure, the WHOLE cell's styling is the
   * only signal available: a red cell blocks every owner sharing it, which
   * extends "ID number red -> whole person opted out" to "whole row" when
   * CMA doesn't mark up IDs individually within the joined cell.
   */
  function ownerIdOptedOutFlags(count) {
    const salePanel = findSectionPanel(findSectionHeader('Sale Information'));
    const cell = findValueElementByLabel("Owner's ID", salePanel || undefined);
    if (!cell) return new Array(count).fill(false);
    const children = cell.querySelectorAll('a, span');
    if (children.length >= count) {
      const flags = [];
      for (let i = 0; i < count; i++) flags.push(isOptedOutStyled(children[i]));
      return flags;
    }
    const wholeCellOptedOut = isOptedOutStyled(cell);
    return new Array(count).fill(wholeCellOptedOut);
  }

  /**
   * Splits the raw "Owner" / "Owner's ID" cell strings on " ; " and pairs
   * them into owner records — uniform for 1 owner (no separator -> a single-
   * element array) or N. If the two arrays don't line up 1:1 (a malformed
   * page), pairs up to the shorter length and logs a warning rather than
   * guessing a match — losing an ID pairing silently would be worse than
   * dropping the extra row. An owner whose ID is styled opted-out (red) is
   * DROPPED entirely — never sent to CoreX (Johan's safe model).
   */
  function buildOwnersArray(deed) {
    const s = deed.sale_information;
    const names = splitOwnerField(s.owner);
    const ids = splitOwnerField(s.owner_id_number);

    if (names.length !== ids.length) {
      console.warn('[CoreX] deeds-capture: owner name count (' + names.length + ') and owner ID count (' + ids.length + ') do not match — pairing up to the shorter list. Raw owner: "' + s.owner + '", raw IDs: "' + s.owner_id_number + '"');
    }

    const count = Math.max(names.length, ids.length);
    const optedOutFlags = ownerIdOptedOutFlags(count);
    const owners = [];
    const blockedCompanies = [];
    for (let i = 0; i < count; i++) {
      const rawName = names[i] || '';
      const rawId = (ids[i] || '').replace(/\s+/g, '');
      if (rawName === '' && rawId === '') continue;

      if (optedOutFlags[i]) {
        console.warn('[CoreX] deeds-capture: owner "' + rawName + '" ID is styled opted-out (red) — dropped, not sent.');
        continue;
      }

      const idType = classifyOwnerIdType(rawId);
      // Owner classification (natural person vs juristic entity) is decided
      // SERVER-SIDE by App\Support\OwnerEntityClassifier — the extension no
      // longer drops or blocks any owner. It sends every owner (name + id +
      // inferred id_type hint); the server keeps a real person with a short or
      // invalid SA ID (e.g. 721135198089, CMA ref 0701) as a natural person and
      // captures a genuine company/CC/trust as an entity Contact. One decision,
      // made server-side → future rule changes need no extension reinstall.

      const parsed = parsePersonName(rawName);
      if (!parsed.confident && rawName) {
        console.warn('[CoreX] deeds-capture: could not confidently parse owner name "' + rawName + '" into surname/first names — sending the raw string; storage falls back to a naive split.');
      }

      // 2026-08-17 (Johan, live cmainfo confirm — the "stars" bug): if the ID
      // is STILL masked here despite revealOwnerIdIfNeeded()'s poll (reveal
      // control not found, click didn't register, or the reveal genuinely
      // never completes within its timeout), sending the partial masked
      // string ("560728*******") stores a value that LOOKS like real data
      // but isn't — worse than sending nothing, because nothing visibly
      // prompts a human to go complete it. Fall back to null so this reads
      // as "ID not captured, needs manual entry" rather than a plausible-
      // looking wrong ID.
      const isMasked = /\*/.test(rawId);
      if (isMasked) {
        console.warn('[CoreX] deeds-capture: owner "' + rawName + '" ID is still masked after the reveal wait — sending null instead of the partial value; needs manual entry.');
      }

      owners.push({
        name: parsed.confident ? [parsed.first_names, parsed.surname].filter(Boolean).join(' ') : rawName,
        surname: parsed.confident ? parsed.surname : null,
        first_names: parsed.confident ? parsed.first_names : null,
        id_number: isMasked ? null : (rawId || null),
        id_type: isMasked ? null : idType,
      });
    }
    owners.blockedCompanies = blockedCompanies; // smuggled alongside the array — see buildDeedsCapturePayload()
    return owners;
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

  /**
   * Splits the "Address" field into street_number + street_name.
   * CONFIRMED LIVE (2026-08-13, 58 Avenue Svea): TrackedProperty has no raw
   * "address" column at all — canonicalFactsForWrite() on the server
   * silently drops property.address; street_number/street_name are the
   * ONLY storable representation of the street line, and were previously
   * always sent null (nothing split them out), so a full-title property's
   * street never persisted even though "Address" extracted fine. Leading
   * numeric token (optional letter suffix, e.g. "58A") is the street
   * number; the rest is the street name. No leading number -> the whole
   * string is the street name, number left null (never guessed).
   *
   * Deliberately only splits p.address (the real street-address field), not
   * p.situated_at (the sectional-title descriptor, e.g. "Section 4, Ocean
   * View, Shelly Beach") — that text isn't a street line and splitting it
   * the same way would produce wrong data for sectional captures.
   */
  function splitStreetAddress(address) {
    const trimmed = (address || '').trim();
    if (!trimmed) return { number: null, name: null };
    const m = trimmed.match(/^(\d+[A-Za-z]?)\s+(.+)$/);
    if (m) return { number: m[1], name: m[2].trim() };
    return { number: null, name: trimmed };
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
  //   - erf_number: CONFIRMED LIVE (2026-08-13) — extracted from the
  //     "Erf no" label (full-title properties only; blank on sectional).
  //   - street_number/street_name: split from "Address" via
  //     splitStreetAddress() (see above) — was always null before.
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

    const street = splitStreetAddress(p.address);

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
            erf_number:        p.erf_no || null,
            address:           p.address || p.situated_at || null,
            street_number:     street.number,
            street_name:       street.name,
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
          owners: buildOwnersArray(deed), // multi-owner (2026-08-12) — see buildOwnersArray()
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

      // LEGACY no-op (2026-08-14): the client-side company drop was removed —
      // buildOwnersArray() no longer drops any owner, so blockedCompanies is
      // always empty here. Every owner is sent; the server classifies natural
      // vs entity and captures companies as entity Contacts. Kept as a harmless
      // guard in case an older payload shape ever surfaces the field.
      const blockedCompanies = (payload.captures[0].owners && payload.captures[0].owners.blockedCompanies) || [];
      if (blockedCompanies.length > 0) {
        window.alert('Company scraping is not allowed at this time — coming soon.\n\nSkipped: ' + blockedCompanies.join(', '));
      }

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
          // Server-side backstop (2026-08-13) — the client-side check above
          // already filters company owners before sending, but if the server
          // ALSO reports some as blocked (e.g. a stale extension build sent
          // one it shouldn't have), surface that rather than staying silent.
          if (row.blocked_companies && row.blocked_companies.length > 0) {
            window.alert('Company scraping is not allowed at this time — coming soon.\n\nSkipped: ' + row.blocked_companies.join(', '));
          }
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

  // markDomActivity() is also read by ensureSectionExpanded() (see the
  // DOM_SETTLE_MS gate above it) — declared here so both the button-sync
  // debounce and the extraction-readiness gate share ONE "when did the page
  // last change" clock, instead of each guessing independently.
  function markDomActivity() {
    lastMutationAt = Date.now();
    scheduleSync();
  }

  const pageObserver = new MutationObserver(markDomActivity);
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
