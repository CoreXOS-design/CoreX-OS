/**
 * CoreX Portal Capture — Background Service Worker
 *
 * Handles:
 * 1. Capture orchestration — runs the page-by-page scrape loop
 * 2. Rate-limited fetching with human-like random delays
 * 3. Batch API sends (every 5 pages / 100 listings)
 * 4. State persistence for resume after popup close / Chrome restart
 * 5. Chrome notifications on capture complete
 * 6. Error handling: rate limits, network issues, API failures, local queue
 */

// ── Capture state (in-memory, persisted to chrome.storage) ───
let capture = defaultCaptureState();

function defaultCaptureState() {
  return {
    active:           false,
    cancelled:        false,
    portal:           null,
    baseUrl:          null,
    searchTerm:       null,
    totalPages:       0,
    totalResults:     0,
    currentPage:      0,
    capturedListings: 0,
    sentListings:     0,
    importedCount:    0,
    updatedCount:     0,
    startTime:        null,
    avgTimePerPage:   0,
    error:            null,
    complete:         false,
    parseWarnings:    0,   // pages that had parsing issues
    rateLimitPauses:  0,
    pendingListings:  [],  // listings not yet sent to API
    batchesSent:      0,
    apiUrl:           null,
    apiToken:         null,
    tabId:            null,
  };
}

// ── Message router ─────────────────────────────────────────
chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  if (msg.action === 'fetchPage') {
    handleFetchPage(msg.url, msg.portal)
      .then(result => sendResponse(result))
      .catch(err => sendResponse({ listings: [], error: err.message }));
    return true;
  }

  if (msg.action === 'sendToCorex') {
    handleSendToCorex(msg.apiUrl, msg.apiToken, msg.payload)
      .then(result => sendResponse(result))
      .catch(err => sendResponse({ error: err.message }));
    return true;
  }

  if (msg.action === 'startCapture') {
    startCapture(msg)
      .then(() => sendResponse({ ok: true }))
      .catch(err => sendResponse({ error: err.message }));
    return true;
  }

  if (msg.action === 'cancelCapture') {
    capture.cancelled = true;
    sendResponse({ ok: true });
    return true;
  }

  if (msg.action === 'getCaptureStatus') {
    sendResponse(getCaptureStatus());
    return true;
  }

  if (msg.action === 'getIncompleteCapture') {
    getIncompleteCapture().then(s => sendResponse(s));
    return true;
  }

  if (msg.action === 'clearIncompleteCapture') {
    chrome.storage.local.remove('captureState', () => sendResponse({ ok: true }));
    return true;
  }

  if (msg.action === 'resumeCapture') {
    resumeCapture(msg.apiUrl, msg.apiToken)
      .then(() => sendResponse({ ok: true }))
      .catch(err => sendResponse({ error: err.message }));
    return true;
  }

  if (msg.action === 'flushLocalQueue') {
    flushLocalQueue(msg.apiUrl, msg.apiToken)
      .then(result => sendResponse(result))
      .catch(err => sendResponse({ error: err.message }));
    return true;
  }

  if (msg.action === 'checkDuplicateSearch') {
    checkDuplicateSearch(msg.apiUrl, msg.apiToken, msg.searchUrl)
      .then(result => sendResponse(result))
      .catch(err => sendResponse({ duplicate: false }));
    return true;
  }

  return false;
});

// ── Capture status snapshot for popup ──────────────────────
function getCaptureStatus() {
  return {
    active:           capture.active,
    cancelled:        capture.cancelled,
    complete:         capture.complete,
    currentPage:      capture.currentPage,
    totalPages:       capture.totalPages,
    capturedListings: capture.capturedListings,
    sentListings:     capture.sentListings,
    importedCount:    capture.importedCount,
    updatedCount:     capture.updatedCount,
    totalResults:     capture.totalResults,
    startTime:        capture.startTime,
    avgTimePerPage:   capture.avgTimePerPage,
    error:            capture.error,
    parseWarnings:    capture.parseWarnings,
    rateLimitPauses:  capture.rateLimitPauses,
    batchesSent:      capture.batchesSent,
  };
}

// ── Delay helper ───────────────────────────────────────────
function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

