<?php

namespace App\Services\RoadToSold\Selections;

use App\Models\Property;
use App\Services\Properties\DaysOnMarket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Selection 9 — my stock that is not selling. The agent's OWN on-market listings where days on the
 * market are high but views and enquiries are low — time to talk price or marketing with the owner.
 * Listing agent only. Thresholds are agency settings (days, views, enquiries; defaults 60 / 30 / 2).
 *
 *   days on market  the one canonical calculation (Properties\DaysOnMarket)
 *   views           portal views over the look-back (property_portal_metrics.view_count)
 *   enquiries       the larger of the portal's own lead count and the enquiries CoreX received
 *                   (property_portal_metrics.total_leads / portal_leads) over the look-back
 *
 * A property with NO portal statistics in the window is skipped: "low views" cannot be claimed
 * without numbers (it may not be on a portal at all). Longest on the market first.
 */
final class MyStockNotSellingSelection extends OwnStockSelection
{
    private const ON_MARKET = ['active', 'for_sale', 'on_show'];

    public function key(): string
    {
        return 'my_stock_not_selling';
    }

    public function label(): string
    {
        return 'My stock that is not selling';
    }

    public function candidates(SelectionContext $ctx, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $props = Property::withoutGlobalScopes()
            ->whereIn('id', $this->ownProperties($ctx)->whereIn('p.status', self::ON_MARKET)->pluck('p.id'))
            ->get();
        if ($props->isEmpty()) {
            return [];
        }

        $ids = $props->pluck('id')->map(fn ($v) => (int) $v)->all();
        $dom = app(DaysOnMarket::class)->forMany($props);
        $since = Carbon::instance($ctx->date)->startOfDay()->subDays(max(1, $ctx->settings->stuckStockLookbackDays));

        $metrics = DB::table('property_portal_metrics')
            ->whereIn('property_id', $ids)->whereNull('deleted_at')
            ->where('metric_date', '>=', $since->toDateString())
            ->groupBy('property_id')
            ->select('property_id', DB::raw('SUM(view_count) as views'), DB::raw('SUM(total_leads) as leads'), DB::raw('COUNT(*) as days'))
            ->get()->keyBy('property_id');

        $received = DB::table('portal_leads')
            ->whereIn('listing_id', $ids)->whereNull('deleted_at')
            ->where('received_at', '>=', $since)
            ->where(fn ($w) => $w->whereNull('lead_type')->orWhere('lead_type', '<>', 'Test'))
            ->groupBy('listing_id')
            ->select('listing_id', DB::raw('COUNT(*) as n'))
            ->pluck('n', 'listing_id');

        $facts = [];
        foreach ($props as $p) {
            $m = $metrics[$p->id] ?? null;
            $days = $dom[$p->id] ?? null;
            if ($m === null || $days === null || $days < $ctx->settings->stuckStockMinDaysOnMarket) {
                continue;
            }
            $views = (int) $m->views;
            $leads = max((int) $m->leads, (int) ($received[$p->id] ?? 0));
            if ($views > $ctx->settings->stuckStockMaxViews || $leads > $ctx->settings->stuckStockMaxLeads) {
                continue;
            }
            $facts[(int) $p->id] = ['days' => $days, 'views' => $views, 'leads' => $leads, 'address' => $p->address];
        }
        if ($facts === []) {
            return [];
        }

        // addressed first, then longest on the market, then id (stable)
        $rows = [];
        foreach ($facts as $id => $f) {
            $rows[] = (object) (['id' => $id] + $f);
        }
        usort($rows, fn ($a, $b) => [trim((string) $a->address) === '' ? 1 : 0, -$a->days, $a->id]
            <=> [trim((string) $b->address) === '' ? 1 : 0, -$b->days, $b->id]);

        $window = (int) $ctx->settings->stuckStockLookbackDays;

        return $this->toCandidates($rows, $ctx, $limit, function ($r) use ($window) {
            return "{$r->days} days on the market, only {$r->views} views and {$r->leads} "
                . ($r->leads === 1 ? 'enquiry' : 'enquiries') . " in the last {$window} days";
        }, $this->key());
    }
}
