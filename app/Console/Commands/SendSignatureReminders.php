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

            // FINAL REMINDER (day 10+, reminder_count < 3)
            if ($daysSinceSent >= $config['final_after_days'] && $request->reminder_count < 3) {
                $signatureService->resendNotification($request);
                $this->line("  FINAL reminder #{$request->fresh()->reminder_count} for {$request->signer_name} ({$request->signer_email})");
                $sent++;

            // TEAM ALERT (day 7+, not yet alerted)
            } elseif ($daysSinceSent >= $config['team_alert_after_days'] && !$request->team_alerted_at) {
                $this->sendTeamAlert($request);
                $this->line("  TEAM ALERT: {$request->signer_name} hasn't signed after {$daysSinceSent} days");
                $alerts++;

            // FIRM REMINDER (day 5+, reminder_count < 2)
            } elseif ($daysSinceSent >= $config['firm_after_days'] && $request->reminder_count < 2) {
                $signatureService->resendNotification($request);
                $this->line("  FIRM reminder #{$request->fresh()->reminder_count} for {$request->signer_name} ({$request->signer_email})");
                $sent++;

            // GENTLE REMINDER (day 2+, reminder_count < 1)
            } elseif ($daysSinceSent >= $config['gentle_after_days'] && $request->reminder_count < 1) {
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
