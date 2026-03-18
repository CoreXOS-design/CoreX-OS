/**
 * CoreX Portal Capture — Popup Controller
 *
 * Manages popup UI states and delegates capture to background service worker.
 * Polls background for status updates so the popup can be closed/reopened
 * without interrupting capture.
 *
 * States:
 *   1. Not on portal → informational message
 *   2. Settings needed → API URL + token form
 *   3. Ready → portal detected, show search info
 *   4. Capturing → progress bar + ETA + batch status
 *   5. Complete → results summary
 *   6. Resume → incomplete capture detected
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
    resume:      document.getElementById('stateResume'),
  };

  const els = {
    settingsToggle:    document.getElementById('settingsToggle'),
    backFromSettings:  document.getElementById('backFromSettings'),
    apiUrl:            document.getElementById('apiUrl'),
    apiToken:          document.getElementById('apiToken'),
    saveSettings:      document.getElementById('saveSettings'),
    settingsMsg:       document.getElementById('settingsMsg'),
    portalName:        document.getElementById('portalName'),
    searchTerm:        document.getElementById('searchTerm'),
    resultCount:       document.getElementById('resultCount'),
    captureBtn:        document.getElementById('captureBtn'),
    progressBar:       document.getElementById('progressBar'),
    progressText:      document.getElementById('progressText'),
    progressEta:       document.getElementById('progressEta'),
    progressBatch:     document.getElementById('progressBatch'),
    cancelBtn:         document.getElementById('cancelBtn'),
    completeTotal:     document.getElementById('completeTotal'),
    completeBreakdown: document.getElementById('completeBreakdown'),
    viewInCorex:       document.getElementById('viewInCorex'),
    captureAnother:    document.getElementById('captureAnother'),
    errorMsg:          document.getElementById('errorMsg'),
    connectionDot:     document.getElementById('connectionDot'),
    connectionText:    document.getElementById('connectionText'),
    resumeText:        document.getElementById('resumeText'),
    resumeBtn:         document.getElementById('resumeBtn'),
    startFreshBtn:     document.getElementById('startFreshBtn'),
    lastCaptureBar:    document.getElementById('lastCaptureBar'),
    lastCaptureText:   document.getElementById('lastCaptureText'),
    duplicateWarning:  document.getElementById('duplicateWarning'),
    duplicateText:     document.getElementById('duplicateText'),
    duplicateConfirm:  document.getElementById('duplicateConfirm'),
    duplicateCancel:   document.getElementById('duplicateCancel'),
  };

  // ── State ──────────────────────────────────────────────────
  let currentState  = null;
  let previousState = null;
  let statusPoller  = null;
  let pageInfo      = null;
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
    setTimeout(() => { els.errorMsg.style.display = 'none'; }, 8000);
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

  function formatTime(ms) {
    const secs = Math.round(ms / 1000);
    if (secs < 60) return secs + 's';
    const mins = Math.floor(secs / 60);
    const rem = secs % 60;
    if (mins < 60) return '~' + mins + 'm ' + rem + 's';
    const hrs = Math.floor(mins / 60);
    return '~' + hrs + 'h ' + (mins % 60) + 'm';
  }

  function formatTimeAgo(timestamp) {
    const diff = Date.now() - timestamp;
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return mins + 'm ago';
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return hrs + 'h ago';
    const days = Math.floor(hrs / 24);
    return days + 'd ago';
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
            'Last capture: ' + dateStr + ' ' + timeStr +
            ' — ' + (info.count || 0).toLocaleString() + ' listings from ' + (info.portal || '?');
          els.lastCaptureBar.style.display = 'block';
        }
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

  // ── Duplicate search check ─────────────────────────────────
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

  // ── Start capture (delegates to background) ────────────────
  async function startCapture() {
    hideError();
    els.duplicateWarning.style.display = 'none';
    showState('capturing');
    startStatusPolling();

    // Flush any local queue first
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

  // ── Status polling ─────────────────────────────────────────
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
        showComplete(status);
      } else if (status.error && !status.active) {
        stopStatusPolling();
        showError(status.error);
        showState('ready');
      }
    } catch (e) {
      // Background may have been terminated
      stopStatusPolling();
    }
  }

  function updateProgress(status) {
    const pct = status.totalPages > 0
      ? Math.round((status.currentPage / status.totalPages) * 100)
      : 0;
    els.progressBar.style.width = pct + '%';

    els.progressText.textContent =
      'Capturing page ' + status.currentPage + ' of ' + status.totalPages +
      '... (' + status.capturedListings.toLocaleString() + ' listings)';

    // Time estimate — 1.5s per remaining page
    if (status.currentPage > 0 && status.totalPages > 1) {
      const remainingPages = status.totalPages - status.currentPage;
      const remainingMs = remainingPages * 1500;
      if (remainingPages > 0) {
        els.progressEta.textContent = 'Estimated time remaining: ' + formatTime(remainingMs);
      } else {
        els.progressEta.textContent = 'Finishing...';
      }
    } else {
      els.progressEta.textContent = '';
    }

    // Batch info
    if (status.batchesSent > 0) {
      els.progressBatch.textContent =
        status.sentListings.toLocaleString() + ' sent to CoreX (' +
        status.batchesSent + ' batches)';
    } else {
      els.progressBatch.textContent = '';
    }

    // Show transient errors (rate limit pauses, etc.)
    if (status.error) {
      els.progressEta.textContent = status.error;
    }
  }

  function showComplete(status) {
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

  // ── Initialise ─────────────────────────────────────────────
  async function init() {
    await loadSettings();
    await showLastCapture();

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

    // Check if a capture is already running
    try {
      const status = await chrome.runtime.sendMessage({ action: 'getCaptureStatus' });
      if (status && status.active) {
        showState('capturing');
        startStatusPolling();
        return;
      }
    } catch (e) { /* ignore */ }

    // Check for incomplete capture from a previous session
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

    // Check for queued local data
    try {
      const queueResult = await chrome.runtime.sendMessage({
        action: 'flushLocalQueue',
        apiUrl: settings.apiUrl,
        apiToken: settings.apiToken,
      });
      if (queueResult && queueResult.flushed > 0) {
        showError(queueResult.flushed + ' queued batches sent to CoreX successfully.');
      }
      if (queueResult && queueResult.remaining > 0) {
        showError(queueResult.remaining + ' batches still queued (CoreX offline).');
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

  els.captureBtn.addEventListener('click', async () => {
    // Check for duplicate search
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
          return; // wait for confirm/cancel
        }
      } catch (e) { /* proceed anyway */ }
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

  els.captureAnother.addEventListener('click', () => {
    init();
  });

  // ── Go ─────────────────────────────────────────────────────
  init();
})();
