/**
 * CoreX Portal Capture — Background Service Worker
 *
 * Handles:
 * 1. Fetching additional search result pages for auto-pagination
 * 2. Parsing listing HTML from fetched pages
 * 3. Sending captured data to the CoreX API
 */

// ── Message router ─────────────────────────────────────────
chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  if (msg.action === 'fetchPage') {
    handleFetchPage(msg.url, msg.portal)
      .then(result => sendResponse(result))
      .catch(err => sendResponse({ listings: [], error: err.message }));
    return true; // keep channel open for async
  }

  if (msg.action === 'sendToCorex') {
    handleSendToCorex(msg.apiUrl, msg.apiToken, msg.payload)
      .then(result => sendResponse(result))
      .catch(err => sendResponse({ error: err.message }));
    return true;
  }

  return false;
});

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

// ── P24 listing extraction (mirrors content-p24.js) ────────
function extractP24Listing(tile) {
  const listing = baseListing('p24');

  try {
    listing.portal_ref = tile.getAttribute('data-listing-id') ||
                         tile.getAttribute('data-listingid') ||
                         tile.dataset?.listingId || null;
    if (!listing.portal_ref) {
      const link = tile.querySelector('a[href*="/for-sale/"], a[href*="/to-rent/"], a[href*="/property/"]');
      if (link) {
        const m = link.href.match(/\/(\d{6,})/);
        if (m) listing.portal_ref = m[1];
      }
    }
    if (listing.portal_ref) listing.portal_ref = 'P24-' + listing.portal_ref.replace(/^P24-/, '');
  } catch (e) { /* */ }

  try {
    const link = tile.querySelector('a[href*="/for-sale/"], a[href*="/to-rent/"], a[href*="/property/"]') ||
                 tile.querySelector('a[href]');
    if (link) listing.portal_url = link.href;
  } catch (e) { /* */ }

  try {
    const el = tile.querySelector('.p24_address, [class*="address"], .p24_title, [class*="listing-title"]');
    if (el) listing.address = el.textContent.trim();
  } catch (e) { /* */ }

  try {
    const el = tile.querySelector('.p24_location, [class*="location"], [class*="suburb"]');
    if (el) listing.suburb = el.textContent.trim();
    else if (listing.address) {
      const parts = listing.address.split(',').map(s => s.trim());
      if (parts.length > 1) listing.suburb = parts[parts.length - 1];
    }
  } catch (e) { /* */ }

  try {
    const el = tile.querySelector('.p24_price, [class*="price"]');
    if (el) {
      const cleaned = el.textContent.replace(/[^\d]/g, '');
      if (cleaned) listing.price = parseInt(cleaned, 10);
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
  // Property type
  try {
    const typeSelectors = portal === 'p24'
      ? '.p24_propertyType, [class*="property-type"], [class*="propertyType"]'
      : '[class*="property-type"], [class*="propertyType"], [class*="badge"]';
    const el = tile.querySelector(typeSelectors);
    if (el) listing.property_type = el.textContent.trim();
  } catch (e) { /* */ }

  // Agent name
  try {
    const agentSelectors = portal === 'p24'
      ? '.p24_agentName, [class*="agent-name"], [class*="agentName"]'
      : '[class*="agent-name"], [class*="agentName"], [class*="consultant"]';
    const el = tile.querySelector(agentSelectors);
    if (el) listing.agent_name = el.textContent.trim();
  } catch (e) { /* */ }

  // Agency name
  try {
    const agencySelectors = portal === 'p24'
      ? '.p24_branchName, [class*="agency"], [class*="branch"]'
      : '[class*="agency"], [class*="Agency"], [class*="brand"]';
    const el = tile.querySelector(agencySelectors);
    if (el) listing.agency_name = el.textContent.trim();
  } catch (e) { /* */ }

  // Thumbnail
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
