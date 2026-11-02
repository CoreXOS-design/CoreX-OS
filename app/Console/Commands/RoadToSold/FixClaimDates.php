<?php

namespace App\Console\Commands\RoadToSold;

use App\Models\ProspectingClaim;
use App\Models\RoadToSold\RtsProspect;
use App\Models\Scopes\AgencyScope;
use App\Services\RoadToSold\ClaimHoldService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Road to Sold v4 (road-to-sold.md 23.22, 2 Nov 2026): the one-off that puts the real claim date on the records the adoption brought onto the belt.
 *
 *   php artisan rts:fix-claim-dates --agency=1                      dry run: per agent, what would change
 *   php artisan rts:fix-claim-dates --agency=1 --apply              do it; writes a row journal to /mnt/HC_Volume_103099143/mic-unwind/
 *   php artisan rts:fix-claim-dates --undo=<journal.jsonl>          put every row back as the journal recorded it
 *   (--adoption-journal=<rts-adopt-claims-....jsonl> limits the run to the claims that adoption put on the belt)
 *
 * The rules (nothing else is ever touched):
 *   - only a record still AT Claimed, open, never worked (no outcome, and no event but "placed" / "moved to Claimed"), with a live claim on it;
 *   - the entry date (Since / Days) becomes the EARLIEST live claim date, when that is on an earlier day than the entry date now;
 *   - the hold becomes the agency's claim hold days in working days from that date; a hold the agent extended is never shortened;
 *   - a claim whose hold would already have run out gets the whole hold from today instead (journalled, one line per claim): nobody loses a claim to this.
 * Rows that were worked or moved are left alone. Nothing is deleted; undo puts the entry date and the holds back.
 */
class FixClaimDates extends Command
{
    protected $signature = 'rts:fix-claim-dates {--agency= : Agency id (required)} {--apply : Write the changes (default is a dry run)} {--undo= : Journal file to reverse} {--adoption-journal= : Only the claims in this adoption journal} {--journal-dir=/mnt/HC_Volume_103099143/mic-unwind}';

    protected $description = 'Road to Sold v4: real claim date as Since/Days and hold clock for claims brought onto the belt late (dry run first; journalled; reversible)';

