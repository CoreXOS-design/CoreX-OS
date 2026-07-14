<?php

namespace App\Services\Communications;

use Illuminate\Support\Facades\DB;

/**
 * AT-246 — heal the historic `communications` rows that carry a manual agent
 * send's owner ONLY in `source_ref` ('manual:user:NN') while `owner_user_id` is
 * NULL. Those rows are invisible to their own sender (Communication::scopeVisibleTo
 * excludes NULL-owner rows) and render as "Agent"/"Unassigned". The write-path
 * fix (OutboundProvisionalLogger::log now stamps owner_user_id) stops NEW rows
 * going NULL; this backfiller repairs the existing ones.
 *
 * Guards (investigation §6):
 *  - only rows with `owner_user_id IS NULL` (never overwrite a real owner);
 *  - only `source_ref REGEXP '^manual:user:[0-9]+$'` (skip 'manual:user:unknown'
 *    and non-manual shapes like 'deal_distribution:user:NN' / 'wa_device:*');
 *  - only where the parsed NN still EXISTS in `users` (the FK is ON DELETE SET
 *    NULL; a merge can hard-delete a user — leave those rows NULL, don't FK-violate);
 *  - touches `updated_at` (the row was corrected) but NEVER the send-clock columns
 *    (`occurred_at` / `captured_at` / `provisional_at`).
 *
 * Reversible + idempotent. Every healed id is recorded in a marker ledger so
 * revert() re-NULLs EXACTLY the rows this backfill changed — critical because,
 * once the write-path fix is live, a fresh manual send carries the same
 * `owner_user_id == NN(source_ref)` shape and is otherwise indistinguishable from
 * a healed legacy row.
 */
class ManualSendOwnerBackfiller
{
    /** Ledger of healed communication ids (created by the AT-246 migration). */
    public const MARKER_TABLE = 'communications_owner_backfill_246';

    /** The manual-send source_ref shape whose trailing integer is the users.id. */
    private const MANUAL_REF_REGEXP = '^manual:user:[0-9]+$';

    /**
     * Heal all currently-NULL manual-send owners. Idempotent: a re-run records no
     * new targets and updates nothing.
     *
     * @return array{updated:int, skipped_no_user:int, total_null_manual:int}
     */
    public function heal(): array
    {
        $totalNullManual = $this->nullManualQuery()->count();

        // Record the rows we are about to heal (valid-user targets only), skipping
        // any already in the ledger so a re-run is a no-op.
        DB::statement(
            'INSERT INTO ' . self::MARKER_TABLE . ' (communication_id, created_at)
             SELECT c.id, NOW() FROM communications c
             WHERE c.owner_user_id IS NULL
               AND c.source_ref REGEXP ?
               AND EXISTS (SELECT 1 FROM users u WHERE u.id = CAST(SUBSTRING_INDEX(c.source_ref, ' . "':'" . ', -1) AS UNSIGNED))
               AND NOT EXISTS (SELECT 1 FROM ' . self::MARKER_TABLE . ' m WHERE m.communication_id = c.id)',
            [self::MANUAL_REF_REGEXP]
        );

        // Set the first-class owner from the parsed source_ref NN. Guarded again on
        // IS NULL so we never clobber a real owner, even if the ledger is stale.
        $updated = DB::update(
            'UPDATE communications c
             JOIN ' . self::MARKER_TABLE . ' m ON m.communication_id = c.id
             SET c.owner_user_id = CAST(SUBSTRING_INDEX(c.source_ref, ' . "':'" . ', -1) AS UNSIGNED),
                 c.updated_at = NOW()
             WHERE c.owner_user_id IS NULL'
        );

        // Whatever is STILL NULL + manual after the update is un-healable (the NN
        // user no longer exists) — report it honestly, never silently drop it.
        $skippedNoUser = $this->nullManualQuery()->count();

        return [
            'updated'           => $updated,
            'skipped_no_user'   => $skippedNoUser,
            'total_null_manual' => $totalNullManual,
        ];
    }

    /**
     * Reverse the heal: re-NULL exactly the ledger's rows and clear the ledger.
     * @return int rows re-NULLed
     */
    public function revert(): int
    {
        $reverted = DB::update(
            'UPDATE communications c
             JOIN ' . self::MARKER_TABLE . ' m ON m.communication_id = c.id
             SET c.owner_user_id = NULL, c.updated_at = NOW()'
        );

        DB::table(self::MARKER_TABLE)->delete();

        return $reverted;
    }

    private function nullManualQuery()
    {
        return DB::table('communications')
            ->whereNull('owner_user_id')
            ->where('source_ref', 'REGEXP', self::MANUAL_REF_REGEXP);
    }
}
