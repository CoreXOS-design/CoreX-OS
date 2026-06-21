<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Scopes\AgencyScope;
use App\Models\Scopes\BranchScope;
use App\Services\BuyerStateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * AT-72 — Buyer Pillar Build 2: backfill auto-land.
 *
 * Context: AT-72 makes a contact LAND on the Buyer Pipeline as "New" when a
 * countable wishlist (ContactMatch, isCountable() per AT-71) is created, via
 * ContactMatchObserver::created(). Wishlists created BEFORE AT-72 shipped did
 * not run that hook, so those contacts can sit with a countable wishlist but
 * NULL buyer_state — invisible on the pipeline board and every surface that
 * reads it.
 *
 * This command lands any existing contact that:
 *   - has at least one COUNTABLE wishlist (per the agency's configured bar), AND
 *   - has NO buyer_state yet (buyer_state IS NULL)
 * onto buyer_state='new', via BuyerStateService::landOnPipeline() so the
 * transition is audited in buyer_state_transitions exactly like the live hook.
 *
 * Idempotent: a contact already in ANY state (new/warm/cold/lost) is skipped —
 * re-running never resets a buyer or double-lands one. Safe to run repeatedly.
 * Scoped: --agency=N restricts to one agency; without it, runs all agencies.
 *
 * Manual operation only — NOT invoked by scripts/deploy.sh.
 *
 * Usage:
 *   php artisan buyers:autoland-pipeline                 # all agencies, write
 *   php artisan buyers:autoland-pipeline --agency=1      # one agency, write
 *   php artisan buyers:autoland-pipeline --dry-run       # report only
 *   php artisan buyers:autoland-pipeline --agency=1 --dry-run
 */
class BuyersAutolandPipelineCommand extends Command
{
    protected $signature = 'buyers:autoland-pipeline
                            {--agency= : Restrict to a single agency_id}
                            {--dry-run : Report counts without writing}';

    protected $description = 'AT-72 — land existing countable-buyer contacts with no buyer_state onto the pipeline as New (idempotent).';

    public function handle(BuyerStateService $states): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $agencyOpt = $this->option('agency');
        $agencyFilter = $agencyOpt !== null ? (int) $agencyOpt : null;

        $tag = $dryRun ? '[DRY-RUN]' : '[WRITE]';
        $scope = $agencyFilter ? "agency_id=$agencyFilter" : 'ALL agencies';
        $this->info("$tag buyers:autoland-pipeline — scope: $scope");

        if ($agencyFilter !== null && !DB::table('agencies')->where('id', $agencyFilter)->exists()) {
            $this->error("Agency id=$agencyFilter not found.");
            return self::INVALID;
        }

        // Per-agency, because the countable bar (AgencyContactSettings) is
        // per-agency. Iterate each agency that owns wishlists.
        $agencyIds = DB::table('contact_matches')
            ->whereNull('deleted_at')
            ->when($agencyFilter !== null, fn ($q) => $q->where('agency_id', $agencyFilter))
            ->whereNotNull('agency_id')
            ->distinct()
            ->pluck('agency_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        if (empty($agencyIds)) {
            $this->info('No wishlists found in scope — nothing to land.');
            return self::SUCCESS;
        }

        $totalCandidates = 0;
        $totalLanded = 0;

        foreach ($agencyIds as $agencyId) {
            // Contacts in this agency with ≥1 COUNTABLE wishlist. SoftDeletes
            // on ContactMatch excludes trashed wishlists automatically.
            $contactIds = ContactMatch::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->countable($agencyId)
                ->whereNotNull('contact_id')
                ->distinct()
                ->pluck('contact_id')
                ->map(fn ($v) => (int) $v)
                ->all();

            if (empty($contactIds)) {
                continue;
            }

            // Of those, the ones with no buyer_state yet (the auto-land
            // targets). Keep SoftDeletes (don't land a trashed contact).
            $targets = Contact::withoutGlobalScopes([AgencyScope::class, BranchScope::class])
                ->whereIn('id', $contactIds)
                ->whereNull('buyer_state')
                ->get();

            $candidates = $targets->count();
            $totalCandidates += $candidates;

            $this->line(sprintf(
                "  agency %-4d: %d countable-buyer contact(s) with NULL buyer_state",
                $agencyId, $candidates
            ));

            if ($candidates === 0 || $dryRun) {
                continue;
            }

            foreach ($targets as $contact) {
                if ($states->landOnPipeline($contact, 'auto_landed')) {
                    $totalLanded++;
                }
            }
        }

        $this->line('');
        if ($dryRun) {
            $this->warn(sprintf(
                "$tag — %d contact(s) WOULD be landed on 'new'. Re-run without --dry-run to apply.",
                $totalCandidates
            ));
            return self::SUCCESS;
        }

        $this->info(sprintf(
            "Done. %d contact(s) landed on the Buyer Pipeline as 'new' (of %d candidates).",
            $totalLanded, $totalCandidates
        ));
        $this->info('Re-running this command is safe (idempotent — a buyer already in a state is never reset or re-landed).');

        return self::SUCCESS;
    }
}
