{{-- Shared A4 page rendering: CSS + JS pagination function --}}
<style>
.corex-a4-page {
    width: 210mm;
    min-height: 297mm;
    max-width: 100%;
    background: white;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin: 0 auto 24px auto;
    padding: 20mm 18mm 25mm 18mm;
    position: relative;
    overflow: hidden;
    box-sizing: border-box;
}
.corex-a4-page .page-number {
    position: absolute;
    bottom: 10mm;
    left: 0;
    right: 0;
    text-align: center;
    font-size: 9px;
    color: #94a3b8;
}
.corex-page-gap {
    height: 24px;
    background: #f1f5f9;
    margin: 0 auto;
    width: 210mm;
    max-width: 100%;
}
/* Kill inner container styling when A4 pages are active */
.corex-a4-page .corex-document-wrapper,
.corex-a4-page .corex-page {
    width: 100% !important;
    max-width: 100% !important;
    min-height: auto !important;
    box-shadow: none !important;
    background: transparent !important;
    margin: 0 !important;
    padding: 0 !important;
    border: none !important;
    border-radius: 0 !important;
}
/* Also for when pagination hasn't run yet but document is in a page container */
#webDocContent .corex-document-wrapper,
#webDocContent .corex-page,
[x-ref="webDocContent"] .corex-document-wrapper,
[x-ref="webDocContent"] .corex-page {
    width: 100% !important;
    max-width: 100% !important;
    box-shadow: none !important;
    border-radius: 0 !important;
}
@media print {
    .corex-a4-page {
        box-shadow: none;
        margin: 0;
        padding: 20mm 18mm;
        page-break-after: always;
        min-height: auto;
    }
    .corex-a4-page:last-child {
        page-break-after: avoid;
    }
    .corex-page-gap {
        display: none;
    }
    .corex-page-break {
        border: none !important;
        margin: 0 !important;
    }
    .corex-page-break > div:last-child {
        display: none !important;
    }
}

/* ═══ ESIGN-WETINK — FIT-TO-BLOCK ink render (Johan's locked spec) ═══
   NOT "force every img to 56px" (that overflows a small marker box and makes
   signatures collide). Instead: the MARKER BLOCK is the fixed, consistent container
   (one standard size for signatures, one for initials), and the ink IMAGE scales to
   FILL that block (width/height 100% + object-fit:contain) — a small drawn/typed
   image scales UP to fill, a large one scales DOWN, both end at the block size, so
   agent + recipient ink render the SAME size AT THE BLOCK LEVEL regardless of the
   image's intrinsic pixels. overflow:hidden on the block guarantees ink NEVER bursts
   its bounds (no collisions/overlap). Matches every ink node whether it lands in a
   [data-marker-type] marker, a legacy .web-sig marker cell, or a class-less overlay
   img (matched by its data-URI src; logos are URL-based, untouched). Same blocks on
   ceremony, agent-review, print. Legal: a signature that renders tiny disappears on
   print/scan — a filled block never does. */

/* ═══ ESIGN-WETINK — UNIFORM, REALISTIC ink sizing ═══
   Every party's ink renders at the SAME HEIGHT. The prior "img 100% + object-fit
   contain in a fixed 200x54 box" made a LONG name fit-to-width → come out shorter
   than a SHORT name (the "different sizes per signatory" bug), and filled the block
   edge-to-edge (oversized). Instead ink is sized by a FIXED HEIGHT (~65-70% of the
   line, with padding) and its width follows the tight-cropped glyph's aspect — so
   EVERY signatory's signature is the same height, EVERY initial is the same height,
   and both look like a real mark in the box, not bursting it. */

