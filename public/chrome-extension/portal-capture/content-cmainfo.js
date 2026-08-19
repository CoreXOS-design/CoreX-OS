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
    // v3.4.5 (2026-08-19, Johan — architectural rebuild after 3.4.4's
    // per-field heuristics still failed live SS<->FH captures). LPI Code
    // (e.g. "N0ET03630000063200000") is cmainfo's own unique per-property
    // identifier — present on BOTH sectional and freehold properties, unlike
    // Erf no (freehold-only) or Scheme no (sectional-only). It is now the
    // authoritative signal for "which property is this extraction actually
    // reading", replacing the Type-text/Erf-no guessing this file relied on
    // through 3.4.4 — see waitForPanelIdentityStable() and extractDeed()'s
    // LPI-transition gate below.
    ['lpi_code',        'LPI Code'],
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
    // v3.6.1 (2026-08-19, Johan's explicit priority) — freehold-only, the ERF
    // SIZE (spec §6.4: "Freehold Extent = the ERF SIZE. Carries through to
    // the property record's erf-size field."). This is the measurement
    // buildDeedsCapturePayload() actually sends via section_extent_m2 for a
    // freehold capture now — see its own mapping note.
    ['erf_extent_raw',  'Extent'],
    // v3.5.0 — freehold-only (spec §6.1/§6.4: "Cadastral extent" is a DIFFERENT
    // measurement from sectional "Section extent" AND from "Extent" (erf
    // size) above, and must never share storage with either — extracted for
    // a possible future dedicated field only; not sent today, see
    // buildDeedsCapturePayload()'s mapping note).
    ['cadastral_extent_raw', 'Cadastral extent'],
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

  // v3.6.0 (2026-08-19) — ROOT CAUSE FOUND (Johan, live DOM dump on
  // SEESKULP section 1 -> Erf 668 MARINE DRIVE): cmainfo does NOT render one
  // shared set of cells that get mutated in place per property. It keeps
  // MULTIPLE property-type templates in the DOM SIMULTANEOUSLY (at least a
  // sectional one and a freehold one — "assume more than two templates
  // exist", per a third, empty, permanently-hidden GPS cell also observed)
  // and toggles which one is visible. The HIDDEN template keeps whatever it
  // last held — the LAST time a property OF ITS TYPE was viewed — forever,
  // with no further mutation, ever. This is not a timing problem and never
  // was: "18 Lilliecrona Drive" arriving on a "39 Bairn Street" capture, and
  // the false "not a recognisable property" refusal on a plainly-loaded Erf
  // 668, are the SAME bug — a label match against a stale, permanently
  // hidden copy of the row, not a mutation the code needed to wait for.
  //
  // Every prior mitigation in this file's history (DOM_SETTLE_MS, the
  // mutation observer, the v3.4.5 LPI-transition freshness gate, the v3.5.0
  // per-field freshness-proof mechanism) was solving a DIFFERENT, wrong
  // model of the bug — "the right cell hasn't been written yet" — when the
  // actual shape was "there are two cells and we're reading the wrong one,
  // and no amount of waiting ever fixes that." See the "RETIRED" note
  // further down for exactly what that made obsolete.
  //
  // The fix: ONE canonical lookup, visible-scoped by default. offsetParent
  // is null when an element (or ANY ancestor) has display:none — checking
  // it in one property read accounts for the WHOLE ancestor chain in one
  // step, unlike a single getComputedStyle() call on the element alone,
  // which would miss a HIDDEN ANCESTOR CONTAINER (exactly cmainfo's
  // per-type template toggle: the whole sectional/freehold sub-container is
  // display:none, not each cell inside it individually).
  //
  // requireVisible defaults to true and MUST stay true for every caller that
  // extracts data that gets sent. The one narrow, deliberate exception is
  // isPropertyLoaded() (requireVisible:false) — it only asks "is ANY
  // property loaded at all, so the Capture button should show", a coarse
  // presence check that doesn't care which template answered, and which
  // must still work while the accordion section itself is collapsed (a
  // DIFFERENT, legitimate display:none — see ensureSectionExpanded()) and
  // its own content hasn't been read for real yet.
  function isVisible(el) {
    return !!(el && el.offsetParent !== null);
  }

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
   * cmainfo can render the SAME label more than once (the multi-template
   * toggle — see the v3.6.0 note above isVisible()) — every candidate this
   * function would otherwise accept is additionally required to be VISIBLE
   * (requireVisible, default true) before it's returned. An invisible
   * candidate is skipped, not returned — the search keeps going, including
   * all the way to a LATER label cell elsewhere in the DOM (the visible
   * template's own copy of this same label). This is the ONE canonical
   * label lookup in this file for text values; findValueElementByLabel
   * below is its element-returning twin. No caller queries the document
   * directly for a label.
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

  function findValueByLabel(label, scopeRoot, opts) {
    const requireVisible = !opts || opts.requireVisible !== false;
    const root = scopeRoot || document;
    const target = normalizeLabel(label);
    const knownLabels = knownLabelTexts();
    const cells = root.querySelectorAll('td, th');

    for (const cell of cells) {
      if (normalizeLabel(cell.textContent) !== target) continue;

      let sib = cell.nextElementSibling;
      while (sib) {
        const t = (sib.textContent || '').replace(/\u00a0/g, ' ').trim();
        if (t && !knownLabels.has(normalizeLabel(t)) && (!requireVisible || isVisible(sib))) return t;
        sib = sib.nextElementSibling;
      }

      const row = cell.closest('tr');
      const nextRow = row ? row.nextElementSibling : null;
      if (nextRow) {
        const firstCell = nextRow.querySelector('td, th');
        if (firstCell) {
          const t = (firstCell.textContent || '').trim();
          if (t && !knownLabels.has(normalizeLabel(t)) && (!requireVisible || isVisible(firstCell))) return t;
        }
      }
      // No visible candidate on THIS label cell — keep scanning the outer
      // loop for another label cell matching the same target elsewhere in
      // the DOM (the visible template's own copy).
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
  function findValueElementByLabel(label, scopeRoot, opts) {
    const requireVisible = !opts || opts.requireVisible !== false;
    const root = scopeRoot || document;
    const target = normalizeLabel(label);
    const knownLabels = knownLabelTexts();
    const cells = root.querySelectorAll('td, th');

    for (const cell of cells) {
      if (normalizeLabel(cell.textContent) !== target) continue;

      let sib = cell.nextElementSibling;
      while (sib) {
        const t = (sib.textContent || '').replace(/\u00a0/g, ' ').trim();
        if (t && !knownLabels.has(normalizeLabel(t)) && (!requireVisible || isVisible(sib))) return sib;
        sib = sib.nextElementSibling;
      }

      const row = cell.closest('tr');
      const nextRow = row ? row.nextElementSibling : null;
      if (nextRow) {
        const firstCell = nextRow.querySelector('td, th');
        if (firstCell) {
          const t = (firstCell.textContent || '').trim();
          if (t && !knownLabels.has(normalizeLabel(t)) && (!requireVisible || isVisible(firstCell))) return firstCell;
        }
      }
    }
    return null;
  }

  

  // v3.6.1 (2026-08-19, live repro — Johan): cmainfo renders an EMPTY field
  // as a literal placeholder character/word ("-", "N/A") rather than a truly
  // blank cell — confirmed on Erf 668's "Type" ("-"), and the same "-"
  // placeholder was already sitting in real captured Bond Holder data on
  // QA1. findValueByLabel() has no way to tell "genuinely says dash" from
  // "empty" apart, so every extracted value is normalised HERE, at the one
  // shared point every field passes through — a placeholder is not data; a
  // literal "-" stored on a property record defeats any future is-null
  // check and looks like real data to an agent, which is worse than an
  // honest blank.
  function normalizeBlankish(v) {
    if (v == null) return null;
    const trimmed = String(v).trim();
    if (trimmed === '' || trimmed === '-' || /^n\/a$/i.test(trimmed)) return null;
    return v;
  }

  function extractByLabelMap(labelPairs, scopeRoot) {
    const out = {};
    labelPairs.forEach(([key, label]) => {
      try {
        out[key] = normalizeBlankish(findValueByLabel(label, scopeRoot));
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
  // Fallback: require GENUINE evidence of a fresh update, not just quiet.
  //   - 2nd+ capture in a session: require at least one mutation observed
  //     AFTER the previous capture completed, in addition to the existing
  //     quiet-350ms check — "quiet" must mean "quiet after a real update",
  //     not "nothing has happened yet". Closes the exact failure mode above
  //     for every capture after the first.
  //   - 1st capture in a session: no previous capture to require a mutation
  //     against, so there's nothing to gate on — widen the settle window
  //     instead, giving a slow-starting postback a wider margin.
  //
  // v3.4.2 REGRESSION (2026-08-19, live-reported by Johan): the v3.4.2
  // clean-slate rewrite dropped the "mutation observed AFTER the previous
  // capture completed" branch along with the OTHER module-level state it was
  // (correctly) removing — reasoning that ALL cross-capture memory was the
  // same kind of thing the address-bleed fix needed gone. It isn't. The
  // address-bleed bug (v3.4.0/v3.4.2) was caused by remembering the PREVIOUS
  // CAPTURE'S EXTRACTED VALUES (scheme_name, section_number, ...) and either
  // comparing against or carrying them forward — THAT memory is gone for
  // good (see nullSectionalFieldsIfFreehold() below, which needs none of
  // it). lastCaptureCompletedAt never held a captured VALUE — only a
  // TIMESTAMP of when extraction last finished, used purely to require
  // genuine NEW DOM activity before trusting "quiet" again. Removing it
  // left domIsSettled() as a pure elapsed-time check with no requirement
  // that the CURRENT property's postback had mutated anything at all: if
  // the agent clicked Capture on a 2nd property soon after selecting it —
  // before cmainfo's postback had started mutating the panel — but MORE
  // than 850ms had passed since whatever the LAST mutation on the page was
  // (e.g. selecting the FIRST property), domIsSettled() reported "settled"
  // immediately and extraction read the 1st property's STILL-FROZEN Sale
  // Information (crucially, its title_deed) for what was supposed to be the
  // 2nd, distinct capture. Since buildSourceRef() keys off title_deed, the
  // 2nd capture's payload got the SAME source_ref as the 1st, so the server
  // exact-matched it (TrackedPropertyMatchOrCreateService strategy 1) to the
  // 1st capture's TrackedProperty and enriched it instead of creating a new
  // one — capture_kind (which gates the Deeds Capture list) is only set on
  // CREATE, so the 2nd property never appeared, even though the extension
  // reported "Captured ✓ (enriched existing)" — a success message that LOOKS
  // like it worked. Restored: lastCaptureCompletedAt is a TIMING signal, not
  // a VALUE, and never caused the address-bleed bug — keeping it is safe.
  const FIRST_CAPTURE_EXTRA_SETTLE_MS = 500;
  let lastCaptureCompletedAt = 0;

  function domIsSettled() {
    // No mutation observed yet this page load (lastMutationAt still 0) counts
    // as settled — otherwise a property loaded before the observer's first
    // callback ever fires would wait out the full timeout for nothing.
    if (lastMutationAt === 0) return true;

    const requiredQuietMs = lastCaptureCompletedAt === 0
      ? (DOM_SETTLE_MS + FIRST_CAPTURE_EXTRA_SETTLE_MS)
      : DOM_SETTLE_MS;
    if ((Date.now() - lastMutationAt) < requiredQuietMs) return false;

    // 2nd+ capture: refuse to trust a panel that hasn't mutated AT ALL since
    // the previous capture completed — that's the exact "nothing has
    // happened yet" false-positive, not a real settle.
    if (lastCaptureCompletedAt > 0 && lastMutationAt <= lastCaptureCompletedAt) return false;

    return true;
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
  /**
   * v3.6.2 (2026-08-19, spec .ai/specs/deeds-capture.md §7.2 — "fix/confirm
   * revealOwnerIdIfNeeded() unmasks every owner-ID position, not just the
   * first"): SEESKULP section 4's Owner's ID cell holds TEN semicolon-joined
   * entries in one cell. Whether cmainfo exposes one reveal icon per cell or
   * one per position is unconfirmed live, so this returns EVERY visible
   * fa-eye icon found for the row/panel, not just the first — clicking all
   * of them is a safe superset regardless of which markup shape is real (a
   * single-icon-per-cell page just gets one real click plus harmless no-op
   * attempts on nothing else found).
   */
  function findOwnerIdRevealControls() {
    const target = normalizeLabel("Owner's ID");
    const panel = document.querySelector('div.sale-info.panel') || document;
    const cells = panel.querySelectorAll('td, th');
    const found = [];
    for (const cell of cells) {
      if (normalizeLabel(cell.textContent) !== target) continue;
      const row = cell.closest('tr');
      if (!row) continue;
      // v3.6.0 — cmainfo can render this row's label more than once (the
      // multi-template toggle); only a VISIBLE icon belongs to the property
      // actually on screen.
      row.querySelectorAll('i.fa.fa-eye').forEach((icon) => {
        if (isVisible(icon)) found.push(icon);
      });
    }
    if (found.length) return found;
    // Fallback: the row-scoped lookup above can miss if the label cell's
    // exact text ever shifts — search every fa-eye icon in the panel
    // directly (i.fa.fa-eye, never i.fas.fa-info-circle).
    const icons = panel.querySelectorAll('i.fa.fa-eye');
    for (const icon of icons) {
      if (isVisible(icon)) found.push(icon);
    }
    return found;
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
   *
   * v3.6.2 — clicks EVERY reveal control found (see
   * findOwnerIdRevealControls()' docblock), not just one, then polls the
   * WHOLE cell for zero asterisks as before. Per spec §7.2: this is a
   * best-effort attempt, not a hard requirement — a non-maskable entry
   * ("IT 1203/91", a trust registration number sitting in the same list as
   * SA IDs) will never lose its own non-asterisk shape and is not a failure;
   * an entry that's still masked when the timeout elapses is sent as-is,
   * masked, verbatim, in the new raw path (buildDeedsCapturePayload()) — the
   * server fails closed on ownership in that case, per spec, not this file.
   */
  function revealOwnerIdIfNeeded(timeoutMs = 1500) {
    return new Promise((resolve) => {
      const controls = findOwnerIdRevealControls();
      if (!controls.length) { resolve(false); return; }
      let dispatchedAny = false;
      controls.forEach((control) => { if (dispatchRealClick(control)) dispatchedAny = true; });
      if (!dispatchedAny) { resolve(false); return; }

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
    const address = findValueByLabel('Address', null, { requireVisible: false });
    const situatedAt = findValueByLabel('Situated at', null, { requireVisible: false });
    return !!(address || situatedAt);
  }

  // ══════════════════════════════════════════════════════════
  // ── EXTRACTION ──────────────────────────────────────────────
  // ══════════════════════════════════════════════════════════

  // v3.4.4 (2026-08-19, Johan — live repro: 62 Bairn Street captured with
  // Address = "20 Lilliecrona Drive", the PREVIOUS capture's address). Fixed
  // by polling the Address cell alone for two consecutive identical reads —
  // but 3.4.4 shipped and Johan's REAL in-page SS<->FH loop still bled
  // history: address AND type/scheme could still carry over, because
  // address-only stability proves nothing about the REST of the panel, and
  // the whole-panel domIsSettled()/sectionHasPopulatedValues() gate (see
  // above) only proves SOME row changed and things went quiet — never that
  // the fields that actually IDENTIFY the current property are mutually
  // consistent.
  //
  // v3.4.5 — architectural rebuild. Generalizes the same poll-until-stable
  // shape (from waitForAddressStable(), which this replaces) across a
  // COMPOSITE identity signature — LPI Code, Address, Erf no — instead of
  // Address alone. cmainfo's LPI Code (e.g. "N0ET03630000063200000") is
  // confirmed to exist on BOTH sectional and freehold properties and is the
  // authoritative "which property is this" marker Johan identified; Address
  // and Erf no are the two OTHER fields already confirmed (by the 3.4.0 Park
  // Street repro and this file's own history) to be attempted-fresh for
  // whichever property is CURRENTLY loaded, regardless of title type —
  // exactly matching Johan's own point 2: "the address you read and the LPI
  // belong to the SAME currently-shown property". Requiring all three to
  // read identically on two consecutive polls closes the exact gap
  // address-only stability had: if the postback lands its fields in a burst
  // (LPI+Erf first, Address trailing, or any other order), the composite
  // signature keeps changing until every one of them has actually landed —
  // not just the one field 3.4.4 happened to have been burned by.
  //
  // Deliberately EXCLUDES Scheme/Section/Situated-at (SECTIONAL_ONLY_FIELDS
  // below) — two independent reasons, not one:
  //   1. They're CONFIRMED to sometimes never re-render at all for the rest
  //      of the page's life once a freehold is loaded (no new markup, no
  //      mutation, ever — see the TRUE CLEAN SLATE history below), so
  //      waiting for them to "stabilize" would either false-negative forever
  //      (blocking every freehold capture) or trivially "stabilize" on
  //      frozen residue (proving nothing about freshness).
  //   2. Circularity: extractDeed()'s LPI-transition gate below decides
  //      whether to TRUST these fields by comparing a baseline snapshot
  //      (taken right after this stability poll resolves) against a LATER
  //      read, watching for a genuine change. If Situated-at were ALSO part
  //      of what this poll waits to stabilize, the poll itself would already
  //      have absorbed any fresh scheme-row update before the gate ever took
  //      its baseline — making the gate's "did it change AFTER my baseline"
  //      check always see "no", even when the row genuinely did just update.
  // Their trustworthiness is entirely the LPI-transition freshness gate's
  // job (extractDeed() below), kept strictly separate from this poll.
  const IDENTITY_STABILITY_POLL_MS = 120;
  const IDENTITY_STABILITY_TIMEOUT_MS = 2000;
  // v3.5.0 — widened to cover BOTH property types' own identity rows
  // (spec §6.1: a sectional panel has no LPI Code/Erf no at all; a freehold
  // panel has no Scheme no/Section number at all). Watching all four (plus
  // Address) means whichever pair is actually rendered still drives this
  // stability poll — for a freehold panel, Scheme no/Section number just read
  // blank throughout, which is harmless (a constant blank component never
  // blocks stability). This poll only decides WHEN it's safe to take the
  // "official" read; it is NOT what decides whether that read is trustworthy
  // — that is extractDeed()'s type-coherence + freshness-gate below.
  const IDENTITY_SIGNAL_LABELS = ['LPI Code', 'Erf no', 'Scheme no', 'Section number', 'Address'];

  function identitySignatureOf(panelEl) {
    return IDENTITY_SIGNAL_LABELS.map((label) => findValueByLabel(label, panelEl) || '').join('␟');
  }

  function waitForPanelIdentityStable(panelEl) {
    return new Promise((resolve) => {
      if (!panelEl) { resolve(); return; }
      const start = Date.now();
      let previous = identitySignatureOf(panelEl);
      (function poll() {
        if (Date.now() - start >= IDENTITY_STABILITY_TIMEOUT_MS) { resolve(); return; }
        setTimeout(() => {
          const current = identitySignatureOf(panelEl);
          if (current === previous) { resolve(); return; }
          previous = current;
          poll();
        }, IDENTITY_STABILITY_POLL_MS);
      })();
    });
  }

  // Scoped to the section's own panel (2026-08-13 — see the scopeRoot note
  // above findValueByLabel) so a same-named label anywhere ELSE on this
  // search page (another panel, a filter form) can never be matched instead
  // of the panel's own real row.
  async function extractPropertyInformation() {
    const panel = findSectionPanel(findSectionHeader('Property Information'));
    await waitForPanelIdentityStable(panel);
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
  // ── TYPE-AWARE IDENTITY: freehold vs sectional ───────────────
  // ══════════════════════════════════════════════════════════
  // History (v3.3.9 through v3.5.0): this file went through several
  // increasingly elaborate mechanisms — a whole-panel DOM-settle wait, a
  // byte-identical-to-previous-capture comparison, an LPI-Code transition
  // gate, then a type-aware composite anchor with a per-field freshness-
  // proof poll and cross-restart persistence in chrome.storage.local — all
  // aimed at the same symptom: a captured field (Address, or the sectional-
  // only fields on a freehold panel) sometimes held a DIFFERENT property's
  // value than the one visibly on screen.
  //
  // v3.6.0 (2026-08-19) — ROOT CAUSE FOUND, and it was never a timing
  // problem. Johan dumped the live DOM on SEESKULP section 1 (sectional) ->
  // Erf 668 MARINE DRIVE (freehold) with an offsetParent visibility check:
  // cmainfo keeps BOTH property-type templates in the DOM AT ALL TIMES and
  // toggles which one is visible. The hidden one keeps whatever it last
  // held — indefinitely, with no further mutation ever — while the visible
  // one is the actually-loaded property. Every field-read in this file now
  // routes through findValueByLabel()/findValueElementByLabel() (see the
  // v3.6.0 note above isVisible(), near the top of the file), which skips
  // an invisible candidate and keeps searching for the visible template's
  // own copy of that label. That is the fix, and it is a READ-TIME fix, not
  // a wait-and-prove-freshness fix — there is no more residue to gate
  // against, because a hidden template's cells are never returned at all.
  //
  // What that made obsolete, and what still earns its place:
  //   REMOVED — the v3.5.0 per-field freshness-proof mechanism
  //   (FRESHNESS_GATED_FIELDS/waitForFieldsProof/earlyFreshnessBaseline) and
  //   the chrome.storage.local anchor persistence (loadLastAnchor/
  //   saveLastAnchor/sameIdentityAsLast) it was built to gate against a
  //   PRIOR capture's anchor. Both existed solely to answer "has Address
  //   genuinely changed since the property switched" — a question that no
  //   longer needs asking, because Address is now read from the visible
  //   template only, every time, unconditionally correct the instant
  //   cmainfo finishes its own postback. Type detection is consequently now
  //   a pure, STATELESS function of the current visible DOM — no capture
  //   history needed, so first-capture-of-a-session and the 100th capture
  //   are handled by the exact same code path (see detectPropertyType()
  //   below) — which is also what fixes the false refusal Johan hit on Erf
  //   668: the OLD stateful design had a "no prior anchor" escape hatch
  //   that a stateless design has no need for, but this NEW stateless
  //   design was never at risk of THAT particular failure to begin with —
  //   it was failing because it was reading the WRONG (hidden) template's
  //   blank/wrong-type cells, which visible-scoping now prevents outright.
  //   KEPT — ensureSectionExpanded()/domIsSettled()/the mutation observer
  //   (above) and waitForPanelIdentityStable() (below, in
  //   extractPropertyInformation()) still earn their place: they answer a
  //   DIFFERENT, still-real question — has the VISIBLE template's OWN
  //   postback actually finished writing its cells yet — which visible-
  //   scoping doesn't touch at all. A WebForms postback can still land a
  //   visible template's fields in a burst; reading mid-burst is still a
  //   real risk this settle-timing machinery still guards against.
  //   KEPT, but now demoted to defense-in-depth — applyTypeCoherence()'s
  //   drop of any foreign-type field. Visible-scoped reads mean a hidden
  //   template's fields should never reach `property` at all any more, so
  //   this should now almost never actually fire. Keeping it costs nothing
  //   (a no-op in the normal case) and guards the one thing tonight's
  //   evidence explicitly flagged as still uncertain: a THIRD, empty,
  //   permanently-hidden GPS cell was observed alongside the two known
  //   templates ("assume more than two templates exist") — if a future
  //   capture ever DOES trip this warning, that is a strong, specific
  //   signal of a not-yet-understood third template, worth investigating
  //   immediately rather than something to have silently deleted here.
  const SECTIONAL_ONLY_FIELDS = ['scheme_name', 'scheme_no', 'section_number', 'flat_number', 'section_extent', 'situated_at'];
  const FREEHOLD_ONLY_FIELDS = ['lpi_code', 'erf_no', 'erf_extent_raw', 'cadastral_extent_raw'];

  function hasFreeholdAnchor(property) {
    return !!(property.lpi_code || property.erf_no);
  }

  function hasSectionalAnchor(property) {
    return !!(property.scheme_no && property.section_number);
  }

  /**
   * Returns 'freehold' | 'sectional' | null — a PURE function of the
   * CURRENT visible read, no capture history involved (see the v3.6.0 note
   * above). null covers two distinct page states, both genuinely
   * unreadable: neither anchor visible (Johan's rule — refuse rather than
   * guess), or BOTH visible at once (see detectPropertyType()'s own
   * reasoning below for why this is now expected to be vanishingly rare
   * rather than the norm it was before visible-scoping).
   */
  function detectPropertyType(property) {
    const fh = hasFreeholdAnchor(property);
    const ss = hasSectionalAnchor(property);
    if (fh && !ss) return 'freehold';
    if (ss && !fh) return 'sectional';
    if (!fh && !ss) return null;

    // Both visible at once — genuinely contradictory (not the "hidden
    // template still holds old data" case any more; that case now reads as
    // exactly one anchor, correctly, because the hidden template's fields
    // are never returned by a visible-scoped read at all). Try cmainfo's
    // own "Type" text as a last-resort tiebreak; if that doesn't disambiguate
    // either, this is the genuinely unreadable case and fails closed same as
    // "neither".
    const typeText = normalizeLabel(property.type || '');
    if (typeText.indexOf('sectional') !== -1) return 'sectional';
    if (typeText.indexOf('freehold') !== -1 || typeText.indexOf('full title') !== -1 || typeText.indexOf('full-title') !== -1) return 'freehold';
    return null;
  }

  /**
   * Defense-in-depth (see the v3.6.0 removal note above) — a real cmainfo
   * panel's VISIBLE template renders exactly one type's field set, so
   * whichever type the anchor identifies, any value present for the OTHER
   * type's fields should not exist post-visible-scoping. If one somehow
   * does (the not-yet-understood third-template case), drop it and log
   * loudly rather than silently sending it.
   */
  function applyTypeCoherence(property, type) {
    const foreignFields = type === 'freehold' ? SECTIONAL_ONLY_FIELDS : FREEHOLD_ONLY_FIELDS;
    const dropped = [];
    foreignFields.forEach((key) => {
      if (property[key]) { dropped.push(key); property[key] = null; }
    });
    if (dropped.length) {
      console.warn('[CoreX] deeds-capture: panel identified as ' + type + ' (via its own VISIBLE anchor) but a visible-scoped read still carried a value for: ' + dropped.join(', ') + ' — dropped, not sent. This should be rare post-visible-scoping; if seen, it likely means a THIRD template exists that is not yet accounted for — investigate.');
    }
    return property;
  }

  async function extractDeed() {
    await ensureSectionExpanded('Property Information');
    let property = await extractPropertyInformation();

    // HARD FAIL-CLOSED — see detectPropertyType()'s docblock: applies ONLY
    // when no anchor is visible at all, or both are.
    const type = detectPropertyType(property);
    if (!type) {
      throw new Error('cmainfo panel is not showing a recognisable property — no LPI Code/Erf no (freehold) and no Scheme no + Section number (sectional) were visible, or both were. Capture refused rather than guessed.');
    }

    // Defense-in-depth only — see applyTypeCoherence()'s docblock. Visible-
    // scoped reads should already make this a no-op in the normal case.
    property = applyTypeCoherence(property, type);

    await ensureSectionExpanded('Sale Information');
    const sale = await extractSaleInformation();

    // v3.3.9, restored v3.4.3 — stamped on EVERY capture so domIsSettled()
    // can require genuine evidence (a real DOM mutation) that THIS
    // property's postback actually ran before the NEXT capture trusts a
    // "quiet" panel — see domIsSettled()'s v3.4.2 REGRESSION comment. A
    // timestamp, never a captured field value.
    lastCaptureCompletedAt = Date.now();

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

  // v3.6.3 (2026-08-20, live repro \u2014 Erf 668 and EVERY other recent QA1
  // capture: cadastral_extent stored "9" for a 9,480 m\u00b2 stand, "1" for a
  // 1,xxx m\u00b2 one, etc \u2014 confirmed truncated on every capture over 999
  // whatever-unit, hiding in plain sight below that threshold).
  //
  // ROOT CAUSE, fix the class not the instance: cmainfo formats BOTH money
  // ("R 1 575 000") and area ("9 480 m\u00b2") with a SPACE as the thousands
  // separator \u2014 plain, NBSP (U+00A0), or thin space (U+2009); JS's `\\s`
  // already matches all three (Unicode \\s includes \\u2000-\\u200a, which
  // covers thin space, and \\u00a0 explicitly). There were previously TWO
  // separate implementations: parseCurrency (blanket-strip everything
  // non-numeric \u2014 already correct for money, confirmed live: Bond Amount
  // already displayed right) and parseNumeric (only collapsed a space
  // specifically BETWEEN two digits \u2014 narrower, and the one that was
  // still shipping the truncation bug). Two parsers for the structurally
  // IDENTICAL problem is exactly the class of duplication that lets one path
  // get fixed and the other not. Unified into ONE canonical parser;
  // parseCurrency/parseNumeric are now both thin aliases so every existing
  // call site (money AND area) provably runs the SAME, single, tested
  // implementation \u2014 there is no second copy left to drift.
  //
  // FAIL LOUD, NOT SILENT (Johan's explicit rule): a value with real content
  // that doesn't reduce to a clean finite number returns null AND logs a
  // warning \u2014 never a truncated prefix passed off as the real number. A
  // genuinely blank/dash/N-A field (already normalised to null at
  // extraction \u2014 see normalizeBlankish()) is NOT a parse failure and does
  // not warn; only "there was something here and we couldn't make sense of
  // it" warns.
  function parseNumericValue(v, fieldLabel) {
    if (v == null) return null;
    const raw = String(v).trim();
    if (!raw) return null;
    const stripped = raw.replace(/[^\d.,-]/g, '').replace(/,/g, '');
    const n = stripped ? parseFloat(stripped) : NaN;
    if (!Number.isFinite(n)) {
      console.warn('[CoreX] deeds-capture: could not parse "' + raw + '"' + (fieldLabel ? ' (' + fieldLabel + ')' : '') + ' as a number \u2014 sending null rather than a guessed/truncated value.');
      return null;
    }
    return n;
  }

  function parseCurrency(v, fieldLabel) {
    return parseNumericValue(v, fieldLabel);
  }

  function parseNumeric(v, fieldLabel) {
    return parseNumericValue(v, fieldLabel);
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

  // v3.5.0 (2026-08-18, root-cause audit .ai/audits/2026-08-18-cmainfo-scraper-
  // root-cause.md, Defect 2) — CONFIRMED LIVE: cmainfo renders "GPS" as
  // "<lng>°<E|W>   <lat>°<S|N>" — longitude FIRST, latitude SECOND, hemisphere
  // as a trailing direction letter, NEVER a leading sign (e.g.
  // "30.391273°E   30.842466°S" = longitude +30.391273, latitude -30.842466).
  // The old parser assigned by POSITION (nums[0]->lat, nums[1]->lng) and never
  // read the direction letter at all — for the E-then-S order above that put
  // the longitude's magnitude in `lat` (failed the SA sanity range, nulled)
  // and the latitude's magnitude in `lng`, UNSIGNED (passed the range check,
  // stored positive) — exactly the corrupted latitude:NULL/longitude:+lat-
  // magnitude state found on every captured row.
  //
  // Fixed by parsing each "<number><°?><letter>" pair and assigning by the
  // LETTER, never by position: E/W -> longitude (positive/negative), N/S ->
  // latitude (positive/negative). Johan's explicit rule (spec §6.6): FAIL
  // CLOSED — if the letters are absent, ambiguous (more than one of the same
  // axis), or the assigned values fail the SA sanity range, send BOTH
  // coordinates as null. Never fall back to positional guessing — a wrong
  // coordinate silently mis-matches two different properties (GPS-proximity
  // match strategy), which is worse than a missing one.
  function parseGps(v) {
    if (!v) return { lat: null, lng: null };
    const matches = String(v).match(/-?\d+(?:\.\d+)?\s*°?\s*[NSEW]/gi);
    if (!matches) return { lat: null, lng: null };

    let lat = null;
    let lng = null;
    let latSeen = 0;
    let lngSeen = 0;

    for (const token of matches) {
      const parsed = token.match(/(-?\d+(?:\.\d+)?)\s*°?\s*([NSEW])/i);
      if (!parsed) return { lat: null, lng: null }; // shouldn't happen given the outer match, but fail closed rather than assume
      const magnitude = Math.abs(parseFloat(parsed[1]));
      const dir = parsed[2].toUpperCase();
      if (dir === 'N' || dir === 'S') {
        latSeen++;
        lat = dir === 'S' ? -magnitude : magnitude;
      } else {
        lngSeen++;
        lng = dir === 'W' ? -magnitude : magnitude;
      }
    }

    // Exactly one latitude letter and one longitude letter — anything else
    // (missing an axis, or the same axis's letter appearing twice) means we
    // can't be sure which value is which. Fail closed rather than guess.
    if (latSeen !== 1 || lngSeen !== 1) return { lat: null, lng: null };

    // SA sanity range as a guard AFTER assignment, never as the thing that
    // decides which value is which (that was the old bug).
    const validLat = lat >= -35 && lat <= -22;
    const validLng = lng >= 16 && lng <= 33;
    if (!validLat || !validLng) return { lat: null, lng: null };

    return { lat: lat, lng: lng };
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

  // v3.4.4 (2026-08-19, Johan — live repro: "SMIT & WESSELS TRUST-TRUSTEES"
  // stored as "& Wessels Trust-trustees Smit"). parsePersonName() below is
  // ONLY valid for a natural person's SURNAME-FIRST name — it unconditionally
  // reorders tokens and title-cases them. Run against a TRUST/CC/PTY/LTD/BK/
  // joint-owner ("A & B") name, the same algorithm treats the entity's own
  // words as if they were "surname" + "first names" and reorders/mangles
  // them, because nothing before this fix ever distinguished an entity name
  // from a person's name. A juristic entity has no surname/first-name split
  // to begin with — the fix is to detect the entity shape FIRST and skip
  // reordering entirely, keeping the raw string verbatim (case included).
  // Matched case-insensitively; "&" (joint/multiple owners written as one
  // cell, e.g. "SMIT & WESSELS") is included per Johan's explicit list.
  //
  // v3.6.0 (2026-08-19, live repro: Erf 668 MARINE DRIVE — Owner "HIBISCUS
  // COAST MUNICIPALITY", no Owner's ID at all). MUNICIPALITY was missing
  // from this list, so a local-government owner (which has no surname/
  // first-name split any more than a company does) was run through the
  // person-name reorder and would have mangled to surname="Hibiscus",
  // first_names="Coast Municipality". Added on the same evidence-driven
  // basis as the other keywords — a real, observed owner shape, not a
  // speculative addition.
  const ENTITY_NAME_PATTERN = /\b(TRUST|TRUSTEE|TRUSTEES|CC|PTY|LTD|LIMITED|BK|INC|INCORPORATED|NPC|NPO|SOC|MUNICIPALITY)\b|&/i;

  function looksLikeEntityName(raw) {
    return ENTITY_NAME_PATTERN.test(String(raw || ''));
  }

  function parsePersonName(raw) {
    const rawTrimmed = String(raw || '').trim();
    if (looksLikeEntityName(rawTrimmed)) {
      // Entity/juristic name (trust, company, CC, joint "A & B", ...) — kept
      // VERBATIM, never reordered or case-mangled. confident:false makes the
      // caller (buildOwnersArray) fall back to sending rawName as-is; the
      // entity:true flag lets it skip the "could not confidently parse"
      // warning, since this isn't a parse failure — it's the correct outcome.
      return { surname: null, first_names: null, confident: false, entity: true };
    }
    const tokens = rawTrimmed.split(/\s+/).filter(Boolean);
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
      if (parsed.entity) {
        console.log('[CoreX] deeds-capture: owner "' + rawName + '" looks like a trust/company/entity name — kept verbatim, not run through person-name reordering.');
      } else if (!parsed.confident && rawName) {
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
   * ref. v3.4.5: LPI Code (see PROPERTY_INFORMATION_LABELS above) is now the
   * PRIMARY candidate — it's cmainfo's own unique per-property identifier,
   * present on both sectional and freehold properties, and doesn't depend on
   * a sale having a recorded Title Deed. Fallback chain below it unchanged
   * (kept for the rare case LPI Code itself is blank on a page), in order of
   * how stable each candidate actually is:
   *   1. LPI Code — cmainfo's own unique per-property identifier.
   *   2. Title Deed number — a genuine deeds-registry identifier.
   *   3. Scheme number + section number — stable for a sectional unit.
   *   4. The rendered address / "Situated at" text.
   *   5. A timestamp — LAST resort; NOT idempotent (each capture creates a
   *      new tracked_property). Flagged loudly so it's never silently relied on.
   */
  /**
   * v3.6.1 (2026-08-19, live repro — Johan) — CONFIRMED BUG: the scheme+
   * section candidate below used to read `p.scheme_number`, a key that does
   * not exist on the extracted property object at all (PROPERTY_INFORMATION_
   * LABELS names the field `scheme_no` — see near the top of this file).
   * `p.scheme_number && p.section_number` was therefore always
   * `undefined && ...` — always falsy — so it NEVER fired, and every
   * sectional capture silently fell through to Title Deed. On a property
   * whose deed is a long shared-title list (SEESKULP section 4: ten
   * semicolon-joined deed/share entries), that candidate blew past the
   * server's 200-char source_ref limit and the capture failed outright.
   *
   * Rebuilt to be TYPE-DRIVEN and match anchorKeyOf()'s own logic exactly —
   * a source_ref must be short, stable and unique per property, and the
   * ONLY fields that are ever any of those three are the type's own anchor:
   * freehold -> LPI Code (falls back to Erf no if LPI is blank); sectional
   * -> Scheme no + Section number, which IS the unique identity of one
   * sectional unit and is always short. Title Deed is NEVER a candidate any
   * more, for EITHER type — a shared/subdivided-title freehold could in
   * principle hit the same problem, and the fix is the same fix either way:
   * don't key an idempotency ref off a field cmainfo can render as an
   * unbounded list. This also means a sectional whose deed field happens to
   * be a single, short value builds its ref the exact same way as one with
   * a ten-entry list — never sometimes-deed, sometimes-scheme.
   */
  function buildSourceRef(deed, type) {
    const p = deed.property_information;
    let candidate = null;
    if (type === 'freehold') {
      candidate = p.lpi_code || p.erf_no || null;
    } else if (type === 'sectional') {
      candidate = (p.scheme_no && p.section_number) ? (p.scheme_no + '-' + p.section_number) : null;
    }
    // Last-resort fallback ONLY when the type's own anchor is somehow still
    // missing here (should not happen post-detectPropertyType, which already
    // required one of these to be present) — address, never Title Deed.
    if (!candidate) {
      candidate = p.address || p.situated_at || null;
    }
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
  //     "Erf no" label (full-title properties only; blank on sectional — and
  //     now, since v3.5.0, structurally GUARANTEED null on a sectional
  //     capture by applyTypeCoherence() in extractDeed(), regardless of what
  //     the raw DOM read happened to contain). "Situated at" (the SCHEME's
  //     erf + township, e.g. "658 UVONGO") is NEVER used for erf_number —
  //     spec §6.2: it is not the unit's own erf, and storing it as one
  //     collapses every unit in a scheme onto each other via the erf+suburb
  //     match strategy.
  //   - street_number/street_name: split from "Address" via
  //     splitStreetAddress() (see above) — was always null before.
  //   - erf_extent_m2 / cadastral_extent_m2 / section_extent_m2 (2026-08-20,
  //     Johan's own contract, cc3 implementing the matching server side in
  //     parallel — spec §6.4): THREE separate, independent, optional payload
  //     fields, one per measurement, replacing the single shared
  //     section_extent_m2 slot this file used through v3.6.3. Each is fed
  //     from exactly ONE label ("Extent" / "Cadastral extent" / "Section
  //     extent") and nothing else — no ternary, no type check needed here at
  //     all any more: applyTypeCoherence() in extractDeed() has ALREADY
  //     nulled erf_extent_raw/cadastral_extent_raw for a sectional capture
  //     and section_extent for a freehold one, so "send only what's actually
  //     visible" falls out for free from parsing each raw field as-is.
  //     section_extent_m2 keeps its NAME (backward compatible — cc3's server
  //     accepts both old and new builds) but is now territorially narrowed:
  //     it carries ONLY the sectional Section extent, never the freehold
  //     Extent that pre-v3.6.4 builds used to route through it.
  function buildDeedsCapturePayload(deed) {
    const p = deed.property_information;
    const s = deed.sale_information;
    const gps = parseGps(p.gps);
    const type = detectPropertyType(p);
    const { ref: sourceRef, stable: sourceRefStable } = buildSourceRef(deed, type);

    // Diagnostic only — logged, never sent (cc1's contract has no field for
    // this; smuggling an undocumented key into the payload isn't "matching
    // the contract"). onCaptureClick() surfaces it in the on-page status too.
    if (!sourceRefStable) {
      console.warn('[CoreX] deeds-capture: no stable identifier found on this page — using a timestamp fallback. Re-capturing this property will create a DUPLICATE tracked_property, not update the existing one.');
    }

    const street = splitStreetAddress(p.address);
    const erfExtentM2 = parseNumeric(p.erf_extent_raw, 'Extent');
    const cadastralExtentM2 = parseNumeric(p.cadastral_extent_raw, 'Cadastral extent');
    const sectionExtentM2 = parseNumeric(p.section_extent, 'Section extent');

    const capture = {
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
        section_extent_m2:    sectionExtentM2,  // existing field, kept for backward compat — now sectional-only (see mapping note)
        erf_extent_m2:        erfExtentM2,       // NEW — freehold Extent (erf size)
        cadastral_extent_m2:  cadastralExtentM2, // NEW — freehold Cadastral extent (its own separate value)
        property_type:     p.type,
        title_deed_number: s.title_deed, // routed from Sale Information — see mapping note above
      },
      owners: buildOwnersArray(deed), // multi-owner (2026-08-12) — see buildOwnersArray() — UNCHANGED, kept exactly as-is per spec §7.2 ("today's existing simple owners[] path exactly as it is")
      sale: {
        sale_price:       parseCurrency(s.sale_price, 'Sale Price'),
        sale_date:        parseSaDate(s.sale_date),
        registered_date:  parseSaDate(s.registered_date),
        bond_holder:      s.bond_holder,
        bond_amount:      parseCurrency(s.bond_amount, 'Bond Amount'),
        sale_type:        s.sale_type,
      },
    };

    // v3.6.2 — spec .ai/specs/deeds-capture.md §7.2/§7.3 (cc4, approved).
    // The extension does NOT parse ownership — no splitting, no share math,
    // no picking an entry, no grouping. When cmainfo's Owner cell has more
    // than one ";"-separated entry, the three raw cell strings (Owner,
    // Owner's ID, Title Deed) are sent VERBATIM, unsplit, un-stripped,
    // exactly as extractByLabelMap() read them (only the shared, universal
    // outer-whitespace-trim/NBSP-collapse every field already gets — never
    // per-entry share-token stripping or splitting; that stays 100%
    // server-side, in the new OwnershipHistoryParser service). Masked IDs
    // are sent AS-IS, masked, if revealOwnerIdIfNeeded() couldn't fully
    // unmask the cell in time (e.g. SEESKULP section 4's ten entries, one of
    // which — "IT 1203/91", a trust registration number — never unmasks at
    // all, correctly) — the server fails closed on ownership for that
    // capture, not this file. ADDITIVE and OPTIONAL: omitted entirely when
    // the Owner cell has only one entry, so an older extension build (no
    // such key) or a page with simple single ownership are both unaffected
    // — owners[] above is unchanged and still authoritative in that case.
    if (typeof s.owner === 'string' && s.owner.indexOf(';') !== -1) {
      capture.ownership_history_raw = {
        owner_names: s.owner,
        owner_ids:   s.owner_id_number,
        title_deeds: s.title_deed,
      };
    }

    return {
      source: 'cmainfo',
      captures: [capture],
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
