<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Stores the free Property24 area pages the extension reads monthly (.ai/specs/mic-work-tab.md §11.2.2): suburb trends,
 * the house numbers P24 lists on a street, and the agencies working a suburb. Global public facts, dated by the SERVER
 * day so a re-read the same day changes nothing and next month adds a new dated row.
 *
 * Never reads or stores a price from the street pages (paid data). Malformed cells are skipped, never an error.
 */
final class P24AreaPageIngest
{
    /** the age-profile columns come labelled by the page, e.g. "Age 36 - 49" */
    private const AGE_SEGMENTS = ['sellers', 'buyers', 'owners'];

    public function trends(int $suburbId, ?string $suburb, ?string $town, array $trends, ?Carbon $day = null): array
    {
        $day = ($day ?? now())->toDateString();
        $rows = [];
        $push = function (string $metric, string $period, string $segment, mixed $value) use (&$rows, $suburbId, $suburb, $town, $day): void {
            if ($value === null || ! is_numeric($value) || $period === '') {
                return;
            }
            $rows[$metric . '|' . $period . '|' . $segment] = [
                'p24_suburb_id' => $suburbId, 'suburb' => $suburb, 'town' => $town, 'metric' => $metric,
                'period' => substr($period, 0, 20), 'segment' => substr($segment, 0, 40), 'value' => (float) $value, 'read_on' => $day,
                'created_at' => now(), 'updated_at' => now(),
            ];
        };
        $d = $trends['datasets'] ?? [];

        // annual sales and prices
        foreach (self::table($d['annual'] ?? null) as $r) {
            $year = self::year($r['Year'] ?? null);
            $push('sales_count', $year, '', $r['NumberOfSales'] ?? null);
            $push('avg_asking_price', $year, '', $r['AverageAskingPrice'] ?? null);
            $push('avg_sold_price', $year, '', $r['AverageSalePrice'] ?? null);
        }
        // monthly stock. The page LABELS each month's total with one figure and plots another; the labelled one is the
        // total the stock-by-bedrooms table adds up to (checked on the Uvongo page: Sep '26 = 788), so it is `stock_total`.
        // The plotted value is kept as `stock_total_plotted`.
        foreach (self::table($d['stock'] ?? null, true) as $r) {
            $month = self::month($r['Month'] ?? null);
            $push('stock_new', $month, '', $r['NewToMarket'] ?? null);
            $labelled = isset($r['TotalOnMarket__f']) ? preg_replace('/\D/', '', (string) $r['TotalOnMarket__f']) : '';
            $push('stock_total', $month, '', $labelled !== '' ? (int) $labelled : ($r['TotalOnMarket'] ?? null));
            if ($labelled !== '') {
                $push('stock_total_plotted', $month, '', $r['TotalOnMarket'] ?? null);
            }
        }
        // average asking price by bedrooms (right now)
        foreach (self::table($d['avg_ask_beds'] ?? null) as $r) {
            $push('avg_asking_by_beds', 'current', (string) ($r['Bedrooms'] ?? ''), $r['AverageListPrice'] ?? null);
        }
        // sold erven vs sectional units. Which graph is which comes from the heading the page prints above it.
        $first = stripos((string) ($trends['sold_labels']['popular1'] ?? ''), 'sectional') !== false ? 'sectional' : 'erven';
        $second = $first === 'erven' ? 'sectional' : 'erven';
        foreach (self::table($d['sold'] ?? null) as $r) {
            $year = self::year($r['Year'] ?? null);
            $push('sold_count', $year, $first, $r['NumberOfProperties_Popular1'] ?? null);
            $push('sold_count', $year, $second, $r['NumberOfProperties_Popular2'] ?? null);
            $push('sold_avg_price', $year, $first, $r['PriceOfProperties_Popular1'] ?? null);
            $push('sold_avg_price', $year, $second, $r['PriceOfProperties_Popular2'] ?? null);
        }
        // seller / buyer / owner age profile (shares 0-1)
        foreach (self::table($d['age'] ?? null) as $r) {
            $who = strtolower(trim((string) ($r['DemographicsType'] ?? '')));
            if (! in_array($who, self::AGE_SEGMENTS, true)) {
                continue;
            }
            foreach ($r as $band => $v) {
                if (stripos((string) $band, 'age') === 0) {
                    $push('age_profile_share', trim((string) $band), $who, $v);
                }
            }
        }
        // monthly stock by number of bedrooms (an HTML table on the page)
        $sbb = $trends['stock_by_beds'] ?? [];
        $months = array_map(fn ($m) => self::month($m), (array) ($sbb['months'] ?? []));
        foreach ((array) ($sbb['rows'] ?? []) as $row) {
            $beds = trim((string) ($row['beds'] ?? ''));
            foreach ((array) ($row['values'] ?? []) as $i => $v) {
                $n = is_numeric($v) ? $v : preg_replace('/[^\d]/', '', (string) $v);
                $push('stock_by_beds', $months[$i] ?? '', $beds, $n === '' ? null : $n);
            }
        }

        $rows = array_values($rows);
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('p24_suburb_trends')->upsert($chunk, ['p24_suburb_id', 'metric', 'period', 'segment', 'read_on'], ['suburb', 'town', 'value', 'updated_at']);
        }

