<?php

namespace App\Console\Commands\RoadToSold;

use App\Models\ProspectingClaim;
use App\Models\RoadToSold\RtsProspect;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\RoadToSold\ClaimBeltService;
use App\Services\RoadToSold\ClaimHoldService;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\ProspectStages;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Road to Sold v4 (road-to-sold.md 23.5.2): the one-time adoption. Every active claim that is not on the belt gets its property put on it.
 *
 *   php artisan rts:adopt-claims --agency=1                    dry run: counts and the reasons nothing was done
 *   php artisan rts:adopt-claims --agency=1 --apply            do it; writes a row journal to /mnt/HC_Volume_103099143/mic-unwind/
 *   php artisan rts:adopt-claims --undo=<journal.jsonl>        put every row back as the journal recorded it
 *
 * The stage follows the claim's status: claimed -> Claimed; contacted -> Contacted only when an outcome exists, else Claimed; meeting_set ->
 * Evaluation booked; listing -> Mandate signed. THE HOLD CLOCK STARTS AT THE ADOPTION DATE for every adopted claim (conductor ruling 2 Nov
 * 2026): a claim that has been sitting for weeks, and a pitched claim that was permanent, are not released on the first run - they get the
 * whole claim hold from today. Nothing is hard-deleted: undo clears the claim columns, puts a moved prospect's stage back and archives a
 * prospect the run created (soft delete).
 */
class AdoptClaims extends Command
{
    protected $signature = 'rts:adopt-claims {--agency= : Agency id (required)} {--apply : Write the changes (default is a dry run)} {--undo= : Journal file to reverse} {--journal-dir=/mnt/HC_Volume_103099143/mic-unwind}';

    protected $description = 'Road to Sold v4: put every active MIC claim on the belt (dry run first; journalled; reversible)';

    private const STAGE_BY_STATUS = [
        ProspectingClaim::STATUS_CLAIMED     => ProspectStages::CLAIMED,
        ProspectingClaim::STATUS_CONTACTED   => ProspectStages::CLAIMED,   // Contacted only with an outcome on record (below)
        ProspectingClaim::STATUS_MEETING_SET => ProspectStages::APPOINTMENT_SET,
        ProspectingClaim::STATUS_LISTING     => ProspectStages::MANDATE_SIGNED,
    ];

    public function handle(ClaimBeltService $belt, ClaimHoldService $hold, ProspectService $prospects): int
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

