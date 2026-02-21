<?php

namespace App\Services\Presentations\Evidence\Parsers;

/**
 * Deterministic parser for Property24 search results pages.
 *
 * Extraction strategy (in priority order):
 *   1. JSON-LD blocks (<script type="application/ld+json">)
 *   2. DOM: listing card patterns (P24's React-rendered tiles, data attributes)
 *   3. Price-pattern scanning as last resort
 *
 * Returns normalised row arrays. No DB writes.
 * AI fallback is handled by UrlIngestionService, not here.
 */
class Property24SearchParserV1
{
    public const PARSER_VERSION = 'p24_search_v1';
    public const SOURCE_TYPE    = 'p24_search';

    /**
     * Parse HTML from a Property24 search results page.
     *
     * @return array<int, array<string, mixed>>
     */
    public function parseHtml(string $html): array
    {
        $rows = $this->extractFromJsonLd($html);

        if (count($rows) === 0) {
            $rows = $this->extractFromDom($html);
        }

        return $rows;
    }

    // ── JSON-LD extraction ────────────────────────────────────────────────────

    private function extractFromJsonLd(string $html): array
    {
        $rows   = [];
        $blocks = $this->collectJsonLdBlocks($html);

        foreach ($blocks as $data) {
            // Handle @graph wrapper (real P24 uses this)
            if (isset($data['@graph']) && is_array($data['@graph'])) {
                foreach ($data['@graph'] as $graphItem) {
                    if (!is_array($graphItem)) {
                        continue;
                    }
                    $rows = array_merge($rows, $this->processJsonLdBlock($graphItem));
                }
                continue;
            }

            $rows = array_merge($rows, $this->processJsonLdBlock($data));
        }

        return $rows;
    }

