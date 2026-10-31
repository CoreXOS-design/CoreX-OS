<?php

declare(strict_types=1);

namespace App\Services\Properties;

use App\Models\MicAgencySetting;
use App\Models\Property;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MIC step 5.4 (.ai/specs/mic-work-tab.md section 11.4, AT-524): `properties.under_offer_at` and `properties.sold_at`.
 *
 * ONE place sets them going forward: recordTransition(), called from PropertyObserver::saved on a status change (before the sold-stock
 * snapshot reads the date). The columns describe the CURRENT state:
 *   - moving INTO under_offer stamps under_offer_at (and clears sold_at, a sale that fell back to an offer);
 *   - moving INTO sold / transferred stamps sold_at (under_offer_at stays: it is the offer that led to the sale); sold -> transferred keeps both;
 *   - moving to any other status clears both (offer fell through, sale reversed, withdrawn, expired ...).
 * Written with a plain UPDATE (no model events, no updated_at bump). `sold_by_3rd_party`, `rented` and `let_out` are not our sale and never stamp.
 * backfill() fills still-empty columns from audit `status_changed` events on or after the agency's trusted-sales date (default 26 Jun 2026, the
 * day after the bulk import) - never from sold_stock's 17 Sep backfill date - and corrects sold_stock rows that carry the backfill day.
 */
final class PropertyOfferSoldDates
{
    public const STEP = '5.4';

    private const SOLD_KEYS = ['sold', 'transferred'];
    private const OFFER_KEY = 'under_offer';

    public static function key(?string $status): string
    {
        return trim((string) preg_replace('/[\s\-]+/', '_', strtolower(trim((string) $status))), '_');
    }

    /** Status-change hook (PropertyObserver::saved). Never throws into the save. */
    public function recordTransition(Property $property, ?CarbonInterface $at = null): void
    {
        $old = (string) $property->getOriginal('status');
        $new = (string) $property->status;
        if (self::key($old) === self::key($new)) {
            return;
        }

        $at ??= now();
        $cur = DB::table('properties')->where('id', $property->id)->first(['under_offer_at', 'sold_at']);
        if (! $cur) {
            return;
        }
        $next = self::decide($old, $new, $cur->under_offer_at ? Carbon::parse($cur->under_offer_at) : null, $cur->sold_at ? Carbon::parse($cur->sold_at) : null, $at);

        $dirty = [];
        foreach (['under_offer_at', 'sold_at'] as $col) {
            $was = $cur->{$col} ? Carbon::parse($cur->{$col})->toDateTimeString() : null;
            $now = $next[$col]?->toDateTimeString();
            if ($was !== $now) {
                $dirty[$col] = $now;
            }
        }
        if ($dirty) {
            DB::table('properties')->where('id', $property->id)->update($dirty); // no updated_at bump, no model events
            foreach ($dirty as $col => $value) {
                $property->setAttribute($col, $value);
            }
            $property->syncOriginalAttributes(array_keys($dirty));
        }
    }

    /**
     * Pure: the two dates after a status moves old -> new.
     *
     * @return array{under_offer_at: ?CarbonInterface, sold_at: ?CarbonInterface}
     */
    public static function decide(?string $old, ?string $new, ?CarbonInterface $offerAt, ?CarbonInterface $soldAt, CarbonInterface $at): array
    {
        $o = self::key($old);
        $n = self::key($new);
        if ($o === $n) {
            return ['under_offer_at' => $offerAt, 'sold_at' => $soldAt];
        }
        if ($n === self::OFFER_KEY) {
            return ['under_offer_at' => $at, 'sold_at' => null];
        }
        if (in_array($n, self::SOLD_KEYS, true)) {
            return ['under_offer_at' => $offerAt, 'sold_at' => in_array($o, self::SOLD_KEYS, true) ? $soldAt : $at];
        }

        return ['under_offer_at' => null, 'sold_at' => null];
    }