// ── Build page URL ─────────────────────────────────────────
function buildPageUrl(baseUrl, page, portal) {
  const url = new URL(baseUrl);
  if (portal === 'p24') {
    url.searchParams.set('Page', page);
  } else {
    url.searchParams.set('page', page);
  }
  return url.toString();
}

// ── Persist capture state for resume ───────────────────────
async function persistCaptureState() {
  const stateToSave = {
    portal:           capture.portal,
    baseUrl:          capture.baseUrl,
    searchTerm:       capture.searchTerm,
    totalPages:       capture.totalPages,
    totalResults:     capture.totalResults,
    currentPage:      capture.currentPage,
    capturedListings: capture.capturedListings,
    sentListings:     capture.sentListings,
    importedCount:    capture.importedCount,
    updatedCount:     capture.updatedCount,
    startTime:        capture.startTime,
    pendingListings:  capture.pendingListings,
    batchesSent:      capture.batchesSent,
    parseWarnings:    capture.parseWarnings,
    savedAt:          Date.now(),
  };
  return new Promise(resolve => {
    chrome.storage.local.set({ captureState: stateToSave }, resolve);
  });
}

async function clearCaptureState() {
  return new Promise(resolve => {
    chrome.storage.local.remove('captureState', resolve);
  });
}

async function getIncompleteCapture() {
  return new Promise(resolve => {
    chrome.storage.local.get('captureState', data => {
      const state = data.captureState || null;
      if (state && state.currentPage < state.totalPages) {
        resolve(state);
      } else {
        resolve(null);
      }
    });
  });
}

// ── Save last capture info for status bar ──────────────────
async function saveLastCapture(count, portal) {
  const info = {
    count:     count,
    portal:    portal === 'p24' ? 'P24' : 'PP',
    timestamp: Date.now(),
  };
  return new Promise(resolve => {
    chrome.storage.local.set({ lastCapture: info }, resolve);
  });
}

// ── Local queue for when API is unreachable ────────────────
async function queueLocally(payload) {
  return new Promise(resolve => {
    chrome.storage.local.get('localQueue', data => {
      const queue = data.localQueue || [];
      queue.push(payload);
      chrome.storage.local.set({ localQueue: queue }, resolve);
    });
  });
}

async function getLocalQueue() {
  return new Promise(resolve => {
    chrome.storage.local.get('localQueue', data => {
      resolve(data.localQueue || []);
    });
  });
}

async function clearLocalQueue() {
  return new Promise(resolve => {
    chrome.storage.local.remove('localQueue', resolve);
  });
}

async function flushLocalQueue(apiUrl, apiToken) {
  const queue = await getLocalQueue();
  if (queue.length === 0) return { flushed: 0 };

  let flushed = 0;
  const remaining = [];

  for (const payload of queue) {
    try {
      await handleSendToCorex(apiUrl, apiToken, payload);
      flushed++;
    } catch (e) {
      remaining.push(payload);
    }
  }

  if (remaining.length > 0) {
    await new Promise(resolve => {
      chrome.storage.local.set({ localQueue: remaining }, resolve);
    });
  } else {
    await clearLocalQueue();
  }

  return { flushed, remaining: remaining.length };
}

// ── Check for duplicate search today ───────────────────────
async function checkDuplicateSearch(apiUrl, apiToken, searchUrl) {
  try {
    const url = apiUrl.replace(/\/+$/, '') + '/api/prospecting/check-search?search_url=' + encodeURIComponent(searchUrl);
    const response = await fetch(url, {
      headers: {
        'Accept': 'application/json',
        'Authorization': 'Bearer ' + apiToken,
      },
    });
    if (response.ok) {
      return await response.json();
    }
  } catch (e) { /* ignore */ }
  return { duplicate: false };
}

