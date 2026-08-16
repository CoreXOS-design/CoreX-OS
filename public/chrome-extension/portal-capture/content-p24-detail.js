/**
 * CoreX — Property24 Content Script (unified)
 *
 * Single content script for ALL P24 pages. Handles:
 *   - Page type detection (search vs listing detail)
 *   - Search context + listing extraction (for Capture Listings flow)
 *   - Full property detail extraction (for Pull Property flow)
 *
 * Extraction priority for detail pages:
 *   1. JSON-LD structured data (most reliable)
 *   2. DOM selectors (.p24_listingDetail, .p24_propertyOverview, etc.)
 *   3. window.listingLeadFormContext for listing number & agency
 */

(function () {
  'use strict';

  // Robust price read from one-or-more .p24_price elements. Prefers the numeric
  // `content` attribute; parses text as DISTINCT number groups (never concatenates
  // digits across two numbers); keeps only plausible SA asking prices; returns the
  // headline (max) figure. Mirrors extractP24Price in content-p24.js — fixes the
  // dropped-zero / wrong-figure / concatenation misparses.
  function p24PriceFrom(scope) {
    const PLAUSIBLE = (v) => Number.isFinite(v) && v >= 100000 && v <= 500000000;
    const els = Array.from((scope || document).querySelectorAll('.p24_price'));
    if (els.length === 0) return null;
    const byContent = [];
    els.forEach((el) => {
      const ca = (el.getAttribute('content') || '').trim();
      if (/^\d{4,12}$/.test(ca)) { const v = parseInt(ca, 10); if (PLAUSIBLE(v)) byContent.push(v); }
    });
    if (byContent.length > 0) return Math.max(...byContent);
    const groups = [];
    els.forEach((el) => {
      const matches = (el.textContent || '').match(/\d[\d\s .,]*\d|\d/g) || [];
      matches.forEach((g) => {
        const d = g.replace(/[^\d]/g, '');
        if (d.length >= 4 && d.length <= 12) { const v = parseInt(d, 10); if (PLAUSIBLE(v)) groups.push(v); }
      });
    });
    return groups.length > 0 ? Math.max(...groups) : null;
  }

  // ══════════════════════════════════════════════════════════
  // ── SHARED CONSTANTS ──────────────────────────────────────
  // ══════════════════════════════════════════════════════════

  const PROPERTY_TYPES = [
    'house', 'apartment', 'townhouse', 'flat', 'duplex', 'simplex',
    'cluster', 'cottage', 'farm', 'smallholding', 'vacant land',
    'land', 'commercial', 'industrial', 'office', 'retail',
    'penthouse', 'studio', 'garden cottage', 'granny flat',
  ];

  // ══════════════════════════════════════════════════════════
  // ── PAGE TYPE DETECTION ───────────────────────────────────
  // ══════════════════════════════════════════════════════════

  function isSearchResultsPage() {
    const hasResultsGrid = !!document.querySelector('[data-listing-number]');
    const tileCount = document.querySelectorAll('[data-listing-number]').length;
    const isDetail = !!(
      document.querySelector('.p24_listingDetail') ||
      document.querySelector('.js_galleryImage')
    );
    return hasResultsGrid && tileCount > 2 && !isDetail;
  }

  function isListingDetailPage() {
    const hasGallery   = !!document.querySelector('.js_galleryImage, .p24_gallery img, [data-gallery-image]');
    const hasDetail    = !!document.querySelector('.p24_listingDetail, .p24_propertyOverview, .p24_listingHeader');
    const hasJsonLd    = !!getJsonLdListing();
    const urlLooksLike = /\/for-sale\/|\/to-rent\/|\/for-sale-by-owner\//.test(window.location.pathname);
    const tileCount    = document.querySelectorAll('[data-listing-number]').length;
    const isSearch     = tileCount > 2;

    return !isSearch && (hasGallery || hasDetail || hasJsonLd || urlLooksLike);
  }

  // ══════════════════════════════════════════════════════════
  // ── SEARCH PAGE EXTRACTION (Capture Listings flow) ────────
  // ══════════════════════════════════════════════════════════

  function getSearchContext() {
    let searchTerm  = null;
    let totalResults = null;
    let totalPages   = null;

    try {
      const h1 = document.querySelector('h1');
      if (h1) searchTerm = h1.textContent.trim();
    } catch (e) { /* ignore */ }

    if (!searchTerm) {
      try { searchTerm = (document.title || '').split('|')[0].trim(); } catch (e) { /* ignore */ }
    }

    try {
      const pager = document.querySelector('.p24_topPager');
      if (pager) {
        // Canonical P24 pager text: "Showing : 1 - 20 of 481". Parse the number AFTER
        // "of" — reading the 2nd bold span grabbed the page-range END (e.g. 45), not the
        // total. Space-separated thousands ("1 481") are stripped to digits.
        const pagerText = (pager.textContent || '').replace(/\s+/g, ' ');
        const m = pagerText.match(/\bof\s+([\d][\d\s,]*)/i);
        if (m) {
          totalResults = parseInt(m[1].replace(/[^\d]/g, ''), 10) || null;
        }
        // Fallback: the total is the LAST bold span in most P24 layouts (not bolds[1]).
        if (!totalResults) {
          const bolds = pager.querySelectorAll('.p24_bold');
          if (bolds.length > 0) {
            const last = parseInt(bolds[bolds.length - 1].textContent.replace(/[^\d]/g, ''), 10);
            if (!isNaN(last)) totalResults = last;
          }
        }
      }
    } catch (e) { /* ignore */ }

    if (!totalResults) {
      const tiles = document.querySelectorAll('[data-listing-number]');
      if (tiles.length > 0) totalResults = tiles.length;
    }

    try {
      const pageLinks = document.querySelectorAll('.p24_pager a, .p24_results .p24_paginateButton');
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

  // Portal lifecycle status off a search card. P24 overlays a ribbon/badge on
  // under-offer / sold tiles (no single stable class), so gather candidate
  // ribbon/badge text and keyword-match. A normally-listed card carries no such
  // ribbon → 'active'. Sold/withdrawn listings usually VANISH from for-sale search
  // (caught by the absence sweep); under-offer typically stays visible WITH a badge
  // (caught here). Values mirror ProspectingListing::PORTAL_STATUSES.
  function extractP24Status(card) {
    try {
      const parts = [];
      card.querySelectorAll(
        '.p24_badge, .p24_ribbon, .p24_ribbonWrapper, [class*="ribbon"], [class*="badge"], [class*="Status"], [class*="status"]'
      ).forEach(el => {
        const t = (el.textContent || '').trim();
        if (t) parts.push(t);
      });
      const hay = parts.join(' ').toLowerCase();
      if (/\bsold\b/.test(hay)) return 'sold';
      if (/under\s*offer|offer\s*pending|sale\s*pending|now\s*sold/.test(hay)) return 'under_offer';
      if (/withdrawn|delisted|no longer available/.test(hay)) return 'withdrawn';
      return 'active';
    } catch (e) { return 'active'; }
  }

  function extractListing(card) {
    const listing = {
      portal_ref: null, portal_url: null, address: null, suburb: null,
      price: null, bedrooms: null, bathrooms: null, garages: null,
      property_size_m2: null, erf_size_m2: null, property_type: null,
      agent_name: null, agency_name: null, thumbnail_url: null,
      portal_status: 'active', source: 'p24',
    };

    // portal_url — prefer the standard content link, but fall back to ANY for-sale/
    // to-rent link in the tile. Promoted/sponsored tiles (data-listing-number
    // "P<num>") have no a.p24_content but DO carry the listing URL on another <a>;
    // without this fallback portal_url was null → NOT-NULL insert 500 → whole batch
    // aborted (v3.1.5 fix).
    try {
      let href = null;
      const content = card.querySelector('a.p24_content');
      if (content) href = content.getAttribute('href') || content.href;
      if (!href) {
        const alt = card.querySelector('a[href*="/for-sale/"], a[href*="/to-rent/"]');
        if (alt) href = alt.getAttribute('href') || alt.href;
      }
      if (href) listing.portal_url = href.startsWith('http') ? href : 'https://www.property24.com' + href;
    } catch (e) { /* */ }

    // portal_ref — the numeric listing id from the URL is authoritative (e.g.
    // /for-sale/uvongo/margate/kwazulu-natal/6359/117400105?…). data-listing-number
    // is unreliable: promoted tiles prefix it ("P117400105") and development banners
    // use a short project id ("4922"). Derive from the URL first, else strip
    // non-digits from data-listing-number; require a real 6+ digit listing number.
    try {
      let num = null;
      if (listing.portal_url) {
        const segments = new URL(listing.portal_url).pathname.split('/').filter(Boolean);
        for (let i = segments.length - 1; i >= 0; i--) {
          if (/^\d{6,}$/.test(segments[i])) { num = segments[i]; break; }
        }
      }
      if (!num) {
        const raw = (card.getAttribute('data-listing-number') || '').replace(/\D/g, '');
        if (/^\d{6,}$/.test(raw)) num = raw;
      }
      if (num) listing.portal_ref = 'P24-' + num;
    } catch (e) { /* */ }

    try {
      listing.price = p24PriceFrom(card);
    } catch (e) { /* */ }

    try {
      const titleEl = card.querySelector('.p24_title');
      if (titleEl) {
        const t = titleEl.textContent.trim();
        const bedMatch = t.match(/^(\d+)\s+Bedroom/i);
        if (bedMatch) listing.bedrooms = parseInt(bedMatch[1], 10);
        const tl = t.toLowerCase();
        for (const pt of PROPERTY_TYPES) { if (tl.includes(pt)) { listing.property_type = pt.charAt(0).toUpperCase() + pt.slice(1); break; } }
      }
    } catch (e) { /* */ }

    try { const el = card.querySelector('.p24_location'); if (el) listing.suburb = el.textContent.trim(); } catch (e) { /* */ }
    try { const el = card.querySelector('.p24_address'); if (el) { const a = el.textContent.trim(); if (a) listing.address = a; } } catch (e) { /* */ }
    // PULL-ALL (v3.1.4): leave address null when the tile has no .p24_address — do
    // NOT stamp a placeholder. Address-less listings are captured (importer accepts
    // null); the MIC "with address only" toggle filters them when wanted.

    if (!listing.suburb && listing.portal_url) {
      try {
        const s = new URL(listing.portal_url).pathname.split('/').filter(Boolean);
        if (s.length >= 2 && !/^\d+$/.test(s[1])) listing.suburb = s[1].replace(/-/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
      } catch (e) { /* */ }
    }

    try {
      const bedEl = card.querySelector('.p24_featureDetails[title="Bedrooms"] span'); if (bedEl) { const v = parseInt(bedEl.textContent.trim(), 10); if (!isNaN(v)) listing.bedrooms = v; }
      const bathEl = card.querySelector('.p24_featureDetails[title="Bathrooms"] span'); if (bathEl) { const v = parseInt(bathEl.textContent.trim(), 10); if (!isNaN(v)) listing.bathrooms = v; }
      const parkEl = card.querySelector('.p24_featureDetails[title="Parking Spaces"] span'); if (parkEl) { const v = parseInt(parkEl.textContent.trim(), 10); if (!isNaN(v)) listing.garages = v; }
    } catch (e) { /* */ }

    try {
      const sizeEl = card.querySelector('.p24_size span');
      if (sizeEl) { const m = sizeEl.textContent.trim().match(/([\d\s,.]+)\s*m[²2]/i); if (m) listing.erf_size_m2 = parseFloat(m[1].replace(/[\s,]/g, '')); }
    } catch (e) { /* */ }

    try { const img = card.querySelector('.p24_branding img[alt]'); if (img) { const a = img.getAttribute('alt').trim(); if (a) listing.agency_name = a; } } catch (e) { /* */ }

    try {
      const thumb = card.querySelector('img.js_P24_listingImage');
      if (thumb) listing.thumbnail_url = thumb.getAttribute('src') || thumb.getAttribute('data-original') || thumb.getAttribute('lazy-src') || null;
      if (!listing.thumbnail_url) { const el = card.querySelector('.p24_image img[src]'); if (el) listing.thumbnail_url = el.getAttribute('src'); }
    } catch (e) { /* */ }

    // MIC SOLD / OFF-MARKET — portal lifecycle status (active/under_offer/sold/withdrawn).
    listing.portal_status = extractP24Status(card);

    return listing;
  }

  function extractAllListings() {
    const allCards = document.querySelectorAll('[data-listing-number]');
    const seen = new Set();
    const listings = [];

    allCards.forEach(card => {
      try {
        // Skip new-development banners — they are projects, not property listings
        // (short project id, /new-developments/ URL, no listing to prospect).
        if (card.classList && card.classList.contains('p24_development')) return;
        const listing = extractListing(card);
        // Only real listings: a valid numeric ref AND a listing URL. This drops the
        // developer/sponsored shells that would otherwise POST a null url / malformed
        // ref and 500 the batch. Dedup by the RESOLVED ref (promoted + regular tile
        // for the same listing collapse to one).
        if (!listing.portal_ref || !listing.portal_url) return;
        if (seen.has(listing.portal_ref)) return;
        seen.add(listing.portal_ref);
        listings.push(listing);
      } catch (e) { /* skip */ }
    });

    // PULL-ALL (v3.1.4): capture EVERY listing with a portal identity, addressed or
    // not. Previously this dropped address-less tiles here (the real bug behind
    // "0 address-less captured"); now they survive with a null address.
    return listings.filter(l => l.portal_ref || l.portal_url);
  }

  // ══════════════════════════════════════════════════════════
  // ── DETAIL PAGE EXTRACTION (Pull Property flow) ───────────
  // ══════════════════════════════════════════════════════════

  function getJsonLdListing() {
    const scripts = document.querySelectorAll('script[type="application/ld+json"]');
    for (const script of scripts) {
      try {
        const data = JSON.parse(script.textContent);
        if (data['@graph'] && Array.isArray(data['@graph'])) {
          for (const item of data['@graph']) {
            if (item['@type'] === 'RealEstateListing' || item['@type'] === 'Product') return item;
          }
        }
        if (data['@type'] === 'RealEstateListing' || data['@type'] === 'Product') return data;
      } catch (e) { /* skip */ }
    }
    return null;
  }

  function getListingContext() {
    try {
      const scripts = document.querySelectorAll('script:not([src])');
      for (const script of scripts) {
        const text = script.textContent;
        if (text.includes('listingLeadFormContext')) {
          const match = text.match(/listingLeadFormContext\s*=\s*(\{[\s\S]*?\});/);
          if (match) return JSON.parse(match[1]);
        }
      }
    } catch (e) { /* ignore */ }
    return null;
  }

  function extractPropertyDetail() {
    const property = {
      portal_ref: null, portal_url: window.location.href,
      title: null, description: null, price: null,
      address: null, suburb: null, city: null, region: null,
      beds: null, baths: null, garages: null,
      erf_size_m2: null, size_m2: null, property_type: null,
      features: [], images: [],
      agent_name: null, agency_name: null, source: 'p24',
    };

    // 1. JSON-LD
    const jsonLd = getJsonLdListing();
    if (jsonLd) {
      property.title = jsonLd.name || null;

      if (jsonLd.offers) {
        const o = jsonLd.offers;
        if (o.priceSpecification && o.priceSpecification.price) property.price = parseInt(String(o.priceSpecification.price).replace(/[^\d]/g, ''), 10) || null;
        else if (o.price) property.price = parseInt(String(o.price).replace(/[^\d]/g, ''), 10) || null;
      }

      const about = jsonLd.about || jsonLd;
      if (about) {
        if (about.numberOfBedrooms != null) property.beds = parseInt(about.numberOfBedrooms, 10);
        if (about.numberOfBathroomsTotal != null) property.baths = parseInt(about.numberOfBathroomsTotal, 10);
        if (about.numberOfRooms != null && property.beds == null) property.beds = parseInt(about.numberOfRooms, 10);
        if (about.floorSize) {
          const val = typeof about.floorSize === 'object' ? about.floorSize.value : about.floorSize;
          if (val) property.size_m2 = parseInt(String(val).replace(/[^\d]/g, ''), 10) || null;
        }
        if (about.address) {
          property.suburb = about.address.addressLocality || null;
          property.region = about.address.addressRegion || null;
          property.city = about.address.addressLocality || null;
        }
        if (about['@type'] && about['@type'] !== 'RealEstateListing') property.property_type = about['@type'];
      }

      if (jsonLd.url) property.portal_url = jsonLd.url;
      if (jsonLd.image) {
        const imgs = Array.isArray(jsonLd.image) ? jsonLd.image : [jsonLd.image];
        imgs.forEach(img => { const url = typeof img === 'string' ? img : (img.url || img.contentUrl || null); if (url) property.images.push(url); });
      }
    }

    // 2. Listing context (listing number, agency)
    const ctx = getListingContext();
    if (ctx) {
      if (ctx.listingNumber && ctx.listingNumber.number) property.portal_ref = 'P24-' + ctx.listingNumber.number;
      if (ctx.agencyName) property.agency_name = ctx.agencyName;
    }

    if (!property.portal_ref) {
      const urlMatch = window.location.pathname.match(/\/(\d{6,})$/);
      if (urlMatch) property.portal_ref = 'P24-' + urlMatch[1];
    }

    // 3. DOM fallbacks
    if (!property.title) { const h1 = document.querySelector('.p24_listingHeader h1, h1'); if (h1) property.title = h1.textContent.trim(); }
    // Title fallback: og:title
    if (!property.title) { const el = document.querySelector('meta[property="og:title"]'); if (el) property.title = el.getAttribute('content'); }

    if (!property.price) {
      property.price = p24PriceFrom(document);
    }

    // Description — try multiple sources (P24 renders description dynamically)
    if (!property.description) {
      // Try DOM selectors first (many possible containers)
      const descSelectors = [
        '.p24_description',
        '.p24_listingDetail .js_readMore',
        '[itemprop="description"]',
        '.js_expandedText',
        '.p24_expandedText',
        '[class*="listing-description"]',
        '[class*="listingDescription"]',
        '[class*="property-description"]',
        '.p24_content .p24_excerpt',
      ];
      for (const sel of descSelectors) {
        try {
          const el = document.querySelector(sel);
          if (el && el.textContent.trim().length > 20) {
            property.description = el.textContent.trim().substring(0, 5000);
            break;
          }
        } catch (e) { /* */ }
      }
    }
    // Description fallback: meta description (server-rendered, always present)
    if (!property.description) {
      try {
        const meta = document.querySelector('meta[name="description"]') || document.querySelector('meta[property="og:description"]');
        if (meta) {
          const content = meta.getAttribute('content');
          if (content && content.length > 20) property.description = content.trim();
        }
      } catch (e) { /* */ }
    }
    // Description fallback: find largest text block in page body
    if (!property.description) {
      try {
        const paragraphs = document.querySelectorAll('p, div[class*="description"], div[class*="Description"]');
        let longest = '';
        paragraphs.forEach(p => {
          const t = p.textContent.trim();
          if (t.length > longest.length && t.length > 50 && t.length < 10000) longest = t;
        });
        if (longest.length > 50) property.description = longest.substring(0, 5000);
      } catch (e) { /* */ }
    }

    if (!property.address) { const el = document.querySelector('.p24_address, [class*="p24_mapsAddress"]'); if (el) property.address = el.textContent.trim(); }
    if (!property.suburb) { const el = document.querySelector('.p24_location'); if (el) property.suburb = el.textContent.trim(); }

    // Features from overview icons
    try {
      const overview = document.querySelector('.p24_propertyOverview, .p24_featureDetails');
      if (overview) {
        overview.querySelectorAll('span').forEach(span => {
          const text = span.textContent.trim();
          const img = span.querySelector('img');
          const alt = img ? (img.getAttribute('alt') || '').toLowerCase() : '';
          if (alt.includes('bed')) { const n = parseInt(text, 10); if (!isNaN(n) && property.beds == null) property.beds = n; }
          else if (alt.includes('bath')) { const n = parseInt(text, 10); if (!isNaN(n) && property.baths == null) property.baths = n; }
          else if (alt.includes('garage') || alt.includes('parking')) { const n = parseInt(text, 10); if (!isNaN(n) && property.garages == null) property.garages = n; }
          else if (alt.includes('erf') || alt.includes('land')) { const m = text.match(/([\d\s,.]+)\s*m[²2]/i); if (m && property.erf_size_m2 == null) property.erf_size_m2 = parseInt(m[1].replace(/[\s,]/g, ''), 10); }
          else if (alt.includes('floor') || alt.includes('size')) { const m = text.match(/([\d\s,.]+)\s*m[²2]/i); if (m && property.size_m2 == null) property.size_m2 = parseInt(m[1].replace(/[\s,]/g, ''), 10); }
        });
      }
    } catch (e) { /* */ }

    // Title-based fallbacks
    try {
      if (property.beds == null) { const el = document.querySelector('.p24_featureDetails[title="Bedrooms"] span, [title="Bedrooms"]'); if (el) { const v = parseInt(el.textContent, 10); if (!isNaN(v)) property.beds = v; } }
      if (property.baths == null) { const el = document.querySelector('.p24_featureDetails[title="Bathrooms"] span, [title="Bathrooms"]'); if (el) { const v = parseInt(el.textContent, 10); if (!isNaN(v)) property.baths = v; } }
      if (property.garages == null) { const el = document.querySelector('.p24_featureDetails[title="Parking Spaces"] span, [title="Parking Spaces"]'); if (el) { const v = parseInt(el.textContent, 10); if (!isNaN(v)) property.garages = v; } }
    } catch (e) { /* */ }

    if (!property.property_type && property.title) {
      const tl = property.title.toLowerCase();
      for (const pt of PROPERTY_TYPES) { if (tl.includes(pt)) { property.property_type = pt.charAt(0).toUpperCase() + pt.slice(1); break; } }
    }

    // 4. Features list
    try {
      document.querySelectorAll('.p24_keyFeatures li, .p24_features li, .p24_propertyFeatures li, .p24_propertyOverview [class*="feature"] li, [class*="keyFeature"] li')
        .forEach(li => { const t = li.textContent.trim(); if (t && t.length < 100) property.features.push(t); });
    } catch (e) { /* */ }

    // 5. Images — P24 uses sequential image IDs on images.prop24.com
    //
    // P24 gallery structure:
    //   - Gallery images use class: js_mainThreeImage (only 3 rendered in DOM)
    //   - Image URLs: https://images.prop24.com/{imageId}/Ensure1280x720
    //   - Image IDs are SEQUENTIAL for a listing's photos
    //   - Total count shown in: .p24_gallery "See all 25 images"
    //
    // Strategy: get first image ID + count, send to backend, backend constructs all URLs
    //
    property.first_image_id = null;
    property.image_count = 0;

    // 5a. Get first image ID from gallery images (js_mainThreeImage)
    try {
      const galleryImg = document.querySelector('.js_mainThreeImage img, .p24_printGalleryImage img');
      if (galleryImg) {
        const src = galleryImg.getAttribute('src') || '';
        // URL format: https://images.prop24.com/374878621/Ensure1280x720
        const match = src.match(/images\.prop24\.com\/(\d+)/);
        if (match) {
          property.first_image_id = parseInt(match[1], 10);
        }
      }
    } catch (e) { /* */ }

    // Fallback: og:image
    if (!property.first_image_id) {
      try {
        const ogImg = document.querySelector('meta[property="og:image"]');
        if (ogImg) {
          const match = ogImg.getAttribute('content').match(/images\.prop24\.com\/(\d+)/);
          if (match) property.first_image_id = parseInt(match[1], 10);
        }
      } catch (e) { /* */ }
    }

    // 5b. Get image count from gallery text ("See all 25 images")
    try {
      const galleryEl = document.querySelector('.p24_gallery');
      if (galleryEl) {
        const countMatch = galleryEl.textContent.match(/(\d+)\s*image/i);
        if (countMatch) property.image_count = parseInt(countMatch[1], 10);
      }
    } catch (e) { /* */ }

    // Fallback: count from body text
    if (!property.image_count) {
      try {
        const bodyMatch = document.body.innerText.match(/(\d+)\s*image/i);
        if (bodyMatch) property.image_count = parseInt(bodyMatch[1], 10);
      } catch (e) { /* */ }
    }

    // Fallback: at least count the gallery images in the DOM
    if (!property.image_count && property.first_image_id) {
      property.image_count = document.querySelectorAll('.js_mainThreeImage').length || 1;
    }

    // 6. Agent info
    try { const el = document.querySelector('.p24_agentName, [class*="agent-name"], [class*="agentName"], .p24_listingAgentName, .p24_agentDetails .p24_name'); if (el) property.agent_name = el.textContent.trim(); } catch (e) { /* */ }
    if (!property.agency_name) {
      try {
        const el = document.querySelector('.p24_agencyName, .p24_listingBranding img[alt], [class*="agency-name"], [class*="agencyName"]');
        if (el) property.agency_name = el.tagName === 'IMG' ? el.getAttribute('alt') : el.textContent.trim();
      } catch (e) { /* */ }
    }

    // 7. Erf size fallback
    if (!property.erf_size_m2) {
      try { document.querySelectorAll('.p24_size span, [class*="erfSize"], [class*="erf-size"]').forEach(el => { const m = el.textContent.trim().match(/([\d\s,.]+)\s*m[²2]/i); if (m) property.erf_size_m2 = parseInt(m[1].replace(/[\s,]/g, ''), 10); }); } catch (e) { /* */ }
    }

    return property;
  }

  // ══════════════════════════════════════════════════════════
  // ── MESSAGE HANDLER (single handler for all P24 actions) ──
  // ══════════════════════════════════════════════════════════

  chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {

    // Page type detection (used by popup to decide which actions to enable)
    if (msg.action === 'getPageType') {
      sendResponse({
        isDetailPage: isListingDetailPage(),
        isSearchPage: isSearchResultsPage(),
        url: window.location.href,
      });
      return true;
    }

    // Search page info (used by Capture Listings flow)
    if (msg.action === 'getPageInfo') {
      sendResponse(getSearchContext());
      return true;
    }

    // Extract all listings from search page (used by Capture Listings flow)
    if (msg.action === 'getListings') {
      sendResponse({ listings: extractAllListings() });
      return true;
    }

    // Extract full property detail (used by Pull Property flow)
    if (msg.action === 'getPropertyDetail') {
      if (!isListingDetailPage()) {
        sendResponse({ error: 'Not a listing detail page' });
        return true;
      }
      sendResponse({ property: extractPropertyDetail() });
      return true;
    }

    return false;
  });
})();
