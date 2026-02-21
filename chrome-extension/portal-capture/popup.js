/**
 * Portal Capture — Popup Script
 * Orchestrates extraction, screenshot, and submission to Laravel.
 * Works with any property portal site.
 */

(function () {
    'use strict';

    var EXTRACTOR_VERSION = 'portal_ext_v1';

    var statusEl        = document.getElementById('status');
    var previewEl       = document.getElementById('preview');
    var captureBtn      = document.getElementById('capture-btn');
    var configSection   = document.getElementById('config-section');
    var siteInfoEl      = document.getElementById('site-info');
    var siteBadgeEl     = document.getElementById('site-badge');
    var baseUrlInput    = document.getElementById('base-url');
    var presIdInput     = document.getElementById('presentation-id');
    var pageTypeSelect  = document.getElementById('page-type');

    var extractedResult = null;
    var screenshotB64   = null;

    function setStatus(msg, type) {
        statusEl.textContent = msg;
        statusEl.className = 'status status-' + type;
    }

    // Load saved settings
    chrome.storage.local.get(['baseUrl', 'presentationId'], function (items) {
        if (items.baseUrl) baseUrlInput.value = items.baseUrl;
        if (items.presentationId) presIdInput.value = items.presentationId;
    });
    baseUrlInput.addEventListener('change', function () {
        chrome.storage.local.set({ baseUrl: baseUrlInput.value });
    });
    presIdInput.addEventListener('change', function () {
        chrome.storage.local.set({ presentationId: presIdInput.value });
    });

    // Get active tab
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
                // Strip the data:image/png;base64, prefix
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
            captureBtn.style.display = 'block';
            captureBtn.disabled = false;
        });
    });

    // Capture button
    captureBtn.addEventListener('click', function () {
        if (!extractedResult) return;

        captureBtn.disabled = true;
        setStatus('Sending to Laravel...', 'info');

        var baseUrl = baseUrlInput.value.replace(/\/+$/, '');
        var hostname = '';
        try { hostname = new URL(extractedResult.raw_html ? window.location.href : '').hostname; } catch (e) {}

        // Derive source_site from the page URL
        var sourceUrl = '';
        chrome.tabs.query({ active: true, currentWindow: true }, function (tabs) {
            sourceUrl = tabs[0] ? tabs[0].url : '';
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

            // Get CSRF cookie then POST
            fetch(baseUrl + '/sanctum/csrf-cookie', { method: 'GET', credentials: 'include' })
            .then(function () {
                var xsrf = getCookie('XSRF-TOKEN');
                return fetch(baseUrl + '/portal-captures/ingest', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-XSRF-TOKEN': xsrf ? decodeURIComponent(xsrf) : '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'include',
                    body: JSON.stringify(payload),
                });
            })
            .then(function (response) {
                if (response.status === 401) throw new Error('Not logged in. Open ' + baseUrl + ' and log in first.');
                if (response.status === 419) throw new Error('CSRF mismatch. Refresh Laravel page.');
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            })
            .then(function (data) {
                if (data.success) {
                    setStatus('Captured! ID: ' + data.capture_id + ' (' + data.html_bytes.toLocaleString() + ' bytes)', 'ok');
                    previewEl.textContent += '\n\nCapture ID: ' + data.capture_id + '\nHash: ' + data.dom_hash;
                } else {
                    setStatus('Server error: ' + JSON.stringify(data), 'error');
                    captureBtn.disabled = false;
                }
            })
            .catch(function (err) {
                setStatus('Error: ' + err.message, 'error');
                captureBtn.disabled = false;
            });
        });
    });

    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? match[2] : null;
    }
})();
