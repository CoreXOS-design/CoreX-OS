<?php

declare(strict_types=1);

namespace App\Listeners\MarketReports;

use App\Events\AbstractDomainEvent;
use App\Events\MarketReports\MarketReportReadByAi;
use App\Events\MarketReports\MarketReportSpotCheckFlagged;
use App\Events\MarketReports\MarketReportUnreadable;
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
 * 2026-10-20 — report recogniser (mic-complete-spec §8.9). Two more named events
 * route here: MarketReportReadByAi ("we couldn't read this layout with a known
 * format, so CoreX read it with AI — please check the figures") and
 * MarketReportUnreadable ("couldn't read it — it's saved and in the CoreX admin
 * queue"). The old "please flag it so it can be added" wording is gone: there is
 * nothing for the agent to flag, the report is already in the admin queue. The
 * action link now uses the real route names (market-intelligence.reports.*) — the
 * old `reports.show` / `reports.index` do not exist, so the email had no link.
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

    /**
     * Set by the one-off reprocess command: a bulk re-run of old reports must never
     * send mail (or fill anyone's bell) about reports they uploaded weeks ago.
     */
    public static bool $suppressed = false;

    /** What the agent is told whenever a report could not be recognised (cap reached, AI failed, or the layout is already with CoreX). */
    public const NOT_RECOGNISED = 'Your report could not be recognised. It has been sent to CoreX to fix. CoreX will report back to you as soon as possible.';

    /** @param MarketReportSpotCheckFlagged|MarketReportReadByAi|MarketReportUnreadable $event */
    public function handle(AbstractDomainEvent $event): void
    {
        try {
            if (self::$suppressed) {
                return;
            }
            $report = $event->report ?? null;
            if (!$report instanceof MarketReport) {
                return;
            }

            if ($event instanceof MarketReportReadByAi) {
                $flaggedBy = 'ai_read';
            } elseif ($event instanceof MarketReportUnreadable) {
                $flaggedBy = 'unrecognized_document';
            } else {
                // Only the structural zero-yield guards route here. AI spot-check
                // discrepancies carry no such flag and are handled elsewhere.
                $results = is_array($report->spot_check_results) ? $report->spot_check_results : [];
                $flaggedBy = $results['flagged_by'] ?? null;
                if (!in_array($flaggedBy, self::GUARD_FLAGS, true)) {
                    return;
                }
            }

            $fileName = $report->file_name ?: 'a market report';

            // The agent who imported the report.
            $importer = null;
            if ($report->uploaded_by_user_id) {
                $importer = User::query()
                    ->withoutGlobalScope(AgencyScope::class)
                    ->whereKey($report->uploaded_by_user_id)
                    ->first();
            }

            // The CoreX team = platform owners (is_owner roles are global platform identities —
            // Johan / Andre), so the maintainers see a parser gap immediately.
            $team = collect();
            $ownerRoleNames = User::ownerRoleNames();
            if (!empty($ownerRoleNames)) {
                $team = User::query()
                    ->withoutGlobalScope(AgencyScope::class)
                    ->whereIn('role', $ownerRoleNames)
                    ->get();
            }

            $reportUrl = $this->routeUrl('market-intelligence.reports.show', [$report->id])
                ?? $this->routeUrl('market-intelligence.reports.index');
            $queueUrl  = $this->routeUrl('market-intelligence.reports.recogniser.queue') ?? $reportUrl;

            // ── Report recogniser (mic-complete-spec §8.9, "AI cost protection") ─────
            // AI-read and not-recognised reports: the uploader gets plain words for an agent,
            // the CoreX team gets a separate alert (agency, file, publisher, queue link) so the
            // layout can be fixed for good. An uploader who is also on the team gets the team one only.
            if ($flaggedBy === 'ai_read' || $flaggedBy === 'unrecognized_document') {
                $code = $event instanceof MarketReportUnreadable ? $event->reasonCode : null;

                [$agentKey, $agentTitle, $agentBody] = $flaggedBy === 'ai_read'
                    ? [
                        'market_report.read_by_ai',
                        "Report read by AI: {$fileName}",
                        "We couldn't read this layout with a known format, so CoreX read it with AI. Please check the figures.",
                    ]
                    : [
                        'market_report.not_recognised',
                        "Report not recognised: {$fileName}",
                        self::NOT_RECOGNISED,
                    ];

                $agencyName = (string) (\Illuminate\Support\Facades\DB::table('agencies')->where('id', $report->agency_id)->value('name') ?: ('Agency #' . $report->agency_id));
                $publisher  = $report->read_publisher ?: 'not known';
                $facts      = "Agency: {$agencyName}. File: {$fileName}. Publisher: {$publisher}.";

                $teamNotification = null;
                if ($flaggedBy === 'ai_read') {
                    $teamNotification = $this->notification($report, 'market_report.read_by_ai_team',
                        "AI read a report: {$fileName} ({$agencyName})",
                        $facts . ' ' . ($event instanceof MarketReportReadByAi && $event->fromCache
                            ? "The AI's earlier answer for this exact file was reused (no new AI cost)."
                            : "This was a new AI read and it counts toward the agency's monthly limit.")
                          . ' Open the admin queue to add this layout permanently.',
                        $queueUrl, 'info', $flaggedBy);
                } elseif ($code === 'layout_case_open') {
                    // Queued under a layout case that is already open: the team already has it. No second alert.
                } elseif ($code === 'ai_platform_daily_cap') {
                    // One "AI read cap hit" alert per day, not one per report.
                    if (\App\Models\MarketReports\MarketReportAiUsage::claimPlatformCapAlert()) {
                        $teamNotification = $this->notification($report, 'market_report.ai_cap_hit_team',
                            'AI read cap hit',
                            "The platform's daily limit of " . app(\App\Services\MarketReports\ReportReadSettings::class)->platformDailyCap()
                              . " AI reads has been reached. Until tomorrow, every report in a new layout is queued instead of read. First one queued: {$fileName} ({$agencyName}). Open the admin queue.",
                            $queueUrl, 'warning', $flaggedBy);
                    }
                } else {
                    $teamNotification = $this->notification($report, 'market_report.parse_unrecognized_team',
                        "Report layout needs adding: {$fileName} ({$agencyName})",
                        $facts . ' Reason: ' . ($report->ai_read_note ?: 'none recorded')
                          . ' It is saved in the admin queue. Add or approve the layout and this agency gets its AI reads back, and its queued reports are re-run on the new layout.',
                        $queueUrl, 'warning', $flaggedBy);
                }

                if ($teamNotification !== null) {
                    $this->sendTo($team, $teamNotification, $report);
                }
                $teamGotIt = $teamNotification !== null && $importer && $team->contains('id', $importer->id);
                if ($importer && !empty($importer->email) && !$teamGotIt) {
                    $this->sendTo(collect([$importer]), $this->notification($report, $agentKey, $agentTitle, $agentBody, $reportUrl, $flaggedBy === 'ai_read' ? 'info' : 'warning', $flaggedBy), $report);
                }

                return;
            }

            // The two structural zero-yield guards keep one message for the importer and the team.
            $recipients = collect([$importer])->concat($team)
                ->filter(fn ($u) => $u && !empty($u->email))
                ->unique('id')
                ->values();
            if ($recipients->isEmpty()) {
                return;
            }

            [$eventKey, $title, $body] = match ($flaggedBy) {
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

            $this->sendTo($recipients, $this->notification($report, $eventKey, $title, $body, $reportUrl, 'warning', $flaggedBy), $report);
        } catch (Throwable $e) {
            Log::error('NotifyOnMarketReportParseFailure failed', [
                'report_id' => $event->report->id ?? null,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    private function routeUrl(string $name, array $params = []): ?string
    {
        try {
            return Route::has($name) ? route($name, $params, false) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function notification(MarketReport $report, string $eventKey, string $title, string $body, ?string $actionUrl, string $severity, string $flaggedBy): PillarEventNotification
    {
        return new PillarEventNotification(
            eventKey:     $eventKey,
            pillar:       'Presentation',
            title:        $title,
            body:         $body,
            subjectType:  MarketReport::class,
            subjectId:    (int) $report->id,
            subjectLabel: $report->file_name ?: 'a market report',
            actionUrl:    $actionUrl,
            severity:     $severity,
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
    }

    /** One failed recipient never stops the others, or the parse job. */
    private function sendTo(\Illuminate\Support\Collection $users, PillarEventNotification $notification, MarketReport $report): void
    {
        foreach ($users->filter(fn ($u) => $u && !empty($u->email))->unique('id') as $user) {
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
    }
}
