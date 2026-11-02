<?php

namespace App\Console\Commands\RoadToSold;

use App\Models\ProspectingClaim;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\RoadToSold\ClaimHoldService;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Road to Sold v4, station 2 (road-to-sold.md 23.5): the claim hold, run every day instead of waiting for somebody to re-claim.
 *
 *   1. WARN  a claim on the belt with claim_warn_days (or fewer) working days left: the agent is told once (warned_at; working the claim re-arms it).
 *   2. END   a claim whose hold has run out and nothing keeps alive (not parked, not won, no evaluation still to come): the claim is released
 *            (reason hold_ended), the belt record goes back to the pool, the agent gets a one-line alert.
 *
 * Every figure is the agency's own setting. Idempotent; safe to re-run. --dry-run changes nothing and lists what it would do.
 * QA1 has no Laravel scheduler (R11): run it by hand there, or from a cron.d entry.
 */
class ReleaseHeldClaims extends Command
{
    protected $signature = 'rts:release-held-claims {--agency= : Only this agency id} {--dry-run}';

    protected $description = 'Road to Sold v4: warn about and release claims whose claim hold has ended';

    public function handle(ClaimHoldService $hold, NotificationDispatcher $dispatcher): int
    {
        if (! $hold->available()) {
            $this->warn('The claim hold columns are not there yet (migration not run) - nothing to do.');

            return self::SUCCESS;
        }
        $dry = (bool) $this->option('dry-run');
        $warned = 0;
        $ended = 0;

        $q = ProspectingClaim::withoutGlobalScope(AgencyScope::class)->where('is_active', true)->whereNull('released_at')->whereNull('deleted_at')
            ->whereNotNull('hold_until')->when($this->option('agency'), fn ($w, $a) => $w->where('agency_id', (int) $a));

        $q->orderBy('id')->chunkById(200, function ($claims) use ($hold, $dispatcher, $dry, &$warned, &$ended) {
            foreach ($claims as $c) {
                $agencyId = (int) $c->agency_id;
                $label = $this->label($c);
                if ($hold->hasEnded($c)) {
                    $this->line(sprintf('  END   agency %d claim #%d agent #%d - %s', $agencyId, $c->id, $c->user_id, $label));
                    $ended++;
                    if (! $dry && $hold->endHold($c, 'daily job')) {
                        $this->notifyAgent($dispatcher, $c, $label, "{$label} - released", 'Not worked for the whole claim hold, so it went back to the pool. You can claim it again if it is still free.', 'info');
                    }
                    continue;
                }
                $left = $hold->daysLeft($c);
                $warnDays = SettingsService::forAgency($agencyId)->claimWarnDays();
                if ($left !== null && $left >= 0 && $left <= $warnDays && $c->warned_at === null) {
                    $this->line(sprintf('  WARN  agency %d claim #%d agent #%d - %s (%d working day(s) left)', $agencyId, $c->id, $c->user_id, $label, $left));
                    $warned++;
                    if (! $dry) {
                        $this->notifyAgent($dispatcher, $c, $label, "{$label} - hold ending", "Your hold on this property ends in {$left} working day(s). Work it to keep it, or extend it once.", 'warning');
                        $c->forceFill(['warned_at' => now()])->saveQuietly();
                    }
                }
            }
        });

        $this->info(($dry ? '[DRY-RUN] would warn ' : 'Warned ') . "$warned, " . ($dry ? 'would release ' : 'released ') . "$ended claim(s).");

        return self::SUCCESS;
    }

    private function notifyAgent(NotificationDispatcher $dispatcher, ProspectingClaim $c, string $label, string $title, string $body, string $severity): void
    {
        $agent = User::query()->withoutGlobalScopes()->find($c->user_id);
        if (! $agent) {
            return;
        }
        try {
            $dispatcher->fire($agent, 'prospecting.claim_stale_warning', $c, [
                'title' => $title, 'body' => $body, 'subject_label' => $label,
                'action_url' => route('market-intelligence.work', ['action_preset' => 'my_claims'], false),
                'severity' => $severity, 'threshold_hit_at' => now()->startOfDay(),
            ]);
        } catch (\Throwable $e) {
            $this->warn('  alert failed for claim #' . $c->id . ': ' . $e->getMessage());
        }
    }

    private function label(ProspectingClaim $c): string
    {
        $addr = DB::table('prospecting_listings')->where('id', $c->prospecting_listing_id)->value('address');

        return trim((string) $addr) !== '' ? (string) $addr : 'Your claimed property';
    }
}
