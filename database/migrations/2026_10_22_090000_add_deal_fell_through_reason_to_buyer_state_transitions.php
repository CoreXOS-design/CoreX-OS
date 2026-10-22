<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-500 (Johan, 22 Oct 2026) — a buyer whose deal falls through / is declined / is archived leaves Won and
 * goes back to the active pipeline. `buyer_state_transitions.reason` is a MySQL ENUM, so the audit reason
 * 'deal_fell_through' must exist before that transition can be written.
 *
 * Reads the column's CURRENT enum list and appends the one value, so it is correct on every box whatever
 * reasons that box's column already holds (QA1, Staging and live were migrated on different days).
 */
return new class extends Migration
{
    private const REASON = 'deal_fell_through';

    public function up(): void
    {
        $values = $this->currentValues();
        if ($values === [] || in_array(self::REASON, $values, true)) {
            return;
        }

        $values[] = self::REASON;
        DB::statement('ALTER TABLE buyer_state_transitions MODIFY reason ENUM(' . $this->quoted($values) . ') NOT NULL');
    }

    public function down(): void
    {
        $values = $this->currentValues();
        if (! in_array(self::REASON, $values, true)) {
            return;
        }

        // Map the new reason onto a legacy value so narrowing the enum is safe.
        DB::table('buyer_state_transitions')->where('reason', self::REASON)->update(['reason' => 'manual_override']);
        $values = array_values(array_diff($values, [self::REASON]));
        DB::statement('ALTER TABLE buyer_state_transitions MODIFY reason ENUM(' . $this->quoted($values) . ') NOT NULL');
    }

    /** @return string[] */
    private function currentValues(): array
    {
        $type = (string) (DB::selectOne(
            "SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'buyer_state_transitions' AND COLUMN_NAME = 'reason'"
        )->t ?? '');

        if (! preg_match('/^enum\((.*)\)$/i', $type, $m)) {
            return [];
        }

        return array_map(fn ($v) => str_replace("''", "'", trim($v, "'")), str_getcsv($m[1], ',', "'"));
    }

    private function quoted(array $values): string
    {
        return implode(',', array_map(fn ($v) => "'" . str_replace("'", "''", $v) . "'", $values));
    }
};
