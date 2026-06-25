<?php

namespace App\Services\Importer;

use App\Models\Property;

/**
 * P24 Listing-Number Reconciler.
 *
 * One-shot repair tool for stock that was imported from a Property24 export
 * before the listing number was wired through to the syndication push.
 *
 * The push (Property24ListingMapper::map) decides "update existing" vs
 * "create new" purely on $property->p24_ref. Imported properties had only
 * p24_listing_number (or nothing) set, so every push omitted listingNumber
 * and P24 created a DUPLICATE. This service reads the original P24 CSV,
 * matches each row back to its CoreX property, and writes the listing number
 * into BOTH p24_listing_number and p24_ref so the next push updates the
 * original listing instead of duplicating it.
 *
 * Matching is intentionally conservative — it only writes when a row resolves
 * to exactly ONE property. Ambiguous rows are reported, never guessed.
 */
class P24ListingNumberReconciler
{
    /** GPS box half-width in degrees (~22m at SA latitudes). */
    private const GPS_TOLERANCE = 0.0002;

    /**
     * Stream the P24 CSV into compact match rows.
     * Auto-detects comma vs tab delimiter. fgetcsv handles quoted multi-line
     * descriptions natively, so we never mis-split a row.
     *
     * @return array<int, array{ln: ?string, lat: ?float, lng: ?float, sn: string, st: string}>
     */
    public function parse(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open CSV: {$path}");
        }

        // Sniff the delimiter from the header line, then rewind.
        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            return [];
        }
        $delimiter = substr_count($firstLine, "\t") > substr_count($firstLine, ',') ? "\t" : ',';
        rewind($handle);

        $header = fgetcsv($handle, 0, $delimiter);
        if (!$header) {
            fclose($handle);
            return [];
        }
        $header = array_map(fn ($h) => trim((string) $h), $header);

        $rows = [];
        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count($data) === 1 && ($data[0] === null || $data[0] === '')) {
                continue;
            }
            $raw = array_combine($header, array_pad($data, count($header), null)) ?: [];

            $rows[] = [
                'ln'  => isset($raw['ListingNumber']) ? trim((string) $raw['ListingNumber']) : null,
                'lat' => is_numeric($raw['Latitude'] ?? null) ? (float) $raw['Latitude'] : null,
                'lng' => is_numeric($raw['Longitude'] ?? null) ? (float) $raw['Longitude'] : null,
                'sn'  => trim((string) ($raw['StreetNumber'] ?? '')),
                'st'  => trim((string) ($raw['StreetName'] ?? '')),
            ];
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Match one CSV row to a single CoreX property and link the listing number.
     * Runs inside the authenticated request so the BelongsToAgency global scope
     * keeps everything tenant-isolated automatically.
     *
     * @param  array{ln: ?string, lat: ?float, lng: ?float, sn: string, st: string}  $row
     * @return array{status: string, listing_number: ?string, reason: string, property_id?: int, address?: string, old_ref?: ?string}
     */
    public function reconcileRow(array $row): array
    {
        $ln = $row['ln'];
        if ($ln === null || $ln === '' || !ctype_digit((string) $ln)) {
            return ['status' => 'invalid', 'listing_number' => $ln, 'reason' => 'Missing or non-numeric ListingNumber'];
        }

        // Strategy 1 — the property already carries this listing number on any
        // of the known reference columns. Strongest signal; just promote it to
        // p24_ref so the push recognises it.
        $exact = Property::query()
            ->where(function ($q) use ($ln) {
                $q->where('p24_listing_number', $ln)
                  ->orWhere('external_id', $ln)
                  ->orWhere('p24_ref', $ln);
            })
            ->get();
        if ($exact->count() === 1) {
            return $this->apply($exact->first(), $ln, 'matched on existing listing number');
        }
        if ($exact->count() > 1) {
            return ['status' => 'skipped', 'listing_number' => $ln, 'reason' => 'Listing number already on ' . $exact->count() . ' properties — ambiguous'];
        }

        // Strategy 2 — GPS proximity (~22m). Works even when the address is messy.
        if ($row['lat'] !== null && $row['lng'] !== null) {
            $t = self::GPS_TOLERANCE;
            $gps = Property::query()
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->whereBetween('latitude', [$row['lat'] - $t, $row['lat'] + $t])
                ->whereBetween('longitude', [$row['lng'] - $t, $row['lng'] + $t])
                ->get();
            if ($gps->count() === 1) {
                return $this->apply($gps->first(), $ln, 'matched on GPS location');
            }
            // >1 GPS hit: fall through to address to disambiguate.
        }

        // Strategy 3 — street number + street name (case/whitespace-insensitive).
        $streetName = trim($row['st']);
        if ($streetName !== '') {
            $streetNumber = trim($row['sn']);
            $q = Property::query()->whereRaw('LOWER(TRIM(street_name)) = ?', [mb_strtolower($streetName)]);
            if ($streetNumber !== '') {
                $q->where('street_number', $streetNumber);
            }
            $addr = $q->get();
            if ($addr->count() === 1) {
                return $this->apply($addr->first(), $ln, $streetNumber !== '' ? 'matched on street address' : 'matched on street name');
            }
            if ($addr->count() > 1) {
                return ['status' => 'skipped', 'listing_number' => $ln, 'reason' => 'Address matched ' . $addr->count() . ' properties — ambiguous'];
            }
        }

        return ['status' => 'unmatched', 'listing_number' => $ln, 'reason' => 'No property matched by listing number, GPS, or address'];
    }

    /**
     * Write the listing number onto the matched property.
     * p24_ref is the field the syndication push reads to UPDATE vs CREATE, so
     * setting it is the actual fix. We overwrite even when a different p24_ref
     * is already present (that stale ref is almost always a duplicate created
     * by an earlier push) — but we report it as a conflict so the operator can
     * withdraw the duplicate on P24.
     *
     * @return array{status: string, property_id: int, listing_number: string, reason: string, address: string, old_ref: ?string}
     */
    private function apply(Property $property, string $listingNumber, string $reason): array
    {
        $oldRef = $property->p24_ref;
        $conflict = $oldRef !== null && $oldRef !== '' && (string) $oldRef !== (string) $listingNumber;

        $update = [
            'p24_listing_number' => (string) $listingNumber,
            'p24_ref'            => (string) $listingNumber,
        ];

        // The listing demonstrably exists and is live on P24 (it came from the
        // P24 export), so reflect that — but only when CoreX has no opinion yet.
        if (empty($property->p24_syndication_status)) {
            $update['p24_syndication_status'] = 'active';
            $update['p24_activated_at'] = now();
        }

        $property->fill($update)->save();

        $address = trim(($property->street_number ? $property->street_number . ' ' : '') . ($property->street_name ?? ''));
        if ($address === '') {
            $address = $property->address ?: ($property->title ?: '#' . $property->id);
        }

        return [
            'status'         => $conflict ? 'conflict' : 'applied',
            'property_id'    => (int) $property->id,
            'listing_number' => (string) $listingNumber,
            'reason'         => $conflict ? ($reason . ' — replaced stale ref ' . $oldRef . ' (withdraw that duplicate on P24)') : $reason,
            'address'        => $address,
            'old_ref'        => $conflict ? (string) $oldRef : null,
        ];
    }
}
