<?php

declare(strict_types=1);

namespace App\Services\Presentation;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Routes CMA-extracted presentation_fields to the Property pillar.
 *
 * Reads the canonical value column on presentation_fields:
 *   COALESCE(final_value, override_value, extracted_value)
 *
 * Idempotent. Re-running with the same presentation produces no spurious updates.
 * Stale-protected: older presentations never clobber newer data on the same property
 * (compared via Property.last_cma_at vs Presentation.updated_at).
 *
 * Multi-tenancy: every resolution path validates property.agency_id matches the
 * presentation's agency. Cross-agency writes are refused.
 *
 * See .ai/specs/market-intelligence-discovery.md Section 13.4 for the strategic context.
 */
final class PropertyCmaPropagationService
{
    /**
     * Field keys read from presentation_fields and the property columns they target.
     * Documented here so anyone extending the schema knows what's expected.
     */
    private const RELEVANT_KEYS = [
        'subject.erf',
        'subject.gps',
        'subject.address',
        'subject.suburb',
        'subject.title_deed',
        'municipal.total_value',
        'municipal.valuation_year',
    ];

    /**
     * Propagate CMA fields from a single presentation to its linked Property.
     *
     * Result statuses:
     *   - 'presentation_not_found'  → presentation id is invalid or soft-deleted
     *   - 'no_extraction_data'      → presentation has no relevant presentation_fields rows
     *   - 'no_linked_property'      → no resolution strategy could find a matching property
     *   - 'skipped_stale'           → property.last_cma_at is newer than presentation.updated_at
     *   - 'no_updates_needed'       → resolution found a property, but no clean values to write
     *   - 'updated'                 → property row was updated; columns_updated returned
     */
    public function propagateFromPresentation(int $presentationId, bool $allowAddressMatch = true): array
    {
        $presentation = DB::table('presentations')
            ->where('id', $presentationId)
            ->whereNull('deleted_at')
            ->first();

        if (!$presentation) {
            return ['status' => 'presentation_not_found', 'presentation_id' => $presentationId];
        }

        $fields = $this->loadFields($presentationId);
        if (empty($fields)) {
            return ['status' => 'no_extraction_data', 'presentation_id' => $presentationId];
        }

        $property = $this->resolveTargetProperty($presentation, $fields, $allowAddressMatch);
        if (!$property) {
            return ['status' => 'no_linked_property', 'presentation_id' => $presentationId];
        }

        // Stale-protection: never overwrite when the property's CMA data is at least as
        // fresh as this presentation. >= rather than > so re-running on the same
        // presentation is idempotent (no spurious writes / log noise).
        if (!empty($property->last_cma_at) && !empty($presentation->updated_at)) {
            $propTs = strtotime((string) $property->last_cma_at);
            $presTs = strtotime((string) $presentation->updated_at);
            if ($propTs !== false && $presTs !== false && $propTs >= $presTs) {
                return [
                    'status' => 'skipped_stale',
                    'presentation_id' => $presentationId,
                    'property_id' => $property->id,
                ];
            }
        }

        $updates = $this->buildUpdates($fields);

        if (empty($updates)) {
            return [
                'status' => 'no_updates_needed',
                'presentation_id' => $presentationId,
                'property_id' => $property->id,
            ];
        }

        $updates['last_cma_at'] = $presentation->updated_at ?? now();
        $updates['last_cma_presentation_id'] = $presentationId;
        $updates['updated_at'] = now();

        DB::table('properties')->where('id', $property->id)->update($updates);

        return [
            'status' => 'updated',
            'presentation_id' => $presentationId,
            'property_id' => $property->id,
            'columns_updated' => array_keys($updates),
        ];
    }

