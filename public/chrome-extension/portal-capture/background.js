/**
 * CoreX — Background Service Worker
 *
 * Handles:
 * 1. Capture orchestration — runs the page-by-page scrape loop
 * 2. Rate-limited fetching with human-like random delays
 * 3. Batch API sends (every 5 pages / 100 listings)
 * 4. State persistence for resume after popup close / Chrome restart
 * 5. Chrome notifications on capture complete
 * 6. Error handling: rate limits, network issues, API failures, durable queue
 * 7. Pull Property — send scraped listing detail to CoreX API to create a Property
 */

// Durable IndexedDB send-queue (self.CoreXQueue). Replaces the old
// chrome.storage.local array queue that silently dropped batches on quota.
importScripts('queue-idb.js');

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
    authError:        false, // set when CoreX rejected our token — capture paused, not "offline"
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

  if (msg.action === 'healthCheck') {
    healthCheck(msg.apiUrl, msg.apiToken)
      .then(result => sendResponse(result))
      .catch(() => sendResponse({ state: 'unreachable' }));
    return true;
  }

  if (msg.action === 'getQueueStatus') {
    getQueueStatus()
      .then(result => sendResponse(result))
      .catch(() => sendResponse({ count: 0, storageRatio: 0 }));
    return true;
  }

  if (msg.action === 'checkDuplicateSearch') {
    checkDuplicateSearch(msg.apiUrl, msg.apiToken, msg.searchUrl)
      .then(result => sendResponse(result))
      .catch(err => sendResponse({ duplicate: false }));
    return true;
  }

  if (msg.action === 'pullProperty') {
    handlePullProperty(msg.apiUrl, msg.apiToken, msg.property)
      .then(result => sendResponse(result))
      .catch(err => sendResponse({ error: err.message }));
    return true;
  }

  // CMA Info deeds capture — the ONE flow with no popup step (an on-page
  // button on cmainfo.co.za messages here directly), so unlike every other
  // handler above, apiUrl/apiToken are NOT relayed in msg — read them from
  // chrome.storage.local ourselves, same source of truth the popup itself
  // reads from.
  if (msg.action === 'captureDeed') {
    handleCaptureDeed(msg.payload)
      .then(result => sendResponse(result))
      .catch(err => sendResponse({ error: err.message }));
    return true;
  }

  // TVA contact capture (2026-08-12) — same no-popup-step shape as
  // captureDeed above; apiUrl/apiToken read from chrome.storage.local here.
  if (msg.action === 'captureTvaContacts') {
    handleCaptureTvaContacts(msg.payload)
      .then(result => sendResponse(result))
      .catch(err => sendResponse({ error: err.message }));
    return true;
  }

  // TVA company DIRECTORSHIP capture (2026-08-14) — directors → natural-person
  // contacts linked to the company entity contact. Same transport shape as
  // captureTvaContacts above.
  if (msg.action === 'captureTvaCompanyDirectors') {
    handleCaptureTvaCompanyDirectors(msg.payload)
      .then(result => sendResponse(result))
      .catch(err => sendResponse({ error: err.message }));
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
    authError:        capture.authError,
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
  if (portal === 'p24') {
    // P24 uses path-based pagination: /p2, /p3, etc.
    // Insert /pN before the query string
    const url = new URL(baseUrl);
    let path = url.pathname;
    // Remove any existing /pN from path
    path = path.replace(/\/p\d+$/, '');
    // Add new page number (skip /p1 — page 1 has no suffix)
    if (page > 1) {
      path = path + '/p' + page;
    }
    url.pathname = path;
    return url.toString();
  } else {
    // PP uses query-based pagination
    const url = new URL(baseUrl);
    url.searchParams.set('page', page);
    return url.toString();
  }
}

