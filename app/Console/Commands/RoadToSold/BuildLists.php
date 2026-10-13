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
 * working day for that agent. Scheduled 03:30, so the list is waiting when the agent starts the day;
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

        foreach ($users as $user) {
            if (! $this->eligible($user)) {
                continue;
            }
            try {
                $closed = $this->option('no-close') ? ['missed' => 0, 'rolled' => 0] : $builder->closePastDays($user, $day, $track);
                $items = $builder->buildFor($user, $day, $track);
                $built++;
                $this->line(sprintf(
                    '#%d %s — %d on the list (%d follow-up, %d carried over), closed %d missed / %d rolled',
                    $user->id, $user->name, $items->count(),
                    $items->where('origin', 'follow_up')->count(), $items->where('origin', 'rollover')->count(),
                    $closed['missed'], $closed['rolled']
                ));
            } catch (\Throwable $e) {
                $failed++;
                ListBuilderService::logFailure($user, $e);
                $this->error("#{$user->id} {$user->name} — failed: {$e->getMessage()}");
            }
        }

        $this->info("Road to Sold {$day->toDateString()}: {$built} agents processed, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
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