    public function handle(ClaimHoldService $hold): int
    {
        if ($this->option('undo')) {
            return $this->undo((string) $this->option('undo'));
        }
        if (! $hold->available()) {
            $this->error('The claim hold columns are not there yet (migration not run).');

            return self::FAILURE;
        }
        $agencyId = (int) $this->option('agency');
        if ($agencyId <= 0) {
            $this->error('--agency=<id> is required.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $only = null;
        if ($this->option('adoption-journal')) {
            $file = (string) $this->option('adoption-journal');
            if (! is_file($file)) {
                $this->error("Adoption journal not found: $file");

                return self::FAILURE;
            }
            $only = [];
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $j = json_decode($line, true);
                if (is_array($j) && ! empty($j['claim_id'])) {
                    $only[(int) $j['claim_id']] = true;
                }
            }
        }

        $path = null;
        $fh = null;
        if ($apply) {
            @mkdir((string) $this->option('journal-dir'), 0775, true);
            $path = rtrim((string) $this->option('journal-dir'), '/') . '/rts-fix-claim-dates-agency' . $agencyId . '-' . now()->format('Ymd-His') . '.jsonl';
            $fh = fopen($path, 'ab');
        }

        $prospects = RtsProspect::withoutGlobalScopes()->where('agency_id', $agencyId)->where('stage', 'claimed')
            ->whereNull('closed_at')->whereNull('released_at')->whereNull('parked_at')->whereNull('deleted_at')->whereNull('last_outcome_id')->orderBy('id')->get();

        $tally = [];   // user_id => [seen, rows_changed, holds_recomputed, fresh_30, skipped_worked, skipped_not_older]
        $fresh = [];
        $beforeAfter = [];
        foreach ($prospects as $p) {
            $uid = (int) $p->user_id;
            $t = &$tally[$uid];
            $t ??= ['rows_changed' => 0, 'holds_recomputed' => 0, 'fresh_30' => 0, 'left_alone_worked' => 0, 'already_right' => 0];

            $claims = ProspectingClaim::withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)->where('rts_prospect_id', $p->id)
                ->where('is_active', true)->whereNull('released_at')->whereNull('deleted_at')->orderBy('claimed_at')->get();
            if ($only !== null) {
                $claims = $claims->filter(fn ($c) => isset($only[(int) $c->id]))->values();
            }
            if ($claims->isEmpty() || $claims->contains(fn ($c) => $c->claimed_at === null)) {
                unset($t);
                continue;
            }
            if ($this->wasWorked($p)) {
                $t['left_alone_worked']++;
                unset($t);
                continue;
            }
            $entry = Carbon::instance($claims->first()->claimed_at);
            if (! $entry->copy()->startOfDay()->lt(Carbon::parse($p->stage_entered_at)->startOfDay())) {
                $t['already_right']++;
                unset($t);
                continue;
            }

            $journal = ['prospect_id' => (int) $p->id, 'user_id' => $uid, 'entry_before' => (string) $p->getRawOriginal('stage_entered_at'), 'entry_after' => $entry->toDateTimeString(), 'claims' => []];
            foreach ($claims as $c) {
                [$until, $isFresh] = $hold->holdEnd($c, $entry);
                $extended = (int) $c->hold_extensions > 0 || $c->hold_extended_at !== null;
                $before = $c->hold_until?->toDateString();
                if ($extended && $before !== null && Carbon::parse($before)->gt($until)) {
                    $until = Carbon::parse($before);   // an extended hold is never shortened
                }
                $line = ['claim_id' => (int) $c->id, 'claimed_at' => $c->claimed_at->toDateTimeString(), 'hold_before' => $before, 'hold_after' => $until->toDateString(), 'extended' => $extended, 'fresh_30_from_today' => $isFresh && ! $extended];
                if ($line['fresh_30_from_today']) {
                    $line['note'] = 'its hold from the original claim date had already run out: given the whole hold from today, so the claim is not lost';
                    $t['fresh_30']++;
                    $fresh[] = $uid . ':' . $c->id;
                }
                if ($before !== $line['hold_after']) {
                    $t['holds_recomputed']++;
                }
                $journal['claims'][] = $line;
                if ($apply) {
                    DB::table('prospecting_claims')->where('id', $c->id)->update(['hold_until' => $line['hold_after']]);
                }
            }
            if ($apply) {
                DB::table('rts_prospects')->where('id', $p->id)->update(['stage_entered_at' => $entry->toDateTimeString()]);
                fwrite($fh, json_encode($journal) . "\n");
            }
            $beforeAfter[] = $journal;
            $t['rows_changed']++;
            unset($t);
        }
        if ($fh) {
            fclose($fh);
        }

        $this->info(($apply ? '[APPLIED] ' : '[DRY RUN] ') . "agency $agencyId: " . count($beforeAfter) . ' record(s) at Claimed ' . ($apply ? 'corrected' : 'to correct') . '.');
        foreach ($tally as $uid => $t) {
            $this->line("  agent $uid: " . json_encode($t));
        }
        if ($fresh) {
            $this->line('  fresh 30 working days from today (hold from the claim date had already ended): ' . implode(', ', $fresh) . '   (agent:claim)');
        }
        if ($apply) {
            $this->line('  journal: ' . $path . '   undo: php artisan rts:fix-claim-dates --undo=' . $path);
        } else {
            $this->line('  (nothing written - add --apply)');
        }

        return self::SUCCESS;
    }

    /** Worked = anything on the record's history besides it being placed and moved to Claimed. */
    private function wasWorked(RtsProspect $p): bool
    {
        if ((int) $p->tries > 0 || $p->last_outcome_id !== null) {
            return true;
        }

        return DB::table('rts_prospect_events')->where('prospect_id', $p->id)
            ->where(fn ($q) => $q->whereNotIn('kind', ['placed', 'stage'])->orWhere(fn ($s) => $s->where('kind', 'stage')->where('to_stage', '!=', 'claimed')))
            ->exists();
    }

    private function undo(string $file): int
    {
        if (! is_file($file)) {
            $this->error("Journal not found: $file");

            return self::FAILURE;
        }
        $n = 0;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $j = json_decode($line, true);
            if (! is_array($j) || empty($j['prospect_id'])) {
                continue;
            }
            DB::transaction(function () use ($j) {
                DB::table('rts_prospects')->where('id', $j['prospect_id'])->update(['stage_entered_at' => $j['entry_before']]);
                foreach ($j['claims'] as $c) {
                    DB::table('prospecting_claims')->where('id', $c['claim_id'])->update(['hold_until' => $c['hold_before']]);
                }
            });
            $n++;
        }
        $this->info("Undone: $n record(s) put back (entry date and holds as before).");

        return self::SUCCESS;
    }
}
