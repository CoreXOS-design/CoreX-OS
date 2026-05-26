<?php

namespace App\Services;

use App\Models\BuyerActivityLog;
use App\Models\BuyerPropertyView;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\Contact;
use App\Models\Property;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BuyerIntelligenceService
{
    public function getActivityTimeline(int $contactId, int $limit = 50): Collection
    {
        return BuyerActivityLog::where('contact_id', $contactId)
            ->orderByDesc('activity_date')
            ->limit($limit)
            ->get()
            ->map(fn($a) => [
                'id' => $a->id,
                'type' => $a->activity_type,
                'date' => $a->activity_date,
                'property_id' => $a->related_property_id,
                'event_id' => $a->related_event_id,
                'logged_by' => $a->logged_by_user_id,
                'metadata' => $a->metadata,
            ]);
    }

    public function getPropertiesViewed(int $contactId): Collection
    {
        // Primary source: calendar event links — find all viewing events where
        // this contact attended as buyer/attendee, then resolve the properties shown.
        $eventIds = DB::table('calendar_event_links')
            ->where('linkable_type', 'App\\Models\\Contact')
            ->where('linkable_id', $contactId)
            ->whereIn('role', ['buyer_contact', 'attendee'])
            ->pluck('calendar_event_id');

        if ($eventIds->isEmpty()) {
            return collect();
        }

        // Get properties linked to those events
        $propLinks = DB::table('calendar_event_links')
            ->whereIn('calendar_event_id', $eventIds)
            ->where('role', 'subject_property')
            ->where('linkable_type', 'App\\Models\\Property')
            ->get(['calendar_event_id', 'linkable_id']);

        if ($propLinks->isEmpty()) {
            return collect();
        }

        // Load events and properties
        $events = CalendarEvent::withoutGlobalScopes()
            ->whereIn('id', $eventIds)
            ->get()
            ->keyBy('id');

        $propertyIds = $propLinks->pluck('linkable_id')->unique();
        $properties = Property::withoutGlobalScopes()
            ->whereIn('id', $propertyIds)
            ->get()
            ->keyBy('id');

        // Load feedback for these (event, contact) tuples
        $feedback = DB::table('calendar_event_feedback')
            ->where('contact_id', $contactId)
            ->whereIn('calendar_event_id', $eventIds)
            ->get()
            ->groupBy('calendar_event_id');

        // Get agent names
        $agentIds = $events->pluck('user_id')->unique()->filter();
        $agents = \App\Models\User::withoutGlobalScopes()
            ->whereIn('id', $agentIds)
            ->pluck('name', 'id');

        // Outcome labels
        $outcomeLabels = DB::table('agency_feedback_options')
            ->where('category', 'outcome')
            ->pluck('label', 'id');

        // Build one row per (property × viewing event)
        $rows = collect();
        foreach ($propLinks as $pl) {
            $event = $events->get($pl->calendar_event_id);
            $prop = $properties->get($pl->linkable_id);
            if (!$event || !$prop) continue;

            // Find feedback for this specific event — match by property_id if set, else take first
            $eventFeedback = $feedback->get($pl->calendar_event_id, collect());
            $fb = $eventFeedback->firstWhere('property_id', $pl->linkable_id)
                ?? $eventFeedback->first();

            $rows->push([
                'property_id' => $prop->id,
                'address' => method_exists($prop, 'buildDisplayAddress') ? $prop->buildDisplayAddress() : ($prop->title ?? "Property #{$prop->id}"),
                'suburb' => $prop->suburb,
                'price' => $prop->price,
                'event_id' => $event->id,
                'event_date' => $event->event_date,
                'event_title' => $event->title,
                'agent_name' => $agents->get($event->user_id, 'Unknown'),
                'view_count' => 1,
                'last_viewed_at' => $event->event_date,
                'feedback' => $fb ? [
                    'outcome_label' => $outcomeLabels->get($fb->outcome_option_id, null),
                    'seller_notes' => $fb->seller_visible_notes,
                    'internal_notes' => $fb->internal_notes,
                    'next_action' => $fb->next_action_notes,
                    'captured_at' => $fb->captured_at,
                ] : null,
            ]);
        }

        $now = now();
        return collect([
            'upcoming' => $rows->filter(fn ($r) => \Carbon\Carbon::parse($r['event_date'])->gte($now))
                ->sortBy('event_date')->values(),
            'past' => $rows->filter(fn ($r) => \Carbon\Carbon::parse($r['event_date'])->lt($now))
                ->sortByDesc('event_date')->values(),
        ]);
    }

    public function getPreferencePatterns(int $contactId): array
    {
        $views = BuyerPropertyView::where('contact_id', $contactId)
            ->with('property')
            ->get();

        $prices = $views->map(fn($v) => $v->property?->price)->filter();
        $suburbs = $views->map(fn($v) => $v->property?->suburb)->filter()->countBy();

        // Feedback patterns
        $feedback = DB::table('calendar_event_feedback')
            ->where('contact_id', $contactId)
            ->whereNotNull('captured_at')
            ->get(['concern_option_ids', 'outcome_option_id']);

        $concerns = $feedback->pluck('concern_option_ids')
            ->map(fn($v) => is_string($v) ? json_decode($v, true) : $v)
            ->flatten()->filter()->countBy();

        return [
            'avg_price' => $prices->isNotEmpty() ? (int) $prices->avg() : null,
            'price_range' => $prices->isNotEmpty() ? ['min' => $prices->min(), 'max' => $prices->max()] : null,
            'top_areas' => $suburbs->sortDesc()->take(5)->toArray(),
            'properties_viewed_count' => $views->count(),
            'top_concerns' => $concerns->sortDesc()->take(5)->toArray(),
            'viewing_intensity' => $this->computeViewingIntensity($contactId),
        ];
    }

    public function getMatchedProperties(int $contactId, int $limit = 10): Collection
    {
        $contact = Contact::withoutGlobalScopes()->find($contactId);
        if (!$contact) return collect();

        // Read criteria from the contact's primary ContactMatch (spec D1).
        $match = \App\Models\ContactMatch::withoutGlobalScopes()
            ->where('contact_id', $contactId)
            ->whereNull('deleted_at')
            ->where('status', \App\Models\ContactMatch::STATUS_ACTIVE)
            ->primary()
            ->first();

        $viewed = BuyerPropertyView::where('contact_id', $contactId)->pluck('property_id')->toArray();

        $query = Property::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->whereNotNull('published_at')
            ->whereNotIn('id', $viewed);

        if ($match) {
            if ($match->price_min) $query->where('price', '>=', $match->price_min * 0.85);
            if ($match->price_max) $query->where('price', '<=', $match->price_max * 1.15);
            $areas = $match->suburbs ?? [];
            if (!empty($areas)) $query->whereIn('suburb', $areas);
        }

        return $query->limit($limit)->get(['id', 'title', 'price', 'suburb', 'published_at'])
            ->map(fn($p) => [
                'id' => $p->id,
                'address' => $p->title,
                'price' => $p->price,
                'suburb' => $p->suburb,
                'match_score' => $this->computeMatchScore($p, $match),
                'days_on_market' => $p->published_at ? (int) $p->published_at->diffInDays(now()) : null,
            ]);
    }

    public function getLostRiskScore(int $contactId): array
    {
        $contact = Contact::withoutGlobalScopes()->find($contactId);
        if (!$contact || !$contact->is_buyer) return ['score' => 0, 'factors' => []];

        $factors = [];
        $score = 0;

        // Factor 1: Days since last activity (max 30 pts)
        $daysSinceActivity = $contact->last_activity_at
            ? (int) $contact->last_activity_at->diffInDays(now())
            : 999;
        $activityPts = min(30, (int) ($daysSinceActivity / 2));
        $factors['days_inactive'] = ['points' => $activityPts, 'value' => $daysSinceActivity, 'max' => 30];
        $score += $activityPts;

        // Factor 2: Viewing frequency drop (max 20 pts)
        $recentViews = BuyerActivityLog::where('contact_id', $contactId)
            ->where('activity_type', 'viewing_completed')
            ->where('activity_date', '>=', now()->subWeeks(4))
            ->count();
        $priorViews = BuyerActivityLog::where('contact_id', $contactId)
            ->where('activity_type', 'viewing_completed')
            ->whereBetween('activity_date', [now()->subWeeks(8), now()->subWeeks(4)])
            ->count();
        $freqDrop = $priorViews > 0 && $recentViews < $priorViews ? min(20, (int) (($priorViews - $recentViews) / $priorViews * 20)) : 0;
        $factors['frequency_drop'] = ['points' => $freqDrop, 'recent' => $recentViews, 'prior' => $priorViews, 'max' => 20];
        $score += $freqDrop;

        // Factor 3: State stagnant warm > 30 days (max 15 pts)
        if ($contact->buyer_state === 'warm' && $contact->last_activity_at && $contact->last_activity_at->diffInDays(now()) > 20) {
            $stagnant = min(15, (int) (($contact->last_activity_at->diffInDays(now()) - 20) / 2));
            $factors['state_stagnant'] = ['points' => $stagnant, 'max' => 15];
            $score += $stagnant;
        }

        // Factor 4: No matched suggestions (max 10 pts)
        $matched = $this->getMatchedProperties($contactId, 1);
        if ($matched->isEmpty()) {
            $factors['no_matches'] = ['points' => 10, 'max' => 10];
            $score += 10;
        }

        return ['score' => min(100, $score), 'factors' => $factors];
    }

    public function getRetentionPlaybook(int $contactId): array
    {
        $risk = $this->getLostRiskScore($contactId);
        $contact = Contact::withoutGlobalScopes()->find($contactId);
        $actions = [];

        if (($risk['factors']['days_inactive']['value'] ?? 0) > 7) {
            $actions[] = [
                'code' => 're_engage_call',
                'title' => 'Schedule re-engagement call',
                'reasoning' => 'No activity for ' . ($risk['factors']['days_inactive']['value'] ?? '?') . ' days.',
            ];
        }

        $matched = $this->getMatchedProperties($contactId, 3);
        if ($matched->isNotEmpty()) {
            $actions[] = [
                'code' => 'send_matches',
                'title' => 'Send ' . $matched->count() . ' new property matches',
                'reasoning' => 'Properties matching buyer\'s profile haven\'t been shared yet.',
            ];
        }

        if ($risk['score'] > 60) {
            $actions[] = [
                'code' => 'manager_review',
                'title' => 'Escalate to branch manager for review',
                'reasoning' => 'Lost-risk score ' . $risk['score'] . '/100 — high risk of losing this buyer.',
            ];
        }

        return $actions;
    }

    private function computeViewingIntensity(int $contactId): ?float
    {
        $firstActivity = BuyerActivityLog::where('contact_id', $contactId)->min('activity_date');
        if (!$firstActivity) return null;
        $weeks = max(1, \Carbon\Carbon::parse($firstActivity)->diffInWeeks(now()));
        $viewings = BuyerActivityLog::where('contact_id', $contactId)
            ->where('activity_type', 'viewing_completed')->count();
        return round($viewings / $weeks, 1);
    }

    /**
     * Lightweight match score for the buyer-intelligence "matched properties"
     * tab. Reads criteria from the contact's primary ContactMatch.
     * Authoritative scoring lives in PropertyMatchScoringService — this is
     * a tab-display heuristic.
     */
    private function computeMatchScore($property, ?\App\Models\ContactMatch $match): int
    {
        if (!$match) return 75;
        $score = 100;
        if ($match->price_max && $property->price > $match->price_max) $score -= 20;
        if ($match->price_min && $property->price < $match->price_min) $score -= 10;
        $areas = $match->suburbs ?? [];
        if (!empty($areas) && !in_array($property->suburb, $areas, true)) $score -= 15;
        return max(50, $score);
    }
}
