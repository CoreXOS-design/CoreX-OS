<?php

namespace App\Services\Presentations\Evidence\Parsers;

/**
 * Deterministic parser for Private Property search results pages.
 *
 * Uses the same strategy as Property24SearchParserV1:
 *   1. JSON-LD blocks
 *   2. DOM fallback (PP-specific class patterns)
 */
class PrivatePropertySearchParserV1
{
    public const PARSER_VERSION = 'pp_search_v1';
    public const SOURCE_TYPE    = 'private_property_search';

    /**
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

    // ── JSON-LD ───────────────────────────────────────────────────────────────

    private function extractFromJsonLd(string $html): array
    {
        $rows   = [];
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
                $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            // ItemList wrapper
            if (($data['@type'] ?? null) === 'ItemList' && isset($data['itemListElement'])) {
                foreach ((array)$data['itemListElement'] as $element) {
                    $item = $element['item'] ?? $element;
                    $row  = $this->mapItem($item);
                    if ($row !== null) {
                        $rows[] = $row;
                    }
                }
                continue;
            }

            // Array of listings
            if (isset($data[0]) && is_array($data[0])) {
                foreach ($data as $item) {
                    $row = $this->mapItem($item);
                    if ($row !== null) {
                        $rows[] = $row;
                    }
                }
                continue;
            }

            $row = $this->mapItem($data);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function mapItem(mixed $item): ?array
    {
        if (!is_array($item)) {
            return null;
        }

        $price = $this->resolvePrice($item);
        if ($price === null) {
            return null;
        }

        $beds   = null;
        $baths  = null;
        $sizeM2 = null;
        $suburb = null;

        foreach (['numberOfRooms', 'numberOfBedrooms', 'bedrooms', 'beds'] as $k) {
            if (isset($item[$k]) && is_numeric($item[$k])) {
                $beds = (int)$item[$k];
                break;
            }
        }
        foreach (['numberOfBathroomsTotal', 'bathrooms', 'baths'] as $k) {
            if (isset($item[$k]) && is_numeric($item[$k])) {
                $baths = (int)$item[$k];
                break;
            }
        }

        $sizeNode = $item['floorSize'] ?? null;
        if (is_array($sizeNode) && isset($sizeNode['value'])) {
            $v      = (int)$sizeNode['value'];
            $sizeM2 = ($v >= 10 && $v <= 99999) ? $v : null;
        } elseif (is_numeric($sizeNode)) {
            $v      = (int)$sizeNode;
            $sizeM2 = ($v >= 10 && $v <= 99999) ? $v : null;
        }

        $addr = $item['address'] ?? $item['location'] ?? null;
        if (is_array($addr)) {
            $suburb = $addr['addressLocality'] ?? $addr['suburb'] ?? $addr['city'] ?? null;
        } elseif (is_string($addr)) {
            $suburb = $addr;
        }

        return [
            'list_price_inc' => $price,
            'beds'           => $beds,
            'baths'          => $baths,
            'size_m2'        => $sizeM2,
            'suburb'         => $suburb,
            'property_type'  => $this->resolvePropertyType($item),
            'listing_date'   => null,
            'external_id'    => $item['@id'] ?? null,
            'raw_data'       => $item,
        ];
    }

    private function resolvePrice(array $item): ?int
    {
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
        if (str_contains($type, 'apartment') || str_contains($type, 'flat') || str_contains($type, 'unit')) {
            return 'unit';
        }
        if (str_contains($type, 'land') || str_contains($type, 'erf') || str_contains($type, 'plot')) {
            return 'land';
        }
        if (str_contains($type, 'house') || str_contains($type, 'residence') || str_contains($type, 'home')) {
            return 'house';
        }
        return null;
    }

    // ── DOM fallback (Private Property class conventions) ─────────────────────

    private function extractFromDom(string $html): array
    {
        $rows = [];
        $dom  = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // PP listing cards use classes like 'listing-card' or 'property-card'
        $cardNodes = $xpath->query(
            '//*[contains(@class,"listing-card") or contains(@class,"property-card") or contains(@class,"listing__")]'
        );

        foreach ($cardNodes as $node) {
            $price  = $this->domPrice($node, $xpath);
            if ($price === null) {
                continue;
            }

            $rows[] = [
                'list_price_inc' => $price,
                'beds'           => $this->domInt($node, $xpath, ['bed']),
                'baths'          => $this->domInt($node, $xpath, ['bath']),
                'size_m2'        => $this->domSize($node, $xpath),
                'suburb'         => $this->domText($node, $xpath, ['suburb', 'location', 'address']),
                'property_type'  => null,
                'listing_date'   => null,
                'external_id'    => null,
                'raw_data'       => ['source' => 'dom'],
            ];
        }

        return $rows;
    }

    private function domPrice(\DOMElement $node, \DOMXPath $xpath): ?int
    {
        $nodes = $xpath->query('.//*[contains(@class,"price")]', $node);
        foreach ($nodes as $n) {
            $cleaned = preg_replace('/[^\d]/', '', $n->textContent);
            if (strlen($cleaned) >= 5) {
                $v = (int)$cleaned;
                if ($v >= 10000 && $v <= 999999999) {
                    return $v;
                }
            }
        }
        return null;
    }

    private function domInt(\DOMElement $node, \DOMXPath $xpath, array $keywords): ?int
    {
        foreach ($keywords as $kw) {
            $nodes = $xpath->query('.//*[contains(@class,"' . $kw . '")]', $node);
            foreach ($nodes as $n) {
                if (preg_match('/\b(\d{1,2})\b/', $n->textContent, $m)) {
                    return (int)$m[1];
                }
            }
        }
        return null;
    }

    private function domSize(\DOMElement $node, \DOMXPath $xpath): ?int
    {
        $nodes = $xpath->query('.//*[contains(@class,"size") or contains(@class,"floor") or contains(@class,"area")]', $node);
        foreach ($nodes as $n) {
            if (preg_match('/(\d{2,5})\s*(?:m²|m2|sqm)/i', $n->textContent, $m)) {
                $v = (int)$m[1];
                return ($v >= 10 && $v <= 99999) ? $v : null;
            }
        }
        return null;
    }

    private function domText(\DOMElement $node, \DOMXPath $xpath, array $keywords): ?string
    {
        foreach ($keywords as $kw) {
            $nodes = $xpath->query('.//*[contains(@class,"' . $kw . '")]', $node);
            foreach ($nodes as $n) {
                $text = trim($n->textContent);
                if ($text !== '' && strlen($text) <= 100) {
                    return $text;
                }
            }
        }
        return null;
    }
}
