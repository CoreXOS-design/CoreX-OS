<?php

namespace App\Console\Commands;

use App\Models\ArticlePool;
use App\Models\LibraryArticle;
use App\Services\Articles\ArticleLibraryService;
use Illuminate\Console\Command;

/**
 * AT-509 — one-off: run the original-wording check over the briefs already in the library and hide any that fail.
 * Each brief is compared with the feed text stored for it (article_pool: title + snippet — the full-text part of the
 * feed is not stored, so this check is a little less strict than the one at write time). Hiding is the normal
 * reversible Hide: the owner can Unhide from the Articles tab. Nothing is deleted, no AI is called.
 *
 * Spec: .ai/specs/at456-ai-tools-scheduler-and-spend-safety.md §C.8.
 */
class RescoreArticleLibrary extends Command
{
    protected $signature = 'article-library:rescore';

    protected $description = 'Score existing Article Library briefs for original wording and hide any that copy their source';

    public function handle(ArticleLibraryService $library): int
    {
        $limits  = $library->originalitySettings();
        $scored  = 0;
        $hidden  = 0;
        $noText  = 0;

        LibraryArticle::query()->orderBy('id')->each(function (LibraryArticle $a) use ($library, $limits, &$scored, &$hidden, &$noText) {
            $pool = ArticlePool::query()->where('url_hash', $a->source_url_hash)->first();
            if ($pool === null) {
                $noText++;
                $this->warn("  #{$a->id} \"{$a->title}\": no stored source text to compare with — left as it is.");

                return;
            }

            $score = $library->originalityScore((string) $a->body, trim($pool->title . "\n" . $pool->snippet));
            $a->originality_overlap     = $score['overlap'];
            $a->originality_longest_run = $score['longest_run'];
            $scored++;

            if (! $library->isOriginal($score, $limits) && $a->status === LibraryArticle::STATUS_READY) {
                $a->status = LibraryArticle::STATUS_HIDDEN;
                $hidden++;
                $this->line(sprintf('  #%d hidden: "%s" — overlap %d%%, %d words copied in a row', $a->id, $a->title, round($score['overlap'] * 100), $score['longest_run']));
            }

            $a->save();
        });

        $this->info("Article Library rescore: {$scored} scored, {$hidden} hidden, {$noText} without stored source text.");

        return self::SUCCESS;
    }
}
