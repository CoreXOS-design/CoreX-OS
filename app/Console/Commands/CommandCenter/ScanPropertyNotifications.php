<?php

namespace App\Console\Commands\CommandCenter;

use App\Models\Property;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\CommandCenter\NotificationPreferenceService;
use App\Support\Notifications\AgeFormatter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ScanPropertyNotifications extends Command
{
    protected $signature = 'notifications:scan-properties';
    protected $description = 'Scan properties and emit pillar notifications based on user preferences.';

    public function handle(NotificationPreferenceService $prefs, NotificationDispatcher $dispatcher): int
    {
        $hasDocs = Schema::hasTable('property_documents');

        Property::query()
            ->whereNotNull('agent_id')
            ->where(function ($q) {
                // BUILD_STANDARD §6 — single source of truth for "not live".
                // The hand-written ['sold','withdrawn','expired'] kept pushing
                // alerts about archived/cancelled stock, and would have nagged
                // agents about listings another agency had already sold (AT-350).
                $q->whereNull('status')->orWhereNotIn('status', Property::OFF_MARKET_STATUSES);
            })
            ->chunkById(200, function ($props) use ($prefs, $dispatcher, $hasDocs) {
                foreach ($props as $property) {
                    $agent = User::find($property->agent_id);
                    if (! $agent) continue;

                    // Tenant guard. This command runs in a console context where
                    // AgencyScope is inert (no Auth::user()), so the query above
                    // sweeps EVERY agency. Without this check an agent assigned to
                    // a property under a different agency (e.g. a stale assignment
                    // from before an agency move) would be pushed alerts for a
                    // listing they cannot even see in-app. Strict match: the
                    // property must carry the agent's own agency_id. NULL agency_id
                    // is an orphan and never notifies (see .ai/specs/multi-tenancy.md).
                    $agencyId = $this->agencyIdFor($agent);
                    if (! $agencyId || (int) ($property->agency_id ?? 0) !== $agencyId) continue;

                    // property.documents_missing — never for compliant stock.
                    // P24 go-live imports are marked compliant and legitimately
                    // carry no uploaded documents; "documents missing" alerts for
                    // them are pure noise (hundreds per import). Mandate-expiry
                    // below still fires — a compliant mandate can still expire.
                    $eff = $property->compliance_snapshot_at === null
                        ? $prefs->effective($agent, 'property.documents_missing')
                        : null;
                    if ($eff && $eff['enabled'] && $eff['threshold']) {
                        $ageHours = AgeFormatter::wholeHours($property->created_at);
                        // created_at is the dedup key below, so it must exist. wholeHours()
                        // returns 0 for a null timestamp, which already fails any sane
                        // threshold — but this scan runs for EVERY agency in one process,
                        // so a null dereference here would take the whole nightly sweep
                        // down, not just this row. Assert it rather than infer it.
                        if ($property->created_at && $ageHours >= (int) $eff['threshold']) {
                            $hasAny = $hasDocs
                                ? \DB::table('property_documents')->where('property_id', $property->id)->exists()
                                : false;
                            if (! $hasAny) {
                                $label = trim((string) ($property->address ?? '')) ?: "Property #{$property->id}";
                                $age   = AgeFormatter::ago($property->created_at);
                                $dispatcher->fire($agent, 'property.documents_missing', $property, [
                                    'title' => "{$label} — documents missing",
                                    'body'  => $age
                                        ? "Listed {$age}, no documents on file."
                                        : 'No documents on file.',
                                    'subject_label' => $label,
                                    'action_url' => "/properties/{$property->id}",
                                    'severity' => 'warning',
                                    // DEDUP KEY — must be derived from the FACT, never from the clock.
                                    //
                                    // This read `now()->startOfHour()`. The dispatcher suppresses a
                                    // repeat only when an existing log row has
                                    // `threshold_hit_at >= $thresholdHit`, so an hourly bucket mints a
                                    // fresh key every hour and the idempotency ledger never matches.
                                    // The 6h cooldown was the ONLY thing left holding it back, which
                                    // capped it at ~4 alerts per property per agent per DAY — forever,
                                    // for as long as the listing had no documents. 23,792 alerts went
                                    // out this way (26 May - 31 Aug 2026); one agent was taking 39
                                    // pushes a day about 19 listings she already knew about.
                                    //
                                    // This is the same defect that produced the 1.9M
                                    // contact.fica_missing storm, and NotificationDispatcherDedupTest
                                    // named it in advance — "a time bucket is not a fact".
                                    //
                                    // "This listing has no documents" is a PERSISTENT condition, so the
                                    // key is the listing's own creation day: stable forever, identical
                                    // on every tick, and independent of the agent's threshold setting
                                    // (so changing that setting cannot re-open the tap). One alert per
                                    // listing per agent, then silence until the documents land.
                                    'threshold_hit_at' => $property->created_at->copy()->startOfDay(),
                                ]);
                            }
                        }
                    }

                    // property.mandate_expiring
                    if (($property->expiry_date ?? null)) {
                        $eff2 = $prefs->effective($agent, 'property.mandate_expiring');
                        if ($eff2 && $eff2['enabled'] && $eff2['threshold']) {
                            $daysOut = now()->diffInDays($property->expiry_date, false);
                            if ($daysOut >= 0 && $daysOut <= (int) $eff2['threshold']) {
                                $label = trim((string) ($property->address ?? '')) ?: "Property #{$property->id}";
                                // Whole days for copy — never a raw float. 0 days reads as "today".
                                $whole = (int) floor($daysOut);
                                $when  = $whole <= 0
                                    ? 'today'
                                    : "in {$whole} day" . ($whole === 1 ? '' : 's');
                                $dispatcher->fire($agent, 'property.mandate_expiring', $property, [
                                    'title' => "{$label} — mandate expires {$when}",
                                    'body'  => "Mandate expiring on " . $property->expiry_date->format('Y-m-d') . '.',
                                    'subject_label' => $label,
                                    'action_url' => "/properties/{$property->id}",
                                    'severity' => $daysOut <= 3 ? 'overdue' : 'warning',
                                    'threshold_hit_at' => $property->expiry_date->copy()->startOfDay(),
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
