<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CarryForwardTargets extends Command
{
    protected $signature = 'targets:carry-forward
                            {--month= : Target month (default: current)}
                            {--year= : Target year (default: current)}
                            {--force : Overwrite existing targets for the target month}';

    protected $description = 'Copy previous month targets to current month for all agents and branches';

    public function handle(): int
    {
        $targetMonth = (int) ($this->option('month') ?: now()->month);
        $targetYear  = (int) ($this->option('year') ?: now()->year);
        $force       = (bool) $this->option('force');

        $targetPeriod = sprintf('%04d-%02d', $targetYear, $targetMonth);

        $prevDate   = Carbon::create($targetYear, $targetMonth, 1)->subMonth();
        $prevPeriod = $prevDate->format('Y-m');

        $this->info("Carrying forward targets from {$prevPeriod} to {$targetPeriod}" . ($force ? ' (force mode)' : ''));

        $totals = [
            'targets'             => $this->carryTable('targets', 'user_id', $prevPeriod, $targetPeriod, $force),
            // Branch and agency requirements now live in rts_budgets (Road to Sold > Budgets, re-home step 4).
            // Agent budgets are NOT copied here: My Portal carries the latest figures forward when reading the current
            // month, and a manager can copy them explicitly. Listing targets are the `targets` listings number now.
            'rts_budgets'         => $this->carryRequirements($prevPeriod, $targetPeriod, $force),
            'activity_targets'    => $this->carryTable('activity_targets', 'user_id', $prevPeriod, $targetPeriod, $force),
            'activity_point_goals' => $this->carryTable('activity_point_goals', 'user_id', $prevPeriod, $targetPeriod, $force),
        ];

        $this->newLine();
        $this->table(['Table', 'Created', 'Skipped', 'Overwritten'], collect($totals)->map(function ($t, $table) {
            return [$table, $t['created'], $t['skipped'], $t['overwritten']];
        })->values()->all());

        $totalCreated = collect($totals)->sum('created');
        if ($totalCreated === 0 && collect($totals)->sum('overwritten') === 0) {
            $this->warn('No new targets were carried forward. Either previous month had no data or current month already has all entries.');
        } else {
            $this->info('Done.');
        }

        return self::SUCCESS;
    }

    /**
     * Carry forward a standard per-user target table.
     * Unique key: period + user_id
     */
    private function carryTable(string $table, string $userKey, string $prevPeriod, string $targetPeriod, bool $force): array
    {
        $stats = ['created' => 0, 'skipped' => 0, 'overwritten' => 0];

        if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
            $this->warn("  Table '{$table}' does not exist — skipping.");
            return $stats;
        }

        $hasSoftDeletes = \Illuminate\Support\Facades\Schema::hasColumn($table, 'deleted_at');

        $prevRows = DB::table($table)
            ->where('period', $prevPeriod)
            ->when($hasSoftDeletes, fn ($q) => $q->whereNull('deleted_at'))
            ->get();

        if ($prevRows->isEmpty()) {
            $this->line("  {$table}: no rows for {$prevPeriod}");
            return $stats;
        }

        foreach ($prevRows as $row) {
            $data    = (array) $row;
            $userId  = $data[$userKey] ?? null;

            $existsQuery = DB::table($table)
                ->where('period', $targetPeriod)
                ->when($hasSoftDeletes, fn ($q) => $q->whereNull('deleted_at'));

            if ($userId !== null) {
                $existsQuery->where($userKey, $userId);
            }

            // Also match branch_id for rows that use it (like monthly_target_goals)
            if (isset($data['branch_id'])) {
                $existsQuery->where('branch_id', $data['branch_id']);
            }

            $exists = $existsQuery->exists();

            if ($exists && ! $force) {
                $stats['skipped']++;
                continue;
            }

            unset($data['id']);
            if ($hasSoftDeletes) {
                unset($data['deleted_at']);
            }
            $data['period']     = $targetPeriod;
            $data['created_at'] = now();
            $data['updated_at'] = now();

            if ($exists && $force) {
                $updateQuery = DB::table($table)
                    ->where('period', $targetPeriod)
                    ->when($hasSoftDeletes, fn ($q) => $q->whereNull('deleted_at'));

                if ($userId !== null) {
                    $updateQuery->where($userKey, $userId);
                }
                if (isset($data['branch_id'])) {
                    $updateQuery->where('branch_id', $data['branch_id']);
                }

                unset($data['period']); // don't update period in the WHERE match
                $updateQuery->update($data);
                $stats['overwritten']++;
            } else {
                DB::table($table)->insert($data);
                $stats['created']++;
            }
        }

        $this->line("  {$table}: {$stats['created']} created, {$stats['skipped']} skipped, {$stats['overwritten']} overwritten");
        return $stats;
    }

    /**
     * Carry forward what each branch (and the agency) needs: rts_budgets rows of level branch / agency.
     * A (who, month) that already has a row - live or archived - is left alone unless --force, which updates a LIVE
     * row's amount. Nothing is ever deleted.
     */
    private function carryRequirements(string $prevPeriod, string $targetPeriod, bool $force): array
    {
        $stats = ['created' => 0, 'skipped' => 0, 'overwritten' => 0];
        $table = 'rts_budgets';

        if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
            $this->warn("  Table '{$table}' does not exist - skipping.");
            return $stats;
        }

        $prevRows = DB::table($table)->where('period', $prevPeriod)->whereNull('deleted_at')
            ->whereIn('level', ['branch', 'agency'])->where('requirement', '>', 0)->get();

        foreach ($prevRows as $row) {
            $existing = DB::table($table)->where('agency_id', $row->agency_id)->where('track', $row->track)
                ->where('scope_key', $row->scope_key)->where('period', $targetPeriod)->first();

            if ($existing && (! $force || $existing->deleted_at !== null)) {
                $stats['skipped']++;
                continue;
            }
            if ($existing) {
                DB::table($table)->where('id', $existing->id)->update(['requirement' => $row->requirement, 'updated_at' => now()]);
                $stats['overwritten']++;
                continue;
            }
            DB::table($table)->insert([
                'agency_id' => $row->agency_id, 'branch_id' => $row->branch_id, 'user_id' => null, 'track' => $row->track,
                'level' => $row->level, 'scope_key' => $row->scope_key, 'period' => $targetPeriod,
                'requirement' => $row->requirement, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $stats['created']++;
        }

        $this->line("  {$table}: {$stats['created']} created, {$stats['skipped']} skipped, {$stats['overwritten']} overwritten");
        return $stats;
    }
}
