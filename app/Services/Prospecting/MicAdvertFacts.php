<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\ProspectingListing;
use Illuminate\Support\Carbon;

/**
 * The public advert facts the extension reads off P24 / PP tiles and listing pages (.ai/specs/mic-work-tab.md §5, §11.2).
 *
 * sanitise() turns one incoming listing into the columns to write. It is deliberately forgiving: a value that is present
 * but malformed is DROPPED and counted, never a 422 for the whole 100-tile batch. A key that is absent is simply not
 * returned, so a tile that lacks a field can never blank what a listing page stored earlier.
 *
 * Fingerprints only — never the advert text or an image. No contact numbers.
 */
final class MicAdvertFacts
{
    /** Every contract key this class reads (the controller validates their presence, this class their content). */
    public const KEYS = [
        'advertiser_type', 'agent_portal_id', 'agency_portal_id', 'listed_date', 'rates_amount', 'levies_amount',
        'portal_latitude', 'portal_longitude', 'is_reduced', 'is_exclusive', 'badges', 'p24_group_id', 'p24_group_size',
        'p24_group_listing_numbers', 'p24_mandate_id', 'wording_fingerprint', 'first_photo_fingerprint', 'page_read',
    ];

    /** South Africa, with margin — a map point outside it is a misparse, not a property. */
    private const LAT = [-35.5, -21.5];
    private const LNG = [16.0, 33.5];

