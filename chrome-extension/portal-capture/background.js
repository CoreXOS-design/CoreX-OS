/**
 * Portal Capture — Background Service Worker
 * Auto-detects active presentation when agent visits a Nexus presentation page.
 * Stores the presentation ID + title in chrome.storage.local for the popup.
 *
 * v1.2 — Handles "Capture All Pages" for multi-page P24 search results.
 * v1.3 — Host matching moved to corex-hosts.js (see there): this file used to
 *        recognise corex.hfcoastal.co.za only, which broke when that hostname
 *        was retired to a redirect.
 */

importScripts('corex-hosts.js');

chrome.tabs.onUpdated.addListener(function (tabId, changeInfo, tab) {
    // Fire on URL change or page load complete (to capture final title)
    if (!changeInfo.url && changeInfo.status !== 'complete') return;

    var url = tab.url || changeInfo.url || '';
    if (!url) return;

    // Match presentation pages on any CoreX host — see corex-hosts.js
    var match = matchCorexPresentation(url);
    if (match) {
        chrome.storage.local.set({
            presentationId: match[1],
            presentationTitle: (tab.title || '').replace(/\s*[-|].*$/, ''),
            presentationDetectedAt: Date.now()
        });
    }
});

// ── Capture All Pages handler ──
chrome.runtime.onMessage.addListener(function (msg, sender, sendResponse) {
    if (msg.action !== 'captureAllPages') return;

    var template = msg.pageUrlTemplate;
    var totalPages = msg.totalPages;
    var sourceSite = msg.sourceSite;
    var baseUrl = msg.baseUrl;
    var apiToken = msg.apiToken;
    var presId = msg.presentationId;
    var extractorVersion = msg.extractorVersion || 'portal_ext_v1';
    var currentPage = msg.currentPage || 1;
    var currentPageData = msg.currentPageData || null;

    var succeeded = 0;
    var failed = 0;

    // Build correct P24 pagination URL.
    // Template has /p{page} in path. Page 1 has no /p1 — strip it.
    // Also strips any &p=N query param — P24 uses ONLY /pN in the path.
    function buildPageUrl(tpl, pageNum) {
        var url;
        if (pageNum <= 1) {
            url = tpl.replace('/p{page}', '');
        } else {
            url = tpl.replace('{page}', String(pageNum));
        }
        // Strip ?p=N or &p=N query param if present (safety net)
        url = url.replace(/([?&])p=\d+(&?)/g, function (match, pre, post) {
            if (pre === '?' && post === '&') return '?';
            if (pre === '?' && post === '') return '';
            return post ? '' : '';
        });
        url = url.replace(/[?&]$/, '');
        console.log('[Portal Capture] buildPageUrl page=' + pageNum + ' → ' + url);
        return url;
    }

    function buildHeaders() {
        var headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        };
        if (apiToken) {
            headers['Authorization'] = 'Bearer ' + apiToken;
        }
        return headers;
    }

    function notifyProgress(completed, total, error) {
        chrome.runtime.sendMessage({
            action: 'captureAllPagesProgress',
            completed: completed,
            total: total,
            error: error || null,
        }).catch(function () { /* popup may be closed */ });
    }

    function notifyDone() {
        chrome.runtime.sendMessage({
            action: 'captureAllPagesDone',
            total: totalPages,
            succeeded: succeeded,
            failed: failed,
        }).catch(function () { /* popup may be closed */ });
    }

    // Submit a single page's capture to the server
    function submitCapture(pageNum, html, pageTitle, screenshot) {
        var pageUrl = buildPageUrl(template, pageNum);
        var payload = {
            source_site: sourceSite,
            page_type: 'search',
            source_url: pageUrl,
            final_url: pageUrl,
            page_title: pageTitle || null,
            captured_at: new Date().toISOString(),
            extractor_version: extractorVersion,
            html: html,
            screenshot: screenshot || null,
            parse_status: 'unparsed_jsonld_missing',
            extracted_fields: {},
            jsonld: [],
            found_image_urls: [],
        };
        if (presId) payload.presentation_id = presId;

        return fetch(baseUrl + '/portal-captures/ingest', {
            method: 'POST',
            headers: buildHeaders(),
            credentials: 'include',
            body: JSON.stringify(payload),
        })
        .then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then(function (data) {
            if (data.success) {
                succeeded++;
            } else {
                failed++;
            }
        })
        .catch(function () {
            failed++;
        });
    }

    // Process pages sequentially to avoid overwhelming the server
    async function captureAllPages() {
        for (var p = 1; p <= totalPages; p++) {
            try {
                if (p === currentPage && currentPageData) {
                    // Use already-extracted data for the current page
                    await submitCapture(
                        p,
                        currentPageData.html,
                        currentPageData.page_title,
                        currentPageData.screenshot
                    );
                } else {
                    // Fetch the page HTML
                    var pageUrl = buildPageUrl(template, p);
                    console.log('[Portal Capture] Fetching page ' + p + ': ' + pageUrl);
                    var response = await fetch(pageUrl, {
                        credentials: 'omit',
                        headers: { 'Accept': 'text/html' },
                    });
                    if (!response.ok) {
                        failed++;
                        notifyProgress(p, totalPages, 'HTTP ' + response.status);
                        continue;
                    }
                    var html = await response.text();
                    var titleMatch = html.match(/<title[^>]*>(.*?)<\/title>/i);
                    var pageTitle = titleMatch ? titleMatch[1].trim() : '';
                    await submitCapture(p, html, pageTitle, null);
                }
                notifyProgress(p, totalPages);
            } catch (err) {
                failed++;
                notifyProgress(p, totalPages, err.message);
            }
        }
        notifyDone();
    }

    captureAllPages();
    return true; // keep message channel open for async
});