    /**
     * Fill empty under_offer_at / sold_at from trustworthy audit events, then correct sold_stock rows whose sale date is the old fallback (property last-update date) or pre-dates the trusted date.
     *
     * @return array<string, mixed>
     */
    public function backfill(?int $agencyId, bool $apply, ?string $runId = null): array
    {
        $runId ??= (string) Str::uuid();
        $r = [
            'run_id' => $runId, 'apply' => $apply, 'agencies' => [], 'events_considered' => 0, 'events_excluded_console' => 0, 'events_excluded_import' => 0,
            'events_excluded_bulk_days' => 0, 'bulk_days' => [], 'skipped_mismatch' => 0, 'under_offer_filled' => 0, 'sold_filled' => 0, 'sold_stock_corrected' => 0, 'event_days' => [], 'by_suburb' => [],
        ];

        $agencies = DB::table('properties')->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))->distinct()->pluck('agency_id');
        foreach ($agencies as $aid) {
            $cutoff = MicAgencySetting::for((int) $aid)->trustedSalesAfter();
            $r['agencies'][(int) $aid] = $cutoff;
            $this->backfillAgency((int) $aid, $cutoff . ' 00:00:00', MicAgencySetting::for((int) $aid)->bulkStatusDayEvents(), $apply, $runId, $r);
            $this->correctSoldStock((int) $aid, $cutoff, $apply, $runId, $r);
        }
        arsort($r['event_days']);
        $r['event_days'] = array_slice($r['event_days'], 0, 8, true);
        $r['by_suburb'] = $this->daysBySuburb($agencyId);

        return $r;
    }

    /** Own sales that have a listed date and a sold date: days listed -> offer and listed -> sold, per P24 suburb. */
    public function daysBySuburb(?int $agencyId): array
    {
        $rows = DB::table('properties as p')->leftJoin('p24_suburbs as s', 's.id', '=', 'p.p24_suburb_id')
            ->whereNull('p.deleted_at')->whereNotNull('p.sold_at')->whereNotNull('p.listed_date')
            ->whereRaw('DATE(p.sold_at) >= p.listed_date')
            ->when($agencyId, fn ($q) => $q->where('p.agency_id', $agencyId))
            ->selectRaw('COALESCE(s.name, "(no suburb id)") as suburb, COUNT(*) as sales, ROUND(AVG(DATEDIFF(p.sold_at, p.listed_date)), 1) as avg_days_to_sale, '
                . 'SUM(p.under_offer_at IS NOT NULL AND DATE(p.under_offer_at) >= p.listed_date) as with_offer, '
                . 'ROUND(AVG(CASE WHEN p.under_offer_at IS NOT NULL AND DATE(p.under_offer_at) >= p.listed_date THEN DATEDIFF(p.under_offer_at, p.listed_date) END), 1) as avg_days_to_offer')
            ->groupBy('p.p24_suburb_id', 's.name')->orderByDesc('sales')->get();

        return $rows->map(fn ($x) => (array) $x)->all();
    }

    /** @param array<string, mixed> $r */
    private function backfillAgency(int $agencyId, string $cutoff, int $bulkDayEvents, bool $apply, string $runId, array &$r): void
    {
        $events = DB::table('property_audit_log as a')->join('properties as p', 'p.id', '=', 'a.property_id')
            ->where('a.agency_id', $agencyId)->where('a.event_type', 'status_changed')->where('a.created_at', '>=', $cutoff)
            ->whereNull('p.deleted_at')
            ->orderBy('a.property_id')->orderBy('a.created_at')->orderBy('a.id')
            ->get(['a.property_id', 'a.old_values', 'a.new_values', 'a.created_at', 'a.source', 'p.status', 'p.p24_imported_at', 'p.under_offer_at as cur_offer', 'p.sold_at as cur_sold']);

        // Catch-up days: this many sale-side status changes (into Under Offer / Sold) on ONE day is data entry after the fact, not the sales themselves.
        $perDay = [];
        foreach ($events as $e) {
            if (str_starts_with((string) $e->source, 'console:') || $this->isImportEvent($e)) {
                continue;
            }
            if (self::isSaleSide(json_decode((string) $e->new_values, true)['status'] ?? null)) {
                $day = substr((string) $e->created_at, 0, 10);
                $perDay[$day] = ($perDay[$day] ?? 0) + 1;
            }
        }
        $bulkDays = array_keys(array_filter($perDay, fn ($n) => $n >= $bulkDayEvents));
        foreach ($bulkDays as $day) {
            $r['bulk_days'][$agencyId . ' ' . $day] = $perDay[$day];
        }

        $by = [];
        foreach ($events as $e) {
            $by[$e->property_id][] = $e;
        }

        foreach ($by as $propertyId => $list) {
            $kept = [];
            foreach ($list as $e) {
                $r['events_considered']++;
                if (str_starts_with((string) $e->source, 'console:')) {
                    $r['events_excluded_console']++;
                    continue;
                }
                if ($this->isImportEvent($e)) {
                    $r['events_excluded_import']++;
                    continue;
                }
                if ($bulkDays && in_array(substr((string) $e->created_at, 0, 10), $bulkDays, true)
                    && self::isSaleSide(json_decode((string) $e->new_values, true)['status'] ?? null)) {
                    $r['events_excluded_bulk_days']++;
                    continue;
                }
                $kept[] = $e;
            }
            if (! $kept) {
                continue;
            }

            $offer = null;
            $sold = null;
            $last = null;
            foreach ($kept as $e) {
                $old = json_decode((string) $e->old_values, true)['status'] ?? null;
                $new = json_decode((string) $e->new_values, true)['status'] ?? null;
                $d = self::decide($old, $new, $offer, $sold, Carbon::parse($e->created_at));
                $offer = $d['under_offer_at'];
                $sold = $d['sold_at'];
                $last = $new;
            }
            $current = $kept[0]->status;
            if (self::key($last) !== self::key($current)) {
                $r['skipped_mismatch']++; // the log does not end where the property is: not trustworthy
                continue;
            }

            $fill = [];
            if ($offer !== null && $kept[0]->cur_offer === null) {
                $fill['under_offer_at'] = $offer->toDateTimeString();
            }
            if ($sold !== null && $kept[0]->cur_sold === null) {
                $fill['sold_at'] = $sold->toDateTimeString();
            }
            if (! $fill) {
                continue;
            }
            foreach ($fill as $col => $value) {
                $r[$col === 'sold_at' ? 'sold_filled' : 'under_offer_filled']++;
                $day = substr($value, 0, 10);
                $r['event_days'][$col . ' ' . $day] = ($r['event_days'][$col . ' ' . $day] ?? 0) + 1;
            }
            if ($apply) {
                DB::transaction(function () use ($agencyId, $propertyId, $fill, $runId) {
                    foreach ($fill as $col => $value) {
                        $this->journal($runId, $agencyId, 'properties', (int) $propertyId, $col, null, $value);
                    }
                    DB::table('properties')->where('id', $propertyId)->update($fill);
                });
            }
        }
    }

    /**
     * A backfilled sold_stock row whose sale date is NOT trustworthy gets the property's real sold_at. Untrusted = the old fallback (the property's
     * last update date, 434 rows on 17 Sep) or a date before the agency's trusted-sales date. A row with a real date is never touched.
     */
    private function correctSoldStock(int $agencyId, string $trustedAfter, bool $apply, string $runId, array &$r): void
    {
        $rows = DB::table('sold_stock as s')->join('properties as p', 'p.id', '=', 's.property_id')
            ->where('s.agency_id', $agencyId)->whereNull('s.deleted_at')->where('s.source', 'backfill')
            ->whereNotNull('p.sold_at')->whereRaw('DATE(p.sold_at) <> s.sale_date')
            ->where(fn ($q) => $q->whereRaw('s.sale_date = DATE(p.updated_at)')->orWhere('s.sale_date', '<=', $trustedAfter))
            ->get(['s.id', 's.sale_date', 's.listed_date', 's.days_on_market', 'p.sold_at']);

        foreach ($rows as $row) {
            $date = substr((string) $row->sold_at, 0, 10);
            $days = $row->listed_date && $date >= (string) $row->listed_date
                ? (int) Carbon::parse($row->listed_date)->diffInDays(Carbon::parse($date)) : null;
            $r['sold_stock_corrected']++;
            if ($apply) {
                DB::transaction(function () use ($agencyId, $row, $date, $days, $runId) {
                    $this->journal($runId, $agencyId, 'sold_stock', (int) $row->id, 'sale_date', (string) $row->sale_date, $date);
                    $update = ['sale_date' => $date];
                    if ($row->listed_date) {
                        $this->journal($runId, $agencyId, 'sold_stock', (int) $row->id, 'days_on_market', $row->days_on_market === null ? null : (string) $row->days_on_market, $days === null ? null : (string) $days);
                        $update['days_on_market'] = $days;
                    }
                    DB::table('sold_stock')->where('id', $row->id)->update($update); // portal_sold_until is deliberately NOT touched
                });
            }
        }
    }

    private function isImportEvent(object $e): bool
    {
        if ($e->p24_imported_at === null) {
            return false;
        }
        $imp = Carbon::parse($e->p24_imported_at);
        $at = Carbon::parse($e->created_at);

        return $at->isSameDay($imp) || abs($at->diffInHours($imp, false)) < 24;
    }

    private static function isSaleSide(?string $status): bool
    {
        $k = self::key($status);

        return $k === self::OFFER_KEY || in_array($k, self::SOLD_KEYS, true);
    }

    /** Put every value a run wrote back (only where the column still holds what the run wrote). */
    public function rollback(string $runId): int
    {
        $restored = 0;
        DB::table('mic_data_journals')->where('run_id', $runId)->where('step', self::STEP)->orderBy('id')
            ->chunkById(500, function ($rows) use (&$restored) {
                foreach ($rows as $j) {
                    $current = DB::table($j->table_name)->where('id', $j->row_id)->value($j->column_name);
                    if ($current === null ? $j->new_value === null : (string) $current === (string) $j->new_value) {
                        DB::table($j->table_name)->where('id', $j->row_id)->update([$j->column_name => $j->old_value]);
                        $restored++;
                    }
                }
            });

        return $restored;
    }

    private function journal(string $runId, int $agencyId, string $table, int $rowId, string $column, ?string $old, ?string $new): void
    {
        DB::table('mic_data_journals')->insert([
            'run_id' => $runId, 'step' => self::STEP, 'agency_id' => $agencyId, 'table_name' => $table, 'row_id' => $rowId,
            'column_name' => $column, 'old_value' => $old, 'new_value' => $new, 'created_at' => now(),
        ]);
    }
}
