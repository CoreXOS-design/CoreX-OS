<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\RoadToSold\AllocationSource;
use App\Services\RoadToSold\ListBuilderService;
use Illuminate\Console\Command;

/**
 * Read-only: what the daily allocation engine WOULD load for one agent on one day, by source (road-to-sold.md 23.14 / 23.22). Writes nothing, takes no
 * stock, ignores nobody: an agent who has not pressed Start is reported as such, with what would load once they do.
 */
class RtsAllocationPreview extends Command
{
    protected $signature = 'rts:allocation-preview {user : the agent\'s user id} {--date= : the working day (default: today)} {--track=sales} {--rows=10 : how many rows to print}';

    protected $description = 'Show what the Road to Sold allocation would load for an agent on a day, by source (writes nothing)';

    public function handle(ListBuilderService $builder): int
    {
        $user = User::withoutGlobalScopes()->find((int) $this->argument('user'));
        if (! $user) {
            $this->error('No such user.');

            return self::FAILURE;
        }
        $date = $this->option('date') ?: now()->toDateString();
        $p = $builder->preview($user, $date, (string) $this->option('track'));

        $this->line("{$user->name} (#{$user->id}) on {$date}: " . ($p['state'] === 'loading' ? 'lists load for this agent' : 'NOT loading (not started, paused, or before the first list day)') . ", target {$p['target']}");
        $this->line('would load: ' . array_sum($p['by_source']));
        foreach (AllocationSource::LABELS as $kind => $label) {
            if (isset($p['by_source'][$kind])) {
                $this->line(sprintf('  %2d  %s', $p['by_source'][$kind], $label));
            }
        }
        foreach (array_slice($p['rows'], 0, (int) $this->option('rows')) as $i => $r) {
            $this->line(sprintf('  %2d. [%s] %s%s', $i + 1, $r['kind'], $r['line'], $r['has_address'] ? '' : ' (no address)'));
        }

        return self::SUCCESS;
    }
}
