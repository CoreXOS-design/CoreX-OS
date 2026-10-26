<?php

declare(strict_types=1);

namespace App\Services\MarketReports;

use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportCompRow;
use App\Models\Prospecting\TrackedProperty;
use App\Services\Address\AddressParser;
use App\Services\MarketReports\DTOs\MarketReportParseResult;
use Carbon\Carbon;

/**
 * The "canonical read": ONE shape that both the AI read and a learned layout
 * produce, turned into the SAME MarketReportParseResult the CMA Info parsers
 * return (data points, comp rows, subject meta, addresses). Spec: mic-complete-spec §8.9.
 *
 * sanitise() is the gate: nothing the AI (or a mapping) produced reaches the
 * database unless it is a plausible value. A bad value is dropped and counted,
 * never stored and never "corrected".
 */
final class CanonicalReadMapper
{
    public const PRICE_MIN = 50_000;
    public const PRICE_MAX = 50_000_000;

    /** stat metric → market_data_points.metric_key (existing keys the CMA Info parsers already write) */
    public const STAT_METRICS = [
        'median_price'     => 'suburb_median_price_year',
        'average_price'    => 'suburb_average_price_year',
        'sales_count'      => 'suburb_sales_count_year',
        'annual_change_pct' => 'suburb_annual_change_pct',
        'median_price_12m' => 'suburb_median_price_12m',
        'total_sales_12m'  => 'suburb_total_sales_12m',
    ];

    private const YEARLESS_STATS = ['median_price_12m', 'total_sales_12m'];
    private const PRICE_STATS    = ['median_price', 'average_price', 'median_price_12m'];

