<?php

namespace App\Console\Commands\RoadToSold;

use App\Models\RoadToSold\RtsListItem;
use App\Models\User;
use App\Services\RoadToSold\ListBuilderService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Road to Sold 23.30.3: the midnight job. Closes every earlier day that still has open rows for an agent, using the SAME clock and working-day calendar as the
 * allocation engine (ListBuilderService::closePastDays): a row loaded and never started is released back to the Market Intelligence pool and counted as
 * missed against the agent for that day; with the agency's carry-over setting on (missed_items_mode = roll_over) it is carried to the top of the next working day
 * instead, counting toward that day's N. A row the agent started on keeps its property.
 *
 *   rts:close-day                    all agents with open rows from earlier days
 *   rts:close-day --agency=1         one agency
 *   rts:close-day --user=22          one agent
 *   rts:close-day --date=2026-11-04  treat this as "today" (rows of earlier days close)
 *   rts:close-day --dry-run          say what would close, change nothing
 *
 * It does not build tomorrow's list - rts:build-lists does that at 04:30 (and closes anything this missed). Safe to run twice.
 */
class CloseDay extends Command
{
    protected $signature = 'rts:close-day
                            {--date= : Treat this day (Y-m-d) as today. Default: today}
                            {--agency= : Only this agency id}
                            {--user= : Only this user id}
                            {--track=sales : sales (rentals arrive with Road to Rented)}
                            {--dry-run : Report only}';

    protected $description = 'Road to Sold: close earlier days at midnight - release untouched loaded properties to the pool (or carry them over, per the agency setting)';

    public function handle(ListBuilderService $builder): int
    {
        $today = Carbon::parse($this->option('date') ?: now())->startOfDay();
        $track = (string) $this->option('track');

        $ids = RtsListItem::withoutGlobalScopes()->where('status', RtsListItem::STATUS_OPEN)->where('track', $track)
            ->where('list_date', '<', $today->toDateString())
            ->when($this->option('agency'), fn ($q, $a) => $q->where('agency_id', (int) $a))
            ->when($this->option('user'), fn ($q, $u) => $q->where('user_id', (int) $u))
            ->distinct()->pluck('user_id');

        $totals = ['missed' => 0, 'rolled' => 0, 'released' => 0];
        $failed = 0;
        foreach (User::withoutGlobalScopes()->whereIn('id', $ids)->orderBy('agency_id')->orderBy('id')->get() as $user) {
            try {
                if ($this->option('dry-run')) {
                    $n = RtsListItem::withoutGlobalScopes()->where('user_id', $user->id)->where('track', $track)->where('status', RtsListItem::STATUS_OPEN)->where('list_date', '<', $today->toDateString())->count();
                    $this->line("#{$user->id} {$user->name} - {$n} open row(s) from earlier days would close");
                    continue;
                }
                $r = $builder->closePastDays($user, $today, $track);
                foreach ($totals as $k => $_) {
                    $totals[$k] += $r[$k] ?? 0;
                }
                $this->line(sprintf('#%d %s - %d missed, %d carried over, %d released to the pool', $user->id, $user->name, $r['missed'], $r['rolled'], $r['released']));
            } catch (\Throwable $e) {
                $failed++;
                ListBuilderService::logFailure($user, $e);
                $this->error("#{$user->id} {$user->name} - failed to close: {$e->getMessage()}");
            }
        }
        $this->info(sprintf('Road to Sold close-day %s: %d missed, %d carried over, %d released to the pool, %d failed.', $today->toDateString(), $totals['missed'], $totals['rolled'], $totals['released'], $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
