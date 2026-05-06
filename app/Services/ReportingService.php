<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportingService
{
    public function getAgentMetrics(int $userId, int $days = 30): array
    {
        $since = now()->subDays($days);
        $priorStart = now()->subDays($days * 2);
        $priorEnd = $since;

        // Activity metrics
        $eventsCompleted = DB::table('calendar_events')
            ->where('user_id', $userId)->where('status', 'completed')
            ->where('event_date', '>=', $since)->count();
        $eventsPrior = DB::table('calendar_events')
            ->where('user_id', $userId)->where('status', 'completed')
            ->whereBetween('event_date', [$priorStart, $priorEnd])->count();

        $viewings = DB::table('calendar_events')
            ->where('user_id', $userId)->where('category', 'viewing')
            ->where('status', 'completed')->where('event_date', '>=', $since)->count();

        $presentations = DB::table('calendar_events')
            ->where('user_id', $userId)->whereIn('category', ['listing_presentation', 'property_evaluation'])
            ->where('status', 'completed')->where('event_date', '>=', $since)->count();

        $feedbackCaptured = DB::table('calendar_event_feedback')
            ->where('captured_by_user_id', $userId)
            ->where('captured_at', '>=', $since)->count();
        $feedbackRate = $eventsCompleted > 0 ? min(100, round($feedbackCaptured / $eventsCompleted * 100)) : 0;

        // Pipeline metrics
        $activeBuyers = DB::table('contacts')
            ->where('created_by_user_id', $userId)->where('is_buyer', true)
            ->whereNull('deleted_at')->whereIn('buyer_state', ['new', 'warm', 'cold'])->count();

        $highRiskBuyers = DB::table('buyer_lost_risk_scores as brs')
            ->join('contacts', 'contacts.id', '=', 'brs.contact_id')
            ->where('contacts.created_by_user_id', $userId)
            ->where('brs.score', '>=', 60)->count();

        $lostDeals = DB::table('buyer_lost_records')
            ->where('agent_owner_user_id_at_loss', $userId)
            ->where('recorded_at', '>=', $since)->count();
        $lostValue = (float) DB::table('buyer_lost_records')
            ->where('agent_owner_user_id_at_loss', $userId)
            ->where('recorded_at', '>=', $since)
            ->sum('preapproval_amount_at_loss');

        return [
            'events_completed' => $eventsCompleted,
            'events_prior' => $eventsPrior,
            'viewings' => $viewings,
            'presentations' => $presentations,
            'feedback_rate' => $feedbackRate,
            'active_buyers' => $activeBuyers,
            'high_risk_buyers' => $highRiskBuyers,
            'lost_deals' => $lostDeals,
            'lost_value' => $lostValue,
        ];
    }

    public function getBranchMetrics(int $branchId, int $days = 30): array
    {
        $since = now()->subDays($days);
        $agentIds = DB::table('users')->where('branch_id', $branchId)->whereNull('deleted_at')->pluck('id');

        $activeAgents = $agentIds->count();
        $activeBuyers = DB::table('contacts')
            ->whereIn('created_by_user_id', $agentIds)->where('is_buyer', true)
            ->whereNull('deleted_at')->whereIn('buyer_state', ['new', 'warm', 'cold'])->count();

        $activeListings = DB::table('properties')
            ->where('branch_id', $branchId)->whereNotNull('published_at')
            ->whereNull('deleted_at')->count();

        $eventsCompleted = DB::table('calendar_events')
            ->whereIn('user_id', $agentIds)->where('status', 'completed')
            ->where('event_date', '>=', $since)->count();

        $lostValue = (float) DB::table('buyer_lost_records')
            ->where('branch_id_at_loss', $branchId)
            ->where('recorded_at', '>=', $since)
            ->sum('preapproval_amount_at_loss');

        $lostCount = DB::table('buyer_lost_records')
            ->where('branch_id_at_loss', $branchId)
            ->where('recorded_at', '>=', $since)->count();

        return [
            'active_agents' => $activeAgents,
            'active_buyers' => $activeBuyers,
            'active_listings' => $activeListings,
            'events_completed' => $eventsCompleted,
            'lost_count' => $lostCount,
            'lost_value' => $lostValue,
        ];
    }

    public function getLeaderboardForBranch(int $branchId, int $days = 30): Collection
    {
        $since = now()->subDays($days);
        $agents = DB::table('users')
            ->where('branch_id', $branchId)->where('role', 'agent')
            ->whereNull('deleted_at')->get(['id', 'name']);

        return $agents->map(function ($agent) use ($since) {
            $events = DB::table('calendar_events')
                ->where('user_id', $agent->id)->where('status', 'completed')
                ->where('event_date', '>=', $since)->count();
            $feedback = DB::table('calendar_event_feedback')
                ->where('captured_by_user_id', $agent->id)
                ->where('captured_at', '>=', $since)->count();
            $buyers = DB::table('contacts')
                ->where('created_by_user_id', $agent->id)->where('is_buyer', true)
                ->whereNull('deleted_at')->whereIn('buyer_state', ['new', 'warm', 'cold'])->count();
            $lost = DB::table('buyer_lost_records')
                ->where('agent_owner_user_id_at_loss', $agent->id)
                ->where('recorded_at', '>=', $since)->count();

            return (object) [
                'id' => $agent->id,
                'name' => $agent->name,
                'events_completed' => $events,
                'feedback_count' => $feedback,
                'feedback_rate' => $events > 0 ? round($feedback / $events * 100) : 0,
                'active_buyers' => $buyers,
                'lost_deals' => $lost,
            ];
        })->sortByDesc('events_completed')->values();
    }

    public function getAgentInsights(int $userId, int $days = 30): array
    {
        $metrics = $this->getAgentMetrics($userId, $days);
        $insights = [];

        // Feedback rate insight
        if ($metrics['feedback_rate'] < 70 && $metrics['events_completed'] > 3) {
            $insights[] = "Feedback capture rate {$metrics['feedback_rate']}%. Agency target: 80%+. Consider capturing feedback immediately after each viewing.";
        }

        // Activity trend
        if ($metrics['events_prior'] > 0) {
            $change = round(($metrics['events_completed'] - $metrics['events_prior']) / $metrics['events_prior'] * 100);
            if ($change > 20) $insights[] = "Activity up {$change}% vs prior period. Strong momentum.";
            elseif ($change < -20) $insights[] = "Activity down " . abs($change) . "% vs prior period. Review pipeline for stagnation.";
        }

        // High-risk buyers
        if ($metrics['high_risk_buyers'] > 3) {
            $insights[] = "{$metrics['high_risk_buyers']} buyers at high lost-risk. Prioritise re-engagement calls.";
        }

        return $insights;
    }

    public function getBranchInsights(int $branchId, int $days = 30): array
    {
        $metrics = $this->getBranchMetrics($branchId, $days);
        $insights = [];

        if ($metrics['lost_value'] > 0) {
            $insights[] = "Branch lost R " . number_format($metrics['lost_value']) . " in buyer preapproval value this period.";
        }

        $leaderboard = $this->getLeaderboardForBranch($branchId, $days);
        $lowFeedback = $leaderboard->filter(fn($a) => $a->feedback_rate < 60 && $a->events_completed > 2);
        if ($lowFeedback->isNotEmpty()) {
            $names = $lowFeedback->pluck('name')->implode(', ');
            $insights[] = "Agents with low feedback rate (<60%): {$names}. Coaching opportunity.";
        }

        return $insights;
    }

    // ── Module 8 Part 2: Agency-level methods ──

    public function getAgencyMetrics(int $agencyId, int $days = 30): array
    {
        $since = now()->subDays($days);

        $totalAgents = DB::table('users')->where('agency_id', $agencyId)->where('role', 'agent')->whereNull('deleted_at')->count();
        $totalBuyers = DB::table('contacts')->where('agency_id', $agencyId)->where('is_buyer', true)->whereNull('deleted_at')->whereIn('buyer_state', ['new', 'warm', 'cold'])->count();
        $totalListings = DB::table('properties')->where('agency_id', $agencyId)->whereNotNull('published_at')->whereNull('deleted_at')->count();
        $eventsCompleted = DB::table('calendar_events')->where('agency_id', $agencyId)->where('status', 'completed')->where('event_date', '>=', $since)->count();
        $lostValue = (float) DB::table('buyer_lost_records')->where('agency_id', $agencyId)->where('recorded_at', '>=', $since)->sum('preapproval_amount_at_loss');
        $lostCount = DB::table('buyer_lost_records')->where('agency_id', $agencyId)->where('recorded_at', '>=', $since)->count();
        $avgDom = DB::table('properties')->where('agency_id', $agencyId)->whereNotNull('published_at')->whereNull('deleted_at')->get()->avg(fn($p) => \Carbon\Carbon::parse($p->published_at)->diffInDays(now()));

        return [
            'total_agents' => $totalAgents,
            'total_buyers' => $totalBuyers,
            'total_listings' => $totalListings,
            'events_completed' => $eventsCompleted,
            'lost_value' => $lostValue,
            'lost_count' => $lostCount,
            'avg_dom' => $avgDom ? (int) round($avgDom) : null,
        ];
    }

    public function getBranchComparison(int $agencyId, int $days = 30): Collection
    {
        $branches = DB::table('branches')->where('agency_id', $agencyId)->whereNull('deleted_at')->get(['id', 'name']);
        return $branches->map(function ($branch) use ($days) {
            $metrics = $this->getBranchMetrics($branch->id, $days);
            return (object) array_merge(['id' => $branch->id, 'name' => $branch->name], $metrics);
        });
    }

    /**
     * Conversion funnel: Lead → First Viewing → Deal Closed (3 stages).
     * Offer Made stage skipped — no distinct offer status in system yet.
     */
    public function getConversionFunnel(array $filter, int $days = 30): array
    {
        $since = now()->subDays($days);

        // Stage 1: Leads (buyers entered pipeline in period)
        $leadsQuery = DB::table('contacts')->where('is_buyer', true)->whereNull('deleted_at')->where('buyer_pipeline_entered_at', '>=', $since);
        if (isset($filter['user_id'])) $leadsQuery->where('created_by_user_id', $filter['user_id']);
        if (isset($filter['branch_id'])) $leadsQuery->where('branch_id', $filter['branch_id']);
        $leads = $leadsQuery->count();

        // Stage 2: First Viewing (buyers with at least 1 feedback row in period)
        $viewedQuery = DB::table('calendar_event_feedback')->whereNotNull('captured_at')->where('captured_at', '>=', $since);
        if (isset($filter['user_id'])) $viewedQuery->where('captured_by_user_id', $filter['user_id']);
        if (isset($filter['branch_id'])) $viewedQuery->where('branch_id', $filter['branch_id']);
        $viewed = $viewedQuery->distinct('contact_id')->count('contact_id');

        // Stage 3: Deal Closed (sold records in period)
        $closedQuery = DB::table('property_sold_records')->where('sold_date', '>=', $since);
        if (isset($filter['user_id'])) $closedQuery->where('captured_by_user_id', $filter['user_id']);
        if (isset($filter['agency_id'])) $closedQuery->where('agency_id', $filter['agency_id']);
        $closed = $closedQuery->count();

        return [
            ['stage' => 'Leads', 'count' => $leads, 'rate' => null],
            ['stage' => 'First Viewing', 'count' => $viewed, 'rate' => $leads > 0 ? round($viewed / $leads * 100) : 0],
            ['stage' => 'Deal Closed', 'count' => $closed, 'rate' => $viewed > 0 ? round($closed / $viewed * 100) : 0],
        ];
    }

    public function getAgencyInsights(int $agencyId, int $days = 30): array
    {
        $metrics = $this->getAgencyMetrics($agencyId, $days);
        $insights = [];

        if ($metrics['lost_value'] > 0) {
            $insights[] = "Agency lost R " . number_format($metrics['lost_value']) . " in buyer preapproval value this period ({$metrics['lost_count']} buyers).";
        }
        if ($metrics['avg_dom'] && $metrics['avg_dom'] > 45) {
            $insights[] = "Average days on market: {$metrics['avg_dom']} days. Industry benchmark: 30 days. Review pricing strategy across slow-moving listings.";
        }
        if ($metrics['total_agents'] > 0 && $metrics['events_completed'] > 0) {
            $eventsPerAgent = round($metrics['events_completed'] / $metrics['total_agents'], 1);
            $insights[] = "Activity rate: {$eventsPerAgent} completed events per agent this period.";
        }

        return $insights;
    }
}
