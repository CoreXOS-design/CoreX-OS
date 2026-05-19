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
           (el.getAttribute('data-marker-index') || '');
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
 * pageIndex < lastPageIndex; the signature section is forced onto the last
 * page (§19.3) — a single-page document gets the signature block, no initial.
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

    // A4 content area: 210x297mm minus 20/25/18/18mm padding ≈ 658x ~1500px.
    var PAGE_CONTENT_HEIGHT = 1500;
    var PAGE_CONTENT_WIDTH = 658;
    var MIN_CLAUSE_VISIBLE = 100;

    var origW = wrapper.style.width, origMW = wrapper.style.maxWidth;
    wrapper.style.width = PAGE_CONTENT_WIDTH + 'px';
    wrapper.style.maxWidth = PAGE_CONTENT_WIDTH + 'px';
    if (innerPage) {
        innerPage.style.width = '100%'; innerPage.style.maxWidth = '100%';
        innerPage.style.padding = '0'; innerPage.style.margin = '0'; innerPage.style.minHeight = 'auto';
    }

    var pages = [];
    var cur = [];
    var curH = 0;
    var inSig = false;

    children.forEach(function (child, idx) {
        if (child.nodeType !== 1) { cur.push(child); return; }

        if (child.classList.contains('sig-section') ||
            child.classList.contains('corex-signature-section')) {
            inSig = true;
        }

        var rect = child.getBoundingClientRect();
        var cs = window.getComputedStyle(child);
        var h = rect.height + (parseFloat(cs.marginTop) || 0) + (parseFloat(cs.marginBottom) || 0);

        var tag = child.tagName;
        var isHeading = tag === 'H1' || tag === 'H2' || tag === 'H3' || tag === 'H4' ||
                        child.classList.contains('corex-h1') ||
                        child.classList.contains('corex-h2') ||
                        child.classList.contains('corex-h3') ||
                        child.classList.contains('corex-section-heading');
        var groupH = h;
        if (isHeading && idx + 1 < children.length && children[idx + 1].nodeType === 1) {
            var nr = children[idx + 1].getBoundingClientRect();
            var ns = window.getComputedStyle(children[idx + 1]);
            groupH += nr.height + (parseFloat(ns.marginTop) || 0) + (parseFloat(ns.marginBottom) || 0);
        }
        var isClause = child.classList.contains('corex-clause') &&
                       child.querySelector('.corex-clause-number');

        if (inSig) {
            cur.push(child); curH += h;
        } else if (isHeading && curH + groupH > PAGE_CONTENT_HEIGHT && cur.length > 0) {
            pages.push(cur); cur = [child]; curH = h;
        } else if (curH + h > PAGE_CONTENT_HEIGHT && cur.length > 0) {
            pages.push(cur); cur = [child]; curH = h;
        } else if (isClause && curH + h > PAGE_CONTENT_HEIGHT - MIN_CLAUSE_VISIBLE && cur.length > 0) {
            pages.push(cur); cur = [child]; curH = h;
        } else {
            cur.push(child); curH += h;
        }
    });
    if (cur.length > 0) pages.push(cur);

    wrapper.style.width = origW;
    wrapper.style.maxWidth = origMW;

    var total = pages.length;

    // Rebuild the wrapper in place (retained for split + per-doc identity).
    wrapper.innerHTML = '';
    pages.forEach(function (pageChildren, p) {
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
        box.style.cssText = 'width:60px;height:30px;border:1px solid #94a3b8;display:flex;align-items:center;justify-content:center;font-size:9px;color:#64748b;cursor:pointer;';
        box.innerHTML = '<span class="initial-placeholder">' + (party.label || party.role) + '</span>';
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

    // Flatten all party initials into a lookup by party role
    Object.keys(storedInitials).forEach(function(partyRole) {
        var partyInitials = storedInitials[partyRole];
        if (!partyInitials || typeof partyInitials !== 'object') return;

        // Find all initial elements for this party
        allInitialEls.forEach(function(el) {
            var elParty = (el.getAttribute('data-marker-party') || '').toLowerCase();
            if (elParty !== partyRole) return;

            // Check if any stored initial data exists for this party
            // Use the first available initial image (they're all the same for a party)
            var firstInitialData = null;
            for (var key in partyInitials) {
                if (partyInitials[key]) {
                    firstInitialData = partyInitials[key];
                    break;
                }
            }

            if (firstInitialData && !el.getAttribute('data-signed')) {
                el.setAttribute('data-signed', 'true');
                el.style.border = '2px solid #10b981';
                el.style.background = 'rgba(16,185,129,0.06)';
                el.style.cursor = 'default';
                el.style.opacity = '1';
                el.innerHTML = '<img src="' + firstInitialData + '" style="max-height:26px;max-width:56px;object-fit:contain;" alt="Initial">';
            }
        });
    });
}

/**
 * Backward-compat wrapper — old views that call splitDocumentIntoPages() still work.
 */
function splitDocumentIntoPages(container) {
    paginateDocument(container, []);
}
</script>