    /**
     * @param  array<string, mixed> $raw
     * @return array{clean: array<string, mixed>, dropped: array<int, string>}
     */
    public function sanitise(array $raw): array
    {
        $dropped = [];
        $clean = [
            'publisher'    => $this->text($raw['publisher'] ?? null, 160),
            'report_title' => $this->text($raw['report_title'] ?? null, 200),
            'suburb'       => $this->text($raw['suburb'] ?? null, 120),
            'town'         => $this->text($raw['town'] ?? null, 120),
            'subject'      => [],
            'valuation'    => [],
            'comps'        => [],
            'suburb_stats' => [],
        ];

        $subject = is_array($raw['subject'] ?? null) ? $raw['subject'] : [];
        $clean['subject'] = array_filter([
            'address'        => $this->text($subject['address'] ?? null, 255),
            'scheme_name'    => $this->text($subject['scheme_name'] ?? null, 200),
            'section_number' => $this->text($subject['section_number'] ?? null, 40),
            'extent_m2'      => $this->intInRange($subject['extent_m2'] ?? null, 1, 1_000_000, 'subject.extent_m2', $dropped),
            'latitude'       => $this->floatInRange($subject['latitude'] ?? null, -90, 90, 'subject.latitude', $dropped),
            'longitude'      => $this->floatInRange($subject['longitude'] ?? null, -180, 180, 'subject.longitude', $dropped),
            'radius_metres'  => $this->intInRange($subject['radius_metres'] ?? null, 1, 100_000, 'subject.radius_metres', $dropped),
        ], fn ($v) => $v !== null && $v !== '');

        $valuation = is_array($raw['valuation'] ?? null) ? $raw['valuation'] : [];
        foreach (['lower', 'middle', 'upper', 'average'] as $k) {
            $v = $this->intInRange($valuation[$k] ?? null, self::PRICE_MIN, self::PRICE_MAX, "valuation.$k", $dropped);
            if ($v !== null) {
                $clean['valuation'][$k] = $v;
            }
        }
        $perM2 = $this->intInRange($valuation['average_r_per_m2'] ?? null, 100, 1_000_000, 'valuation.average_r_per_m2', $dropped);
        if ($perM2 !== null) {
            $clean['valuation']['average_r_per_m2'] = $perM2;
        }

        foreach ((array) ($raw['comps'] ?? []) as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $kind = ($row['kind'] ?? 'comp') === 'listing' ? 'listing' : 'comp';
            $c = [
                'kind'                  => $kind,
                'address'               => $this->text($row['address'] ?? null, 255),
                'scheme_name'           => $this->text($row['scheme_name'] ?? null, 200),
                'section_number'        => $this->text($row['section_number'] ?? null, 40),
                'property_type'         => $this->text($row['property_type'] ?? null, 60),
                'suburb'                => $this->text($row['suburb'] ?? null, 120),
                'extent_m2'             => $this->intInRange($row['extent_m2'] ?? null, 1, 1_000_000, "comps.$i.extent_m2", $dropped),
                'sale_date'             => $this->date($row['sale_date'] ?? null, "comps.$i.sale_date", $dropped),
                'sale_price'            => $this->intInRange($row['sale_price'] ?? null, self::PRICE_MIN, self::PRICE_MAX, "comps.$i.sale_price", $dropped),
                'list_price'            => $this->intInRange($row['list_price'] ?? null, self::PRICE_MIN, self::PRICE_MAX, "comps.$i.list_price", $dropped),
                'r_per_m2'              => $this->intInRange($row['r_per_m2'] ?? null, 100, 1_000_000, "comps.$i.r_per_m2", $dropped),
                'municipal_valuation'   => $this->intInRange($row['municipal_valuation'] ?? null, self::PRICE_MIN, self::PRICE_MAX, "comps.$i.municipal_valuation", $dropped),
                'distance_to_subject_m' => $this->intInRange($row['distance_to_subject_m'] ?? null, 0, 100_000, "comps.$i.distance_to_subject_m", $dropped),
                'days_on_market'        => $this->intInRange($row['days_on_market'] ?? null, 0, 3650, "comps.$i.days_on_market", $dropped),
            ];
            $keyField = $kind === 'comp' ? 'sale_price' : 'list_price';
            if ($c[$keyField] === null) {
                $dropped[] = "comps.$i: no usable $keyField — row dropped";
                continue;
            }
            $clean['comps'][] = array_filter($c, fn ($v) => $v !== null && $v !== '');
        }

        $thisYear = (int) now()->format('Y');
        foreach ((array) ($raw['suburb_stats'] ?? []) as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $metric = is_string($row['metric'] ?? null) ? $row['metric'] : '';
            if (!isset(self::STAT_METRICS[$metric])) {
                $dropped[] = "suburb_stats.$i: unknown metric — row dropped";
                continue;
            }
            $year = null;
            if (!in_array($metric, self::YEARLESS_STATS, true)) {
                $year = $this->intInRange($row['year'] ?? null, 1990, $thisYear, "suburb_stats.$i.year", $dropped);
                if ($year === null) {
                    continue;
                }
            }
            $value = in_array($metric, self::PRICE_STATS, true)
                ? $this->intInRange($row['value'] ?? null, self::PRICE_MIN, self::PRICE_MAX, "suburb_stats.$i.value", $dropped)
                : $this->floatInRange($row['value'] ?? null, -100_000, 10_000_000, "suburb_stats.$i.value", $dropped);
            if ($value === null) {
                continue;
            }
            $clean['suburb_stats'][] = array_filter([
                'metric' => $metric,
                'year'   => $year,
                'value'  => $value,
                'suburb' => $this->text($row['suburb'] ?? null, 120),
                'town'   => $this->text($row['town'] ?? null, 120),
            ], fn ($v) => $v !== null && $v !== '');
        }

        $clean['publisher'] = $clean['publisher'] ?: null;

        return ['clean' => $clean, 'dropped' => $dropped];
    }

    /** True when a sanitised read carries nothing worth storing. */
    public function isEmpty(array $clean): bool
    {
        return empty($clean['comps']) && empty($clean['suburb_stats']) && empty($clean['valuation']);
    }

