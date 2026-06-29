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
                $q->whereNull('status')->orWhereNotIn('status', ['sold','withdrawn','expired']);
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
                        if ($ageHours >= (int) $eff['threshold']) {
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
                                    'threshold_hit_at' => now()->startOfHour(),
                                ]);
                            }
                        }
                    }

                    // property.mandate_expiring
                    if (($property->mandate_expires_at ?? null)) {
                        $eff2 = $prefs->effective($agent, 'property.mandate_expiring');
                        if ($eff2 && $eff2['enabled'] && $eff2['threshold']) {
                            $daysOut = now()->diffInDays($property->mandate_expires_at, false);
                            if ($daysOut >= 0 && $daysOut <= (int) $eff2['threshold']) {
                                $label = trim((string) ($property->address ?? '')) ?: "Property #{$property->id}";
                                // Whole days for copy — never a raw float. 0 days reads as "today".
                                $whole = (int) floor($daysOut);
                                $when  = $whole <= 0
                                    ? 'today'
                                    : "in {$whole} day" . ($whole === 1 ? '' : 's');
                                $dispatcher->fire($agent, 'property.mandate_expiring', $property, [
                                    'title' => "{$label} — mandate expires {$when}",
                                    'body'  => "Mandate expiring on " . $property->mandate_expires_at->format('Y-m-d') . '.',
                                    'subject_label' => $label,
                                    'action_url' => "/properties/{$property->id}",
                                    'severity' => $daysOut <= 3 ? 'overdue' : 'warning',
                                    'threshold_hit_at' => $property->mandate_expires_at->copy()->startOfDay(),
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
