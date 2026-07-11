<?php

namespace App\Console\Commands;

use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\User;
use App\Notifications\SignatureTeamAlert;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Console\Command;

class SendSignatureReminders extends Command
{
    protected $signature = 'signatures:send-reminders';

    protected $description = 'Send escalating reminders for pending signature requests (configurable via config/signatures.php)';

    public function handle(SignatureService $signatureService): int
    {
        $this->info('Checking for signature requests needing reminders...');

        $config = config('signatures.reminders');

        // Total emails any one signer may receive across the whole ladder.
        // Declared in config/signatures.php and previously never read — the tiers
        // were hardcoded, so raising or lowering the cap in config did nothing.
        $maxReminders = (int) ($config['max_email_reminders'] ?? 3);

        // Chase EVERY signing that is waiting on an external signer — sales included.
        // (This used to be a hand-rolled rental-only list, which is why no sales
        // signing was ever reminded. The canonical set lives on the model.)
        $pendingRequests = SignatureRequest::needsReminder()
            ->whereHas('template', fn ($q) => $q->awaitingSigner())
            ->with(['template.document', 'template.creator'])
            ->get();

        $sent = 0;
        $expired = 0;
        $alerts = 0;

        foreach ($pendingRequests as $request) {
            // Check if expired
            if ($request->isExpired()) {
                $request->update(['status' => SignatureRequest::STATUS_EXPIRED]);
                $expired++;
                continue;
            }

            // Skip wet ink uploads pending review (signer has done their part)
            if ($request->wet_ink_status === SignatureRequest::WET_INK_UPLOADED_PENDING_REVIEW) {
                continue;
            }

            $daysSinceSent = $request->daysSinceSent();

            // The ladder. Each rung is the Nth email to this signer, and NO rung may
            // push a signer past the configured cap:
            //   gentle = the 1st email · firm = the 2nd · final = the 3rd and beyond.
            // Capping each rung (not just the last) means max_email_reminders = 1 or 0
            // is honoured too, instead of the early rungs firing regardless.
            $gentleCap = min(1, $maxReminders);
            $firmCap   = min(2, $maxReminders);

            // FINAL REMINDER (day 10+, until the cap is reached)
            if ($daysSinceSent >= $config['final_after_days'] && $request->reminder_count < $maxReminders) {
                $signatureService->resendNotification($request);
                $this->line("  FINAL reminder #{$request->fresh()->reminder_count} for {$request->signer_name} ({$request->signer_email})");
                $sent++;

            // TEAM ALERT (day 7+, not yet alerted). This alerts the AGENT, not the
            // signer, so it is not an email to the signer and not capped by
            // max_email_reminders — it fires once, guarded by team_alerted_at.
            } elseif ($daysSinceSent >= $config['team_alert_after_days'] && !$request->team_alerted_at) {
                $this->sendTeamAlert($request);
                $this->line("  TEAM ALERT: {$request->signer_name} hasn't signed after {$daysSinceSent} days");
                $alerts++;

            // FIRM REMINDER (day 5+, the 2nd email)
            } elseif ($daysSinceSent >= $config['firm_after_days'] && $request->reminder_count < $firmCap) {
                $signatureService->resendNotification($request);
                $this->line("  FIRM reminder #{$request->fresh()->reminder_count} for {$request->signer_name} ({$request->signer_email})");
                $sent++;

            // GENTLE REMINDER (day 2+, the 1st email)
            } elseif ($daysSinceSent >= $config['gentle_after_days'] && $request->reminder_count < $gentleCap) {
                $signatureService->resendNotification($request);
                $this->line("  GENTLE reminder #{$request->fresh()->reminder_count} for {$request->signer_name} ({$request->signer_email})");
                $sent++;
            }
        }

        $this->info("Done. Reminders: {$sent}, Team alerts: {$alerts}, Expired: {$expired}");

        return 0;
    }

    private function sendTeamAlert(SignatureRequest $request): void
    {
        $template = $request->template;
        $agent = $template->creator ?? ($request->sent_by ? User::find($request->sent_by) : null);

        if ($agent) {
            try {
                // Send the agent to the dashboard that actually holds this document.
                // This was hardcoded to the rentals dashboard, so once sales alerts
                // start firing (they never could before), a sales agent would have
                // been dropped on the rentals screen with no sign of their document.
                $dashboardUrl = $template->isRentalSideSigning()
                    ? route('docuperfect.rental')
                    : route('docuperfect.dashboard');

                $agent->notify(new SignatureTeamAlert(
                    signerName: $request->signer_name,
                    signerEmail: $request->signer_email,
                    documentName: $template->document->name ?? 'Document',
                    daysSinceSent: $request->daysSinceSent(),
                    signerStatus: $request->status,
                    dashboardUrl: $dashboardUrl,
                ));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Failed to send team alert notification', [
                    'request_id' => $request->id,
                    'agent_id' => $agent->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $request->update(['team_alerted_at' => now()]);

        SignatureAuditLog::log(
            $template,
            SignatureAuditLog::ACTION_TEAM_ALERT_SENT,
            SignatureAuditLog::ACTOR_SYSTEM,
            'System',
            requestId: $request->id,
            metadata: [
                'signer_name' => $request->signer_name,
                'agent_name' => $agent?->name,
                'days_since_sent' => $request->daysSinceSent(),
            ],
        );
    }
}
