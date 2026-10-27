<?php

namespace App\Console\Commands\RoadToSold;

use App\Models\RoadToSold\RtsTask;
use App\Services\RoadToSold\RecipeCatalogue;
use Illuminate\Console\Command;

/**
 * Road to Sold v3 (road-to-sold.md 22.8) - report what binding an agency's migrated tasks to CoreX events would do, from a mapping file Johan signs off.
 *
 *   rts:bind-tasks --agency=1 [--map=database/data/rts/hfc-event-mapping.json]
 *
 * REPORT ONLY. It cannot apply: binding a task also means switching off the old detector that credits the same thing (or each event would
 * credit twice), and that retirement is not built until Johan has signed the mapping. Nothing is written.
 */
class BindTasks extends Command
{
    protected $signature = 'rts:bind-tasks {--agency= : The agency id (required)} {--track=sales} {--map=database/data/rts/hfc-event-mapping.json}';

    protected $description = 'Road to Sold v3: report which migrated tasks would be bound to which events (report only until the mapping is signed off)';

    public function handle(): int
    {
        $agencyId = (int) $this->option('agency');
        if ($agencyId <= 0) {
            $this->error('Give the agency: --agency=1');

            return self::FAILURE;
        }
        $path = base_path((string) $this->option('map'));
        $map = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($map) || ! isset($map['bind'])) {
            $this->error("Could not read the mapping file {$path}.");

            return self::FAILURE;
        }
        $tasks = RtsTask::withoutGlobalScopes()->where('agency_id', $agencyId)->where('track', (string) $this->option('track'))->whereNull('deleted_at')->get()
            ->groupBy(fn ($t) => mb_strtolower(trim($t->label)));

        $rows = [];
        foreach ($map['bind'] as $b) {
            $found = $tasks->get(mb_strtolower(trim($b['task'])), collect());
            $event = $b['event'];
            $problems = [];
            if ($found->isEmpty()) {
                $problems[] = 'no such task in this agency';
            }
            if (! isset(RecipeCatalogue::EVENTS[$event])) {
                $problems[] = 'event is not on the allowed list';
            } else {
                foreach (array_keys($b['filter'] ?? []) as $fact) {
                    if (! isset(RecipeCatalogue::EVENTS[$event]['filters'][$fact])) {
                        $problems[] = "the event has no '{$fact}' to filter on";
                    }
                }
            }
            $rows[] = [$b['task'], $found->count() . ' task(s)', class_basename($event), json_encode($b['filter'] ?? []), $problems === [] ? 'would bind' : implode('; ', $problems), $b['note'] ?? ''];
        }
        $this->table(['Task', 'Found', 'Event', 'Filter', 'Result', 'Note for Johan'], $rows);
        $this->line('Keep as hand-ticked: ' . count($map['keep'] ?? []) . ' - Johan to say: ' . implode(', ', $map['johan_to_say'] ?? []) . ' - Road to Rented later: ' . count($map['rentals_later'] ?? []));
        $this->warn('Report only. Nothing was written, and this command cannot apply the mapping until Johan has signed it off and the matching old detectors can be retired.');

        return self::SUCCESS;
    }
}
