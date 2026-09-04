<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cleanup for `cds_drafts` rows that Template::canonicalFieldMappings()'s
 * tier-1 lookup can never legitimately return again, so leaving them alive
 * with status='draft' forever is just accumulating dead weight in the table
 * the CDS builder queries on every template read.
 *
 * Two independent categories, both soft-deleted (never hard-deleted — CLAUDE.md
 * non-negotiable #1):
 *
 *   1. Idle drafts — status IN ('draft','abandoned'), untouched for
 *      --days (default 21). `TemplateController::cdsGenerate()` already
 *      flips a user's OWN superseded drafts to 'abandoned' on save, but a
 *      genuinely different user's independent in-progress draft is never
 *      touched by someone else's save (see that method's docblock) — it
 *      only goes stale by simple neglect. This pass is what eventually
 *      retires those.
 *   2. Orphaned drafts — `source_template_id` no longer resolves to a live
 *      (non-trashed) `docuperfect_templates` row. Unreachable by any normal
 *      flow (Template::findOrFail 404s before canonicalFieldMappings() is
 *      ever called), so these are pruned regardless of age.
 *
 * Soft-deleting here is safe in a way the same call on the cdsGenerate() hot
 * path was NOT (a8af5d10a): nobody's browser tab is realistically still
 * open, --days later, on a draft nobody has touched in that long — the 404
 * regression that commit fixed was specifically about deleting the draft a
 * user's live tab was sitting on the instant they saved.
 *
 *   php artisan docuperfect:prune-abandoned-cds-drafts
 *   php artisan docuperfect:prune-abandoned-cds-drafts --days=45 --dry-run
 */
class PruneAbandonedCdsDrafts extends Command
{
    protected $signature = 'docuperfect:prune-abandoned-cds-drafts
        {--days=21 : Soft-delete draft/abandoned rows untouched for this many days}
        {--dry-run : Report what would be pruned without pruning}';

    protected $description = 'Soft-delete idle CDS builder drafts and drafts orphaned by a deleted template.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $now = now();

        $idle = DB::table('cds_drafts')
            ->whereIn('status', ['draft', 'abandoned'])
            ->whereNull('deleted_at')
            ->where('updated_at', '<', $cutoff);
        $idleCount = $idle->count();
        $this->info("Idle cds_drafts rows (status draft/abandoned, untouched {$days}d+): {$idleCount}");
        if ($idleCount > 0 && !$dry) {
            $idle->update(['deleted_at' => $now]);
            $this->line("  soft-deleted {$idleCount} idle draft(s).");
        } elseif ($idleCount > 0) {
            $this->line('  (dry run — not deleted)');
        }

        $orphans = DB::table('cds_drafts')
            ->whereNull('deleted_at')
            ->whereNotNull('source_template_id')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('docuperfect_templates')
                    ->whereColumn('docuperfect_templates.id', 'cds_drafts.source_template_id')
                    ->whereNull('docuperfect_templates.deleted_at');
            });
        $orphanCount = $orphans->count();
        $this->info("Orphaned cds_drafts rows (source template deleted/missing): {$orphanCount}");
        if ($orphanCount > 0 && !$dry) {
            $orphans->update(['deleted_at' => $now]);
            $this->line("  soft-deleted {$orphanCount} orphaned draft(s).");
        } elseif ($orphanCount > 0) {
            $this->line('  (dry run — not deleted)');
        }

        return self::SUCCESS;
    }
}
