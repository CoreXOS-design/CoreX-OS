/**
 * CoreX — MIC capture helpers (extension 3.13.0), shared by the Property24 and Private Property content scripts.
 *
 * Reads the PUBLIC advert facts of .ai/specs/mic-work-tab.md §5 off a search tile or a listing page. Pure functions over
 * a DOM node passed in, so they run unchanged under jsdom in tests/mic-lib.test.cjs.
 *
 * Rules (spec §5, §11.2):
 *   - Only what the advert shows. Never click "Show contact number"; no phone numbers or e-mail addresses are read.
 *   - Wording is sent as a SHA-1 fingerprint of the normalised text, never the text. (The first-photo fingerprint is made
 *     by background.js from the image bytes; the image is never stored or sent.)
 *   - A tile that lacks a field leaves the key out, so the server never blanks a stored value because of a missing key.
 */
(function (root) {
  'use strict';

  const MIC = {};

  // ── SHA-1 (hex) — synchronous, so tile extraction stays synchronous ────────────────────────────
  MIC.sha1hex = function (str) {
    const bytes = [];
    const s = unescape(encodeURIComponent(String(str)));
    for (let i = 0; i < s.length; i++) bytes.push(s.charCodeAt(i));
    const words = [];
    for (let i = 0; i < bytes.length; i++) words[i >> 2] = (words[i >> 2] || 0) | (bytes[i] << (24 - (i % 4) * 8));
    const bitLen = bytes.length * 8;
    words[bitLen >> 5] = (words[bitLen >> 5] || 0) | (0x80 << (24 - (bitLen % 32)));
    words[(((bitLen + 64) >> 9) << 4) + 15] = bitLen;
    const rol = (n, c) => (n << c) | (n >>> (32 - c));
    let h0 = 0x67452301, h1 = 0xefcdab89, h2 = 0x98badcfe, h3 = 0x10325476, h4 = 0xc3d2e1f0;
    for (let i = 0; i < words.length; i += 16) {
      const w = new Array(80);
      for (let t = 0; t < 80; t++) {
        w[t] = t < 16 ? (words[i + t] | 0) : rol(w[t - 3] ^ w[t - 8] ^ w[t - 14] ^ w[t - 16], 1);
      }
      let a = h0, b = h1, c = h2, d = h3, e = h4;
      for (let t = 0; t < 80; t++) {
        let f, k;
        if (t < 20) { f = (b & c) | (~b & d); k = 0x5a827999; }
        else if (t < 40) { f = b ^ c ^ d; k = 0x6ed9eba1; }
        else if (t < 60) { f = (b & c) | (b & d) | (c & d); k = 0x8f1bbcdc; }
        else { f = b ^ c ^ d; k = 0xca62c1d6; }
        const tmp = (rol(a, 5) + f + e + k + w[t]) | 0;
        e = d; d = c; c = rol(b, 30); b = a; a = tmp;
      }
      h0 = (h0 + a) | 0; h1 = (h1 + b) | 0; h2 = (h2 + c) | 0; h3 = (h3 + d) | 0; h4 = (h4 + e) | 0;
    }
    return [h0, h1, h2, h3, h4].map((n) => ('00000000' + (n >>> 0).toString(16)).slice(-8)).join('');
  };

  // Lower-case, letters+digits only, single spaces: the same advert text on two portals differs in punctuation/line breaks.
  MIC.normaliseWording = function (text) {
    return String(text || '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
  };

  // Fingerprint of an advert's wording. Too little text proves nothing, so < 60 normalised characters gives null.
  MIC.wordingFingerprint = function (text) {
    const n = MIC.normaliseWording(text);
    return n.length >= 60 ? MIC.sha1hex(n) : null;
  };

  // "R 1 323", "R1,323.00", "1 323" -> 1323 (rand per month, cents dropped). null when no figure.
  MIC.parseRand = function (text) {
    if (text === null || text === undefined) return null;
    let s = String(text).replace(/[  ]/g, ' ');
    s = s.replace(/\.\d{1,2}(?!\d)/, '');            // drop cents
    const m = s.match(/\d[\d\s,]*/);
    if (!m) return null;
    const n = parseInt(m[0].replace(/[^\d]/g, ''), 10);
    return Number.isFinite(n) && n >= 0 && n <= 10000000 ? n : null;
  };

  // "169 m²", "1 375.5 m2" -> number
  MIC.parseM2 = function (text) {
    if (!text) return null;
    const m = String(text).replace(/[  ]/g, ' ').match(/(\d[\d\s,.]*)\s*(?:m\s*[²2]|sqm|square)/i) || String(text).match(/(\d[\d\s,.]*)/);
    if (!m) return null;
    let t = m[1].replace(/\s/g, '');
    if (/,\d{3}(?!\d)/.test(t) && !/\./.test(t)) t = t.replace(/,/g, '');
    else t = t.replace(/,/g, '.');
    const n = parseFloat(t);
    return Number.isFinite(n) && n > 0 && n < 100000000 ? n : null;
  };

  const MONTHS = { jan: 1, feb: 2, mar: 3, apr: 4, may: 5, jun: 6, jul: 7, aug: 8, sep: 9, oct: 10, nov: 11, dec: 12 };

  // "01 September 2026", "2026-09-01", "2026-09-01T08:00:00Z" -> "2026-09-01". null otherwise.
  MIC.parseListingDate = function (text) {
    if (!text) return null;
    const s = String(text).trim();
    let y, mo, d;
    let m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (m) { y = +m[1]; mo = +m[2]; d = +m[3]; }
    else if ((m = s.match(/(\d{1,2})\s+([A-Za-z]{3,9})\.?,?\s+(\d{4})/))) {
      d = +m[1]; mo = MONTHS[m[2].slice(0, 3).toLowerCase()]; y = +m[3];
    } else return null;
    if (!mo || mo < 1 || mo > 12 || d < 1 || d > 31 || y < 2005 || y > 2100) return null;
    return y + '-' + ('0' + mo).slice(-2) + '-' + ('0' + d).slice(-2);
  };

  // The icon next to a size on a P24 tile says which size it is: alt "Erf Size" / "Floor Size".
  MIC.sizeKind = function (alt) {
    const a = String(alt || '').toLowerCase();
    if (/floor|building|unit/.test(a)) return 'floor';
    if (/erf|land|stand|plot/.test(a)) return 'erf';
    return null;
  };

  // ── Badges: ONLY from badge-like elements and short label text, never the advert description ───
  const BADGE_RULES = [
    [/^\s*retirement\b|retirement (village|living|home)/i, 'retirement'],
    [/\bauction\b/i, 'auction'],
    [/repossess|bank\s*repo|\bin\s*possession\b/i, 'repossessed'],
    [/\bon\s*show\b|show\s*day|open\s*house/i, 'on_show'],
    [/no\s*transfer\s*duty|transfer\s*duty\s*free|transfer\s*duty\s*exempt/i, 'no_transfer_duty'],
    [/bank\s*sale/i, 'bank_sale'],
  ];

  function labelTexts(root0) {
    const out = [];
    const sel = '[class*="badge" i], [class*="ribbon" i], [class*="banner" i], [class*="flag" i], [class*="tag" i]';
    root0.querySelectorAll(sel).forEach((el) => {
      const t = (el.textContent || '').trim();
      if (t && t.length <= 60) out.push(t);
      ['title', 'alt', 'aria-label'].forEach((a) => { const v = el.getAttribute(a); if (v && v.length <= 60) out.push(v); });
    });
    // short stand-alone labels (leaf elements) — hashed-class sites such as PP
    root0.querySelectorAll('span, div, p, li, strong, em, b').forEach((el) => {
      if (el.children.length) return;
      const t = (el.textContent || '').trim();
      if (t && t.length <= 30 && BADGE_RULES.some((r) => r[0].test(t))) out.push(t);
    });
    // badge icons only — never an agency/agent logo ("Auction Alliance" is an agency, not an auction badge)
    root0.querySelectorAll('img[alt]').forEach((img) => {
      if (img.closest && img.closest('.p24_branding, [class*="brand" i], [class*="logo" i], [class*="agen" i]')) return;
      const a = (img.getAttribute('alt') || '').trim();
      if (a && a.length <= 60) out.push(a);
    });
    return out;
  }

  MIC.badgesFrom = function (root0) {
    const found = [];
    labelTexts(root0).forEach((t) => {
      BADGE_RULES.forEach((r) => { if (r[0].test(t) && found.indexOf(r[1]) === -1) found.push(r[1]); });
    });
    return found;
  };

  function digits(v) { return String(v === null || v === undefined ? '' : v).replace(/\D/g, ''); }

  // ── Property24 search tile ──────────────────────────────────────────────────────────────────────
  // Adds the MIC keys to the listing object extractListing() already built. `l` is mutated and returned.
  MIC.p24Tile = function (card, l) {
    // who is advertising
    try {
      const img = card.querySelector('.p24_branding img');
      if (img) {
        const src = [img.getAttribute('src'), img.getAttribute('data-original'), img.getAttribute('lazy-src')].filter(Boolean).join(' ');
        const alt = (img.getAttribute('alt') || '').trim();
        if (/listed_by_owner/i.test(src) || alt.toLowerCase() === 'sale') {
          l.advertiser_type = 'owner';
          l.agency_name = null;               // never "Sale"
        } else {
          l.advertiser_type = 'agency';
        }
      }
    } catch (e) { /* */ }

    // reduced
    try { l.is_reduced = !!card.querySelector('.p24_reducedBanner'); } catch (e) { /* */ }

    // mandate id
    try {
      const el = card.hasAttribute('data-mandate-id') ? card : card.querySelector('[data-mandate-id]');
      const id = el ? digits(el.getAttribute('data-mandate-id')) : '';
      if (id) l.p24_mandate_id = id;
    } catch (e) { /* */ }

    // P24 group ("Listed by N Estate Agencies")
    try {
      const gEl = card.hasAttribute('data-group-listing-id') ? card : card.querySelector('[data-group-listing-id]');
      const gid = gEl ? digits(gEl.getAttribute('data-group-listing-id')) : '';
      if (gid) {
        l.p24_group_id = gid;
        const tEl = (gEl.hasAttribute('data-listing-group-text') ? gEl : card.querySelector('[data-listing-group-text]'));
        const txt = tEl ? (tEl.getAttribute('data-listing-group-text') || tEl.textContent || '') : (card.textContent || '');
        const sm = txt.match(/(\d+)\s+Estate\s+Agenc/i);
        if (sm) l.p24_group_size = parseInt(sm[1], 10);
        // real markup (31 Oct): <span class="js_groupedListingsLink" data-group-listing-id=".." data-href="/Results/GroupedListings/<id>?..&listingNumbers=%5b1%2c2%5d">
        let href = gEl.getAttribute('data-href') || '';
        if (!/GroupedListings/i.test(href)) {
          const link = card.querySelector('[data-href*="GroupedListings" i], a[href*="GroupedListings" i]');
          href = link ? (link.getAttribute('data-href') || link.getAttribute('href') || '') : '';
        }
        const nums = MIC.listingNumbersFromHref(href);
        if (nums.length) l.p24_group_listing_numbers = nums;
      }
    } catch (e) { /* */ }

    // badges
    try { l.badges = MIC.badgesFrom(card); } catch (e) { /* */ }

    // floor vs erf size: the icon's alt says which one the tile is showing
    try {
      const sz = card.querySelector('.p24_size');
      if (sz) {
        const img = sz.querySelector('img[alt]') || (sz.parentElement ? sz.parentElement.querySelector('img[alt*="Size" i]') : null);
        const kind = MIC.sizeKind(img ? img.getAttribute('alt') : '');
        const span = sz.querySelector('span');
        const m2 = MIC.parseM2(span ? span.textContent : sz.textContent);
        if (m2 !== null && kind === 'floor') { l.property_size_m2 = m2; l.erf_size_m2 = null; }
        else if (m2 !== null && kind === 'erf') { l.erf_size_m2 = m2; l.property_size_m2 = null; }
      }
    } catch (e) { /* */ }

    return l;
  };

  // listing numbers out of the GroupedListings link: ".../Results/GroupedListings/123?listingNumbers=1,2,3" (or path/encoded forms)
  MIC.listingNumbersFromHref = function (href) {
    let h = String(href || '');
    try { h = decodeURIComponent(h); } catch (e) { /* keep raw */ }
    const m = h.match(/listingNumbers[=/]([^&#?]*)/i);
    const part = m ? m[1] : '';
    const out = [];
    (part.match(/\d{6,12}/g) || []).forEach((n) => { if (out.indexOf(n) === -1) out.push(n); });
    return out;
  };

  // ── Property24 listing page ─────────────────────────────────────────────────────────────────────
  function lastNumber(url) { const m = String(url || '').match(/\/(\d+)\/?(?:[?#].*)?$/); return m ? m[1] : null; }

  function jsonLdListings(doc) {
    const out = [];
    doc.querySelectorAll('script[type="application/ld+json"]').forEach((s) => {
      try {
        const d = JSON.parse(s.textContent);
        const items = d && d['@graph'] ? d['@graph'] : (Array.isArray(d) ? d : [d]);
        items.forEach((it) => { if (it && (it['@type'] === 'RealEstateListing' || it.datePosted || it.offers || it.geo)) out.push(it); });
      } catch (e) { /* */ }
    });
    return out;
  }

  function overviewRows(doc) {
    const rows = {};
    doc.querySelectorAll('.p24_propertyOverviewRow').forEach((row) => {
      const key = row.querySelector('.p24_propertyOverviewKey');
      const val = row.querySelector('.p24_propertyOverviewResult .p24_info') || row.querySelector('.p24_propertyOverviewResult');
      if (key && val) rows[(key.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase()] = (val.textContent || '').replace(/\s+/g, ' ').trim();
    });
    return rows;
  }

  function coord(a, b) {
    const lat = parseFloat(a), lng = parseFloat(b);
    return Number.isFinite(lat) && Number.isFinite(lng) && Math.abs(lat) > 0 && Math.abs(lng) > 0 ? { lat, lng } : null;
  }

  // The first photo of an advert, as a URL for background.js to fingerprint (it is NOT sent on). Underscore = private.
  function firstPhotoUrl(doc, ld) {
    try {
      const img = ld && (Array.isArray(ld.image) ? ld.image[0] : ld.image);
      const u = img && (typeof img === 'string' ? img : (img.url || img.contentUrl));
      if (u && /^https?:/i.test(u)) return u;
    } catch (e) { /* */ }
    const el = doc.querySelector('.js_galleryImage, .p24_gallery img, [data-gallery-image]');
    if (el) {
      const u = el.getAttribute('data-original') || el.getAttribute('data-src') || el.getAttribute('src');
      if (u && /^https?:/i.test(u) && !/blank\.gif/i.test(u)) return u;
    }
    return null;
  }

  MIC.p24ListingPage = function (doc, href) {
    const f = { page_read: true };
    const ldAll = jsonLdListings(doc);
    const ld = ldAll[0] || null;
    const rows = overviewRows(doc);
    const scriptText = Array.prototype.map.call(doc.querySelectorAll('script:not([src])'), (s) => s.textContent || '').join('\n');

    // lead-form context: isPrivateListing is P24's own "no agent behind this advert" flag
    let lead = null;
    try {
      const m = scriptText.match(/window\.listingLeadFormContext\s*=\s*(\{[\s\S]*?\});\s*(?:<\/script>|$|\n)/);
      if (m) lead = JSON.parse(m[1]);
    } catch (e) { lead = null; }

    const bodyText = (doc.body ? doc.body.textContent : '') || '';
    const contactSeller = /Contact\s+Seller/i.test(bodyText);
    const contactAgent = /Contact\s+Agent/i.test(bodyText);
    let offeredBy = null;
    try { offeredBy = ld && ld.offers && ld.offers.offeredBy ? ld.offers.offeredBy : null; } catch (e) { /* */ }

    const isOwner = (lead && lead.isPrivateListing === true) || (!contactAgent && contactSeller && !offeredBy);
    if (isOwner) {
      f.advertiser_type = 'owner';
      f.agency_name = null;
    } else if (lead || offeredBy) {
      f.advertiser_type = 'agency';
      const agency = (offeredBy && offeredBy.worksFor && offeredBy.worksFor.name) || (lead && lead.agencyName) || null;
      if (agency) f.agency_name = String(agency).trim();
      const agencyId = lastNumber(offeredBy && offeredBy.worksFor && offeredBy.worksFor.url);
      if (agencyId) f.agency_portal_id = agencyId;
      const agentName = (offeredBy && offeredBy.name) || (lead && lead.primaryAgent && lead.primaryAgent.name) || null;
      if (agentName) f.agent_name = String(agentName).trim();
      const agentId = lastNumber(offeredBy && offeredBy.url) || (lead && lead.primaryAgent && lead.primaryAgent.id ? String(lead.primaryAgent.id) : null);
      if (agentId) f.agent_portal_id = agentId;
    }

    // listing date: the JSON-LD datePosted, else the Property Overview "Listing Date" row
    const posted = MIC.parseListingDate(ld && ld.datePosted) || MIC.parseListingDate(rows['listing date']);
    if (posted) f.listed_date = posted;

    const rates = MIC.parseRand(rows['rates and taxes'] || rows['rates & taxes'] || rows['rates']);
    if (rates !== null) f.rates_amount = rates;
    const levies = MIC.parseRand(rows['levies'] || rows['levy']);
    if (levies !== null) f.levies_amount = levies;

    const floor = MIC.parseM2(rows['floor size']);
    if (floor !== null) f.property_size_m2 = floor;
    const erf = MIC.parseM2(rows['erf size']);
    if (erf !== null) f.erf_size_m2 = erf;

    // the portal's own map point — present only when the address is shown
    let pt = null;
    try {
      const about = ld && ld.about;
      const geo = (about && (about.geo || about)) || (ld && ld.geo) || null;
      pt = geo ? coord(geo.latitude, geo.longitude) : null;
      if (!pt && ld && ld.about && ld.about.address && ld.about.address.geo) pt = coord(ld.about.address.geo.latitude, ld.about.address.geo.longitude);
    } catch (e) { /* */ }
    if (pt) { f.portal_latitude = pt.lat; f.portal_longitude = pt.lng; }

    const mm = scriptText.match(/"mandateId"\s*:\s*(\d{3,})/);
    if (mm) f.p24_mandate_id = mm[1];

    // wording fingerprint (full description when the page has it)
    const descEl = doc.querySelector('.p24_descriptionContainer .js_expandedText') || doc.querySelector('.p24_descriptionContainer .js_readMoreText') || doc.querySelector('.p24_descriptionContainer');
    const wf = descEl ? MIC.wordingFingerprint(descEl.textContent) : null;
    if (wf) f.wording_fingerprint = wf;

    f.badges = MIC.badgesFrom(doc.querySelector('.p24_listingDetail, .p24_listingHeader, header') || doc.body || doc);
    const photo = firstPhotoUrl(doc, ld);
    if (photo) f._photo_url = photo;
    f._ref = (String(href || '').match(/\/(\d{6,12})(?:[/?#]|$)/) || [])[1] || (lead && lead.listingNumber && lead.listingNumber.number ? String(lead.listingNumber.number) : null);
    return f;
  };

  // ── Private Property search tile (the <a> is the card) ──────────────────────────────────────────
  MIC.ppTile = function (link, l) {
    let text = '';
    try { text = (link.textContent || '').replace(/\s+/g, ' '); } catch (e) { /* */ }

    // private seller
    try {
      const priv = link.querySelector('.listing-result__listed-privately, [class*="listed-privately"]') || (/LISTED PRIVATELY/.test(text) ? link : null);
      if (priv) { l.advertiser_type = 'owner'; l.agency_name = null; }
      else l.advertiser_type = 'agency';          // a PP tile without the private marker is an agency advert
    } catch (e) { /* */ }

    // status banner: PP search includes under offer & sold, so say so explicitly
    try {
      const banners = Array.prototype.map.call(link.querySelectorAll('.listing-banner--offer-pending, [class*="listing-banner"]'), (b) => (b.textContent || '').toLowerCase());
      const pending = !!link.querySelector('.listing-banner--offer-pending') || banners.some((t) => /under\s*offer|offer\s*pending/.test(t));
      const sold = banners.some((t) => /\bsold\b/.test(t));
      l.portal_status = sold ? 'sold' : (pending ? 'under_offer' : 'active');
    } catch (e) { /* */ }

    // exclusive to PP
    try { l.is_exclusive = /Only on Private Property/i.test(text); } catch (e) { /* */ }

    // floor vs land size, separately
    try {
      const fl = link.querySelector('[title="Floor size"]');
      const ld = link.querySelector('[title="Land size"]');
      const f2 = fl ? MIC.parseM2(fl.textContent) : null;
      const e2 = ld ? MIC.parseM2(ld.textContent) : null;
      if (f2 !== null) l.property_size_m2 = f2;
      if (e2 !== null) l.erf_size_m2 = e2; else if (f2 !== null) l.erf_size_m2 = null;
    } catch (e) { /* */ }

    try { l.badges = MIC.badgesFrom(link); } catch (e) { /* */ }
    return l;
  };

  // ── Private Property listing page ───────────────────────────────────────────────────────────────
  // `sv` is the safe subset background.js reads from the page's own window.serverVariables.bundleParams (MAIN world).
  MIC.ppListingPage = function (doc, href, sv) {
    const f = { page_read: true };
    sv = sv || {};
    const ld = jsonLdListings(doc)[0] || null;

    const bodyText = (doc.body ? doc.body.textContent : '') || '';
    const contactSeller = /Contact\s+Seller/i.test(bodyText);
    const contactAgent = /Contact\s+Agent/i.test(bodyText);
    const agency = sv.agencyName || null;
    const isOwner = sv.hasAgency === false || (contactSeller && !contactAgent && !agency);
    if (isOwner) {
      f.advertiser_type = 'owner';
      f.agency_name = null;
    } else if (agency || contactAgent) {
      f.advertiser_type = 'agency';
      if (agency) f.agency_name = String(agency).trim();
      if (sv.agencyId) f.agency_portal_id = String(sv.agencyId);
    }
    if (sv.contactName) f.agent_name = String(sv.contactName).trim();
    if (sv.agentId) f.agent_portal_id = String(sv.agentId);

    // property details rows: "Listing date", "Floor size", "Land size", "Rates", "Levies"
    const rows = {};
    doc.querySelectorAll('.property-details__list-item').forEach((row) => {
      const label = row.querySelector('.property-details__name-value');
      const value = row.querySelector('.property-details__value');
      if (label && value) rows[(label.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase()] = (value.textContent || '').replace(/\s+/g, ' ').trim();
    });
    const pick = (re) => { const k = Object.keys(rows).find((x) => re.test(x)); return k ? rows[k] : null; };

    const posted = MIC.parseListingDate(pick(/listing date|listed|date listed/)) || MIC.parseListingDate(ld && ld.datePosted);
    if (posted) f.listed_date = posted;
    const floor = MIC.parseM2(pick(/floor size/));
    if (floor !== null) f.property_size_m2 = floor;
    const land = MIC.parseM2(pick(/land size|erf size/));
    if (land !== null) f.erf_size_m2 = land;
    const rates = MIC.parseRand(pick(/rates/));
    if (rates !== null) f.rates_amount = rates;
    const levies = MIC.parseRand(pick(/levies|levy/));
    if (levies !== null) f.levies_amount = levies;

    let pt = sv.lat !== undefined && sv.lat !== null ? coord(sv.lat, sv.lng) : null;
    if (!pt && ld) {
      const geo = ld.geo || (ld.about && ld.about.geo) || null;
      pt = geo ? coord(geo.latitude, geo.longitude) : null;
    }
    if (pt) { f.portal_latitude = pt.lat; f.portal_longitude = pt.lng; }

    const wf = MIC.wordingFingerprint(sv.description || '');
    if (wf) f.wording_fingerprint = wf;
    f.badges = MIC.badgesFrom(doc.body || doc);
    if (sv.photoUrl) f._photo_url = String(sv.photoUrl);
    f._ref = (String(href || '').match(/\/(T\d{5,})\/?(?:[?#]|$)/) || [])[1] || null;
    return f;
  };

  // PP search must include under-offer and sold: strip the filter that excludes them (spec §5)
  MIC.cleanPpSearchUrl = function (url) {
    try {
      const u = new URL(url);
      let changed = false;
      ['ps', 'PS', 'propertystatus'].forEach((k) => {
        const v = u.searchParams.get(k);
        if (v !== null && /ExcludeSoldOrUnderOffer/i.test(v)) { u.searchParams.delete(k); changed = true; }
      });
      return { url: u.toString(), changed };
    } catch (e) { return { url, changed: false }; }
  };

  if (typeof module !== 'undefined' && module.exports) module.exports = MIC;
  else root.CorexMic = MIC;
})(typeof self !== 'undefined' ? self : this);