    /**
     * Bulk backfill: run propagateFromPresentation across every presentation that
     * has any extraction data. Aggregate stats. Safe to run repeatedly.
     *
     * Scope: if $agencyId provided, only presentations owned by that agency
     * (via presentations.agency_id when populated, else branches.agency_id fallback).
     */
    public function backfillAll(?int $agencyId = null, bool $allowAddressMatch = true): array
    {
        $query = DB::table('presentations')
            ->select('presentations.id')
            ->join('presentation_fields', 'presentation_fields.presentation_id', '=', 'presentations.id')
            ->whereNull('presentations.deleted_at')
            ->whereNull('presentation_fields.deleted_at')
            ->distinct();

        if ($agencyId !== null) {
            $query->leftJoin('branches', 'branches.id', '=', 'presentations.branch_id')
                ->where(function ($q) use ($agencyId) {
                    $q->where('presentations.agency_id', $agencyId)
                      ->orWhere('branches.agency_id', $agencyId);
                });
        }

        $presentationIds = $query->pluck('presentations.id')->all();

        $stats = [
            'total' => count($presentationIds),
            'updated' => 0,
            'skipped' => 0,
            'no_linked' => 0,
            'no_data' => 0,
            'errors' => 0,
            'updated_property_ids' => [],
        ];

        foreach ($presentationIds as $pid) {
            try {
                $result = $this->propagateFromPresentation($pid, $allowAddressMatch);
                $status = $result['status'] ?? 'unknown';
                if ($status === 'updated') {
                    $stats['updated']++;
                    if (isset($result['property_id'])) {
                        $stats['updated_property_ids'][] = $result['property_id'];
                    }
                } elseif (in_array($status, ['skipped_stale', 'no_updates_needed'], true)) {
                    $stats['skipped']++;
                } elseif ($status === 'no_linked_property') {
                    $stats['no_linked']++;
                } elseif (in_array($status, ['no_extraction_data', 'presentation_not_found'], true)) {
                    $stats['no_data']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::warning("CMA propagation failed for presentation {$pid}: " . $e->getMessage());
            }
        }

        $stats['updated_property_ids'] = array_values(array_unique($stats['updated_property_ids']));
        return $stats;
    }

    /**
     * Load presentation_fields keyed by field_key.
     * Uses the canonical resolved value: final_value, then override_value, then extracted_value.
     */
    private function loadFields(int $presentationId): array
    {
        $rows = DB::table('presentation_fields')
            ->where('presentation_id', $presentationId)
            ->whereNull('deleted_at')
            ->select('field_key', 'final_value', 'override_value', 'extracted_value')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $value = $r->final_value ?? $r->override_value ?? $r->extracted_value;
            if ($value !== null && $value !== '') {
                $out[$r->field_key] = $value;
            }
        }
        return $out;
    }

    /**
     * Resolution strategy in order:
     *   1. presentations.listing_id (direct, scoped to same agency)
     *   2. property_presentation_snapshots pivot (presentation_id → property_id)
     *   3. address match (normalised + token overlap, ProspectingStockMatchService spirit)
     *   4. erf match (against properties.erf_number / property_number / stand_number)
     */
    private function resolveTargetProperty(object $presentation, array $fields, bool $allowAddressMatch): ?object
    {
        $agencyId = $this->resolveAgencyId($presentation);
        if ($agencyId === null) return null;

        // Strategy 1: direct listing_id
        if (!empty($presentation->listing_id)) {
            $property = $this->loadPropertyById((int) $presentation->listing_id, $agencyId);
            if ($property) return $property;
        }

        // Strategy 2: snapshot pivot
        $pivotPropertyId = DB::table('property_presentation_snapshots')
            ->where('presentation_id', $presentation->id)
            ->value('property_id');
        if ($pivotPropertyId) {
            $property = $this->loadPropertyById((int) $pivotPropertyId, $agencyId);
            if ($property) return $property;
        }

        if (!$allowAddressMatch) return null;

        $subjectAddress = $fields['subject.address'] ?? null;
        $subjectSuburb = $fields['subject.suburb'] ?? null;

        // Strategy 3: address match
        if ($subjectAddress && $subjectSuburb) {
            $property = $this->findByAddress($agencyId, (string) $subjectAddress, (string) $subjectSuburb);
            if ($property) return $property;
        }

        // Strategy 4: erf match
        $subjectErf = $fields['subject.erf'] ?? null;
        if ($subjectErf && $subjectSuburb) {
            $property = $this->findByErf($agencyId, (string) $subjectErf, (string) $subjectSuburb);
            if ($property) return $property;
        }

        return null;
    }

    private function resolveAgencyId(object $presentation): ?int
    {
        if (!empty($presentation->agency_id)) {
            return (int) $presentation->agency_id;
        }
        if (!empty($presentation->branch_id)) {
            $branch = DB::table('branches')->where('id', $presentation->branch_id)->first(['agency_id']);
            if ($branch && $branch->agency_id) {
                return (int) $branch->agency_id;
            }
        }
        return null;
    }

    private function loadPropertyById(int $propertyId, int $agencyId): ?object
    {
        return DB::table('properties')
            ->where('id', $propertyId)
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->select('id', 'agency_id', 'last_cma_at', 'last_cma_presentation_id')
            ->first();
    }