// ── Navigate tab and extract listings via content script ────
async function getListingsFromTab(tabId, pageUrl) {
  // Navigate tab to the page
  await new Promise((resolve, reject) => {
    const onUpdated = (updatedTabId, changeInfo) => {
      if (updatedTabId === tabId && changeInfo.status === 'complete') {
        chrome.tabs.onUpdated.removeListener(onUpdated);
        resolve();
      }
    };
    chrome.tabs.onUpdated.addListener(onUpdated);
    chrome.tabs.update(tabId, { url: pageUrl });

    // Timeout after 15 seconds
    setTimeout(() => {
      chrome.tabs.onUpdated.removeListener(onUpdated);
      reject(new Error('Page load timeout'));
    }, 15000);
  });

  // Wait for DOM to settle
  await sleep(500);

  // Get listings from content script
  return new Promise((resolve, reject) => {
    chrome.tabs.sendMessage(tabId, { action: 'getListings' }, response => {
      if (chrome.runtime.lastError) {
        reject(new Error(chrome.runtime.lastError.message));
      } else {
        resolve(response?.listings || []);
      }
    });
  });
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

// ── Durable send-queue (IndexedDB) ─────────────────────────
// Fixes the "disappearing batches": every queued batch is a committed IDB
// record, so a save either succeeds or throws — it is never silently dropped.

const STORAGE_STOP_RATIO = 0.97;   // hard stop: refuse to queue past this, don't drop
const FLUSH_CHUNK        = 8;      // batches per drain pass — finishes within the SW's life
let queueMigrated = false;

// One-time move of any legacy chrome.storage.local.localQueue array (e.g. the
// 228 batches already stuck) into IndexedDB. Atomic: the localStorage copy is
// removed ONLY after every row is committed to IDB, so nothing is lost if it fails.
async function ensureQueueReady() {
  if (queueMigrated) return;
  const data = await new Promise(r =>
    chrome.storage.local.get(['localQueue', 'queueMigratedToIdb'], r));

  const legacy = data.localQueue || [];
  if (data.queueMigratedToIdb && legacy.length === 0) {
    queueMigrated = true;
    return;
  }
  if (legacy.length === 0) {
    await new Promise(r => chrome.storage.local.set({ queueMigratedToIdb: true }, r));
    queueMigrated = true;
    return;
  }

  try {
    await CoreXQueue.addMany(legacy);                                   // all-or-nothing
    await new Promise(r => chrome.storage.local.remove('localQueue', r));
    await new Promise(r => chrome.storage.local.set({ queueMigratedToIdb: true }, r));
    queueMigrated = true;
    console.log('[CoreX] Migrated ' + legacy.length + ' queued batch(es) to IndexedDB (recoverable).');
  } catch (e) {
    // Leave the localStorage queue untouched so the batches survive; retry next call.
    console.warn('[CoreX] Queue migration deferred (kept localStorage queue intact):', e && e.message);
  }
}

// Persist one batch durably. THROWS on storage pressure / quota so the caller
// can STOP capturing rather than silently lose listings.
async function queueLocally(payload) {
  await ensureQueueReady();

  const p = await CoreXQueue.pressure();
  if (p.ratio >= STORAGE_STOP_RATIO) {
    const err = new Error('Local storage is full'); err.kind = 'storage_full';
    throw err;
  }
  try {
    await CoreXQueue.add(payload);
  } catch (e) {
    const err = new Error('Could not save batch locally: ' + (e && e.message));
    err.kind = 'storage_full';
    throw err;
  }
}

async function getQueueStatus() {
  await ensureQueueReady();
  const count = await CoreXQueue.count();
  const p = await CoreXQueue.pressure();
  return { count: count, storageRatio: p.ratio };
}

// Gentle chunked drain. Deletes a batch ONLY after CoreX confirms the import,
// so an interrupted drain never loses data (server de-dupes by portal_ref, so a
// re-sent batch is harmless). Stops early — and says why — on auth/validation/
// network/server errors instead of masking them all as "offline".
async function flushLocalQueue(apiUrl, apiToken) {
  await ensureQueueReady();

  const total = await CoreXQueue.count();
  if (total === 0) return { flushed: 0, remaining: 0, done: true, stop: null };

  const chunk = await CoreXQueue.peek(FLUSH_CHUNK);
  let flushed = 0;
  let stop = null;

  for (const item of chunk) {
    try {
      await handleSendToCorex(apiUrl, apiToken, item.payload);
      await CoreXQueue.remove(item.id);   // delete only after a confirmed 2xx import
      flushed++;
    } catch (err) {
      stop = err.kind || 'server';        // keep the batch; report the real reason
      break;
    }
  }

  const remaining = await CoreXQueue.count();

  // More to go and nothing blocking → schedule the next chunk unattended.
  if (remaining > 0 && !stop) scheduleDrain();

  return { flushed: flushed, remaining: remaining, done: remaining === 0, stop: stop };
}

// Unattended continuation of the drain (works even if the popup is closed).
function scheduleDrain() {
  try { chrome.alarms.create('coreXQueueDrain', { delayInMinutes: 0.5 }); } catch (e) { /* */ }
}

if (chrome.alarms && chrome.alarms.onAlarm) {
  chrome.alarms.onAlarm.addListener(async (alarm) => {
    if (alarm.name !== 'coreXQueueDrain') return;
    const s = await new Promise(r => chrome.storage.local.get(['apiUrl', 'apiToken'], r));
    if (!s.apiToken) return;
    try {
      await flushLocalQueue(s.apiUrl || 'https://www.corexos.co.za', s.apiToken);
    } catch (e) { /* next alarm retries */ }
  });
}

// ── Lightweight auth/health probe ──────────────────────────
// Reuses the read-only check-search endpoint so it authenticates EXACTLY like
// the import call. Distinguishes token-expired (401/419) from no-agency (422)
// from server error from truly-unreachable — so "token expired" stops looking
// identical to "offline".
async function healthCheck(apiUrl, apiToken) {
  if (!apiToken) return { state: 'no_token' };
  const url = apiUrl.replace(/\/+$/, '') +
    '/api/prospecting/check-search?search_url=' + encodeURIComponent('corex-extension-healthcheck');

  let resp;
  try {
    resp = await fetch(url, {
      method: 'GET',
      headers: { 'Accept': 'application/json', 'Authorization': 'Bearer ' + apiToken },
    });
  } catch (e) {
    return { state: 'unreachable' };          // network / DNS / TLS — server not reached
  }

  if (resp.ok) return { state: 'connected' };
  if (resp.status === 401 || resp.status === 419) return { state: 'auth', status: resp.status };
  if (resp.status === 422) return { state: 'no_agency', status: resp.status };
  return { state: 'server_error', status: resp.status };
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

// ── Send batch to API with durable queue fallback ──────────
async function sendBatchToApi(listings, context) {
  const payload = {
    source: capture.portal,
    // Snapshot the context at send time — intermediate (partial) batches must never
    // pick up a capture_complete flag set later for the final batch (shared-ref race).
    search_context: { ...context },
    listings: listings,
  };

  try {
    const result = await handleSendToCorex(capture.apiUrl, capture.apiToken, payload);

    if (result && !result.error) {
      capture.sentListings += listings.length;
      capture.importedCount += (result.imported || 0);
      capture.updatedCount += (result.updated || 0);
      capture.batchesSent++;
      capture.error = null;
      return true;
    }
    return false;
  } catch (err) {
    // ALWAYS preserve the batch first (never drop stock)...
    try {
      await queueLocally(payload);
    } catch (storageErr) {
      // Durable queue itself is full — STOP rather than silently lose listings.
      capture.cancelled = true;
      capture.error = 'STORAGE FULL — capture stopped so nothing is lost. Open CoreX to send the ' +
                      'queued batches, then capture again.';
      return false;
    }

    // ...then react to the ERROR KIND honestly, not a blanket "offline".
    if (err.kind === 'auth') {
      capture.cancelled = true;        // a bad token won't fix itself — stop hammering
      capture.authError = true;
      capture.error = 'Login rejected (token expired) — re-authenticate in Settings. Capture paused; ' +
                      listings.length + ' listings safely queued (nothing lost).';
    } else if (err.kind === 'validation') {
      capture.cancelled = true;
      capture.authError = true;
      capture.error = 'CoreX rejected the batch (no agency context) — fix your login/agency. ' +
                      listings.length + ' listings safely queued (nothing lost).';
    } else if (err.kind === 'network') {
      capture.error = 'CoreX unreachable — ' + listings.length +
                      ' listings safely queued; will send when back online.';
    } else {
      capture.error = 'CoreX server error (' + (err.status || '5xx') + ') — ' + listings.length +
                      ' listings safely queued; will retry.';
    }
    return false;
  }
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
    // MIC SUBURB RECONCILE — false on every partial/intermediate batch; set true ONLY on
    // the final batch below when the WHOLE suburb was captured (all pages, none skipped).
    capture_complete: false,
  };

  // Track every mid-loop batch send so we can await them ALL before we compute the
  // final "captured" total — otherwise fire-and-forget batches ingest server-side but
  // their imported/updated counts land after the completion snapshot and the shown
  // figure undercounts (271 vs the ~480 actually ingested). Awaiting them before the
  // final capture_complete batch also guarantees the reconcile batch is the LAST to
  // reach the server, so it never sees a still-in-flight page as "gone".
  const inFlightBatches = [];

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

    // Pages 2..N
    for (let p = startPage; p <= capture.totalPages; p++) {
      if (capture.cancelled) break;

      capture.currentPage = p;
      capture.error = null;

      const pageUrl = buildPageUrl(capture.baseUrl, p, capture.portal);

      // Both P24 and PP: navigate tab to page, extract from live DOM via content script
      try {
        const listings = await getListingsFromTab(capture.tabId, pageUrl);
        if (listings && listings.length > 0) {
          capture.pendingListings.push(...listings);
          capture.capturedListings += listings.length;
        } else {
          capture.parseWarnings++;
        }
      } catch (e) {
        capture.parseWarnings++;
        // Continue to next page on error
      }

      context.pages_captured = p;

      // Batch send every 100 listings — but NEVER flush on the last page: hold its
      // listings for the final (capture_complete) batch so a complete capture always
      // ends with a flagged non-empty send the server can reconcile against.
      if (capture.pendingListings.length >= 100 && p < capture.totalPages) {
        const batch = capture.pendingListings.splice(0, capture.pendingListings.length);
        if (batch.length > 0) {
          // Overlaps with page navigation (not awaited here), but tracked so it is
          // awaited before completion — see inFlightBatches / Promise.all below.
          inFlightBatches.push(sendBatchToApi(batch, context));
        }
      }

      await persistCaptureState();

      // 1.5s delay between pages — only between pages, not after last
      if (p < capture.totalPages && !capture.cancelled) {
        await sleep(1500);
      }
    }

    // Await EVERY mid-loop batch before finishing — so the completion total reflects
    // all ingested rows (not just the awaited final batch) AND the reconcile batch below
    // is the last to hit the server.
    await Promise.all(inFlightBatches);

    // Send any remaining pending listings (final batch — must await)
    if (capture.pendingListings.length > 0 && !capture.cancelled) {
      const batch = capture.pendingListings.splice(0, capture.pendingListings.length);
      context.pages_captured = capture.currentPage;
      // MIC SUBURB RECONCILE — mark this a COMPLETE suburb capture ONLY when every page was
      // walked, none was skipped/failed (parseWarnings === 0), and it wasn't cancelled. The
      // server retires listings gone from the suburb ONLY when this flag is true — a partial
      // capture (skipped page / cancel) leaves it false so nothing is wrongly retired.
      context.capture_complete = !capture.cancelled
        && capture.parseWarnings === 0
        && capture.currentPage >= capture.totalPages;
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
      try {
        await queueLocally({
          source: capture.portal,
          search_context: context,
          listings: capture.pendingListings.splice(0),
        });
      } catch (queueErr) {
        capture.error = (capture.error ? capture.error + ' ' : '') +
                        '(Could not queue trailing listings — storage full.)';
      }
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

// ── Parse listings from raw HTML string (PP only) ──────────
function parseListingsFromHtml(html, portal) {
  const parser = new DOMParser();
  const doc = parser.parseFromString(html, 'text/html');
  const listings = [];

  if (portal === 'pp') {
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

  extractFeatures(tile, listing);
  extractSizes(tile, listing);
  extractMeta(tile, listing);

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

function extractFeatures(tile, listing) {
  try {
    const features = tile.querySelectorAll('[class*="feature"] span, [class*="Feature"] span, li[class*="feature"]');
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

function extractMeta(tile, listing) {
  try {
    const el = tile.querySelector('[class*="property-type"], [class*="propertyType"], [class*="badge"]');
    if (el) listing.property_type = el.textContent.trim();
  } catch (e) { /* */ }

  try {
    const el = tile.querySelector('[class*="agent-name"], [class*="agentName"], [class*="consultant"]');
    if (el) {
      const name = el.textContent.trim();
      if (name.length <= 100) listing.agent_name = name;
    }
  } catch (e) { /* */ }

  try {
    const el = tile.querySelector('[class*="agency"], [class*="Agency"], [class*="brand"]');
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

// ── Send data to CoreX API (prospecting) ────────────────────
async function handleSendToCorex(apiUrl, apiToken, payload) {
  const url = apiUrl.replace(/\/+$/, '') + '/api/prospecting/import';

  let response;
  try {
    response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type':  'application/json',
        'Accept':        'application/json',
        'Authorization': 'Bearer ' + apiToken,
      },
      body: JSON.stringify(payload),
    });
  } catch (e) {
    // fetch() only rejects on a genuine network failure (DNS/TLS/offline).
    const err = new Error('CoreX unreachable'); err.kind = 'network';
    throw err;
  }

  if (!response.ok) {
    const text = await response.text().catch(() => '');
    const err = new Error('API ' + response.status + ': ' + (text || 'Unknown error').slice(0, 200));
    err.status = response.status;
    if (response.status === 401 || response.status === 419) err.kind = 'auth';        // token expired
    else if (response.status === 422) err.kind = 'validation';                        // no agency context
    else err.kind = 'server';                                                         // 5xx / other
    throw err;
  }

  return await response.json();
}

// ── Pull Property — send to CoreX to create a Property ──────
async function handlePullProperty(apiUrl, apiToken, property) {
  const url = apiUrl.replace(/\/+$/, '') + '/api/properties/pull-from-portal';

  const response = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type':  'application/json',
      'Accept':        'application/json',
      'Authorization': 'Bearer ' + apiToken,
    },
    body: JSON.stringify(property),
  });

  if (!response.ok) {
    const text = await response.text().catch(() => '');
    if (response.status === 401) {
      throw new Error('Invalid API token. Check your settings.');
    }
    if (response.status === 422) {
      try {
        const errors = JSON.parse(text);
        const firstError = Object.values(errors.errors || {})[0];
        throw new Error(firstError ? firstError[0] : 'Validation failed');
      } catch (e) {
        if (e.message.includes('Validation')) throw e;
        throw new Error('Validation failed: ' + text);
      }
    }
    throw new Error('API error ' + response.status + ': ' + (text || 'Unknown error'));
  }

  const result = await response.json();

  // Chrome notification
  try {
    chrome.notifications.create('pull-complete', {
      type: 'basic',
      iconUrl: 'icons/icon-128.png',
      title: 'CoreX: Property Pulled',
      message: (property.title || 'Property') + ' has been added to CoreX',
      priority: 2,
    });
  } catch (e) { /* ignore */ }

  return result;
}

// ── CMA Info deeds capture — send to CoreX ─────────────────
// Mirrors handlePullProperty()'s shape (single-item POST, no durable queue —
// this is a one-off capture, not a paginated bulk loop) with one deliberate
// difference: this is the only capture flow with no popup step, so
// apiUrl/apiToken are read from chrome.storage.local HERE rather than
// relayed in the message.
//
// Endpoint + auth verified against cc1's actual shipped code (not just the
// spec doc) — routes/api.php nests deeds-capture inside the
// auth:sanctum + prefix('v1') group, so the full path is
// /api/v1/deeds-capture (note the /v1/ — this endpoint is NOT under the
// same bare /api/... path the older /api/prospecting/import uses). Same
// Bearer-token flow as every other capture source. The response shape is
// { ok, results: [{ source_ref, tracked_property_id, owner_contact_id,
// created, error? }] } — a 200 does NOT guarantee success for every row
// (batch never hard-fails on one bad row); content-cmainfo.js's
// onCaptureClick() is what checks results[0].error, not this function —
// this function's job stays limited to transport (auth, error-kind
// distinction, endpoint), same separation as every other handler here.
async function handleCaptureDeed(payload) {
  const settings = await new Promise(resolve => {
    chrome.storage.local.get(['apiUrl', 'apiToken'], resolve);
  });

  if (!settings.apiToken) {
    throw new Error('Not connected — add your API token in the CoreX extension Settings.');
  }

  const apiUrl = (settings.apiUrl || 'https://www.corexos.co.za').replace(/\/+$/, '');
  const url = apiUrl + '/api/v1/deeds-capture';

  let response;
  try {
    response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type':  'application/json',
        'Accept':        'application/json',
        'Authorization': 'Bearer ' + settings.apiToken,
      },
      body: JSON.stringify(payload),
    });
  } catch (e) {
    throw new Error('CoreX unreachable');
  }

  if (!response.ok) {
    const text = await response.text().catch(() => '');
    if (response.status === 401 || response.status === 419) {
      throw new Error('Invalid API token. Check your extension Settings.');
    }
    if (response.status === 422) {
      try {
        const errors = JSON.parse(text);
        const firstError = Object.values(errors.errors || {})[0];
        throw new Error(firstError ? firstError[0] : 'Validation failed');
      } catch (e) {
        if (e.message && e.message !== 'Validation failed') throw e;
        throw new Error('Validation failed: ' + text);
      }
    }
    throw new Error('API error ' + response.status + ': ' + (text || 'Unknown error'));
  }

  const result = await response.json();

  try {
    chrome.notifications.create('deeds-capture-complete', {
      type: 'basic',
      iconUrl: 'icons/icon-128.png',
      title: 'CoreX: Deed Captured',
      message: 'Property + sale information sent to CoreX',
      priority: 2,
    });
  } catch (e) { /* ignore */ }

  return result;
}

