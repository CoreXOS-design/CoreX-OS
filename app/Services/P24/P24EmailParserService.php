<?php

namespace App\Services\P24;

class P24EmailParserService
{
    /**
     * Parse email body (HTML or plain text) and extract property listings.
     *
     * @return array<int, array{
     *     p24_listing_number: string,
     *     asking_price: float|null,
     *     property_type: string|null,
     *     suburb: string|null,
     *     bedrooms: int|null,
     *     bathrooms: int|null,
     *     garages: int|null,
     *     is_mandated: bool,
     *     p24_url: string|null,
     * }>
     */
    public function parse(string $body, string $subject = ''): array
    {
        $listings = [];

        // Extract listing numbers from URLs: listingNumber=P24-XXXXXXXXX
        preg_match_all('/listingNumber=(P24-\d+)/', $body, $listingMatches);
        $listingNumbers = array_unique($listingMatches[1] ?? []);

        if (empty($listingNumbers)) {
            // Try from subject: "House for sale in Sea Park P24-116950342"
            if (preg_match('/(P24-\d+)/', $subject, $m)) {
                $listingNumbers[] = $m[1];
            }
        }

        if (empty($listingNumbers)) {
            return [];
        }

        // Split body into per-listing blocks for multi-listing emails
        $blocks = $this->splitIntoBlocks($body, $listingNumbers);

        foreach ($listingNumbers as $listingNumber) {
            $block = $blocks[$listingNumber] ?? $body;

            $listing = [
                'p24_listing_number' => $listingNumber,
                'asking_price' => null,
                'property_type' => null,
                'suburb' => null,
                'bedrooms' => null,
                'bathrooms' => null,
                'garages' => null,
                'is_mandated' => false,
                'p24_url' => null,
            ];

            // Extract URL
            $quotedNumber = preg_quote($listingNumber, '/');
            if (preg_match(
                '/(https?:\/\/www\.property24\.com\/[^\s"<>]*listingNumber=' . $quotedNumber . '[^\s"<>]*)/',
                $body,
                $urlMatch
            )) {
                $listing['p24_url'] = html_entity_decode($urlMatch[1]);
            }

            // Extract price — "R 1 590 000" or "R1,590,000" or "R 850 000"
            if (preg_match('/R\s*([\d][\d\s,]+\d)/', $block, $priceMatch)) {
                $price = str_replace([' ', ','], '', $priceMatch[1]);
                if ($price > 0) {
                    $listing['asking_price'] = (float) $price;
                }
            }

            // Extract property type — "X Bedroom House/Apartment/Townhouse"
            if (preg_match('/(\d+)\s*Bedroom\s*(House|Apartment|Townhouse|Flat|Duplex|Simplex|Unit|Villa|Penthouse|Farm|Vacant\s+Land|Stand)/i', $block, $typeMatch)) {
                $listing['bedrooms'] = (int) $typeMatch[1];
                $listing['property_type'] = trim($typeMatch[2]);
            }

            // Extract from subject if body parsing missed it
            if (!$listing['property_type'] && preg_match(
                '/(House|Apartment|Townhouse|Flat|Duplex|Vacant\s+Land|Stand|Farm)\s+for\s+sale\s+in\s+(.+?)(?:\s+P24-|\s*$)/i',
                $subject,
                $subjectMatch
            )) {
                $listing['property_type'] = trim($subjectMatch[1]);
                $listing['suburb'] = trim($subjectMatch[2]);
            }

            // Extract suburb/area from the block text
            if (!$listing['suburb']) {
                if (preg_match('/Bedroom\s+\w+\s*[\r\n]+\s*([A-Za-z\s]+?)[\r\n]/i', $block, $areaMatch)) {
                    $candidate = trim($areaMatch[1]);
                    if (strlen($candidate) >= 3 && strlen($candidate) <= 60) {
                        $listing['suburb'] = $candidate;
                    }
                }
            }

            // Extract beds/baths/garages from icon patterns
            if (preg_match('/icon_bed[^>]*>\s*(\d+)/i', $block, $bedMatch)) {
                $listing['bedrooms'] = (int) $bedMatch[1];
            }
            if (preg_match('/icon_bath[^>]*>\s*(\d+)/i', $block, $bathMatch)) {
                $listing['bathrooms'] = (int) $bathMatch[1];
            }
            if (preg_match('/icon_garage[^>]*>\s*(\d+)/i', $block, $garageMatch)) {
                $listing['garages'] = (int) $garageMatch[1];
            }

            // Check mandated status (within the block, not the whole body)
            if (stripos($block, 'Mandated') !== false) {
                $listing['is_mandated'] = true;
            }

            $listings[] = $listing;
        }

        return $listings;
    }

    /**
     * Split the email body into blocks, one per listing number.
     * If we can't split cleanly, returns the full body for each listing.
     */
    private function splitIntoBlocks(string $body, array $listingNumbers): array
    {
        $blocks = [];

        if (count($listingNumbers) <= 1) {
            // Single listing — use the whole body
            foreach ($listingNumbers as $num) {
                $blocks[$num] = $body;
            }
            return $blocks;
        }

        // Try to find positions of each listing number and split
        $positions = [];
        foreach ($listingNumbers as $num) {
            $pos = strpos($body, $num);
            if ($pos !== false) {
                $positions[$num] = $pos;
            }
        }

        asort($positions);
        $sortedNums = array_keys($positions);

        for ($i = 0; $i < count($sortedNums); $i++) {
            $num = $sortedNums[$i];
            $start = max(0, $positions[$num] - 2000); // 2000 chars before
            $end = isset($sortedNums[$i + 1])
                ? $positions[$sortedNums[$i + 1]]
                : strlen($body);

            $blocks[$num] = substr($body, $start, $end - $start);
        }

        return $blocks;
    }
}
