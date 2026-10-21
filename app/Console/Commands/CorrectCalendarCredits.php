<?php

namespace App\Console\Commands;

use App\Services\Activity\ActivityPointsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Put right the confirmed calendar credits that were counted at weight x weight
 * (agency-tracker-rehome.md 5.6 defect 1; Johan, 21 Oct 2026: "correct the 21 overstated calendar credits").
 *
 * The defect: calendar mappings were seeded with the activity's WEIGHT as their count per event, so one
 * calendar event stored value = 200 and was then multiplied by the weight of 200 again (40,000 points).
 * Migration 2026_11_26_100000 fixed every NEW credit and the still-provisional ones; this command corrects the
 * credits that had already been CONFIRMED at the wrong size, because changing confirmed points needs Johan's go.
 *
 * Which entries: source auto_calendar, state confirmed, a count above 1 that equals the activity's weight
 * (the seeded copy). Entries a manager OVERRIDDEN are never touched (a person chose that number), nor are revoked,
 * manual or instant ones, nor a count an agency chose for itself.
 * What changes: the count becomes 1 (one event = one count); points follow from the shared rule
 * (ActivityPointsService: count x weight). Nothing else on the row moves.
 * Audit: one row per entry in daily_activity_entry_corrections (credit id, agent, month, old / new count and points).
 *
 * Safe to run again: an entry is corrected once (unique key + the selection no longer matches). Dry run by default.
 * On a production environment it refuses unless --force-production is given - live is promoted only on Johan's order.
 */
class CorrectCalendarCredits extends Command
{
    public const CORRECTION_KEY = 'calendar-weight-squared-2026-10';

    protected $signature = 'daily-points:correct-calendar-credits
                            {--apply : Actually correct the entries (without it, only show what would change)}
                            {--agency= : Only this agency id}
                            {--force-production : Required to run when APP_ENV is production}';

    protected $description = 'Correct confirmed calendar credits that were counted at weight x weight (audited, idempotent; dry run unless --apply)';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force-production')) {
            $this->error('Refusing to run on a production environment without --force-production. Run it on live only on Johan\'s order.');

            return self::FAILURE;
        }
        if (! DB::getSchemaBuilder()->hasTable('daily_activity_entry_corrections')) {
            $this->error('The audit table is missing - run the migrations first.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $candidates = $this->candidates($this->option('agency') !== null ? (int) $this->option('agency') : null);

        if ($candidates->isEmpty()) {
            $this->info('Nothing to correct - every confirmed calendar credit is already at the right size.');

            return self::SUCCESS;
        }

        $this->line(($apply ? 'Correcting ' : 'Dry run - would correct ') . $candidates->count() . ' confirmed calendar credit(s):');
        $done = 0;
        $totalOld = 0.0;
        $totalNew = 0.0;
        $rows = [];

        foreach ($candidates as $c) {
            $oldPts = ActivityPointsService::pointsFor((int) $c->value, $c->weight);
            $newPts = ActivityPointsService::pointsFor(1, $c->weight);

            if ($apply) {
                $ok = DB::transaction(function () use ($c, $oldPts, $newPts) {
                    // Re-check under a lock: it must still be exactly what we selected.
                    $live = DB::table('daily_activity_entries')->where('id', $c->id)->lockForUpdate()->first();
                    if (! $live || (int) $live->value !== (int) $c->value || $live->point_state !== 'confirmed' || $live->source !== 'auto_calendar') {
                        return false;
                    }
                    $already = DB::table('daily_activity_entry_corrections')->where('entry_id', $c->id)->where('correction_key', self::CORRECTION_KEY)->exists();
                    if ($already) {
                        return false;
                    }
                    DB::table('daily_activity_entries')->where('id', $c->id)->update(['value' => 1, 'updated_at' => now()]);
                    DB::table('daily_activity_entry_corrections')->insert([
                        'entry_id' => $c->id, 'correction_key' => self::CORRECTION_KEY, 'agency_id' => $c->agency_id, 'user_id' => $c->user_id,
                        'activity_definition_id' => $c->activity_definition_id, 'activity_date' => $c->activity_date, 'period' => $c->period,
                        'weight' => $c->weight, 'old_value' => (int) $c->value, 'new_value' => 1, 'old_points' => $oldPts, 'new_points' => $newPts,
                        'reason' => 'Calendar credit was counted at weight x weight; one event is one count (agency-tracker-rehome.md 5.6 defect 1)',
                        'ran_on' => substr((string) config('app.url'), 0, 120), 'corrected_at' => now(),
                    ]);

                    return true;
                });
                if (! $ok) {
                    continue;
                }
                $done++;
            }

            $totalOld += $oldPts;
            $totalNew += $newPts;
            $rows[] = [$c->id, $c->agent, $c->period, $c->activity, (int) $c->value, 1, number_format($oldPts, 0, '.', ' '), number_format($newPts, 0, '.', ' ')];
        }

        $this->table(['Credit', 'Agent', 'Month', 'Activity', 'Old count', 'New count', 'Old points', 'New points'], $rows);
        $this->info(($apply ? "Corrected {$done}" : 'Would correct ' . count($rows)) . ' credit(s): '
            . number_format($totalOld, 0, '.', ' ') . ' points -> ' . number_format($totalNew, 0, '.', ' ') . ' points.');
        if (! $apply) {
            $this->warn('Dry run only. Run again with --apply to make the change.');
        }

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int,object> */
    private function candidates(?int $agencyId)
    {
        return DB::table('daily_activity_entries as e')
            ->join('activity_definitions as d', 'd.id', '=', 'e.activity_definition_id')
            ->join('users as u', 'u.id', '=', 'e.user_id')
            ->where('e.source', 'auto_calendar')
            ->where('e.point_state', 'confirmed')
            ->where('e.value', '>', 1)
            ->whereRaw('e.`value` = ROUND(d.`weight`)')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('daily_activity_entry_corrections as k')
                    ->whereColumn('k.entry_id', 'e.id')->where('k.correction_key', self::CORRECTION_KEY);
            })
            ->when($agencyId, fn ($q) => $q->where('e.agency_id', $agencyId))
            ->orderBy('e.period')->orderBy('u.name')->orderBy('e.id')
            ->get(['e.id', 'e.agency_id', 'e.user_id', 'e.activity_definition_id', 'e.activity_date', 'e.period', 'e.value',
                'd.weight', 'd.name as activity', 'u.name as agent']);
    }
}
