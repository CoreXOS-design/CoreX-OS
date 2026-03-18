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

  // ── Helper: get only direct/own text of an element (no child text) ──
  function ownText(el) {
    let text = '';
    for (const node of el.childNodes) {
      if (node.nodeType === Node.TEXT_NODE) {
        text += node.textContent;
      }
    }
    return text.trim();
  }

  // ── Helper: find first element whose own text matches a regex ──────
  function findByOwnText(root, regex) {
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
    let node = walker.currentNode;
    while (node) {
      const t = ownText(node);
      if (t && regex.test(t)) return node;
      node = walker.nextNode();
    }
    return null;
  }

  // ── Helper: find all elements whose own text matches a regex ───────
  function findAllByOwnText(root, regex) {
    const results = [];
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
    let node = walker.currentNode;
    while (node) {
      const t = ownText(node);
      if (t && regex.test(t)) results.push(node);
      node = walker.nextNode();
    }
    return results;
  }

  // ── P24 badge text filter ──────────────────────────────────────────
  // These overlay texts appear on listing cards and must NOT be used as addresses.
  const BADGE_TEXTS = [
    'reduced', 'no transfer duty', 'new', 'sole mandate', 'auction',
    'hot', 'under offer', 'sold', 'pending', 'price reduced',
    'exclusive', 'must sell', 'repossessed', 'bank sale',
    'new release', 'show day', 'virtual tour', 'new development',
    'new listing', 'featured',
  ];

  /**
   * Returns true if text looks like a badge/label rather than a listing title.
   * Checks: known badge words, too short, ALL CAPS under 30 chars, pure number.
   */
  function isBadgeText(text) {
    if (!text) return true;
    const trimmed = text.trim();
    if (trimmed.length < 10) return true;
    if (/^\d+$/.test(trimmed)) return true;
    // ALL CAPS and under 30 chars → badge pattern (e.g. "NO TRANSFER DUTY")
    if (trimmed === trimmed.toUpperCase() && trimmed.length < 30) return true;
    // Check against known badge words (exact match, case-insensitive)
    if (BADGE_TEXTS.includes(trimmed.toLowerCase())) return true;
    return false;
  }

  // ── P24 known property types ───────────────────────────────────────
  const PROPERTY_TYPES = [
    'house', 'apartment', 'townhouse', 'flat', 'duplex', 'simplex',
    'cluster', 'cottage', 'farm', 'smallholding', 'vacant land',
    'land', 'commercial', 'industrial', 'office', 'retail',
    'penthouse', 'studio', 'garden cottage', 'granny flat',
  ];

  // ── Extract data from a single listing tile ────────────────────────
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

    // ── Portal URL — the primary listing link ────────────────────────
    // P24 URL pattern: /for-sale/[desc]-in-[suburb]/[city]/[province]/[areaId]/[listingId]
    let listingLink = null;
    try {
      listingLink = tile.querySelector('a[href*="/for-sale/"], a[href*="/to-rent/"]');
      if (!listingLink) listingLink = tile.querySelector('a[href]');
      if (listingLink) {
        listing.portal_url = listingLink.href;
      }
    } catch (e) { /* ignore */ }

    // ── Portal ref — ALWAYS extract from URL (most reliable) ─────────
    try {
      // Try data attributes first
      listing.portal_ref = tile.getAttribute('data-listing-id') ||
                           tile.getAttribute('data-listingid') ||
                           tile.dataset?.listingId || null;

      // Fallback: extract the last numeric segment from the listing URL
      // URL pattern: /for-sale/house-in-uvongo/kwazulu-natal/607/116950342
      if (!listing.portal_ref && listing.portal_url) {
        try {
          const urlPath = new URL(listing.portal_url).pathname;
          // Get the last segment that is purely numeric (6+ digits = listing ID)
          const segments = urlPath.split('/').filter(Boolean);
          for (let i = segments.length - 1; i >= 0; i--) {
            if (/^\d{6,}$/.test(segments[i])) {
              listing.portal_ref = segments[i];
              break;
            }
          }
        } catch (e) { /* ignore */ }
      }

      // Fallback: any large number in any href on the tile
      if (!listing.portal_ref) {
        const links = tile.querySelectorAll('a[href]');
        for (const link of links) {
          const m = link.href.match(/\/(\d{6,})(?:[/?#]|$)/);
          if (m) { listing.portal_ref = m[1]; break; }
        }
      }

      if (listing.portal_ref) {
        listing.portal_ref = 'P24-' + listing.portal_ref.replace(/^P24-/, '');
      }
    } catch (e) { /* ignore */ }

    // ── Address — from the main listing link text ────────────────────
    // P24 listing titles follow: "[N] Bedroom [Type] (for Sale) in [Suburb]"
    // CRITICAL: Filter out badge overlays like "Reduced", "NO TRANSFER DUTY", etc.
    try {
      // Strategy 1: find ALL <a> links pointing to listing detail pages
      // and pick the first one whose text is a real title (not a badge)
      const detailLinks = tile.querySelectorAll('a[href*="/for-sale/"], a[href*="/to-rent/"]');
      for (const link of detailLinks) {
        const linkText = link.textContent.trim();
        if (!isBadgeText(linkText)) {
          listing.address = linkText;
          break;
        }
      }

      // Strategy 2: h2/h3 heading inside the tile (filter badges)
      if (!listing.address) {
        const headings = tile.querySelectorAll('h2, h3, h4');
        for (const heading of headings) {
          const headText = heading.textContent.trim();
          if (!isBadgeText(headText)) {
            listing.address = headText;
            break;
          }
        }
      }

      // Strategy 3: look for elements with "address" or "title" in class
      if (!listing.address) {
        const addrEl = tile.querySelector('.p24_address, [class*="address"], .p24_title');
        if (addrEl) {
          const addrText = addrEl.textContent.trim();
          if (!isBadgeText(addrText)) listing.address = addrText;
        }
      }

      // Strategy 4: construct from URL path
      // /for-sale/house-in-uvongo/kwazulu-natal/607/116950342
      if (!listing.address && listing.portal_url) {
        try {
          const urlPath = new URL(listing.portal_url).pathname;
          const segments = urlPath.split('/').filter(Boolean);
          if (segments.length >= 2) {
            const desc = segments[1].replace(/-/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
            if (!isBadgeText(desc)) listing.address = desc;
          }
        } catch (e) { /* ignore */ }
      }

      // Strategy 5: build a descriptive title from bedrooms + type + suburb
      if (!listing.address && listing.portal_url) {
        try {
          const urlPath = new URL(listing.portal_url).pathname.toLowerCase();
          let beds = null, type = null, suburb = null;
          const bedMatch = urlPath.match(/(\d+)-bedroom/);
          if (bedMatch) beds = bedMatch[1];
          for (const t of PROPERTY_TYPES) {
            if (urlPath.includes(t.replace(/\s+/g, '-'))) { type = t.charAt(0).toUpperCase() + t.slice(1); break; }
          }
          const inMatch = urlPath.match(/in-([a-z-]+)/);
          if (inMatch) suburb = inMatch[1].replace(/-/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
          if (beds && type && suburb) listing.address = beds + ' Bedroom ' + type + ' in ' + suburb;
          else if (type && suburb) listing.address = type + ' in ' + suburb;
          else if (suburb) listing.address = suburb;
        } catch (e) { /* ignore */ }
      }

      if (!listing.address) listing.address = 'Address not available';
    } catch (e) {
      if (!listing.address) listing.address = 'Address not available';
    }

    // ── Suburb — from URL path or address text ───────────────────────
    try {
      // Best source: URL path contains suburb info
      // /for-sale/house-in-uvongo/... or /for-sale/uvongo/...
      if (listing.portal_url) {
        try {
          const urlPath = new URL(listing.portal_url).pathname;
          const segments = urlPath.split('/').filter(Boolean);
          if (segments.length >= 2) {
            const seg = segments[1]; // e.g. "house-in-uvongo" or "uvongo"
            const inMatch = seg.match(/in-(.+)$/);
            if (inMatch) {
              listing.suburb = inMatch[1].replace(/-/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
            } else if (!/^\d+$/.test(seg)) {
              listing.suburb = seg.replace(/-/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
            }
          }
        } catch (e) { /* ignore */ }
      }

      // Fallback: from the address text ("... in Uvongo" or "..., Uvongo")
      if (!listing.suburb && listing.address) {
        const inMatch = listing.address.match(/\bin\s+([A-Z][a-zA-Z\s]+?)(?:\s*$|\s*,)/);
        if (inMatch) {
          listing.suburb = inMatch[1].trim();
        } else {
          const parts = listing.address.split(',').map(s => s.trim());
          if (parts.length > 1) listing.suburb = parts[parts.length - 1];
        }
      }

      // Complex/estate name extraction for sectional title listings
      // "2 Bedroom Apartment in Sea Breeze Estate" → address = "Sea Breeze Estate, Uvongo"
      // Pattern: title contains "[Type] in [Complex Name]" where complex has estate/village/etc.
      if (listing.address && listing.suburb) {
        const complexMatch = listing.address.match(
          /\bin\s+(.+?\b(?:estate|village|complex|lodge|manor|park|place|gardens|court|villas|heights|towers|ridge|mews|close)\b[^,]*)/i
        );
        if (complexMatch) {
          const complexName = complexMatch[1].trim();
          // Only use if the complex name is different from just the suburb
          if (complexName.toLowerCase() !== listing.suburb.toLowerCase()) {
            listing.address = complexName + ', ' + listing.suburb;
          }
        }
      }

      // Fallback: search page URL (the area being browsed)
      if (!listing.suburb) {
        try {
          const pageUrl = window.location.pathname;
          const segs = pageUrl.split('/').filter(Boolean);
          // /for-sale/uvongo/kwazulu-natal/607
          if (segs.length >= 2 && !/^\d+$/.test(segs[1])) {
            listing.suburb = segs[1].replace(/-/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
          }
        } catch (e) { /* ignore */ }
      }
    } catch (e) { /* ignore */ }

    // ── Price — find by "R" currency pattern in text ─────────────────
    // CRITICAL: Do NOT use textContent of a container (bleeds into child elements).
    // Instead, find the deepest element whose OWN text matches "R X,XXX,XXX".
    try {
      // Strategy 1: find deepest element with own text matching R + digits
      const priceEl = findByOwnText(tile, /^R\s*[\d\s,]+/);
      if (priceEl) {
        const priceText = ownText(priceEl);
        const match = priceText.match(/R\s*([\d\s,]+)/);
        if (match) {
          const cleaned = match[1].replace(/[\s,]/g, '');
          if (cleaned && cleaned.length >= 4) { // at least R1,000
            listing.price = parseInt(cleaned, 10);
          }
        }
      }

      // Strategy 2: fallback — class-based selector with careful extraction
      if (!listing.price) {
        const pEls = tile.querySelectorAll('.p24_price, [class*="price"], [class*="Price"]');
        for (const pEl of pEls) {
          // Use ownText to avoid pulling in child element text (features etc.)
          let pText = ownText(pEl);
          if (!pText) pText = pEl.textContent.trim();
          const match = pText.match(/R\s*([\d\s,]+)/);
          if (match) {
            const cleaned = match[1].replace(/[\s,]/g, '');
            if (cleaned && cleaned.length >= 4) {
              listing.price = parseInt(cleaned, 10);
              break;
            }
          }
        }
      }

      // Strategy 3: broader scan — any text node containing R+digits
      if (!listing.price) {
        const allEls = findAllByOwnText(tile, /R\s*[\d\s,]{4,}/);
        for (const el of allEls) {
          const match = ownText(el).match(/R\s*([\d\s,]+)/);
          if (match) {
            const cleaned = match[1].replace(/[\s,]/g, '');
            const num = parseInt(cleaned, 10);
            if (num >= 10000) { // realistic property price
              listing.price = num;
              break;
            }
          }
        }
      }
    } catch (e) { /* ignore */ }

    // ── Features: bedrooms, bathrooms, garages ───────────────────────
    // P24 React components render features as icon+number pairs.
    // Since class names are hashed, we detect by:
    //  1. Title/aria-label attributes containing "bed", "bath", "garage"
    //  2. SVG icon usage hints (path shapes, viewBox, data-icon)
    //  3. Positional order: P24 always shows Beds → Baths → Garages → Size
    //  4. Finding small standalone numbers (1-20) near SVG/icon elements
    try {
      // Strategy 1: title/aria-label attributes (most reliable when present)
      const titledEls = tile.querySelectorAll('[title], [aria-label]');
      titledEls.forEach(el => {
        const hint = ((el.getAttribute('title') || '') + ' ' + (el.getAttribute('aria-label') || '')).toLowerCase();
        const numText = el.textContent.trim();
        const num = parseInt(numText, 10);
        // Also check if the element or its parent contains just a small number
        const parentNum = el.parentElement ? parseInt(el.parentElement.textContent.trim(), 10) : NaN;
        const val = !isNaN(num) && num <= 50 ? num : (!isNaN(parentNum) && parentNum <= 50 ? parentNum : NaN);

        if (isNaN(val)) return;
        if (hint.includes('bed'))     listing.bedrooms  = val;
        if (hint.includes('bath'))    listing.bathrooms  = val;
        if (hint.includes('garage') || hint.includes('parking')) listing.garages = val;
      });

      // Strategy 2: find feature containers with SVG icons + adjacent numbers
      // Look for small groups: [SVG/icon] [number] repeated
      if (listing.bedrooms === null && listing.bathrooms === null) {
        const svgs = tile.querySelectorAll('svg');
        const featureNumbers = [];

        svgs.forEach(svg => {
          // Look for a sibling or parent-sibling that contains a small number
          const parent = svg.parentElement;
          if (!parent) return;

          // Check the parent's text for a number (the icon label)
          const parentText = ownText(parent).trim();
          const num = parseInt(parentText, 10);
          if (!isNaN(num) && num >= 0 && num <= 50) {
            featureNumbers.push({ el: parent, num: num, svg: svg });
            return;
          }

          // Check next sibling
          const next = parent.nextElementSibling || svg.nextElementSibling;
          if (next) {
            const nextNum = parseInt(next.textContent.trim(), 10);
            if (!isNaN(nextNum) && nextNum >= 0 && nextNum <= 50) {
              featureNumbers.push({ el: next, num: nextNum, svg: svg });
            }
          }
        });

        // P24 feature order: Beds, Baths, Garages (or Beds, Baths, Parking, Erf Size)
        if (featureNumbers.length >= 1 && listing.bedrooms  === null) listing.bedrooms  = featureNumbers[0].num;
        if (featureNumbers.length >= 2 && listing.bathrooms === null) listing.bathrooms = featureNumbers[1].num;
        if (featureNumbers.length >= 3 && listing.garages   === null) listing.garages   = featureNumbers[2].num;
      }

      // Strategy 3: extract from listing title text
      // "4 Bedroom House for Sale" → bedrooms=4
      if (listing.bedrooms === null && listing.address) {
        const bedMatch = listing.address.match(/(\d+)\s*bed/i);
        if (bedMatch) listing.bedrooms = parseInt(bedMatch[1], 10);
      }
    } catch (e) { /* ignore */ }

    // ── Property size / Erf size ─────────────────────────────────────
    try {
      // Find any text containing "m²" or "m2" pattern
      const sizeEls = findAllByOwnText(tile, /[\d,.]+\s*m[²2]/i);
      sizeEls.forEach(el => {
        const text = (ownText(el) + ' ' + (el.getAttribute('title') || '')).toLowerCase();
        const numMatch = text.match(/([\d,.]+)\s*m[²2]/i);
        if (numMatch) {
          const val = parseFloat(numMatch[1].replace(/,/g, ''));
          if (text.includes('erf') || text.includes('land') || text.includes('stand')) {
            listing.erf_size_m2 = val;
          } else if (text.includes('floor') || text.includes('size') || val < 1000) {
            listing.property_size_m2 = val;
          } else {
            listing.erf_size_m2 = val;
          }
        }
      });

      // Also check title/aria-label elements
      const titledSize = tile.querySelectorAll('[title*="m²"], [title*="m2"], [aria-label*="m²"], [aria-label*="size"]');
      titledSize.forEach(el => {
        const hint = (el.getAttribute('title') || el.getAttribute('aria-label') || '').toLowerCase();
        const numMatch = hint.match(/([\d,.]+)\s*m[²2]/i);
        if (numMatch) {
          const val = parseFloat(numMatch[1].replace(/,/g, ''));
          if (hint.includes('erf') || hint.includes('land')) {
            listing.erf_size_m2 = val;
          } else {
            listing.property_size_m2 = val;
          }
        }
      });
    } catch (e) { /* ignore */ }

    // ── Property type — extract from title text or badge ─────────────
    try {
      // Strategy 1: extract from listing title/address text
      // "4 Bedroom House for Sale in Uvongo" → "House"
      if (listing.address) {
        const titleLower = listing.address.toLowerCase();
        for (const type of PROPERTY_TYPES) {
          if (titleLower.includes(type)) {
            listing.property_type = type.charAt(0).toUpperCase() + type.slice(1);
            break;
          }
        }
      }

      // Strategy 2: extract from URL path
      // /for-sale/house-in-uvongo/ → "House"
      if (!listing.property_type && listing.portal_url) {
        try {
          const urlPath = new URL(listing.portal_url).pathname.toLowerCase();
          for (const type of PROPERTY_TYPES) {
            const slug = type.replace(/\s+/g, '-');
            if (urlPath.includes(slug + '-in-') || urlPath.includes(slug + '-for-') || urlPath.includes('/' + slug + '/')) {
              listing.property_type = type.charAt(0).toUpperCase() + type.slice(1);
              break;
            }
          }
        } catch (e) { /* ignore */ }
      }

      // Strategy 3: class-based badge selectors
      if (!listing.property_type) {
        const typeEl = tile.querySelector('.p24_propertyType, [class*="property-type"], [class*="propertyType"]');
        if (typeEl) listing.property_type = typeEl.textContent.trim();
      }

      // Strategy 4: walk all text nodes looking for a standalone type word
      if (!listing.property_type) {
        for (const type of PROPERTY_TYPES) {
          const regex = new RegExp('\\b' + type + '\\b', 'i');
          const found = findByOwnText(tile, regex);
          if (found) {
            // Make sure it's not inside a long sentence (avoid false matches)
            const txt = ownText(found).trim();
            if (txt.length < 30) {
              listing.property_type = type.charAt(0).toUpperCase() + type.slice(1);
              break;
            }
          }
        }
      }
    } catch (e) { /* ignore */ }

    // ── Agent name ───────────────────────────────────────────────────
    try {
      // Strategy 1: class-based selectors
      const agentEl = tile.querySelector(
        '.p24_agentName, [class*="agent-name"], [class*="agentName"], [class*="consultant"]'
      );
      if (agentEl) {
        const name = agentEl.textContent.trim();
        if (name.length <= 100) listing.agent_name = name;
      }

      // Strategy 2: find an img with alt text like "Agent: John Smith"
      if (!listing.agent_name) {
        const agentImgs = tile.querySelectorAll('img[alt]');
        for (const img of agentImgs) {
          const alt = img.alt.trim();
          // Agent photos often have alt="Agent Name" or "Photo of Agent Name"
          if (alt && !alt.toLowerCase().includes('property') && !alt.toLowerCase().includes('logo')
              && !alt.toLowerCase().includes('badge') && alt.length > 2 && alt.length < 50) {
            // Check if this is near agent-related content (not the property photo)
            const parent = img.closest('div, section, footer, aside');
            if (parent) {
              const parentText = parent.textContent.toLowerCase();
              if (parentText.includes('agent') || parentText.includes('consultant') ||
                  parentText.includes('contact') || parentText.includes('sold by')) {
                listing.agent_name = alt;
                break;
              }
            }
          }
        }
      }
    } catch (e) { /* ignore */ }

    // ── Agency name ──────────────────────────────────────────────────
    try {
      // Strategy 1: class-based selectors
      const agencyEl = tile.querySelector(
        '.p24_branchName, [class*="agency"], [class*="branch"], [class*="logo-name"]'
      );
      if (agencyEl) {
        const name = agencyEl.textContent.trim();
        if (name.length <= 100) listing.agency_name = name;
      }

      // Strategy 2: agency logo img alt text
      if (!listing.agency_name) {
        const imgs = tile.querySelectorAll('img[alt]');
        for (const img of imgs) {
          const alt = img.alt.trim();
          const src = (img.src || '').toLowerCase();
          if (alt && (src.includes('logo') || src.includes('brand') || src.includes('agency') ||
                      alt.toLowerCase().includes('estate') || alt.toLowerCase().includes('propert') ||
                      alt.toLowerCase().includes('realty') || alt.toLowerCase().includes('remax') ||
                      alt.toLowerCase().includes('seeff') || alt.toLowerCase().includes('pam golding') ||
                      alt.toLowerCase().includes('rawson'))) {
            listing.agency_name = alt;
            break;
          }
        }
      }

      // Strategy 3: look for "Marketed by" or "Listed by" text patterns
      if (!listing.agency_name) {
        const marketedEl = findByOwnText(tile, /(?:marketed|listed|sold)\s+by/i);
        if (marketedEl) {
          const fullText = marketedEl.textContent.trim();
          const match = fullText.match(/(?:marketed|listed|sold)\s+by\s+(.+)/i);
          if (match) listing.agency_name = match[1].trim();
        }
      }
    } catch (e) { /* ignore */ }

    // ── Thumbnail ────────────────────────────────────────────────────
    try {
      // Get the first meaningful image (not icons/logos)
      const imgs = tile.querySelectorAll('img');
      for (const img of imgs) {
        const src = img.src || img.dataset?.src || img.getAttribute('data-original') || '';
        if (!src) continue;
        // Skip tiny images (likely icons), logos, agent photos
        const w = img.naturalWidth || img.width || 0;
        const h = img.naturalHeight || img.height || 0;
        if (w > 0 && w < 32) continue;

        // Property images usually come from the P24 CDN or contain "listing"/"property"
        if (src.includes('listing') || src.includes('property') || src.includes('images.prop24') ||
            src.includes('img-') || src.includes('/resize/') ||
            (w >= 100 || w === 0)) { // w=0 means not loaded yet, likely lazy-loaded main image
          listing.thumbnail_url = src;
          break;
        }
      }

      // Fallback: just take the first img
      if (!listing.thumbnail_url) {
        const firstImg = tile.querySelector('img[src]');
        if (firstImg) listing.thumbnail_url = firstImg.src;
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
