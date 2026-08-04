<?php

namespace App\Console\Commands;

use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Services\PrivateProperty\PrivatePropertySyndicationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * AT-369 remediation.
 *
 * Before this fix, PrivatePropertyListingMapper::map() silently auto-derived
 * SoleMandateExclusiveDays from listed_date/expiry_date on every sole-mandate
 * Sale submit — no agent ever opted in. This command finds every property PP
 * actually granted exclusivity to under that old behaviour and, for the ones
 * still inside the exclusive window, resubmits with the field cleared so PP
 * releases it.
 *
 * Ground truth for "PP granted exclusivity" is `pp_delay_until` — it is
 * written ONLY from PP's own DelayListingOnOtherWebsitesUntil response
 * (PrivatePropertySyndicationService::submitListing), never guessed or
 * recomputed locally. A property with it set definitely had
 * SoleMandateExclusiveDays >= 1 accepted by PP at some point.
 *
 * --dry-run is the DEFAULT — this command only reports until --live is passed
 * explicitly. No hard deletes anywhere: the only write is
 * `pp_exclusive_days` => null followed by the ordinary submitListing() update
 * path, the same one "Refresh" already uses.
 */
class PpRemediateLegacyExclusiveDays extends Command
{
    protected $signature = 'pp:remediate-legacy-exclusivity
        {--live : Actually clear pp_exclusive_days and resubmit (DEFAULT is dry-run — report only, change nothing)}
        {--force : Skip the confirmation prompt when --live is passed}
        {--agency= : Restrict to one agency ID}';

    protected $description = 'AT-369 — find properties the pre-fix auto-exclusivity bug requested on PP, and release any still active';

    public function handle(PrivatePropertySyndicationService $service): int
    {
        $live     = (bool) $this->option('live');
        $agencyId = $this->option('agency') !== null ? (int) $this->option('agency') : null;

        $query = Property::withoutGlobalScope(AgencyScope::class)
            ->whereNotNull('pp_delay_until');

        if ($agencyId !== null) {
            $query->where('agency_id', $agencyId);
        }

        $candidates = $query->orderBy('id')->get();

        if ($candidates->isEmpty()) {
            $this->info('No properties found with a PP-granted exclusivity window (pp_delay_until set). Nothing to do.');
            return self::SUCCESS;
        }

        $this->info(($live ? 'LIVE RUN' : 'DRY RUN') . " — {$candidates->count()} propert" . ($candidates->count() === 1 ? 'y' : 'ies') . ' with pp_delay_until set:');
        $this->line('');

        $toProcess     = [];
        $recentSkips   = [];
        $alreadyLapsed = [];

        foreach ($candidates as $property) {
            $stillActive      = $property->isPpExclusiveActive();
            $mandateSaleMatch = in_array(strtolower($property->mandate_type ?? ''), ['sole', 'sole mandate'], true)
                && ($property->listing_type ?? 'sale') === 'sale';

            $row = [
                'id'             => $property->id,
                'pp_ref'         => $property->pp_ref ?? '(none)',
                'pp_delay_until' => $property->pp_delay_until->toDateTimeString(),
                'mandate_type'   => $property->mandate_type ?? '(none)',
                'listing_type'   => $property->listing_type ?? 'sale',
                'bug_condition'  => $mandateSaleMatch ? 'yes' : 'NO — mandate/type changed since original submit, investigate',
            ];

            if (!$stillActive) {
                $row['action'] = 'already lapsed — no action needed';
                $alreadyLapsed[] = $row;
                continue;
            }

            // Rev 4.6 p20: reducing SoleMandateExclusiveDays below 1 within 24h
            // of the listing's PP creation is a PP error. CoreX has no direct
            // record of "when PP created this listing" — pp_activated_at (set
            // ONCE, never overwritten — PrivatePropertySyndicationService) is
            // the closest stable proxy; pp_last_submitted_at is the fallback
            // for the rare row that activated without that column populating.
            $ppCreatedProxy    = $property->pp_activated_at ?? $property->pp_last_submitted_at;
            $withinPp24hWindow = $ppCreatedProxy && $ppCreatedProxy->diffInHours(now()) < 24;

            if ($withinPp24hWindow) {
                $row['action'] = "SKIPPED — inside PP's 24h no-reduction window (created {$ppCreatedProxy->diffForHumans()}) — handle manually once past 24h";
                $recentSkips[] = $row;
                continue;
            }

            $row['action'] = $live ? 'clearing pp_exclusive_days and resubmitting…' : 'WOULD clear pp_exclusive_days and resubmit';
            $toProcess[] = ['property' => $property, 'row' => $row];
        }

        $this->table(
            ['id', 'pp_ref', 'pp_delay_until', 'mandate_type', 'listing_type', 'bug condition matches', 'action'],
            collect($toProcess)->pluck('row')->concat($recentSkips)->concat($alreadyLapsed)
                ->map(fn ($r) => [$r['id'], $r['pp_ref'], $r['pp_delay_until'], $r['mandate_type'], $r['listing_type'], $r['bug_condition'], $r['action']])
        );

        $this->line('');
        $this->info('Summary: ' . count($toProcess) . ' to process, ' . count($recentSkips) . " skipped (inside PP's 24h window), " . count($alreadyLapsed) . ' already lapsed (no action needed).');

        if (!$live) {
            $this->line('');
            $this->comment('Dry run — nothing changed. Re-run with --live to actually clear and resubmit.');
            return self::SUCCESS;
        }

        if (empty($toProcess)) {
            $this->info('Nothing to process on this live run (all candidates were skipped or already lapsed).');
            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm('About to clear pp_exclusive_days and resubmit ' . count($toProcess) . ' propert' . (count($toProcess) === 1 ? 'y' : 'ies') . ' to the LIVE Private Property endpoint. Continue?')) {
            $this->comment('Aborted — nothing changed.');
            return self::SUCCESS;
        }

        $ok = 0;
        $fail = 0;
        foreach ($toProcess as $item) {
            $property = $item['property'];
            try {
                DB::transaction(function () use ($property, $service) {
                    $property->update(['pp_exclusive_days' => null]);

                    $result = $service->submitListing($property->fresh());

                    if (!($result['success'] ?? false)) {
                        // Roll back the local clear too — never leave CoreX
                        // thinking exclusivity is cleared when PP never
                        // actually received (or rejected) the resubmit.
                        throw new \RuntimeException($result['message'] ?? 'Resubmit failed with no message');
                    }
                });
                $this->info("  #{$property->id} — resubmitted, exclusivity cleared.");
                $ok++;
            } catch (\Throwable $e) {
                $this->error("  #{$property->id} — FAILED, rolled back: {$e->getMessage()}");
                $fail++;
            }
        }

        $this->line('');
        $this->info("DONE — {$ok} succeeded, {$fail} failed. Full PP request/response for every resubmit is in storage/logs/private_property.log.");

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }
}
