<?php

namespace App\Console\Commands;

use App\Services\Articles\ArticleLibraryService;
use Illuminate\Console\Command;

/**
 * AT-456 Part C — run the Article Library pipeline (fetch active sources, keep the relevant items,
 * write short briefs). Scheduled daily in routes/console.php; it only does work when the owner's
 * schedule setting says it is due. `--force` runs it regardless (what the Run now button does too).
 *
 * Spend is charged to the platform AI bucket (agency NULL), never an agency.
 *
 * Spec: .ai/specs/at456-ai-tools-scheduler-and-spend-safety.md §C.3.
 */
class RunArticleLibrary extends Command
{
    protected $signature = 'article-library:run {--force : Run now even if the schedule setting is off or not due}';

    protected $description = 'Fetch the Article Library sources and write new short briefs (platform AI spend)';

    public function handle(ArticleLibraryService $library): int
    {
        if (! $this->option('force') && ! $library->isDue()) {
            $this->line('Article Library: not due (schedule: ' . $library->settings()['schedule'] . ').');

            return self::SUCCESS;
        }

        $result = $library->run();

        $this->info($result['message']);
        foreach ($result['errors'] as $error) {
            $this->warn('  - ' . $error);
        }

        return in_array($result['status'], ['failed', 'paused'], true) ? self::FAILURE : self::SUCCESS;
    }
}
