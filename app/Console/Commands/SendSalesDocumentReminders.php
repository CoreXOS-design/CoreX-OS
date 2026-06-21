<?php

namespace App\Console\Commands;

use App\Mail\Signatures\SalesDocumentReminderMail;
use App\Models\SalesDocumentRecipient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendSalesDocumentReminders extends Command
{
    protected $signature = 'sales-documents:send-reminders';
    protected $description = 'Send reminders for unreturned sales documents';

    public function handle(): int
    {
        $sent = 0;

        $pending = SalesDocumentRecipient::awaitingReturn()
            ->with('documentSend.sender')
            ->get();

        foreach ($pending as $recipient) {
            $days = $recipient->daysSinceSent();

            if ($days >= 10 && $recipient->reminder_count < 3) {
                $this->sendReminder($recipient, 'final');
                $sent++;
            } elseif ($days >= 5 && $recipient->reminder_count < 2) {
                $this->sendReminder($recipient, 'firm');
                $sent++;
            } elseif ($days >= 2 && $recipient->reminder_count < 1) {
                $this->sendReminder($recipient, 'gentle');
                $sent++;
            }
        }

        $this->info("Sent {$sent} sales document reminders.");

        return self::SUCCESS;
    }

    private function sendReminder(SalesDocumentRecipient $recipient, string $level): void
    {
        $send  = $recipient->documentSend;
        $agent = $send->sender;

        Mail::to($recipient->recipient_email)->send(
            (new SalesDocumentReminderMail(
                recipientName: $recipient->recipient_name,
                documentName: $send->document_name,
                uploadUrl: route('sales-documents.upload', ['token' => $recipient->token]),
                level: $level,
                agentEmail: $agent->outward_email ?? config('mail.from.address'), // AT-79 outward override
                daysSinceSent: $recipient->daysSinceSent(),
            ))->fromAgent($agent)
        );

        $recipient->update([
            'reminder_count'   => $recipient->reminder_count + 1,
            'last_reminder_at' => now(),
        ]);

        $this->line("  → {$level} reminder sent to {$recipient->recipient_name} ({$send->document_name})");
    }
}