/* signature LINE — accommodates the mark on one line; does NOT clip a wider name. */
[data-marker-type="signature"],
.web-sig-interactive[data-marker-type="signature"],
.sig-inline-line,
.sig-cell-line {
    display: inline-block !important;
    min-width: 120px !important;
    min-height: 42px !important;
    overflow: visible !important;
    vertical-align: bottom !important;
    white-space: nowrap !important;
    text-align: center !important;
}
/* initial BOX — fixed, centred. */
[data-marker-type="initial"],
.corex-page-initials {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 84px !important;
    max-width: 84px !important;
    height: 40px !important;
    max-height: 40px !important;
    overflow: hidden !important;
    vertical-align: bottom !important;
}
/* — TYPED-INITIAL / placeholder TEXT fills the initial block (kill the 9px speck) —
   A page-break initial box (.corex-page-initials, [data-marker-type=initial]) that
   holds TEXT (the party-label placeholder, or a typed initial rendered as glyphs)
   was stuck at font-size:9px while the block is 84x40 → a 9px speck floating in the
   box, next to a full-size image initial. Size the text to FILL the block height so
   a typed initial matches an image initial and matches the agent. Image initials are
   handled by the fill rule below; this only affects text content. */
/* the BOX keeps its 84x40 block size (rule above); here we only center content
   and kill the 9px so text content is sized by the span rule, not the box. */
.corex-page-initials {
    font-size: 24px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    line-height: 1 !important;
}
/* the TEXT content (placeholder label OR a typed-glyph initial) fills the block. */
.initial-placeholder,
[data-marker-type="initial"] .initial-placeholder {
    font-size: 24px !important;
    line-height: 1 !important;
    font-weight: 600 !important;
    letter-spacing: normal !important;
    color: #1e293b !important;
    text-align: center !important;
    max-width: 100% !important;
    max-height: 100% !important;
    overflow: hidden !important;
}

/* — SIGNATURE INK — ONE height for EVERY signatory (~67% of the 54px line). Width
   follows the tight-cropped glyph (long name = wider, not shorter). Capped generously
   so an extreme name can't run away; that cap is the only case height can dip. — */
[data-marker-type="signature"] img,
.sig-inline-line img,
.sig-cell-line img,
[data-marker-type="signature"] img.web-sig-signed-img,
[data-marker-type="signature"] img.corex-ink,
img.corex-ink--signature {
    height: 36px !important;
    min-height: 36px !important;
    max-height: 36px !important;
    width: auto !important;
    min-width: 0 !important;
    max-width: 240px !important;
    object-fit: contain !important;
    object-position: center !important;
    display: inline-block !important;
    vertical-align: bottom !important;
    margin: 0 auto !important;
    padding: 0 !important;
    transform: none !important;
    box-sizing: content-box !important;
}
/* — INITIAL INK — ONE height for EVERY party (~65% of the 40px box). — */
[data-marker-type="initial"] img,
.corex-page-initials img,
img.corex-ink--initial {
    height: 26px !important;
    min-height: 26px !important;
    max-height: 26px !important;
    width: auto !important;
    min-width: 0 !important;
    max-width: 76px !important;
    object-fit: contain !important;
    object-position: center !important;
    display: inline-block !important;
    vertical-align: middle !important;
    margin: 0 auto !important;
    padding: 0 !important;
    transform: none !important;
    box-sizing: content-box !important;
}
</style>
<script>
/**
 * Client-side A4 page pagination based on actual rendered element heights.
 *
 * Strategy 1: Template has multiple .corex-page divs → wrap each in .corex-a4-page
 * Strategy 2: Continuous HTML → measure children heights, split at A4 page boundaries
 *
 * @param {HTMLElement} container  The DOM element containing the document HTML
 * @param {Array}       parties   [{role:'agent',label:'Agent'}, ...] for initials between pages
 */
/**
 * §19 — Per-document pagination. A pack is an ENVELOPE of N documents, not a
 * merge: each .corex-document-wrapper paginates within its OWN boundary with
 * its own page numbering, per-page initials and terminal signature block. No
 * page straddles two documents. Single (non-pack) docs = one wrapper, behave
 * as before plus the new per-page initial footer rows.
 */
