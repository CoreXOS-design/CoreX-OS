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
 * When a CMA/market report parses but yields nothing usable — zero comparable
 * sales despite its own summary ranges implying sales exist, an entirely
 * unrecognised document, or a recognised document that still produced zero
 * data points overall (the three structural guards in ParseMarketReportJob) —
 * the import silently failed. The agent would rely on a presentation with no
 * comparables, or a suburb report with no CMA figures, and never know. This
 * listener surfaces that failure to the people who need it: the agent who
 * imported the report (so they can re-import / raise it) and the platform
 * owners (Johan / Andre — the maintainers who fix the parser), both in-app
 * (notification bell) AND by email.
 *
 * 2026-08-25 — widened from the single zero-comp-with-summary case to all
 * three guards. Johan, verbatim: "An import that extracts nothing must never
 * look like an import that worked." Real trigger: both of his own Shelly
 * Beach CMA uploads silently produced zero data points with no warning at all
 * before this fix — one because it was not recognised as a CMA, one because it
 * was recognised but its table layout didn't match the parser's regex.
 *
 * Registered explicitly in AppServiceProvider::boot() (2026-08-25 — event
 * auto-discovery is disabled app-wide per AT-261; this listener's original
 * docblock claimed discovery would register it, but discovery was disabled
 * after this was written and nobody added the explicit registration, so it
 * had never actually run). Only the three structural GUARD flags route here;
 * AI-detected spot-check discrepancies flag through their own existing path.
 *
 * Synchronous + failure-isolated: a mail hiccup or a departed user must never
 * break the parse job. The report is already flagged before this runs.
 */
class NotifyOnMarketReportParseFailure
{
    private const GUARD_FLAGS = [
        'zero_comp_with_summary_guard',
        'unrecognized_document',
        'recognized_zero_data_points',
    ];

    public function handle(MarketReportSpotCheckFlagged $event): void
    {
        try {
            $report = $event->report;
            if (!$report instanceof MarketReport) {
                return;
            }

            // Only the structural zero-yield guards route here. AI spot-check
            // discrepancies carry no such flag and are handled elsewhere.
            $results = is_array($report->spot_check_results) ? $report->spot_check_results : [];
            $flaggedBy = $results['flagged_by'] ?? null;
            if (!in_array($flaggedBy, self::GUARD_FLAGS, true)) {
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

            [$eventKey, $title, $body] = match ($flaggedBy) {
                'unrecognized_document' => [
                    'market_report.parse_unrecognized',
                    'CMA import was not recognised',
                    "The report “{$fileName}” did not match any known CMA report format, so nothing was extracted "
                        . "from it. If this is meant to be a CMA/market report, its layout is not yet supported by "
                        . "CoreX — please flag it so it can be added. If it isn't a CMA report, no action is needed.",
                ],
                'recognized_zero_data_points' => [
                    'market_report.parse_zero_data',
                    'CMA import extracted nothing',
                    "The report “{$fileName}” was recognised as a CMA report, but the parser could not extract any "
                        . "figures from it — the import produced nothing. Please re-check the report before relying "
                        . "on it; it needs review.",
                ],
                default => [
                    'market_report.parse_zero_comps',
                    'CMA import produced no comparable sales',
                    "The report “{$fileName}” parsed but extracted ZERO comparable sales, even though it "
                        . "carries summary ranges (Lower / Middle / Upper / Average) that imply sales exist. Its "
                        . "sales table is in a layout the parser did not recognise, so the comparables did NOT "
                        . "import — a presentation built from it will show no comps. Please re-check the report.",
                ],
            };

            $notification = new PillarEventNotification(
                eventKey:     $eventKey,
                pillar:       'Presentation',
                title:        $title,
                body:         $body,
                subjectType:  MarketReport::class,
                subjectId:    (int) $report->id,
                subjectLabel: $fileName,
                actionUrl:    $actionUrl,
                severity:     'warning',
                payload:      [
                    'report_id'   => (int) $report->id,
                    'report_type' => $report->reportType?->key,
                    'agency_id'   => (int) $report->agency_id,
                    'flagged_by'  => $flaggedBy,
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
