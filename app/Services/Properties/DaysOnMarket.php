<?php

declare(strict_types=1);

namespace App\Services\Properties;

use App\Models\Property;
use App\Support\HumanDiff;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * THE one days-on-market calculation (hotfix 2026-10-08, Johan's rule).
 *
 * Days on market = days since the START OF THE CURRENT ON-MARKET PERIOD, i.e. since the
 * current advert went live. Every screen that shows it (Intelligence tab tile + comparable
 * listings, seller live link, property header, mobile + client app, Core Matches,
 * recommendations, Command Centre, presentation snapshots, third-party-sale record, suburb
 * report) calls this class, so two screens can never disagree.
 *
 * Rules:
 *  C. Not currently on the market (prospecting, draft, sold, withdrawn, expired, archived, or
 *     deactivated on the portal) -> no figure (null).
 *  A. Start of the current period = the most recent of: the last status change INTO an
 *     on-market status (property_audit_log), a takeover (imported_released_at), and - when one
 *     follows - the first successful Property24 submit / Private Property activation after that
 *     boundary (the advert actually going live). Other Agency Stock: the portal's own listing
 *     date captured at import (listed_date).
 *  B. Never created_at, p24_imported_at, or any activation/listing date on the import day: for
 *     imported stock those are import artefacts. A listed_date set on the import day is unknown.
 *  D. No proof of the current start -> null. Agents see "—", the seller link hides the figure.
 *     No "at least N", no guesses.
 *
 * Stock created in CoreX with no status history (created straight into an on-market status):
 * the earliest proof the advert went live (website publish, first P24 submit, PP / P24
 * activation), else its listing date - which CoreX itself stamped at
 * creation. Off-market statuses never count.
 */
final class DaysOnMarket
{
    public const ON_MARKET_STATUSES = ['active', 'for_sale', 'to_let', 'under_offer', 'on_show'];

    public static function for(Property $property): ?int
    {
        return app(self::class)->days($property);
    }

    public function days(Property $property): ?int
    {
        $start = $this->startDates([$property])[$property->id] ?? null;

        return $start ? HumanDiff::daysBetween($start) : null;
    }

    /**
     * @param  iterable<Property> $properties
     * @return array<int, ?int> property id => whole days, or null when unknown / not on market
     */
    public function forMany(iterable $properties): array
    {
        $out = [];
        foreach ($this->startDates($properties) as $id => $start) {
            $out[$id] = $start ? HumanDiff::daysBetween($start) : null;
        }

        return $out;
    }

    public function startDate(Property $property): ?Carbon
    {
        return $this->startDates([$property])[$property->id] ?? null;
    }

    /**
     * @param  iterable<Property> $properties
     * @return array<int, ?Carbon>
     */
    public function startDates(iterable $properties): array
    {
        $list = [];
        foreach ($properties as $p) {
            $list[$p->id] = $p;
        }
        if ($list === []) {
            return [];
        }

        $ids     = array_keys($list);
        $audit   = $this->statusTransitions($ids);
        $p24Logs = $this->p24Logs($ids);

        $out = [];
        foreach ($list as $id => $p) {
            $out[$id] = $this->resolve($p, $audit[$id] ?? [], $p24Logs[$id] ?? ['submits' => [], 'status_updates' => []]);
        }

        return $out;
    }

    /**
     * @param  array<int, array{at:Carbon, from:?string, to:?string}> $transitions oldest first
     * @param  array{submits: Carbon[], status_updates: Carbon[]}      $logs
     */
    private function resolve(Property $p, array $transitions, array $logs): ?Carbon
    {
        $inferred = $this->inferredStart($p, $transitions, $logs);
        $listed   = $this->realListedDate($p);

        // A REAL listing date (e.g. set by hand to the portal's own date) is trusted and is the start of
        // the count - unless a later re-publish / move on-market proves the current advert went live
        // after it.
        if ($listed && $inferred) {
            return $listed->gt($inferred) ? $listed : $inferred;
        }

        return $listed ?? $inferred;
    }

    /**
     * listed_date when it is a real date: for imported stock any listing date that is NOT the same
     * calendar day as the import / created day (those are import artefacts); for CoreX-created stock
     * the date CoreX itself stamped. Null otherwise. Only meaningful while the property is on market.
     */
    private function realListedDate(Property $p): ?Carbon
    {
        $status = strtolower(trim((string) $p->status));
        if (!$p->listed_date
            || $status === Property::STATUS_OTHER_AGENCY_STOCK
            || !in_array($status, self::ON_MARKET_STATUSES, true)
            || $this->deactivatedOnPortals($p)) {
            return null;
        }

        $listed = Carbon::parse($p->listed_date)->startOfDay();
        if ($p->p24_imported_at !== null) {
            if ($listed->isSameDay(Carbon::parse($p->p24_imported_at))
                || ($p->created_at && $listed->isSameDay(Carbon::parse($p->created_at)))) {
                return null;
            }
        }

        return $listed;
    }

    private function inferredStart(Property $p, array $transitions, array $logs): ?Carbon
    {
        $status = strtolower(trim((string) $p->status));

        // Other Agency Stock: the portal's own listing date, captured at import.
        if ($status === Property::STATUS_OTHER_AGENCY_STOCK) {
            return $p->listed_date ? Carbon::parse($p->listed_date)->startOfDay() : null;
        }

        // C - not currently on the market.
        if (!in_array($status, self::ON_MARKET_STATUSES, true) || $this->deactivatedOnPortals($p)) {
            return null;
        }

        $untouchedImport = $p->p24_imported_at !== null && $p->imported_released_at === null;

        // A - the boundary: last move INTO an on-market status, or the takeover.
        $boundary = null;
        foreach ($transitions as $t) {
            $to   = $t['to'];
            $from = $t['from'];
            if ($to !== null && in_array($to, self::ON_MARKET_STATUSES, true)
                && ($from === null || !in_array($from, self::ON_MARKET_STATUSES, true))) {
                $boundary = $t['at'];
            }
        }
        if ($p->imported_released_at) {
            $released = Carbon::parse($p->imported_released_at);
            if (!$boundary || $released->gt($boundary)) {
                $boundary = $released;
            }
        }
        // A deactivate/re-publish pushed through the P24 status endpoint is also a boundary.
        $lastStatusPush = $logs['status_updates'] ? max($logs['status_updates']) : null;
        $afterBoundary  = $boundary;
        if ($lastStatusPush && (!$boundary || $lastStatusPush->gt($boundary))) {
            $afterBoundary = $lastStatusPush;
        }

        if ($afterBoundary) {
            // The advert going live after the boundary: first successful P24 submit / PP activation.
            $live = [];
            foreach ($logs['submits'] as $s) {
                if ($s->gte($afterBoundary)) {
                    $live[] = $s;
                }
            }
            if ($p->pp_activated_at && Carbon::parse($p->pp_activated_at)->gte($afterBoundary)) {
                $live[] = Carbon::parse($p->pp_activated_at);
            }
            if ($live !== []) {
                return $this->earliest($live)->startOfDay();
            }
            // No submit followed. A boundary that is a real move on-market is the start; a bare
            // portal status push with no move on-market is no proof.
            return $boundary ? $boundary->copy()->startOfDay() : null;
        }

        // No boundary at all.
        if ($untouchedImport) {
            // B: every CoreX stamp on an untouched import is an import artefact (a real listing date is
            // honoured separately, in resolve()).
            return null;
        }

        // CoreX-created stock with no status history: earliest proof the advert went live, else the
        // listing date CoreX stamped at creation.
        // first_marketed_at is deliberately NOT a proof: loaded stock carries it equal to created_at.
        $proofs = [$p->published_at, $p->pp_activated_at, $p->p24_activated_at];
        foreach ($logs['submits'] as $s) {
            $proofs[] = $s;
        }
        $proofs = array_filter(array_map(fn ($d) => $d ? Carbon::parse($d) : null, $proofs));
        if ($proofs !== []) {
            return $this->earliest($proofs)->startOfDay();
        }

        return null;
    }

    /** @param Carbon[] $dates */
    private function earliest(array $dates): Carbon
    {
        $dates = array_values($dates);
        $e = $dates[0];
        foreach ($dates as $d) {
            if ($d->lt($e)) {
                $e = $d;
            }
        }

        return $e->copy();
    }

    private function deactivatedOnPortals(Property $p): bool
    {
        $p24 = strtolower((string) $p->p24_syndication_status);
        $pp  = strtolower((string) $p->pp_syndication_status);

        // Deactivated on the portal it was on, and not live on the other one.
        return ($p24 === 'deactivated' && $pp !== 'active') || ($pp === 'deactivated' && $p24 !== 'active');
    }

    /**
     * Status history per property, oldest first.
     *
     * @return array<int, array<int, array{at:Carbon, from:?string, to:?string}>>
     */
    private function statusTransitions(array $ids): array
    {
        $rows = DB::table('property_audit_log')
            ->whereIn('property_id', $ids)
            ->where('event_type', 'status_changed')
            ->orderBy('created_at')->orderBy('id')
            ->get(['property_id', 'old_values', 'new_values', 'created_at']);

        $out = [];
        foreach ($rows as $r) {
            $old = json_decode((string) $r->old_values, true);
            $new = json_decode((string) $r->new_values, true);
            $out[$r->property_id][] = [
                'at'   => Carbon::parse($r->created_at),
                'from' => isset($old['status']) ? strtolower(trim((string) $old['status'])) : null,
                'to'   => isset($new['status']) ? strtolower(trim((string) $new['status'])) : null,
            ];
        }

        return $out;
    }

    /** @return array<int, array{submits: Carbon[], status_updates: Carbon[]}> successful P24 pushes only */
    private function p24Logs(array $ids): array
    {
        $rows = DB::table('p24_syndication_logs')
            ->whereIn('property_id', $ids)
            ->whereIn('action', ['submit', 'status_update'])
            ->whereBetween('status_code', [200, 299])
            ->orderBy('created_at')
            ->get(['property_id', 'action', 'created_at']);

        $out = [];
        foreach ($rows as $r) {
            $key = $r->action === 'submit' ? 'submits' : 'status_updates';
            $out[$r->property_id][$key][] = Carbon::parse($r->created_at);
        }
        foreach ($out as $id => $v) {
            $out[$id] += ['submits' => [], 'status_updates' => []];
        }

        return $out;
    }
}