    private function processJsonLdBlock(array $data): array
    {
        $rows = [];

        // ItemList wrapper
        if (($data['@type'] ?? null) === 'ItemList' && isset($data['itemListElement'])) {
            foreach ((array)$data['itemListElement'] as $element) {
                $item = $element['item'] ?? $element;
                $row  = $this->mapJsonLdItem($item);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
            return $rows;
        }

        // Direct listing object or array of listings
        if (is_array($data) && isset($data[0])) {
            foreach ($data as $item) {
                $row = $this->mapJsonLdItem($item);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
            return $rows;
        }

        $row = $this->mapJsonLdItem($data);
        if ($row !== null) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Collect all JSON-LD script blocks from HTML, decoded.
     *
     * @return array<int, mixed>
     */
    private function collectJsonLdBlocks(string $html): array
    {
        $blocks = [];
        $dom    = new \DOMDocument();

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
                $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                $blocks[] = $decoded;
            } catch (\JsonException) {
                // Skip malformed blocks
            }
        }

        return $blocks;
    }

    /**
     * Map a single JSON-LD item to a row array, or null if not a listing.
     *
     * @return array<string, mixed>|null
     */
    private function mapJsonLdItem(mixed $item): ?array
    {
        if (!is_array($item)) {
            return null;
        }

        $type = $item['@type'] ?? null;

        // Accept common real-estate types
        $acceptedTypes = [
            'Product', 'Offer', 'RealEstateListing', 'Accommodation',
            'House', 'Apartment', 'SingleFamilyResidence', 'Residence',
        ];

        // Also accept items without a type if they have a price
        $hasPrice = isset($item['offers']['price'])
            || isset($item['offers']['priceSpecification']['price'])
            || isset($item['price'])
            || isset($item['priceRange']);

        if ($type !== null && !in_array($type, $acceptedTypes, true) && !$hasPrice) {
            return null;
        }

        $price  = $this->resolvePrice($item);
        $beds   = $this->resolveInt($item, ['numberOfRooms', 'numberOfBedrooms', 'bedrooms', 'beds']);
        $baths  = $this->resolveInt($item, ['numberOfBathroomsTotal', 'bathrooms', 'baths']);
        $sizeM2 = $this->resolveSize($item);
        $suburb = $this->resolveSuburb($item);

        // Handle nested "about" (P24 listing page puts details in about)
        $about = $item['about'] ?? null;
        if (is_array($about)) {
            if ($beds === null) {
                $beds = $this->resolveInt($about, ['numberOfRooms', 'numberOfBedrooms', 'bedrooms', 'beds']);
            }
            if ($baths === null) {
                $baths = $this->resolveInt($about, ['numberOfBathroomsTotal', 'bathrooms', 'baths']);
            }
            if ($sizeM2 === null) {
                $sizeM2 = $this->resolveSize($about);
            }
            if ($suburb === null) {
                $suburb = $this->resolveSuburb($about);
            }
        }

        // Require at least a price to include the row
        if ($price === null) {
            return null;
        }

        return [
            'list_price_inc' => $price,
            'beds'           => $beds,
            'baths'          => $baths,
            'size_m2'        => $sizeM2,
            'suburb'         => $suburb,
            'property_type'  => $this->resolvePropertyType($item) ?? ($about ? $this->resolvePropertyType($about) : null),
            'listing_date'   => null,
            'external_id'    => $item['productID'] ?? $item['@id'] ?? null,
            'raw_data'       => $item,
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

    private function resolveInt(array $item, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (isset($item[$key]) && is_numeric($item[$key])) {
                return (int)$item[$key];
            }
        }
        return null;
    }

    private function resolveSize(array $item): ?int
    {
        // floorSize.value
        $sizeNode = $item['floorSize'] ?? $item['floorplan'] ?? null;
        if (is_array($sizeNode) && isset($sizeNode['value'])) {
            $v = (int)$sizeNode['value'];
            return ($v >= 10 && $v <= 99999) ? $v : null;
        }
        // Direct numeric
        foreach (['floorSize', 'size_m2', 'sizeM2', 'erfSize'] as $key) {
            if (isset($item[$key]) && is_numeric($item[$key])) {
                $v = (int)$item[$key];
                return ($v >= 10 && $v <= 99999) ? $v : null;
            }
        }
        return null;
    }

    private function resolveSuburb(array $item): ?string
    {
        $addr = $item['address'] ?? $item['location'] ?? null;
        if (is_array($addr)) {
            return $addr['addressLocality'] ?? $addr['suburb'] ?? $addr['city'] ?? null;
        }
        if (is_string($addr)) {
            return $addr;
        }
        return null;
    }

    private function resolvePropertyType(array $item): ?string
    {
        $type = $item['@type'] ?? $item['propertyType'] ?? null;
        if ($type === null) {
            return null;
        }
        $type = strtolower((string)$type);
        if (str_contains($type, 'apartment') || str_contains($type, 'flat') || str_contains($type, 'unit')) {
            return 'unit';
        }
        if (str_contains($type, 'land') || str_contains($type, 'erf') || str_contains($type, 'plot')) {
            return 'land';
        }
        if (str_contains($type, 'house') || str_contains($type, 'residence') || str_contains($type, 'home')) {
            return 'house';
        }
        // Non-property schema types (RealEstateListing, Product, Offer, etc.) return null
        // so callers can fall through to nested "about" type
        $nonPropertyTypes = ['realestatelisting', 'product', 'offer', 'breadcrumblist', 'listitem'];
        if (in_array($type, $nonPropertyTypes, true)) {
            return null;
        }
        return 'other';
    }

    // ── DOM extraction ────────────────────────────────────────────────────────

    private function extractFromDom(string $html): array
    {
        $rows = [];
        $dom  = new \DOMDocument();

        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // Strategy 1: data-listing-number or data-listing-id elements (legacy P24)
        $listingNodes = $xpath->query(
            '//*[@data-listing-number] | //*[@data-listing-id] | //*[contains(@class,"listing-result")]'
        );

        if ($listingNodes->length > 0) {
            foreach ($listingNodes as $node) {
                $row = $this->domExtractCard($node, $xpath);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
            return $rows;
        }

        // Strategy 2: P24 tile containers (React-rendered pages)
        $tileNodes = $xpath->query(
            '//*[contains(@class,"p24_tileContainer")] | //*[contains(@class,"js_listingTile")] | //*[contains(@class,"p24_regularTile")]'
        );

        if ($tileNodes->length > 0) {
            foreach ($tileNodes as $node) {
                $row = $this->domExtractCard($node, $xpath);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
            return $rows;
        }

        // Strategy 3: Links that match P24 listing URL pattern — each = one listing
        $listingLinks = $xpath->query('//a[contains(@href,"/for-sale/") and contains(@href,"?")]');
        $seenUrls = [];

        foreach ($listingLinks as $linkNode) {
            $href = $linkNode->getAttribute('href');
            // Must look like a listing URL (has 6+ digit ID)
            if (!preg_match('#/(\d{6,})(?:\?|$)#', $href, $m)) {
                continue;
            }
            if (isset($seenUrls[$href])) {
                continue;
            }
            $seenUrls[$href] = true;

            $externalId = $m[1];
            $cardNode = $this->findCardAncestor($linkNode);

            if ($cardNode) {
                $row = $this->domExtractCard($cardNode, $xpath);
                if ($row !== null) {
                    if (empty($row['external_id'])) {
                        $row['external_id'] = $externalId;
                    }
                    $rows[] = $row;
                }
            }
        }

        // Strategy 4: Broad price pattern scanning (last resort)
        if (count($rows) === 0) {
            $rows = $this->extractFromPricePatterns($html);
        }

        return $rows;
    }

    private function findCardAncestor(\DOMElement $node): ?\DOMElement
    {
        $current = $node->parentNode;
        $depth = 0;
        while ($current && $depth < 6) {
            if ($current instanceof \DOMElement) {
                $class = $current->getAttribute('class');
                if (str_contains($class, 'tile') || str_contains($class, 'card')
                    || str_contains($class, 'listing') || str_contains($class, 'result')
                    || $current->tagName === 'article' || $current->tagName === 'li') {
                    return $current;
                }
            }
            $current = $current->parentNode;
            $depth++;
        }
        return null;
    }

    private function domExtractCard(\DOMElement $node, \DOMXPath $xpath): ?array
    {
        $price   = $this->domExtractPrice($node, $xpath);
        $beds    = $this->domExtractInt($node, $xpath, 'bed');
        $baths   = $this->domExtractInt($node, $xpath, 'bath');
        $sizeM2  = $this->domExtractSize($node, $xpath);
        $suburb  = $this->domExtractSuburb($node, $xpath);

        if ($price === null) {
            return null;
        }

        return [
            'list_price_inc' => $price,
            'beds'           => $beds,
            'baths'          => $baths,
            'size_m2'        => $sizeM2,
            'suburb'         => $suburb,
            'property_type'  => null,
            'listing_date'   => null,
            'external_id'    => $node->getAttribute('data-listing-number')
                ?: $node->getAttribute('data-listing-id')
                ?: null,
            'raw_data'       => ['source' => 'dom'],
        ];
    }

    private function domExtractPrice(\DOMElement $node, \DOMXPath $xpath): ?int
    {
        // data attribute first
        $dataPrice = $node->getAttribute('data-price');
        if ($dataPrice !== '' && is_numeric(str_replace([',', ' '], '', $dataPrice))) {
            $v = (int)str_replace([',', ' '], '', $dataPrice);
            if ($v >= 10000) {
                return $v;
            }
        }

        // Look for price text in child nodes (multiple class patterns)
        $priceNodes = $xpath->query(
            './/*[contains(@class,"price") or contains(@class,"p24_price")]',
            $node
        );
        foreach ($priceNodes as $pNode) {
            $v = $this->parsePriceText($pNode->textContent);
            if ($v !== null) {
                return $v;
            }
        }

        // Look for R X XXX XXX pattern in text
        $text = $node->textContent;
        if (preg_match('/R\s*[\d\s,]+/', $text, $m)) {
            $v = $this->parsePriceText($m[0]);
            if ($v !== null) {
                return $v;
            }
        }

        return null;
    }

    /**
     * Extract a number from the immediate next sibling text of an <img> element.
     */
    private function extractNumberNextToImg(\DOMElement $img): ?int
    {
        $sibling = $img->nextSibling;
        while ($sibling) {
            if ($sibling->nodeType === XML_TEXT_NODE) {
                $txt = trim($sibling->textContent);
                if ($txt !== '' && preg_match('/\b(\d{1,2})\b/', $txt, $m)) {
                    return (int)$m[1];
                }
            }
            if ($sibling->nodeType === XML_ELEMENT_NODE) {
                break;
            }
            $sibling = $sibling->nextSibling;
        }
        // If img is inside a <span>, use that span's text
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
        $text    = trim($text);
        $cleaned = preg_replace('/[^\d]/', '', $text);
        if ($cleaned !== '' && strlen($cleaned) >= 5) {
            $v = (int)$cleaned;
            if ($v >= 10000 && $v <= 999999999) {
                return $v;
            }
        }
        return null;
    }

    private function domExtractInt(\DOMElement $node, \DOMXPath $xpath, string $keyword): ?int
    {
        // Icon alt text pattern (real P24: <img alt="Bedrooms"> 4)
        $altKeyword = ucfirst($keyword);
        $imgNodes = $xpath->query('.//img[contains(@alt,"' . $altKeyword . '")]', $node);
        foreach ($imgNodes as $img) {
            $v = $this->extractNumberNextToImg($img);
            if ($v !== null) {
                return $v;
            }
        }

        // Icon src pattern (icon_bed_listing.svg, icon_bath_listing.svg)
        $iconNodes = $xpath->query('.//img[contains(@src,"icon_' . $keyword . '")]', $node);
        foreach ($iconNodes as $img) {
            $v = $this->extractNumberNextToImg($img);
            if ($v !== null) {
                return $v;
            }
        }

        // Class-based fallback
        $nodes = $xpath->query('.//*[contains(@class,"' . $keyword . '")]', $node);
        foreach ($nodes as $n) {
            $text = trim($n->textContent);
            if (preg_match('/\b(\d{1,2})\b/', $text, $m)) {
                return (int)$m[1];
            }
        }

        // Text pattern: "X Bedroom" or "X Bathroom"
        $fullText = $node->textContent;
        $patterns = [
            'bed' => '/(\d{1,2})\s*(?:bed|Bed)/i',
            'bath' => '/(\d{1,2})\s*(?:bath|Bath)/i',
        ];
        if (isset($patterns[$keyword]) && preg_match($patterns[$keyword], $fullText, $m)) {
            return (int)$m[1];
        }

        return null;
    }

    private function domExtractSize(\DOMElement $node, \DOMXPath $xpath): ?int
    {
        // Icon-based (icon_floor_new.svg, icon_erf_new.svg)
        $iconNodes = $xpath->query('.//img[contains(@src,"icon_floor") or contains(@src,"icon_erf") or contains(@alt,"Erf") or contains(@alt,"Floor")]', $node);
        foreach ($iconNodes as $img) {
            // Check next sibling text first (more precise)
            $sibling = $img->nextSibling;
            while ($sibling) {
                if ($sibling->nodeType === XML_TEXT_NODE) {
                    $txt = trim($sibling->textContent);
                    if ($txt !== '' && preg_match('/(\d[\d\s]*)\s*m[²2]/i', $txt, $m)) {
                        $v = (int)preg_replace('/\s/', '', $m[1]);
                        return ($v >= 10 && $v <= 99999) ? $v : null;
                    }
                }
                if ($sibling->nodeType === XML_ELEMENT_NODE) {
                    break;
                }
                $sibling = $sibling->nextSibling;
            }
            // Fallback: wrapping span
            if ($img->parentNode && $img->parentNode->nodeName === 'span') {
                $spanText = trim($img->parentNode->textContent);
                if (preg_match('/(\d[\d\s]*)\s*m[²2]/i', $spanText, $m)) {
                    $v = (int)preg_replace('/\s/', '', $m[1]);
                    return ($v >= 10 && $v <= 99999) ? $v : null;
                }
            }
        }

        // Class-based
        $nodes = $xpath->query('.//*[contains(@class,"size") or contains(@class,"floor") or contains(@class,"erf")]', $node);
        foreach ($nodes as $n) {
            $text = trim($n->textContent);
            if (preg_match('/(\d[\d\s]*)\s*(?:m²|m2|sqm)/i', $text, $m)) {
                $v = (int)preg_replace('/\s/', '', $m[1]);
                return ($v >= 10 && $v <= 99999) ? $v : null;
            }
        }

        return null;
    }

    private function domExtractSuburb(\DOMElement $node, \DOMXPath $xpath): ?string
    {
        $nodes = $xpath->query('.//*[contains(@class,"suburb") or contains(@class,"location") or contains(@class,"address")]', $node);
        foreach ($nodes as $n) {
            $text = trim($n->textContent);
            if ($text !== '' && strlen($text) <= 100) {
                return $text;
            }
        }
        return null;
    }

    /**
     * Last-resort extraction: find R X XXX XXX patterns across the page body.
     */
    private function extractFromPricePatterns(string $html): array
    {
        $rows = [];
        if (!preg_match_all('/R\s*([\d][\d\s,]{4,}[\d])/', $html, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $seen = [];
        foreach ($matches[0] as $match) {
            $priceText = $match[0];
            $offset = $match[1];
            $cleaned = (int)preg_replace('/[^\d]/', '', $priceText);

            if ($cleaned < 100000 || $cleaned > 999999999) {
                continue;
            }
            if (isset($seen[$cleaned])) {
                continue;
            }
            $seen[$cleaned] = true;

            // Look for bed/bath in nearby context (200 chars around)
            $context = substr($html, max(0, $offset - 200), 400);
            $beds = null;
            $baths = null;
            if (preg_match('/(\d{1,2})\s*(?:bed|Bed)/i', $context, $bm)) {
                $beds = (int)$bm[1];
            }
            if (preg_match('/(\d{1,2})\s*(?:bath|Bath)/i', $context, $bm)) {
                $baths = (int)$bm[1];
            }

            $rows[] = [
                'list_price_inc' => $cleaned,
                'beds'           => $beds,
                'baths'          => $baths,
                'size_m2'        => null,
                'suburb'         => null,
                'property_type'  => null,
                'listing_date'   => null,
                'external_id'    => null,
                'raw_data'       => ['source' => 'price_pattern'],
            ];
        }

        return $rows;
    }
}
