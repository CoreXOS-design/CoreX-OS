<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Listeners\MarketReports\NotifyOnMarketReportParseFailure;
use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportAiRead;
use App\Services\MarketReports\MarketReportReprocessor;
use App\Services\MarketReports\ReportReadSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * One-off: push the market reports that were flagged "not recognised" before the report
 * recogniser existed back through the read pipeline (learned layout -> AI read).
 * Spec: .ai/specs/mic-complete-spec.md §8.9.
 *
 * DRY-RUN BY DEFAULT: prints how many reports would be reprocessed (per agency, how many
 * have their file on disk, how many already have a cached AI answer and so cost nothing)
 * and changes nothing. --apply does the work. Either way it NEVER sends a notification
 * (the uploaders were told about these weeks ago), honours the agency switch, the page
 * limit, the AI cache and the spend guard exactly like a live upload.
 */
class ReprocessUnreadMarketReports extends Command
{
    protected $signature = 'market-reports:reprocess-unread
        {--apply : Actually reprocess (default is a dry run that changes nothing)}
        {--agency= : Only this agency id}
        {--limit= : Reprocess at most this many reports}';

    protected $description = 'Reprocess market reports flagged "not recognised" through the learned-layout / AI read pipeline (dry run unless --apply).';

    public function handle(MarketReportReprocessor $reprocessor): int
    {
        $query = MarketReport::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->where('read_method', MarketReport::READ_UNREAD)
                    ->orWhere(fn ($q2) => $q2->where('read_method', MarketReport::READ_PARSER)
                        ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(spot_check_results, '$.flagged_by')) = 'unrecognized_document'"));
            })
            ->orderBy('id');
        if ($this->option('agency')) {
            $query->where('agency_id', (int) $this->option('agency'));
        }
        if ($this->option('limit')) {
            $query->limit(max(1, (int) $this->option('limit')));
        }

        $reports = $query->get();
        $apply = (bool) $this->option('apply');

        $this->info(($apply ? 'APPLY' : 'DRY RUN') . ' — ' . $reports->count() . ' report(s) flagged "not recognised".');

        $perAgency = [];
        $missing = 0;
        $cached = 0;
        foreach ($reports as $r) {
            $a = (int) $r->agency_id;
            $perAgency[$a] = ($perAgency[$a] ?? 0) + 1;
            if (!$reprocessor->fileExists($r)) {
                $missing++;
                continue;
            }
            $path = Storage::disk('local')->path($r->file_path);
            if (MarketReportAiRead::query()->where('file_hash', hash_file('sha256', $path))
                ->where('prompt_version', ReportReadSettings::PROMPT_VERSION)->exists()) {
                $cached++;
            }
        }
        foreach ($perAgency as $agencyId => $n) {
            $name = DB::table('agencies')->where('id', $agencyId)->value('name') ?? '(unknown)';
            $this->line("  agency {$agencyId} ({$name}): {$n}");
        }
        $this->line("  stored PDF missing on disk: {$missing}");
        $this->line("  AI answer already cached (no new AI cost): {$cached}");
        $this->line('  would need a new paid AI read: ' . max(0, $reports->count() - $missing - $cached));

        if (!$apply) {
            $this->comment('Dry run — nothing was changed. Re-run with --apply to reprocess.');

            return self::SUCCESS;
        }

        NotifyOnMarketReportParseFailure::$suppressed = true;
        $outcomes = ['ai' => 0, 'learned' => 0, 'parser' => 0, 'unread' => 0, 'skipped_no_file' => 0];
        foreach ($reports as $r) {
            if (!$reprocessor->rerun($r)) {
                $outcomes['skipped_no_file']++;
                continue;
            }
            $r->refresh();
            $outcomes[$r->read_method] = ($outcomes[$r->read_method] ?? 0) + 1;
            $this->line("  #{$r->id} {$r->file_name} → {$r->read_method}" . ($r->ai_read_note ? " ({$r->ai_read_note})" : ''));
        }
        $this->info('Done: ' . json_encode($outcomes));

        return self::SUCCESS;
    }
}