// ── Fetch page with single retry on failure ────────────────
// On success (HTTP 200): return immediately, no delays.
// On failure: retry ONCE, then skip that page and continue.
async function fetchPageWithRetry(url, portal) {
  async function doFetch() {
    const response = await fetch(url, {
      headers: {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept': 'text/html,application/xhtml+xml',
      },
    });

    if (response.status === 403 || response.status === 429) {
      return { status: response.status, ok: false, html: null };
    }
    if (!response.ok) {
      throw new Error('HTTP ' + response.status);
    }

    const html = await response.text();
    return { status: 200, ok: true, html: html };
  }

  // Attempt 1
  try {
    const resp = await doFetch();
    if (resp.ok) {
      const listings = parseListingsFromHtml(resp.html, portal);
      if (listings.length === 0) capture.parseWarnings++;
      return { listings: listings };
    }

    // Rate limited — wait 10s, retry once
    capture.rateLimitPauses++;
    capture.error = 'Rate limit (' + resp.status + ') — retrying in 10s';
    await sleep(10000);

  } catch (err) {
    // Network error — wait 3s, retry once
    capture.error = 'Network error — retrying in 3s';
    await sleep(3000);
  }

  // Attempt 2 (single retry)
  try {
    const resp = await doFetch();
    if (resp.ok) {
      capture.error = null;
      const listings = parseListingsFromHtml(resp.html, portal);
      if (listings.length === 0) capture.parseWarnings++;
      return { listings: listings };
    }
    // Still rate limited — skip this page
    capture.parseWarnings++;
    return { listings: [] };
  } catch (e) {
    // Still failing — skip this page
    capture.parseWarnings++;
    return { listings: [] };
  }
}

// ── Send batch to API with queue fallback ──────────────────
async function sendBatchToApi(listings, context) {
  const payload = {
    source: capture.portal,
    search_context: context,
    listings: listings,
  };

  try {
    const result = await handleSendToCorex(capture.apiUrl, capture.apiToken, payload);

    if (result && !result.error) {
      capture.sentListings += listings.length;
      capture.importedCount += (result.imported || 0);
      capture.updatedCount += (result.updated || 0);
      capture.batchesSent++;
      return true;
    }
  } catch (err) {
    // API unreachable — queue locally
    await queueLocally(payload);
    capture.error = 'CoreX offline — ' + listings.length + ' listings queued locally';
    return false;
  }

  return false;
}

// ── Main capture loop ──────────────────────────────────────
async function startCapture(msg) {
  capture = defaultCaptureState();
  capture.active      = true;
  capture.portal      = msg.portal;
  capture.baseUrl     = msg.baseUrl;
  capture.searchTerm  = msg.searchTerm;
  capture.totalPages  = msg.totalPages;
  capture.totalResults = msg.totalResults;
  capture.apiUrl      = msg.apiUrl;
  capture.apiToken    = msg.apiToken;
  capture.tabId       = msg.tabId;
  capture.startTime   = Date.now();

  await runCaptureLoop(1);
}

async function resumeCapture(apiUrl, apiToken) {
  const saved = await getIncompleteCapture();
  if (!saved) throw new Error('No incomplete capture found');

  capture = defaultCaptureState();
  capture.active           = true;
  capture.portal           = saved.portal;
  capture.baseUrl          = saved.baseUrl;
  capture.searchTerm       = saved.searchTerm;
  capture.totalPages       = saved.totalPages;
  capture.totalResults     = saved.totalResults;
  capture.capturedListings = saved.capturedListings;
  capture.sentListings     = saved.sentListings;
  capture.importedCount    = saved.importedCount;
  capture.updatedCount     = saved.updatedCount;
  capture.pendingListings  = saved.pendingListings || [];
  capture.batchesSent      = saved.batchesSent;
  capture.parseWarnings    = saved.parseWarnings;
  capture.apiUrl           = apiUrl;
  capture.apiToken         = apiToken;
  capture.startTime        = Date.now();

  const startPage = saved.currentPage + 1;
  await runCaptureLoop(startPage);
}

