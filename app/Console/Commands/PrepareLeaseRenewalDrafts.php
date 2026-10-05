<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\Lease;
use App\Models\LeaseSetting;
use App\Services\Rentals\RenewalDraftEligibilityService;
use App\Services\Rentals\RenewalDraftService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * .ai/specs/rental-renewals.md §5 — Johan's ruling (AT-444 follow-up 3,
 * 2026-10-05): when a lease enters the agency's own reminder window
 * (LeaseSetting::expiryNoticeWindowDaysFor() — already live, repointed by
 * cc1/AT-439, confirmed not re-touched here), CoreX drafts the renewal
 * itself whenever it has enough information to, rather than waiting for
 * the agent to open the renewal screen. It never sends anything — the
 * agent still edits terms and explicitly sends, exactly as §5/§7 already
 * require for every path.
 *
 * Same shape as CheckLeaseExpiry: iterates agencies EXPLICITLY (console
 * commands run with no authenticated user, so Lease's own AgencyScope is a
 * no-op here regardless — relying on that implicit absence to silently
 * span every agency in one query is exactly the mistake CheckLeaseExpiry's
 * own docblock already names), and only ever creates a NEW lease/flow row —
 * it never writes `status` on the lease being renewed.
 *
 * Idempotent: Lease::hasPendingRenewalDraft() is the skip condition, so a
 * second run the same day (or the next) does nothing for a lease that
 * already has an open draft. Also skips a lease with an outcome already
 * on file — notice (either party) or month-to-month — since none of those
 * leases are renewing.
 */
class PrepareLeaseRenewalDrafts extends Command
{
    protected $signature = 'rentals:prepare-renewal-drafts {--lease= : Restrict to one lease id (QA1 verification only — never run unscoped against real data)}';

    protected $description = 'Auto-draft a renewal (copy-forward or agency-template) for leases entering the reminder window, where CoreX has enough information to';

    public function handle(): int
    {
        $this->info('Preparing renewal drafts...');

        $drafted = 0;
        $skippedOutcome = 0;
        $skippedAlreadyDrafted = 0;
        $skippedNoAgent = 0;
        $insufficientInfo = 0;

        $leaseFilter = $this->option('lease');

        foreach (Agency::all() as $agency) {
            $windowDays = LeaseSetting::expiryNoticeWindowDaysFor($agency->id);
            $today = now()->startOfDay();

            $query = Lease::withoutGlobalScopes()
                ->where('agency_id', $agency->id)
                ->where('status', Lease::STATUS_ACTIVE)
                ->whereNotNull('end_date')
                ->where('end_date', '>=', $today->toDateString())
                ->where('end_date', '<=', $today->copy()->addDays($windowDays)->toDateString());

            if ($leaseFilter) {
                $query->where('id', $leaseFilter);
            }

            foreach ($query->get() as $lease) {
                if ($lease->hasActiveNotice() || $lease->is_month_to_month) {
                    $skippedOutcome++;
                    continue;
                }

                if ($lease->hasPendingRenewalDraft()) {
                    $skippedAlreadyDrafted++;
                    continue;
                }

                $agent = $lease->createdByUser;
                if (!$agent) {
                    $skippedNoAgent++;
                    continue;
                }

                try {
                    $decision = app(RenewalDraftEligibilityService::class)->decide($lease, $agent);

                    if ($decision['outcome'] === 'copy_forward') {
                        app(RenewalDraftService::class)->copyForward($lease, $decision['terms'], $agent);
                        $drafted++;
                        $this->line("  DRAFTED (copy-forward): lease #{$lease->id}");
                    } elseif ($decision['outcome'] === 'draft_from_template') {
                        app(RenewalDraftService::class)->draftFromTemplate($lease, $decision['template'], $decision['terms'], $agent);
                        $drafted++;
                        $this->line("  DRAFTED (template \"{$decision['template']->name}\"): lease #{$lease->id}");
                    } else {
                        // Insufficient info — nothing created. The Command
                        // Centre's needs-action row computes the same
                        // decision live and names what's missing there;
                        // nothing to persist here.
                        $insufficientInfo++;
                        $this->line("  NOT ENOUGH INFO: lease #{$lease->id} — " . implode(', ', $decision['missing']));
                    }
                } catch (ValidationException|\Throwable $e) {
                    Log::error('Failed to auto-draft lease renewal', [
                        'lease_id' => $lease->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->info(
            "Done. Drafted: {$drafted}, not enough info: {$insufficientInfo}, "
            . "already had a draft: {$skippedAlreadyDrafted}, outcome already on file: {$skippedOutcome}, "
            . "no agent to draft as: {$skippedNoAgent}."
        );

        return 0;
    }
}
