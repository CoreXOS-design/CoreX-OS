<?php

namespace App\Console\Commands\CommandCenter;

use App\Models\Contact;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\CommandCenter\NotificationPreferenceService;
use App\Support\Notifications\AgeFormatter;
use Illuminate\Console\Command;

class ScanContactNotifications extends Command
{
    protected $signature = 'notifications:scan-contacts';
    protected $description = 'Scan contacts for FICA / follow-up / birthday notifications.';

    public function handle(NotificationPreferenceService $prefs, NotificationDispatcher $dispatcher): int
    {
        Contact::query()
            ->whereNotNull('created_by_user_id')
            ->chunkById(300, function ($contacts) use ($prefs, $dispatcher) {
                foreach ($contacts as $contact) {
                    $agent = User::find($contact->created_by_user_id);
                    if (! $agent) continue;

                    // contact.fica_missing
                    $eff = $prefs->effective($agent, 'contact.fica_missing');
                    if ($eff && $eff['enabled'] && $eff['threshold']) {
                        $ageHours = AgeFormatter::wholeHours($contact->created_at);
                        if ($ageHours >= (int) $eff['threshold']) {
                            $hasFica = false;
                            try {
                                $hasFica = $contact->isFicaCompliant();
                            } catch (\Throwable $e) { /* method may differ */ }

                            if (! $hasFica) {
                                $name = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: ('Contact #' . $contact->id);
                                $age  = AgeFormatter::ago($contact->created_at);
                                $dispatcher->fire($agent, 'contact.fica_missing', $contact, [
                                    'title' => "$name — FICA missing",
                                    'body'  => $age
                                        ? "Created {$age} without FICA documents."
                                        : 'No FICA documents on file.',
                                    'subject_label' => $name,
                                    'action_url' => "/contacts/{$contact->id}",
                                    'severity' => 'warning',
                                    'threshold_hit_at' => now()->startOfHour(),
                                ]);
                            }
                        }
                    }

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
}