async function runCaptureLoop(startPage) {
  const context = {
    url:            capture.baseUrl,
    search_term:    capture.searchTerm || '',
    total_results:  capture.totalResults || 0,
    pages_captured: 0,
    captured_at:    new Date().toISOString(),
  };

  try {
    // Page 1: get from content script if starting fresh
    if (startPage === 1 && capture.tabId) {
      capture.currentPage = 1;
      capture.error = null;

      try {
        const page1 = await new Promise((resolve, reject) => {
          chrome.tabs.sendMessage(capture.tabId, { action: 'getListings' }, response => {
            if (chrome.runtime.lastError) {
              reject(new Error(chrome.runtime.lastError.message));
            } else {
              resolve(response);
            }
          });
        });

        if (page1 && page1.listings) {
          capture.pendingListings.push(...page1.listings);
          capture.capturedListings += page1.listings.length;
        }
      } catch (e) {
        // Content script unavailable — fetch page 1 via background
        const result = await fetchPageWithRetry(capture.baseUrl, capture.portal);
        if (result.listings) {
          capture.pendingListings.push(...result.listings);
          capture.capturedListings += result.listings.length;
        }
      }

      context.pages_captured = 1;
      await persistCaptureState();
      startPage = 2;
    }

    // Pages 2..N: fetch via background
    const pageTimes = [];

    for (let p = startPage; p <= capture.totalPages; p++) {
      if (capture.cancelled) break;

      const pageStart = Date.now();
      capture.currentPage = p;
      capture.error = null;

      const pageUrl = buildPageUrl(capture.baseUrl, p, capture.portal);
      const result = await fetchPageWithRetry(pageUrl, capture.portal);

      if (result.listings && result.listings.length > 0) {
        capture.pendingListings.push(...result.listings);
        capture.capturedListings += result.listings.length;
      }

      context.pages_captured = p;

      // Batch send every 100 listings (fire-and-forget, don't block loop)
      if (capture.pendingListings.length >= 100) {
        const batch = capture.pendingListings.splice(0, capture.pendingListings.length);
        if (batch.length > 0) {
          sendBatchToApi(batch, context); // no await — fire and continue
        }
      }

      // Persist state for resume (lightweight, runs fast)
      await persistCaptureState();

      // Clear transient error on successful page
      if (result.listings) capture.error = null;

      // Fixed 1.5s delay between pages — only between pages, not after last
      if (p < capture.totalPages && !capture.cancelled) {
        await sleep(1500);
      }
    }

    // Send any remaining pending listings (final batch — must await)
    if (capture.pendingListings.length > 0 && !capture.cancelled) {
      const batch = capture.pendingListings.splice(0, capture.pendingListings.length);
      context.pages_captured = capture.currentPage;
      await sendBatchToApi(batch, context);
    }

    // Mark complete
    capture.active = false;
    capture.complete = !capture.cancelled;
    await clearCaptureState();

    // Save last capture info
    const totalProcessed = capture.importedCount + capture.updatedCount;
    await saveLastCapture(totalProcessed || capture.capturedListings, capture.portal);

    // Chrome notification
    if (capture.complete && !capture.cancelled) {
      const portalName = capture.portal === 'p24' ? 'Property24' : 'Private Property';
      const count = totalProcessed || capture.capturedListings;
      try {
        chrome.notifications.create('capture-complete', {
          type: 'basic',
          iconUrl: 'icons/icon-128.png',
          title: 'CoreX: Capture Complete',
          message: count.toLocaleString() + ' listings captured from ' + portalName,
          priority: 2,
        });
      } catch (e) { /* notifications may not be available */ }
    }

  } catch (err) {
    capture.error = 'Capture failed: ' + err.message;
    capture.active = false;

    // Save state so we can resume
    await persistCaptureState();

    // Save whatever we captured
    if (capture.pendingListings.length > 0) {
      const context = {
        url: capture.baseUrl,
        search_term: capture.searchTerm || '',
        total_results: capture.totalResults || 0,
        pages_captured: capture.currentPage,
        captured_at: new Date().toISOString(),
      };
      await queueLocally({
        source: capture.portal,
        search_context: context,
        listings: capture.pendingListings.splice(0),
      });
    }
  }
}

// ── Fetch a search results page and extract listings ───────
async function handleFetchPage(url, portal) {
  const response = await fetch(url, {
    headers: {
      'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
      'Accept': 'text/html,application/xhtml+xml',
    },
  });

  if (!response.ok) {
    throw new Error('Failed to fetch page: ' + response.status);
  }

  const html = await response.text();
  const listings = parseListingsFromHtml(html, portal);

  return { listings: listings };
}

