<?php

namespace App\Console\Commands;

use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Rental\RentalReminderSetting;
use App\Models\User;
use App\Notifications\SignatureTeamAlert;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Console\Command;

class SendSignatureReminders extends Command
{
    protected $signature = 'signatures:send-reminders';

    protected $description = 'Send reminders for pending signature requests (configurable via Rental Settings > Email Reminders)';

    public function handle(SignatureService $signatureService): int
    {
        $this->info('Checking for signature requests needing reminders...');

        $settings = RentalReminderSetting::current();

        if (!$settings->enabled) {
            $this->info('Automatic reminders are disabled. Exiting.');
            return 0;
        }

        $pendingRequests = SignatureRequest::needsReminder()
            ->whereHas('template', function ($q) {
                $q->whereIn('status', [
                    SignatureTemplate::STATUS_SIGNING,
                    SignatureTemplate::STATUS_AWAITING_TENANT,
                    SignatureTemplate::STATUS_AWAITING_LANDLORD,
                ]);
            })
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

            if ($settings->mode === 'simple') {
                // Simple mode: send every N days, up to max
                if ($request->reminder_count >= $settings->max_simple_reminders) {
                    continue;
                }

                $nextReminderDay = $settings->interval_days * ($request->reminder_count + 1);

                if ($daysSinceSent >= $nextReminderDay) {
                    $signatureService->resendNotification($request, $settings);
                    $this->line("  SIMPLE reminder #{$request->fresh()->reminder_count} for {$request->signer_name} ({$request->signer_email})");
                    $sent++;
                }
            } else {
                // Escalating mode (thresholds from DB settings)
                $maxReminders = $settings->max_escalating_reminders;

                // FINAL REMINDER
                if ($daysSinceSent >= $settings->final_after_days && $request->reminder_count < $maxReminders) {
                    $signatureService->resendNotification($request, $settings);
                    $this->line("  FINAL reminder #{$request->fresh()->reminder_count} for {$request->signer_name} ({$request->signer_email})");
                    $sent++;

                // TEAM ALERT
                } elseif ($daysSinceSent >= $settings->team_alert_after_days && !$request->team_alerted_at) {
                    $this->sendTeamAlert($request);
                    $this->line("  TEAM ALERT: {$request->signer_name} hasn't signed after {$daysSinceSent} days");
                    $alerts++;

                // FIRM REMINDER
                } elseif ($daysSinceSent >= $settings->firm_after_days && $request->reminder_count < min(2, $maxReminders)) {
                    $signatureService->resendNotification($request, $settings);
                    $this->line("  FIRM reminder #{$request->fresh()->reminder_count} for {$request->signer_name} ({$request->signer_email})");
                    $sent++;

                // GENTLE REMINDER
                } elseif ($daysSinceSent >= $settings->gentle_after_days && $request->reminder_count < 1) {
                    $signatureService->resendNotification($request, $settings);
                    $this->line("  GENTLE reminder #{$request->fresh()->reminder_count} for {$request->signer_name} ({$request->signer_email})");
                    $sent++;
                }
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
                $agent->notify(new SignatureTeamAlert(
                    signerName: $request->signer_name,
                    signerEmail: $request->signer_email,
                    documentName: $template->document->name ?? 'Document',
                    daysSinceSent: $request->daysSinceSent(),
                    signerStatus: $request->status,
                    dashboardUrl: route('docuperfect.rental'),
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
