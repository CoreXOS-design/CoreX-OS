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
function paginateDocument(container, parties) {
    if (!container) return;
    if (container.dataset.paginated === 'true') return;
    container.dataset.paginated = 'true';

    parties = parties || [];

    // ── Strategy 1: Paged templates (multiple .corex-page divs) ──
    var wrapper = container.querySelector('.corex-document-wrapper');
    var searchIn = wrapper || container;
    var corexPages = Array.from(searchIn.children).filter(function(el) {
        return el.classList && el.classList.contains('corex-page');
    });

    if (corexPages.length > 1) {
        // Collect <style>/<link> tags to preserve
        var styles = [];
        Array.from(container.children).forEach(function(el) {
            if (el.tagName === 'STYLE' || el.tagName === 'LINK') styles.push(el);
        });

        var pageEls = Array.from(corexPages);
        container.innerHTML = '';
        styles.forEach(function(s) { container.appendChild(s); });

        var totalPages = pageEls.length;
        pageEls.forEach(function(pageEl, idx) {
            var pageDiv = document.createElement('div');
            pageDiv.className = 'corex-a4-page';
            while (pageEl.firstChild) pageDiv.appendChild(pageEl.firstChild);

            // Page number
            var pageNum = document.createElement('div');
            pageNum.className = 'page-number';
            pageNum.textContent = 'Page ' + (idx + 1) + ' of ' + totalPages;
            pageDiv.appendChild(pageNum);

            // Initials between pages (not after last)
            if (idx < totalPages - 1 && parties.length > 0) {
                pageDiv.appendChild(_buildInitialsRow(parties, idx));
            }

            container.appendChild(pageDiv);

            if (idx < totalPages - 1) {
                var gap = document.createElement('div');
                gap.className = 'corex-page-gap';
                container.appendChild(gap);
            }
        });
        return;
    }

    // ── Strategy 2: Continuous HTML — measure actual element heights ──

    // A4 content area dimensions (accounting for .corex-a4-page padding)
    // Page: 210mm x 297mm.  Padding: 20mm top, 25mm bottom, 18mm left/right.
    // Content area: 174mm x 252mm.  At 96dpi ≈ 658px x 953px.
    var PAGE_CONTENT_HEIGHT = 1500;
    var PAGE_CONTENT_WIDTH = 658;

    // Find the innermost content container (unwrap nested wrappers)
    var contentEl = container;
    var innerWrapper = container.querySelector('.corex-document-wrapper');
    if (innerWrapper) contentEl = innerWrapper;
    var innerPage = contentEl.querySelector('.corex-page');
    if (innerPage && innerPage.parentElement === contentEl) contentEl = innerPage;

    // Collect <style>/<link> tags before restructuring
    var styleEls = [];
    Array.from(container.querySelectorAll('style, link[rel="stylesheet"]')).forEach(function(el) {
        styleEls.push(el.cloneNode(true));
    });

    // Remove any legacy .corex-page-break markers (from old server-side injection)
    contentEl.querySelectorAll('.corex-page-break').forEach(function(el) { el.remove(); });

    // Get all direct children to measure
    var children = Array.from(contentEl.children);
    if (children.length === 0) return;

    // Temporarily set container to A4 content width for accurate height measurement
    var origStyles = {
        width: container.style.width,
        maxWidth: container.style.maxWidth,
        position: container.style.position,
        visibility: container.style.visibility
    };
    container.style.width = PAGE_CONTENT_WIDTH + 'px';
    container.style.maxWidth = PAGE_CONTENT_WIDTH + 'px';

    // Also strip wrapper/page styling that could interfere with measurement
    if (innerWrapper) {
        innerWrapper.style.width = '100%';
        innerWrapper.style.maxWidth = '100%';
        innerWrapper.style.padding = '0';
        innerWrapper.style.margin = '0';
        innerWrapper.style.boxShadow = 'none';
    }
    if (innerPage && innerPage !== contentEl) {
        innerPage.style.width = '100%';
        innerPage.style.maxWidth = '100%';
        innerPage.style.padding = '0';
        innerPage.style.margin = '0';
        innerPage.style.minHeight = 'auto';
    }

    // Measure each child and assign to pages
    var pages = [];
    var currentPage = [];
    var currentHeight = 0;
    var inSigSection = false;

    // Minimum visible content (px) a clause must have on a page to avoid orphaning
    var MIN_CLAUSE_VISIBLE = 100;

    children.forEach(function(child, idx) {
        // Skip non-element nodes (text nodes, comments)
        if (child.nodeType !== 1) {
            currentPage.push(child);
            return;
        }

        // Skip <style> and <link> — they'll be re-added globally
        if (child.tagName === 'STYLE' || child.tagName === 'LINK') return;

        // Detect signature section — keep it together on the last page
        if (child.classList.contains('sig-section') ||
            child.classList.contains('corex-signature-section')) {
            inSigSection = true;
        }

        var rect = child.getBoundingClientRect();
        var childHeight = rect.height;
        // Include margins in height calculation
        var computedStyle = window.getComputedStyle(child);
        var marginTop = parseFloat(computedStyle.marginTop) || 0;
        var marginBottom = parseFloat(computedStyle.marginBottom) || 0;
        childHeight += marginTop + marginBottom;

        // Check if this element is a heading / section header
        var tag = child.tagName;
        var isHeading = tag === 'H1' || tag === 'H2' || tag === 'H3' || tag === 'H4' ||
                        child.classList.contains('corex-h1') ||
                        child.classList.contains('corex-h2') ||
                        child.classList.contains('corex-h3') ||
                        child.classList.contains('corex-section-heading');

        // If heading, measure height INCLUDING the next sibling so they stay together
        var groupHeight = childHeight;
        if (isHeading && idx + 1 < children.length && children[idx + 1].nodeType === 1) {
            var nextRect = children[idx + 1].getBoundingClientRect();
            var nextStyle = window.getComputedStyle(children[idx + 1]);
            groupHeight += nextRect.height + (parseFloat(nextStyle.marginTop) || 0) + (parseFloat(nextStyle.marginBottom) || 0);
        }

        // Check if this is a numbered clause that would be orphaned at page bottom
        var isNumberedClause = child.classList.contains('corex-clause') &&
                               child.querySelector('.corex-clause-number');

        if (inSigSection) {
            // Inside signature section — never break
            currentPage.push(child);
            currentHeight += childHeight;
        } else if (isHeading && currentHeight + groupHeight > PAGE_CONTENT_HEIGHT && currentPage.length > 0) {
            // Heading + its first child would overflow — push heading to next page
            pages.push(currentPage);
            currentPage = [child];
            currentHeight = childHeight;
        } else if (currentHeight + childHeight > PAGE_CONTENT_HEIGHT && currentPage.length > 0) {
            // Normal overflow — start new page
            pages.push(currentPage);
            currentPage = [child];
            currentHeight = childHeight;
        } else if (isNumberedClause && currentHeight + childHeight > PAGE_CONTENT_HEIGHT - MIN_CLAUSE_VISIBLE && currentPage.length > 0) {
            // Numbered clause would barely fit — less than MIN_CLAUSE_VISIBLE visible. Push to next page.
            pages.push(currentPage);
            currentPage = [child];
            currentHeight = childHeight;
        } else {
            currentPage.push(child);
            currentHeight += childHeight;
        }
    });

    // Last page
    if (currentPage.length > 0) {
        pages.push(currentPage);
    }

    // Restore container styles
    container.style.width = origStyles.width;
    container.style.maxWidth = origStyles.maxWidth;
    container.style.position = origStyles.position;
    container.style.visibility = origStyles.visibility;

    // If only 1 page, no need to wrap — just strip inner container styling
    if (pages.length <= 1) {
        _stripInnerStyling(container);
        return;
    }

    // Rebuild DOM: clear container, re-add styles, then wrap each page in .corex-a4-page
    container.innerHTML = '';
    styleEls.forEach(function(s) { container.appendChild(s); });

    var totalPages = pages.length;
    pages.forEach(function(pageChildren, pageIdx) {
        var pageDiv = document.createElement('div');
        pageDiv.className = 'corex-a4-page';

        // Move children into page wrapper
        pageChildren.forEach(function(child) { pageDiv.appendChild(child); });

        // Page number footer
        var pageNum = document.createElement('div');
        pageNum.className = 'page-number';
        pageNum.textContent = 'Page ' + (pageIdx + 1) + ' of ' + totalPages;
        pageDiv.appendChild(pageNum);

        // Initials between pages (not after last)
        if (pageIdx < totalPages - 1 && parties.length > 0) {
            pageDiv.appendChild(_buildInitialsRow(parties, pageIdx));
        }

        container.appendChild(pageDiv);

        // Gap between pages (not after last)
        if (pageIdx < totalPages - 1) {
            var gap = document.createElement('div');
            gap.className = 'corex-page-gap';
            container.appendChild(gap);
        }
    });

    // Strip inner container styling
    _stripInnerStyling(container);
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
 * Backward-compat wrapper — old views that call splitDocumentIntoPages() still work.
 */
function splitDocumentIntoPages(container) {
    paginateDocument(container, []);
}
</script>
