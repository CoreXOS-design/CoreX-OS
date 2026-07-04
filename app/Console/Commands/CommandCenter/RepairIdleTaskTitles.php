<?php

namespace App\Console\Commands\CommandCenter;

use App\Models\CommandCenter\CommandTask;
use App\Models\Property;
use App\Support\HumanDiff;
use Illuminate\Console\Command;

/**
 * AT-164 — one-time (idempotent) repair of the "Property needs attention — no activity
 * in -0.605399…days" to-do titles. Those titles were generated with Carbon 3's signed
 * FLOAT diffInDays (fixed at source in AutoEventService via App\Support\HumanDiff);
 * this rewrites the already-persisted rows to the humanised phrasing.
 *
 * Re-runnable: after a pass, repaired titles carry the new "no activity for N days"
 * wording and no longer match the malformed pattern.
 */
class RepairIdleTaskTitles extends Command
{
    protected $signature = 'command-center:repair-idle-task-titles {--dry-run : Report only, write nothing}';
    protected $description = 'Rewrite idle-property to-do titles that embedded a raw signed/fractional day count';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // Malformed = the old "no activity in <number> days" phrasing (any number,
        // incl. the negative/fractional garbage). The new phrasing is "no activity
        // for …", so a repaired row never re-matches → idempotent.
        $query = CommandTask::withoutGlobalScopes()
            ->where('task_type', 'review')
            ->where('title', 'like', '%no activity in %days%');

        $total    = (clone $query)->count();
        $repaired = 0;

        $this->info(($dry ? '[dry-run] ' : '') . "Scanning {$total} candidate to-do titles…");

        $query->chunkById(200, function ($tasks) use (&$repaired, $dry) {
            foreach ($tasks as $task) {
                $newTitle = $this->rebuildTitle($task);
                if ($newTitle === null || $newTitle === $task->title) {
                    continue;
                }
                if (!$dry) {
                    $task->title = $newTitle;
                    $task->saveQuietly();
                }
                $repaired++;
            }
        });

        $this->info(($dry ? '[dry-run] would repair ' : 'Repaired ') . "{$repaired} of {$total}.");

        return self::SUCCESS;
    }

    /**
     * Rebuild the title in the canonical shape. Prefers recomputing the idle span
     * from the linked property (the stored number was always garbage); falls back to
     * neutral wording when the property is gone, and preserves the URGENT prefix.
     */
    private function rebuildTitle(CommandTask $task): ?string
    {
        $urgent = str_starts_with($task->title, 'URGENT:') ? 'URGENT: ' : '';

        $property = $task->property_id
            ? Property::withoutGlobalScopes()->withTrashed()->find($task->property_id)
            : null;

        if ($property) {
            $lastActivity = $property->last_activity_at ?? $property->updated_at;
            $span    = $lastActivity ? HumanDiff::days($lastActivity) : 'a while';
            $address = $property->buildDisplayAddress() ?: 'Property #' . $property->id;
            return "{$urgent}Property needs attention — no activity for {$span} — {$address}";
        }

        // Property unresolvable: keep everything after the address separator, just
        // neutralise the broken "no activity in <num> days" fragment.
        $tail = '';
        if (($pos = strpos($task->title, ' — ', strlen($urgent))) !== false) {
            // second " — " onward is the address portion
            $rest = substr($task->title, $pos + strlen(' — '));
            if (($pos2 = strpos($rest, ' — ')) !== false) {
                $tail = ' — ' . substr($rest, $pos2 + strlen(' — '));
            }
        }
        return "{$urgent}Property needs attention — no activity recently{$tail}";
    }
}
