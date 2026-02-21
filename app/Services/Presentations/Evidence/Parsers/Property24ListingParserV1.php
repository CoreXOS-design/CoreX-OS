<?php

namespace App\Services\Presentations\Evidence\Parsers;

/**
 * Deterministic parser for a single Property24 listing page.
 *
 * Returns 0 or 1 rows. Extraction priority:
 *   1. JSON-LD (application/ld+json) — handles @graph wrapper and priceSpecification
 *   2. DOM structured property detail section (icon-based and class-based)
 *   3. Broad R X XXX XXX price pattern + text-based bed/bath
 */
class Property24ListingParserV1
{
    public const PARSER_VERSION = 'p24_listing_v1';
    public const SOURCE_TYPE    = 'p24_listing';

    /**
     * @return array<int, array<string, mixed>>  0 or 1 elements
     */
    public function parseHtml(string $html): array
    {
        $rows = $this->extractFromJsonLd($html);

        if (count($rows) === 0) {
            $rows = $this->extractFromDom($html);
        }

        return $rows;
    }

    // ── JSON-LD ───────────────────────────────────────────────────────────────

    private function extractFromJsonLd(string $html): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath   = new \DOMXPath($dom);
        $scripts = $xpath->query('//script[@type="application/ld+json"]');

        foreach ($scripts as $script) {
            $json = trim($script->textContent);
            if ($json === '') {
                continue;
            }
            try {
                $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            // Handle @graph wrapper (real P24 uses this)
            if (isset($data['@graph']) && is_array($data['@graph'])) {
                foreach ($data['@graph'] as $graphItem) {
                    if (!is_array($graphItem)) {
                        continue;
                    }
                    $row = $this->mapItem($graphItem);
                    if ($row !== null) {
                        return [$row];
                    }
                }
                continue;
            }

            $row = $this->mapItem($data);
            if ($row !== null) {
                return [$row];
            }
        }

        return [];
    }

    private function mapItem(mixed $data): ?array
    {
        if (!is_array($data)) {
            return null;
        }

        $price = $this->resolvePrice($data);
        if ($price === null) {
            return null;
        }

        $beds   = null;
        $baths  = null;
        $sizeM2 = null;
        $suburb = null;

        // Direct keys
        foreach (['numberOfRooms', 'numberOfBedrooms', 'bedrooms', 'beds'] as $k) {
            if (isset($data[$k]) && is_numeric($data[$k])) {
                $beds = (int)$data[$k];
                break;
            }
        }
        foreach (['numberOfBathroomsTotal', 'bathrooms', 'baths'] as $k) {
            if (isset($data[$k]) && is_numeric($data[$k])) {
                $baths = (int)$data[$k];
                break;
            }
        }

        // Handle nested "about" (real P24 puts property details in about)
        $about = $data['about'] ?? null;
        if (is_array($about)) {
            if ($beds === null) {
                foreach (['numberOfRooms', 'numberOfBedrooms', 'bedrooms', 'beds'] as $k) {
                    if (isset($about[$k]) && is_numeric($about[$k])) {
                        $beds = (int)$about[$k];
                        break;
                    }
                }
            }
            if ($baths === null) {
                foreach (['numberOfBathroomsTotal', 'bathrooms', 'baths'] as $k) {
                    if (isset($about[$k]) && is_numeric($about[$k])) {
                        $baths = (int)$about[$k];
                        break;
                    }
                }
            }

            // Size from about
            $sizeNode = $about['floorSize'] ?? null;
            if (is_array($sizeNode) && isset($sizeNode['value'])) {
                $v = (int)$sizeNode['value'];
                $sizeM2 = ($v >= 10 && $v <= 99999) ? $v : null;
            } elseif (is_numeric($sizeNode)) {
                $v = (int)$sizeNode;
                $sizeM2 = ($v >= 10 && $v <= 99999) ? $v : null;
            }

            // Suburb from about.address
            $addr = $about['address'] ?? $about['location'] ?? null;
            if (is_array($addr)) {
                $suburb = $addr['addressLocality'] ?? $addr['suburb'] ?? null;
            }
        }

        // Size from top-level
        if ($sizeM2 === null) {
            $sizeNode = $data['floorSize'] ?? null;
            if (is_array($sizeNode) && isset($sizeNode['value'])) {
                $v      = (int)$sizeNode['value'];
                $sizeM2 = ($v >= 10 && $v <= 99999) ? $v : null;
            } elseif (is_numeric($sizeNode)) {
                $v      = (int)$sizeNode;
                $sizeM2 = ($v >= 10 && $v <= 99999) ? $v : null;
            }
        }

        // Suburb from top-level
        if ($suburb === null) {
            $addr = $data['address'] ?? $data['location'] ?? null;
            if (is_array($addr)) {
                $suburb = $addr['addressLocality'] ?? $addr['suburb'] ?? null;
            }
        }

        // Property type from about or top-level
        $propType = $this->resolvePropertyType($data);
        if ($propType === null && is_array($about)) {
            $propType = $this->resolvePropertyType($about);
        }

        return [
            'list_price_inc' => $price,
            'beds'           => $beds,
            'baths'          => $baths,
            'size_m2'        => $sizeM2,
            'suburb'         => $suburb,
            'property_type'  => $propType,
            'listing_date'   => $data['datePosted'] ?? $data['availabilityStarts'] ?? null,
            'external_id'    => $data['productID'] ?? $data['@id'] ?? null,
            'raw_data'       => $data,
        ];
    }

