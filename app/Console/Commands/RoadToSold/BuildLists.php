<?php

namespace App\Console\Commands\RoadToSold;

use App\Models\User;
use App\Services\RoadToSold\ListBuilderService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * AT-454 — the nightly Road to Sold build.
 *
 * For every agent who can use Road to Sold: first CLOSE the open items of earlier days (missed, or
 * rolled onto the next working day when the agency rolls over), then BUILD today's list if today is a
 * working day for that agent. The build runs in ROUNDS so stock is dealt out across the agents (road-to-sold.md 22.6). Scheduled 04:30, so the list is waiting when the agent starts the day;
 * the page also builds on demand when it finds none. Safe to re-run: the builder tops up to the target
 * and adds nothing the second time.
 *
 *   rts:build-lists                      all eligible agents, today
 *   rts:build-lists --user=22            one agent
 *   rts:build-lists --agency=1           one agency
 *   rts:build-lists --date=2026-10-15    build for a given day (closes days before it)
 *   rts:build-lists --no-close           build only
 *
 * One agent failing never stops the rest.
 */
class BuildLists extends Command
{
    protected $signature = 'rts:build-lists
                            {--date= : The day to build (Y-m-d). Default: today}
                            {--agency= : Only this agency id}
                            {--user= : Only this user id}
                            {--track=sales : sales (rentals arrive with Road to Rented)}
                            {--no-close : Do not close earlier days first}';

    protected $description = 'Road to Sold: close earlier days and build each agent\'s daily call list';

    public function handle(ListBuilderService $builder): int
    {
        $day = Carbon::parse($this->option('date') ?: now())->startOfDay();
        $track = (string) $this->option('track');
        if (! in_array($track, \App\Models\RoadToSold\RtsSetting::TRACKS, true)) {
            $this->error("Unknown track '{$track}'. Use: " . implode(', ', \App\Models\RoadToSold\RtsSetting::TRACKS) . '.');

            return self::FAILURE;
        }
        $failed = 0;
        $built = 0;

        $users = User::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereNotNull('agency_id')
            ->when($this->option('agency'), fn ($q, $a) => $q->where('agency_id', (int) $a))
            ->when($this->option('user'), fn ($q, $u) => $q->where('id', (int) $u))
            ->orderBy('agency_id')->orderBy('id')
            ->get();

        $agents = $users->filter(fn (User $u) => $this->eligible($u))->values();

        // 1. close earlier days (missed / rolled), per agent
        $closedBy = [];
        foreach ($agents as $user) {
            try {
                $closedBy[$user->id] = $this->option('no-close') ? ['missed' => 0, 'rolled' => 0] : $builder->closePastDays($user, $day, $track);
            } catch (\Throwable $e) {
                $closedBy[$user->id] = ['missed' => 0, 'rolled' => 0];
                $failed++;
                ListBuilderService::logFailure($user, $e);
                $this->error("#{$user->id} {$user->name} — failed to close earlier days: {$e->getMessage()}");
            }
        }

        // 2. build in ROUNDS (road-to-sold.md 22.6): in each round every agent of an agency takes the best unowned property for their next
        //    place, so the stock is dealt out like cards instead of the first agent taking the best five. The rotation start moves with the day.
        $byAgency = $agents->groupBy('agency_id');
        $broken = [];
        foreach ($byAgency as $group) {
            $group = $group->values();
            $start = $group->count() > 0 ? ((int) $day->dayOfYear) % $group->count() : 0;
            $ordered = $group->slice($start)->merge($group->slice(0, $start))->values();
            $rounds = max(1, (int) $ordered->map(fn (User $u) => $this->targetFor($u, $day, $track))->max());
            for ($round = 1; $round <= $rounds; $round++) {
                foreach ($ordered as $user) {
                    if (isset($broken[$user->id])) {
                        continue;
                    }
                    try {
                        $builder->buildFor($user, $day, $track, $round);
                    } catch (\Throwable $e) {
                        $broken[$user->id] = true;
                        $failed++;
                        ListBuilderService::logFailure($user, $e);
                        $this->error("#{$user->id} {$user->name} — failed: {$e->getMessage()}");
                    }
                }
            }
        }

        // 3. report
        foreach ($agents as $user) {
            if (isset($broken[$user->id])) {
                continue;
            }
            $items = $builder->listFor($user, $day, $track);
            $built++;
            $closed = $closedBy[$user->id] ?? ['missed' => 0, 'rolled' => 0];
            $this->line(sprintf(
                '#%d %s — %d on the list (%d follow-up, %d carried over), closed %d missed / %d rolled',
                $user->id, $user->name, $items->count(),
                $items->where('origin', 'follow_up')->count(), $items->where('origin', 'rollover')->count(),
                $closed['missed'], $closed['rolled']
            ));
        }

        $this->info("Road to Sold {$day->toDateString()}: {$built} agents processed, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function targetFor(User $user, Carbon $day, string $track): int
    {
        return \App\Services\RoadToSold\WorkdayService::make()->targetFor($user, $day, $track);
    }

    /** Active selling users who hold the Road to Sold access permission; assistants work through their agent. */
    private function eligible(User $user): bool
    {
        if ($user->isAssistant()) {
            return false;
        }

        return $user->hasPermission('access_road_to_sold');
    }
}
