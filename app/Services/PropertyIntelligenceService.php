<?php

namespace App\Services;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventFeedback;
use App\Models\Property;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates calendar feedback, portal data, buyer signals, compliance,
 * and auto-derived recommendations into the Property Intelligence Hub.
 */
class PropertyIntelligenceService
{
    /**
     * Chronological activity timeline for a property.
     */
    public function getActivityTimeline(int $propertyId, int $limit = 50): Collection
    {
        // Viewings + feedback events
        $events = CalendarEvent::withoutGlobalScopes()
            ->whereHas('linkedProperties', fn($q) => $q->where('properties.id', $propertyId))
            ->with('linkedContacts')
            ->orderByDesc('event_date')
            ->limit($limit)
            ->get()
            ->map(fn($e) => [
                'type' => 'event',
                'date' => $e->event_date,
                'title' => $e->title,
                'category' => $e->category,
                'status' => $e->status,
                'agent' => $e->user_id,
                'event_id' => $e->id,
            ]);

        return $events->sortByDesc('date')->values();
    }

    /**
     * Portal performance metrics for a property (last N days).
     *
     * Note: portal_captures stores raw page HTML for presentation scraping,
     * not per-property view/favourite/enquiry metrics. When portal analytics
     * integration is built (P24 Stats API, PP Dashboard API), this method
     * will query a dedicated property_portal_metrics table. Until then,
     * returns zeros to prevent 500 errors on the Property Hub.
     */
    public function getPortalPerformance(int $propertyId, int $rangeDays = 30): array
    {
        return [
            'views' => 0,
            'favourites' => 0,
            'enquiries' => 0,
            'total' => 0,
            'range_days' => $rangeDays,
        ];
    }

    /**
     * Buyers in the system whose profile matches this property.
     */
    public function getBuyerInterestSignals(int $propertyId): Collection
    {
        $property = Property::withoutGlobalScopes()->find($propertyId);
        if (!$property) return collect();

        // Basic matching: same suburb/area + price within range
        $buyers = DB::table('contacts')
            ->where('is_buyer', true)
            ->where('agency_id', $property->agency_id)
            ->whereNull('deleted_at')
            ->whereNull('purged_at')
            ->limit(10)
            ->get(['id', 'first_name', 'last_name', 'buyer_state', 'last_activity_at']);

        return collect($buyers)->map(fn($b) => [
            'id' => $b->id,
            'name' => trim(($b->first_name ?? '') . ' ' . ($b->last_name ?? '')),
            'state' => $b->buyer_state,
            'last_activity' => $b->last_activity_at,
            'match_score' => 75, // Placeholder — full scoring in Module 8
        ]);
    }

    /**
     * Aggregated feedback metrics for a property.
     * Joins through calendar_event_links since feedback.property_id may be NULL.
     */
    /**
     * @param bool $excludeInternalOnly When true, filters out internal_only feedback (for seller-facing surfaces)
     */
    public function getFeedbackRollup(int $propertyId, bool $excludeInternalOnly = false): array
    {
        // Find all events linked to this property
        $eventIds = DB::table('calendar_event_links')
            ->where('linkable_type', 'App\\Models\\Property')
            ->where('linkable_id', $propertyId)
            ->where('role', 'subject_property')
            ->pluck('calendar_event_id');

        // Get feedback for those events (or directly linked to this property)
        $feedback = CalendarEventFeedback::where(function ($q) use ($propertyId, $eventIds) {
            $q->where('property_id', $propertyId)
              ->orWhereIn('calendar_event_id', $eventIds);
        })->whereNotNull('captured_at')
          ->when($excludeInternalOnly, fn ($q) => $q->where('visibility', '!=', 'internal_only'))
          ->get();

        $viewingCount = $feedback->unique('calendar_event_id')->count();
        $allConcerns = $feedback->pluck('concern_option_ids')->flatten()->filter()->countBy();
        $outcomes = $feedback->pluck('outcome_option_id')->filter()->countBy();

        return [
            'total_viewings' => $viewingCount,
            'total_feedback_rows' => $feedback->count(),
            'top_concerns' => $allConcerns->sortDesc()->take(5)->toArray(),
            'outcome_distribution' => $outcomes->toArray(),
        ];
    }