    private function resolvePrice(array $item): ?int
    {
        // offers.priceSpecification.price (real P24 format)
        $priceSpec = $item['offers']['priceSpecification']['price'] ?? null;
        if ($priceSpec !== null) {
            $cleaned = (float)str_replace([',', ' ', 'R'], '', (string)$priceSpec);
            if ($cleaned >= 10000) {
                return (int)$cleaned;
            }
        }

        // offers.price
        $price = $item['offers']['price'] ?? $item['price'] ?? null;
        if ($price !== null) {
            $cleaned = (float)str_replace([',', ' ', 'R'], '', (string)$price);
            if ($cleaned >= 10000) {
                return (int)$cleaned;
            }
        }

        return null;
    }

    private function resolvePropertyType(array $item): ?string
    {
        $type = strtolower((string)($item['@type'] ?? $item['propertyType'] ?? ''));
        if ($type === '') {
            return null;
        }
        if (str_contains($type, 'apartment') || str_contains($type, 'flat') || str_contains($type, 'unit')) {
            return 'unit';
        }
        if (str_contains($type, 'land') || str_contains($type, 'erf') || str_contains($type, 'plot')) {
            return 'land';
        }
        if (str_contains($type, 'house') || str_contains($type, 'residence') || str_contains($type, 'home')) {
            return 'house';
        }
        // Non-property schema types return null so callers fall through to nested "about" type
        $nonPropertyTypes = ['realestatelisting', 'product', 'offer', 'breadcrumblist', 'listitem'];
        if (in_array($type, $nonPropertyTypes, true)) {
            return null;
        }
        return 'other';
    }

    // ── DOM fallback ──────────────────────────────────────────────────────────

    private function extractFromDom(string $html): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // ── Price ──────────────────────────────────────────────────────────
        $price = null;

        // P24 price class
        $priceNodes = $xpath->query('//*[contains(@class,"price") or contains(@class,"p24_price")]');
        foreach ($priceNodes as $n) {
            $v = $this->parsePriceText($n->textContent);
            if ($v !== null) {
                $price = $v;
                break;
            }
        }

        // Fallback: R X XXX XXX in <h1> or heading
        if ($price === null) {
            $headings = $xpath->query('//h1 | //h2');
            foreach ($headings as $h) {
                $v = $this->parsePriceText($h->textContent);
                if ($v !== null) {
                    $price = $v;
                    break;
                }
            }
        }

        // Last resort: first R X XXX XXX in body
        if ($price === null && preg_match('/R\s*([\d][\d\s,]{4,}[\d])/', $html, $m)) {
            $price = $this->parsePriceText($m[0]);
        }

        if ($price === null) {
            return [];
        }

        // ── Beds ───────────────────────────────────────────────────────────
        $beds = null;

        // Icon alt text pattern (real P24: <img alt="Bedrooms"> 4)
        $bedImgs = $xpath->query('//img[contains(@alt,"Bedroom") or contains(@src,"icon_bed")]');
        foreach ($bedImgs as $img) {
            $beds = $this->extractNumberNextToImg($img);
            if ($beds !== null) {
                break;
            }
        }

        if ($beds === null) {
            $bedNodes = $xpath->query('//*[contains(@class,"bed")]');
            foreach ($bedNodes as $n) {
                if (preg_match('/\b(\d{1,2})\b/', $n->textContent, $m)) {
                    $beds = (int)$m[1];
                    break;
                }
            }
        }

        // Text pattern fallback
        if ($beds === null && preg_match('/(\d{1,2})\s*(?:bed|Bed)/i', $html, $m)) {
            $beds = (int)$m[1];
        }

        // ── Baths ──────────────────────────────────────────────────────────
        $baths = null;

        $bathImgs = $xpath->query('//img[contains(@alt,"Bathroom") or contains(@src,"icon_bath")]');
        foreach ($bathImgs as $img) {
            $baths = $this->extractNumberNextToImg($img);
            if ($baths !== null) {
                break;
            }
        }

