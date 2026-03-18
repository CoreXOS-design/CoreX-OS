/**
 * CoreX Portal Capture — Popup Controller
 *
 * Manages popup UI states and orchestrates the capture flow:
 *   1. Not on portal → informational message
 *   2. Settings needed → API URL + token form
 *   3. Ready → portal detected, show search info
 *   4. Capturing → progress bar + page-by-page scrape
 *   5. Complete → results summary
 */

(function () {
  'use strict';

  // ── DOM refs ───────────────────────────────────────────────
  const states = {
    notOnPortal: document.getElementById('stateNotOnPortal'),
    settings:    document.getElementById('stateSettings'),
    ready:       document.getElementById('stateReady'),
    capturing:   document.getElementById('stateCapturing'),
    complete:    document.getElementById('stateComplete'),
  };

  const els = {
    settingsToggle:  document.getElementById('settingsToggle'),
    backFromSettings: document.getElementById('backFromSettings'),
    apiUrl:          document.getElementById('apiUrl'),
    apiToken:        document.getElementById('apiToken'),
    saveSettings:    document.getElementById('saveSettings'),
    settingsMsg:     document.getElementById('settingsMsg'),
    portalName:      document.getElementById('portalName'),
    searchTerm:      document.getElementById('searchTerm'),
    resultCount:     document.getElementById('resultCount'),
    captureBtn:      document.getElementById('captureBtn'),
    progressBar:     document.getElementById('progressBar'),
    progressText:    document.getElementById('progressText'),
    cancelBtn:       document.getElementById('cancelBtn'),
    completeTotal:   document.getElementById('completeTotal'),
    completeBreakdown: document.getElementById('completeBreakdown'),
    viewInCorex:     document.getElementById('viewInCorex'),
    captureAnother:  document.getElementById('captureAnother'),
    errorMsg:        document.getElementById('errorMsg'),
    connectionDot:   document.getElementById('connectionDot'),
    connectionText:  document.getElementById('connectionText'),
  };

  // ── State ──────────────────────────────────────────────────
  let currentState  = null;
  let previousState = null;
  let cancelled     = false;
  let pageInfo      = null;   // from content script
  let tabId         = null;
  let settings      = { apiUrl: '', apiToken: '' };

  // ── Helpers ────────────────────────────────────────────────
  function showState(name) {
    previousState = currentState;
    currentState  = name;
    Object.keys(states).forEach(k => states[k].classList.remove('active'));
    if (states[name]) states[name].classList.add('active');
  }

  function showError(msg) {
    els.errorMsg.textContent = msg;
    els.errorMsg.style.display = 'block';
    setTimeout(() => { els.errorMsg.style.display = 'none'; }, 6000);
  }

  function hideError() {
    els.errorMsg.style.display = 'none';
  }

  function setConnection(connected) {
    els.connectionDot.className = 'dot' + (connected ? '' : ' disconnected');
    els.connectionText.textContent = connected
      ? 'Connected to CoreX'
      : 'Not connected';
  }

  // ── Settings ───────────────────────────────────────────────
  async function loadSettings() {
    return new Promise(resolve => {
      chrome.storage.local.get(['apiUrl', 'apiToken'], data => {
        settings.apiUrl   = data.apiUrl   || 'https://corex.hfcoastal.co.za';
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
      chrome.storage.local.set({ apiUrl: url, apiToken: token }, () => {
        els.settingsMsg.innerHTML = '<div class="success-msg">Settings saved!</div>';
        setConnection(true);
        setTimeout(() => { els.settingsMsg.innerHTML = ''; }, 2000);
        resolve();
      });
    });
  }

  // ── Portal detection via active tab ────────────────────────
  function detectPortal(url) {
    if (!url) return null;
    if (url.includes('property24.com'))         return 'p24';
    if (url.includes('privateproperty.co.za'))  return 'pp';
    return null;
  }

  function portalLabel(portal) {
    return portal === 'p24' ? 'Property24' : 'Private Property';
  }

  // ── Request page info from content script ──────────────────
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

  // ── Request listings from content script ───────────────────
  function requestListings(tid) {
    return new Promise((resolve, reject) => {
      chrome.tabs.sendMessage(tid, { action: 'getListings' }, response => {
        if (chrome.runtime.lastError) {
          reject(new Error(chrome.runtime.lastError.message));
          return;
        }
        resolve(response);
      });
    });
  }

  // ── Capture flow ───────────────────────────────────────────
  async function startCapture() {
    hideError();
    cancelled = false;
    showState('capturing');

    const allListings = [];
    const totalPages  = pageInfo.totalPages || 1;
    const portal      = pageInfo.portal;
    const baseUrl     = pageInfo.currentUrl;

    try {
      // Page 1: get from content script (already on this page)
      updateProgress(1, totalPages, 0, pageInfo.totalResults || 0);
      const page1 = await requestListings(tabId);
      if (page1 && page1.listings) {
        allListings.push(...page1.listings);
      }
      updateProgress(1, totalPages, allListings.length, pageInfo.totalResults || 0);

      // Pages 2..N: fetch via background service worker
      for (let p = 2; p <= totalPages; p++) {
        if (cancelled) { showState('ready'); return; }

        const pageUrl = buildPageUrl(baseUrl, p, portal);
        updateProgress(p, totalPages, allListings.length, pageInfo.totalResults || 0);

        const result = await chrome.runtime.sendMessage({
          action: 'fetchPage',
          url: pageUrl,
          portal: portal,
        });

        if (result && result.listings) {
          allListings.push(...result.listings);
        }

        updateProgress(p, totalPages, allListings.length, pageInfo.totalResults || 0);
      }

      if (cancelled) { showState('ready'); return; }

      // Send to CoreX
      els.progressText.textContent = 'Sending to CoreX...';

      const payload = {
        source: portal,
        search_context: {
          url:            baseUrl,
          search_term:    pageInfo.searchTerm || '',
          total_results:  pageInfo.totalResults || allListings.length,
          pages_captured: totalPages,
          captured_at:    new Date().toISOString(),
        },
        listings: allListings,
      };

      const apiResult = await chrome.runtime.sendMessage({
        action: 'sendToCorex',
        apiUrl:  settings.apiUrl,
        apiToken: settings.apiToken,
        payload: payload,
      });

      if (apiResult && apiResult.error) {
        showError(apiResult.error);
        showState('ready');
        return;
      }

      // Show complete state
      const imported = apiResult ? (apiResult.imported || 0) : 0;
      const updated  = apiResult ? (apiResult.updated  || 0) : 0;
      const total    = apiResult ? (apiResult.total    || allListings.length) : allListings.length;

      els.completeTotal.textContent     = total + ' listings captured!';
      els.completeBreakdown.textContent = 'New: ' + imported + ' | Updated: ' + updated;
      els.viewInCorex.href              = settings.apiUrl + '/prospecting';

      showState('complete');

    } catch (err) {
      showError('Capture failed: ' + err.message);
      showState('ready');
    }
  }

  function updateProgress(page, totalPages, captured, totalResults) {
    const pct = Math.round((page / totalPages) * 100);
    els.progressBar.style.width = pct + '%';
    els.progressText.textContent =
      'Capturing page ' + page + ' of ' + totalPages + '... ' +
      captured + ' of ' + totalResults + ' listings';
  }

  function buildPageUrl(baseUrl, page, portal) {
    const url = new URL(baseUrl);
    if (portal === 'p24') {
      url.searchParams.set('Page', page);
    } else {
      // Private Property uses page param — adjust if needed
      url.searchParams.set('page', page);
    }
    return url.toString();
  }

  // ── Initialise ─────────────────────────────────────────────
  async function init() {
    await loadSettings();

    // Pre-fill settings fields
    els.apiUrl.value   = settings.apiUrl;
    els.apiToken.value = settings.apiToken;

    // If no token, show settings
    if (!settings.apiToken) {
      showState('settings');
      setConnection(false);
      return;
    }

    setConnection(true);

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

    tabId = tab.id;

    // Request page info from content script
    try {
      pageInfo = await requestPageInfo(tabId);

      if (!pageInfo || !pageInfo.isSearchPage) {
        showState('notOnPortal');
        return;
      }

      pageInfo.portal     = portal;
      pageInfo.currentUrl = tab.url;

      els.portalName.textContent  = portalLabel(portal) + ' detected';
      els.searchTerm.textContent  = pageInfo.searchTerm || 'Search results';
      els.resultCount.textContent =
        (pageInfo.totalResults || '?') + ' listings' +
        (pageInfo.totalPages ? ' (' + pageInfo.totalPages + ' pages)' : '');

      showState('ready');

    } catch (err) {
      // Content script may not be injected yet
      showState('notOnPortal');
    }
  }

  // ── Event listeners ────────────────────────────────────────
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

  els.captureBtn.addEventListener('click', () => {
    startCapture();
  });

  els.cancelBtn.addEventListener('click', () => {
    cancelled = true;
  });

  els.captureAnother.addEventListener('click', () => {
    init();
  });

  // ── Go ─────────────────────────────────────────────────────
  init();
})();