// ── TVA (The Virtual Agent) contact capture — send to CoreX ────────────
// Same shape and reasoning as handleCaptureDeed() directly above — no popup
// step, reads apiUrl/apiToken from chrome.storage.local, /api/v1/ prefix.
// Response shape: { ok, results: [{ id_number, tva_contact_capture_id,
// tracked_property_id, matched_contact_id, items_count, error? }] } — same
// per-row error semantics as deeds-capture; content-tva.js checks
// results[0].error, this function stays limited to transport.
async function handleCaptureTvaContacts(payload) {
  const settings = await new Promise(resolve => {
    chrome.storage.local.get(['apiUrl', 'apiToken'], resolve);
  });

  if (!settings.apiToken) {
    throw new Error('Not connected — add your API token in the CoreX extension Settings.');
  }

  const apiUrl = (settings.apiUrl || 'https://www.corexos.co.za').replace(/\/+$/, '');
  const url = apiUrl + '/api/v1/tva-contact-capture';

  let response;
  try {
    response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type':  'application/json',
        'Accept':        'application/json',
        'Authorization': 'Bearer ' + settings.apiToken,
      },
      body: JSON.stringify(payload),
    });
  } catch (e) {
    throw new Error('CoreX unreachable');
  }

  if (!response.ok) {
    const text = await response.text().catch(() => '');
    if (response.status === 401 || response.status === 419) {
      throw new Error('Invalid API token. Check your extension Settings.');
    }
    if (response.status === 422) {
      try {
        const errors = JSON.parse(text);
        const firstError = Object.values(errors.errors || {})[0];
        throw new Error(firstError ? firstError[0] : 'Validation failed');
      } catch (e) {
        if (e.message && e.message !== 'Validation failed') throw e;
        throw new Error('Validation failed: ' + text);
      }
    }
    throw new Error('API error ' + response.status + ': ' + (text || 'Unknown error'));
  }

  const result = await response.json();

  try {
    chrome.notifications.create('tva-capture-complete', {
      type: 'basic',
      iconUrl: 'icons/icon-128.png',
      title: 'CoreX: TVA Contacts Captured',
      message: 'Contact numbers/emails sent to CoreX',
      priority: 2,
    });
  } catch (e) { /* ignore */ }

  return result;
}

