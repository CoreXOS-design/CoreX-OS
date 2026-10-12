<?php

namespace App\Console\Commands;

use App\Models\CommandCenter\AgencyFeedbackOption;
use App\Services\Properties\PropertyViewings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One-off, reversible, idempotent move of viewing feedback into the ONE store (R1).
 * Spec: .ai/specs/calendar-viewing-feedback.md
 *
 * Rows written by the current per-property form keep their outcome (a label string), concern ticks and seller note
 * inside the kind_specific_data JSON bundle and are stamped feedback_kind='listing_presentation' even though they sit
 * on viewing appointments (mismatches 1, 4, 10). This copies those values into the real columns
 * (outcome_option_id, concern_option_ids, seller_visible_notes) and restamps feedback_kind='viewing'.
 *
 *  - DRY RUN BY DEFAULT: prints exactly what would change and writes nothing. Pass --apply to write.
 *  - ORIGINALS KEPT: kind_specific_data is never cleared; every changed column's previous value is stored in
 *    viewing_feedback_migration_log (before / after) per row.
 *  - IDEMPOTENT: a row already in the log (and not reversed) is skipped; a second --apply changes nothing.
 *  - REVERSIBLE: --reverse puts the logged "before" values back (latest run, or --run=<token>).
 *  - Nothing is deleted. Rows with content in neither store are left exactly as they are (they simply do not
 *    count as feedback any more - PropertyViewings ignores blank rows).
 */
class MigrateViewingFeedbackSingleStore extends Command
{
    protected $signature = 'viewing-feedback:migrate-single-store
        {--apply : write the changes (default is a dry run that writes nothing)}
        {--reverse : undo a previous --apply run}
        {--run= : run token to reverse (default: the most recent run that is not yet reversed)}';

    protected $description = 'Move viewing feedback from the JSON bundle into the real columns (dry run by default; reversible; idempotent)';

    public function handle(): int
    {
        return $this->option('reverse') ? $this->reverse() : $this->forward();
    }

    private function forward(): int
    {
        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'APPLY run - writing changes.' : 'DRY RUN - nothing will be written (pass --apply to write).');

        $hasLog = \Illuminate\Support\Facades\Schema::hasTable('viewing_feedback_migration_log');
        if ($apply && ! $hasLog) {
            $this->error('The viewing_feedback_migration_log table is missing - run php artisan migrate first.');

            return self::FAILURE;
        }
        // A dry run also works before the schema migration has run (nothing can be logged yet).
        $already = $hasLog ? DB::table('viewing_feedback_migration_log')->whereNull('reversed_at')->pluck('feedback_id')->all() : [];

        $rows = DB::table('calendar_event_feedback as f')
            ->join('calendar_events as e', 'e.id', '=', 'f.calendar_event_id')
            ->whereNull('f.deleted_at')
            ->whereIn('e.category', PropertyViewings::VIEWING_CATEGORIES)
            ->whereNotNull('f.kind_specific_data')
            ->select('f.*')
            ->orderBy('f.id')
            ->get();

        $optionIds = AgencyFeedbackOption::withoutGlobalScopes()->whereIn('category', ['outcome', 'lp_outcome'])->get(['id', 'label', 'category', 'agency_id']);
        $byLabel = [];
        foreach ($optionIds->sortBy(fn ($o) => $o->category === 'outcome' ? 0 : 1) as $o) {
            $byLabel[Str::lower(trim($o->label))] ??= (int) $o->id;
        }
        $labels = AgencyFeedbackOption::withoutGlobalScopes()->pluck('label', 'id');

        $stats = ['examined' => $rows->count(), 'already_migrated' => 0, 'to_change' => 0, 'outcome_mapped' => 0, 'outcome_unmapped' => 0,
                  'concerns_carried' => 0, 'seller_note_carried' => 0, 'kind_restamped' => 0, 'nothing_to_carry' => 0];
        $unmapped = [];
        $plan = [];

