<?php

namespace App\Services\PrivateProperty;

use App\Support\Property\PortalRef;

/**
 * Reads a Private Property alert e-mail into advert rows (property-identity.md section 15, C1).
 *
 * FORMAT NOTE: no real Private Property alert e-mail was available on the box when this was written, so the reader is built against the
 * public shape of a Private Property advert link (".../for-sale/<province>/<region>/<town>/<suburb>/<slug>/T1234567") and the same
 * "3 Bedroom House ... R 1 590 000" card wording Property24 uses. It is exercised by tests/Fixtures/pp-alert-sample.html. The first real
 * mail must be run through `pp:import` on QA and compared with this reader before anyone relies on it (the importer logs a clear
 * "found nothing" line for a mail it could not read, and the mail is NOT marked as processed when it yields nothing).
 *
 * @phpstan-type Row array{pp_listing_number:string, asking_price:?float, property_type:?string, suburb:?string, bedrooms:?int, bathrooms:?int, garages:?int, is_mandated:bool, pp_url:?string}
 */
class PpEmailParserService
{
    private const TYPES = 'House|Apartment|Townhouse|Flat|Duplex|Simplex|Unit|Villa|Penthouse|Farm|Vacant\s+Land|Stand|Cluster|Studio';

    /** @return list<array<string,mixed>> */
    public function parse(string $body, string $subject = ''): array
    {
        // advert links: privateproperty.co.za/.../<id>
        preg_match_all('~https?://(?:www\.)?privateproperty\.co\.za/[^\s"\'<>]*?/([A-Za-z]{1,3}\d{5,10})(?=[/?#"\'\s<>]|$)[^\s"\'<>]*~i', $body, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $adverts = [];
        foreach ($m as $hit) {
            $id = PortalRef::normalise($hit[1][0], PortalRef::PP);
            if ($id === null) {
                continue;
            }
            $number = substr($id, 3);   // "pp:T123" -> "T123"
            if (! isset($adverts[$number])) {
                $adverts[$number] = ['pos' => $hit[0][1], 'url' => html_entity_decode($hit[0][0])];
            }
        }
        if ($adverts === [] && preg_match('/\b([A-Za-z]{1,3}\d{5,10})\b/', $subject, $s) && ($id = PortalRef::normalise($s[1], PortalRef::PP)) !== null) {
            $adverts[substr($id, 3)] = ['pos' => 0, 'url' => null];
        }
        if ($adverts === []) {
            return [];
        }

        uasort($adverts, fn ($a, $b) => $a['pos'] <=> $b['pos']);
        $numbers = array_keys($adverts);
        $rows = [];
        foreach ($numbers as $i => $number) {
            // a card runs from its own link to the next card's link (the card's details follow the link)
            $start = $adverts[$number]['pos'];
            $end = $numbers[$i + 1] ?? null;
            $end = $end !== null ? $adverts[$end]['pos'] : strlen($body);
            $block = substr($body, $start, max(0, $end - $start));
            if (count($numbers) === 1) {
                $block = $body;
            }
            $rows[] = $this->row($number, $adverts[$number]['url'], $block, $subject);
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    private function row(string $number, ?string $url, string $block, string $subject): array
    {
        $block = preg_replace('~https?://[^\s"\'<>]+~i', ' ', $block) ?? $block;   // the link text itself (a block may start inside the href) is not content
        $text = html_entity_decode(trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/<(br|\/p|\/div|\/tr|\/td)[^>]*>/i', "\n", $block))) ?? ''));
        $row = [
            'pp_listing_number' => strtoupper($number), 'asking_price' => null, 'property_type' => null, 'suburb' => null,
            'bedrooms' => null, 'bathrooms' => null, 'garages' => null, 'is_mandated' => false, 'pp_url' => $url,
        ];

        if (preg_match('/(?<![A-Za-z0-9])R\s*(\d{1,3}(?:[ ,\x{00A0}]\d{3})+|\d+)/u', $text, $p)) {
            $price = (float) str_replace([' ', ',', "\u{00A0}"], '', $p[1]);
            $row['asking_price'] = $price > 0 ? $price : null;
        }
        if (preg_match('/(\d+)\s*Bed(?:room)?s?\s*(' . self::TYPES . ')/i', $text, $t)) {
            $row['bedrooms'] = (int) $t[1];
            $row['property_type'] = trim($t[2]);
        } elseif (preg_match('/\b(' . self::TYPES . ')\b\s+for\s+sale/i', $text . ' ' . $subject, $t)) {
            $row['property_type'] = trim($t[1]);
        }
        if (preg_match('/(\d+)\s*Bath(?:room)?s?\b/i', $text, $b)) {
            $row['bathrooms'] = (int) $b[1];
        }
        if (preg_match('/(\d+)\s*(?:Garages?|Parking)\b/i', $text, $g)) {
            $row['garages'] = (int) $g[1];
        }
        $row['is_mandated'] = (bool) preg_match('/\b(Sole\s+Mandate|Mandated)\b/i', $text);

        // suburb: "... for sale in Uvongo" in the subject/text, else the town/suburb segments of the advert link
        if (preg_match('/for\s+sale\s+in\s+([A-Za-z][A-Za-z\s\'-]{2,40}?)(?:\s+(?:[A-Za-z]{1,3}\d{5,10})|,|\.|\s*$|\s{2,}|\s+R\s*\d)/i', $subject . '  ', $s)) {
            $row['suburb'] = trim($s[1]);
        } elseif ($url && preg_match('~/(?:for-sale|to-rent)/[^/]+/[^/]+/[^/]+/([^/]+)/[^/]+/[A-Za-z]{1,3}\d{5,10}~i', $url, $s)) {
            $row['suburb'] = ucwords(str_replace('-', ' ', $s[1]));
        }

        return $row;
    }
}
