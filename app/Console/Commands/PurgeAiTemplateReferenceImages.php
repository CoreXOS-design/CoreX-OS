<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AdTemplates\AiTemplateBuilderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * AT-456 Part B (spec §B.3) — reference images uploaded to the AI Template Builder are
 * kept privately for 7 days, then the FILE is deleted (the usage row stays).
 *
 *   php artisan ai-templates:purge-reference-images
 *
 * Scheduled daily in routes/console.php; safe to run by hand any time — it only touches
 * images older than the retention window.
 */
final class PurgeAiTemplateReferenceImages extends Command
{
    protected $signature = 'ai-templates:purge-reference-images';

    protected $description = 'Delete AI Template Builder reference images older than 7 days.';

    public function handle(AiTemplateBuilderService $builder): int
    {
        $purged = $builder->purgeExpiredReferences();

        $this->line("ai-templates:purge-reference-images — {$purged} reference image(s) deleted.");
        Log::info('ai-templates:purge-reference-images run', ['purged' => $purged]);

        return self::SUCCESS;
    }
}
