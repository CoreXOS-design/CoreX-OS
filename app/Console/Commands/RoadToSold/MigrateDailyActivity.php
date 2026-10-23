<?php

namespace App\Console\Commands\RoadToSold;

use App\Models\User;
use App\Services\RoadToSold\DailyActivityMigrationService;
use Illuminate\Console\Command;

/**
 * AT-454 v2 phase 3 - rts:migrate-daily-activity [--agency=ID] [--track=sales] [--apply] [--report=path.md]
 *
 * Moves an agency's daily-activity setup and history into the Road to Sold task recipe (road-to-sold.md section 7). A DRY RUN by
 * default: it writes nothing and prints what would move, with the counts and points before and after. --apply does it (one transaction
 * per agency; refuses unless the entry count and the counted points reconcile to the cent; safe to run again). Nothing old is dropped
 * or rewritten. --report writes the before/after report (per agent and month, under the corrected rule) for Johan.
 */
class MigrateDailyActivity extends Command
{
    protected $signature = 'rts:migrate-daily-activity {--agency= : one agency id (default: every agency in use)} {--track=sales} {--apply : do it (default is a dry run)} {--report= : write the before/after report to this markdown file}';

    protected $description = 'Move daily-activity definitions and history into the Road to Sold task recipe (dry run unless --apply)';

    public function handle(DailyActivityMigrationService $svc): int
    {
        $track = $this->option('track') === 'rentals' ? 'rentals' : 'sales';
        $agencies = $this->option('agency') ? [(int) $this->option('agency')] : $svc->agenciesInUse();
        $apply = (bool) $this->option('apply');
        if ($agencies === []) {
            $this->info('No agency has daily-activity entries or automatic credit rules.');

            return self::SUCCESS;
        }

        $this->line($apply ? 'APPLYING the move.' : 'DRY RUN - nothing is written.');
        $report = ["# Daily activity -> Road to Sold tasks - " . ($apply ? 'applied' : 'dry run') . ' ' . now()->format('j M Y H:i'), ''];

        foreach ($agencies as $agencyId) {
            $plan = $svc->plan($agencyId, $track);
            $this->newLine();
            $this->info("Agency {$agencyId} ({$track})" . ($plan['already_cut_over'] ? ' - already cut over' : ''));
            $this->table(['What', 'Number'], [
                ['activities in use -> tasks', $plan['definitions'] . ' (' . $plan['captured_tasks'] . ' captured, ' . $plan['legacy_fed_tasks'] . ' credited by CoreX)'],
                ['tasks still to create', $plan['tasks_to_create']],
                ['entries -> completions', $plan['entries'] . ' (' . $plan['entries_already_copied'] . ' already copied)'],
                ['points counted today (manual / automatic / total)', "{$plan['points_before']['manual']} / {$plan['points_before']['automatic']} / {$plan['points_before']['total']}"],
                ['the same history under the corrected rule (for information)', $plan['corrected_rule']],
            ]);
            $this->table(['State', 'Source', 'Entries'], array_map(fn ($r) => [$r['point_state'], $r['source'], $r['n']], $plan['entries_by_state_source']));

            $report[] = "## Agency {$agencyId}";
            $report[] = "- activities in use: {$plan['definitions']} ({$plan['captured_tasks']} captured tasks, {$plan['legacy_fed_tasks']} credited by CoreX); tasks to create: {$plan['tasks_to_create']}";
            $report[] = "- entries: {$plan['entries']}; points counted before: manual {$plan['points_before']['manual']}, automatic {$plan['points_before']['automatic']}, total {$plan['points_before']['total']}";
            $report[] = "- the same history under the corrected rule: {$plan['corrected_rule']}";

            if ($apply) {
                $done = $svc->apply($agencyId, $track);
                $this->info("  moved: {$done['completions']} completions; counted points after: {$done['points_after']['total']} (before {$done['points_before']['total']}) - reconciled.");
                $report[] = "- APPLIED: {$done['completions']} completions, points after {$done['points_after']['total']} = before {$done['points_before']['total']}; tasks created " . json_encode($done['tasks']);
            }

            $diff = $svc->beforeAfterByAgentMonth($agencyId);
            $names = User::query()->withoutGlobalScopes()->whereIn('id', collect($diff)->pluck('user_id'))->pluck('name', 'id');
            $report[] = '';
            $report[] = '### Before / after by agent and month (only where they differ)';
            $report[] = '| Agent | Month | Shown | Corrected rule |';
            $report[] = '|---|---|---:|---:|';
            foreach ($diff as $r) {
                $report[] = '| ' . ($names[$r['user_id']] ?? '#' . $r['user_id']) . " | {$r['period']} | {$r['shown']} | {$r['corrected']} |";
            }
            $this->line('  agent-months that differ under the corrected rule: ' . count($diff));
            $report[] = '';
        }

        if ($path = $this->option('report')) {
            file_put_contents($path, implode("\n", $report) . "\n");
            $this->info("Report written to {$path}");
        }

        return self::SUCCESS;
    }
}
