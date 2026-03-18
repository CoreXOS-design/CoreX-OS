/**
 * CoreX Portal Capture — Property24 Content Script
 *
 * Injected on property24.com pages. Extracts search context
 * and per-listing data from P24 search result pages.
 *
 * P24 uses clean semantic CSS classes (p24_regularTile, p24_price, etc.)
 * so extraction is done via direct selectors — no text walking needed.
 */

(function () {
  'use strict';

  // ── P24 known property types ───────────────────────────────────────
  const PROPERTY_TYPES = [
    'house', 'apartment', 'townhouse', 'flat', 'duplex', 'simplex',
    'cluster', 'cottage', 'farm', 'smallholding', 'vacant land',
    'land', 'commercial', 'industrial', 'office', 'retail',
    'penthouse', 'studio', 'garden cottage', 'granny flat',
  ];

  // ── Search page detection ──────────────────────────────────
  function isSearchResultsPage() {
    const hasResultsGrid = !!document.querySelector('.p24_regularTile');
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

    // Search term from h1
    try {
      const h1 = document.querySelector('h1');
      if (h1) searchTerm = h1.textContent.trim();
    } catch (e) { /* ignore */ }

    // Fallback: title tag
    if (!searchTerm) {
      try {
        searchTerm = (document.title || '').split('|')[0].trim();
      } catch (e) { /* ignore */ }
    }

    // Total results — "Showing X - Y of Z results" or "X results"
    try {
      const countEl =
        document.querySelector('.p24_results .p24_size') ||
        document.querySelector('.p24_content .p24_headliner');
      if (countEl) {
        const text = countEl.textContent.trim();
        const ofMatch = text.match(/of\s+([\d,\s]+)/i);
        if (ofMatch) {
          totalResults = parseInt(ofMatch[1].replace(/[\s,]/g, ''), 10);
        } else {
          const numMatch = text.match(/([\d,\s]+)\s*results?/i) ||
                           text.match(/([\d,\s]+)\s*propert/i) ||
                           text.match(/([\d,\s]+)/);
          if (numMatch) totalResults = parseInt(numMatch[1].replace(/[\s,]/g, ''), 10);
        }
      }
    } catch (e) { /* ignore */ }

    // Fallback: count tiles
    if (!totalResults) {
      const tiles = document.querySelectorAll('.p24_regularTile');
      if (tiles.length > 0) totalResults = tiles.length;
    }

    // Total pages from pagination
    try {
      const pageLinks = document.querySelectorAll(
        '.p24_pager a, .p24_results .p24_paginateButton'
      );
      let maxPage = 1;
      pageLinks.forEach(link => {
        const num = parseInt(link.textContent.trim(), 10);
        if (!isNaN(num) && num > maxPage) maxPage = num;
      });

      const nextBtn = document.querySelector('a[title="Next"]');
      if (nextBtn && nextBtn.href) {
        const urlMatch = nextBtn.href.match(/[?&]Page=(\d+)/i);
        if (urlMatch) {
          const nextPage = parseInt(urlMatch[1], 10);
          if (nextPage > maxPage) maxPage = nextPage;
        }
      }
      totalPages = maxPage;
    } catch (e) { /* ignore */ }

    // Calculate pages from total results if needed
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

  // ── Extract data from a single listing card ──────────────────────
  // Card structure:
  //   div.p24_regularTile[data-listing-number="116833781"]
  //     a.p24_content[href]
  //       span.p24_price[content="1000000"]  → "R 1 000 000"
  //       span.p24_title                      → "8 Bedroom House"
  //       span.p24_location                   → "Uvongo"
  //       span.p24_address                    → "7 Robben Road" (optional)
  //       span.p24_excerpt                    → description text
  //       span.p24_icons
  //         span.p24_featureDetails[title="Bedrooms"] span → "8"
  //         span.p24_featureDetails[title="Bathrooms"] span → "4"
  //         span.p24_featureDetails[title="Parking Spaces"] span → "3"
  //         span.p24_size span → "1 039 m²"
  //       span.p24_branding img[alt] → agency name
  //       span.p24_image img.js_P24_listingImage → thumbnail
  function extractListing(card) {
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

    // ── Portal ref — data-listing-number attribute ─────────────────
    try {
      const ref = card.getAttribute('data-listing-number');
      if (ref) listing.portal_ref = 'P24-' + ref;
    } catch (e) { /* ignore */ }

    // ── Portal URL — from the main content link ───────────────────
    try {
      const content = card.querySelector('a.p24_content');
      if (content) {
        const href = content.getAttribute('href') || content.href;
        if (href) {
          listing.portal_url = href.startsWith('http')
            ? href
            : 'https://www.property24.com' + href;
        }
      }
    } catch (e) { /* ignore */ }

    // Fallback portal ref from URL
    if (!listing.portal_ref && listing.portal_url) {
      try {
        const segments = new URL(listing.portal_url).pathname.split('/').filter(Boolean);
        for (let i = segments.length - 1; i >= 0; i--) {
          if (/^\d{6,}$/.test(segments[i])) {
            listing.portal_ref = 'P24-' + segments[i];
            break;
          }
        }
      } catch (e) { /* ignore */ }
    }

    // ── Price — from .p24_price content attribute or text ──────────
    try {
      const priceEl = card.querySelector('.p24_price');
      if (priceEl) {
        const contentAttr = priceEl.getAttribute('content');
        if (contentAttr) {
          listing.price = parseInt(contentAttr, 10);
        } else {
          const cleaned = priceEl.textContent.replace(/[^\d]/g, '');
          if (cleaned && cleaned.length >= 4) listing.price = parseInt(cleaned, 10);
        }
      }
    } catch (e) { /* ignore */ }

    // ── Title — from .p24_title ("8 Bedroom House") ───────────────
    try {
      const titleEl = card.querySelector('.p24_title');
      if (titleEl) {
        const titleText = titleEl.textContent.trim();
        // Extract bedrooms
        const bedMatch = titleText.match(/^(\d+)\s+Bedroom/i);
        if (bedMatch) listing.bedrooms = parseInt(bedMatch[1], 10);
        // Extract property type
        const titleLower = titleText.toLowerCase();
        for (const pt of PROPERTY_TYPES) {
          if (titleLower.includes(pt)) {
            listing.property_type = pt.charAt(0).toUpperCase() + pt.slice(1);
            break;
          }
        }
      }
    } catch (e) { /* ignore */ }

    // ── Suburb — from .p24_location ───────────────────────────────
    try {
      const locEl = card.querySelector('.p24_location');
      if (locEl) listing.suburb = locEl.textContent.trim();
    } catch (e) { /* ignore */ }

    // ── Address — from .p24_address (optional, not all have it) ───
    try {
      const addrEl = card.querySelector('.p24_address');
      if (addrEl) {
        const addr = addrEl.textContent.trim();
        if (addr) listing.address = addr;
      }
    } catch (e) { /* ignore */ }
    if (!listing.address) listing.address = 'Address not available';

    // Suburb fallback from URL
    if (!listing.suburb && listing.portal_url) {
      try {
        const urlPath = new URL(listing.portal_url).pathname;
        const segments = urlPath.split('/').filter(Boolean);
        if (segments.length >= 2 && !/^\d+$/.test(segments[1])) {
          listing.suburb = segments[1].replace(/-/g, ' ')
            .replace(/\b\w/g, c => c.toUpperCase());
        }
      } catch (e) { /* ignore */ }
    }

    // ── Features — from .p24_featureDetails with title attributes ──
    try {
      const bedEl = card.querySelector('.p24_featureDetails[title="Bedrooms"] span');
      if (bedEl) {
        const val = parseInt(bedEl.textContent.trim(), 10);
        if (!isNaN(val)) listing.bedrooms = val;
      }

      const bathEl = card.querySelector('.p24_featureDetails[title="Bathrooms"] span');
      if (bathEl) {
        const val = parseInt(bathEl.textContent.trim(), 10);
        if (!isNaN(val)) listing.bathrooms = val;
      }

      const parkEl = card.querySelector('.p24_featureDetails[title="Parking Spaces"] span');
      if (parkEl) {
        const val = parseInt(parkEl.textContent.trim(), 10);
        if (!isNaN(val)) listing.garages = val;
      }
    } catch (e) { /* ignore */ }

    // ── Size — from .p24_size span ────────────────────────────────
    try {
      const sizeEl = card.querySelector('.p24_size span');
      if (sizeEl) {
        const sizeText = sizeEl.textContent.trim();
        const m = sizeText.match(/([\d\s,.]+)\s*m[²2]/i);
        if (m) {
          listing.erf_size_m2 = parseFloat(m[1].replace(/[\s,]/g, ''));
        }
      }
    } catch (e) { /* ignore */ }

    // ── Agency — from .p24_branding img alt ───────────────────────
    try {
      const agencyImg = card.querySelector('.p24_branding img[alt]');
      if (agencyImg) {
        const alt = agencyImg.getAttribute('alt').trim();
        if (alt) listing.agency_name = alt;
      }
    } catch (e) { /* ignore */ }

    // ── Thumbnail — from listing image ───────────────────────────
    try {
      const thumb = card.querySelector('img.js_P24_listingImage');
      if (thumb) {
        listing.thumbnail_url = thumb.getAttribute('src') ||
          thumb.getAttribute('data-original') ||
          thumb.getAttribute('lazy-src') || null;
      }
      if (!listing.thumbnail_url) {
        const imgEl = card.querySelector('.p24_image img[src]');
        if (imgEl) listing.thumbnail_url = imgEl.getAttribute('src');
      }
    } catch (e) { /* ignore */ }

    return listing;
  }

  // ── Extract all listings from current page ─────────────────
  function extractAllListings() {
    const cards = document.querySelectorAll('.p24_regularTile');
    const listings = [];

    cards.forEach(card => {
      try {
        const listing = extractListing(card);
        if (listing.portal_ref || listing.portal_url) {
          listings.push(listing);
        }
      } catch (e) { /* skip broken card */ }
    });

    return listings;
  }

  // ── Message handler ────────────────────────────────────────
  chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
    if (msg.action === 'getPageInfo') {
      sendResponse(getSearchContext());
      return true;
    }

    if (msg.action === 'getListings') {
      sendResponse({ listings: extractAllListings() });
      return true;
    }

    return false;
  });
})();
