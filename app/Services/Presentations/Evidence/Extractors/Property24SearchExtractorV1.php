<?php

namespace App\Services\Presentations\Evidence\Extractors;

use App\Models\PortalCapture;
use Illuminate\Support\Facades\Storage;

/**
 * Deterministic DOM extractor for Property24 search result captures.
 *
 * Reads stored HTML from portal_captures disk path.
 * Extracts listing cards via DOMDocument + XPath only (no AI, no heuristics).
 * Populates portal_captures.extracted_fields_json with the search schema.
 */
class Property24SearchExtractorV1
{
    public const VERSION = 'p24_search_dom_v1';

    /**
     * Run extraction on a portal capture record.
     * Returns the structured extraction result, or null on failure.
     */
    public function extract(PortalCapture $capture): ?array
    {
        $html = $this->loadHtml($capture);

        if ($html === null || trim($html) === '') {
            return null;
        }

        $items = $this->extractItems($html);

        // Deduplicate by portal_listing_id
        $items = $this->deduplicateItems($items);

        $result = [
            'search' => [
                'items_on_page' => count($items),
                'total_count'   => $this->extractTotalCount($html),
                'items'         => $items,
            ],
            '_extraction' => [
                'extractor' => self::VERSION,
                'source'    => 'stored_html',
            ],
        ];

        return $result;
    }

    private function loadHtml(PortalCapture $capture): ?string
    {
        if (empty($capture->raw_html_path)) {
            return null;
        }

        if (!Storage::disk('local')->exists($capture->raw_html_path)) {
            return null;
        }

        return Storage::disk('local')->get($capture->raw_html_path);
    }

    /**
     * Extract all listing items from the HTML.
     *
     * @return array<int, array>
     */
    private function extractItems(string $html): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $items = [];

        // Strategy 1: p24_regularTile / p24_tileContainer with data-listing-number
        $tiles = $xpath->query(
            '//*[contains(@class,"p24_regularTile") or contains(@class,"p24_tileContainer") or contains(@class,"js_listingTile")]'
        );

        if ($tiles->length > 0) {
            foreach ($tiles as $tile) {
                $item = $this->extractFromTile($tile, $xpath);
                if ($item !== null) {
                    $items[] = $item;
                }
            }
            return $items;
        }

        // Strategy 2: data-listing-number attribute on any element
        $listingNodes = $xpath->query('//*[@data-listing-number]');
        if ($listingNodes->length > 0) {
            foreach ($listingNodes as $node) {
                $item = $this->extractFromTile($node, $xpath);
                if ($item !== null) {
                    $items[] = $item;
                }
            }
            return $items;
        }