// TVA company DIRECTORSHIP capture — same transport as handleCaptureTvaContacts.
// POSTs { company:{registration_number,name}, directors:[{id_number,full_name,
// gender}] } to /api/v1/tva-company-directors; the server creates the directors
// as natural-person contacts linked to the company entity contact and returns
// { ok, entity_contact_id, directors:[{id_number, contact_id, error? }] }.
async function handleCaptureTvaCompanyDirectors(payload) {
  const settings = await new Promise(resolve => {
    chrome.storage.local.get(['apiUrl', 'apiToken'], resolve);
  });

  if (!settings.apiToken) {
    throw new Error('Not connected — add your API token in the CoreX extension Settings.');
  }

  const apiUrl = (settings.apiUrl || 'https://www.corexos.co.za').replace(/\/+$/, '');
  const url = apiUrl + '/api/v1/tva-company-directors';

  let response;
  try {
    response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type':  'application/json',
        'Accept':        'application/json',
        'Authorization': 'Bearer ' + settings.apiToken,
      },
      body: JSON.stringify(payload),
    });
  } catch (e) {
    throw new Error('CoreX unreachable');
  }

  if (!response.ok) {
    const text = await response.text().catch(() => '');
    if (response.status === 401 || response.status === 419) {
      throw new Error('Invalid API token. Check your extension Settings.');
    }
    if (response.status === 422) {
      try {
        const errors = JSON.parse(text);
        const firstError = Object.values(errors.errors || {})[0];
        throw new Error(firstError ? firstError[0] : 'Validation failed');
      } catch (e) {
        if (e.message && e.message !== 'Validation failed') throw e;
        throw new Error('Validation failed: ' + text);
      }
    }
    throw new Error('API error ' + response.status + ': ' + (text || 'Unknown error'));
  }

  const result = await response.json();

  try {
    chrome.notifications.create('tva-directors-complete', {
      type: 'basic',
      iconUrl: 'icons/icon-128.png',
      title: 'CoreX: Company Directors Captured',
      message: 'Directors linked to the company in CoreX',
      priority: 2,
    });
  } catch (e) { /* ignore */ }

  return result;
}
