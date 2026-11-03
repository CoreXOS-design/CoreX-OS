<?php

declare(strict_types=1);

namespace App\Console\Commands\Property;

use App\Models\Property;
use App\Services\Prospecting\CaptureClaimService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Own stock matched to Market Intelligence counts as claimed (.ai/specs/property-identity.md §16.5; Johan's 29 Oct ruling).
 *
 *   php artisan property-identity:claim-joined --dry-run     # the plan, nothing written (the default)
 *   php artisan property-identity:claim-joined --apply       # recognise + claim + own-stock flag for every joined own listing
 *
 * Back-fills what the resolver now does going forward: for every own listing that sits on a property file (properties.tracked_property_id)
 * the file's Market Intelligence adverts are recognised as ours, claimed for the listing's agent, and the work record's own-stock flag is
 * turned on. Idempotent (a re-run only does what is still undone). The test agency is left out. Refuses outside QA / local / testing.
 */
final class ClaimJoinedStock extends Command
{
    protected $signature = 'property-identity:claim-joined
        {--dry-run : Plan only (the default)}
        {--apply : Write}
        {--agency= : One agency id}
        {--exclude-agency=20 : Comma-separated agency ids to leave out (test / demo agencies)}
        {--journal-dir= : Where the journal is written (default storage/app/reports)}';

    protected $description = 'Claim the Market Intelligence adverts of every own listing joined to a property file (dry run first)';

    private const ALLOWED_ENVIRONMENTS = ['local', 'testing', 'qa'];

    public function handle(CaptureClaimService $claims): int
    {
        if (! app()->environment(self::ALLOWED_ENVIRONMENTS)) {
            $this->error('This only runs on QA / local / testing (APP_ENV is "' . app()->environment() . '").');

            return self::FAILURE;
        }
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Choose --dry-run or --apply, not both.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $exclude = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('exclude-agency')))));

        $stamp = now()->format('Ymd-His');
        $dir = (string) ($this->option('journal-dir') ?: storage_path('app/reports'));
        File::ensureDirectoryExists($dir);
        $journal = $apply ? fopen($dir . "/property-identity-claim-joined-journal-{$stamp}.jsonl", 'ab') : null;

        $out = ['properties_joined' => 0, 'not_own_stock' => 0, 'file_gone' => 0, 'no_agent' => 0, 'with_mic_adverts' => 0, 'adverts_recognised' => 0, 'adverts_claimed' => 0,
            'held_by_other_agent' => 0, 'already_claimed' => 0, 'own_stock_flag_on' => 0, 'road_to_sold' => [], 'samples' => []];
        $started = now();

        $q = DB::table('properties')->whereNull('deleted_at')->whereNotNull('tracked_property_id')->orderBy('id');
        if ($this->option('agency')) {
            $q->where('agency_id', (int) $this->option('agency'));
        } elseif ($exclude) {
            $q->whereNotIn('agency_id', $exclude);
        }

        $q->select(['id', 'agency_id', 'agent_id', 'tracked_property_id', 'status'])->chunkById(200, function ($props) use (&$out, $apply, $claims, $journal) {
            foreach ($props as $p) {
                $out['properties_joined']++;
                if (in_array((string) $p->status, Property::NOT_OWN_STOCK_STATUSES, true)) {
                    $out['not_own_stock']++;
                    continue;
                }
                $file = DB::table('tracked_properties')->where('id', $p->tracked_property_id)->where('agency_id', $p->agency_id)->first(['status']);
                if (! $file || $file->status === 'duplicate') {
                    $out['file_gone']++;
                    continue;
                }
                if (! $p->agent_id) {
                    $out['no_agent']++;
                }
                $adverts = DB::table('prospecting_listings')->where('agency_id', $p->agency_id)->where('tracked_property_id', $p->tracked_property_id)->whereNull('deleted_at')->get(['id', 'matched_property_id']);
                $unmatched = $adverts->whereNull('matched_property_id')->pluck('id')->all();
                if ($adverts->isNotEmpty()) {
                    $out['with_mic_adverts']++;
                }
                $out['adverts_recognised'] += count($unmatched);

                if (! $apply) {
                    $ids = $adverts->pluck('id')->all();
                    $mine = $p->agent_id && $ids ? DB::table('prospecting_claims')->whereIn('prospecting_listing_id', $ids)->where('user_id', $p->agent_id)->where('is_active', 1)->whereNull('released_at')->whereNull('deleted_at')->pluck('prospecting_listing_id')->all() : [];
                    $other = $ids ? DB::table('prospecting_claims')->whereIn('prospecting_listing_id', $ids)->where('user_id', '<>', (int) $p->agent_id)->where('is_active', 1)->whereNull('released_at')->whereNull('deleted_at')->exists() : false;
                    if ($other) {
                        $out['held_by_other_agent']++;
                    } elseif ($p->agent_id && $ids) {
                        $todo = count(array_diff($ids, $mine));
                        $out['adverts_claimed'] += $todo;
                        $todo === 0 ? $out['already_claimed']++ : null;
                    }
                    $out['own_stock_flag_on']++;
                    continue;
                }

                $beforeClaims = DB::table('prospecting_claims')->where('property_id', $p->id)->pluck('id')->all();
                $r = $claims->claimFromJoin((int) $p->id);
                $newClaims = array_values(array_diff(DB::table('prospecting_claims')->where('property_id', $p->id)->pluck('id')->all(), $beforeClaims));
                $out['adverts_claimed'] += count($newClaims);
                $out['held_by_other_agent'] += $r['outcome'] === CaptureClaimService::OUTCOME_HELD_BY_OTHER ? 1 : 0;
                $out['already_claimed'] += $r['outcome'] === CaptureClaimService::OUTCOME_ALREADY ? 1 : 0;
                $out['own_stock_flag_on'] += $r['own_stock'] ? 1 : 0;
                if ($r['road_to_sold'] !== null) {
                    $out['road_to_sold'][$r['road_to_sold']] = ($out['road_to_sold'][$r['road_to_sold']] ?? 0) + 1;
                }
                if ($journal) {
                    fwrite($journal, json_encode(['property' => (int) $p->id, 'file' => (int) $p->tracked_property_id, 'matched_listing_ids' => $unmatched, 'new_claim_ids' => $newClaims, 'outcome' => $r['outcome'], 'road_to_sold' => $r['road_to_sold']]) . "\n");
                }
                if (count($out['samples']) < 10 && $newClaims) {
                    $out['samples'][] = ['property' => (int) $p->id, 'file' => (int) $p->tracked_property_id, 'agent' => (int) $p->agent_id, 'claims' => count($newClaims), 'outcome' => $r['outcome']];
                }
            }
        });
        if ($journal) {
            fclose($journal);
        }

        $mode = $apply ? 'apply' : 'dry-run';
        $md = "# property-identity:claim-joined - {$mode} (" . now()->toDateTimeString() . ', env ' . app()->environment() . ")\n\n"
            . ($apply ? "Written to the database.\n\n" : "Nothing was written.\n\n") . "```json\n" . json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n```\n";
        $file = $dir . "/property-identity-claim-joined-{$mode}-{$stamp}.md";
        File::put($file, $md);
        $this->line($md);
        $this->info("Report written to {$file}");

        return self::SUCCESS;
    }
}