        foreach ($rows as $r) {
            if (in_array($r->id, $already, true)) {
                $stats['already_migrated']++;
                continue;
            }
            $kd = json_decode($r->kind_specific_data ?? 'null', true);
            if (! is_array($kd)) {
                $kd = [];
            }

            $beforeConcerns = json_decode($r->concern_option_ids ?? 'null', true) ?: [];
            $kdConcerns = array_values(array_filter(array_map('intval', (array) ($kd['concern_ids'] ?? []))));
            $afterConcerns = array_values(array_unique(array_merge($beforeConcerns ? array_map('intval', $beforeConcerns) : [], $kdConcerns)));
            sort($afterConcerns);

            $afterOutcome = $r->outcome_option_id ? (int) $r->outcome_option_id : null;
            $outcomeNote = null;
            if ($afterOutcome === null && ! empty($kd['outcome'])) {
                $mapped = $byLabel[Str::lower(trim((string) $kd['outcome']))] ?? null;
                if ($mapped) {
                    $afterOutcome = $mapped;
                    $stats['outcome_mapped']++;
                } else {
                    $stats['outcome_unmapped']++;
                    $unmapped[] = "row {$r->id}: \"{$kd['outcome']}\"";
                    $outcomeNote = 'UNMAPPED outcome "' . $kd['outcome'] . '" (kept in kind_specific_data)';
                }
            }

            $afterSeller = trim((string) $r->seller_visible_notes) !== '' ? $r->seller_visible_notes
                : (trim((string) ($kd['seller_notes'] ?? '')) !== '' ? $kd['seller_notes'] : null);

            $before = [
                'feedback_kind'        => $r->feedback_kind,
                'outcome_option_id'    => $r->outcome_option_id,
                'concern_option_ids'   => $r->concern_option_ids,
                'seller_visible_notes' => $r->seller_visible_notes,
            ];
            $after = [
                'feedback_kind'        => 'viewing',
                'outcome_option_id'    => $afterOutcome,
                'concern_option_ids'   => json_encode($afterConcerns),
                'seller_visible_notes' => $afterSeller,
            ];

            // Normalise for comparison (JSON text formatting must not look like a change).
            $beforeCmp = $before;
            $beforeCmp['concern_option_ids'] = json_encode(array_values(array_map('intval', $beforeConcerns)));
            if ($beforeCmp === $after) {
                $stats['nothing_to_carry']++;
                continue;
            }

            if ($r->feedback_kind !== 'viewing') {
                $stats['kind_restamped']++;
            }
            if ($afterConcerns !== array_values(array_map('intval', $beforeConcerns))) {
                $stats['concerns_carried']++;
            }
            if ($afterSeller !== null && trim((string) $r->seller_visible_notes) === '') {
                $stats['seller_note_carried']++;
            }
            $stats['to_change']++;
            $plan[] = [
                'id' => $r->id, 'event' => $r->calendar_event_id, 'property' => $r->property_id,
                'kind' => $r->feedback_kind . ' -> viewing',
                'outcome' => $afterOutcome ? ($labels[$afterOutcome] ?? $afterOutcome) : ($outcomeNote ?? '-'),
                'concerns' => $afterConcerns ? collect($afterConcerns)->map(fn ($i) => $labels[$i] ?? $i)->implode(', ') : '-',
                'seller_note' => $afterSeller ? Str::limit($afterSeller, 30) : '-',
                '_before' => $before, '_after' => $after,
            ];
        }

        if ($plan) {
            $this->table(['row', 'event', 'property', 'feedback_kind', 'outcome', 'concern ticks', 'seller note'],
                array_map(fn ($p) => [$p['id'], $p['event'], $p['property'], $p['kind'], $p['outcome'], $p['concerns'], $p['seller_note']], $plan));
        }
        foreach ($stats as $k => $v) {
            $this->line(sprintf('  %-22s %d', str_replace('_', ' ', $k), $v));
        }
        if ($unmapped) {
            $this->warn('Outcome labels with no matching option (left in kind_specific_data): ' . implode('; ', $unmapped));
        }

        if (! $apply) {
            $this->info('Dry run complete - nothing written.');

            return self::SUCCESS;
        }
        if (! $plan) {
            $this->info('Nothing to change.');

            return self::SUCCESS;
        }

        $token = 'vf-' . now()->format('YmdHis') . '-' . Str::lower(Str::random(6));
        DB::transaction(function () use ($plan, $token) {
            foreach ($plan as $p) {
                DB::table('calendar_event_feedback')->where('id', $p['id'])->update($p['_after']);
                DB::table('viewing_feedback_migration_log')->insert([
                    'run_token'  => $token,
                    'feedback_id' => $p['id'],
                    'before'     => json_encode($p['_before']),
                    'after'      => json_encode($p['_after']),
                    'applied_at' => now(),
                ]);
            }
        });
        $this->info("Applied {$stats['to_change']} rows. Run token: {$token}  (undo with: viewing-feedback:migrate-single-store --reverse --run={$token})");

        return self::SUCCESS;
    }

    private function reverse(): int
    {
        $token = $this->option('run');
        if (! $token) {
            $token = DB::table('viewing_feedback_migration_log')->whereNull('reversed_at')->orderByDesc('id')->value('run_token');
        }
        if (! $token) {
            $this->info('No run to reverse.');

            return self::SUCCESS;
        }
        $entries = DB::table('viewing_feedback_migration_log')->where('run_token', $token)->whereNull('reversed_at')->get();
        if ($entries->isEmpty()) {
            $this->info("Run {$token} has nothing left to reverse.");

            return self::SUCCESS;
        }
        $apply = (bool) $this->option('apply');
        $this->info(($apply ? 'REVERSING' : 'DRY RUN - would reverse') . " run {$token}: {$entries->count()} rows" . ($apply ? '' : ' (pass --apply to write)'));
        if (! $apply) {
            return self::SUCCESS;
        }
        DB::transaction(function () use ($entries) {
            foreach ($entries as $e) {
                $before = json_decode($e->before, true) ?: [];
                DB::table('calendar_event_feedback')->where('id', $e->feedback_id)->update($before);
                DB::table('viewing_feedback_migration_log')->where('id', $e->id)->update(['reversed_at' => now()]);
            }
        });
        $this->info('Reversed.');

        return self::SUCCESS;
    }
}
