/**
 * CoreX Portal Capture — Private Property Content Script
 *
 * Injected on privateproperty.co.za pages. Extracts search context
 * and per-listing data from PP search result pages.
 *
 * All selectors wrapped in try/catch for defensive extraction.
 */

(function () {
  'use strict';

  // ── Search page detection ──────────────────────────────────
  function isSearchResultsPage() {
    // PP search pages have listing result cards
    const hasResults = !!(
      document.querySelector('[class*="listing-result"]') ||
      document.querySelector('[class*="listingResult"]') ||
      document.querySelector('.listing-card') ||
      document.querySelector('[data-testid*="listing"]') ||
      document.querySelector('.result-card') ||
      document.querySelector('.property-card')
    );

    // Detail pages have a single large listing with gallery
    const isDetailPage = !!(
      document.querySelector('[class*="listing-detail"]') ||
      document.querySelector('[class*="galleryCarousel"]') ||
      document.querySelector('.property-detail-page')
    );

    return hasResults && !isDetailPage;
  }

  // ── Extract search context ─────────────────────────────────
  function getSearchContext() {
    let searchTerm   = null;
    let totalResults = null;
    let totalPages   = null;

    // Search term from heading or breadcrumb
    try {
      const h1 = document.querySelector('h1');
      if (h1) {
        searchTerm = h1.textContent.trim();
      }
    } catch (e) { /* ignore */ }

    if (!searchTerm) {
      try {
        const title = document.title || '';
        searchTerm = title.split('|')[0].split('-')[0].trim();
      } catch (e) { /* ignore */ }
    }

    // Total results count
    try {
      const countEl =
        document.querySelector('[class*="results-count"]') ||
        document.querySelector('[class*="resultsCount"]') ||
        document.querySelector('[class*="search-count"]') ||
        document.querySelector('[class*="totalResults"]');

      if (countEl) {
        const text = countEl.textContent.trim();
        const match = text.match(/([\d,\s]+)\s*(?:results?|propert|listing)/i) ||
                      text.match(/([\d,\s]+)/);
        if (match) {
          totalResults = parseInt(match[1].replace(/[\s,]/g, ''), 10);
        }
      }
    } catch (e) { /* ignore */ }

    // Fallback: count visible tiles
    if (!totalResults) {
      try {
        const tiles = getListingTiles();
        if (tiles.length > 0) {
          totalResults = tiles.length;
        }
      } catch (e) { /* ignore */ }
    }

    // Total pages from pagination
    try {
      const pageLinks = document.querySelectorAll(
        '.pagination a, [class*="pagination"] a, [class*="pager"] a, nav[aria-label*="page"] a'
      );
      let maxPage = 1;
      pageLinks.forEach(link => {
        const num = parseInt(link.textContent.trim(), 10);
        if (!isNaN(num) && num > maxPage) maxPage = num;
      });

      // Check for next page link
      const nextBtn = document.querySelector('[class*="next"], [aria-label="Next"]');
      if (nextBtn && nextBtn.href) {
        const urlMatch = nextBtn.href.match(/[?&]page=(\d+)/i);
        if (urlMatch) {
          const nextPage = parseInt(urlMatch[1], 10);
          if (nextPage > maxPage) maxPage = nextPage;
        }
      }

      totalPages = maxPage;
    } catch (e) { /* ignore */ }

    // Calculate total pages from results if needed
    if (totalResults && (!totalPages || totalPages <= 1)) {
      totalPages = Math.ceil(totalResults / 20);
    }

    return {
      isSearchPage: isSearchResultsPage(),
      searchTerm:   searchTerm,
      totalResults: totalResults,
      totalPages:   totalPages || 1,
    };
  }

  // ── Get listing tile elements ──────────────────────────────
  function getListingTiles() {
    const selectors = [
      '[class*="listing-result"]',
      '[class*="listingResult"]',
      '.listing-card',
      '.result-card',
      '.property-card',
      '[data-testid*="listing"]',
      '[class*="PropertyCard"]',
    ];

    for (const sel of selectors) {
      const tiles = document.querySelectorAll(sel);
      if (tiles.length > 0) return Array.from(tiles);
    }

    return [];
  }

  // ── Extract data from a single listing tile ────────────────
  function extractListing(tile) {
    const listing = {
      portal_ref:       null,
      portal_url:       null,
      address:          null,
      suburb:           null,
      price:            null,
      bedrooms:         null,
      bathrooms:        null,
      garages:          null,
      property_size_m2: null,
      erf_size_m2:      null,
      property_type:    null,
      agent_name:       null,
      agency_name:      null,
      thumbnail_url:    null,
      source:           'pp',
    };

    // Portal ref from data attribute or URL
    try {
      listing.portal_ref = tile.getAttribute('data-listing-id') ||
                           tile.getAttribute('data-id') ||
                           tile.dataset.listingId || null;

      if (!listing.portal_ref) {
        const link = tile.querySelector('a[href*="/for-sale/"], a[href*="/to-rent/"], a[href]');
        if (link) {
          const hrefMatch = link.href.match(/\/(\d{5,})/);
          if (hrefMatch) listing.portal_ref = hrefMatch[1];
        }
      }

      if (listing.portal_ref) {
        listing.portal_ref = 'PP-' + listing.portal_ref.replace(/^PP-/, '');
      }
    } catch (e) { /* ignore */ }

    // Portal URL
    try {
      const link = tile.querySelector('a[href*="/for-sale/"], a[href*="/to-rent/"], a[href]');
      if (link) {
        listing.portal_url = link.href;
      }
    } catch (e) { /* ignore */ }

    // Address
    try {
      const addrEl = tile.querySelector(
        '[class*="address"], [class*="title"], [class*="listing-name"], h2, h3'
      );
      if (addrEl) {
        listing.address = addrEl.textContent.trim();
      }
    } catch (e) { /* ignore */ }

    // Suburb
    try {
      const locEl = tile.querySelector(
        '[class*="location"], [class*="suburb"], [class*="area"]'
      );
      if (locEl) {
        listing.suburb = locEl.textContent.trim();
      } else if (listing.address) {
        const parts = listing.address.split(',').map(s => s.trim());
        if (parts.length > 1) listing.suburb = parts[parts.length - 1];
      }
    } catch (e) { /* ignore */ }

    // Price
    try {
      const priceEl = tile.querySelector('[class*="price"], [class*="Price"]');
      if (priceEl) {
        const cleaned = priceEl.textContent.replace(/[^\d]/g, '');
        if (cleaned) listing.price = parseInt(cleaned, 10);
      }
    } catch (e) { /* ignore */ }

    // Features: bedrooms, bathrooms, garages
    try {
      const features = tile.querySelectorAll(
        '[class*="feature"] span, [class*="Feature"] span, [class*="icon-feature"], li[class*="feature"]'
      );

      features.forEach(feat => {
        const text  = feat.textContent.trim().toLowerCase();
        const title = (feat.getAttribute('title') || feat.getAttribute('aria-label') || '').toLowerCase();
        const cls   = (feat.className || '').toLowerCase();
        const num   = parseInt(text, 10);

        if (isNaN(num)) return;

        if (title.includes('bed') || cls.includes('bed') || text.includes('bed')) {
          listing.bedrooms = num;
        } else if (title.includes('bath') || cls.includes('bath') || text.includes('bath')) {
          listing.bathrooms = num;
        } else if (title.includes('garage') || title.includes('parking') ||
                   cls.includes('garage') || cls.includes('parking') || text.includes('garage')) {
          listing.garages = num;
        }
      });

      // Fallback: look for SVG icon patterns with sibling text
      if (listing.bedrooms === null || listing.bathrooms === null) {
        const iconGroups = tile.querySelectorAll('[class*="feature"], [class*="amenity"]');
        iconGroups.forEach(group => {
          const svg = group.querySelector('svg, img');
          const numEl = group.querySelector('span, p');
          if (!svg || !numEl) return;

          const svgClass = ((svg.className && svg.className.baseVal) || svg.getAttribute('data-icon') || '').toLowerCase();
          const imgAlt   = (svg.getAttribute('alt') || '').toLowerCase();
          const hint     = svgClass + ' ' + imgAlt;
          const n        = parseInt(numEl.textContent.trim(), 10);
          if (isNaN(n)) return;

          if (hint.includes('bed') && listing.bedrooms === null) listing.bedrooms = n;
          else if (hint.includes('bath') && listing.bathrooms === null) listing.bathrooms = n;
          else if ((hint.includes('garage') || hint.includes('parking')) && listing.garages === null) listing.garages = n;
        });
      }
    } catch (e) { /* ignore */ }

    // Property/erf size
    try {
      const sizeEls = tile.querySelectorAll('[class*="size"], [class*="Size"], [class*="area"]');
      sizeEls.forEach(el => {
        const text = (el.textContent + ' ' + (el.getAttribute('title') || '')).toLowerCase();
        const numMatch = text.match(/([\d,.]+)\s*m/);
        if (numMatch) {
          const val = parseFloat(numMatch[1].replace(/,/g, ''));
          if (text.includes('erf') || text.includes('land') || text.includes('stand')) {
            listing.erf_size_m2 = val;
          } else if (text.includes('floor') || text.includes('size')) {
            listing.property_size_m2 = val;
          } else if (!listing.erf_size_m2) {
            listing.erf_size_m2 = val;
          }
        }
      });
    } catch (e) { /* ignore */ }

    // Property type
    try {
      const typeEl = tile.querySelector(
        '[class*="property-type"], [class*="propertyType"], [class*="listing-type"], [class*="badge"]'
      );
      if (typeEl) listing.property_type = typeEl.textContent.trim();
    } catch (e) { /* ignore */ }

    // Agent name
    try {
      const agentEl = tile.querySelector(
        '[class*="agent-name"], [class*="agentName"], [class*="consultant"]'
      );
      if (agentEl) listing.agent_name = agentEl.textContent.trim();
    } catch (e) { /* ignore */ }

    // Agency name
    try {
      const agencyEl = tile.querySelector(
        '[class*="agency"], [class*="Agency"], [class*="brand"], [class*="logo"] + span'
      );
      if (agencyEl) listing.agency_name = agencyEl.textContent.trim();
    } catch (e) { /* ignore */ }

    // Thumbnail
    try {
      const img = tile.querySelector('img[src], img[data-src]');
      if (img) {
        listing.thumbnail_url = img.src || img.dataset.src || img.getAttribute('data-original') || null;
      }
    } catch (e) { /* ignore */ }

    return listing;
  }

  // ── Extract all listings from current page ─────────────────
  function extractAllListings() {
    const tiles = getListingTiles();
    const listings = [];

    tiles.forEach(tile => {
      try {
        const listing = extractListing(tile);
        if (listing.portal_ref || listing.address || listing.portal_url) {
          listings.push(listing);
        }
      } catch (e) { /* skip broken tile */ }
    });

    return listings;
  }

  // ── Message handler ────────────────────────────────────────
  chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
    if (msg.action === 'getPageInfo') {
      const context = getSearchContext();
      sendResponse(context);
      return true;
    }

    if (msg.action === 'getListings') {
      const listings = extractAllListings();
      sendResponse({ listings: listings });
      return true;
    }

    return false;
  });
})();
