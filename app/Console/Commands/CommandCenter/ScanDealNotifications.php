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

                        // AT-259 — deal.documents_missing: the deal lacks a document of every type its
                        // agency requires (DealStageDocumentRule). Skips agencies with NO rules configured
                        // (no required set → no alert, so it never nags where it wasn't set up). Presence is
                        // keyed on the DR2 twin (documents.deal_id = deals_v2.id). DEFAULT OFF (opt-in).
                        $effDocs = $prefs->effective($agent, 'deal.documents_missing');
                        if ($effDocs && $effDocs['enabled'] && $effDocs['threshold'] && $deal->deal_v2_id) {
                            $requiredTypeIds = \App\Models\DealV2\DealStageDocumentRule::where('agency_id', $deal->agency_id)
                                ->distinct()->pluck('document_type_id')->filter()->map(fn ($i) => (int) $i)->all();
                            if (! empty($requiredTypeIds)) {
                                $presentTypeIds = \DB::table('documents')
                                    ->where('deal_id', $deal->deal_v2_id)->whereNull('deleted_at')
                                    ->distinct()->pluck('document_type_id')->filter()->map(fn ($i) => (int) $i)->all();
                                $missing = array_values(array_diff($requiredTypeIds, $presentTypeIds));
                                $stamp   = $deal->created_at;
                                if (! empty($missing) && $stamp) {
                                    $thHours = $effDocs['event_type']->threshold_unit === 'days'
                                        ? ((int) $effDocs['threshold']) * 24 : (int) $effDocs['threshold'];
                                    if (AgeFormatter::wholeHours($stamp) >= $thHours) {
                                        $label = $deal->title ?? ('Deal #' . $deal->id);
                                        $n     = count($missing);
                                        $dispatcher->fire($agent, 'deal.documents_missing', $deal, [
                                            'title' => "{$label} — documents missing",
                                            'body'  => "{$n} required document" . ($n === 1 ? '' : 's') . ' not yet on file.',
                                            'subject_label' => $label,
                                            'action_url' => "/deals/{$deal->id}",
                                            'severity' => 'warning',
                                            'threshold_hit_at' => $stamp->copy()->startOfDay(),
                                        ]);
                                    }
                                }
                            }
                        }

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

        // AT-259 — deal.commission_unpaid: a REGISTERED deal whose commission is still unpaid past the
        // threshold (days since registration). Separate query — the stalled scan above excludes registered
        // deals, and "commission overdue" is meaningless before registration. DEFAULT OFF (opt-in).
        Deal::query()
            ->whereNotNull('registration_date')
            ->where(function ($q) {
                $q->whereNull('commission_status')->orWhere('commission_status', '!=', 'Paid');
            })
            ->chunkById(200, function ($deals) use ($prefs, $dispatcher) {
                foreach ($deals as $deal) {
                    $agentIds = [];
                    try { $agentIds = $deal->users()->pluck('users.id')->all(); } catch (\Throwable $e) {}
                    foreach ($agentIds as $uid) {
                        $agent = User::find($uid);
                        if (! $agent) continue;
                        $agencyId = $this->agencyIdFor($agent);
                        if (! $agencyId || (int) ($deal->agency_id ?? 0) !== $agencyId) continue;

                        $eff = $prefs->effective($agent, 'deal.commission_unpaid');
                        if (! $eff || ! $eff['enabled'] || ! $eff['threshold']) continue;
                        $reg = $deal->registration_date ? \Illuminate\Support\Carbon::parse($deal->registration_date) : null;
                        if (! $reg) continue;
                        $daysSince = (int) floor($reg->diffInDays(now()));
                        if ($daysSince >= (int) $eff['threshold']) {
                            $label = $deal->title ?? ('Deal #' . $deal->id);
                            $dispatcher->fire($agent, 'deal.commission_unpaid', $deal, [
                                'title' => "{$label} — commission unpaid",
                                'body'  => "Commission still unpaid {$daysSince} day" . ($daysSince === 1 ? '' : 's') . ' after registration.',
                                'subject_label' => $label,
                                'action_url' => "/deals/{$deal->id}",
                                'severity' => 'warning',
                                'threshold_hit_at' => $reg->copy()->startOfDay(),
                            ]);
                        }
                    }
                }
            });

        // AT-259 — deal.milestone_due: a deal-milestone CalendarEvent (event_type='deal') coming within the
        // threshold window. Fires to the event owner (user_id). DEFAULT OFF (opt-in).
        \App\Models\CalendarEvent::withoutGlobalScopes()->query()
            ->where('event_type', 'deal')
            ->where('status', 'pending')
            ->whereNotNull('user_id')
            ->whereNotNull('event_date')
            ->whereDate('event_date', '>=', now()->toDateString())
            ->chunkById(200, function ($events) use ($prefs, $dispatcher) {
                foreach ($events as $event) {
                    $agent = User::find($event->user_id);
                    if (! $agent) continue;
                    $agencyId = $this->agencyIdFor($agent);
                    if (! $agencyId || (int) ($event->agency_id ?? 0) !== $agencyId) continue;

                    $eff = $prefs->effective($agent, 'deal.milestone_due');
                    if (! $eff || ! $eff['enabled'] || ! $eff['threshold']) continue;
                    $due     = \Illuminate\Support\Carbon::parse($event->event_date);
                    $hoursOut = now()->diffInHours($due, false); // Carbon 3: positive for future (verified)
                    $thHours = $eff['event_type']->threshold_unit === 'days'
                        ? ((int) $eff['threshold']) * 24 : (int) $eff['threshold'];
                    if ($hoursOut >= 0 && $hoursOut <= $thHours) {
                        $label = $event->title ?: 'Deal milestone';
                        $when  = $due->copy()->startOfDay()->lte(now()->startOfDay()) ? 'today' : $due->diffForHumans(now(), ['parts' => 1]);
                        $dispatcher->fire($agent, 'deal.milestone_due', $event, [
                            'title' => "{$label} — due {$when}",
                            'body'  => 'Milestone due on ' . $due->format('Y-m-d') . '.',
                            'subject_label' => $label,
                            'action_url' => $event->source_id ? "/deals/{$event->source_id}" : '/calendar',
                            'severity' => $hoursOut <= 24 ? 'overdue' : 'warning',
                            'threshold_hit_at' => $due->copy()->startOfDay(),
                        ]);
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
