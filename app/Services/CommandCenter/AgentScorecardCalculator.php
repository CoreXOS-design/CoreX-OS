<?php

namespace App\Services\CommandCenter;

use App\Models\CommandCenter\AgentScorecard;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CommandTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AgentScorecardCalculator
{
    /**
     * Calculate weekly scorecard for a user.
     */
    public function calculateWeekly(User $user): AgentScorecard
    {
        $start = now()->startOfWeek();
        $end   = now()->endOfWeek();

        return $this->calculate($user, 'weekly', $start, $end);
    }

    /**
     * Calculate monthly scorecard for a user.
     */
    public function calculateMonthly(User $user): AgentScorecard
    {
        $start = now()->startOfMonth();
        $end   = now()->endOfMonth();

        return $this->calculate($user, 'monthly', $start, $end);
    }

    protected function calculate(User $user, string $periodType, $start, $end): AgentScorecard
    {
        // Tasks
        $tasksTotal     = CommandTask::forUser($user->id)->whereBetween('created_at', [$start, $end])->count();
        $tasksCompleted = CommandTask::forUser($user->id)->where('status', 'done')->whereBetween('completed_at', [$start, $end])->count();
        $tasksOverdue   = CommandTask::forUser($user->id)->overdue()->count();

        // Properties attended (had activity in period)
        $propertiesTotal    = DB::table('properties')->where('agent_id', $user->id)->whereNull('deleted_at')->count();
        $propertiesAttended = DB::table('properties')
            ->where('agent_id', $user->id)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('last_activity_at', [$start, $end])
                  ->orWhereBetween('updated_at', [$start, $end]);
            })
            ->count();

        // Documents uploaded
        $documentsUploaded = DB::table('property_files')
            ->where('user_id', $user->id)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        // FICA
        $ficaTotal    = DB::table('contacts')->where('created_by_user_id', $user->id)->count();
        $ficaComplete = 0; // Will be calculated when FICA module is wired

        // Events
        $eventsTotal     = CalendarEvent::forUser($user->id)->whereBetween('event_date', [$start, $end])->count();
        $eventsCompleted = CalendarEvent::forUser($user->id)->where('status', 'completed')->whereBetween('event_date', [$start, $end])->count();

        // Activity points (from existing system)
        $period       = now()->format('Y-m');
        // M6.5 — achievement-total filter.
        $activityPoints = (int) DB::table('daily_activity_entries as e')
            ->join('activity_definitions as d', 'd.id', '=', 'e.activity_definition_id')
            ->where('e.user_id', $user->id)
            ->where('e.period', $period)
            ->where('d.is_enabled', 1)
            ->where('d.scope', 'system')
            ->whereIn('e.point_state', \App\Models\DailyActivityEntry::ACHIEVEMENT_TOTAL_STATES)
            ->whereIn('e.source', \App\Models\DailyActivityEntry::ACHIEVEMENT_TOTAL_SOURCES)
            ->sum(DB::raw('e.value * d.weight'));

        // Average response time (hours from task created to started)
        $avgResponse = CommandTask::forUser($user->id)
            ->whereNotNull('started_at')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, started_at)) as avg_hours')
            ->value('avg_hours') ?? 0;

        // Deals progressed
        $dealsProgressed = 0;
        try {
            if (\Schema::hasTable('deal_activity_log')) {
                $dealsProgressed = DB::table('deal_activity_log')
                    ->where('user_id', $user->id)
                    ->whereBetween('created_at', [$start, $end])
                    ->distinct('deal_id')
                    ->count('deal_id');
            }
        } catch (\Throwable $e) {
            // Table may not exist yet
        }

        // Overall score (weighted average)
        $taskScore    = $tasksTotal > 0 ? ($tasksCompleted / max($tasksTotal, 1)) * 100 : 50;
        $propScore    = $propertiesTotal > 0 ? ($propertiesAttended / max($propertiesTotal, 1)) * 100 : 50;
        $overdueScore = max(0, 100 - ($tasksOverdue * 10));

        $overall = (int) round(
            ($taskScore * 0.3) +
            ($propScore * 0.3) +
            ($overdueScore * 0.2) +
            (min(100, $activityPoints) * 0.2)
        );

        return AgentScorecard::updateOrCreate(
            [
                'user_id'      => $user->id,
                'period_type'  => $periodType,
                'period_start' => $start->toDateString(),
            ],
            [
                // Runs from the nightly console command — no Auth::user(), so
                // BelongsToAgency cannot infer agency_id (and the single-agency
                // net is off on a multi-agency install). Stamp from the agent
                // (User = Agent pillar). In values so a moved agent's card
                // re-homes rather than forking a new row.
                'agency_id'           => $user->agency_id,
                'period_end'          => $end->toDateString(),
                'tasks_completed'     => $tasksCompleted,
                'tasks_overdue'       => $tasksOverdue,
                'tasks_total'         => $tasksTotal,
                'properties_attended' => $propertiesAttended,
                'properties_total'    => $propertiesTotal,
                'documents_uploaded'  => $documentsUploaded,
                'fica_complete'       => $ficaComplete,
                'fica_total'          => $ficaTotal,
                'avg_response_hours'  => round($avgResponse, 2),
                'deals_progressed'    => $dealsProgressed,
                'events_completed'    => $eventsCompleted,
                'events_total'        => $eventsTotal,
                'activity_points'     => $activityPoints,
                'overall_score'       => max(0, min(100, $overall)),
                'computed_at'         => now(),
            ]
        );
    }

    /**
     * Calculate scorecards for all active agents.
     */
    public function calculateAllWeekly(): int
    {
        $count = 0;
        User::where('is_active', 1)->chunk(50, function ($users) use (&$count) {
            foreach ($users as $user) {
                try {
                    $this->calculateWeekly($user);
                    $count++;
                } catch (\Throwable $e) {
                    \Log::warning("Scorecard calc failed for user #{$user->id}: {$e->getMessage()}");
                }
            }
        });
        return $count;
    }
}