function paginateDocument(container, parties) {
    if (!container) return;
    parties = parties || [];

    // §19.4 — idempotent re-anchor. If already paginated (content edit / zoom
    // re-flow), snapshot every applied initial/signature by its stable
    // (party|type|docIdx-pageIdx-partyIdx) key, de-paginate back to flat
    // per-wrapper content, then rebuild and re-apply by key. No duplicate
    // rows, no lost applied values, orphaned rows dropped on shrink, and the
    // signature block follows the document's (possibly new) last page.
    // Give every signature/initial marker a STABLE per-anchor sequence in
    // document order per (party|type). The composed clause signatures carry NO
    // data-marker-index (only the final attestation markers do), so without this
    // _markerKey collapses all of a party's clause signatures onto one key
    // "party|signature|" — the snapshot/re-apply below then overwrites them to the
    // LAST one, re-collapsing the per-anchor signatures (seller l/m/n → all n)
    // AFTER bakeInk had bound them correctly. Idempotent: assigned once, persists
    // across re-pagination passes so snapshot and re-apply resolve the same key.
    (function _ensureAnchorSeq() {
        var seq = {};
        container.querySelectorAll('[data-marker-type="signature"], [data-marker-type="initial"]').forEach(function (el) {
            if (el.getAttribute('data-anchor-seq')) return; // already numbered
            var kk = (el.getAttribute('data-marker-party') || '') + '|' + (el.getAttribute('data-marker-type') || '');
            var n = seq[kk] || 0; seq[kk] = n + 1;
            el.setAttribute('data-anchor-seq', String(n));
        });
    })();

    var applied = {};
    if (container.dataset.paginated === 'true') {
        container.querySelectorAll('[data-marker-type="initial"][data-signed="true"], [data-marker-type="signature"][data-signed="true"]').forEach(function (el) {
            applied[_markerKey(el)] = { html: el.innerHTML, signed: el.getAttribute('data-signed'), style: el.getAttribute('style') || '' };
        });
        _dePaginate(container);
    }
    container.dataset.paginated = 'true';

    // Fresh client build — drop stale server-side initials / page-break markers.
    container.querySelectorAll('[data-marker-type="initial"]').forEach(function (el) { el.remove(); });
    container.querySelectorAll('.corex-page-break').forEach(function (el) { el.remove(); });

    // Preserve global <style>/<link>; re-add at container top after restructure.
    var styleEls = [];
    Array.from(container.querySelectorAll('style, link[rel="stylesheet"]')).forEach(function (el) {
        styleEls.push(el.cloneNode(true));
    });

    // Each document = one .corex-document-wrapper. None => one implicit doc.
    var wrappers = Array.from(container.children).filter(function (el) {
        return el.classList && el.classList.contains('corex-document-wrapper');
    });
    if (wrappers.length === 0) {
        wrappers = Array.from(container.querySelectorAll('.corex-document-wrapper'));
    }
    if (wrappers.length === 0) {
        var synthetic = document.createElement('div');
        synthetic.className = 'corex-document-wrapper';
        Array.from(container.childNodes).forEach(function (n) {
            if (n.nodeType === 1 && (n.tagName === 'STYLE' || n.tagName === 'LINK')) return;
            synthetic.appendChild(n);
        });
        container.innerHTML = '';
        container.appendChild(synthetic);
        wrappers = [synthetic];
    }

    // Lift style tags out of wrappers to the container top.
    container.querySelectorAll('style, link[rel="stylesheet"]').forEach(function (el) { el.remove(); });
    styleEls.forEach(function (s) { container.insertBefore(s, container.firstChild); });

    wrappers.forEach(function (wrapper, docIdx) {
        _paginateWrapper(wrapper, docIdx, parties);
    });

    // §19.4 — re-apply captured initial/signature state by stable key.
    if (Object.keys(applied).length) {
        container.querySelectorAll('[data-marker-type="initial"], [data-marker-type="signature"]').forEach(function (el) {
            var s = applied[_markerKey(el)];
            if (!s) return;
            el.innerHTML = s.html;
            if (s.signed) el.setAttribute('data-signed', s.signed);
            if (s.style) el.setAttribute('style', s.style);
        });
    }

    _stripInnerStyling(container);
}

/** Stable re-anchor key (§19.4): party | type | docIdx-pageIdx-partyIdx. */
function _markerKey(el) {
    return (el.getAttribute('data-marker-party') || '') + '|' +
           (el.getAttribute('data-marker-type') || '') + '|' +
           // Prefer the composed index; fall back to the per-anchor sequence so
           // index-less clause signatures each keep a UNIQUE key (no collapse).
           (el.getAttribute('data-marker-index') || el.getAttribute('data-anchor-seq') || '');
}

/** Pull each wrapper's body back out of its .corex-a4-page pages (drop the
 *  page-number / initials-row decorations) so it can be re-measured. */