// ── Parse listings from raw HTML string ────────────────────
function parseListingsFromHtml(html, portal) {
  const parser = new DOMParser();
  const doc = parser.parseFromString(html, 'text/html');
  const listings = [];

  if (portal === 'p24') {
    const tiles = findTiles(doc, [
      '.js_resultTile',
      '.p24_regularTile',
      '[class*="listing-result"]',
      '.js_listingTile',
      '[data-listing-id]',
    ]);

    tiles.forEach(tile => {
      try {
        listings.push(extractP24Listing(tile));
      } catch (e) { /* skip */ }
    });
  } else if (portal === 'pp') {
    const tiles = findTiles(doc, [
      '[class*="listing-result"]',
      '[class*="listingResult"]',
      '.listing-card',
      '.result-card',
      '.property-card',
      '[data-testid*="listing"]',
    ]);

    tiles.forEach(tile => {
      try {
        listings.push(extractPPListing(tile));
      } catch (e) { /* skip */ }
    });
  }

  return listings.filter(l => l.portal_ref || l.address || l.portal_url);
}

// ── Find tiles using multiple selector fallbacks ───────────
function findTiles(doc, selectors) {
  for (const sel of selectors) {
    const tiles = doc.querySelectorAll(sel);
    if (tiles.length > 0) return Array.from(tiles);
  }
  return [];
}

// ── Helper: get only direct/own text of an element ──────────
function ownText(el) {
  let text = '';
  for (const node of el.childNodes) {
    if (node.nodeType === 3) { // TEXT_NODE
      text += node.textContent;
    }
  }
  return text.trim();
}

// ── P24 known property types ────────────────────────────────
const P24_TYPES = [
  'house', 'apartment', 'townhouse', 'flat', 'duplex', 'simplex',
  'cluster', 'cottage', 'farm', 'smallholding', 'vacant land',
  'land', 'commercial', 'industrial', 'office', 'retail',
  'penthouse', 'studio',
];

// ── P24 badge text filter ───────────────────────────────────
const P24_BADGE_TEXTS = [
  'reduced', 'no transfer duty', 'new', 'sole mandate', 'auction',
  'hot', 'under offer', 'sold', 'pending', 'price reduced',
  'exclusive', 'must sell', 'repossessed', 'bank sale',
  'new release', 'show day', 'virtual tour', 'new development',
  'new listing', 'featured',
];

function isBadgeText(text) {
  if (!text) return true;
  const trimmed = text.trim();
  if (trimmed.length < 3) return true;
  if (/^\d+$/.test(trimmed)) return true;
  if (trimmed === trimmed.toUpperCase() && trimmed.length < 30) return true;
  if (P24_BADGE_TEXTS.includes(trimmed.toLowerCase())) return true;
  return false;
}

// ── Street address detection (mirrors content-p24.js) ───────
const STREET_WORDS = /\b(road|street|avenue|drive|place|close|crescent|lane|way|court|circle|terrace|boulevard|ave|rd|st|dr|blvd|cres|crt)\b/i;
const COMPLEX_WORDS = /\b(estate|village|complex|lodge|manor|park|gardens|court|villas|heights|towers|ridge|mews|close|place)\b/i;
const PRICE_PATTERN = /^R\s*[\d\s,]+/;
const TYPE_PATTERN = /^\d+\s+bedroom/i;
const DESCRIPTION_MIN_LENGTH = 80;

function classifyAddressText(text) {
  if (!text) return false;
  const t = text.trim();
  if (t.length < 3 || t.length > 80) return false;
  if (PRICE_PATTERN.test(t)) return false;
  if (TYPE_PATTERN.test(t)) return false;
  if (isBadgeText(t)) return false;
  if (t.length > 60 && t.includes(' ')) {
    const words = t.split(/\s+/).length;
    if (words > 10) return false;
  }
  if (STREET_WORDS.test(t)) return 'street';
  if (COMPLEX_WORDS.test(t)) return 'complex';
  if (t.length < 50 && /^[\dA-Z]/.test(t) && !t.includes('...')) return 'street';
  return false;
}

