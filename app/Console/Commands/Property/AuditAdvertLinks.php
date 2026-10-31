<?php

declare(strict_types=1);

namespace App\Console\Commands\Property;

use App\Services\Prospecting\Matching\AdvertLinkAudit;
use App\Services\Prospecting\PropertyIdentityReconciler;
use App\Services\Prospecting\TrackedPropertyMerger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * AT-520 4.8: adverts whose own address / suburb contradicts the property file they sit on go back to their own file (journalled). Dry run by default.
 *
 *   php artisan property-identity:audit-advert-links --dry-run | --apply [--agency=] [--exclude-agency=20] [--journal-dir=]
 */
final class AuditAdvertLinks extends Command
{
    protected $signature = 'property-identity:audit-advert-links
        {--dry-run : Count and list samples, write nothing (the default)}
        {--apply : Move the contradicting adverts back to their own file}
        {--agency= : One agency id}
        {--exclude-agency=20 : Comma-separated agency ids to leave out}
        {--journal-dir= : Where the row journal and the report are written (default: storage/app/reports)}';

    protected $description = 'Find adverts that contradict the property file they sit on and move them back (row journal)';

    public function handle(AdvertLinkAudit $audit, PropertyIdentityReconciler $reconciler, TrackedPropertyMerger $merger): int
    {
        if (! app()->environment(['local', 'testing', 'qa'])) {
            $this->error('This only runs on QA / local / testing (APP_ENV is "' . app()->environment() . '").');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $exclude = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('exclude-agency')))));
        $agencies = $this->option('agency') ? [(int) $this->option('agency')] : array_values(array_diff($reconciler->agencies(), $exclude));
        $dir = (string) ($this->option('journal-dir') ?: storage_path('app/reports'));
        File::ensureDirectoryExists($dir);
        $stamp = now()->format('Ymd-His');
        if ($apply) {
            $merger->journalTo($dir . "/advert-link-audit-journal-{$stamp}.jsonl");
        }
        $results = [];
        foreach ($agencies as $agencyId) {
            $results[$agencyId] = $audit->run($agencyId, $apply);
            $r = $results[$agencyId];
            $this->line("agency {$agencyId}: audited {$r['audited']}, contradictions {$r['contradictions']} " . json_encode($r['by_kind']) . ", moved to an existing file {$r['moved_to_existing']}, to a new file {$r['moved_to_new']}, emptied files {$r['files_emptied']}");
        }
        $merger->journalTo(null);
        $report = $dir . '/advert-link-audit-' . ($apply ? 'apply' : 'dry-run') . "-{$stamp}.json";
        File::put($report, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->info("Report written to {$report}");

        return self::SUCCESS;
    }
}