    /**
     * @param array<string, mixed> $clean  output of sanitise()['clean']
     * @param array<string, mixed> $rawJson  provenance written to market_reports.raw_extracted_json
     */
    public function toParseResult(array $clean, MarketReport $report, array $rawJson = []): MarketReportParseResult
    {
        $today = now()->toDateString();

        $reportSuburbRaw = filled($report->source_suburb) ? (string) $report->source_suburb : ($clean['suburb'] ?? null);
        $reportSuburb = $reportSuburbRaw ? TrackedProperty::normaliseSuburb($reportSuburbRaw) : null;
        $town = filled($report->source_town) ? (string) $report->source_town : ($clean['town'] ?? null);

        $subjectMeta = [];
        $subject = $clean['subject'] ?? [];
        foreach ([
            'address'        => 'subject_address',
            'scheme_name'    => 'subject_scheme_name',
            'section_number' => 'subject_section_number',
            'latitude'       => 'subject_latitude',
            'longitude'      => 'subject_longitude',
            'extent_m2'      => 'subject_extent_m2',
            'radius_metres'  => 'radius_metres',
        ] as $from => $to) {
            if (isset($subject[$from])) {
                $subjectMeta[$to] = $subject[$from];
            }
        }
        if (!filled($report->source_suburb) && !empty($clean['suburb'])) {
            $subjectMeta['source_suburb'] = $clean['suburb'];
        }
        if (!filled($report->source_town) && !empty($clean['town'])) {
            $subjectMeta['source_town'] = $clean['town'];
        }

        $points = [];
        $addresses = [];
        $compRows = [];

        $valuationKeys = ['lower' => 'cma_value_lower', 'middle' => 'cma_value_middle', 'upper' => 'cma_value_upper',
            'average' => 'cma_value_average', 'average_r_per_m2' => 'cma_value_average_r_per_m2'];
        foreach ($valuationKeys as $field => $metricKey) {
            if (isset($clean['valuation'][$field])) {
                $points[] = [
                    'metric_key'           => $metricKey,
                    'metric_value_numeric' => (float) $clean['valuation'][$field],
                    'metric_date'          => $today,
                    'confidence'           => 'medium',
                    'suburb_normalised'    => $reportSuburb,
                    'town'                 => $town,
                ];
            }
        }

        foreach (($clean['comps'] ?? []) as $index => $c) {
            $isComp = ($c['kind'] ?? 'comp') === 'comp';
            $suburbNorm = isset($c['suburb']) ? TrackedProperty::normaliseSuburb($c['suburb']) : $reportSuburb;

            $compRows[] = [
                'row_index'             => $index,
                'row_type'              => $isComp ? MarketReportCompRow::ROW_COMP : MarketReportCompRow::ROW_LISTING,
                'scheme_name'           => $c['scheme_name'] ?? null,
                'section_number'        => $c['section_number'] ?? null,
                'address'               => $c['address'] ?? null,
                'suburb_normalised'     => $suburbNorm,
                'property_type'         => $c['property_type'] ?? null,
                'extent_m2'             => $c['extent_m2'] ?? null,
                'sale_date'             => $c['sale_date'] ?? null,
                'sale_price'            => $c['sale_price'] ?? null,
                'list_price'            => $c['list_price'] ?? null,
                'r_per_m2'              => $c['r_per_m2'] ?? null,
                'municipal_valuation'   => $c['municipal_valuation'] ?? null,
                'distance_to_subject_m' => $c['distance_to_subject_m'] ?? null,
                'days_on_market'        => $c['days_on_market'] ?? null,
                'raw_row_json'          => $c,
            ];

            if ($isComp) {
                $points[] = [
                    'metric_key'           => 'comparable_sale_price',
                    'metric_value_numeric' => (float) $c['sale_price'],
                    'metric_date'          => $c['sale_date'] ?? $today,
                    'confidence'           => 'medium',
                    'suburb_normalised'    => $suburbNorm,
                    'town'                 => $town,
                ];
                if (!empty($c['address'])) {
                    $addresses[] = $this->address($c, $c['suburb'] ?? $reportSuburbRaw, $town);
                }
            }
        }

        foreach (($clean['suburb_stats'] ?? []) as $s) {
            $areaRaw = $s['suburb'] ?? $reportSuburbRaw;
            $points[] = [
                'metric_key'           => self::STAT_METRICS[$s['metric']],
                'metric_value_numeric' => (float) $s['value'],
                'metric_date'          => isset($s['year']) ? $s['year'] . '-12-31' : $today,
                'confidence'           => 'medium',
                'suburb_normalised'    => $areaRaw ? TrackedProperty::normaliseSuburb($areaRaw) : null,
                'town'                 => $s['town'] ?? $town,
            ];
        }

        return new MarketReportParseResult(
            dataPoints:         $points,
            extractedAddresses: $addresses,
            rawJson:            array_merge($rawJson, [
                'publisher'    => $clean['publisher'] ?? null,
                'report_title' => $clean['report_title'] ?? null,
                'comp_rows'    => count($compRows),
                'stat_rows'    => count($clean['suburb_stats'] ?? []),
            ]),
            subjectMeta:        $subjectMeta,
            compRows:           $compRows,
        );
    }

