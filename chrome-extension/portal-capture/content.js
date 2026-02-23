/**
 * Portal Capture — Content Script (injected on demand by popup)
 * Generic extraction for any property portal site.
 * Extractor version: portal_ext_v1
 *
 * v1.1 — Reads HFC meta tags for presentation auto-detection.
 *       — Handles new P24 URL patterns (houses-for-sale, advanced-search).
 */

(function () {
    'use strict';

    var result = {};

    // ── HFC Nexus presentation meta tags ──
    var presMeta = document.querySelector('meta[name="hfc-presentation-id"]');
    var titleMeta = document.querySelector('meta[name="hfc-presentation-title"]');
    if (presMeta && presMeta.content) {
        result._hfc_presentation_id = presMeta.content;
        result._hfc_presentation_title = titleMeta ? titleMeta.content : '';
    }

    // Raw HTML
    result.raw_html = document.documentElement.outerHTML;
    result.page_title = document.title || '';

    // JSON-LD extraction — deterministic, no guesswork
    result.jsonld = [];
    var jsonldScripts = document.querySelectorAll('script[type="application/ld+json"]');
    jsonldScripts.forEach(function (script) {
        try {
            var parsed = JSON.parse(script.textContent);
            result.jsonld.push(parsed);
        } catch (e) {
            result.jsonld.push({ _parse_error: e.message, _raw: script.textContent.substring(0, 500) });
        }
    });

    // Extract structured fields from JSON-LD (if property/listing schema present)
    result.extracted_fields = {};
    result.parse_status = 'unparsed_jsonld_missing';

    function flattenJsonLd(items) {
        var flat = [];
        (Array.isArray(items) ? items : [items]).forEach(function (item) {
            if (!item || typeof item !== 'object') return;
            flat.push(item);
            if (item['@graph'] && Array.isArray(item['@graph'])) {
                item['@graph'].forEach(function (g) { flat.push(g); });
            }
        });
        return flat;
    }

    var allNodes = [];
    result.jsonld.forEach(function (ld) { allNodes = allNodes.concat(flattenJsonLd(ld)); });

    // Look for property-related schemas
    var propertyTypes = ['RealEstateListing', 'Product', 'Residence', 'House', 'Apartment', 'SingleFamilyResidence', 'Place'];
    for (var i = 0; i < allNodes.length; i++) {
        var node = allNodes[i];
        var nodeType = node['@type'] || '';
        if (typeof nodeType === 'string') nodeType = [nodeType];

        var isProperty = nodeType.some(function (t) {
            return propertyTypes.some(function (pt) { return t.toLowerCase().includes(pt.toLowerCase()); });
        });

        if (isProperty) {
            result.parse_status = 'parsed';
            if (node.name) result.extracted_fields.name = node.name;

            // Price
            if (node.offers && node.offers.price) {
                result.extracted_fields.price = node.offers.price;
                result.extracted_fields.currency = node.offers.priceCurrency || null;
            } else if (node.price) {
                result.extracted_fields.price = node.price;
            }

            // Address
            if (node.address) {
                if (typeof node.address === 'string') {
                    result.extracted_fields.address = node.address;
                } else {
                    result.extracted_fields.address = [
                        node.address.streetAddress,
                        node.address.addressLocality,
                        node.address.addressRegion,
                    ].filter(Boolean).join(', ');
                }
            }

            // Rooms
            if (node.numberOfRooms) result.extracted_fields.rooms = node.numberOfRooms;
            if (node.numberOfBedrooms) result.extracted_fields.bedrooms = node.numberOfBedrooms;
            if (node.numberOfBathroomsTotal) result.extracted_fields.bathrooms = node.numberOfBathroomsTotal;

            // Area
            if (node.floorSize) {
                result.extracted_fields.floor_size = typeof node.floorSize === 'object' ? node.floorSize.value : node.floorSize;
            }
            if (node.lotSize) {
                result.extracted_fields.lot_size = typeof node.lotSize === 'object' ? node.lotSize.value : node.lotSize;
            }

            // Image from JSON-LD
            if (node.image) {
                result.extracted_fields.image = typeof node.image === 'string' ? node.image : (node.image.url || node.image[0] || null);
            }

            result.extracted_fields._jsonld_type = node['@type'];
            break; // use first matching node
        }
    }

    // Found image URLs — all img[src] absolute URLs on page
    result.found_image_urls = [];
    var seenUrls = {};
    document.querySelectorAll('img[src]').forEach(function (img) {
        try {
            var absUrl = new URL(img.src, window.location.href).href;
            if (!seenUrls[absUrl] && absUrl.startsWith('http')) {
                seenUrls[absUrl] = true;
                result.found_image_urls.push(absUrl);
            }
        } catch (e) { /* skip invalid URLs */ }
    });

    // Page type detection (deterministic URL + JSON-LD + DOM signals)
    result.detected_page_type = 'unknown';
    var path = window.location.pathname.toLowerCase();
    var host = window.location.hostname.toLowerCase();
    var urlConfidence = 'none'; // Track how confident the URL match is

    // Property24 patterns
    // Property URLs: /for-sale/{suburb}/{region}/{province}/{areaId}/{listingId} where listingId is 6+ digits
    // Search URLs: /for-sale/... without trailing listingId, or /advanced-search/results
    if (host.includes('property24.com')) {
        if (path.includes('/advanced-search/')) {
            result.detected_page_type = 'search';
            urlConfidence = 'high';
        } else if (path.includes('for-sale') || path.includes('to-rent') || path.includes('/properties/')) {
            if (/\/\d{6,}\/?$/.test(path)) {
                result.detected_page_type = 'property';
                urlConfidence = 'high';
            } else {
                result.detected_page_type = 'search';
                urlConfidence = 'high';
            }
        } else if (/\/\d{6,}\/?$/.test(path)) {
            result.detected_page_type = 'property';
            urlConfidence = 'medium';
        }
    }
    // PrivateProperty patterns — check search FIRST
    else if (host.includes('privateproperty.co.za')) {
        if (path.includes('/for-sale') || path.includes('/to-rent') || path.includes('/results')) {
            result.detected_page_type = 'search';
            urlConfidence = 'medium';
        } else if (/\/\d+\/?$/.test(path) || path.includes('/property-detail')) {
            result.detected_page_type = 'property';
            urlConfidence = 'medium';
        }
    }
    // Generic fallback: long number in path = listing
    else if (/\/\d{6,}\/?$/.test(path)) {
        result.detected_page_type = 'property';
        urlConfidence = 'low';
    }

    // JSON-LD secondary signal: confirm or override when URL confidence is not high
    var jsonldPageType = 'unknown';
    var searchTypes = ['ItemList', 'SearchResultsPage', 'CollectionPage'];
    var listingTypes = ['Product', 'RealEstateListing', 'Residence', 'House', 'Apartment', 'SingleFamilyResidence'];
    for (var j = 0; j < allNodes.length; j++) {
        var jNode = allNodes[j];
        var jType = jNode['@type'] || '';
        if (typeof jType === 'string') jType = [jType];
        var isSearch = jType.some(function (t) {
            return searchTypes.some(function (st) { return t.toLowerCase().includes(st.toLowerCase()); });
        });
        var isListing = jType.some(function (t) {
            return listingTypes.some(function (lt) { return t.toLowerCase().includes(lt.toLowerCase()); });
        });
        if (isSearch) { jsonldPageType = 'search'; break; }
        if (isListing) { jsonldPageType = 'property'; break; }
    }
    result.jsonld_page_type = jsonldPageType;

    // Use JSON-LD to confirm or correct when URL was ambiguous
    if (urlConfidence !== 'high' && jsonldPageType !== 'unknown') {
        result.detected_page_type = jsonldPageType;
    }

    // DOM-based tiebreaker: ONLY for unknown pages (never override URL/JSON-LD property detection)
    if (result.detected_page_type === 'unknown') {
        var tileSelectors = '.p24_regularTile, .js_resultTile, [data-testid="tile"], .listing-result, .search-result, .property-card';
        var tiles = document.querySelectorAll(tileSelectors);
        var paging = document.querySelector('.pagination, [class*="paging"], [class*="Pagination"], nav[aria-label*="page"]');
        if (tiles.length >= 4 || (tiles.length >= 2 && paging)) {
            result.detected_page_type = 'search';
        }
    }

    // ── P24 search: extract total result count ──
    // P24 shows "Browse X properties" or "Showing 1-20 of X results" or "X Properties"
    if (result.detected_page_type === 'search' && host.includes('property24.com')) {
        var totalCount = null;

        // Strategy 1: Look for "X Properties" in search header elements
        var searchHeaderEls = document.querySelectorAll(
            '.p24_results h1, .p24_results .p24_heading, [class*="resultCount"], [class*="ResultCount"], [class*="searchCount"], .p24_searchResults h1, [data-testid="results-count"]'
        );
        for (var si = 0; si < searchHeaderEls.length; si++) {
            var headerText = searchHeaderEls[si].textContent.trim();
            var countMatch = headerText.match(/(\d[\d\s,]*)\s*(?:propert|result|listing)/i);
            if (countMatch) {
                var parsed = parseInt(countMatch[1].replace(/[\s,]/g, ''), 10);
                if (parsed > 0 && parsed < 100000) { totalCount = parsed; break; }
            }
        }

        // Strategy 2: "of X results" or "of X properties" anywhere on page
        if (!totalCount) {
            var bodyText = document.body.textContent;
            var ofMatch = bodyText.match(/of\s+(\d[\d\s,]*)\s*(?:result|propert|listing)/i);
            if (ofMatch) {
                var parsed2 = parseInt(ofMatch[1].replace(/[\s,]/g, ''), 10);
                if (parsed2 > 0 && parsed2 < 100000) { totalCount = parsed2; }
            }
        }

        // Strategy 3: "Browse X properties" or "showing X properties"
        if (!totalCount) {
            var browseMatch = (document.body.textContent).match(/(?:browse|showing|found)\s+(\d[\d\s,]*)\s*(?:propert|result|listing)/i);
            if (browseMatch) {
                var parsed3 = parseInt(browseMatch[1].replace(/[\s,]/g, ''), 10);
                if (parsed3 > 0 && parsed3 < 100000) { totalCount = parsed3; }
            }
        }

        if (totalCount) {
            result.extracted_fields.total_result_count = totalCount;
        }

        // ── Pagination detection ──
        var pagination = { current_page: 1, total_pages: 1, page_url_template: null };

        // P24 uses /pN in the URL path (NOT ?p=N query param)
        // Page 1: /advanced-search/results?sp=...
        // Page 2: /advanced-search/results/p2?sp=...
        var pathPageMatch = window.location.pathname.match(/\/p(\d+)(?:[?\/]|$)/);
        if (pathPageMatch) {
            pagination.current_page = parseInt(pathPageMatch[1], 10);
        } else {
            // Fallback: ?p=N query parameter (non-P24 sites)
            var urlParams = new URLSearchParams(window.location.search);
            var qPage = urlParams.get('p');
            if (qPage) pagination.current_page = parseInt(qPage, 10);
        }

        // Find max page from pagination links
        var maxPage = pagination.current_page;
        var pagingLinks = document.querySelectorAll(
            '.p24_paginateButton, .pagination a, [class*="paging"] a, [class*="Pagination"] a, nav[aria-label*="page"] a'
        );
        for (var pi = 0; pi < pagingLinks.length; pi++) {
            var pHref = pagingLinks[pi].getAttribute('href') || '';
            // P24 path-style: /pN before query string
            var pathMatch2 = pHref.match(/\/p(\d+)(?:[?\/]|$)/);
            if (pathMatch2) {
                var pNum = parseInt(pathMatch2[1], 10);
                if (pNum > maxPage) maxPage = pNum;
            }
            // Query-style fallback: ?p=N
            var queryMatch = pHref.match(/[?&]p=(\d+)/);
            if (queryMatch) {
                var pNum2 = parseInt(queryMatch[1], 10);
                if (pNum2 > maxPage) maxPage = pNum2;
            }
            // Text content fallback (page number in link text)
            var pText = pagingLinks[pi].textContent.trim();
            if (/^\d+$/.test(pText)) {
                var pTextNum = parseInt(pText, 10);
                if (pTextNum > maxPage) maxPage = pTextNum;
            }
        }
        pagination.total_pages = maxPage;

        // Build URL template with /p{page} in path (P24 pattern)
        // Strip any existing /pN from path to get base
        var basePath = window.location.pathname.replace(/\/p\d+(?=[?\/]|$)/, '');
        pagination.page_url_template = window.location.origin + basePath + '/p{page}' + window.location.search;

        if (pagination.total_pages > 1) {
            result.extracted_fields.pagination = pagination;
        }
    }

    // Return result to popup
    return result;
})();