function _dePaginate(container) {
    container.querySelectorAll('.corex-document-wrapper').forEach(function (wrapper) {
        var pages = Array.from(wrapper.querySelectorAll(':scope > .corex-a4-page'));
        if (pages.length === 0) return;
        var frag = wrapper.ownerDocument.createDocumentFragment();
        pages.forEach(function (pageDiv) {
            Array.from(pageDiv.childNodes).forEach(function (node) {
                if (node.nodeType === 1 && (
                    node.classList.contains('page-number') ||
                    node.classList.contains('corex-page-initials-row'))) {
                    return; // decoration — rebuilt fresh
                }
                frag.appendChild(node);
            });
        });
        wrapper.innerHTML = '';
        wrapper.appendChild(frag);
    });
    container.querySelectorAll('.corex-page-gap').forEach(function (g) { g.remove(); });
}

/**
 * Height-paginate ONE document wrapper within its own boundary, rebuilding it
 * IN PLACE as a per-document sequence of .corex-a4-page elements. The wrapper
 * element is retained so SignatureService::splitMergedHtml() still splits the
 * already-paginated DOM per document (§19.7). Page numbering restarts at 1 per
 * document; an initial slot is placed on every page where
 * pageIndex < lastPageIndex; the signature section is kept on the last
 * page (§19.3) — a single-page document gets the signature block, no initial.
 *
 * MEASURE-AND-FIT (no magic constant). Instead of guessing a content-height
 * budget (the old PAGE_CONTENT_HEIGHT ≈ 878px reserve — fragile: it held on one
 * doc and spilled on others because the emailed PDF renders with SUBSTITUTE
 * fonts that are TALLER than the signing browser's, so a box packed to the
 * guessed budget overflowed one physical A4 sheet and its footer/initials strip
 * spilled onto a near-blank next page), we measure the REAL rendered box in the
 * CURRENT render environment:
 *
 *   1. Probe the exact one-physical-sheet height by measuring an empty
 *      .corex-a4-page (min-height:297mm) here — so the threshold is whatever
 *      297mm actually renders to in THIS browser/Chromium, not a derived px.
 *   2. Fill a live .corex-a4-page box element node-by-node, and after each
 *      append measure box.offsetHeight WITH the bottom initials strip appended
 *      (the real furniture, not a 58px guess). The moment the box would exceed
 *      one sheet, the last node starts the next page.
 *
 * Because pagination runs in the SAME engine that produces the PDF (Chromium,
 * via SignaturePdfService::injectInitialsPagination), the measured height IS
 * the rendered height — every logical page fits exactly one physical sheet:
 * zero clipping, zero near-blank spill pages, for short, medium and long docs.
 */
