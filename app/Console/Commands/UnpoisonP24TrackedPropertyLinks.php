<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Prospecting\TrackedProperty;
use Illuminate\Console\Command;

/**
 * MIC CRISIS ITEM 3 (2026-08-19) — one-shot cleanup for TrackedProperty rows
 * poisoned by Property24SyndicationService::writeP24ExternalRef()'s old
 * unconditional promoted_to_property_id write (fixed alongside this command
 * — see that method's comment for the full root cause).
 *
 * That code stamped promoted_to_property_id on WHATEVER TrackedProperty
 * matchOrCreate() returned — including one it merely MATCHED via a loose
 * strategy, not one it created from the property's own facts — without ever
 * setting promoted_at/promoted_by_user_id/status the way the real
 * TrackedPropertyMatchOrCreateService::promoteToStock() flow does. A row
 * poisoned this way then read as "already in agency stock" in MIC's
 * claim/promote flow and redirected the agent to the WRONG property.
 *
 * Signature of a poisoned row: promoted_to_property_id IS NOT NULL but
 * promoted_at IS NULL — every row that went through the real promote flow
 * always has both set together (see promoteToStock()'s own update() call).
 * This command clears promoted_to_property_id (and the harmless companion
 * columns, in case they were ever set independently) on every row matching
 * that signature, restoring it to "not promoted" — the true state, since
 * none of these were ever actually promoted by a user. No hard delete, no
 * other column touched; the TrackedProperty row itself and its full
 * source_chain audit trail are untouched.
 *
 * Idempotent — safe to re-run; a second run finds nothing left to fix.
 */
final class UnpoisonP24TrackedPropertyLinks extends Command
{
    protected $signature = 'tracked:unpoison-p24-links
                            {--agency= : Limit to a specific agency_id}
                            {--dry-run : Report the affected rows only, no writes}';

    protected $description = 'Clear promoted_to_property_id on TrackedProperty rows poisoned by the old writeP24ExternalRef bug (promoted_to_property_id set but promoted_at never stamped)';

    public function handle(): int
    {
        $agencyId = $this->option('agency') ? (int) $this->option('agency') : null;
        $dryRun = (bool) $this->option('dry-run');

        $query = TrackedProperty::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereNotNull('promoted_to_property_id')
            ->whereNull('promoted_at');

        if ($agencyId !== null) {
            $query->where('agency_id', $agencyId);
        }

        $rows = $query->get(['id', 'agency_id', 'promoted_to_property_id', 'erf_number', 'street_number', 'street_name', 'suburb']);

        $this->info('Poisoned rows found (promoted_to_property_id set, promoted_at NULL): ' . $rows->count()
            . ($agencyId !== null ? " [agency #{$agencyId}]" : '')
            . ($dryRun ? ' [DRY RUN — no writes]' : ''));

        foreach ($rows as $tp) {
            $this->line("  tp#{$tp->id} (agency #{$tp->agency_id}) was wrongly linked to property #{$tp->promoted_to_property_id}"
                . ' — ' . trim(($tp->street_number ?? '') . ' ' . ($tp->street_name ?? '') . ' erf=' . ($tp->erf_number ?? '') . ' ' . ($tp->suburb ?? '')));
        }

        if ($dryRun || $rows->isEmpty()) {
            return self::SUCCESS;
        }

        $ids = $rows->pluck('id')->all();
        $updated = TrackedProperty::withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->update(['promoted_to_property_id' => null]);

        $this->info("Cleared promoted_to_property_id on {$updated} row(s).");

        return self::SUCCESS;
    }
}