        $claims = ProspectingClaim::withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)->where('is_active', true)->whereNull('released_at')
            ->whereNull('deleted_at')->whereNull('rts_prospect_id')->where('status', '!=', ProspectingClaim::STATUS_RESOLVED_OWN_STOCK)->orderBy('id')->get();

        $path = null;
        $fh = null;
        if ($apply) {
            @mkdir((string) $this->option('journal-dir'), 0775, true);
            $path = rtrim((string) $this->option('journal-dir'), '/') . '/rts-adopt-claims-agency' . $agencyId . '-' . now()->format('Ymd-His') . '.jsonl';
            $fh = fopen($path, 'ab');
        }

        $before = $this->counts($agencyId);
        $tally = ['adopted' => 0, 'prospect_created' => 0, 'prospect_moved' => 0, 'prospect_existing' => 0];
        $skips = [];
        $byStage = [];
        $seenFile = [];   // agent|file => true, so a second advert claim of the same file does not count a second prospect

        foreach ($claims as $c) {
            $reason = $this->skipReason($c, $belt);
            if ($reason !== null) {
                $skips[$reason][] = (int) $c->id;
                continue;
            }
            $trackedId = $belt->trackedPropertyOf($c);
            $existing = $prospects->find($agencyId, $trackedId, 'sales');
            $stage = $this->stageFor($c, $existing);
            $key = $c->user_id . '|' . $trackedId;

            $byStage[$stage] = ($byStage[$stage] ?? 0) + 1;
            if (! $apply) {
                $tally['adopted']++;
                $tally[$existing && $existing->released_at === null ? 'prospect_existing' : 'prospect_created']++;
                continue;
            }

            $journal = [
                'claim_id' => (int) $c->id, 'claim_before' => ['rts_prospect_id' => null, 'hold_until' => null, 'status' => $c->status],
                'prospect_id' => $existing?->id, 'prospect_before' => $existing ? $existing->getRawOriginal() : null,
            ];
            $p = $belt->placeClaim($c, $stage, 'claimed_mic', 'Claimed in Market Intelligence on ' . ($c->claimed_at?->format('j M Y') ?? now()->format('j M Y')));
            if (! $p) {
                $skips['could not be placed'][] = (int) $c->id;
                continue;
            }
            $journal['prospect_id'] = (int) $p->id;
            $journal['prospect_created'] = $existing === null;
            $journal['hold_until'] = $c->fresh()->hold_until?->toDateString();
            fwrite($fh, json_encode($journal) . "\n");
            $tally['adopted']++;
            if (! isset($seenFile[$key])) {
                $seenFile[$key] = true;
                $tally[$existing === null ? 'prospect_created' : ($existing->stage !== $p->stage ? 'prospect_moved' : 'prospect_existing')]++;
            }
        }
        if ($fh) {
            fclose($fh);
        }

        $after = $apply ? $this->counts($agencyId) : null;
        $this->info(($apply ? '[APPLIED] ' : '[DRY RUN] ') . "agency $agencyId: " . count($claims) . ' active claim(s) not on the belt.');
        $this->line('  claims ' . ($apply ? 'adopted' : 'to adopt') . ': ' . $tally['adopted'] . '   by stage: ' . json_encode($byStage));
        $this->line('  prospects: ' . ($apply ? 'created ' : 'would be created ') . $tally['prospect_created'] . ', existing ' . $tally['prospect_existing'] . ($apply ? ', moved forward ' . $tally['prospect_moved'] : ''));
        foreach ($skips as $why => $ids) {
            $this->line('  not adopted - ' . $why . ': ' . count($ids) . ' (claims ' . implode(',', array_slice($ids, 0, 40)) . (count($ids) > 40 ? ',...' : '') . ')');
        }
        $this->line('  before: ' . json_encode($before));
        if ($after) {
            $this->line('  after:  ' . json_encode($after));
            $this->line('  journal: ' . $path . '   undo: php artisan rts:adopt-claims --undo=' . $path);
        } else {
            $this->line('  (nothing written - add --apply)');
        }

        return self::SUCCESS;
    }

    private function skipReason(ProspectingClaim $c, ClaimBeltService $belt): ?string
    {
        $trackedId = $belt->trackedPropertyOf($c);
        if ($trackedId === null) {
            return 'the advert has no property file yet';
        }
        $agent = User::query()->withoutGlobalScopes()->where('agency_id', $c->agency_id)->find($c->user_id);
        if (! $agent || ! $agent->is_active) {
            return 'the agent is no longer active';
        }
        if ($agent->is_assistant) {
            return 'claimed by an assistant';
        }
        $p = app(ProspectService::class)->find((int) $c->agency_id, $trackedId, 'sales');
        if ($p && $p->closed_at !== null) {
            return 'the belt record for the property is closed';
        }
        if ($p && $p->released_at === null && (int) $p->user_id !== (int) $c->user_id) {
            return "the property is on another agent's belt";
        }

        return null;
    }

    private function stageFor(ProspectingClaim $c, ?RtsProspect $existing): string
    {
        $stage = self::STAGE_BY_STATUS[$c->status] ?? ProspectStages::CLAIMED;
        if ($c->status === ProspectingClaim::STATUS_CONTACTED && $existing && $existing->last_outcome_id) {
            $stage = ProspectStages::CONTACTED;
        }

        return $stage;
    }

    /** @return array<string,int> */
    private function counts(int $agencyId): array
    {
        $claims = DB::table('prospecting_claims')->where('agency_id', $agencyId)->where('is_active', 1)->whereNull('released_at')->whereNull('deleted_at');

        return [
            'active_claims' => (clone $claims)->count(),
            'claims_on_belt' => (clone $claims)->whereNotNull('rts_prospect_id')->count(),
            'open_prospects' => DB::table('rts_prospects')->where('agency_id', $agencyId)->whereNull('deleted_at')->whereNull('closed_at')->whereNull('released_at')->count(),
        ];
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
            if (! is_array($j)) {
                continue;
            }
            DB::transaction(function () use ($j) {
                DB::table('prospecting_claims')->where('id', $j['claim_id'])->update([
                    'rts_prospect_id' => null, 'hold_until' => null, 'hold_extended_at' => null, 'hold_extensions' => 0, 'status' => $j['claim_before']['status'] ?? DB::raw('status'),
                ]);
                if (! empty($j['prospect_id'])) {
                    if (! empty($j['prospect_created'])) {
                        DB::table('rts_prospects')->where('id', $j['prospect_id'])->update(['deleted_at' => now()]);   // soft delete: archived, never removed
                    } elseif (! empty($j['prospect_before'])) {
                        // the whole row as it was before the run (a moved stage, or a released record the run brought back)
                        $row = $j['prospect_before'];
                        unset($row['id']);
                        DB::table('rts_prospects')->where('id', $j['prospect_id'])->update($row);
                    }
                }
            });
            $n++;
        }
        $this->info("Undone: $n claim row(s) put back; prospects the run created are archived.");

        return self::SUCCESS;
    }
}