        return ['trend_values' => count($rows), 'metrics' => count(array_unique(array_map(fn ($r) => $r['metric'], $rows)))];
    }

    /** @param list<array<string,mixed>> $agencies */
    public function agencies(int $suburbId, array $agencies, ?Carbon $day = null): array
    {
        $day = ($day ?? now())->toDateString();
        $rows = [];
        foreach ($agencies as $a) {
            $id = preg_replace('/\D/', '', (string) ($a['agency_portal_id'] ?? ''));
            $name = trim((string) ($a['agency_name'] ?? ''));
            if ($id === '' || strlen($id) > 20 || $name === '') {
                continue;
            }
            $rows[$id] = [
                'p24_suburb_id' => $suburbId, 'agency_portal_id' => $id, 'agency_name' => substr($name, 0, 191),
                'listings_in_suburb' => self::nonNegInt($a['listings_in_suburb'] ?? null), 'total_listings' => self::nonNegInt($a['total_listings'] ?? null),
                'read_on' => $day, 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            DB::table('p24_suburb_agencies')->upsert($chunk, ['p24_suburb_id', 'agency_portal_id', 'read_on'], ['agency_name', 'listings_in_suburb', 'total_listings', 'updated_at']);
        }

        return ['agencies' => count($rows)];
    }

    /** @param list<array<string,mixed>> $streets */
    public function streets(int $suburbId, array $streets, ?Carbon $day = null): array
    {
        $day = ($day ?? now())->toDateString();
        $written = 0;
        $new = 0;
        foreach ($streets as $s) {
            $slug = strtolower(trim((string) ($s['street_slug'] ?? '')));
            if (preg_match('/^[a-z0-9][a-z0-9\-]{0,118}$/', $slug) !== 1) {
                continue;
            }
            $name = substr(trim((string) ($s['street_name'] ?? '')), 0, 160) ?: null;
            foreach ((array) ($s['addresses'] ?? []) as $a) {
                $address = trim((string) ($a['address'] ?? ''));
                $number = strtoupper(trim((string) ($a['house_number'] ?? '')));
                if ($number === '' && preg_match('/^\s*(\d+[A-Za-z]?)\b/', $address, $m) === 1) {
                    $number = strtoupper($m[1]);
                }
                if (preg_match('/^\d{1,6}[A-Z]?$/', $number) !== 1) {
                    continue;                      // no usable house number: not a stand we can place
                }
                $year = isset($a['last_sold_year']) && is_numeric($a['last_sold_year']) && $a['last_sold_year'] >= 1900 && $a['last_sold_year'] <= (int) date('Y') + 1
                    ? (int) $a['last_sold_year'] : null;
                $existing = DB::table('p24_street_numbers')->where('p24_suburb_id', $suburbId)->where('street_slug', $slug)->where('house_number', $number)->first();
                if ($existing === null) {
                    DB::table('p24_street_numbers')->insert([
                        'p24_suburb_id' => $suburbId, 'street_slug' => $slug, 'street_name' => $name, 'house_number' => $number,
                        'address_text' => substr($address, 0, 200) ?: null, 'last_sold_year' => $year, 'first_seen_on' => $day, 'last_seen_on' => $day,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $new++;
                } else {
                    DB::table('p24_street_numbers')->where('id', $existing->id)->update(array_filter([
                        'street_name' => $name, 'address_text' => substr($address, 0, 200) ?: null, 'last_sold_year' => $year,
                    ], fn ($v) => $v !== null) + ['last_seen_on' => $day, 'updated_at' => now()]);
                }
                $written++;
            }
        }

        return ['house_numbers' => $written, 'house_numbers_new' => $new];
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────

    /**
     * A Google-charts style table {cols:[{id}], rows:[{c:[{v,f}]}]} as a list of [col id => value] rows. With $keepFormatted
     * the formatted text of each cell is also returned as "<id>__f".
     *
     * @return list<array<string,mixed>>
     */
    private static function table(mixed $t, bool $keepFormatted = false): array
    {
        if (! is_array($t) || ! isset($t['cols'], $t['rows']) || ! is_array($t['cols']) || ! is_array($t['rows'])) {
            return [];
        }
        $ids = array_map(fn ($c) => (string) ($c['id'] ?? ''), $t['cols']);
        $out = [];
        foreach ($t['rows'] as $r) {
            $row = [];
            foreach ((array) ($r['c'] ?? []) as $i => $cell) {
                if (! isset($ids[$i]) || $ids[$i] === '' || ! is_array($cell)) {
                    continue;
                }
                $row[$ids[$i]] = $cell['v'] ?? null;
                if ($keepFormatted && isset($cell['f'])) {
                    $row[$ids[$i] . '__f'] = $cell['f'];
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    private static function year(mixed $v): string
    {
        return preg_match('/^(19|20)\d{2}$/', trim((string) $v)) === 1 ? trim((string) $v) : '';
    }

    /** "Oct '25" -> "2025-10" */
    private static function month(mixed $v): string
    {
        if (preg_match("/^([A-Za-z]{3})[a-z]*\.?\s*'?(\d{2}|\d{4})$/", trim((string) $v), $m) !== 1) {
            return '';
        }
        $mo = array_search(strtolower($m[1]), ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'], true);
        if ($mo === false) {
            return '';
        }
        $y = strlen($m[2]) === 2 ? 2000 + (int) $m[2] : (int) $m[2];

        return sprintf('%04d-%02d', $y, $mo + 1);
    }

    private static function nonNegInt(mixed $v): ?int
    {
        return is_numeric($v) && $v >= 0 && $v < 1_000_000_000 ? (int) $v : null;
    }
}
