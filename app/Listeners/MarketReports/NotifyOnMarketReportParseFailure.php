<?php

declare(strict_types=1);

namespace App\Listeners\MarketReports;

use App\Events\MarketReports\MarketReportSpotCheckFlagged;
use App\Models\MarketReports\MarketReport;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Notifications\PillarEventNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * When a CMA/market report parses but yields ZERO comparable sales while its own
 * summary ranges imply sales exist (the zero-comp guard in ParseMarketReportJob),
 * the import silently failed — the agent would build a presentation with no
 * comparables and never know. This listener surfaces that failure to the people
 * who need it: the agent who imported the report (so they can re-import / raise
 * it) and the platform owners (Johan / Andre — the maintainers who fix the
 * parser), both in-app (notification bell) AND by email.
 *
 * Registered by Laravel's automatic listener discovery (handle() is type-hinted
 * on the event) — do NOT add an explicit Event::listen() in AppServiceProvider,
 * that double-fires it. Only the zero-comp GUARD flag routes here; AI-detected
 * spot-check discrepancies flag through their own existing path.
 *
 * Synchronous + failure-isolated: a mail hiccup or a departed user must never
 * break the parse job. The report is already flagged before this runs.
 */
class NotifyOnMarketReportParseFailure
{
    public function handle(MarketReportSpotCheckFlagged $event): void
    {
        try {
            $report = $event->report;
            if (!$report instanceof MarketReport) {
                return;
            }

            // Only the structural zero-comp guard routes here. AI spot-check
            // discrepancies carry no such flag and are handled elsewhere.
            $results = is_array($report->spot_check_results) ? $report->spot_check_results : [];
            if (($results['flagged_by'] ?? null) !== 'zero_comp_with_summary_guard') {
                return;
            }

            $recipients = collect();

            // 1. The agent who imported the report.
            if ($report->uploaded_by_user_id) {
                $importer = User::query()
                    ->withoutGlobalScope(AgencyScope::class)
                    ->whereKey($report->uploaded_by_user_id)
                    ->first();
                if ($importer) {
                    $recipients->push($importer);
                }
            }

            // 2. Platform owners (is_owner roles are global platform identities —
            //    Johan / Andre) so the maintainers see a parser gap immediately.
            $ownerRoleNames = User::ownerRoleNames();
            if (!empty($ownerRoleNames)) {
                $owners = User::query()
                    ->withoutGlobalScope(AgencyScope::class)
                    ->whereIn('role', $ownerRoleNames)
                    ->get();
                $recipients = $recipients->concat($owners);
            }

            $recipients = $recipients
                ->filter(fn ($u) => $u && !empty($u->email))
                ->unique('id')
                ->values();

            if ($recipients->isEmpty()) {
                return;
            }

            $fileName = $report->file_name ?: 'a market report';

            $actionUrl = null;
            try {
                if (Route::has('reports.show')) {
                    $actionUrl = route('reports.show', $report->id, false);
                } elseif (Route::has('reports.index')) {
                    $actionUrl = route('reports.index', [], false);
                }
            } catch (Throwable) {
                $actionUrl = null;
            }

            $notification = new PillarEventNotification(
                eventKey:     'market_report.parse_zero_comps',
                pillar:       'Presentation',
                title:        'CMA import produced no comparable sales',
                body:         "The report “{$fileName}” parsed but extracted ZERO comparable sales, even though it "
                            . "carries summary ranges (Lower / Middle / Upper / Average) that imply sales exist. Its "
                            . "sales table is in a layout the parser did not recognise, so the comparables did NOT "
                            . "import — a presentation built from it will show no comps. Please re-check the report.",
                subjectType:  MarketReport::class,
                subjectId:    (int) $report->id,
                subjectLabel: $fileName,
                actionUrl:    $actionUrl,
                severity:     'warning',
                payload:      [
                    'report_id'   => (int) $report->id,
                    'report_type' => $report->reportType?->key,
                    'agency_id'   => (int) $report->agency_id,
                    'flagged_by'  => 'zero_comp_with_summary_guard',
                ],
                channels:     ['database', 'mail'],
                // Dedicated CoreX mailer so email delivers via real SMTP even
                // where the default mailer is a sink (staging).
                mailer:       'corex',
            );

            foreach ($recipients as $user) {
                try {
                    $user->notify($notification);
                } catch (Throwable $e) {
                    Log::error('NotifyOnMarketReportParseFailure: per-recipient notify failed', [
                        'report_id' => $report->id,
                        'user_id'   => $user->id,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }
        } catch (Throwable $e) {
            Log::error('NotifyOnMarketReportParseFailure failed', [
                'report_id' => $event->report->id ?? null,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
