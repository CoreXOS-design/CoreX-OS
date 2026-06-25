<?php

namespace App\Console\Commands\CommandCenter;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\CommandCenter\NotificationPreferenceService;
use Illuminate\Console\Command;

class ScanContactNotifications extends Command
{
    protected $signature = 'notifications:scan-contacts';
    protected $description = 'Scan contacts for follow-up / birthday notifications.';

    public function handle(NotificationPreferenceService $prefs, NotificationDispatcher $dispatcher): int
    {
        Contact::query()
            ->whereNotNull('created_by_user_id')
            ->chunkById(300, function ($contacts) use ($prefs, $dispatcher) {
                foreach ($contacts as $contact) {
                    $agent = User::find($contact->created_by_user_id);
                    if (! $agent) continue;

                    // Tenant guard. This command runs in a console context where
                    // AgencyScope is inert (no Auth::user()), so the query above
                    // sweeps EVERY agency's contacts — including contacts created
                    // by a system-owner account (NULL agency_id). Without this
                    // check that owner, who can't even hold contacts in-app, still
                    // gets birthday emails. Strict match: the contact must carry
                    // the recipient's own agency_id. NULL agency_id is an orphan
                    // and never notifies (see .ai/specs/multi-tenancy.md).
                    $agencyId = $this->agencyIdFor($agent);
                    if (! $agencyId || (int) ($contact->agency_id ?? 0) !== $agencyId) continue;

                    // contact.birthday — daily, fires once per (year-month-day) via threshold_hit_at = today.
                    // Opt-in only: agents are never reminded about a birthday unless they explicitly
                    // turned on the reminder for this contact (contacts.birthday_reminder).
                    if (($contact->birthday_reminder ?? false) && (($contact->birthday ?? null) || ($contact->dob ?? null))) {
                        $dob = $contact->birthday ?? $contact->dob;
                        try {
                            $dobC = \Carbon\Carbon::parse($dob);
                            if ($dobC->month === now()->month && $dobC->day === now()->day) {
                                $eff2 = $prefs->effective($agent, 'contact.birthday');
                                if ($eff2 && $eff2['enabled']) {
                                    $name = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: ('Contact #' . $contact->id);
                                    $dispatcher->fire($agent, 'contact.birthday', $contact, [
                                        'title' => "$name — birthday today",
                                        'body'  => "Today is $name's birthday.",
                                        'subject_label' => $name,
                                        'action_url' => "/contacts/{$contact->id}",
                                        'severity' => 'info',
                                        'threshold_hit_at' => now()->startOfDay(),
                                    ]);
                                }
                            }
                        } catch (\Throwable $e) {}
                    }
                }
            });

        return self::SUCCESS;
    }

    /**
     * Resolve an agent's effective agency without touching the session
     * (this runs in a scheduler/console context where no session is bound).
     * Mirrors User::effectiveAgencyId() minus the owner switcher override,
     * which never applies during a batch scan. A system-owner account with no
     * agency resolves to null here and is therefore never notified.
     */
    private function agencyIdFor(User $agent): ?int
    {
        if ($agent->agency_id) {
            return (int) $agent->agency_id;
        }
        if ($agent->branch_id) {
            $branch = Branch::find($agent->branch_id);
            if ($branch?->agency_id) {
                return (int) $branch->agency_id;
            }
        }
        return null;
    }
}
