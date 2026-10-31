<?php

declare(strict_types=1);

namespace App\Services\MarketIntelligence\PropertyTypes;

use App\Models\PropertyTypeMap;
use Illuminate\Support\Facades\DB;

/**
 * MIC 5.2 (.ai/specs/mic-work-tab.md section 8): the one place a raw property-type text becomes a canonical type_key
 * (house, apartment, townhouse, land, farm, commercial, industrial, other). An agency row beats the global row; text nobody has
 * mapped returns NULL - it is reported (see report()), never guessed.
 */
final class PropertyTypeResolver
{
    /** @var array<string, array<string, ?string>> agency key => raw_key => type_key */
    private static array $cache = [];

    public static function normalise(?string $raw): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $raw)));
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    public function keyFor(?string $raw, ?int $agencyId = null): ?string
    {
        $raw = self::normalise($raw);
        if ($raw === '') {
            return null;
        }

        return $this->map($agencyId)[$raw] ?? null;
    }

    /** @return array<string, string> raw_key => type_key for this agency (global rows overlaid by the agency's own). */
    public function map(?int $agencyId = null): array
    {
        $k = (string) ($agencyId ?? 0);

        return self::$cache[$k] ??= PropertyTypeMap::query()
            ->where(fn ($q) => $q->whereNull('agency_id')->when($agencyId, fn ($q2) => $q2->orWhere('agency_id', $agencyId)))
            ->orderByRaw('agency_id IS NULL DESC')   // global first, agency rows overwrite
            ->get(['raw_key', 'type_key'])
            ->pluck('type_key', 'raw_key')
            ->all();
    }

    /**
     * Every raw value held in the MIC-relevant tables, with its row count and mapped key (NULL = unmapped).
     *
     * @return array<string, list<array{raw: string, rows: int, type_key: ?string}>>
     */
    public function report(?int $agencyId = null): array
    {
        $sources = [
            'properties'              => ['properties', 'property_type', true],
            'prospecting_listings'    => ['prospecting_listings', 'property_type', false],
            'market_report_comp_rows' => ['market_report_comp_rows', 'property_type', true],
            'p24_listings'            => ['p24_listings', 'property_type', true],
            'tracked_properties'      => ['tracked_properties', 'property_type', true],
        ];
        $out = [];
        foreach ($sources as $label => [$table, $col, $softDeletes]) {
            $q = DB::table($table)->selectRaw("LOWER(TRIM({$col})) as raw, COUNT(*) as n")->groupBy('raw');
            if ($softDeletes) {
                $q->whereNull('deleted_at');
            }
            if ($agencyId !== null) {
                $q->where('agency_id', $agencyId);
            }
            $rows = [];
            foreach ($q->get() as $r) {
                $raw = (string) ($r->raw ?? '');
                $rows[] = ['raw' => $raw, 'rows' => (int) $r->n, 'type_key' => $raw === '' ? null : $this->keyFor($raw, $agencyId)];
            }
            usort($rows, fn ($a, $b) => $b['rows'] <=> $a['rows']);
            $out[$label] = $rows;
        }

        return $out;
    }
}
