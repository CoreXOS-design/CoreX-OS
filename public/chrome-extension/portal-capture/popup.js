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
    actionHint:        document.getElementById('actionHint'),
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
    els.connectionText.textContent = connected ? 'Connected to CoreX' : 'Not connected';
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
    els.pullBtn.disabled = true;

    try {
      const result = await requestPropertyDetail(tabId);

      if (!result || result.error || !result.property) {
        showError(result?.error || 'Could not extract property data from this page.');
        showState('choose');
        return;
      }

      propertyData = result.property;

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
        startPullImagePolling();
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

  function startPullImagePolling() {
    stopPullImagePolling();
    pullPoller = setInterval(pollPullImageStatus, 1500);
  }

  function stopPullImagePolling() {
    if (pullPoller) {
      clearInterval(pullPoller);
      pullPoller = null;
    }
  }

  async function pollPullImageStatus() {
    if (!pulledPropertyId) return;

    try {
      const url = settings.apiUrl.replace(/\/+$/, '') +
        '/api/properties/' + pulledPropertyId + '/pull-status';

      const response = await fetch(url, {
        headers: {
          'Accept': 'application/json',
          'Authorization': 'Bearer ' + settings.apiToken,
        },
      });

      if (!response.ok) return;
      const status = await response.json();

      const downloaded = status.downloaded || 0;
      const total = status.total || pullTotalImages;
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
        showPullComplete();
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

    // Flush queued data
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
      els.actionHint.textContent = 'You\'re on a listing page — pull this property into CoreX';
    } else if (isSearch) {
      // On a search page — Capture Listings is available, Pull Property disabled
      els.actionPullProperty.disabled = true;
      els.actionCaptureListings.disabled = false;
      els.actionHint.textContent = 'You\'re on a search page — capture listings for prospecting';
    } else {
      // On some other portal page
      els.actionPullProperty.disabled = true;
      els.actionCaptureListings.disabled = true;
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
