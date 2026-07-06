<?php

declare(strict_types=1);

namespace App\Console\Commands\Communications;

use App\Models\Communications\Communication;
use App\Services\Communications\EmailQuoteStripper;
use Illuminate\Console\Command;

/**
 * AT-182 (thread de-duplication) — derive `body_display` (email reply-quote stripped) for
 * ALL existing archived emails so the thread view reads clean immediately after deploy.
 *
 * DISPLAY-LAYER ONLY: the raw `body_text` (immutable compliance record) is never touched —
 * this only writes the derived `body_display`. Idempotent (recomputes; writes only on
 * change), chunked, and system-context (crosses agencies). Run as part of the deploy.
 */
class BackfillDisplayBodies extends Command
{
    protected $signature = 'comms:backfill-display-bodies {--chunk=500 : Rows per batch}';

    protected $description = 'AT-182 — derive body_display (email quote stripped) for existing archived emails. Raw body_text untouched.';

    public function handle(EmailQuoteStripper $stripper): int
    {
        $chunk = max(50, (int) $this->option('chunk'));
        $scanned = 0;
        $updated = 0;

        Communication::query()
            ->withoutGlobalScopes()               // backfill every agency (system context)
            ->where('channel', Communication::CHANNEL_EMAIL)
            ->whereNotNull('body_text')
            ->chunkById($chunk, function ($rows) use ($stripper, &$scanned, &$updated): void {
                foreach ($rows as $comm) {
                    $scanned++;
                    $result = $stripper->strip($comm->body_text);
                    $new = $result['stripped'] ? $result['display'] : null;

                    if ($new !== $comm->body_display) {
                        // Only the derived column changes; body_text (raw) is left intact.
                        $comm->body_display = $new;
                        $comm->saveQuietly();      // display-only backfill — no observers/events
                        $updated++;
                    }
                }
            });

        $this->info("comms:backfill-display-bodies — scanned {$scanned} email(s), updated {$updated}.");

        return self::SUCCESS;
    }
}
