<?php

namespace App\Services;

use App\Models\BuyerActivityLog;
use App\Models\BuyerPropertyView;
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
        return BuyerPropertyView::where('contact_id', $contactId)
            ->with('property')
            ->orderByDesc('last_viewed_at')
            ->get()
            ->map(fn($v) => [
                'property_id' => $v->property_id,
                'address' => $v->property?->title ?? "Property #{$v->property_id}",
                'suburb' => $v->property?->suburb,
                'price' => $v->property?->price,
                'view_count' => $v->view_count,
                'last_viewed_at' => $v->last_viewed_at,
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

        $prefs = DB::table('buyer_preferences')->where('contact_id', $contactId)->first();
        $viewed = BuyerPropertyView::where('contact_id', $contactId)->pluck('property_id')->toArray();

        $query = Property::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->whereNotNull('published_at')
            ->whereNotIn('id', $viewed);

        if ($prefs) {
            if ($prefs->budget_min) $query->where('price', '>=', $prefs->budget_min * 0.85);
            if ($prefs->budget_max) $query->where('price', '<=', $prefs->budget_max * 1.15);
            $areas = json_decode($prefs->preferred_areas ?? '[]', true);
            if (!empty($areas)) $query->whereIn('suburb', $areas);
        }

        return $query->limit($limit)->get(['id', 'title', 'price', 'suburb', 'published_at'])
            ->map(fn($p) => [
                'id' => $p->id,
                'address' => $p->title,
                'price' => $p->price,
                'suburb' => $p->suburb,
                'match_score' => $this->computeMatchScore($p, $prefs),
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

    private function computeMatchScore($property, $prefs): int
    {
        if (!$prefs) return 75; // Default if no preferences set
        $score = 100;
        if ($prefs->budget_max && $property->price > $prefs->budget_max) $score -= 20;
        if ($prefs->budget_min && $property->price < $prefs->budget_min) $score -= 10;
        $areas = json_decode($prefs->preferred_areas ?? '[]', true);
        if (!empty($areas) && !in_array($property->suburb, $areas)) $score -= 15;
        return max(50, $score);
    }
}
