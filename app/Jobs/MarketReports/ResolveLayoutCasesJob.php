<?php

declare(strict_types=1);

namespace App\Jobs\MarketReports;

use App\Models\MarketReports\MarketReportLearnedLayout;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Notifications\PillarEventNotification;
use App\Services\MarketReports\LayoutCaseResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * A CoreX owner approved a learned layout: close every layout case it answers (reset the agencies' AI counters, re-run
 * the queued reports on the layout with no AI call, tell their agents). Spec: mic-complete-spec §8.9 (item 8).
 *
 * Runs on a queue worker, never in the Approve web request: a case can hold many reports and each is a full re-parse.
 * The owner gets a bell message with the outcome when it is done.
 */
class ResolveLayoutCasesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** One run re-parses every queued report in the case(s); give it room. */
    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public readonly int $layoutId, public readonly ?int $byUserId = null) {}

    public function handle(LayoutCaseResolver $resolver): void
    {
        $layout = MarketReportLearnedLayout::query()->withTrashed()->find($this->layoutId);
        if ($layout === null) {
            return;
        }

        $summary = $resolver->resolveForLayout($layout, $this->byUserId);
        $text    = LayoutCaseResolver::summaryText($summary);

        $this->tellOwner('Layout approved' . ($text !== '' ? '. ' . $text : '. No queued reports were waiting for it.'));
    }

    public function failed(?Throwable $e = null): void
    {
        Log::error('ResolveLayoutCasesJob failed', ['layout_id' => $this->layoutId, 'error' => $e?->getMessage()]);
        $this->tellOwner('The layout was approved, but re-running its queued reports did not finish. Approve it again to retry.');
    }

    private function tellOwner(string $body): void
    {
        if ($this->byUserId === null) {
            return;
        }
        try {
            $user = User::query()->withoutGlobalScope(AgencyScope::class)->whereKey($this->byUserId)->first();
            $user?->notify(new PillarEventNotification(
                eventKey:     'market_report.layout_resolved',
                pillar:       'Presentation',
                title:        'Learned layout approved',
                body:         $body,
                subjectType:  MarketReportLearnedLayout::class,
                subjectId:    $this->layoutId,
                subjectLabel: 'Learned layout #' . $this->layoutId,
                actionUrl:    Route::has('market-intelligence.reports.recogniser.layouts') ? route('market-intelligence.reports.recogniser.layouts', [], false) : null,
                severity:     'info',
                payload:      ['layout_id' => $this->layoutId],
                channels:     ['database'],
            ));
        } catch (Throwable $e) {
            Log::warning('ResolveLayoutCasesJob: owner notification failed', ['layout_id' => $this->layoutId, 'error' => $e->getMessage()]);
        }
    }
}