function _paginateWrapper(wrapper, docIdx, parties) {
    var doc = wrapper.ownerDocument;

    var contentEl = wrapper;
    var innerPage = wrapper.querySelector(':scope > .corex-page');
    if (innerPage) contentEl = innerPage;

    var children = Array.from(contentEl.children).filter(function (el) {
        return !(el.tagName === 'STYLE' || el.tagName === 'LINK');
    });
    if (children.length === 0) return;

    // Keep the wrapper's own <style>/<link> so intra-document CSS survives.
    var styleNodes = Array.from(contentEl.children).filter(function (el) {
        return el.tagName === 'STYLE' || el.tagName === 'LINK';
    });

    // Detach every content node (references + order preserved) and clear the
    // wrapper so we can build/measure fresh .corex-a4-page boxes inside it.
    children.forEach(function (c) { if (c.parentNode) c.parentNode.removeChild(c); });
    var origW = wrapper.style.width, origMW = wrapper.style.maxWidth;
    wrapper.innerHTML = '';
    // Let each .corex-a4-page own its 210mm A4 width (was pinned to 658px). The
    // box's own CSS width is what the PDF prints at, so measuring inside it is
    // the faithful width.
    wrapper.style.width = '';
    wrapper.style.maxWidth = '';

    // (1) Probe the exact one-sheet height in THIS render environment.
    var probe = doc.createElement('div');
    probe.className = 'corex-a4-page';
    probe.style.visibility = 'hidden';
    wrapper.appendChild(probe);
    var SHEET_PX = probe.offsetHeight;             // 297mm as it really renders (incl. padding)
    wrapper.removeChild(probe);
    if (!SHEET_PX || SHEET_PX < 200) {
        SHEET_PX = Math.round(297 * 96 / 25.4);    // ultra-safe fallback: 297mm @96dpi ≈ 1123px
    }

    // (2) Greedily fill live boxes, measuring the REAL rendered box (content +
    //     the initials strip the non-last pages carry) so a page never exceeds
    //     one physical sheet. A signature-section node marks the closing block:
    //     once reached we keep it on the current/last page and break at most
    //     once (to move an unbroken signature block onto a fresh last page).
    var pageGroups = [];
    var i = 0;
    var sigMode = false;         // reached the signature/closing section
    var sigBrokenOnce = false;   // allowed a single break to start the sig page

    while (i < children.length) {
        var box = doc.createElement('div');
        box.className = 'corex-a4-page';
        box.style.visibility = 'hidden';
        wrapper.appendChild(box);
        // The real furniture element (same one the assembler appends) — reused
        // for every measurement on this page; reserved on non-last (non-sig) pages.
        var strip = parties.length ? _buildInitialsRow(parties, 'measure') : null;
        var group = [];

        while (i < children.length) {
            var child = children[i];
            if (child.nodeType !== 1) { box.appendChild(child); group.push(child); i++; continue; }

            if (!sigMode && (child.classList.contains('sig-section') ||
                             child.classList.contains('corex-signature-section'))) {
                sigMode = true;
            }

            box.appendChild(child);
            var reserve = strip && !sigMode;                 // last (sig) page carries no strip
            if (reserve) box.appendChild(strip);
            var overflow = box.offsetHeight > SHEET_PX;
            if (reserve && strip.parentNode === box) box.removeChild(strip);

            if (overflow && group.length > 0 && !(sigMode && sigBrokenOnce)) {
                box.removeChild(child);                      // this node starts the next page
                if (sigMode) sigBrokenOnce = true;           // sig block now on its own fresh page
                break;
            }
            group.push(child);
            i++;
        }

        pageGroups.push(group);
        wrapper.removeChild(box);                            // rebuilt cleanly below with furniture
    }

    var total = pageGroups.length;

    // Rebuild the wrapper in place (retained for split + per-doc identity).
    wrapper.innerHTML = '';
    styleNodes.forEach(function (s) { wrapper.appendChild(s); });
    pageGroups.forEach(function (pageChildren, p) {
        var pageDiv = doc.createElement('div');
        pageDiv.className = 'corex-a4-page';
        pageDiv.setAttribute('data-doc-index', String(docIdx));
        pageDiv.setAttribute('data-page-index', String(p));
        pageDiv.setAttribute('data-doc-total', String(total));
        pageChildren.forEach(function (c) { pageDiv.appendChild(c); });

        var pn = doc.createElement('div');
        pn.className = 'page-number';
        pn.textContent = 'Page ' + (p + 1) + ' of ' + total; // per-document
        pageDiv.appendChild(pn);

        // §19.3 — initial slot on every page EXCEPT this document's last page.
        // _buildInitialsRow encodes the key as docIdx-pageIdx-partyIdx.
        if (p < total - 1 && parties.length > 0) {
            pageDiv.appendChild(_buildInitialsRow(parties, docIdx + '-' + p));
        }

        wrapper.appendChild(pageDiv);

        if (p < total - 1) {
            var gap = doc.createElement('div');
            gap.className = 'corex-page-gap';
            wrapper.appendChild(gap);
        }
    });

    wrapper.style.width = origW;
    wrapper.style.maxWidth = origMW;
}

/**
 * Build an initials row with a box for each signing party.
 */