    /**
     * 2-pass address matcher, inspired by ProspectingStockMatchService::matchProspect():
     *   Pass 1: exact normalised match against (street_number + street_name + suburb) or address fallback.
     *   Pass 2: same suburb + ≥2 significant token overlap.
     *
     * Returns the candidate property row or null.
     */
    private function findByAddress(int $agencyId, string $address, string $suburb): ?object
    {
        $normalisedSubject = $this->normaliseAddress($address . ' ' . $suburb);
        $suburbLower = mb_strtolower(trim($suburb));

        $candidates = DB::table('properties')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($suburb, $suburbLower) {
                $q->whereRaw('LOWER(suburb) = ?', [$suburbLower])
                  ->orWhere('suburb', 'LIKE', '%' . $suburb . '%');
            })
            ->select('id', 'agency_id', 'street_number', 'street_name', 'address', 'suburb',
                     'last_cma_at', 'last_cma_presentation_id')
            ->get();

        if ($candidates->isEmpty()) return null;

        // Pass 1: exact normalised
        foreach ($candidates as $cand) {
            $candAddress = trim(($cand->street_number ?? '') . ' ' . ($cand->street_name ?? ''));
            if ($candAddress === '') {
                $candAddress = (string) ($cand->address ?? '');
            }
            $candNorm = $this->normaliseAddress($candAddress . ' ' . ($cand->suburb ?? ''));
            if ($candNorm !== '' && $candNorm === $normalisedSubject) {
                return $cand;
            }
        }

        // Pass 2: token overlap (≥2 significant tokens)
        $subjectTokens = $this->extractStreetTokens($address);
        if (empty($subjectTokens)) return null;

        foreach ($candidates as $cand) {
            $candAddress = trim(($cand->street_number ?? '') . ' ' . ($cand->street_name ?? ''));
            if ($candAddress === '') {
                $candAddress = (string) ($cand->address ?? '');
            }
            $candTokens = $this->extractStreetTokens($candAddress);
            $overlap = array_intersect($subjectTokens, $candTokens);
            if (count($overlap) >= 2) {
                return $cand;
            }
        }

