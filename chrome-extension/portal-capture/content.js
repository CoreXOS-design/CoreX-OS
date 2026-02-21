/**
 * Portal Capture — Content Script (injected on demand by popup)
 * Generic extraction for any property portal site.
 * Extractor version: portal_ext_v1
 */

(function () {
    'use strict';

    var result = {};

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

    // Page type detection (best-effort, deterministic)
    result.detected_page_type = 'unknown';
    var path = window.location.pathname.toLowerCase();
    var host = window.location.hostname.toLowerCase();

    // Property24 patterns
    if (host.includes('property24.com')) {
        if (/\/\d+$/.test(path)) {
            result.detected_page_type = 'property';
        } else if (path.includes('/for-sale/') || path.includes('/to-rent/') || path.includes('/properties/')) {
            result.detected_page_type = 'search';
        }
    }
    // PrivateProperty patterns
    else if (host.includes('privateproperty.co.za')) {
        if (/\/\d+$/.test(path) || path.includes('/property-detail')) {
            result.detected_page_type = 'property';
        } else if (path.includes('/for-sale') || path.includes('/to-rent') || path.includes('/results')) {
            result.detected_page_type = 'search';
        }
    }
    // Generic: if path ends in a number, likely a listing
    else if (/\/\d+\/?$/.test(path)) {
        result.detected_page_type = 'property';
    }

    // Return result to popup
    return result;
})();
