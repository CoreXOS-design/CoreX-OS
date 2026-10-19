<?php

namespace App\Console\Commands;

use App\Services\Marketing\ScheduledPostImages;
use Illuminate\Console\Command;

/**
 * AT-456 Part D — remove the picture files of scheduled posts once nobody needs them: published + 30
 * days (cancelled / failed: 30 days after they were last touched), and uploads that never became a
 * post after 2 days. Pictures of posts that are still scheduled are never touched. Scheduled daily in
 * routes/console.php; also runnable by hand: php artisan marketing:purge-scheduled-images
 */
class PurgeScheduledPostImages extends Command
{
    protected $signature = 'marketing:purge-scheduled-images';

    protected $description = 'Delete old scheduled-post pictures (published + 30 days; unused uploads after 2 days)';

    public function handle(ScheduledPostImages $images): int
    {
        $r = $images->purgeExpired();

        $this->info("Scheduled-post pictures removed: {$r['posts']} for finished posts, {$r['orphans']} unused uploads.");

        return self::SUCCESS;
    }
}