        return null;
    }

    private function findByErf(int $agencyId, string $erf, string $suburb): ?object
    {
        $erfCanonical = trim(preg_replace('/^(erf|stand)\s+/i', '', $erf));
        if ($erfCanonical === '') return null;
        $suburbLower = mb_strtolower(trim($suburb));

        return DB::table('properties')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($suburb, $suburbLower) {
                $q->whereRaw('LOWER(suburb) = ?', [$suburbLower])
                  ->orWhere('suburb', 'LIKE', '%' . $suburb . '%');
            })
            ->where(function ($q) use ($erfCanonical) {
                $q->where('erf_number', $erfCanonical)
                  ->orWhere('property_number', $erfCanonical)
                  ->orWhere('stand_number', $erfCanonical);
            })
            ->select('id', 'agency_id', 'last_cma_at', 'last_cma_presentation_id')
            ->first();
    }

    private function normaliseAddress(string $addr): string
    {
        $addr = mb_strtolower($addr);
        $addr = preg_replace('/[^\w\s]/u', ' ', $addr);
        $addr = preg_replace('/\s+/', ' ', (string) $addr);
        return trim((string) $addr);
    }

    /**
     * Extract significant street tokens (≥3 chars, alpha only).
     * Used for the Pass-2 fuzzy match.
     */
    private function extractStreetTokens(string $addr): array
    {
        $n = $this->normaliseAddress($addr);
        $tokens = preg_split('/\s+/', $n) ?: [];
        // Drop short/common/numeric tokens — keep 3+ alpha words.
        return array_values(array_filter($tokens, function ($t) {
            return strlen($t) >= 3 && !ctype_digit($t);
        }));
    }

    /**
     * Map extracted presentation_fields values onto clean Property column values.
     */
    private function buildUpdates(array $fields): array
    {
        $updates = [];

        // Erf number — extractor already strips "Erf "/"Stand " prefix per
        // DocumentExtractor.php:158 but we defensively strip again here in case
        // older rows or pivot-resolved presentations carry the prefix.
        if (!empty($fields['subject.erf'])) {
            $erf = trim((string) $fields['subject.erf']);
            $erf = (string) preg_replace('/^(erf|stand)\s+/i', '', $erf);
            if ($erf !== '') {
                $updates['erf_number'] = $erf;
            }
        }

        // Municipal valuation — handles plain digits ("850000") and full SA format
        // ("R 1 250 000.50") harmlessly. Empirical data is plain digits per pre-flight.
        if (!empty($fields['municipal.total_value'])) {
            $val = $this->parseRandValue((string) $fields['municipal.total_value']);
            if ($val !== null) {
                $updates['municipal_valuation'] = $val;
            }
        }

        if (!empty($fields['municipal.valuation_year'])) {
            $year = (int) preg_replace('/\D/', '', (string) $fields['municipal.valuation_year']);
            if ($year >= 2000 && $year <= ((int) date('Y') + 1)) {
                $updates['municipal_valuation_year'] = $year;
            }
        }

        // GPS — empirical format: "30.265405°E 30.980583°S" (longitude first, then latitude).
        if (!empty($fields['subject.gps'])) {
            $coords = $this->parseGps((string) $fields['subject.gps']);
            if ($coords !== null) {
                $updates['cma_gps_lat'] = $coords['lat'];
                $updates['cma_gps_lng'] = $coords['lng'];
            }
        }

        // Title deed — extractor doesn't emit subject.title_deed in v1 patterns, but if a
        // future parser pass writes it, this propagates cleanly.
        if (!empty($fields['subject.title_deed'])) {
            $td = trim((string) $fields['subject.title_deed']);
            if ($td !== '') {
                $updates['title_deed_number'] = $td;
            }
        }

        return $updates;
    }

    /**
     * Parse a value that may be:
     *   - plain digits ("850000")        → 850000.00
     *   - decimal           ("850000.50")→ 850000.50
     *   - SA-formatted ("R 1 250 000")   → 1250000.00
     *   - SA-formatted ("R1,250,000.50") → 1250000.50
     *
     * Returns null on garbage.
     */
    public function parseRandValue(string $raw): ?float
    {
        $raw = trim($raw);
        if ($raw === '') return null;

        // Drop R, currency symbols, and whitespace; keep only digits, ',', '.', '-'.
        $clean = preg_replace('/[^\d.,\-]/', '', $raw);
        if ($clean === null || $clean === '') return null;

        // If both ',' and '.' appear, treat ',' as thousands separator (SA convention
        // is space-separated thousands; but some sources use commas).
        if (str_contains($clean, ',') && str_contains($clean, '.')) {
            $clean = str_replace(',', '', $clean);
        } else {
            // Lone commas → thousands separators ("1,250,000" → "1250000").
            // Lone period → decimal point — leave as-is.
            $clean = str_replace(',', '', $clean);
        }

        if (!is_numeric($clean)) return null;
        $val = (float) $clean;
        return $val > 0 ? $val : null;
    }

    /**
     * Parse SA-style GPS string.
     *   Input:  "30.407214°E 30.807379°S"  (longitude first, latitude second)
     *   Output: ['lat' => -30.807379, 'lng' => 30.407214]
     *
     * Real-world tolerance: the pdftotext upstream extracts the degree symbol as
     * two literal "?" bytes when the source PDF uses a non-standard encoding
     * (confirmed in HFC's existing 277-row data set). The separator pattern
     * accepts °, ??, or no separator at all.
     *
     * Also tolerates:
     *   - Lat-first format: "30.807379°S 30.407214°E"
     *   - Plain "lat,lng" decimal
     *
     * Returns null when no recognisable pattern found.
     */
    public function parseGps(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') return null;

        // Separator class: degree symbol, two question marks (pdftotext mojibake), or nothing.
        $sep = '(?:°|\?\?|\s)*';

        // Empirical SA format: longitude (E/W) first, then latitude (N/S).
        if (preg_match('/([\d.]+)\s*' . $sep . '\s*([EW])\s+([\d.]+)\s*' . $sep . '\s*([NS])/i', $raw, $m)) {
            $lng = (float) $m[1] * (strtoupper($m[2]) === 'W' ? -1 : 1);
            $lat = (float) $m[3] * (strtoupper($m[4]) === 'S' ? -1 : 1);
            return ['lat' => $lat, 'lng' => $lng];
        }

        // Defensive: lat-first format.
        if (preg_match('/([\d.]+)\s*' . $sep . '\s*([NS])\s+([\d.]+)\s*' . $sep . '\s*([EW])/i', $raw, $m)) {
            $lat = (float) $m[1] * (strtoupper($m[2]) === 'S' ? -1 : 1);
            $lng = (float) $m[3] * (strtoupper($m[4]) === 'W' ? -1 : 1);
            return ['lat' => $lat, 'lng' => $lng];
        }

        // Plain decimal "lat,lng".
        if (preg_match('/(-?[\d.]+)\s*,\s*(-?[\d.]+)/', $raw, $m)) {
            return ['lat' => (float) $m[1], 'lng' => (float) $m[2]];
        }

        return null;
    }
}
