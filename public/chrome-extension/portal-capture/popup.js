/**
 * CoreX — Popup Controller v3.0
 *
 * Two-mode popup:
 *   1. Pull Property — scrape a single P24 listing detail page → create Property in CoreX
 *   2. Capture Listings — multi-page search scrape → prospecting (existing flow)
 *
 * States:
 *   notOnPortal → informational message
 *   settings    → API URL + token form
 *   choose      → two action cards (Pull Property / Capture Listings)
 *   ready       → portal detected, show search info (capture flow)
 *   capturing   → progress bar + ETA (capture flow)
 *   complete    → results summary (capture flow)
 *   resume      → incomplete capture detected (capture flow)
 *   pullPreview → property preview before pulling
 *   pulling     → indeterminate progress (pull flow)
 *   pullComplete → success + link to property (pull flow)
 */

(function () {
  'use strict';

  // Show the REAL manifest version in the popup header, so the popup and
  // chrome://extensions always agree (the old hardcoded "v3.0.0" string made a
  // correctly-packaged build look stale and misled a live diagnosis).
  try {
    var _vEl = document.getElementById('extVersion') || document.querySelector('.version');
    if (_vEl && chrome.runtime && chrome.runtime.getManifest) {
      _vEl.textContent = 'v' + chrome.runtime.getManifest().version;
    }
  } catch (e) { /* keep the fallback text */ }

  // ── DOM refs ───────────────────────────────────────────────
  const states = {
    notOnPortal:  document.getElementById('stateNotOnPortal'),
    settings:     document.getElementById('stateSettings'),
    choose:       document.getElementById('stateChoose'),
    ready:        document.getElementById('stateReady'),
    capturing:    document.getElementById('stateCapturing'),
    complete:     document.getElementById('stateComplete'),
    resume:       document.getElementById('stateResume'),
    pullPreview:  document.getElementById('statePullPreview'),
    pulling:      document.getElementById('statePulling'),
    pullComplete: document.getElementById('statePullComplete'),
    oasPreview:   document.getElementById('stateOasPreview'),
    oasComplete:  document.getElementById('stateOasComplete'),
  };

  const els = {
    // Settings
    settingsToggle:    document.getElementById('settingsToggle'),
    backFromSettings:  document.getElementById('backFromSettings'),
    apiUrl:            document.getElementById('apiUrl'),
    apiToken:          document.getElementById('apiToken'),
    saveSettings:      document.getElementById('saveSettings'),
    settingsMsg:       document.getElementById('settingsMsg'),
    // Choose
    choosePortalName:  document.getElementById('choosePortalName'),
    actionPullProperty:    document.getElementById('actionPullProperty'),
    actionCaptureListings: document.getElementById('actionCaptureListings'),
    actionImportOas:   document.getElementById('actionImportOas'),
    actionHint:        document.getElementById('actionHint'),
    // Other Agency Stock
    backFromOas:       document.getElementById('backFromOas'),
    oasThumb:          document.getElementById('oasThumb'),
    oasTitle:          document.getElementById('oasTitle'),
    oasPrice:          document.getElementById('oasPrice'),
    oasAddress:        document.getElementById('oasAddress'),
    oasFeatures:       document.getElementById('oasFeatures'),
    oasImagesCount:    document.getElementById('oasImagesCount'),
    oasAgency:         document.getElementById('oasAgency'),
    oasConsentCheck:   document.getElementById('oasConsentCheck'),
    oasConsentText:    document.getElementById('oasConsentText'),
    oasImportBtn:      document.getElementById('oasImportBtn'),
    oasMsg:            document.getElementById('oasMsg'),
    oasCompleteTitle:  document.getElementById('oasCompleteTitle'),
    oasCompleteDetail: document.getElementById('oasCompleteDetail'),
    oasViewProperty:   document.getElementById('oasViewProperty'),
    oasAnother:        document.getElementById('oasAnother'),
    // Ready (capture)
    backFromReady:     document.getElementById('backFromReady'),
    portalName:        document.getElementById('portalName'),
    searchTerm:        document.getElementById('searchTerm'),
    resultCount:       document.getElementById('resultCount'),
    captureBtn:        document.getElementById('captureBtn'),
    // Capturing
    progressBar:       document.getElementById('progressBar'),
    progressText:      document.getElementById('progressText'),
    progressEta:       document.getElementById('progressEta'),
    progressBatch:     document.getElementById('progressBatch'),
    cancelBtn:         document.getElementById('cancelBtn'),
    // Complete
    completeTotal:     document.getElementById('completeTotal'),
    completeBreakdown: document.getElementById('completeBreakdown'),
    viewInCorex:       document.getElementById('viewInCorex'),
    captureAnother:    document.getElementById('captureAnother'),
    // Resume
    resumeText:        document.getElementById('resumeText'),
    resumeBtn:         document.getElementById('resumeBtn'),
    startFreshBtn:     document.getElementById('startFreshBtn'),
    // Pull preview
    backFromPull:      document.getElementById('backFromPull'),
    pullThumb:         document.getElementById('pullThumb'),
    pullTitle:         document.getElementById('pullTitle'),
    pullPrice:         document.getElementById('pullPrice'),
    pullAddress:       document.getElementById('pullAddress'),
    pullFeatures:      document.getElementById('pullFeatures'),
    pullImagesCount:   document.getElementById('pullImagesCount'),
    actionOwnershipWarning: document.getElementById('actionOwnershipWarning'),
    pullBtn:           document.getElementById('pullBtn'),
    // Pulling (image progress)
    pullImagesDetail:  document.getElementById('pullImagesDetail'),
    // Pull complete
    pullCompleteTitle:  document.getElementById('pullCompleteTitle'),
    pullCompleteDetail: document.getElementById('pullCompleteDetail'),
    viewProperty:      document.getElementById('viewProperty'),
    pullAnother:       document.getElementById('pullAnother'),
    // Shared
    errorMsg:          document.getElementById('errorMsg'),
    connectionDot:     document.getElementById('connectionDot'),
    connectionText:    document.getElementById('connectionText'),
    lastCaptureBar:    document.getElementById('lastCaptureBar'),
    lastCaptureText:   document.getElementById('lastCaptureText'),
    duplicateWarning:  document.getElementById('duplicateWarning'),
    duplicateText:     document.getElementById('duplicateText'),
    duplicateConfirm:  document.getElementById('duplicateConfirm'),
    duplicateCancel:   document.getElementById('duplicateCancel'),
  };

  // 2026-09-29 Johan's ruling: the single-listing "Pull as My Own Listing"
  // action is hidden — "Import as Other Agency Stock" is the only
  // single-listing P24/PP action for now. Code stays intact (not deleted)
  // so this is a one-line flip to restore, not a rebuild. Bulk MIC import
  // is a completely separate flow and is untouched by this flag.
  const PULL_PROPERTY_ACTION_ENABLED = false;
  if (!PULL_PROPERTY_ACTION_ENABLED && els.actionPullProperty) {
    els.actionPullProperty.style.display = 'none';
  }

  // ── State ──────────────────────────────────────────────────
  let currentState   = null;
  let previousState  = null;
  let statusPoller   = null;
  let pageInfo       = null;
  let propertyData   = null;
  let tabId          = null;
  let tabUrl         = null;
  let detectedPortal = null;
  let settings       = { apiUrl: '', apiToken: '' };
  let cachedPropertyDetailTabId = null;

  // ── Helpers ────────────────────────────────────────────────

  // 2026-09-29 Pomona incident — property #21094: visually make the
  // correct choice obvious, not just enabled/disabled. $primary gets the
  // highlighted style; $secondary is demoted (still fully clickable —
  // never actually blocked, an agent's own judgement still wins).
  function highlightAction(primaryEl, secondaryEl) {
    primaryEl.style.borderColor = '#0ea5e9';
    primaryEl.style.boxShadow = '0 0 0 1px #0ea5e9';
    primaryEl.style.order = '-1';
    secondaryEl.style.opacity = '0.6';
    secondaryEl.style.borderColor = '';
    secondaryEl.style.boxShadow = 'none';
    secondaryEl.style.order = '';
  }

  function showState(name) {
    previousState = currentState;
    currentState  = name;
    Object.keys(states).forEach(k => states[k].classList.remove('active'));
    if (states[name]) states[name].classList.add('active');
  }

  function showError(msg) {
    els.errorMsg.textContent = msg;
    els.errorMsg.style.display = 'block';
    setTimeout(() => { els.errorMsg.style.display = 'none'; }, 8000);
  }

  function hideError() {
    els.errorMsg.style.display = 'none';
  }

  // Honest, multi-state connection indicator. "Token expired" is no longer
  // shown the same as "offline".
  const CONNECTION_STATES = {
    checking:     { cls: 'checking',     text: 'Checking connection…' },
    connected:    { cls: '',             text: 'Connected to CoreX' },
    auth:         { cls: 'disconnected', text: 'Login rejected — token expired. Re-authenticate in Settings.' },
    no_agency:    { cls: 'warning',      text: 'Logged in, but no agency selected — imports are rejected.' },
    server_error: { cls: 'warning',      text: 'CoreX reachable but erroring — imports may fail.' },
    unreachable:  { cls: 'disconnected', text: 'CoreX unreachable — check your network.' },
    no_token:     { cls: 'disconnected', text: 'Not connected — add your API token in Settings.' },
  };

  function setConnection(state) {
    const m = CONNECTION_STATES[state] || CONNECTION_STATES.unreachable;
    els.connectionDot.className = ('dot ' + m.cls).trim();
    els.connectionText.textContent = m.text;
  }

  // Ask the background worker to actually probe CoreX (same auth path as import),
  // then reflect the real result on the dot. Returns the health object.
  async function refreshConnection() {
    setConnection('checking');
    try {
      const res = await chrome.runtime.sendMessage({
        action: 'healthCheck',
        apiUrl: settings.apiUrl,
        apiToken: settings.apiToken,
      });
      setConnection(res && res.state ? res.state : 'unreachable');
      return res || { state: 'unreachable' };
    } catch (e) {
      setConnection('unreachable');
      return { state: 'unreachable' };
    }
  }

  // Drain the durable queue in gentle chunks while the popup is open, showing
  // honest progress. The background alarm continues any remainder after close.
  async function drainQueueLoop() {
    let guard = 0;
    while (guard++ < 300) {
      let res;
      try {
        res = await chrome.runtime.sendMessage({
          action: 'flushLocalQueue',
          apiUrl: settings.apiUrl,
          apiToken: settings.apiToken,
        });
      } catch (e) { break; }
      if (!res) break;

      const qs = await chrome.runtime.sendMessage({ action: 'getQueueStatus' });
      const remaining = (qs && qs.count) || 0;

      if (res.stop) {
        if (res.stop === 'auth' || res.stop === 'validation') {
          await refreshConnection();
          showError(remaining + ' batches still queued — re-authenticate to send them (nothing lost).');
        } else {
          showError(remaining + ' batches still queued — CoreX unreachable/erroring; will retry automatically.');
        }
        break;
      }
      if (res.done || remaining === 0) {
        showError('All queued batches sent to CoreX.');
        await refreshConnection();
        break;
      }
      showError('Sending queued batches… ' + remaining + ' remaining.');
      await new Promise(r => setTimeout(r, 300));
    }
  }

  function formatTime(ms) {
    const secs = Math.round(ms / 1000);
    if (secs < 60) return secs + 's';
    const mins = Math.floor(secs / 60);
    const rem  = secs % 60;
    if (mins < 60) return '~' + mins + 'm ' + rem + 's';
    const hrs = Math.floor(mins / 60);
    return '~' + hrs + 'h ' + (mins % 60) + 'm';
  }

  function formatPrice(price) {
    if (!price) return '';
    return 'R ' + price.toLocaleString('en-ZA');
  }

  // ── Settings ───────────────────────────────────────────────
  async function loadSettings() {
    return new Promise(resolve => {
      chrome.storage.local.get(['apiUrl', 'apiToken'], data => {
        settings.apiUrl   = data.apiUrl   || 'https://www.corexos.co.za';
        settings.apiToken = data.apiToken || '';
        resolve(settings);
      });
    });
  }

  async function saveSettingsToStorage() {
    const url   = els.apiUrl.value.trim().replace(/\/+$/, '');
    const token = els.apiToken.value.trim();

    if (!url || !token) {
      showError('Both API URL and token are required.');
      return;
    }

    settings.apiUrl   = url;
    settings.apiToken = token;

    return new Promise(resolve => {
      chrome.storage.local.set({ apiUrl: url, apiToken: token }, async () => {
        els.settingsMsg.innerHTML = '<div class="success-msg">Settings saved!</div>';
        // Verify the new token actually works, rather than assuming it does.
        const health = await refreshConnection();
        if (health.state === 'connected') {
          // A freshly-fixed token — try to send anything that was stuck.
          const qs = await chrome.runtime.sendMessage({ action: 'getQueueStatus' });
          if (qs && qs.count > 0) drainQueueLoop();
        }
        setTimeout(() => { els.settingsMsg.innerHTML = ''; }, 2000);
        resolve();
      });
    });
  }

  // ── Last capture status bar ────────────────────────────────
  async function showLastCapture() {
    return new Promise(resolve => {
      chrome.storage.local.get('lastCapture', data => {
        const info = data.lastCapture;
        if (info && info.timestamp) {
          const date = new Date(info.timestamp);
          const dateStr = date.toLocaleDateString('en-ZA', {
            day: 'numeric', month: 'short', year: 'numeric',
          });
          const timeStr = date.toLocaleTimeString('en-ZA', {
            hour: '2-digit', minute: '2-digit',
          });
          els.lastCaptureText.textContent =
            'Last: ' + dateStr + ' ' + timeStr +
            ' — ' + (info.count || 0).toLocaleString() + ' ' + (info.type || 'listings') +
            ' from ' + (info.portal || '?');
          els.lastCaptureBar.style.display = 'block';
        }
        resolve();
      });
    });
  }

  // ── Portal detection ───────────────────────────────────────
  function detectPortal(url) {
    if (!url) return null;
    if (url.includes('property24.com'))        return 'p24';
    if (url.includes('privateproperty.co.za')) return 'pp';
    return null;
  }

  function portalLabel(portal) {
    return portal === 'p24' ? 'Property24' : 'Private Property';
  }

  // ── Request page type from content script ──────────────────
  function requestPageType(tid) {
    return new Promise((resolve, reject) => {
      chrome.tabs.sendMessage(tid, { action: 'getPageType' }, response => {
        if (chrome.runtime.lastError) {
          reject(new Error(chrome.runtime.lastError.message));
          return;
        }
        resolve(response);
      });
    });
  }

  // ── Request page info from content script (search pages) ───
  function requestPageInfo(tid) {
    return new Promise((resolve, reject) => {
      chrome.tabs.sendMessage(tid, { action: 'getPageInfo' }, response => {
        if (chrome.runtime.lastError) {
          reject(new Error(chrome.runtime.lastError.message));
          return;
        }
        resolve(response);
      });
    });
  }

  // ── Request property detail from content script ────────────
  function requestPropertyDetail(tid) {
    return new Promise((resolve, reject) => {
      chrome.tabs.sendMessage(tid, { action: 'getPropertyDetail' }, response => {
        if (chrome.runtime.lastError) {
          reject(new Error(chrome.runtime.lastError.message));
          return;
        }
        resolve(response);
      });
    });
  }

  // ── Duplicate check (capture flow) ─────────────────────────
  async function checkDuplicate(searchUrl) {
    return new Promise(resolve => {
      chrome.runtime.sendMessage({
        action: 'checkDuplicateSearch',
        apiUrl: settings.apiUrl,
        apiToken: settings.apiToken,
        searchUrl: searchUrl,
      }, response => {
        resolve(response || { duplicate: false });
      });
    });
  }

  // ══════════════════════════════════════════════════════════
  // ── CAPTURE LISTINGS FLOW (existing) ──────────────────────
  // ══════════════════════════════════════════════════════════

  async function initCaptureFlow() {
    hideError();

    try {
      pageInfo = await requestPageInfo(tabId);

      if (!pageInfo || !pageInfo.isSearchPage) {
        showError('Navigate to a search results page first.');
        return;
      }

      pageInfo.portal     = detectedPortal;
      pageInfo.currentUrl = tabUrl;

      els.portalName.textContent  = portalLabel(detectedPortal) + ' detected';
      els.searchTerm.textContent  = pageInfo.searchTerm || 'Search results';
      els.resultCount.textContent =
        (pageInfo.totalResults || '?') + ' listings' +
        (pageInfo.totalPages ? ' (' + pageInfo.totalPages + ' pages)' : '');

      showState('ready');

    } catch (err) {
      showError('Could not read search page. Try refreshing the page.');
    }
  }

  async function startCapture() {
    hideError();
    els.duplicateWarning.style.display = 'none';
    showState('capturing');
    startStatusPolling();

    try {
      await chrome.runtime.sendMessage({
        action: 'flushLocalQueue',
        apiUrl: settings.apiUrl,
        apiToken: settings.apiToken,
      });
    } catch (e) { /* ignore */ }

    chrome.runtime.sendMessage({
      action:       'startCapture',
      portal:       pageInfo.portal,
      baseUrl:      pageInfo.currentUrl,
      searchTerm:   pageInfo.searchTerm || '',
      totalPages:   pageInfo.totalPages || 1,
      totalResults: pageInfo.totalResults || 0,
      apiUrl:       settings.apiUrl,
      apiToken:     settings.apiToken,
      tabId:        tabId,
    });
  }

  function startStatusPolling() {
    stopStatusPolling();
    statusPoller = setInterval(pollStatus, 800);
  }

  function stopStatusPolling() {
    if (statusPoller) {
      clearInterval(statusPoller);
      statusPoller = null;
    }
  }

  async function pollStatus() {
    try {
      const status = await chrome.runtime.sendMessage({ action: 'getCaptureStatus' });
      if (!status) return;

      if (status.active) {
        showState('capturing');
        updateProgress(status);
      } else if (status.complete) {
        stopStatusPolling();
        showCaptureComplete(status);
      } else if (status.error && !status.active) {
        stopStatusPolling();
        showError(status.error);
        // If CoreX rejected our token/agency, reflect it honestly on the dot.
        if (status.authError) refreshConnection();
        showState('ready');
      }
    } catch (e) {
      stopStatusPolling();
    }
  }

  function updateProgress(status) {
    const pct = status.totalPages > 0
      ? Math.round((status.currentPage / status.totalPages) * 100) : 0;
    els.progressBar.style.width = pct + '%';
    els.progressText.textContent =
      'Capturing page ' + status.currentPage + ' of ' + status.totalPages +
      '... (' + status.capturedListings.toLocaleString() + ' listings)';

    if (status.currentPage > 0 && status.totalPages > 1) {
      const rem = status.totalPages - status.currentPage;
      if (rem > 0) {
        els.progressEta.textContent = 'Estimated time remaining: ' + formatTime(rem * 1500);
      } else {
        els.progressEta.textContent = 'Finishing...';
      }
    } else {
      els.progressEta.textContent = '';
    }

    if (status.batchesSent > 0) {
      els.progressBatch.textContent =
        status.sentListings.toLocaleString() + ' sent to CoreX (' +
        status.batchesSent + ' batches)';
    } else {
      els.progressBatch.textContent = '';
    }

    if (status.error) {
      els.progressEta.textContent = status.error;
    }
  }

  function showCaptureComplete(status) {
    const total = (status.importedCount || 0) + (status.updatedCount || 0);
    const captured = status.capturedListings || 0;
    const displayed = total || captured;

    els.completeTotal.textContent = displayed.toLocaleString() + ' listings captured!';
    els.completeBreakdown.textContent =
      'New: ' + (status.importedCount || 0) +
      ' | Updated: ' + (status.updatedCount || 0);

    if (status.parseWarnings > 0) {
      els.completeBreakdown.textContent +=
        ' | ' + status.parseWarnings + ' pages had parsing issues';
    }

    els.viewInCorex.href = settings.apiUrl + '/prospecting';
    showState('complete');
    showLastCapture();
  }

  // ══════════════════════════════════════════════════════════
  // ── PULL PROPERTY FLOW (new) ──────────────────────────────
  // ══════════════════════════════════════════════════════════

  async function initPullFlow() {
    hideError();
    showState('pullPreview');
    els.pullTitle.textContent = 'Loading property details...';
    els.pullPrice.textContent = '';
    els.pullAddress.textContent = '';
    els.pullFeatures.innerHTML = '';
    els.pullImagesCount.textContent = '';
    els.pullThumb.innerHTML = '';
    els.actionOwnershipWarning.style.display = 'none';
    els.pullBtn.disabled = true;

    try {
      const result = await requestPropertyDetail(tabId);

      if (!result || result.error || !result.property) {
        showError(result?.error || 'Could not extract property data from this page.');
        showState('choose');
        return;
      }

      propertyData = result.property;

      // 2026-09-29 Pomona incident — property #21094: an agent used this
      // action ("Pull as My Own Listing") on a listing that belonged to a
      // DIFFERENT agency, which silently created a plain draft with none
      // of Other Agency Stock's consent, source-tracking or field mapping.
      // Cross-check the page's own listing agency (already extracted by
      // the existing content script — no new page read needed) against
      // this user's OWN CoreX agency; a mismatch is a strong signal they
      // meant "Import as Other Agency Stock" instead. Never blocks the
      // pull outright (agency names can legitimately differ by spelling/
      // trading name) — surfaces a clear warning and lets the agent decide.
      try {
        const ctxRes = await chrome.runtime.sendMessage({ action: 'oasConsentWording', apiUrl: settings.apiUrl, apiToken: settings.apiToken });
        const ownAgency = (ctxRes && ctxRes.agency_name) ? ctxRes.agency_name.trim().toLowerCase() : null;
        const listingAgency = propertyData.agency_name ? String(propertyData.agency_name).trim().toLowerCase() : null;
        if (ownAgency && listingAgency && ownAgency !== listingAgency) {
          els.actionOwnershipWarning.textContent = 'This listing is advertised by "' + propertyData.agency_name
            + '", not your own agency. If it is NOT your agency\'s mandate, go back and use "Import as Other Agency Stock" instead.';
          els.actionOwnershipWarning.style.display = 'block';
        } else {
          els.actionOwnershipWarning.style.display = 'none';
        }
      } catch (e) { /* best-effort — never block the pull on this check failing */ }

      // Fill preview
      els.pullTitle.textContent = propertyData.title || 'Untitled Property';
      els.pullPrice.textContent = formatPrice(propertyData.price);
      els.pullAddress.textContent = [
        propertyData.address,
        propertyData.suburb,
        propertyData.city || propertyData.region,
      ].filter(Boolean).join(', ') || 'Address not available';

      // Features
      const feats = [];
      if (propertyData.beds != null) feats.push('<span class="feat">' + propertyData.beds + ' Bed</span>');
      if (propertyData.baths != null) feats.push('<span class="feat">' + propertyData.baths + ' Bath</span>');
      if (propertyData.garages != null) feats.push('<span class="feat">' + propertyData.garages + ' Garage</span>');
      if (propertyData.erf_size_m2) feats.push('<span class="feat">' + propertyData.erf_size_m2.toLocaleString() + ' m²</span>');
      els.pullFeatures.innerHTML = feats.join('');

      // Image count
      const imgCount = (propertyData.images || []).length;
      els.pullImagesCount.textContent = imgCount + ' image' + (imgCount !== 1 ? 's' : '') + ' will be imported';

      // Thumbnail
      if (propertyData.images && propertyData.images.length > 0) {
        const img = document.createElement('img');
        img.src = propertyData.images[0];
        img.alt = 'Property thumbnail';
        els.pullThumb.appendChild(img);
      }

      els.pullBtn.disabled = false;

    } catch (err) {
      showError('Failed to read property: ' + err.message);
      showState('choose');
    }
  }

  // ── Other Agency Stock — .ai/specs/other-agency-stock.md §5 ──────────
  // Extraction runs via chrome.scripting.executeScript directly from the
  // popup (not through a registered content script) so PP's read can use
  // world:'MAIN' (the page's own already-parsed window.serverVariables) —
  // content scripts always run ISOLATED and cannot see it.

  let oasExtracted = null; // the built payload, set once extraction succeeds
  let oasConsentWording = 'I confirm I have received permission from this agency to use their portal advert.';

  // <<P24_OAS_EXTRACT_BEGIN>> — tests/p24-oas-extract.test.cjs slices this exact
  // function out of this file and runs it against saved P24 pages.
  function p24ExtractOasFn() {
    // Runs ISOLATED-world in the P24 tab. Self-contained — no outer closures
    // (chrome.scripting serialises the function body, so every helper lives inside).
    function getJsonLd() {
      var scripts = document.querySelectorAll('script[type]');
      for (var i = 0; i < scripts.length; i++) {
        var t = (scripts[i].getAttribute('type') || '').toLowerCase();
        // The type attribute can be HTML-entity-encoded (application/ld&#x2B;json).
        if (t.indexOf('application/ld') !== 0 && t.indexOf('application/ld&') !== 0) continue;
        try {
          var data = JSON.parse(scripts[i].textContent);
          var graph = data['@graph'] && Array.isArray(data['@graph']) ? data['@graph'] : [data];
          for (var j = 0; j < graph.length; j++) {
            if (graph[j]['@type'] === 'RealEstateListing' || graph[j]['@type'] === 'Product') return graph[j];
          }
        } catch (e) { /* skip */ }
      }
      return null;
    }
    // 2026-10-07 (P24 import 117621889): ONE decimal-aware number reader for every
    // numeric field. The old num() deleted every non-digit, so "2.5" bathrooms
    // became 25 and "1 375.5" became 13755. P24 writes "R 1 590" / "664 m²" /
    // "2.5" (space thousands, dot decimal); "1,375" and "2,5" are read too.
    // Returns null — never 0 — when there is no number at all.
    function parseNumber(v) {
      if (v === null || v === undefined) return null;
      if (typeof v === 'number') return isFinite(v) ? v : null;
      var s = String(v).replace(/[\s  ]/g, '');
      var m = s.match(/\d[\d.,]*/);
      if (!m) return null;
      var t = m[0].replace(/[.,]+$/, '');
      var lastDot = t.lastIndexOf('.'), lastComma = t.lastIndexOf(',');
      if (lastDot !== -1 && lastComma !== -1) {
        // Both present: whichever comes last is the decimal separator.
        t = lastComma > lastDot ? t.replace(/\./g, '').replace(',', '.') : t.replace(/,/g, '');
      } else if (lastComma !== -1) {
        // Comma only: "1,375" / "1,375,000" are thousands, "2,5" is a decimal.
        t = /^\d{1,3}(,\d{3})+$/.test(t) ? t.replace(/,/g, '') : t.replace(',', '.');
      } else if (lastDot !== -1 && (t.match(/\./g) || []).length > 1) {
        t = t.replace(/\./g, ''); // "1.375.000" — dots as thousands
      }
      var n = parseFloat(t);
      return isNaN(n) ? null : n;
    }
    // A size as square metres: "664 m²" / "1 375 m²" -> as-is; "1.2 ha" and
    // "0.5 acres" are converted. Two decimals at most.
    function parseArea(v) {
      var n = parseNumber(v);
      if (n === null) return null;
      var s = String(v).toLowerCase();
      if (/m\s*[²2]|sqm|sq\.?\s*m/.test(s)) return Math.round(n * 100) / 100;
      if (/\bha\b|hectare/.test(s)) return Math.round(n * 10000 * 100) / 100;
      if (/acre/.test(s)) return Math.round(n * 4046.86 * 100) / 100;
      return Math.round(n * 100) / 100;
    }
    function clean(s) { return String(s === null || s === undefined ? '' : s).replace(/\s+/g, ' ').trim(); }
    function splitList(s) { return String(s || '').split(/[\n,]/).map(clean).filter(Boolean); }
    function textOf(sel) { var el = document.querySelector(sel); return el ? el.textContent.trim() : null; }
    function galleryImageId(img) {
      var attrs = ['lazy-src', 'data-src', 'src'];
      for (var a = 0; a < attrs.length; a++) {
        var val = img.getAttribute(attrs[a]) || '';
        var gm = val.match(/images\.prop24\.com\/(\d+)\//);
        if (gm) return parseInt(gm[1], 10);
      }
      return null;
    }

    var ld = getJsonLd() || {};
    var about = ld.about || ld;
    var offers = ld.offers || {};

    var listingRef = null;
    var m = (document.title || '').match(/P24-(\d+)/);
    if (m) listingRef = m[1];
    if (!listingRef) {
      m = location.pathname.match(/\/(\d{5,12})(?:\/|$)/);
      if (m) listingRef = m[1];
    }

    // .ai/specs/other-agency-stock.md §5 — 2026-09-29 Pomona field-mapping
    // fix. P24's own URL shape is /for-sale/{suburb}/{city}/{province}/
    // {p24_suburb_id}/{listing_id} (to-rent for rentals) — sale-vs-rent and
    // P24's OWN external suburb id are both sitting right there, confirmed
    // live against 117485980 (/for-sale/pomona/kempton-park/gauteng/1350/
    // 117485980 -> sale, suburb id 1350). The server resolves this id via
    // P24LocationResolver::resolveByP24Id() — the extension sends the raw
    // signal only, never guesses suburb/city/province text itself.
    var listingType = /^\/to-rent\//i.test(location.pathname) ? 'rental' : 'sale';
    var p24SuburbExternalId = null;
    var pathParts = location.pathname.split('/').filter(Boolean);
    if (pathParts.length >= 5 && /^\d+$/.test(pathParts[4])) {
      p24SuburbExternalId = parseInt(pathParts[4], 10);
    }

    var leadCtx = null;
    try {
      var scriptText = document.documentElement.outerHTML;
      var lm = scriptText.match(/listingLeadFormContext\s*=\s*(\{[\s\S]*?\});/);
      if (lm) leadCtx = JSON.parse(lm[1]);
    } catch (e) { /* ignore */ }

    // Informational only now (the gallery download below never touches a
    // photo URL list to filter) — still sent for display/consistency with
    // the PP payload shape.
    var agencyLogoUrl = (offers.offeredBy && offers.offeredBy.worksFor && offers.offeredBy.worksFor.logo) || null;

    // 2026-10-07 (P24 import 117621889): the gallery is the ORDERED, de-duplicated
    // list of ids in the page's own thumbnail strip (img.js_galleryThumbnail,
    // lazy-src until the browser has loaded it). The earlier "first id + count,
    // then add 1 each time" guess is wrong — checked against six saved pages,
    // the ids are NOT consecutive on five of them (gaps, a later image with a
    // lower id, a second batch uploaded weeks later), so the import pulled
    // other listings' photos and missed real ones. The thumbnail strip count
    // equalled P24's own "N images" figure on all six pages.
    var imageIds = [];
    try {
      document.querySelectorAll('img.js_galleryThumbnail').forEach(function (img) {
        var gid = galleryImageId(img);
        if (gid && imageIds.indexOf(gid) === -1) imageIds.push(gid);
      });
    } catch (e) { /* fall back below */ }

    var firstImageId = null;
    var imageCount = 0;
    if (imageIds.length) {
      firstImageId = imageIds[0];
      imageCount = imageIds.length;
    } else {
      // Last resort only (no thumbnail strip found): the previous heuristic.
      try {
        var galleryImg = document.querySelector('.js_mainThreeImage img, .p24_printGalleryImage img');
        if (galleryImg) {
          var gsrc = galleryImg.getAttribute('src') || '';
          var gmatch = gsrc.match(/images\.prop24\.com\/(\d+)/);
          if (gmatch) firstImageId = parseInt(gmatch[1], 10);
        }
      } catch (e) { /* */ }
      if (!firstImageId) {
        try {
          var ogImg = document.querySelector('meta[property="og:image"]');
          if (ogImg) {
            var ogMatch = ogImg.getAttribute('content').match(/images\.prop24\.com\/(\d+)/);
            if (ogMatch) firstImageId = parseInt(ogMatch[1], 10);
          }
        } catch (e) { /* */ }
      }
      try {
        var galleryEl = document.querySelector('.p24_gallery');
        if (galleryEl) {
          var countMatch = galleryEl.textContent.match(/(\d+)\s*image/i);
          if (countMatch) imageCount = parseInt(countMatch[1], 10);
        }
      } catch (e) { /* */ }
      if (!imageCount) {
        try {
          var bodyMatch = document.body.innerText.match(/(\d+)\s*image/i);
          if (bodyMatch) imageCount = parseInt(bodyMatch[1], 10);
        } catch (e) { /* */ }
      }
      if (!imageCount && firstImageId) {
        imageCount = document.querySelectorAll('.js_mainThreeImage').length || 1;
      }
    }

    // 2026-09-30 field audit (property #21098, Norkem Park): Levies, Rates
    // and Taxes, Listing Date, Pets Allowed, Zoning, Parking, Pool, Kitchen,
    // Garden, Security all sit in the .p24_propertyOverviewRow table as
    // beds/baths/floor — identical markup (.p24_propertyOverviewKey label +
    // .p24_propertyOverviewResult .p24_info value). Raw text is sent AS-IS;
    // the server-side shared mapper (OtherAgencyStockFieldMapper) does the
    // currency/zoning parsing and Carbon parses "17 July 2026" natively —
    // same "extension sends raw, server maps" split property_type already uses.
    //
    // 2026-10-07 (P24 import 117621889): labels are matched EXACTLY. The old
    // `label contains "floor"` also caught "Floor" (= "Tiled Floors"), "Floor
    // Number" and "Number of floors" further down the table and overwrote the
    // real "Floor Size" (a 287 m² house imported as 1 m²; an apartment lost its
    // floor size entirely). Same for erf: the size button next to the icons is
    // the FLOOR size on apartments/townhouses, so it is only an erf size when
    // its own title says so.
    var erfSize = null, floorSize = null, beds = null, baths = null;
    var garagesRow = null, parkingAggregate = null, coveredParking = null;
    var typeRow = null, streetRow = null;
    var levyRaw = null, ratesTaxesRaw = null, listingDateRaw = null, petsAllowedRaw = null,
        zoningRaw = null, poolYes = false, gardenYes = false,
        kitchenFeatures = [], gardenFeatures = [],
        bathroomFeatures = [], parkingTextFeatures = [];
    // 2026-09-30 REGRESSION FIX (property #21098): the "Parking" row can be an
    // AGGREGATE that undercounts — P24 also renders one row PER parking spot:
    // "Parking 1" -> "1 Carport", "Parking 2" -> "1 open parking". Collected
    // separately and preferred over the aggregate when present.
    var parkingSubRows = [];
    document.querySelectorAll('.p24_propertyOverviewRow').forEach(function (row) {
      var keyEl = row.querySelector('.p24_propertyOverviewKey');
      var resEl = row.querySelector('.p24_propertyOverviewResult');
      if (!keyEl || !resEl) return;
      var k2 = clean(keyEl.textContent).toLowerCase();
      var infos = Array.prototype.slice.call(resEl.querySelectorAll('.p24_info')).map(function (e) { return e.textContent; });
      var rawFirst = infos.length ? infos[0] : resEl.textContent;
      var vText = clean(rawFirst);
      var extras = infos.slice(1).map(clean).filter(Boolean);
      if (!vText) return;

      if (k2 === 'type of property') typeRow = vText;
      else if (k2 === 'street address') streetRow = clean(resEl.textContent);
      else if (k2 === 'floor size') floorSize = parseArea(vText);
      else if (k2 === 'erf size') erfSize = parseArea(vText);
      else if (k2 === 'bedrooms' || k2 === 'bedroom') beds = parseNumber(vText);
      else if (k2 === 'bathrooms' || k2 === 'bathroom') {
        baths = parseNumber(vText);
        // A second .p24_info in the SAME row is a free-text note ("Shower
        // only"), confirmed live. Kept as one entry per block, never split.
        bathroomFeatures = bathroomFeatures.concat(extras);
      }
      else if (k2 === 'garage' || k2 === 'garages') garagesRow = parseNumber(vText);
      else if (k2 === 'parking') {
        var pn = parseNumber(vText);
        // "Parking | 1 | Carport parking, Secure parking" (count + feature list)
        // or "Parking | Single Parking" (no count, the type itself).
        if (/^\d/.test(vText) && pn !== null) parkingAggregate = pn; else parkingTextFeatures = parkingTextFeatures.concat(splitList(rawFirst));
        extras.forEach(function (x) { parkingTextFeatures = parkingTextFeatures.concat(splitList(x)); });
      }
      else if (/^parking \d+$/.test(k2)) parkingSubRows.push(vText);
      else if (k2 === 'covered parking' || k2 === 'carport' || k2 === 'carports') coveredParking = parseNumber(vText);
      else if (k2 === 'levies') levyRaw = vText;
      else if (k2 === 'rates and taxes') ratesTaxesRaw = vText;
      else if (k2 === 'listing date') listingDateRaw = vText;
      else if (k2 === 'pets allowed') petsAllowedRaw = vText;
      else if (k2 === 'zoning') zoningRaw = vText;
      // "Pool | Yes" on most pages, "Pool | Pool" on others — anything but "No".
      else if (k2 === 'pool') poolYes = !/^no$/i.test(vText);
      else if (k2 === 'kitchen' || k2 === 'kitchens') {
        // "Kitchens | 1 | Open plan with fitted hob" (a count, then a note) or
        // "Kitchen | Gas Oven, Gas Hob" (the list itself).
        if (/^\d+$/.test(vText)) kitchenFeatures = kitchenFeatures.concat(extras);
        else kitchenFeatures = kitchenFeatures.concat(splitList(rawFirst));
      }
      else if (k2 === 'garden' || k2 === 'gardens') {
        var gItems = splitList(rawFirst);
        // "Garden | Yes" / "Garden | Garden" is the flag, not a feature called "Yes".
        gardenYes = gItems.length > 0 && !/^no$/i.test(gItems[0]);
        gardenFeatures = gItems.filter(function (g) { return !/^(yes|no|garden)$/i.test(g); });
      }
    });
    var petsAllowed = petsAllowedRaw ? /^yes$/i.test(petsAllowedRaw) : null;

    // 2026-10-07 (P24 import 117580701): EVERY row of every accordion on the page
    // (Property Overview, Rooms/Facilities, External Features, Building, Other
    // Features) and the tags beside the icon strip ("Furnished", "Pet Friendly",
    // "Fibre Internet" …), sent RAW. The server (OtherAgencyStockFeatureMapper)
    // decides which CoreX features each one ticks — the extension only reads.
    // Values keep their line breaks (a Security list is one block with newlines).
    // "Points of Interest" is skipped on purpose: P24 loads it on demand from a
    // separate request (nearby schools/shops), it is not part of the advert.
    var featureRows = [];
    document.querySelectorAll('.panel').forEach(function (panel) {
      var head = panel.querySelector('.panel-heading');
      if (!head) return;
      var headSpan = head.querySelector('span');
      var section = clean((headSpan || head).textContent);
      if (/^points of interest$/i.test(section)) return;
      panel.querySelectorAll('.p24_propertyOverviewRow').forEach(function (row) {
        var kEl = row.querySelector('.p24_propertyOverviewKey');
        var rEl = row.querySelector('.p24_propertyOverviewResult');
        if (!kEl || !rEl) return;
        var vals = Array.prototype.slice.call(rEl.querySelectorAll('.p24_info'))
          .map(function (e) { return e.textContent.trim(); }).filter(Boolean);
        if (!vals.length && rEl.textContent.trim()) vals = [rEl.textContent.trim()];
        var kText = clean(kEl.textContent);
        if (kText && vals.length) featureRows.push({ s: section, k: kText, v: vals });
      });
    });
    var stripTags = [];
    document.querySelectorAll('.p24_listingFeatures').forEach(function (blk) {
      if (blk.querySelector('.p24_featureAmount')) return; // bedrooms/baths/garages/parking: read as counts above
      var lab = blk.querySelector('.p24_feature');
      var tag = lab ? clean(lab.textContent).replace(/:$/, '') : '';
      if (tag && stripTags.indexOf(tag) === -1) stripTags.push(tag);
    });

    // 2026-10-07 (P24 import 117580701): the title is the advert's own heading — the
    // <h5> at the top of the description card ("Beautifully situated Coastal Property
    // with sea views"). JSON-LD `name` and the page <h1> are only P24's generic line
    // ("3 Bedroom Townhouse for sale in Uvongo"), kept as the fallback. When an advert
    // has no heading P24 prints a generic one there too ("House For Sale in Umhlali
    // Golf Estate Ballito KwaZulu Natal", "Apartment To Rent in Ballito Central,
    // Ballito, KwaZulu Natal") — "<type> for sale/to rent in <place>" ending in a
    // province — and that is treated as no heading.
    var advertHeading = null;
    try {
      var headingEl = document.querySelector('.p24_listingAbout h5');
      var headingText = headingEl ? clean(headingEl.textContent) : '';
      var looksGeneric = /\b(for sale|to rent|for rent|to let)\s+in\s+/i.test(headingText)
        && /(kwazulu[\s-]?natal|gauteng|western cape|eastern cape|free state|limpopo|mpumalanga|north west|northern cape)\s*$/i.test(headingText);
      if (headingText && !looksGeneric) advertHeading = headingText;
    } catch (e) { /* fall back to the generic line */ }

    // The icon strip "Features" block: <span class="p24_feature">Garages:</span>
    // <span class="p24_featureAmount">2</span>. This markup replaced the old one
    // where the garage icon sat INSIDE .p24_feature (the old lookup found no
    // garage icon's .p24_feature ancestor any more, so garages were never read —
    // import 117621889 showed "—" for a 2-garage house).
    var keyFeat = {};
    document.querySelectorAll('.p24_listingFeatures').forEach(function (blk) {
      var lab = blk.querySelector('.p24_feature');
      var amt = blk.querySelector('.p24_featureAmount');
      if (!lab || !amt) return;
      keyFeat[clean(lab.textContent).replace(/:$/, '').toLowerCase()] = parseNumber(amt.textContent);
    });

    var legacyGarages = null;
    try {
      var garageImg = document.querySelector('img[src*="icon_garage"]');
      var garageFeature = garageImg ? garageImg.closest('.p24_feature') : null;
      var amountEl = garageFeature ? garageFeature.querySelector('.p24_featureAmount') : null;
      if (amountEl) legacyGarages = parseNumber(amountEl.textContent);
    } catch (e) { /* */ }

    // Garages, parking and covered parking stay SEPARATE counts: the icon
    // strip's "Parking Spaces" figure is garages + parking added together, so
    // it is never read. Garage -> garages; open/covered bays -> Parking.
    var garages = garagesRow !== null ? garagesRow : (keyFeat.garages !== undefined && keyFeat.garages !== null ? keyFeat.garages : legacyGarages);
    var parkingBase = parkingSubRows.length > 0 ? parkingSubRows.length
      : (parkingAggregate !== null ? parkingAggregate : (keyFeat.parking !== undefined ? keyFeat.parking : null));
    var parkingCount = parkingBase;
    var parkingFeatures = parkingSubRows.length > 0 ? parkingSubRows : parkingTextFeatures;
    if (coveredParking !== null && coveredParking > 0) {
      parkingCount = (parkingBase || 0) + coveredParking;
      parkingFeatures = parkingFeatures.concat(['Covered parking']);
    }

    if (beds === null && keyFeat.bedrooms !== undefined) beds = keyFeat.bedrooms;
    if (beds === null && about.numberOfBedrooms !== undefined) beds = parseNumber(about.numberOfBedrooms);
    if (baths === null && keyFeat.bathrooms !== undefined) baths = keyFeat.bathrooms;
    if (baths === null && about.numberOfBathroomsTotal !== undefined) baths = parseNumber(about.numberOfBathroomsTotal);

    // The size button beside the icons carries its own title ("Erf Size" /
    // "Floor Size") — used only to fill a size the overview table did not give.
    try {
      var sizeBtn = document.querySelector('.js_sizeConversionsButton');
      var sizeSpan = sizeBtn ? sizeBtn.querySelector('span') : null;
      if (sizeBtn && sizeSpan) {
        var sizeTitle = (sizeBtn.getAttribute('title') || '').toLowerCase();
        if (sizeTitle.indexOf('erf') !== -1 && erfSize === null) erfSize = parseArea(sizeSpan.textContent);
        else if (sizeTitle.indexOf('floor') !== -1 && floorSize === null) floorSize = parseArea(sizeSpan.textContent);
      }
    } catch (e) { /* */ }
    if (floorSize === null && about.floorSize && about.floorSize.value !== undefined) floorSize = parseArea(about.floorSize.value + ' m2');

    // 2026-09-29 URGENT FIX #2 (Clayville): ld.description is P24's JSON-LD
    // headline ("Stunning 2 Bedroom House In Clayville Ext 45" — one line,
    // not the listing body). Ported from content-p24-detail.js's proven
    // multi-strategy extraction (DOM selectors -> meta description ->
    // longest paragraph) so OAS imports get the same full description the
    // old Pull Property path already gets, falling back to ld.description
    // only if every DOM strategy comes up empty.
    var fullDescription = null;
    var descSelectors = [
      '.p24_description', '.p24_listingDetail .js_readMore', '[itemprop="description"]',
      '.js_expandedText', '.p24_expandedText', '[class*="listing-description"]',
      '[class*="listingDescription"]', '[class*="property-description"]', '.p24_content .p24_excerpt',
    ];
    for (var ds = 0; ds < descSelectors.length; ds++) {
      try {
        var descEl = document.querySelector(descSelectors[ds]);
        if (descEl && descEl.textContent.trim().length > 20) {
          fullDescription = descEl.textContent.trim().substring(0, 5000);
          break;
        }
      } catch (e) { /* ignore */ }
    }
    if (!fullDescription) {
      try {
        var descMeta = document.querySelector('meta[name="description"]') || document.querySelector('meta[property="og:description"]');
        if (descMeta) {
          var descContent = descMeta.getAttribute('content');
          if (descContent && descContent.length > 20) fullDescription = descContent.trim();
        }
      } catch (e) { /* ignore */ }
    }
    if (!fullDescription) {
      try {
        var paragraphs = document.querySelectorAll('p, div[class*="description"], div[class*="Description"]');
        var longest = '';
        paragraphs.forEach(function (p) {
          var t = p.textContent.trim();
          if (t.length > longest.length && t.length > 50 && t.length < 10000) longest = t;
        });
        if (longest.length > 50) fullDescription = longest.substring(0, 5000);
      } catch (e) { /* ignore */ }
    }

    // 2026-10-07 (P24 import 117621889): the street line. P24 shows it three
    // ways — JSON-LD about.address.streetAddress ("42 Springwood"), the
    // "Street Address" overview row ("42 Springwood, Umhlali Golf Estate") and
    // the page header. Sent RAW; the server splits it into street number /
    // street name (and a complex when the line carries one) and drops the
    // suburb part. Listings that hide their street address carry none of them.
    var streetAddress = (about.address && about.address.streetAddress) || null;
    if (!streetAddress && streetRow) streetAddress = streetRow.split(',')[0];
    streetAddress = streetAddress ? clean(streetAddress) : null;

    // P24 now puts GPS coordinates in the JSON-LD `about` block on listings
    // that show an exact location (the original spec said P24 never did).
    // (Number(), not parseNumber(): coordinates are signed JSON numbers.)
    var lat = (about.latitude !== undefined && about.latitude !== null && isFinite(Number(about.latitude))) ? Number(about.latitude) : null;
    var lng = (about.longitude !== undefined && about.longitude !== null && isFinite(Number(about.longitude))) ? Number(about.longitude) : null;

    var priceRaw = offers.priceSpecification ? offers.priceSpecification.price : offers.price;

    return {
      portal: 'p24',
      listing_ref: listingRef,
      listing_url: location.href,
      price: parseNumber(priceRaw),
      description: fullDescription || ld.description || null,
      // 2026-09-29 URGENT FIX #2 (Clayville): the extension already computed
      // this as `_title` for the popup's own preview panel but DELETED it
      // before POSTing — the server's deriveTitle() then fell back to a
      // bare suburb name ("Clayville") because street_number/street_name
      // are never sent. Send it for real; deriveTitle() now prefers it.
      listing_title: advertHeading || ld.name || textOf('h1') || null,
      listing_type: listingType,
      // Raw signals only — the server maps these to CoreX's current
      // taxonomy (property_type/category). property_type_raw is the
      // schema.org @type (e.g. "Apartment"); property_type_label_hint is
      // P24's own free-text label when present (about.description, e.g.
      // "Apartment / Flat" — often already an exact CoreX label, confirmed
      // on the Pomona sample, but not assumed universal). A townhouse is
      // @type "Apartment" in the JSON-LD, so the hint (or, when the JSON-LD
      // one is blank, the "Type of Property" overview row) is what keeps it a
      // townhouse.
      property_type_raw: (about['@type'] && about['@type'] !== 'RealEstateListing') ? about['@type'] : null,
      property_type_label_hint: (about.description && about.description !== ld.description) ? about.description : (typeRow || null),
      beds: beds, baths: baths, garages: garages,
      size_m2: floorSize, erf_size_m2: erfSize,
      suburb: (about.address && about.address.addressLocality) || null,
      province: (about.address && about.address.addressRegion) || null,
      street_address: streetAddress,
      latitude: lat,
      longitude: lng,
      // P24's OWN external suburb id, straight off the URL — the server
      // resolves this to CoreX's internal p24_suburb_id (+ city/province
      // chain) via P24LocationResolver::resolveByP24Id(). Authoritative
      // over the plain suburb/province text above when present.
      p24_suburb_external_id: p24SuburbExternalId,
      // The real gallery, in P24's own order (see the thumbnail-strip note
      // above). first_image_id/image_count stay in the payload for the
      // sequential fallback and the popup preview.
      image_ids: imageIds,
      first_image_id: firstImageId,
      image_count: imageCount,
      source_agency_name: leadCtx ? leadCtx.agencyName : null,
      source_agent_name: leadCtx && leadCtx.primaryAgent ? leadCtx.primaryAgent.name : (leadCtx && leadCtx.agentDetails && leadCtx.agentDetails[0] ? leadCtx.agentDetails[0].name : null),
      source_agent_profile_url: leadCtx && leadCtx.agentDetails && leadCtx.agentDetails[0] ? leadCtx.agentDetails[0].profileURL : null,
      source_agent_image_url: (leadCtx && leadCtx.primaryAgent && leadCtx.primaryAgent.imageURL)
        || (leadCtx && leadCtx.agentDetails && leadCtx.agentDetails[0] ? leadCtx.agentDetails[0].imageURL : null) || null,
      source_agency_logo_url: agencyLogoUrl,
      // 2026-09-30 field audit — the Property Overview's own "Listing Date"
      // row ("17 July 2026") wins over JSON-LD's datePosted when present;
      // Laravel's `date` validation rule (strtotime-compatible) and PHP's
      // Carbon both parse this format natively, no client-side date math.
      date_posted: listingDateRaw || ld.datePosted || null,
      levy: levyRaw,
      rates_taxes: ratesTaxesRaw,
      zone_type_raw: zoningRaw,
      pets_allowed: petsAllowed,
      parking_count: parkingCount,
      parking_features: parkingFeatures,
      pool: poolYes,
      garden: gardenYes,
      kitchen_features: kitchenFeatures,
      garden_features: gardenFeatures,
      // Security now travels inside feature_rows (mapped to CoreX's own security list server-side).
      feature_rows: featureRows,
      strip_tags: stripTags,
      bathroom_features: bathroomFeatures,
      _title: advertHeading || ld.name || textOf('h1'),
      _expected_photo_count: imageCount,
    };
  }
  // <<P24_OAS_EXTRACT_END>>

  function ppExtractOasFn() {
    // Runs MAIN-world in the PP tab — reads the page's OWN already-parsed
    // window.serverVariables directly (never re-implements the page's own
    // token/index de-obfuscation).
    var sv = window.serverVariables || {};
    var bp = sv.bundleParams || {};
    var agencyInfo = bp.agencyInfo || null; // null = private seller — allowed, blank agency.
    var contact = (bp.contactDetails || []).filter(function (c) { return c.contactType === 'Agent'; })[0] || (bp.contactDetails || [])[0] || null;

    var m = location.pathname.match(/\/(T\d+)\/?$/i);

    // .ai/specs/other-agency-stock.md §5 — 2026-09-29 Pomona field-mapping
    // fix. PP's URL uses the same /for-sale/ vs /to-rent/ prefix as P24.
    var listingType = /^\/to-rent\//i.test(location.pathname) ? 'rental' : 'sale';

    var photos = (bp.galleryPhotos || []).map(function (p) { return p.mediumUrl || (p.srcSet && p.srcSet[0]) || null; }).filter(Boolean);

    // 2026-10-07 (P24 import 117621889, same class): parseInt() drops a half
    // bathroom ("2.5" -> 2) and the digit-stripper below turned "1 375.5 m²"
    // into 13755. Same decimal-aware reader as the P24 extractor (copied — each
    // injected function must be self-contained).
    function parseNumber(v) {
      if (v === null || v === undefined) return null;
      if (typeof v === 'number') return isFinite(v) ? v : null;
      var s = String(v).replace(/[\s\u00a0\u202f]/g, '');
      var m0 = s.match(/\d[\d.,]*/);
      if (!m0) return null;
      var t = m0[0].replace(/[.,]+$/, '');
      var lastDot = t.lastIndexOf('.'), lastComma = t.lastIndexOf(',');
      if (lastDot !== -1 && lastComma !== -1) {
        t = lastComma > lastDot ? t.replace(/\./g, '').replace(',', '.') : t.replace(/,/g, '');
      } else if (lastComma !== -1) {
        t = /^\d{1,3}(,\d{3})+$/.test(t) ? t.replace(/,/g, '') : t.replace(',', '.');
      } else if (lastDot !== -1 && (t.match(/\./g) || []).length > 1) {
        t = t.replace(/\./g, '');
      }
      var n = parseFloat(t);
      return isNaN(n) ? null : n;
    }
    var beds = null, baths = null, garages = null;
    (bp.additionalProperty || []).forEach(function (p) {
      var name = (p.name || '').toLowerCase();
      var val = parseNumber(p.value);
      if (val === null) return;
      if (name.indexOf('bedroom') !== -1) beds = val;
      else if (name.indexOf('bathroom') !== -1) baths = val;
      else if (name.indexOf('garage') !== -1) garages = val;
    });

    var floorSize = null, erfSize = null;
    document.querySelectorAll('.property-details__list-item').forEach(function (row) {
      var label = row.querySelector('.property-details__name-value');
      var value = row.querySelector('.property-details__value');
      if (!label || !value) return;
      var l = label.textContent.toLowerCase();
      var v = parseNumber(value.textContent);
      if (v === null) return;
      if (l.indexOf('floor size') !== -1) floorSize = v;
      else if (l.indexOf('land size') !== -1) erfSize = v;
    });

    return {
      portal: 'pp',
      listing_ref: m ? m[1] : null,
      listing_url: location.href,
      // 2026-09-29 Pomona fix — bp.purchasePrice is undefined on some
      // listings (confirmed live, T3497323); bp.priceDisplay.purchasePrice
      // is the same value and reliably present.
      price: bp.purchasePrice || (bp.priceDisplay ? bp.priceDisplay.purchasePrice : null) || null,
      description: bp.description || null,
      listing_type: listingType,
      // Raw signal only, same reasoning as the P24 path — PP's propertyType
      // is often a full descriptive string ("5 Bedroom House", "3 Bedroom
      // Apartment"), not a bare type, so the server strips the leading bed
      // count before mapping to CoreX's current taxonomy.
      property_type_raw: bp.propertyType || null,
      beds: beds, baths: baths, garages: garages,
      size_m2: floorSize, erf_size_m2: erfSize,
      suburb: bp.suburbName || null,
      latitude: bp.mapCoOrdinates ? bp.mapCoOrdinates.lat : null,
      longitude: bp.mapCoOrdinates ? bp.mapCoOrdinates.lng : null,
      photos: photos,
      source_agency_name: agencyInfo ? agencyInfo.agencyName : null,
      source_agent_name: contact ? (contact.name || null) : null,
      source_agent_profile_url: contact && contact.agentPageUrl ? ('https://www.privateproperty.co.za' + contact.agentPageUrl) : null,
      // .ai/specs/other-agency-stock.md §5 — 2026-09-29 gallery-filter fix.
      // bundleParams.galleryPhotos is served from a completely different CDN
      // host (images.pp.co.za) than agent/agency images
      // (helium.privateproperty.co.za / a blob-storage placeholder host) and
      // was confirmed clean against both real fixtures (T12292, T3497323) —
      // no PP contamination found, unlike P24. Sent anyway, purely for the
      // server's uniform cross-check (same field names as the P24 path) —
      // costs nothing since these URLs never appear in `photos` here.
      source_agent_image_url: contact ? (contact.image || null) : null,
      source_agency_logo_url: agencyInfo ? (agencyInfo.agencyLogo || null) : null,
      // 2026-09-29 URGENT FIX #2 — same fix as the P24 extractor: send the
      // real title instead of letting it get dropped before deriveTitle()
      // falls back to a bare suburb name.
      listing_title: bp.title || document.title || null,
      _title: bp.title || document.title,
      _expected_photo_count: null, // PP's page has no equivalent declared-count field to cross-check against.
    };
  }

  async function initOasFlow() {
    hideError();
    showState('oasPreview');
    els.oasTitle.textContent = 'Extracting...';
    els.oasPrice.textContent = '';
    els.oasAddress.textContent = '';
    els.oasFeatures.innerHTML = '';
    els.oasImagesCount.textContent = '';
    els.oasAgency.textContent = '';
    els.oasThumb.innerHTML = '';
    els.oasImportBtn.disabled = true;
    els.oasConsentCheck.checked = false;
    els.oasMsg.textContent = '';

    // Fetch this agency's consent wording so the checkbox never shows stale text.
    try {
      const wordingRes = await chrome.runtime.sendMessage({ action: 'oasConsentWording', apiUrl: settings.apiUrl, apiToken: settings.apiToken });
      if (wordingRes && wordingRes.wording) oasConsentWording = wordingRes.wording;
    } catch (e) { /* keep default */ }
    els.oasConsentText.textContent = oasConsentWording;

    try {
      const isP24 = detectedPortal === 'p24';
      const injection = await chrome.scripting.executeScript({
        target: { tabId: tabId },
        world: isP24 ? 'ISOLATED' : 'MAIN',
        func: isP24 ? p24ExtractOasFn : ppExtractOasFn,
      });
      const data = injection && injection[0] ? injection[0].result : null;

      if (!data || !data.listing_ref) {
        showError('Could not find a listing reference on this page — make sure you are on a single listing detail page.');
        showState('choose');
        return;
      }

      oasExtracted = data;

      els.oasTitle.textContent = data._title || 'Listing ' + data.listing_ref;
      els.oasPrice.textContent = data.price ? 'R ' + Number(data.price).toLocaleString() : 'Price not found';
      els.oasAddress.textContent = [data.street_address, data.suburb, data.province].filter(Boolean).join(', ') || 'Address not available';
      const feats = [];
      if (data.beds != null) feats.push('<span class="feat">' + data.beds + ' Bed</span>');
      if (data.baths != null) feats.push('<span class="feat">' + data.baths + ' Bath</span>');
      if (data.garages != null) feats.push('<span class="feat">' + data.garages + ' Garage</span>');
      els.oasFeatures.innerHTML = feats.join('');
      // 2026-09-30 URGENT FIX #3 — P24 no longer sends a photos[] URL list
      // (see p24ExtractOasFn); count/preview off image_count + first_image_id,
      // the same signal Pull's own preview is built from. PP is unchanged —
      // it still sends a real photos[] array (bp.galleryPhotos).
      const isP24Photos = data.first_image_id != null;
      const photoCount = isP24Photos ? (data.image_count || 0) : (data.photos || []).length;
      els.oasImagesCount.textContent = photoCount + ' photo' + (photoCount !== 1 ? 's' : '') + ' will be imported';
      els.oasAgency.textContent = data.source_agency_name ? ('Agency: ' + data.source_agency_name) : 'Agency not shown (private seller, or not on this page) — import will proceed with a blank agency.';
      const thumbUrl = isP24Photos
        ? (data.first_image_id ? 'https://images.prop24.com/' + data.first_image_id + '/Ensure1280x720' : null)
        : (data.photos && data.photos[0] ? data.photos[0] : null);
      if (thumbUrl) {
        const img = document.createElement('img');
        img.src = thumbUrl;
        els.oasThumb.appendChild(img);
      }

      els.oasImportBtn.disabled = !els.oasConsentCheck.checked;
    } catch (err) {
      showError('Failed to read this listing: ' + err.message);
      showState('choose');
    }
  }

  els.actionImportOas.addEventListener('click', () => {
    if (!els.actionImportOas.disabled) initOasFlow();
  });

  els.backFromOas.addEventListener('click', () => {
    showState('choose');
  });

  els.oasConsentCheck.addEventListener('change', () => {
    els.oasImportBtn.disabled = !els.oasConsentCheck.checked;
  });

  // 2026-09-30 URGENT FIX #3 (Norkem Park, property #21098): OAS import had
  // NO photos. Reuses the Pull flow's own image mechanism end to end — same
  // statePulling progress UI, same startImagePolling()/pull-status endpoint,
  // same DownloadPortalPropertyImages job server-side. Only the completion
  // screen differs (oasComplete, not pullComplete).
  els.oasImportBtn.addEventListener('click', importOas);

  async function importOas() {
    if (!oasExtracted || !els.oasConsentCheck.checked) return;

    hideError();
    showState('pulling');
    setPullStep('Create', 'active');
    setPullStep('Images', 'pending');
    document.getElementById('pullImagesTrack').style.display = 'none';
    document.getElementById('pullImagesBar').style.width = '0%';
    els.pullImagesDetail.textContent = '';

    const payload = Object.assign({}, oasExtracted, { consent: true });
    delete payload._title;
    delete payload._expected_photo_count; // client-side preview hint only — not a payload field

    try {
      const res = await chrome.runtime.sendMessage({
        action: 'importOtherAgencyStock',
        apiUrl: settings.apiUrl,
        apiToken: settings.apiToken,
        payload: payload,
      });

      if (!res || !res.success) {
        showState('oasPreview');
        if (res && res.code === 'already_agency_stock') {
          // Spec §5e — already on CoreX as the agency's own stock: stays on screen (not the 8s error
          // box) with a link to it. Built with DOM nodes — the server text is never injected as HTML.
          showOasAlreadyAgencyStock(res.message, res.url);
        } else {
          showError((res && res.message) || 'Import failed.');
        }
        return;
      }

      setPullStep('Create', 'done');
      const oasPropertyId = res.property_id;
      const oasImagesCount = res.images_count || 0;
      const oasPropertyUrl = res.url || (settings.apiUrl.replace(/\/+$/, '') + '/corex/properties/' + res.property_id);

      if (oasImagesCount > 0) {
        setPullStep('Images', 'active');
        document.getElementById('pullStepImagesText').textContent =
          'Downloading ' + oasImagesCount + ' images...';
        document.getElementById('pullImagesTrack').style.display = 'block';
        startImagePolling(oasPropertyId, oasImagesCount, function (downloaded) {
          showOasComplete(oasPropertyUrl, downloaded);
        });
      } else {
        setPullStep('Images', 'done');
        document.getElementById('pullStepImagesText').textContent = 'No images to download';
        showOasComplete(oasPropertyUrl, 0);
      }
    } catch (err) {
      showError('Import failed: ' + err.message);
      showState('oasPreview');
    }
  }

  function showOasAlreadyAgencyStock(message, url) {
    els.oasMsg.textContent = '';
    const text = document.createElement('div');
    text.textContent = message || 'This property is already on CoreX as agency stock.';
    els.oasMsg.appendChild(text);
    if (url && /^https?:\/\//i.test(url)) {
      const link = document.createElement('a');
      link.href = url;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      link.style.display = 'block';
      link.style.marginTop = '6px';
      link.textContent = 'Open it in CoreX';
      els.oasMsg.appendChild(link);
    }
    els.oasImportBtn.disabled = true;
  }

  function showOasComplete(url, downloadedCount) {
    setTimeout(() => {
      els.oasCompleteDetail.textContent = (oasExtracted && oasExtracted.listing_title)
        ? oasExtracted.listing_title
        : (downloadedCount + ' photo' + (downloadedCount !== 1 ? 's' : '') + ' imported.');
      els.oasViewProperty.href = url || '#';
      showState('oasComplete');
    }, 800);
  }

  els.oasAnother.addEventListener('click', () => {
    showState('choose');
  });

  let pullPoller = null;
  let pulledPropertyId = null;
  let pulledPropertyUrl = null;
  let pullTotalImages = 0;

  function setPullStep(step, state) {
    // state: 'pending', 'active', 'done'
    const el = document.getElementById('pullStep' + step);
    const icon = document.getElementById('pullStep' + step + 'Icon');
    if (!el || !icon) return;
    el.className = 'pull-step ' + state;
    if (state === 'done') icon.innerHTML = '&check;';
    else if (state === 'active') icon.innerHTML = '&bull;';
    else icon.innerHTML = '&bull;';
  }

  async function pullProperty() {
    if (!propertyData) return;

    hideError();
    showState('pulling');

    // Step 1: Creating property
    setPullStep('Create', 'active');
    setPullStep('Images', 'pending');
    document.getElementById('pullImagesTrack').style.display = 'none';
    document.getElementById('pullImagesBar').style.width = '0%';
    els.pullImagesDetail.textContent = '';

    try {
      const result = await chrome.runtime.sendMessage({
        action:   'pullProperty',
        property: propertyData,
        apiUrl:   settings.apiUrl,
        apiToken: settings.apiToken,
      });

      if (result && result.error) {
        showError(result.error);
        showState('pullPreview');
        return;
      }

      // Property created
      setPullStep('Create', 'done');
      pulledPropertyId = result.property_id;
      pullTotalImages = result.images_count || 0;

      if (result.property_url) {
        pulledPropertyUrl = result.property_url;
      } else if (result.property_id) {
        pulledPropertyUrl = settings.apiUrl + '/corex/properties/' + result.property_id;
      } else {
        pulledPropertyUrl = settings.apiUrl + '/corex/properties';
      }

      // Step 2: Download images
      if (pullTotalImages > 0) {
        setPullStep('Images', 'active');
        document.getElementById('pullStepImagesText').textContent =
          'Downloading ' + pullTotalImages + ' images...';
        document.getElementById('pullImagesTrack').style.display = 'block';
        startImagePolling(pulledPropertyId, pullTotalImages, function () {
          showPullComplete();
        });
      } else {
        // No images — done immediately
        setPullStep('Images', 'done');
        document.getElementById('pullStepImagesText').textContent = 'No images to download';
        showPullComplete();
      }

    } catch (err) {
      showError('Pull failed: ' + err.message);
      showState('pullPreview');
    }
  }

  // 2026-09-30 URGENT FIX #3 — shared by the Pull flow AND the OAS import
  // flow (see importOas() above): same pull-status endpoint, same progress
  // DOM (pullImagesBar/pullStepImagesText/pullImagesDetail), same polling
  // loop. Only what happens on completion differs, via onComplete().
  function startImagePolling(propertyId, totalImages, onComplete) {
    stopPullImagePolling();
    pullPoller = setInterval(function () {
      pollImageStatus(propertyId, totalImages, onComplete);
    }, 1500);
  }

  function stopPullImagePolling() {
    if (pullPoller) {
      clearInterval(pullPoller);
      pullPoller = null;
    }
  }

  async function pollImageStatus(propertyId, totalImages, onComplete) {
    if (!propertyId) return;

    try {
      const url = settings.apiUrl.replace(/\/+$/, '') +
        '/api/properties/' + propertyId + '/pull-status';

      const response = await fetch(url, {
        headers: {
          'Accept': 'application/json',
          'Authorization': 'Bearer ' + settings.apiToken,
        },
      });

      if (!response.ok) return;
      const status = await response.json();

      const downloaded = status.downloaded || 0;
      const total = status.total || totalImages;
      const failed = status.failed || 0;
      const pct = total > 0 ? Math.round((downloaded / total) * 100) : 0;

      document.getElementById('pullImagesBar').style.width = pct + '%';
      document.getElementById('pullStepImagesText').textContent =
        'Downloading images... ' + downloaded + ' / ' + total;
      els.pullImagesDetail.textContent =
        downloaded + ' downloaded' + (failed > 0 ? ', ' + failed + ' failed' : '');

      if (status.complete) {
        stopPullImagePolling();
        setPullStep('Images', 'done');
        document.getElementById('pullStepImagesText').textContent =
          downloaded + ' image' + (downloaded !== 1 ? 's' : '') + ' downloaded';
        document.getElementById('pullImagesBar').style.width = '100%';
        onComplete(downloaded, failed);
      }
    } catch (e) {
      // Keep polling on error
    }
  }

  function showPullComplete() {
    // Short delay so user sees 100% before transition
    setTimeout(() => {
      els.pullCompleteTitle.textContent = 'Property ready';
      els.pullCompleteDetail.textContent = propertyData.title || '';
      els.viewProperty.href = pulledPropertyUrl || settings.apiUrl + '/corex/properties';
      showState('pullComplete');

      chrome.storage.local.set({
        lastCapture: {
          count: 1,
          type: 'property',
          portal: detectedPortal === 'p24' ? 'P24' : 'PP',
          timestamp: Date.now(),
        }
      });
    }, 800);
  }

  // ══════════════════════════════════════════════════════════
  // ── INIT ──────────────────────────────────────────────────
  // ══════════════════════════════════════════════════════════

  async function init() {
    await loadSettings();
    await showLastCapture();

    // Pre-fill settings
    els.apiUrl.value   = settings.apiUrl;
    els.apiToken.value = settings.apiToken;

    // No token → show settings
    if (!settings.apiToken) {
      showState('settings');
      setConnection('no_token');
      return;
    }

    // Real connectivity probe (honest dot: connected / token-expired / no-agency /
    // server-error / unreachable), instead of assuming green because a token exists.
    const health = await refreshConnection();

    // Check if a capture is already running
    try {
      const status = await chrome.runtime.sendMessage({ action: 'getCaptureStatus' });
      if (status && status.active) {
        showState('capturing');
        startStatusPolling();
        return;
      }
    } catch (e) { /* ignore */ }

    // Check for incomplete capture
    try {
      const incomplete = await chrome.runtime.sendMessage({ action: 'getIncompleteCapture' });
      if (incomplete) {
        els.resumeText.textContent =
          'Previous capture incomplete (page ' + incomplete.currentPage +
          ' of ' + incomplete.totalPages + ', ' +
          (incomplete.capturedListings || 0).toLocaleString() + ' listings captured). Resume or start fresh?';
        showState('resume');
        return;
      }
    } catch (e) { /* ignore */ }

    // Durable queue: send it if we can, otherwise say honestly why not (and that
    // nothing is lost). No more "offline" when the real problem is an expired token.
    try {
      const qs = await chrome.runtime.sendMessage({ action: 'getQueueStatus' });
      const queued = (qs && qs.count) || 0;

      if (qs && qs.storageRatio >= 0.85) {
        showError('Local storage ' + Math.round(qs.storageRatio * 100) +
                  '% full — send the queued batches to CoreX soon to avoid pausing capture.');
      }

      if (queued > 0) {
        if (health.state === 'connected') {
          drainQueueLoop();                                  // start sending + show progress
        } else if (health.state === 'auth' || health.state === 'no_agency') {
          showError(queued + ' batches safely queued — re-authenticate in Settings to send them (nothing lost).');
        } else {
          showError(queued + ' batches safely queued — will send when CoreX is reachable (nothing lost).');
        }
      }
    } catch (e) { /* ignore */ }

    // Get active tab
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (!tab || !tab.url) {
      showState('notOnPortal');
      return;
    }

    const portal = detectPortal(tab.url);
    if (!portal) {
      showState('notOnPortal');
      return;
    }

    tabId          = tab.id;
    tabUrl         = tab.url;
    detectedPortal = portal;

    // Detect page type (search vs detail vs other)
    let isDetail = false;
    let isSearch = false;

    try {
      const pageType = await requestPageType(tabId);
      isDetail = pageType && pageType.isDetailPage;
      isSearch = pageType && pageType.isSearchPage;
    } catch (e) {
      // Fallback: try getPageInfo for search detection
      try {
        const info = await requestPageInfo(tabId);
        isSearch = info && info.isSearchPage;
      } catch (e2) { /* ignore */ }
    }

    // Show choose state with appropriate hints
    els.choosePortalName.textContent = portalLabel(portal) + ' detected';

    if (isDetail) {
      // On a detail page — Pull Property is primary, Capture Listings disabled
      els.actionPullProperty.disabled = false;
      els.actionCaptureListings.disabled = true;
      els.actionImportOas.disabled = false;
      els.actionHint.textContent = 'You\'re on a listing page — pull this property into CoreX';

      // 2026-09-29: the agency-mismatch cross-check + highlightAction()
      // primary/secondary styling this block used to do only mattered for
      // choosing between the Pull button and OAS import — moot now that
      // Pull is hidden (PULL_PROPERTY_ACTION_ENABLED above). Removed along
      // with the extra requestPropertyDetail() scrape it cost on every
      // detail-page load; highlightAction() itself stays defined (unused)
      // in case Pull is ever re-enabled.
    } else if (isSearch) {
      // On a search page — Capture Listings is available, Pull Property disabled
      els.actionPullProperty.disabled = true;
      els.actionCaptureListings.disabled = false;
      els.actionImportOas.disabled = true;
      els.actionHint.textContent = 'You\'re on a search page — capture listings for prospecting';
    } else {
      // On some other portal page
      els.actionPullProperty.disabled = true;
      els.actionCaptureListings.disabled = true;
      els.actionImportOas.disabled = true;
      els.actionHint.textContent = 'Navigate to a listing or search results page';
    }

    showState('choose');
  }

  // ── Event listeners ────────────────────────────────────────

  // Settings
  els.settingsToggle.addEventListener('click', () => {
    els.apiUrl.value   = settings.apiUrl;
    els.apiToken.value = settings.apiToken;
    showState('settings');
  });

  els.backFromSettings.addEventListener('click', () => {
    showState(previousState || 'notOnPortal');
  });

  els.saveSettings.addEventListener('click', async () => {
    await saveSettingsToStorage();
  });

  // Choose actions
  els.actionPullProperty.addEventListener('click', () => {
    if (!els.actionPullProperty.disabled) initPullFlow();
  });

  els.actionCaptureListings.addEventListener('click', () => {
    if (!els.actionCaptureListings.disabled) initCaptureFlow();
  });

  // Ready (capture flow)
  els.backFromReady.addEventListener('click', () => {
    showState('choose');
  });

  els.captureBtn.addEventListener('click', async () => {
    if (pageInfo && pageInfo.currentUrl) {
      try {
        const dupCheck = await checkDuplicate(pageInfo.currentUrl);
        if (dupCheck && dupCheck.duplicate) {
          const ago = dupCheck.captured_ago || 'earlier today';
          const count = dupCheck.listing_count || 0;
          els.duplicateText.textContent =
            'This search was captured ' + ago + ' (' + count + ' listings). ' +
            'Capture again to check for new/changed listings?';
          els.duplicateWarning.style.display = 'block';
          return;
        }
      } catch (e) { /* proceed */ }
    }
    startCapture();
  });

  els.duplicateConfirm.addEventListener('click', () => {
    els.duplicateWarning.style.display = 'none';
    startCapture();
  });

  els.duplicateCancel.addEventListener('click', () => {
    els.duplicateWarning.style.display = 'none';
  });

  els.cancelBtn.addEventListener('click', () => {
    chrome.runtime.sendMessage({ action: 'cancelCapture' });
    stopStatusPolling();
    showState('ready');
  });

  // Resume
  els.resumeBtn.addEventListener('click', () => {
    showState('capturing');
    startStatusPolling();
    chrome.runtime.sendMessage({
      action:   'resumeCapture',
      apiUrl:   settings.apiUrl,
      apiToken: settings.apiToken,
    });
  });

  els.startFreshBtn.addEventListener('click', () => {
    chrome.runtime.sendMessage({ action: 'clearIncompleteCapture' });
    init();
  });

  // Capture complete
  els.captureAnother.addEventListener('click', () => {
    init();
  });

  // Pull flow
  els.backFromPull.addEventListener('click', () => {
    showState('choose');
  });

  els.pullBtn.addEventListener('click', () => {
    pullProperty();
  });

  els.pullAnother.addEventListener('click', () => {
    init();
  });

  // ── Go ─────────────────────────────────────────────────────
  init();
})();