function _buildInitialsRow(parties, pageIdx) {
    var row = document.createElement('div');
    row.className = 'corex-page-initials-row';
    row.style.cssText = 'display:flex;justify-content:flex-end;align-items:center;gap:8px;padding:12px 0 4px 0;';

    parties.forEach(function(party, pIdx) {
        var box = document.createElement('div');
        box.className = 'corex-page-initials';
        box.setAttribute('data-marker-party', party.role);
        box.setAttribute('data-marker-type', 'initial');
        box.setAttribute('data-marker-index', pageIdx + '-' + pIdx);
        // ESIGN-WETINK — the initial block + its TEXT fill at the standard size.
        // Box 84x40; the placeholder/typed-glyph SPAN carries font-size:24px INLINE
        // so it never depends on a stylesheet rule reaching the child (the conductor
        // measured the span still tiny even with the .initial-placeholder rule — an
        // inline size on the span itself is the bulletproof fix).
        box.style.cssText = 'width:84px;height:40px;border:1px solid #94a3b8;display:flex;align-items:center;justify-content:center;font-size:24px;color:#334155;cursor:pointer;overflow:hidden;';
        box.innerHTML = '<span class="initial-placeholder" style="font-size:24px;line-height:1;font-weight:600;display:flex;align-items:center;justify-content:center;width:100%;height:100%;">' + (party.label || party.role) + '</span>';
        row.appendChild(box);
    });

    return row;
}

/**
 * Strip inner container styling that conflicts with A4 page wrapping.
 */
function _stripInnerStyling(container) {
    container.querySelectorAll('.corex-document-wrapper, .corex-page').forEach(function(el) {
        el.style.width = '100%';
        el.style.maxWidth = '100%';
        el.style.minHeight = 'auto';
        el.style.boxShadow = 'none';
        el.style.background = 'transparent';
        el.style.margin = '0';
        el.style.padding = '0';
        el.style.border = 'none';
        el.style.borderRadius = '0';
    });
}

/**
 * Restore previously signed initials into page-break initial elements.
 * Called AFTER paginateDocument() so the initial elements exist in the DOM.
 *
 * @param {HTMLElement} container    The document container
 * @param {Object}      storedInitials  { "agent": { "agent-init-0": "data:image/...", ... }, "supervisor": {...} }
 */
function restoreStoredInitials(container, storedInitials) {
    if (!container || !storedInitials || typeof storedInitials !== 'object') return;

    var allInitialEls = container.querySelectorAll('[data-marker-type="initial"]');
    if (allInitialEls.length === 0) return;

    // AT-324/AT-325 — key each captured initial by the CANONICAL RECIPIENT KEY
    // embedded in its sub-key ("seller_2-init-0" -> "seller_2"), NOT the base-role
    // top-level group. signed_initials nests N same-role signers' initials under a
    // base-role group ({ seller: { "seller_2-init-0": img } }); matching only the
    // top-level role put the 2nd co-seller's ink in the 1st seller's page-break box
    // (and left the 2nd's box empty). Building a per-recipient map and matching each
    // box by its own data-marker-party places every signer's initials in THEIR box.
    // recipientKey -> { list: [img@index], first: img }. PER-ANCHOR: keep every
    // captured initial at its OWN document-order index ("seller_2-init-2" -> idx 2)
    // so the k-th of a recipient's page-break boxes takes init-k — not init-0 for
    // all boxes (which bled the first initial across every page and dropped the
    // rest). Mirrors the per-anchor signature binding in CanonicalInkComposer.
    var byRecipient = {};
    Object.keys(storedInitials).forEach(function (topKey) {
        var group = storedInitials[topKey];
        if (!group || typeof group !== 'object') return;
        Object.keys(group).forEach(function (subKey) {
            var img = group[subKey];
            if (!img) return;
            var m = /^(.*)-init-(\d+)$/.exec(subKey);
            var recipientKey = (m ? m[1] : (topKey || '')).toLowerCase();
            var idx = m ? parseInt(m[2], 10) : 0;
            if (!byRecipient[recipientKey]) byRecipient[recipientKey] = { list: [], first: img };
            byRecipient[recipientKey].list[idx] = img;
        });
    });

    var seenByParty = {}; // recipientKey -> running box position (document order)
    allInitialEls.forEach(function (el) {
        var elParty = (el.getAttribute('data-marker-party') || '').toLowerCase();
        var rec = byRecipient[elParty];
        if (!rec) return; // this recipient captured no initial — leave their box empty
        var k = seenByParty[elParty] || 0;
        seenByParty[elParty] = k + 1; // advance even past a pre-inked box, to keep positions aligned
        if (el.getAttribute('data-signed')) return; // already inked (baked canonical) — keep it
        var img = rec.list[k] || rec.first; // this box's OWN initial, else adopt-once fallback
        if (!img) return;

        el.setAttribute('data-signed', 'true');
        el.style.cursor = 'default';
        el.style.opacity = '1';
        // ESIGN-WETINK — render restored initials at the SAME uniform initial size
        // as the baked canonical (was max-height:26px — the tiny-recipient-initial
        // bug). No emerald box (BUG6 lineage). The .corex-ink--initial class also
        // picks up the enforced ink spec above.
        el.innerHTML = '<img src="' + img + '" class="corex-ink corex-ink--initial" '
            + 'style="height:38px;max-height:38px;width:auto;object-fit:contain;" alt="Initial">';
    });
}

