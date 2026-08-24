/**
 * Portal Capture — Popup Script
 * Orchestrates extraction, screenshot, and submission to Laravel.
 * Works with any property portal site.
 *
 * v1.1 — Auto-detects active presentation from Nexus tabs.
 *       — Uses chrome.cookies API for XSRF-TOKEN (fixes auth in Manifest V3).
 */

(function () {
    'use strict';

    var EXTRACTOR_VERSION = 'portal_ext_v1';

    var statusEl        = document.getElementById('status');
    var previewEl       = document.getElementById('preview');
    var captureBtn      = document.getElementById('capture-btn');
    var captureCloseBtn = document.getElementById('capture-close-btn');
    var captureButtons  = document.getElementById('capture-buttons');
    var configSection   = document.getElementById('config-section');
    var siteInfoEl      = document.getElementById('site-info');
    var siteBadgeEl     = document.getElementById('site-badge');
    var baseUrlInput    = document.getElementById('base-url');
    var presIdInput     = document.getElementById('presentation-id');
    var pageTypeSelect  = document.getElementById('page-type');
    var savingToEl      = document.getElementById('saving-to');
    var apiTokenInput   = document.getElementById('api-token');

    var allPagesSection  = document.getElementById('all-pages-section');
    var captureAllBtn    = document.getElementById('capture-all-btn');
    var allPagesProgress = document.getElementById('all-pages-progress');
    var allPagesFill     = document.getElementById('all-pages-progress-fill');
    var allPagesText     = document.getElementById('all-pages-text');

    var extractedResult = null;
    var screenshotB64   = null;
    var closeAfterCapture = false;
    var capturedTabId   = null;

    function setStatus(msg, type) {
        statusEl.textContent = msg;
        statusEl.className = 'status status-' + type;
    }

    function updateSavingTo(id, title) {
        if (!savingToEl) return;
        if (id) {
            var label = 'Saving to: Presentation #' + id;
            if (title) label += ' — ' + title;
            savingToEl.textContent = label;
            savingToEl.style.display = 'block';
        } else {
            savingToEl.style.display = 'none';
        }
    }

    // ── Load saved settings + auto-detected presentation ──
    chrome.storage.local.get(['baseUrl', 'presentationId', 'presentationTitle', 'apiToken'], function (items) {
        if (items.baseUrl) baseUrlInput.value = items.baseUrl;
        if (items.apiToken) apiTokenInput.value = items.apiToken;
        if (items.presentationId) {
            presIdInput.value = items.presentationId;
            updateSavingTo(items.presentationId, items.presentationTitle || '');
        }
    });
    baseUrlInput.addEventListener('change', function () {
        chrome.storage.local.set({ baseUrl: baseUrlInput.value });
    });
    presIdInput.addEventListener('change', function () {
        chrome.storage.local.set({ presentationId: presIdInput.value });
        updateSavingTo(presIdInput.value, '');
    });
    apiTokenInput.addEventListener('change', function () {
        chrome.storage.local.set({ apiToken: apiTokenInput.value });
    });

    // ── Also scan open tabs for a Nexus presentation page (in case background missed it) ──
    chrome.tabs.query({}, function (tabs) {
        var baseUrl = baseUrlInput.value.replace(/\/+$/, '');
        for (var i = 0; i < tabs.length; i++) {
            var tabUrl = tabs[i].url || '';
            var match = matchCorexPresentation(tabUrl);
            if (match) {
                var detectedId = match[1];
                var detectedTitle = (tabs[i].title || '').replace(/\s*[-|].*$/, '');
                // Only update if different from what's stored (prefer most recently detected)
                if (presIdInput.value !== detectedId) {
                    presIdInput.value = detectedId;
                    chrome.storage.local.set({
                        presentationId: detectedId,
                        presentationTitle: detectedTitle
                    });
                    updateSavingTo(detectedId, detectedTitle);
                }
                break;
            }
        }
    });

    // ── Get XSRF token via chrome.cookies API (works in Manifest V3 popup) ──
    function getXsrfToken(baseUrl) {
        return new Promise(function (resolve) {
            chrome.cookies.get({ url: baseUrl, name: 'XSRF-TOKEN' }, function (cookie) {
                if (cookie) {
                    resolve(decodeURIComponent(cookie.value));
                } else {
                    resolve('');
                }
            });
        });
    }

    // ── Get active tab and start extraction ──
    chrome.tabs.query({ active: true, currentWindow: true }, function (tabs) {
        var tab = tabs[0];
        if (!tab || !tab.url || tab.url.startsWith('chrome://')) {
            setStatus('Navigate to a property portal page first.', 'error');
            return;
        }

        var hostname = '';
        try { hostname = new URL(tab.url).hostname; } catch (e) {}

        siteInfoEl.style.display = 'block';
        siteBadgeEl.textContent = hostname;
        configSection.style.display = 'block';

        // Capture screenshot
        chrome.tabs.captureVisibleTab(null, { format: 'png' }, function (dataUrl) {
            if (dataUrl) {
                screenshotB64 = dataUrl.replace(/^data:image\/png;base64,/, '');
            }
        });

        // Inject content script and get extraction results
        chrome.scripting.executeScript({
            target: { tabId: tab.id },
            files: ['content.js']
        }, function (results) {
            if (chrome.runtime.lastError) {
                setStatus('Error: ' + chrome.runtime.lastError.message, 'error');
                return;
            }

            var result = results && results[0] && results[0].result;
            if (!result || !result.raw_html) {
                setStatus('Could not extract page content. Try refreshing.', 'error');
                return;
            }

            extractedResult = result;

            // If content.js detected a Nexus presentation (meta tags), store it
            if (result._hfc_presentation_id) {
                presIdInput.value = result._hfc_presentation_id;
                chrome.storage.local.set({
                    presentationId: result._hfc_presentation_id,
                    presentationTitle: result._hfc_presentation_title || ''
                });
                updateSavingTo(result._hfc_presentation_id, result._hfc_presentation_title || '');
            }

            // Set detected page type in dropdown
            if (result.detected_page_type && result.detected_page_type !== 'unknown') {
                pageTypeSelect.value = result.detected_page_type;
            }

            // Build preview
            var preview = 'Site: ' + hostname + '\n';
            preview += 'Type: ' + (result.detected_page_type || 'unknown') + '\n';
            preview += 'Title: ' + (result.page_title || '').substring(0, 80) + '\n';
            preview += 'HTML: ' + result.raw_html.length.toLocaleString() + ' bytes\n';
            preview += 'JSON-LD nodes: ' + result.jsonld.length + '\n';
            preview += 'Images found: ' + result.found_image_urls.length + '\n';
            preview += 'Parse status: ' + result.parse_status + '\n';

            if (result.parse_status === 'parsed' && result.extracted_fields) {
                preview += '\nExtracted fields:\n';
                Object.keys(result.extracted_fields).forEach(function (k) {
                    if (k.startsWith('_')) return;
                    preview += '  ' + k + ': ' + result.extracted_fields[k] + '\n';
                });
            }

            preview += '\nScreenshot: ' + (screenshotB64 ? 'captured' : 'pending...');

            previewEl.textContent = preview;
            previewEl.style.display = 'block';

            setStatus('Ready to capture', 'ok');
            captureButtons.style.display = 'flex';
            captureBtn.disabled = false;
            captureCloseBtn.disabled = false;
            capturedTabId = tab.id;

            // Show "Capture All Pages" if pagination detected
            var pag = result.extracted_fields && result.extracted_fields.pagination;
            if (pag && pag.total_pages > 1) {
                allPagesSection.style.display = 'block';
                captureAllBtn.textContent = 'Capture All Pages (' + pag.total_pages + ' pages)';
                captureAllBtn.disabled = false;
                preview += '\nPagination: page ' + pag.current_page + ' of ' + pag.total_pages;
                previewEl.textContent = preview;
            }
        });
    });

    // ── Shared capture function ──
    function doCapture(shouldCloseTab) {
        if (!extractedResult) return;

        captureBtn.disabled = true;
        captureCloseBtn.disabled = true;
        setStatus('Sending to Laravel...', 'info');

        var baseUrl = baseUrlInput.value.replace(/\/+$/, '');

        // Derive source_site from the page URL
        chrome.tabs.query({ active: true, currentWindow: true }, function (tabs) {
            var sourceUrl = tabs[0] ? tabs[0].url : '';
            var sourceSite = '';
            try { sourceSite = new URL(sourceUrl).hostname; } catch (e) {}

            var payload = {
                source_site:        sourceSite,
                page_type:          pageTypeSelect.value,
                source_url:         sourceUrl,
                final_url:          sourceUrl,
                page_title:         extractedResult.page_title || null,
                captured_at:        new Date().toISOString(),
                extractor_version:  EXTRACTOR_VERSION,
                html:               extractedResult.raw_html,
                screenshot:         screenshotB64 || null,
                parse_status:       extractedResult.parse_status || 'unparsed_jsonld_missing',
                extracted_fields:   extractedResult.extracted_fields || {},
                jsonld:             extractedResult.jsonld || [],
                found_image_urls:   extractedResult.found_image_urls || [],
            };

            var presId = presIdInput.value;
            if (presId) payload.presentation_id = parseInt(presId, 10);

            // POST capture — CSRF-exempt; auth via bearer token (preferred) or session cookie
            var headers = {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            };
            var token = apiTokenInput.value.trim();
            if (token) {
                headers['Authorization'] = 'Bearer ' + token;
            }

            fetch(baseUrl + '/portal-captures/ingest', {
                method: 'POST',
                headers: headers,
                credentials: 'include',
                body: JSON.stringify(payload),
            })
            .then(function (response) {
                if (response.status === 401) throw new Error('Not logged in. Open ' + baseUrl + ' and log in first.');
                if (response.status === 419) throw new Error('CSRF mismatch. Refresh Laravel page and try again.');
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            })
            .then(function (data) {
                if (data.success) {
                    setStatus('Captured! ID: ' + data.capture_id + ' (' + data.html_bytes.toLocaleString() + ' bytes)', 'ok');
                    previewEl.textContent += '\n\nCapture ID: ' + data.capture_id + '\nHash: ' + data.dom_hash;

                    if (shouldCloseTab && capturedTabId) {
                        // Find Nexus presentation tab and focus it, then close P24 tab
                        chrome.tabs.query({}, function (allTabs) {
                            var nexusTabId = null;
                            for (var i = 0; i < allTabs.length; i++) {
                                var tabUrl = allTabs[i].url || '';
                                if (isCorexPresentationUrl(tabUrl)) {
                                    nexusTabId = allTabs[i].id;
                                    break;
                                }
                            }
                            // Close the portal tab
                            chrome.tabs.remove(capturedTabId);
                            // Focus the Nexus tab if found
                            if (nexusTabId) {
                                chrome.tabs.update(nexusTabId, { active: true });
                            }
                        });
                    }
                } else {
                    setStatus('Server error: ' + JSON.stringify(data), 'error');
                    captureBtn.disabled = false;
                    captureCloseBtn.disabled = false;
                }
            })
            .catch(function (err) {
                setStatus('Error: ' + err.message, 'error');
                captureBtn.disabled = false;
                captureCloseBtn.disabled = false;
            });
        });
    }

    // ── Capture button (stay on page) ──
    captureBtn.addEventListener('click', function () {
        doCapture(false);
    });

    // ── Capture & Close button ──
    captureCloseBtn.addEventListener('click', function () {
        doCapture(true);
    });

    // ── Capture All Pages ──
    captureAllBtn.addEventListener('click', function () {
        if (!extractedResult) return;
        var pag = extractedResult.extracted_fields && extractedResult.extracted_fields.pagination;
        if (!pag || pag.total_pages <= 1) return;

        captureAllBtn.disabled = true;
        captureBtn.disabled = true;
        captureCloseBtn.disabled = true;
        allPagesProgress.style.display = 'block';
        allPagesText.style.display = 'block';
        allPagesText.textContent = 'Starting capture of ' + pag.total_pages + ' pages...';
        allPagesFill.style.width = '0%';

        var baseUrl = baseUrlInput.value.replace(/\/+$/, '');
        var token = apiTokenInput.value.trim();
        var presId = presIdInput.value;

        // Get source_site from current tab URL
        chrome.tabs.query({ active: true, currentWindow: true }, function (tabs) {
            var sourceUrl = tabs[0] ? tabs[0].url : '';
            var sourceSite = '';
            try { sourceSite = new URL(sourceUrl).hostname; } catch (e) {}

            chrome.runtime.sendMessage({
                action: 'captureAllPages',
                pageUrlTemplate: pag.page_url_template,
                totalPages: pag.total_pages,
                sourceSite: sourceSite,
                baseUrl: baseUrl,
                apiToken: token,
                presentationId: presId ? parseInt(presId, 10) : null,
                extractorVersion: EXTRACTOR_VERSION,
                // Send current page data so background can skip fetching it
                currentPage: pag.current_page,
                currentPageData: {
                    html: extractedResult.raw_html,
                    page_title: extractedResult.page_title,
                    extracted_fields: extractedResult.extracted_fields,
                    jsonld: extractedResult.jsonld,
                    found_image_urls: extractedResult.found_image_urls,
                    parse_status: extractedResult.parse_status,
                    screenshot: screenshotB64 || null,
                }
            });
        });
    });

    // ── Listen for progress updates from background ──
    chrome.runtime.onMessage.addListener(function (msg) {
        if (msg.action === 'captureAllPagesProgress') {
            var pct = Math.round((msg.completed / msg.total) * 100);
            allPagesFill.style.width = pct + '%';
            allPagesText.textContent = 'Page ' + msg.completed + ' of ' + msg.total +
                (msg.error ? ' (error: ' + msg.error + ')' : ' captured');
        }
        if (msg.action === 'captureAllPagesDone') {
            allPagesFill.style.width = '100%';
            var summary = msg.succeeded + ' of ' + msg.total + ' pages captured';
            if (msg.failed > 0) summary += ' (' + msg.failed + ' failed)';
            allPagesText.textContent = summary;
            setStatus('All pages captured! ' + summary, 'ok');
            captureBtn.disabled = false;
            captureCloseBtn.disabled = false;
        }
    });
})();
