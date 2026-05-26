<?php

namespace App\Services;

use App\Mail\FeedbackReportMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class FeedbackDeliveryService
{
    /**
     * Deliver a feedback report via the configured channel.
     */
    public function deliver(int $reportId): void
    {
        $channel = $this->resolveChannel();

        if ($channel === 'log') {
            $this->deliverToLog($reportId);
        } else {
            $this->deliverToEmail($reportId);
        }
    }

    private function resolveChannel(): string
    {
        $configured = config('feedback.channel', 'auto');

        if ($configured !== 'auto') {
            return $configured;
        }

        return app()->environment('local') ? 'log' : 'email';
    }

    private function deliverToLog(int $reportId): void
    {
        $report = DB::table('feedback_reports')->where('id', $reportId)->first();
        if (!$report) return;

        $user = \App\Models\User::withoutGlobalScopes()->find($report->user_id);

        Log::channel('feedback')->info('New feedback report', [
            'id' => $report->id,
            'type' => $report->type,
            'severity' => $report->severity,
            'title' => $report->title,
            'description' => $report->description,
            'module' => $report->module_tag,
            'page_url' => $report->page_url,
            'submitted_by' => $user?->name ?? 'Unknown',
            'submitted_at' => $report->submitted_at,
        ]);
    }

    private function deliverToEmail(int $reportId): void
    {
        $report = DB::table('feedback_reports')->where('id', $reportId)->first();
        if (!$report) return;

        $recipients = $this->getRecipients($report->agency_id);
        if (empty($recipients)) return;

        $user = \App\Models\User::withoutGlobalScopes()->find($report->user_id);
        $attachments = DB::table('feedback_attachments')
            ->where('feedback_report_id', $reportId)
            ->get();

        Mail::to($recipients)->send(new FeedbackReportMail($report, $user, $attachments));
    }

    /**
     * Get recipient emails: agency-specific first, then fallback from config.
     */
    private function getRecipients(int $agencyId): array
    {
        $agencyRecipients = DB::table('agencies')
            ->where('id', $agencyId)
            ->value('feedback_recipients');

        if ($agencyRecipients) {
            $decoded = json_decode($agencyRecipients, true);
            if (!empty($decoded)) {
                return $decoded;
            }
        }

        return config('feedback.fallback_recipients', []);
    }
}
