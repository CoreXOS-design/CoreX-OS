<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\Compliance\PpraEmploymentLetter;
use App\Services\Compliance\PpraEmploymentLetterService;
use Illuminate\Console\Command;

/**
 * PPRA FFC renewal — Confirmation of Employment letter.
 * .ai/specs/ppra-ffc-employment-letter.md
 *
 * While a letter sits awaiting_principal_signature, re-send the "awaiting
 * your signature" email to the resolved principal every
 * agencies.ppra_employment_letter_reminder_days days (0 = off). No expiry —
 * it waits indefinitely (Johan's ruling). Mirrors SendSignatureReminders'
 * shape (one command, one scheduled slot) but with its own, simpler cadence
 * — no escalation tiers, since this is an internal two-person ceremony, not
 * an external multi-party signing chain.
 */
class SendPpraEmploymentLetterReminders extends Command
{
    protected $signature = 'ppra-employment-letters:send-reminders';

    protected $description = 'Re-send the principal-signature reminder email for PPRA FFC employment letters, per each agency\'s configured cadence';

    public function handle(PpraEmploymentLetterService $service): int
    {
        $sent = 0;

        PpraEmploymentLetter::where('status', PpraEmploymentLetter::STATUS_AWAITING_PRINCIPAL_SIGNATURE)
            ->whereColumn('user_id', '!=', 'principal_user_id') // self-sign-both letters never need a reminder
            ->chunkById(100, function ($letters) use ($service, &$sent) {
                $agencyCache = [];

                foreach ($letters as $letter) {
                    $agencyId = $letter->agency_id;
                    $agencyCache[$agencyId] ??= Agency::withoutGlobalScopes()->find($agencyId);
                    $agency = $agencyCache[$agencyId];
                    if (! $agency) {
                        continue;
                    }

                    $days = (int) $agency->ppra_employment_letter_reminder_days;
                    if ($days <= 0) {
                        continue; // reminders off for this agency
                    }

                    $last = $letter->reminder_last_sent_at ?? $letter->created_at;
                    if ($last->diffInDays(now()) < $days) {
                        continue;
                    }

                    $service->notifyPrincipal($letter);
                    $sent++;
                }
            });

        $this->info("PPRA employment letter reminders sent: {$sent}");

        return 0;
    }
}
