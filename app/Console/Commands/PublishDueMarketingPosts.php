<?php

namespace App\Console\Commands;

use App\Services\Marketing\ScheduledPostPublisher;
use Illuminate\Console\Command;

/**
 * AT-456 Part D — publish every scheduled social post whose time has come. Registered every minute in
 * routes/console.php (withoutOverlapping + onOneServer); also runnable by hand:
 *
 *     php artisan marketing:publish-due
 *
 * Needs no queue worker: each claimed post is published inline. Each post is claimed with an atomic
 * UPDATE first, so overlapping runs publish once. A failed post is recorded with a reason and the agent
 * is told; it is never retried automatically.
 *
 * Spec: .ai/specs/at456-ai-tools-scheduler-and-spend-safety.md §D.3.
 */
class PublishDueMarketingPosts extends Command
{
    protected $signature = 'marketing:publish-due {--limit=50 : Most posts to publish in one run}';

    protected $description = 'Publish scheduled Facebook / Instagram posts that are due (Ad Manager → Scheduled Posts)';

    public function handle(ScheduledPostPublisher $publisher): int
    {
        $r = $publisher->runDue(max(1, (int) $this->option('limit')));

        $this->info(sprintf(
            'Scheduled posts: %d published, %d failed, %d skipped (already handled), %d interrupted.',
            $r['published'], $r['failed'], $r['skipped'], $r['interrupted'],
        ));

        return self::SUCCESS;
    }
}