/**
 * Disclosure restore-on-render (§20). Shared, read-only re-application of a
 * signer's stored YES/NO/N/A disclosure answers onto a freshly-rendered grid.
 *
 * Stored answers (web_template_data['disclosure_answers']) are keyed
 * disclosure_row_0..N in document order — the exact order the signing-view
 * converter used. Signatures get embedded into merged_html; disclosure
 * answers do not, so any LATER viewer (agent review, a subsequent signer,
 * any future passive viewer) renders a blank grid unless restored here.
 *
 * Purely visual + read-only: marks the selected radio/placeholder, attaches
 * NO listeners. The reviewing agent (and any non-disclosing party) sees the
 * seller's selections but cannot alter them — the approved §20 legal rule.
 * Keyed off disclosure_answers, NOT per-template — works for every
 * disclosure grid (Addendum B #119, Seller Mandatory Addendum #120) and,
 * best-effort, the bare YES/NO/N/A table form (Sales Mandatory Disclosure
 * #123). Fail-open: any error leaves the grid untouched.
 */
// §20 — THE single source of disclosure-key derivation. The signing-view
// gate (disclosure-logic.blade.php), the persisted store, and the agent
// review restore (restoreStoredDisclosure below) ALL key disclosure rows
// through this one object. Two implementations of this rule is the exact
// defect that caused the prior rounds — there is now exactly one.
//
// Key is INTRINSIC + STATELESS: disclosure_<docKey>_<ordinal-of-this-row
// among the canonical disclosure rows of its own .corex-document-wrapper>.
// docKey is the instance-stable token stamped server-side. No counter, no
// walk-order or pack-position dependence: the same row yields the same key
// from any caller.
window.CoreXDisclosure = window.CoreXDisclosure || {
    docKeyOf: function (el) {
        var w = (el && el.closest) ? el.closest('.corex-document-wrapper') : null;
        var k = w ? (w.getAttribute('data-disclosure-doc') || '') : '';
        return (k && k.trim() !== '') ? k.trim() : 'doc';
    },
    // Canonical, ordered disclosure ANSWER rows within ONE wrapper:
    //   checklist form  -> every .corex-disclosure-row
    //   bare YES/NO/N/A -> tbody <tr> that are real answer/cert rows
    // (checklist-owned <table class="corex-disclosure-table"> excluded —
    // that IS the checklist form, counted above). ONE definition of the
    // row-set, consumed by the gate converters AND restore.
    rowsInWrapper: function (w) {
        if (!w) return [];
        var out = [];
        // Order MUST mirror _processAllDisclosures' per-scope order:
        // _processDisclosureTable (bare) THEN processWebDisclosureChecklists
        // (checklist), so the stateless ordinal == the gate's assignment.
        w.querySelectorAll('table').forEach(function (table) {
            if (table.classList.contains('corex-disclosure-table') ||
                table.closest('.corex-disclosure-checklist')) return;
            var ths = table.querySelectorAll('thead th');
            if (ths.length < 2) return;
            var H = Array.prototype.map.call(ths, function (h) {
                return (h.textContent || '').trim().toUpperCase();
            });
            var yi = H.indexOf('YES'), ni = H.indexOf('NO');
            if (yi === -1 || ni === -1) return;
            table.querySelectorAll('tbody tr').forEach(function (tr) {
                var tds = tr.querySelectorAll('td');
                if (tds.length < ths.length) return;
                if (tds.length === 1) return;
                var c0 = ((tds[0] && tds[0].textContent) || '').trim();
                var c1 = ((tds[1] && tds[1].textContent) || '').trim();
                if (tds.length > ths.length && !c0 &&
                    c1.indexOf('If Yes, when was it issued') !== -1) { out.push(tr); return; }
                if (!c0) return;
                var sub = (!(tds[yi] && tds[yi].textContent.trim())) &&
                          (!(tds[ni] && tds[ni].textContent.trim())) &&
                          c0.charAt(c0.length - 1) === ':';
                if (sub) return;
                out.push(tr);
            });
        });
        w.querySelectorAll('.corex-disclosure-row').forEach(function (r) { out.push(r); });
        return out;
    },
    _ordinal: function (rowEl) {
        var w = (rowEl && rowEl.closest) ? rowEl.closest('.corex-document-wrapper') : null;
        var rows = w ? this.rowsInWrapper(w) : [rowEl];
        var i = Array.prototype.indexOf.call(rows, rowEl);
        return i < 0 ? 0 : i;
    },
    keyForRow: function (rowEl) {
        return 'disclosure_' + this.docKeyOf(rowEl) + '_' + this._ordinal(rowEl);
    },
    dateKeyForRow: function (rowEl) {
        return 'disclosure_' + this.docKeyOf(rowEl) + '_date_' + this._ordinal(rowEl);
    },
    isAnswerKey: function (k) {
        return typeof k === 'string' && k.indexOf('disclosure_') === 0 && k.indexOf('_date_') === -1;
    }
};

