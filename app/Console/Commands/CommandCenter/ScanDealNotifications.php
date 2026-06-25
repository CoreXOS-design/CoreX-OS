<?php

namespace App\Console\Commands\CommandCenter;

use App\Models\Deal;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\CommandCenter\NotificationPreferenceService;
use App\Support\Notifications\AgeFormatter;
use Illuminate\Console\Command;

class ScanDealNotifications extends Command
{
    protected $signature = 'notifications:scan-deals';
    protected $description = 'Scan deals for stalled-stage / missing-docs notifications.';

    public function handle(NotificationPreferenceService $prefs, NotificationDispatcher $dispatcher): int
    {
        Deal::query()
            ->whereNull('registration_date')
            ->where(function ($q) {
                $q->whereNull('accepted_status')
                  ->orWhereNotIn('accepted_status', ['D', 'R']);
            })
            ->chunkById(200, function ($deals) use ($prefs, $dispatcher) {
                foreach ($deals as $deal) {
                    $agentIds = [];
                    try {
                        $agentIds = $deal->users()->pluck('users.id')->all();
                    } catch (\Throwable $e) {}
                    if (empty($agentIds)) continue;

                    foreach ($agentIds as $uid) {
                        $agent = User::find($uid);
                        if (! $agent) continue;

                        // Tenant guard. This command runs in a console context where
                        // AgencyScope is inert (no Auth::user()), so the query above
                        // sweeps EVERY agency's deals. Only notify an agent about a
                        // deal that belongs to their own agency. NULL agency_id is an
                        // orphan and never notifies (see .ai/specs/multi-tenancy.md).
                        $agencyId = $this->agencyIdFor($agent);
                        if (! $agencyId || (int) ($deal->agency_id ?? 0) !== $agencyId) continue;

                        $stageKey = empty($deal->accepted_status) ? 'deal.stalled_offer'
                            : ($deal->accepted_status === 'G' ? 'deal.stalled_bond' : 'deal.stalled_conveyancing');

                        // Human stage label — never leak the raw status code ('G' etc.) into copy.
                        $stageLabel = [
                            'deal.stalled_offer'        => 'offer',
                            'deal.stalled_bond'         => 'bond approval',
                            'deal.stalled_conveyancing' => 'conveyancing',
                        ][$stageKey];

                        $eff = $prefs->effective($agent, $stageKey);
                        if ($eff && $eff['enabled'] && $eff['threshold']) {
                            $stamp = $deal->updated_at ?? $deal->created_at;
                            if (! $stamp) continue;
                            $ageHours = AgeFormatter::wholeHours($stamp);
                            $thresholdHours = $eff['event_type']->threshold_unit === 'days'
                                ? ((int) $eff['threshold']) * 24
                                : (int) $eff['threshold'];
                            if ($ageHours >= $thresholdHours) {
                                $label = $deal->title ?? ("Deal #" . $deal->id);
                                $age   = AgeFormatter::duration($stamp);
                                $dispatcher->fire($agent, $stageKey, $deal, [
                                    'title' => "$label — no progress",
                                    'body'  => $age
                                        ? "No update in {$age} at {$stageLabel} stage."
                                        : "Awaiting progress at {$stageLabel} stage.",
                                    'subject_label' => $label,
                                    'action_url' => "/deals/{$deal->id}",
                                    'severity' => 'warning',
                                    'threshold_hit_at' => now()->startOfHour(),
                                ]);
                            }
                        }
                    }
                }
            });

        return self::SUCCESS;
    }

    /**
     * Resolve an agent's effective agency without touching the session
     * (this runs in a scheduler/console context where no session is bound).
     * Mirrors User::effectiveAgencyId() minus the owner switcher override,
     * which never applies during a batch scan.
     */
    private function agencyIdFor(User $agent): ?int
    {
        if ($agent->agency_id) {
            return (int) $agent->agency_id;
        }
        if ($agent->branch_id) {
            $branch = \App\Models\Branch::find($agent->branch_id);
            if ($branch?->agency_id) {
                return (int) $branch->agency_id;
            }
        }
        return null;
    }
}
