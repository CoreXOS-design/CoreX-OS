{{-- Shared A4 page rendering: CSS + JS split function --}}
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
/* Also for when split hasn't run yet but document is in a page container */
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
function splitDocumentIntoPages(container) {
    if (!container) return;
    // Guard against double invocation (e.g. init + method choice in external signing)
    if (container.dataset.pagesSplit === 'true') return;
    container.dataset.pagesSplit = 'true';
    var breaks = container.querySelectorAll('.corex-page-break');
    if (breaks.length === 0) return;

    var allNodes = Array.from(container.childNodes);
    var pages = [];
    var currentPageNodes = [];

    allNodes.forEach(function(node) {
        if (node.nodeType === 1 && node.classList && node.classList.contains('corex-page-break')) {
            // Page break marker belongs to current page (initials at bottom)
            currentPageNodes.push(node);
            pages.push(currentPageNodes);
            currentPageNodes = [];
        } else {
            currentPageNodes.push(node);
        }
    });

    // Last page (content after final break)
    if (currentPageNodes.length > 0) {
        pages.push(currentPageNodes);
    }

    // Clear container and rebuild with A4 page wrappers
    container.innerHTML = '';
    var totalPages = pages.length;

    pages.forEach(function(pageNodes, idx) {
        var pageDiv = document.createElement('div');
        pageDiv.className = 'corex-a4-page';
        pageNodes.forEach(function(n) { pageDiv.appendChild(n); });

        // Page number footer
        var pageNum = document.createElement('div');
        pageNum.className = 'page-number';
        pageNum.textContent = 'Page ' + (idx + 1) + ' of ' + totalPages;
        pageDiv.appendChild(pageNum);

        container.appendChild(pageDiv);

        // Gap between pages (not after last)
        if (idx < totalPages - 1) {
            var gap = document.createElement('div');
            gap.className = 'corex-page-gap';
            container.appendChild(gap);
        }
    });

    // Strip inner container styling — the template HTML contains .corex-document-wrapper
    // and .corex-page with their own width/shadow/padding. Now that .corex-a4-page handles
    // all page styling, neutralize the inner containers to prevent nested white boxes.
    container.querySelectorAll('.corex-document-wrapper').forEach(function(el) {
        el.style.width = '100%';
        el.style.maxWidth = '100%';
        el.style.boxShadow = 'none';
        el.style.background = 'transparent';
        el.style.margin = '0';
        el.style.padding = '0';
    });
    container.querySelectorAll('.corex-page').forEach(function(el) {
        el.style.width = '100%';
        el.style.maxWidth = '100%';
        el.style.boxShadow = 'none';
        el.style.background = 'transparent';
        el.style.margin = '0';
        el.style.padding = '0';
        el.style.minHeight = 'auto';
    });
}
</script>
