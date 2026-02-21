<?php

namespace App\Services\Presentations\Evidence\Parsers;

/**
 * Deterministic parser for a single Private Property listing page.
 * Returns 0 or 1 rows.
 */
class PrivatePropertyListingParserV1
{
    public const PARSER_VERSION = 'pp_listing_v1';
    public const SOURCE_TYPE    = 'private_property_listing';

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

            $price = $this->resolvePrice($data);
            if ($price === null) {
                continue;
            }

            $beds = null;
            foreach (['numberOfRooms', 'numberOfBedrooms', 'bedrooms', 'beds'] as $k) {
                if (isset($data[$k]) && is_numeric($data[$k])) {
                    $beds = (int)$data[$k];
                    break;
                }
            }

            $baths = null;
            foreach (['numberOfBathroomsTotal', 'bathrooms', 'baths'] as $k) {
                if (isset($data[$k]) && is_numeric($data[$k])) {
                    $baths = (int)$data[$k];
                    break;
                }
            }

            $sizeM2   = null;
            $sizeNode = $data['floorSize'] ?? null;
            if (is_array($sizeNode) && isset($sizeNode['value'])) {
                $v      = (int)$sizeNode['value'];
                $sizeM2 = ($v >= 10 && $v <= 99999) ? $v : null;
            }

            $suburb = null;
            $addr   = $data['address'] ?? $data['location'] ?? null;
            if (is_array($addr)) {
                $suburb = $addr['addressLocality'] ?? $addr['suburb'] ?? null;
            }

            return [[
                'list_price_inc' => $price,
                'beds'           => $beds,
                'baths'          => $baths,
                'size_m2'        => $sizeM2,
                'suburb'         => $suburb,
                'property_type'  => null,
                'listing_date'   => $data['datePosted'] ?? null,
                'external_id'    => $data['@id'] ?? null,
                'raw_data'       => $data,
            ]];
        }

        return [];
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

    // ── DOM fallback ──────────────────────────────────────────────────────────

    private function extractFromDom(string $html): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        $price = null;
        $priceNodes = $xpath->query(
            '//*[contains(@class,"price") or contains(@class,"listing-price") or contains(@class,"asking-price")]'
        );
        foreach ($priceNodes as $n) {
            $cleaned = preg_replace('/[^\d]/', '', $n->textContent);
            if (strlen($cleaned) >= 5) {
                $v = (int)$cleaned;
                if ($v >= 10000 && $v <= 999999999) {
                    $price = $v;
                    break;
                }
            }
        }

        if ($price === null) {
            return [];
        }

        $beds = null;
        $bedNodes = $xpath->query('//*[contains(@class,"bed")]');
        foreach ($bedNodes as $n) {
            if (preg_match('/\b(\d{1,2})\b/', $n->textContent, $m)) {
                $beds = (int)$m[1];
                break;
            }
        }

        $baths = null;
        $bathNodes = $xpath->query('//*[contains(@class,"bath")]');
        foreach ($bathNodes as $n) {
            if (preg_match('/\b(\d{1,2})\b/', $n->textContent, $m)) {
                $baths = (int)$m[1];
                break;
            }
        }

        $sizeM2 = null;
        $sizeNodes = $xpath->query('//*[contains(@class,"size") or contains(@class,"floor") or contains(@class,"area")]');
        foreach ($sizeNodes as $n) {
            if (preg_match('/(\d{2,5})\s*(?:m²|m2|sqm)/i', $n->textContent, $m)) {
                $v      = (int)$m[1];
                $sizeM2 = ($v >= 10 && $v <= 99999) ? $v : null;
                break;
            }
        }

        return [[
            'list_price_inc' => $price,
            'beds'           => $beds,
            'baths'          => $baths,
            'size_m2'        => $sizeM2,
            'suburb'         => null,
            'property_type'  => null,
            'listing_date'   => null,
            'external_id'    => null,
            'raw_data'       => ['source' => 'dom'],
        ]];
    }
}
