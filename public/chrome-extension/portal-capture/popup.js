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

  function p24ExtractOasFn() {
    // Runs ISOLATED-world in the P24 tab. Self-contained — no outer closures.
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
    function num(v) { var n = parseInt(String(v).replace(/[^\d]/g, ''), 10); return isNaN(n) ? null : n; }
    function textOf(sel) { var el = document.querySelector(sel); return el ? el.textContent.trim() : null; }

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

    // Informational only now (sequential-id download below never touches a
    // photo URL list to filter) — still sent for display/consistency with
    // the PP payload shape.
    var agencyLogoUrl = (offers.offeredBy && offers.offeredBy.worksFor && offers.offeredBy.worksFor.logo) || null;

    // 2026-09-30 URGENT FIX #3 (Norkem Park, property #21098): the DOM/regex
    // photos[] collection above (agent/logo exclusion + gallery URL merge)
    // is GONE. It over-collected once, under-collected once, and OAS
    // imports still ended up with NO photos, because it built the URL list
    // client-side and shipped it to a job (DownloadOtherAgencyStockGalleryJob)
    // that was never wired up right. The Pull flow's own image mechanism has
    // been proven live to work — same P24 gallery, same page. Reuse it
    // exactly, verbatim from content-p24-detail.js's extractPropertyDetail():
    // first image id + declared count, sequential IDs reconstructed and
    // downloaded SERVER-SIDE (DownloadPortalPropertyImages) — never send a
    // photo URL list from the client at all.
    var firstImageId = null;
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

    var imageCount = 0;
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

    var erfSize = null, floorSize = null, beds = null, baths = null, garages = null;
    // 2026-09-30 field audit (property #21098, Norkem Park): Levies, Rates
    // and Taxes, Listing Date, Pets Allowed, Zoning, Parking, Pool, Kitchen,
    // Garden, Security all sit in this SAME .p24_propertyOverviewRow table
    // as beds/baths/floor — confirmed live, identical markup
    // (.p24_propertyOverviewKey label + .p24_propertyOverviewResult .p24_info
    // value). Kitchen/Garden/Security render their feature list as ONE
    // .p24_info block with real embedded newlines (P24's own <br>-per-item
    // markup collapses to textContent newlines) — .split('\n') recovers the
    // list. Raw text is sent AS-IS; the server-side shared mapper
    // (OtherAgencyStockFieldMapper) does the currency/zoning parsing and
    // Carbon parses "17 July 2026" natively — same "extension sends raw,
    // server maps" split property_type already uses.
    var levyRaw = null, ratesTaxesRaw = null, listingDateRaw = null, petsAllowedRaw = null,
        zoningRaw = null, parkingCount = null, poolYes = false,
        kitchenFeatures = [], gardenFeatures = [], securityFeatures = [];
    document.querySelectorAll('.p24_propertyOverviewRow').forEach(function (row) {
      var key = row.querySelector('.p24_propertyOverviewKey');
      var val = row.querySelector('.p24_propertyOverviewResult .p24_info');
      if (!key || !val) return;
      var k2 = key.textContent.toLowerCase();
      var v2 = num(val.textContent);
      var vText = val.textContent.trim();
      if (k2.indexOf('bedroom') !== -1) beds = v2;
      else if (k2.indexOf('bathroom') !== -1) baths = v2;
      else if (k2.indexOf('floor') !== -1) floorSize = v2;
      else if (k2 === 'levies') levyRaw = vText;
      else if (k2 === 'rates and taxes') ratesTaxesRaw = vText;
      else if (k2 === 'listing date') listingDateRaw = vText;
      else if (k2 === 'pets allowed') petsAllowedRaw = vText;
      else if (k2 === 'zoning') zoningRaw = vText;
      else if (k2 === 'parking') parkingCount = parseInt(vText, 10) || null;
      else if (k2 === 'pool') poolYes = /^yes$/i.test(vText);
      else if (k2 === 'kitchen') kitchenFeatures = vText.split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
      else if (k2 === 'garden') gardenFeatures = vText.split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
      else if (k2 === 'security') securityFeatures = vText.split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
    });
    var petsAllowed = petsAllowedRaw ? /^yes$/i.test(petsAllowedRaw) : null;
    var erfEl = document.querySelector('.js_sizeConversionsButton span');
    if (erfEl) erfSize = num(erfEl.textContent);

    var garageImg = document.querySelector('img[src*="icon_garage"]');
    if (garageImg) {
      var garageFeature = garageImg.closest('.p24_feature');
      var amountEl = garageFeature ? garageFeature.querySelector('.p24_featureAmount') : null;
      if (amountEl) garages = num(amountEl.textContent);
    }

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

    return {
      portal: 'p24',
      listing_ref: listingRef,
      listing_url: location.href,
      price: offers.priceSpecification ? num(offers.priceSpecification.price) : num(offers.price),
      description: fullDescription || ld.description || null,
      // 2026-09-29 URGENT FIX #2 (Clayville): the extension already computed
      // this as `_title` for the popup's own preview panel but DELETED it
      // before POSTing — the server's deriveTitle() then fell back to a
      // bare suburb name ("Clayville") because street_number/street_name
      // are never sent. Send it for real; deriveTitle() now prefers it.
      listing_title: ld.name || textOf('h1') || null,
      listing_type: listingType,
      // Raw signals only — the server maps these to CoreX's current
      // taxonomy (property_type/category). property_type_raw is the
      // schema.org @type (e.g. "Apartment"); property_type_label_hint is
      // P24's own free-text label when present (about.description, e.g.
      // "Apartment / Flat" — often already an exact CoreX label, confirmed
      // on the Pomona sample, but not assumed universal).
      property_type_raw: (about['@type'] && about['@type'] !== 'RealEstateListing') ? about['@type'] : null,
      property_type_label_hint: (about.description && about.description !== ld.description) ? about.description : null,
      beds: beds, baths: baths, garages: garages,
      size_m2: floorSize, erf_size_m2: erfSize,
      suburb: (about.address && about.address.addressLocality) || null,
      province: (about.address && about.address.addressRegion) || null,
      // P24's OWN external suburb id, straight off the URL — the server
      // resolves this to CoreX's internal p24_suburb_id (+ city/province
      // chain) via P24LocationResolver::resolveByP24Id(). Authoritative
      // over the plain suburb/province text above when present.
      p24_suburb_external_id: p24SuburbExternalId,
      // 2026-09-30 URGENT FIX #3 — same signal, same field names
      // PropertyPullController/DownloadPortalPropertyImages already accept;
      // no photo URL list sent from the client at all any more.
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
      pool: poolYes,
      kitchen_features: kitchenFeatures,
      garden_features: gardenFeatures,
      security_features: securityFeatures,
      _title: ld.name || textOf('h1'),
      _expected_photo_count: imageCount,
    };
  }

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

    var beds = null, baths = null, garages = null;
    (bp.additionalProperty || []).forEach(function (p) {
      var name = (p.name || '').toLowerCase();
      var val = parseInt(p.value, 10);
      if (isNaN(val)) return;
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
      var v = parseInt(String(value.textContent).replace(/[^\d]/g, ''), 10);
      if (isNaN(v)) return;
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
      els.oasAddress.textContent = [data.suburb, data.province].filter(Boolean).join(', ') || 'Address not available';
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
        showError((res && res.message) || 'Import failed.');
        showState('oasPreview');
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
