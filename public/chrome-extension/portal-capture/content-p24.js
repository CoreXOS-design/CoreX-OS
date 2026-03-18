/**
 * CoreX Portal Capture — Property24 Content Script
 *
 * Injected on property24.com pages. Extracts search context
 * and per-listing data from P24 search result pages.
 *
 * All selectors are wrapped in try/catch — if P24 changes their
 * DOM, individual fields degrade to null rather than crashing.
 */

(function () {
  'use strict';

  // ── Search page detection ──────────────────────────────────
  function isSearchResultsPage() {
    // P24 search results pages have a results container and are not
    // single listing detail pages. Detail pages have .p24_listingCard
    // or a large hero image section.
    const hasResultsGrid = !!(
      document.querySelector('.js_resultTile') ||
      document.querySelector('[class*="listing-result"]') ||
      document.querySelector('.p24_regularTile') ||
      document.querySelector('[data-listing-id]')
    );

    const isDetailPage = !!(
      document.querySelector('.p24_listingDetail') ||
      document.querySelector('.js_galleryImage')
    );

    return hasResultsGrid && !isDetailPage;
  }

  // ── Extract search context ─────────────────────────────────
  function getSearchContext() {
    let searchTerm  = null;
    let totalResults = null;
    let totalPages   = null;

    // Search term from page title or heading
    try {
      const h1 = document.querySelector('h1');
      if (h1) {
        searchTerm = h1.textContent.trim();
      }
    } catch (e) { /* ignore */ }

    // Fallback: title tag
    if (!searchTerm) {
      try {
        const title = document.title || '';
        // P24 titles: "Houses for sale in Shelly Beach | Property24"
        searchTerm = title.split('|')[0].trim();
      } catch (e) { /* ignore */ }
    }

    // Total results from results count header
    try {
      const countEl =
        document.querySelector('.p24_results .p24_size') ||
        document.querySelector('[class*="resultsCount"]') ||
        document.querySelector('.js_resultsCount') ||
        document.querySelector('.p24_content .p24_headliner');

      if (countEl) {
        const text = countEl.textContent.trim();
        const match = text.match(/([\d,\s]+)\s*results?/i) ||
                      text.match(/([\d,\s]+)\s*propert/i) ||
                      text.match(/([\d,\s]+)/);
        if (match) {
          totalResults = parseInt(match[1].replace(/[\s,]/g, ''), 10);
        }
      }
    } catch (e) { /* ignore */ }

    // Fallback: count listing tiles on page and estimate
    if (!totalResults) {
      try {
        const tiles = getListingTiles();
        if (tiles.length > 0) {
          totalResults = tiles.length; // at least what we see
        }
      } catch (e) { /* ignore */ }
    }

    // Total pages from pagination
    try {
      const pageLinks = document.querySelectorAll(
        '.p24_pager a, .pagination a, [class*="pagination"] a, .p24_results .p24_paginateButton'
      );
      let maxPage = 1;
      pageLinks.forEach(link => {
        const text = link.textContent.trim();
        const num = parseInt(text, 10);
        if (!isNaN(num) && num > maxPage) {
          maxPage = num;
        }
      });

      // Also check for "Next" button with page number in href
      const nextBtn = document.querySelector('a[title="Next"]') ||
                      document.querySelector('.p24_pager .p24_paginateButton:last-child');
      if (nextBtn && nextBtn.href) {
        const urlMatch = nextBtn.href.match(/[?&]Page=(\d+)/i);
        if (urlMatch) {
          const nextPage = parseInt(urlMatch[1], 10);
          if (nextPage > maxPage) maxPage = nextPage;
        }
      }

      totalPages = maxPage;
    } catch (e) { /* ignore */ }

    // If we have totalResults and no good totalPages, calculate
    if (totalResults && (!totalPages || totalPages <= 1)) {
      totalPages = Math.ceil(totalResults / 20); // P24 shows ~20 per page
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
    // P24 uses various tile classes across their versions
    const selectors = [
      '.js_resultTile',
      '.p24_regularTile',
      '[class*="listing-result"]',
      '.js_listingTile',
      '[data-listing-id]',
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
      source:           'p24',
    };

    // Portal ref: from data attribute or URL
    try {
      listing.portal_ref = tile.getAttribute('data-listing-id') ||
                           tile.getAttribute('data-listingid') ||
                           tile.dataset.listingId || null;

      // Fallback: extract from the listing link URL
      if (!listing.portal_ref) {
        const link = tile.querySelector('a[href*="/for-sale/"], a[href*="/to-rent/"], a[href*="/property/"]');
        if (link) {
          const hrefMatch = link.href.match(/\/(\d{6,})/);
          if (hrefMatch) listing.portal_ref = hrefMatch[1];
        }
      }

      if (listing.portal_ref) {
        listing.portal_ref = 'P24-' + listing.portal_ref.replace(/^P24-/, '');
      }
    } catch (e) { /* ignore */ }

    // Portal URL
    try {
      const link = tile.querySelector('a[href*="/for-sale/"], a[href*="/to-rent/"], a[href*="/property/"]') ||
                   tile.querySelector('a[href]');
      if (link) {
        listing.portal_url = link.href;
      }
    } catch (e) { /* ignore */ }

    // Address
    try {
      const addrEl = tile.querySelector('.p24_address, [class*="address"], .p24_title, [class*="listing-title"]');
      if (addrEl) {
        listing.address = addrEl.textContent.trim();
      }
    } catch (e) { /* ignore */ }

    // Suburb: try to extract from address or location element
    try {
      const locEl = tile.querySelector('.p24_location, [class*="location"], [class*="suburb"]');
      if (locEl) {
        listing.suburb = locEl.textContent.trim();
      } else if (listing.address) {
        // Try to get suburb from the last part of the address
        const parts = listing.address.split(',').map(s => s.trim());
        if (parts.length > 1) {
          listing.suburb = parts[parts.length - 1];
        }
      }
    } catch (e) { /* ignore */ }

    // Price
    try {
      const priceEl = tile.querySelector('.p24_price, [class*="price"], .p24_regularTilePrice');
      if (priceEl) {
        const priceText = priceEl.textContent.trim();
        const cleaned = priceText.replace(/[^\d]/g, '');
        if (cleaned) {
          listing.price = parseInt(cleaned, 10);
        }
      }
    } catch (e) { /* ignore */ }

    // Features: bedrooms, bathrooms, garages
    try {
      const features = tile.querySelectorAll(
        '.p24_featureDetails span, [class*="feature"] span, .p24_listingFeatures span, .js_iconRow span'
      );

      features.forEach(feat => {
        const text  = feat.textContent.trim().toLowerCase();
        const title = (feat.getAttribute('title') || '').toLowerCase();
        const num   = parseInt(text, 10);

        if (isNaN(num)) return;

        if (title.includes('bed') || text.includes('bed') || feat.querySelector('[class*="bed"]')) {
          listing.bedrooms = num;
        } else if (title.includes('bath') || text.includes('bath') || feat.querySelector('[class*="bath"]')) {
          listing.bathrooms = num;
        } else if (title.includes('garage') || title.includes('parking') || text.includes('garage') || feat.querySelector('[class*="garage"], [class*="parking"]')) {
          listing.garages = num;
        }
      });

      // Alternative: icon-based features
      if (listing.bedrooms === null) {
        const bedIcon = tile.querySelector('[title*="Bed"], [title*="bed"], .p24_featureDetails .p24_bed');
        if (bedIcon) {
          const bedNum = parseInt(bedIcon.textContent.trim(), 10);
          if (!isNaN(bedNum)) listing.bedrooms = bedNum;
        }
      }
      if (listing.bathrooms === null) {
        const bathIcon = tile.querySelector('[title*="Bath"], [title*="bath"], .p24_featureDetails .p24_bath');
        if (bathIcon) {
          const bathNum = parseInt(bathIcon.textContent.trim(), 10);
          if (!isNaN(bathNum)) listing.bathrooms = bathNum;
        }
      }
      if (listing.garages === null) {
        const garageIcon = tile.querySelector('[title*="Garage"], [title*="garage"], [title*="Parking"], .p24_featureDetails .p24_garage');
        if (garageIcon) {
          const garageNum = parseInt(garageIcon.textContent.trim(), 10);
          if (!isNaN(garageNum)) listing.garages = garageNum;
        }
      }
    } catch (e) { /* ignore */ }

    // Property size / Erf size
    try {
      const sizeEls = tile.querySelectorAll(
        '.p24_size, [class*="size"], [class*="floor"], [class*="erf"], .p24_featureDetails [title*="size"]'
      );
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
            // Default to erf if ambiguous
            listing.erf_size_m2 = val;
          }
        }
      });
    } catch (e) { /* ignore */ }

    // Property type from badge
    try {
      const typeEl = tile.querySelector(
        '.p24_propertyType, [class*="property-type"], [class*="propertyType"], [class*="listing-type"]'
      );
      if (typeEl) {
        listing.property_type = typeEl.textContent.trim();
      }
    } catch (e) { /* ignore */ }

    // Agent name
    try {
      const agentEl = tile.querySelector(
        '.p24_agentName, [class*="agent-name"], [class*="agentName"]'
      );
      if (agentEl) {
        listing.agent_name = agentEl.textContent.trim();
      }
    } catch (e) { /* ignore */ }

    // Agency name
    try {
      const agencyEl = tile.querySelector(
        '.p24_branchName, [class*="agency"], [class*="branch"], [class*="logo-name"]'
      );
      if (agencyEl) {
        listing.agency_name = agencyEl.textContent.trim();
      }
    } catch (e) { /* ignore */ }

    // Thumbnail
    try {
      const img = tile.querySelector(
        'img[src*="listing"], img[data-src], img.p24_mainImage, img[class*="photo"], img'
      );
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
        // Only add if we have at least an address or portal_ref
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