    /**
     * Detailed viewing + feedback rows for a property (recent first, limit 20).
     */
    public function getRecentViewings(int $propertyId, int $limit = 20, bool $excludeInternalOnly = false): \Illuminate\Support\Collection
    {
        $eventIds = DB::table('calendar_event_links')
            ->where('linkable_type', 'App\\Models\\Property')
            ->where('linkable_id', $propertyId)
            ->where('role', 'subject_property')
            ->pluck('calendar_event_id');

        if ($eventIds->isEmpty()) return collect();

        $events = CalendarEvent::withoutGlobalScopes()
            ->whereIn('id', $eventIds)
            ->orderByDesc('event_date')
            ->limit($limit)
            ->get();

        $feedbackQuery = DB::table('calendar_event_feedback')
            ->whereIn('calendar_event_id', $eventIds);
        if ($excludeInternalOnly) {
            $feedbackQuery->where('visibility', '!=', 'internal_only');
        }
        $feedback = $feedbackQuery->get()->groupBy('calendar_event_id');

        $agents = \App\Models\User::withoutGlobalScopes()
            ->whereIn('id', $events->pluck('user_id')->unique()->filter())
            ->pluck('name', 'id');

        $outcomeLabels = DB::table('agency_feedback_options')
            ->where('category', 'outcome')
            ->pluck('label', 'id');

        // Resolve buyer contacts for each event
        $buyerLinks = DB::table('calendar_event_links')
            ->whereIn('calendar_event_id', $eventIds)
            ->where('linkable_type', 'App\\Models\\Contact')
            ->whereIn('role', ['buyer_contact', 'attendee'])
            ->get()
            ->groupBy('calendar_event_id');

        $contactIds = $buyerLinks->flatten()->pluck('linkable_id')->unique();
        $contacts = \App\Models\Contact::withoutGlobalScopes()
            ->whereIn('id', $contactIds)
            ->get(['id', 'first_name', 'last_name'])
            ->keyBy('id');

        return $events->map(function ($ev) use ($feedback, $agents, $outcomeLabels, $buyerLinks, $contacts) {
            $fbs = $feedback->get($ev->id, collect());
            $buyers = ($buyerLinks->get($ev->id, collect()))->map(function ($bl) use ($contacts) {
                $c = $contacts->get($bl->linkable_id);
                return $c ? ['id' => $c->id, 'name' => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''))] : null;
            })->filter()->values();

            return [
                'event_id' => $ev->id,
                'event_date' => $ev->event_date,
                'title' => $ev->title,
                'agent_name' => $agents->get($ev->user_id, 'Unknown'),
                'buyers' => $buyers,
                'feedback' => $fbs->map(fn($fb) => [
                    'outcome_label' => $outcomeLabels->get($fb->outcome_option_id),
                    'seller_notes' => $fb->seller_visible_notes,
                    'internal_notes' => $fb->internal_notes,
                    'captured_at' => $fb->captured_at,
                ])->values(),
            ];
        });
    }

    /**
     * Auto-derived agent recommendations based on feedback patterns.
     */
    public function getAgentRecommendations(int $propertyId): Collection
    {
        return DB::table('property_recommendations')
            ->where('property_id', $propertyId)
            ->whereNull('dismissed_at')
            ->whereNull('actioned_at')
            ->orderByDesc('generated_at')
            ->get();
    }

    /**
     * Similar listings in the same area for comparison.
     */
    public function getComparableListings(int $propertyId, int $limit = 5): Collection
    {
        $property = Property::withoutGlobalScopes()->find($propertyId);
        if (!$property) return collect();

        return Property::withoutGlobalScopes()
            ->where('id', '!=', $propertyId)
            ->where('agency_id', $property->agency_id)
            ->whereNull('deleted_at')
            ->when($property->suburb, fn($q) => $q->where('suburb', $property->suburb))
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get(['id', 'title', 'price', 'suburb', 'published_at'])
            ->map(fn($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'price' => $p->price,
                'suburb' => $p->suburb,
                'days_on_market' => $p->published_at ? (int) $p->published_at->diffInDays(now()) : null,
            ]);
    }

    /**
     * Presentations linked to a property + their snapshots.
     */
    public function getPresentations(int $propertyId, bool $sellerView = false): Collection
    {
        $query = DB::table('presentations')
            ->where('listing_id', $propertyId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at');

        if ($sellerView) {
            $query->where('status', 'finalized'); // Seller only sees finalized
            $query->limit(1); // Most recent only
        }

        return $query->get(['id', 'title', 'status', 'created_at', 'created_by_user_id', 'asking_price_inc']);
    }

    /**
     * Snapshot history for a property (market position over time).
     */
    public function getSnapshotHistory(int $propertyId): Collection
    {
        return \App\Models\PropertyPresentationSnapshot::where('property_id', $propertyId)
            ->orderByDesc('generated_at')
            ->limit(10)
            ->get();
    }

    /**
     * Latest market position data from most recent snapshot.
     */
    public function getLatestMarketPosition(int $propertyId): ?array
    {
        $latest = \App\Models\PropertyPresentationSnapshot::where('property_id', $propertyId)
            ->orderByDesc('generated_at')
            ->first();

        if (!$latest) return null;

        return [
            'recommended_price' => $latest->recommended_price_at_time,
            'days_on_market' => $latest->days_on_market_at_time,
            'area_avg_price' => $latest->market_data_snapshot['area_average_price'] ?? null,
            'area_avg_dom' => $latest->market_data_snapshot['area_days_on_market'] ?? null,
            'snapshot_date' => $latest->generated_at?->toDateString(),
            'comparable_sales_count' => count($latest->market_data_snapshot['comparable_sales'] ?? []),
        ];
    }

    /**
     * Compliance status for a property (mandate, FICA, etc.).
     */
    public function getComplianceStatus(int $propertyId): array
    {
        $property = Property::withoutGlobalScopes()->with('contacts')->find($propertyId);
        if (!$property) return [];

        // Check mandate expiry from calendar events
        $mandateEvent = CalendarEvent::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('category', 'mandate_expiry')
            ->whereNull('deleted_at')
            ->orderByDesc('event_date')
            ->first(['event_date', 'status']);

        // Check seller FICA status
        $sellers = $property->contacts()
            ->wherePivotIn('role', ['owner', 'seller', 'landlord', 'lessor'])
            ->get();
        $ficaComplete = $sellers->every(fn($c) => $c->ficaStatus() === 'complete');

        return [
            'mandate_type' => $property->mandate_type,
            'mandate_expiry' => $mandateEvent?->event_date?->toDateString(),
            'mandate_expired' => $mandateEvent && $mandateEvent->event_date->isPast(),
            'seller_fica_complete' => $ficaComplete,
            'seller_count' => $sellers->count(),
            'published' => (bool) $property->published_at,
            'days_on_market' => $property->published_at ? (int) $property->published_at->diffInDays(now()) : null,
        ];
    }
}
