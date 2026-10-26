<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

use App\Models\MarketReports\MarketReport;
use App\Models\Property;
use Illuminate\Support\Facades\Log;

/**
 * A property with no location takes it from the agency's OWN imported report. Spec:
 * .ai/specs/geocoding-spec.md "Report coordinates".
 *
 * The report's subject coordinates are read off the document itself, so they are better evidence than
 * sending the address to a third party. The match is strict on purpose (GPS-BORROW-FIX, 2026-06-18 —
 * borrowing a neighbour's pin is never right):
 *   - the report belongs to the property's own agency;
 *   - the report's subject address contains the property's street number AND street name as whole words;
 *   - the property's suburb (or estate) appears in the report's address, suburb or scheme name.
 * A property with no street number / street name, or no suburb, never matches.
 *
 * Used by PropertyGeoBackfillService::backfillProperty, so the create/update hook, the geocode button,
 * the backfill command and presentation generation all try the report before Google.
 */
final class PropertyReportCoordinates
{
    public const GEO_SOURCE = 'market_report_subject';

    public function find(Property $property): ?MarketReport
    {
        $agencyId = (int) $property->agency_id;
        $street   = $this->normalise(trim(implode(' ', array_filter([$property->street_number, $property->street_name]))));
        $suburb   = $this->normalise((string) $property->suburb);
        // Number AND street name AND suburb: a street alone would borrow a neighbour's pin.
        if ($agencyId === 0 || trim((string) $property->street_number) === '' || trim((string) $property->street_name) === ''
            || $street === '' || $suburb === '') {
            return null;
        }

        $streetPattern = '/(?<![a-z0-9])' . preg_quote($street, '/') . '(?![a-z0-9])/';
        $suburbPattern = '/(?<![a-z0-9])' . preg_quote($suburb, '/') . '(?![a-z0-9])/';

        $candidates = MarketReport::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('agency_id', $agencyId)
            ->whereNotNull('subject_latitude')
            ->whereNotNull('subject_longitude')
            ->whereRaw('LOWER(subject_address) LIKE ?', ['%' . mb_strtolower((string) $property->street_name) . '%'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        foreach ($candidates as $report) {
            if (!preg_match($streetPattern, $this->normalise((string) $report->subject_address))) {
                continue;
            }
            $where = $this->normalise(implode(' ', [$report->subject_address, $report->source_suburb, $report->subject_scheme_name ?? '']));
            if (preg_match($suburbPattern, $where)) {
                return $report;
            }
        }

        return null;
    }

    /** Fill a property that has no real location from its agency's report. Returns true when it did. */
    public function fillIfMissing(Property $property): bool
    {
        $hasReal = $property->latitude !== null && $property->longitude !== null
            && !((float) $property->latitude === 0.0 && (float) $property->longitude === 0.0);
        if ($hasReal) {
            return false;
        }

        $report = $this->find($property);
        if ($report === null) {
            return false;
        }

        $property->latitude        = $report->subject_latitude;
        $property->longitude       = $report->subject_longitude;
        $property->geo_source      = self::GEO_SOURCE;
        $property->geo_confidence  = 'high';
        $property->geo_resolved_at = now();
        $property->saveQuietly();

        Log::channel('geocoding')->info('property location taken from the agency\'s own report', [
            'property_id' => $property->id,
            'report_id'   => $report->id,
        ]);

        return true;
    }

    private function normalise(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
