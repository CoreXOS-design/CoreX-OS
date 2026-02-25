<?php

namespace App\Console\Commands;

use App\Services\Articles\ArticleScraperService;
use Illuminate\Console\Command;

class ScrapeArticles extends Command
{
    protected $signature = 'articles:scrape';

    protected $description = 'Scrape RSS feeds for property news articles and store them in the article pool';

    public function handle(): int
    {
        $this->info('Scraping article feeds...');

        $service = new ArticleScraperService();
        $stats   = $service->scrapeAll();

        $this->info("Feeds attempted: {$stats['feeds_attempted']}");
        $this->info("Feeds succeeded: {$stats['feeds_succeeded']}");
        $this->info("Articles upserted: {$stats['articles_upserted']}");

        if (!empty($stats['errors'])) {
            $this->warn('Errors:');
            foreach ($stats['errors'] as $error) {
                $this->warn("  - {$error}");
            }
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
