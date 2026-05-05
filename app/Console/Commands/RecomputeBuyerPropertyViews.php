<?php

namespace App\Console\Commands;

use App\Models\BuyerPropertyView;
use App\Models\CommandCenter\CalendarEventFeedback;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecomputeBuyerPropertyViews extends Command
{
    protected $signature = 'buyer-views:recompute {--agency= : Limit to agency}';
    protected $description = 'Recompute buyer_property_views cache from calendar_event_feedback data';

    public function handle(): int
    {
        $query = CalendarEventFeedback::query()
            ->whereNotNull('contact_id')
            ->whereNotNull('property_id')
            ->whereNotNull('captured_at');

        if ($agencyId = $this->option('agency')) {
            $query->where('agency_id', (int) $agencyId);
        }

        $pairs = $query
            ->selectRaw('contact_id, property_id, MAX(captured_at) as last_at, COUNT(DISTINCT calendar_event_id) as view_cnt')
            ->groupBy('contact_id', 'property_id')
            ->get();

        $this->info("Found {$pairs->count()} (contact × property) pairs to cache.");

        foreach ($pairs as $pair) {
            $latestFeedback = CalendarEventFeedback::where('contact_id', $pair->contact_id)
                ->where('property_id', $pair->property_id)
                ->whereNotNull('captured_at')
                ->orderByDesc('captured_at')
                ->value('id');

            BuyerPropertyView::updateOrCreate(
                ['contact_id' => $pair->contact_id, 'property_id' => $pair->property_id],
                [
                    'last_viewed_at' => $pair->last_at,
                    'view_count' => $pair->view_cnt,
                    'most_recent_feedback_id' => $latestFeedback,
                ]
            );
        }

        $this->info('Done. ' . BuyerPropertyView::count() . ' rows in buyer_property_views.');
        return 0;
    }
}