    /** @param array<string, mixed> $c */
    private function address(array $c, ?string $suburb, ?string $town): array
    {
        $bits = [
            'street_number' => null,
            'street_name'   => $c['address'],
            'suburb'        => $suburb,
            'town'          => $town,
            'sale_price'    => $c['sale_price'] ?? null,
            'sale_date'     => $c['sale_date'] ?? null,
        ];
        // The plot size printed against a sale belongs to the property: a full title's is its erf, a sectional
        // unit's is its own section extent. A type the report does not state either way is left unwritten.
        $extent = $c['extent_m2'] ?? null;
        $type   = (string) ($c['property_type'] ?? '');
        if ($extent !== null && preg_match('/sectional/i', $type)) {
            $bits['section_extent_m2'] = $extent;
        } elseif ($extent !== null && preg_match('/full\s*title|freehold/i', $type)) {
            $bits['erf_size_m2'] = $extent;
        }
        try {
            $read = (new AddressParser())->parse([
                'street_number' => null,
                'street_name'   => $c['address'],
                'suburb'        => $suburb,
                'town'          => $town,
            ]);
            if (($read['status'] ?? null) === 'parsed' && ($read['street_core'] ?? null) !== null) {
                $bits['street_name']   = $read['street_name_clean'];
                $bits['street_number'] = $read['street_number'] ?? null;
            }
        } catch (\Throwable) {
            // never let reading an address stop a report import
        }

        return array_filter($bits, fn ($v) => $v !== null && $v !== '');
    }

    // ── value gates ──────────────────────────────────────────────────────

    private function text(mixed $v, int $max): ?string
    {
        if (!is_string($v) && !is_numeric($v)) {
            return null;
        }
        $s = trim((string) preg_replace('/\s+/u', ' ', (string) $v));

        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    private function number(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (!is_string($v)) {
            return null;
        }
        $s = preg_replace('/[^\d.\-]/', '', $v) ?? '';
        if ($s === '' || $s === '-' || $s === '.' || !is_numeric($s)) {
            return null;
        }

        return (float) $s;
    }

    /** @param array<int, string> $dropped */
    private function intInRange(mixed $v, int $min, int $max, string $field, array &$dropped): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        $n = $this->number($v);
        if ($n === null || $n < $min || $n > $max) {
            $dropped[] = "$field: out of range or unreadable — value dropped";

            return null;
        }

        return (int) round($n);
    }

    /** @param array<int, string> $dropped */
    private function floatInRange(mixed $v, float $min, float $max, string $field, array &$dropped): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        $n = $this->number($v);
        if ($n === null || $n < $min || $n > $max) {
            $dropped[] = "$field: out of range or unreadable — value dropped";

            return null;
        }

        return $n;
    }

    /** @param array<int, string> $dropped */
    private function date(mixed $v, string $field, array &$dropped): ?string
    {
        if (!is_string($v) || trim($v) === '') {
            return null;
        }
        try {
            $d = Carbon::parse($v);
        } catch (\Throwable) {
            $dropped[] = "$field: unreadable date — value dropped";

            return null;
        }
        if ($d->year < 1990 || $d->isFuture()) {
            $dropped[] = "$field: implausible date — value dropped";

            return null;
        }

        return $d->toDateString();
    }
}
