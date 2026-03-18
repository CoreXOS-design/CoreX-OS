/**
 * CoreX Portal Capture — Private Property Content Script
 *
 * Injected on privateproperty.co.za pages. Extracts search context
 * and per-listing data from PP search result pages.
 *
 * KEY INSIGHT: PP uses hashed React classes that change on every build,
 * so we cannot rely on CSS class selectors for regular cards. Instead,
 * we extract addresses from listing URLs which contain the address as
 * path segments:
 *   /for-sale/.../suburb/address-part/TXXXXXXX
 *   /for-sale/.../suburb/address-part1/address-part2/TXXXXXXX
 *
 * Featured cards have clean classes (featured-listing__*) so we use
 * those selectors where available.
 */

(function () {
  'use strict';

  // ── Province / region keywords that are NOT address segments ──
  const AREA_KEYWORDS = [
    'south-africa', 'kwazulu-natal', 'kzn-south-coast',
    'western-cape', 'gauteng', 'eastern-cape', 'free-state', 'limpopo',
    'mpumalanga', 'north-west', 'northern-cape', 'for-sale', 'to-rent',
  ];

  // ── Search page detection ──────────────────────────────────
  function isSearchResultsPage() {
    // PP search pages have listing links ending in /TXXXXXXX
    const listingLinks = document.querySelectorAll('a[href*="/for-sale/"], a[href*="/to-rent/"]');
    let hasListings = false;
    listingLinks.forEach(link => {
      const href = link.getAttribute('href') || '';
      if (/\/T\d{5,}$/.test(href) || /\/T\d{5,}\/?$/.test(href)) {
        hasListings = true;
      }
    });

    // Detail pages have gallery carousels
    const isDetailPage = !!(
      document.querySelector('[class*="galleryCarousel"]') ||
      document.querySelector('[class*="listing-detail"]') ||
      document.querySelector('.property-detail-page')
    );

    return hasListings && !isDetailPage;
  }

  // ── Extract search context ─────────────────────────────────
  function getSearchContext() {
    let searchTerm   = null;
    let totalResults = null;
    let totalPages   = null;

    // Search term from h1
    try {
      const h1 = document.querySelector('h1');
      if (h1) searchTerm = h1.textContent.trim();
    } catch (e) { /* ignore */ }

    if (!searchTerm) {
      try {
        searchTerm = (document.title || '').split('|')[0].split('-')[0].trim();
      } catch (e) { /* ignore */ }
    }

    // Total results: "1-20 of 776 results"
    try {
      const bodyText = document.body.innerText;
      const totalMatch = bodyText.match(/of\s+([\d,]+)\s+results/i);
      if (totalMatch) {
        totalResults = parseInt(totalMatch[1].replace(/,/g, ''), 10);
        totalPages = Math.ceil(totalResults / 20);
      }
    } catch (e) { /* ignore */ }

    // Fallback: count visible listing links
    if (!totalResults) {
      try {
        const seen = new Set();
        document.querySelectorAll('a[href*="/for-sale/"], a[href*="/to-rent/"]').forEach(link => {
          const href = link.getAttribute('href') || '';
          const m = href.match(/\/(T\d{5,})\/?$/);
          if (m) seen.add(m[1]);
        });
        if (seen.size > 0) totalResults = seen.size;
      } catch (e) { /* ignore */ }
    }

    // Total pages from pagination links
    try {
      const pageLinks = document.querySelectorAll('a[href*="page="]');
      let maxPage = 1;
      pageLinks.forEach(link => {
        const m = (link.getAttribute('href') || '').match(/page=(\d+)/);
        if (m) {
          const p = parseInt(m[1], 10);
          if (p > maxPage) maxPage = p;
        }
      });
      if (maxPage > (totalPages || 1)) totalPages = maxPage;
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

  // ── Title-case a hyphenated path segment ───────────────────
  function titleCase(segment) {
    return segment
      .replace(/-/g, ' ')
      .replace(/\b\w/g, c => c.toUpperCase());
  }

  // ── Extract address + suburb from a PP listing URL ─────────
  // URL pattern: /for-sale/province/region/city/suburb/addr1/addr2/TXXXXXXX
  // Address segments are between the suburb and the TXXXXXXX ref.
  // Address segments typically contain numbers (street numbers).
  function extractAddressFromUrl(href) {
    const pathSegments = href.split('/').filter(Boolean);
    const refIdx = pathSegments.findIndex(s => /^T\d{5,}$/.test(s));

    if (refIdx <= 0) return { address: null, suburb: null };

    const addrParts = [];
    let suburb = null;

    // Walk backwards from ref to find address segments
    for (let i = refIdx - 1; i >= 0; i--) {
      const seg = pathSegments[i];
      if (AREA_KEYWORDS.includes(seg)) break;

      // Address segments typically contain numbers, or prefixes like ss-, bc-
      if (/\d/.test(seg) || seg.includes('ss-') || seg.includes('bc-')) {
        addrParts.unshift(seg);
      } else {
        // This is likely the suburb
        suburb = titleCase(seg);
        break;
      }
    }

    if (addrParts.length === 0) return { address: null, suburb: suburb };

    const address = addrParts.map(p => titleCase(p)).join(', ');
    return { address, suburb };
  }

  // ── Extract all listings from current page ─────────────────
  function extractAllListings() {
    const listings = [];
    const seen = new Set();

    // Find ALL listing links on the page
    const allLinks = document.querySelectorAll('a[href*="/for-sale/"], a[href*="/to-rent/"]');

    allLinks.forEach(link => {
      const href = link.getAttribute('href') || '';
      // Match PP listing URLs ending in /TXXXXXXX
      const refMatch = href.match(/\/(T\d{5,})\/?$/);
      if (!refMatch) return;

      const ref = refMatch[1];
      if (seen.has(ref)) return;
      seen.add(ref);

      // The <a> tag IS the card — it wraps all listing content (price, type, features, images)
      let card = link;

      // Extract address from URL
      const { address, suburb } = extractAddressFromUrl(href);

      // Skip listings without a real address
      if (!address) return;

      // Extract price from card text
      let price = null;
      try {
        // Try featured-listing price element first
        const priceEl = card.querySelector('.featured-listing__price, [class*="price"], [class*="Price"]');
        if (priceEl) {
          const cleaned = priceEl.textContent.replace(/[^\d]/g, '');
          if (cleaned && cleaned.length >= 4) price = parseInt(cleaned, 10);
        }
        if (!price) {
          const priceMatch = card.textContent.match(/R\s*([\d\s]+)/);
          if (priceMatch) {
            price = parseInt(priceMatch[1].replace(/\s/g, ''), 10);
          }
        }
      } catch (e) { /* ignore */ }

      // Extract property type and bedrooms from card text
      let propertyType = null;
      let bedrooms = null;
      try {
        const typeMatch = card.textContent.match(/(\d+)\s+Bedroom\s+(House|Apartment|Townhouse|Flat|Duplex|Simplex)/i);
        if (typeMatch) {
          bedrooms = parseInt(typeMatch[1], 10);
          propertyType = typeMatch[2];
        } else {
          const landMatch = card.textContent.match(/([\d\s]+)\s*m[²2]\s+Land/i);
          if (landMatch) propertyType = 'Land';
        }
      } catch (e) { /* ignore */ }

      // Extract features from featured-listing cards
      let bathrooms = null;
      let garages = null;
      let erfSize = null;

      try {
        const bedEl = card.querySelector('[title="Bedrooms"]');
        if (bedEl) {
          const v = parseInt(bedEl.textContent.trim(), 10);
          if (!isNaN(v)) bedrooms = v;
        }
        const bathEl = card.querySelector('[title="Bathrooms"]');
        if (bathEl) {
          const v = parseInt(bathEl.textContent.trim(), 10);
          if (!isNaN(v)) bathrooms = v;
        }
        const parkEl = card.querySelector('[title="Parking spaces"]');
        if (parkEl) {
          const v = parseInt(parkEl.textContent.trim(), 10);
          if (!isNaN(v)) garages = v;
        }
        const sizeEl = card.querySelector('[title="Land size"], [title="Floor size"]');
        if (sizeEl) {
          const m = sizeEl.textContent.match(/([\d\s,.]+)\s*m/);
          if (m) erfSize = parseFloat(m[1].replace(/[\s,]/g, ''));
        }
      } catch (e) { /* ignore */ }

      // Agency from image alt
      let agencyName = null;
      try {
        const agencyImgs = card.querySelectorAll('img[alt]');
        agencyImgs.forEach(img => {
          const alt = img.getAttribute('alt') || '';
          // Skip listing images (they repeat the type)
          if (alt && !alt.includes('Bedroom') && !alt.includes('Land') &&
              alt.length > 3 && alt.length < 60) {
            agencyName = alt;
          }
        });
      } catch (e) { /* ignore */ }

      // Thumbnail
      let thumbnail = null;
      try {
        const thumbImg = card.querySelector('img[src*="images.prop24"], img[src*="images.pp.co.za"], img[src*="privateproperty"]');
        if (thumbImg) thumbnail = thumbImg.getAttribute('src') || null;
        if (!thumbnail) {
          const anyImg = card.querySelector('img[src]');
          if (anyImg) thumbnail = anyImg.getAttribute('src') || null;
        }
      } catch (e) { /* ignore */ }

      listings.push({
        portal_ref:       'PP-' + ref,
        portal_url:       href.startsWith('http') ? href : 'https://www.privateproperty.co.za' + href,
        address:          address,
        suburb:           suburb,
        price:            price,
        bedrooms:         bedrooms,
        bathrooms:        bathrooms,
        garages:          garages,
        property_size_m2: null,
        erf_size_m2:      erfSize,
        property_type:    propertyType,
        agent_name:       null,
        agency_name:      agencyName,
        thumbnail_url:    thumbnail,
        source:           'pp',
      });
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