    /**
     * @param  array<string,mixed> $data one incoming listing (after the controller's own trimming)
     * @return array{columns: array<string,mixed>, group: ?array{id:string,numbers:list<string>,size:?int}, listed_date: ?string, page_read: bool, dropped: int}
     */
    public static function sanitise(array $data, string $portalSource): array
    {
        $cols = [];
        $dropped = 0;
        $drop = function () use (&$dropped): void {
            $dropped++;
        };

        // advertiser_type
        if (self::present($data, 'advertiser_type')) {
            $t = strtolower(trim((string) $data['advertiser_type']));
            in_array($t, ProspectingListing::ADVERTISER_TYPES, true) ? $cols['advertiser_type'] = $t : $drop();
        }
        // An older extension build stored P24's "listed by owner" branding image alt text ("Sale") as the agency. Same rule as the back-fill.
        if (! isset($cols['advertiser_type']) && $portalSource === 'p24' && strtolower(trim((string) ($data['agency_name'] ?? ''))) === 'sale') {
            $cols['advertiser_type'] = 'owner';
        }

        foreach (['agent_portal_id', 'agency_portal_id'] as $k) {
            if (self::present($data, $k)) {
                $v = trim((string) $data[$k]);
                preg_match('/^[\w.\-]{1,40}$/', $v) ? $cols[$k] = $v : $drop();
            }
        }

        foreach (['rates_amount', 'levies_amount'] as $k) {
            if (self::present($data, $k)) {
                (is_numeric($data[$k]) && $data[$k] >= 0 && $data[$k] <= 10_000_000) ? $cols[$k] = (int) round((float) $data[$k]) : $drop();
            }
        }

        // a map point is only worth keeping as a pair, inside South Africa
        if (self::present($data, 'portal_latitude') || self::present($data, 'portal_longitude')) {
            $lat = $data['portal_latitude'] ?? null;
            $lng = $data['portal_longitude'] ?? null;
            if (is_numeric($lat) && is_numeric($lng)
                && $lat >= self::LAT[0] && $lat <= self::LAT[1] && $lng >= self::LNG[0] && $lng <= self::LNG[1]) {
                $cols['portal_latitude'] = round((float) $lat, 7);
                $cols['portal_longitude'] = round((float) $lng, 7);
                $cols['portal_geo_source'] = 'jsonld';
            } else {
                $drop();
            }
        }

        foreach (['is_reduced', 'is_exclusive'] as $k) {
            if (self::present($data, $k)) {
                $b = filter_var($data[$k], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $b === null ? $drop() : $cols[$k] = $b;
            }
        }

        if (array_key_exists('badges', $data) && $data['badges'] !== null) {
            if (is_array($data['badges'])) {
                $cols['badges'] = array_values(array_unique(array_filter(
                    array_map(fn ($b) => is_string($b) ? strtolower(trim($b)) : '', $data['badges']),
                    fn ($b) => in_array($b, ProspectingListing::BADGES, true),
                )));
            } else {
                $drop();
            }
        }

        foreach (['p24_group_id', 'p24_mandate_id'] as $k) {
            if (self::present($data, $k)) {
                $v = trim((string) $data[$k]);
                preg_match('/^\d{1,20}$/', $v) ? $cols[$k] = $v : $drop();
            }
        }

        foreach (['wording_fingerprint', 'first_photo_fingerprint'] as $k) {
            if (self::present($data, $k)) {
                $v = strtolower(trim((string) $data[$k]));
                preg_match('/^[0-9a-f]{40}$/', $v) ? $cols[$k] = $v : $drop();
            }
        }

        // P24 group: needs the group id AND at least one listing number
        $group = null;
        if (isset($cols['p24_group_id']) && is_array($data['p24_group_listing_numbers'] ?? null)) {
            $numbers = array_values(array_unique(array_filter(
                array_map(fn ($n) => preg_replace('/\D/', '', (string) $n), $data['p24_group_listing_numbers']),
                fn ($n) => preg_match('/^\d{6,12}$/', $n) === 1,
            )));
            if ($numbers !== []) {
                $size = (int) ($data['p24_group_size'] ?? 0);
                $group = ['id' => $cols['p24_group_id'], 'numbers' => array_slice($numbers, 0, 300),
                    'size' => ($size >= 2 && $size <= 500) ? $size : null];
            }
        }

        // A search tile carries no listed date (AT-454, Johan 13 Oct): it is honoured only on a listing-page read.
        $listed = null;
        if (self::present($data, 'listed_date') && filter_var($data['page_read'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $listed = self::validDate($data['listed_date']);
            $listed === null ? $drop() : null;
        }

        return [
            'columns'     => $cols,
            'group'       => $group,
            'listed_date' => $listed,
            'page_read'   => filter_var($data['page_read'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'dropped'     => $dropped,
        ];
    }

    /**
     * Write the sanitised facts onto a listing (not saved here). An owner advert never carries an agency name; an
     * agent-corrected listed date is never overwritten; a listing-page read stamps when and at what price.
     */
    public static function apply(ProspectingListing $l, array $facts, \Carbon\CarbonInterface $now): void
    {
        foreach ($facts['columns'] as $col => $val) {
            $l->{$col} = $val;
        }
        if (($facts['columns']['advertiser_type'] ?? $l->advertiser_type) === 'owner') {
            $l->agency_name = null;
        }
        if ($facts['listed_date'] !== null && $l->listed_date_source !== ProspectingListing::LISTED_DATE_SOURCE_AGENT) {
            $l->listed_date = $facts['listed_date'];
            $l->listed_date_source = ProspectingListing::LISTED_DATE_SOURCE_PORTAL;
        }
        if ($facts['page_read']) {
            $l->details_read_at = $now;
            $l->details_read_price = $l->price !== null ? (int) $l->price : null;
        }
    }

    /** The public facts to hand the shared pool (allow-listed there again). */
    public static function poolFacts(ProspectingListing $l): array
    {
        $f = [
            'advertiser_type' => $l->advertiser_type, 'agent_portal_id' => $l->agent_portal_id, 'agency_portal_id' => $l->agency_portal_id,
            'listed_date' => $l->listed_date?->toDateString(), 'rates_amount' => $l->rates_amount, 'levies_amount' => $l->levies_amount,
            'is_reduced' => $l->is_reduced, 'is_exclusive' => $l->is_exclusive, 'badges' => $l->badges,
            'p24_group_id' => $l->p24_group_id, 'p24_mandate_id' => $l->p24_mandate_id,
            'wording_fingerprint' => $l->wording_fingerprint, 'first_photo_fingerprint' => $l->first_photo_fingerprint,
            'listing_agent_name' => $l->agent_name, 'listing_agency_name' => $l->agency_name,
        ];
        if ($l->portal_latitude !== null && $l->portal_longitude !== null) {
            $f += ['latitude' => $l->portal_latitude, 'longitude' => $l->portal_longitude, 'geo_confidence' => 'high'];
        }

        return array_filter($f, fn ($v) => $v !== null && $v !== '');
    }

    public static function validDate(mixed $raw): ?string
    {
        if (! is_string($raw) || preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($raw)) !== 1) {
            return null;
        }
        try {
            $d = Carbon::createFromFormat('!Y-m-d', trim($raw));
        } catch (\Throwable) {
            return null;
        }
        if ($d === false || $d->format('Y-m-d') !== trim($raw) || $d->year < 2005 || $d->gt(Carbon::now()->addDay())) {
            return null;
        }

        return $d->toDateString();
    }

    private static function present(array $data, string $key): bool
    {
        return array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '';
    }
}