        // Strategy 3: Links matching P24 listing URL pattern
        $links = $xpath->query('//a[contains(@href,"/for-sale/")]');
        $seenIds = [];
        foreach ($links as $linkNode) {
            $href = $linkNode->getAttribute('href');
            if (!preg_match('#/(\d{6,})(?:\?|$)#', $href, $m)) {
                continue;
            }
            $listingId = $m[1];
            if (isset($seenIds[$listingId])) {
                continue;
            }
            $seenIds[$listingId] = true;

            $cardNode = $this->findCardAncestor($linkNode);
            if ($cardNode) {
                $item = $this->extractFromTile($cardNode, $xpath, $listingId, $href);
                if ($item !== null) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    /**
     * Extract a single listing item from a tile DOM element.
     */
    private function extractFromTile(
        \DOMElement $tile,
        \DOMXPath $xpath,
        ?string $fallbackId = null,
        ?string $fallbackHref = null
    ): ?array {
        // Portal listing ID
        $listingId = $tile->getAttribute('data-listing-number')
            ?: $tile->getAttribute('data-listing-id')
            ?: $fallbackId;

        // Try to find listing URL from <a> inside the tile
        $url = $fallbackHref;
        if ($url === null) {
            $linkNodes = $xpath->query('.//a[contains(@href,"/for-sale/")]', $tile);
            if ($linkNodes->length > 0) {
                $url = $linkNodes->item(0)->getAttribute('href');
            }
        }

        // Extract portal_listing_id from URL if not already known
        if ($listingId === null && $url !== null) {
            if (preg_match('#/(\d{6,})(?:\?|$)#', $url, $m)) {
                $listingId = $m[1];
            }
        }

        // Must have at least a listing ID
        if ($listingId === null || $listingId === '') {
            return null;
        }

        // Normalize URL to absolute, strip query params
        $normalizedUrl = $this->normalizeUrl($url);

        // Extract price
        $price = $this->extractPrice($tile, $xpath);

        // Extract title
        $title = $this->extractTitle($tile, $xpath);

        // Extract image
        $image = $this->extractImage($tile, $xpath);

        // Extract feature fields
        $beds = $this->extractIntByIcon($tile, $xpath, 'bed', 'Bedrooms');
        $baths = $this->extractIntByIcon($tile, $xpath, 'bath', 'Bathrooms');
        $parking = $this->extractIntByIcon($tile, $xpath, 'parking', 'Parking');
        $sizeM2 = $this->extractSize($tile, $xpath, 'floor');
        $erfM2 = $this->extractSize($tile, $xpath, 'erf');

        return [
            'portal_listing_id' => $listingId,
            'url'               => $normalizedUrl,
            'price'             => $price,
            'currency'          => $price !== null ? 'ZAR' : null,
            'beds'              => $beds,
            'baths'             => $baths,
            'parking'           => $parking,
            'size_m2'           => $sizeM2,
            'erf_m2'            => $erfM2,
            'title'             => $title,
            'image'             => $image,
        ];
    }

    private function extractPrice(\DOMElement $tile, \DOMXPath $xpath): ?int
    {
        // data-price attribute
        $dataPrice = $tile->getAttribute('data-price');
        if ($dataPrice !== '' && is_numeric(str_replace([',', ' '], '', $dataPrice))) {
            $v = (int) str_replace([',', ' '], '', $dataPrice);
            if ($v >= 10000) {
                return $v;
            }
        }

        // p24_price class
        $priceNodes = $xpath->query('.//*[contains(@class,"p24_price") or contains(@class,"price")]', $tile);
        foreach ($priceNodes as $pNode) {
            $v = $this->parsePriceText($pNode->textContent);
            if ($v !== null) {
                return $v;
            }
        }

        // Fallback: R X XXX XXX in tile text
        $text = $tile->textContent;
        if (preg_match('/R\s*[\d\s,]+/', $text, $m)) {
            return $this->parsePriceText($m[0]);
        }

        return null;
    }

    private function parsePriceText(string $text): ?int
    {
        $cleaned = preg_replace('/[^\d]/', '', trim($text));
        if ($cleaned !== '' && strlen($cleaned) >= 5) {
            $v = (int) $cleaned;
            if ($v >= 10000 && $v <= 999999999) {
                return $v;
            }
        }
        return null;
    }

    private function extractTitle(\DOMElement $tile, \DOMXPath $xpath): ?string
    {
        $titleNodes = $xpath->query('.//*[contains(@class,"p24_title") or contains(@class,"title")]', $tile);
        foreach ($titleNodes as $node) {
            $text = trim($node->textContent);
            if ($text !== '' && strlen($text) <= 500) {
                return $text;
            }
        }
        return null;
    }

    private function extractImage(\DOMElement $tile, \DOMXPath $xpath): ?string
    {
        $imgNodes = $xpath->query('.//img[contains(@class,"p24_tileImage") or contains(@class,"listing-image") or @data-src]', $tile);
        foreach ($imgNodes as $img) {
            $src = $img->getAttribute('data-src') ?: $img->getAttribute('src');
            if ($src !== '' && !str_contains($src, 'icon_')) {
                return $src;
            }
        }
        return null;
    }

    /**
     * Extract integer value next to an icon (beds, baths, parking).
     */
    private function extractIntByIcon(
        \DOMElement $tile,
        \DOMXPath $xpath,
        string $iconKeyword,
        string $altKeyword
    ): ?int {
        // Icon src pattern
        $imgNodes = $xpath->query('.//img[contains(@src,"icon_' . $iconKeyword . '")]', $tile);
        foreach ($imgNodes as $img) {
            $v = $this->extractNumberNextToImg($img);
            if ($v !== null) {
                return $v;
            }
        }

        // Icon alt text pattern
        $imgNodes = $xpath->query('.//img[contains(@alt,"' . $altKeyword . '")]', $tile);
        foreach ($imgNodes as $img) {
            $v = $this->extractNumberNextToImg($img);
            if ($v !== null) {
                return $v;
            }
        }

        // Class-based fallback
        $nodes = $xpath->query('.//*[contains(@class,"' . $iconKeyword . '")]', $tile);
        foreach ($nodes as $n) {
            $text = trim($n->textContent);
            if (preg_match('/\b(\d{1,2})\b/', $text, $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    private function extractNumberNextToImg(\DOMElement $img): ?int
    {
        $sibling = $img->nextSibling;
        while ($sibling) {
            if ($sibling->nodeType === XML_TEXT_NODE) {
                $txt = trim($sibling->textContent);
                if ($txt !== '' && preg_match('/\b(\d{1,2})\b/', $txt, $m)) {
                    return (int) $m[1];
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
                return (int) $m[1];
            }
        }
        return null;
    }

    /**
     * Extract size (floor or erf) in m².
     */
    private function extractSize(\DOMElement $tile, \DOMXPath $xpath, string $keyword): ?int
    {
        $imgNodes = $xpath->query(
            './/img[contains(@src,"icon_' . $keyword . '") or contains(@alt,"' . ucfirst($keyword) . '")]',
            $tile
        );
        foreach ($imgNodes as $img) {
            $sibling = $img->nextSibling;
            while ($sibling) {
                if ($sibling->nodeType === XML_TEXT_NODE) {
                    $txt = trim($sibling->textContent);
                    if ($txt !== '' && preg_match('/(\d[\d\s]*)\s*m[²2]/i', $txt, $m)) {
                        $v = (int) preg_replace('/\s/', '', $m[1]);
                        return ($v >= 1 && $v <= 99999) ? $v : null;
                    }
                }
                if ($sibling->nodeType === XML_ELEMENT_NODE) {
                    break;
                }
                $sibling = $sibling->nextSibling;
            }
            // Wrapping span fallback
            if ($img->parentNode && $img->parentNode->nodeName === 'span') {
                $spanText = trim($img->parentNode->textContent);
                if (preg_match('/(\d[\d\s]*)\s*m[²2]/i', $spanText, $m)) {
                    $v = (int) preg_replace('/\s/', '', $m[1]);
                    return ($v >= 1 && $v <= 99999) ? $v : null;
                }
            }
        }

        return null;
    }

    /**
     * Normalize a relative P24 URL to absolute.
     */
    private function normalizeUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        // Strip query string
        $url = strtok($url, '?');

        // If relative, make absolute
        if (str_starts_with($url, '/')) {
            $url = 'https://www.property24.com' . $url;
        }

        return rtrim($url, '/');
    }

    /**
     * Deduplicate items by portal_listing_id (keep first occurrence).
     */
    private function deduplicateItems(array $items): array
    {
        $seen = [];
        $result = [];
        foreach ($items as $item) {
            $id = $item['portal_listing_id'] ?? null;
            if ($id === null || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $result[] = $item;
        }
        return $result;
    }

    /**
     * Try to extract total results count from the page.
     *
     * P24 patterns:
     *   - "Showing 1 - 20 of 48"
     *   - "48 Properties" / "48 properties for sale"
     *   - "Browse 48 properties"
     *   - "Results 1 – 20 of 48"
     */
    private function extractTotalCount(string $html): ?int
    {
        // Strategy 1: "of X" pattern from pagination text (most specific)
        if (preg_match('/(?:showing|results)[^<]{0,40}of\s+(\d[\d\s,]*)/i', $html, $m)) {
            $v = (int) preg_replace('/[^\d]/', '', $m[1]);
            if ($v > 0 && $v < 100000) return $v;
        }

        // Strategy 2: "X Properties" or "X properties for sale"
        if (preg_match('/(\d[\d\s,]*)\s*(?:propert(?:y|ies)|results?\s*(?:found|for\s*sale))/i', $html, $m)) {
            $v = (int) preg_replace('/[^\d]/', '', $m[1]);
            if ($v > 0 && $v < 100000) return $v;
        }

        // Strategy 3: "Browse X properties" / "showing X properties"
        if (preg_match('/(?:browse|found|showing)\s+(\d[\d\s,]*)\s*(?:propert|listing|result)/i', $html, $m)) {
            $v = (int) preg_replace('/[^\d]/', '', $m[1]);
            if ($v > 0 && $v < 100000) return $v;
        }

        // Strategy 4: generic "of X" (broader fallback)
        if (preg_match('/of\s+(\d[\d\s,]*)/i', $html, $m)) {
            $v = (int) preg_replace('/[^\d]/', '', $m[1]);
            if ($v > 0 && $v < 100000) return $v;
        }

        return null;
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
}