        if ($baths === null) {
            $bathNodes = $xpath->query('//*[contains(@class,"bath")]');
            foreach ($bathNodes as $n) {
                if (preg_match('/\b(\d{1,2})\b/', $n->textContent, $m)) {
                    $baths = (int)$m[1];
                    break;
                }
            }
        }

        if ($baths === null && preg_match('/(\d{1,2})\s*(?:bath|Bath)/i', $html, $m)) {
            $baths = (int)$m[1];
        }

        // ── Size ───────────────────────────────────────────────────────────
        $sizeM2 = null;

        $sizeImgs = $xpath->query('//img[contains(@src,"icon_floor") or contains(@src,"icon_erf") or contains(@alt,"Erf") or contains(@alt,"Floor")]');
        foreach ($sizeImgs as $img) {
            if ($img->parentNode) {
                $parentText = trim($img->parentNode->textContent);
                if (preg_match('/(\d[\d\s]*)\s*m[²2]/i', $parentText, $m)) {
                    $v = (int)str_replace(' ', '', $m[1]);
                    if ($v >= 10 && $v <= 99999) {
                        $sizeM2 = $v;
                        break;
                    }
                }
            }
        }

        if ($sizeM2 === null) {
            $sizeNodes = $xpath->query('//*[contains(@class,"size") or contains(@class,"floor") or contains(@class,"erf")]');
            foreach ($sizeNodes as $n) {
                if (preg_match('/(\d[\d\s]*)\s*(?:m²|m2|sqm)/i', $n->textContent, $m)) {
                    $v      = (int)str_replace(' ', '', $m[1]);
                    $sizeM2 = ($v >= 10 && $v <= 99999) ? $v : null;
                    break;
                }
            }
        }

        // Broad text scan
        if ($sizeM2 === null && preg_match('/(\d[\d\s]*)\s*m[²2]/i', $html, $m)) {
            $v = (int)str_replace(' ', '', $m[1]);
            $sizeM2 = ($v >= 10 && $v <= 99999) ? $v : null;
        }

        // ── Suburb ─────────────────────────────────────────────────────────
        $suburb = null;
        $suburbNodes = $xpath->query('//*[contains(@class,"suburb") or contains(@class,"location") or contains(@class,"address")]');
        foreach ($suburbNodes as $n) {
            $text = trim($n->textContent);
            if ($text !== '' && strlen($text) <= 100) {
                $suburb = $text;
                break;
            }
        }

        // ── Listing ID from JS context ─────────────────────────────────────
        $externalId = null;
        if (preg_match('/"listingNumber"\s*:\s*\{\s*"number"\s*:\s*(\d+)/', $html, $m)) {
            $externalId = $m[1];
        }

        return [[
            'list_price_inc' => $price,
            'beds'           => $beds,
            'baths'          => $baths,
            'size_m2'        => $sizeM2,
            'suburb'         => $suburb,
            'property_type'  => null,
            'listing_date'   => null,
            'external_id'    => $externalId,
            'raw_data'       => ['source' => 'dom'],
        ]];
    }

    /**
     * Extract a number from the immediate next sibling text of an <img> element.
     * Falls back to the wrapping <span> if the img is inside one.
     */
    private function extractNumberNextToImg(\DOMElement $img): ?int
    {
        // Check immediate next sibling text node
        $sibling = $img->nextSibling;
        while ($sibling) {
            if ($sibling->nodeType === XML_TEXT_NODE) {
                $txt = trim($sibling->textContent);
                if ($txt !== '' && preg_match('/\b(\d{1,2})\b/', $txt, $m)) {
                    return (int)$m[1];
                }
            }
            // Stop at the next element (another img, span, etc.)
            if ($sibling->nodeType === XML_ELEMENT_NODE) {
                break;
            }
            $sibling = $sibling->nextSibling;
        }

        // If img is inside a <span>, use that span's text (more precise than parent div)
        if ($img->parentNode && $img->parentNode->nodeName === 'span') {
            $spanText = trim($img->parentNode->textContent);
            if (preg_match('/\b(\d{1,2})\b/', $spanText, $m)) {
                return (int)$m[1];
            }
        }

        return null;
    }

    private function parsePriceText(string $text): ?int
    {
        $text = trim($text);
        // Look for "R X XXX XXX" pattern
        if (preg_match('/R\s*([\d][\d\s,]+)/', $text, $m)) {
            $cleaned = (int)preg_replace('/[^\d]/', '', $m[1]);
            if ($cleaned >= 10000 && $cleaned <= 999999999) {
                return $cleaned;
            }
        }
        // Fallback: just strip non-digits
        $cleaned = preg_replace('/[^\d]/', '', $text);
        if ($cleaned !== '' && strlen($cleaned) >= 5) {
            $v = (int)$cleaned;
            if ($v >= 10000 && $v <= 999999999) {
                return $v;
            }
        }
        return null;
    }
}