// Read-only restore of stored YES/NO/N/A onto a freshly-rendered (NOT
// signing-Alpine) page — the agent review. Per .corex-document-wrapper
// (every pack segment), both the checklist AND the bare-table form, keyed
// via the ONE CoreXDisclosure rule so it matches what the gate stored.
function restoreStoredDisclosure(container, disclosureAnswers) {
    if (!container || !disclosureAnswers || typeof disclosureAnswers !== 'object') return;
    try {
        var CD = window.CoreXDisclosure;
        var wrappers = container.querySelectorAll('.corex-document-wrapper');
        var scopes = wrappers.length ? Array.prototype.slice.call(wrappers) : [container];
        scopes.forEach(function (w) {
            CD.rowsInWrapper(w).forEach(function (row) {
                var key = CD.keyForRow(row);
                var rawv = disclosureAnswers[key];
                var val = (rawv === undefined || rawv === null) ? '' : ('' + rawv).trim().toLowerCase();
                if (!val) return;
                // Checklist form
                var phs = row.querySelectorAll('.corex-radio-placeholder');
                if (phs.length) {
                    phs.forEach(function (ph) {
                        var sel = ((ph.getAttribute('data-value') || '').trim().toLowerCase() === val);
                        ph.setAttribute('data-selected', sel ? 'true' : 'false');
                        ph.textContent = sel ? '●' : '○';
                        ph.style.cursor = 'default';
                    });
                    return;
                }
                // Bare YES/NO/N/A <tr>: mark the matching column cell.
                var table = row.closest('table');
                if (!table) return;
                var ths = table.querySelectorAll('thead th');
                var col = {};
                Array.prototype.forEach.call(ths, function (th, ci) {
                    var t = (th.textContent || '').trim().toUpperCase();
                    if (t === 'YES') col.yes = ci;
                    else if (t === 'NO') col.no = ci;
                    else if (t === 'N/A' || t === 'NA') col.na = ci;
                });
                var tds = row.querySelectorAll('td');
                var target = val === 'yes' ? col.yes : (val === 'no' ? col.no : col.na);
                if (target === undefined || !tds[target]) return;
                tds[target].textContent = '●';
                tds[target].style.textAlign = 'center';
                row.querySelectorAll('input[type="radio"]').forEach(function (r) {
                    r.checked = ((r.value || '').trim().toLowerCase() === val);
                    r.disabled = true;
                });
            });
        });
    } catch (e) {
        if (window.console) console.warn('restoreStoredDisclosure failed', e);
    }
}

/**
 * Backward-compat wrapper — old views that call splitDocumentIntoPages() still work.
 */
function splitDocumentIntoPages(container) {
    paginateDocument(container, []);
}
</script>