// ── P24 listing extraction (mirrors content-p24.js) ─────────
function extractP24Listing(tile) {
  const listing = baseListing('p24');

  // Portal URL first
  try {
    const link = tile.querySelector('a[href*="/for-sale/"], a[href*="/to-rent/"]') ||
                 tile.querySelector('a[href]');
    if (link) listing.portal_url = link.href || link.getAttribute('href');
  } catch (e) { /* */ }

  // Portal ref — from data attributes then URL
  try {
    listing.portal_ref = tile.getAttribute('data-listing-id') ||
                         tile.getAttribute('data-listingid') ||
                         tile.dataset?.listingId || null;
    if (!listing.portal_ref && listing.portal_url) {
      const segments = listing.portal_url.split('/').filter(Boolean);
      for (let i = segments.length - 1; i >= 0; i--) {
        if (/^\d{6,}$/.test(segments[i])) {
          listing.portal_ref = segments[i];
          break;
        }
      }
    }
    if (!listing.portal_ref) {
      const links = tile.querySelectorAll('a[href]');
      for (const link of links) {
        const href = link.href || link.getAttribute('href') || '';
        const m = href.match(/\/(\d{6,})(?:[/?#]|$)/);
        if (m) { listing.portal_ref = m[1]; break; }
      }
    }
    if (listing.portal_ref) listing.portal_ref = 'P24-' + listing.portal_ref.replace(/^P24-/, '');
  } catch (e) { /* */ }

  // Address + Suburb — walk card body to find street address (mirrors content-p24.js)
  try {
    let typeLine = null;
    let suburbFromCard = null;
    let streetAddress = null;

    // Collect all leaf text elements
    const textElements = [];
    const allEls = tile.querySelectorAll('*');
    for (const el of allEls) {
      const t = ownText(el);
      if (t && t.length >= 2 && t.length < 200) {
        textElements.push({ el: el, text: t });
      }
    }

    // Pass 1: find type line
    for (const { text } of textElements) {
      if (!typeLine && TYPE_PATTERN.test(text)) {
        typeLine = text.trim();
        const bedMatch = typeLine.match(/^(\d+)\s+bedroom/i);
        if (bedMatch && listing.bedrooms === null) {
          listing.bedrooms = parseInt(bedMatch[1], 10);
        }
        const typeLower = typeLine.toLowerCase();
        for (const pt of P24_TYPES) {
          if (typeLower.includes(pt)) {
            listing.property_type = pt.charAt(0).toUpperCase() + pt.slice(1);
            break;
          }
        }
        break;
      }
    }

    // Pass 2: find suburb line
    let suburbIdx = -1;
    for (let i = 0; i < textElements.length; i++) {
      const { text } = textElements[i];
      if (text.length > 30) continue;
      if (PRICE_PATTERN.test(text)) continue;
      if (TYPE_PATTERN.test(text)) continue;
      if (isBadgeText(text) && text.length < 5) continue;
      const isSuburbLike = text.length <= 25 && /^[A-Z]/.test(text) && !STREET_WORDS.test(text);
      if (isSuburbLike && typeLine) {
        suburbFromCard = text.trim();
        suburbIdx = i;
        break;
      }
    }

    // Pass 3: find address line after suburb
    if (suburbIdx >= 0) {
      for (let i = suburbIdx + 1; i < textElements.length && i <= suburbIdx + 3; i++) {
        const { text } = textElements[i];
        if (text.length >= DESCRIPTION_MIN_LENGTH) break;
        if (PRICE_PATTERN.test(text)) continue;
        if (TYPE_PATTERN.test(text)) continue;
        const classification = classifyAddressText(text);
        if (classification) {
          streetAddress = text.trim();
          break;
        }
      }
    }

    // Pass 4: class-based selector
    if (!streetAddress) {
      const addrEl = tile.querySelector('.p24_address, [class*="address"]');
      if (addrEl) {
        const addrText = ownText(addrEl).trim() || addrEl.textContent.trim();
        if (addrText.length > 2 && addrText.length < 80 && !PRICE_PATTERN.test(addrText)) {
          streetAddress = addrText;
        }
      }
    }

    // Pass 5: scan for street words
    if (!streetAddress) {
      for (const { text } of textElements) {
        if (text.length >= 3 && text.length <= 80 && STREET_WORDS.test(text) && !PRICE_PATTERN.test(text)) {
          streetAddress = text.trim();
          break;
        }
      }
    }

    // Set address
    if (streetAddress) {
      listing.address = streetAddress;
    } else if (typeLine && suburbFromCard) {
      listing.address = typeLine.replace(/\s+for\s+sale.*/i, '').trim() + ' in ' + suburbFromCard;
    } else if (typeLine) {
      listing.address = typeLine.replace(/\s+for\s+sale.*/i, '').trim();
    }

    if (suburbFromCard) listing.suburb = suburbFromCard;
    if (!listing.address) listing.address = 'Address not available';
  } catch (e) { if (!listing.address) listing.address = 'Address not available'; }

  // Suburb fallbacks
  try {
    if (!listing.suburb && listing.portal_url) {
      const segs = listing.portal_url.split('/').filter(Boolean);
      if (segs.length >= 2) {
        const seg = segs[1];
        const inMatch = seg.match(/in-(.+)$/);
        if (inMatch) listing.suburb = inMatch[1].replace(/-/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
        else if (!/^\d+$/.test(seg)) listing.suburb = seg.replace(/-/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
      }
    }
  } catch (e) { /* */ }

  // Price
  try {
    const allEls = tile.querySelectorAll('*');
    for (const el of allEls) {
      const t = ownText(el);
      const match = t.match(/R\s*([\d\s,]+)/);
      if (match) {
        const cleaned = match[1].replace(/[\s,]/g, '');
        const num = parseInt(cleaned, 10);
        if (num >= 10000) { listing.price = num; break; }
      }
    }
  } catch (e) { /* */ }

  // Property type from title/URL
  try {
    const src = (listing.address + ' ' + (listing.portal_url || '')).toLowerCase();
    for (const type of P24_TYPES) {
      if (src.includes(type)) {
        listing.property_type = type.charAt(0).toUpperCase() + type.slice(1);
        break;
      }
    }
  } catch (e) { /* */ }

  extractFeatures(tile, listing, 'p24');
  extractSizes(tile, listing);
  extractMeta(tile, listing, 'p24');

  return listing;
}

// ── PP listing extraction (mirrors content-pp.js) ──────────
function extractPPListing(tile) {
  const listing = baseListing('pp');

  try {
    listing.portal_ref = tile.getAttribute('data-listing-id') ||
                         tile.getAttribute('data-id') ||
                         tile.dataset?.listingId || null;
    if (!listing.portal_ref) {
      const link = tile.querySelector('a[href*="/for-sale/"], a[href*="/to-rent/"], a[href]');
      if (link) {
        const m = link.href.match(/\/(\d{5,})/);
        if (m) listing.portal_ref = m[1];
      }
    }
    if (listing.portal_ref) listing.portal_ref = 'PP-' + listing.portal_ref.replace(/^PP-/, '');
  } catch (e) { /* */ }

  try {
    const link = tile.querySelector('a[href*="/for-sale/"], a[href*="/to-rent/"], a[href]');
    if (link) listing.portal_url = link.href;
  } catch (e) { /* */ }

  try {
    const el = tile.querySelector('[class*="address"], [class*="title"], h2, h3');
    if (el) listing.address = el.textContent.trim();
  } catch (e) { /* */ }

  try {
    const el = tile.querySelector('[class*="location"], [class*="suburb"], [class*="area"]');
    if (el) listing.suburb = el.textContent.trim();
    else if (listing.address) {
      const parts = listing.address.split(',').map(s => s.trim());
      if (parts.length > 1) listing.suburb = parts[parts.length - 1];
    }
  } catch (e) { /* */ }

  try {
    const el = tile.querySelector('[class*="price"], [class*="Price"]');
    if (el) {
      const cleaned = el.textContent.replace(/[^\d]/g, '');
      if (cleaned) listing.price = parseInt(cleaned, 10);
    }
  } catch (e) { /* */ }

  extractFeatures(tile, listing, 'pp');
  extractSizes(tile, listing);
  extractMeta(tile, listing, 'pp');

  return listing;
}

// ── Shared helpers ─────────────────────────────────────────
function baseListing(source) {
  return {
    portal_ref: null, portal_url: null, address: null, suburb: null,
    price: null, bedrooms: null, bathrooms: null, garages: null,
    property_size_m2: null, erf_size_m2: null, property_type: null,
    agent_name: null, agency_name: null, thumbnail_url: null,
    source: source,
  };
}

function extractFeatures(tile, listing, portal) {
  try {
    const selectors = portal === 'p24'
      ? '.p24_featureDetails span, [class*="feature"] span, .js_iconRow span'
      : '[class*="feature"] span, [class*="Feature"] span, li[class*="feature"]';

    const features = tile.querySelectorAll(selectors);
    features.forEach(feat => {
      const text  = feat.textContent.trim().toLowerCase();
      const title = (feat.getAttribute('title') || '').toLowerCase();
      const num   = parseInt(text, 10);
      if (isNaN(num)) return;

      if (title.includes('bed') || text.includes('bed')) listing.bedrooms = num;
      else if (title.includes('bath') || text.includes('bath')) listing.bathrooms = num;
      else if (title.includes('garage') || title.includes('parking')) listing.garages = num;
    });
  } catch (e) { /* */ }
}

function extractSizes(tile, listing) {
  try {
    const els = tile.querySelectorAll('[class*="size"], [class*="Size"], [class*="area"], [class*="erf"]');
    els.forEach(el => {
      const text = (el.textContent + ' ' + (el.getAttribute('title') || '')).toLowerCase();
      const m = text.match(/([\d,.]+)\s*m/);
      if (m) {
        const val = parseFloat(m[1].replace(/,/g, ''));
        if (text.includes('erf') || text.includes('land') || text.includes('stand')) {
          listing.erf_size_m2 = val;
        } else if (text.includes('floor') || text.includes('size')) {
          listing.property_size_m2 = val;
        } else if (!listing.erf_size_m2) {
          listing.erf_size_m2 = val;
        }
      }
    });
  } catch (e) { /* */ }
}

function extractMeta(tile, listing, portal) {
  try {
    const typeSelectors = portal === 'p24'
      ? '.p24_propertyType, [class*="property-type"], [class*="propertyType"]'
      : '[class*="property-type"], [class*="propertyType"], [class*="badge"]';
    const el = tile.querySelector(typeSelectors);
    if (el) listing.property_type = el.textContent.trim();
  } catch (e) { /* */ }

  try {
    const agentSelectors = portal === 'p24'
      ? '.p24_agentName, [class*="agent-name"], [class*="agentName"]'
      : '[class*="agent-name"], [class*="agentName"], [class*="consultant"]';
    const el = tile.querySelector(agentSelectors);
    if (el) {
      const name = el.textContent.trim();
      if (name.length <= 100) listing.agent_name = name;
    }
  } catch (e) { /* */ }

  try {
    const agencySelectors = portal === 'p24'
      ? '.p24_branchName, [class*="agency"], [class*="branch"]'
      : '[class*="agency"], [class*="Agency"], [class*="brand"]';
    const el = tile.querySelector(agencySelectors);
    if (el) {
      const name = el.textContent.trim();
      if (name.length <= 100) listing.agency_name = name;
    }
  } catch (e) { /* */ }

  try {
    const img = tile.querySelector('img[src], img[data-src]');
    if (img) listing.thumbnail_url = img.src || img.dataset?.src || null;
  } catch (e) { /* */ }
}

// ── Send data to CoreX API ─────────────────────────────────
async function handleSendToCorex(apiUrl, apiToken, payload) {
  const url = apiUrl.replace(/\/+$/, '') + '/api/prospecting/import';

  const response = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type':  'application/json',
      'Accept':        'application/json',
      'Authorization': 'Bearer ' + apiToken,
    },
    body: JSON.stringify(payload),
  });

  if (!response.ok) {
    const text = await response.text().catch(() => '');
    if (response.status === 401) {
      throw new Error('Invalid API token. Check your settings.');
    }
    throw new Error('API error ' + response.status + ': ' + (text || 'Unknown error'));
  }

  return await response.json();
}
