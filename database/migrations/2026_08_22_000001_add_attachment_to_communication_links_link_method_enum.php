<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen communication_links.link_method to include 'attachment' (CX-114,
 * 2026-08-22) — CommunicationDealLinkingService::fileAttachments() writes
 * link_method=CommunicationLink::METHOD_ATTACHMENT for the comm->Document
 * provenance link it creates when DR2 files an email's attachments into the
 * deal's document library. The column was defined as an ENUM that did not
 * contain that value — under MySQL STRICT mode the write threw SQLSTATE[01000]
 * 1265 "Data truncated for column 'link_method'", silently swallowed by
 * fileAttachments()'s own per-attachment try/catch (so the document was
 * filed but its provenance link — and therefore dedup and withdraw-on-
 * move/unlink — never recorded).
 *
 * Idempotent and reversible, matching
 * 2026_05_20_000001_add_feedback_captured_to_buyer_activity_log_enum.php.
 */
return new class extends Migration {
    private const OLD_ENUM_VALUES = ['deterministic', 'attorney_ref', 'ellie_suggested', 'manual'];
    private const NEW_VALUE = 'attachment';

    public function up(): void
    {
        if ($this->enumContains(self::NEW_VALUE)) {
            return; // idempotent no-op
        }

        $values = array_merge(self::OLD_ENUM_VALUES, [self::NEW_VALUE]);
        $enum = "ENUM('" . implode("','", $values) . "')";

        DB::statement("ALTER TABLE communication_links MODIFY COLUMN link_method {$enum} NOT NULL");
    }

    public function down(): void
    {
        if (!$this->enumContains(self::NEW_VALUE)) {
            return; // already at the old shape
        }

        // No sensible "nearest existing value" to demote to — attachment-provenance
        // rows are meaningless under any of the OLD_ENUM_VALUES, so they are soft-
        // deleted (non-negotiable #1 — no hard deletes, even in a migration rollback)
        // rather than mislabeled.
        DB::table('communication_links')
            ->where('link_method', self::NEW_VALUE)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        $enum = "ENUM('" . implode("','", self::OLD_ENUM_VALUES) . "')";
        DB::statement("ALTER TABLE communication_links MODIFY COLUMN link_method {$enum} NOT NULL");
    }

    private function enumContains(string $value): bool
    {
        $row = DB::selectOne(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'communication_links'
               AND COLUMN_NAME = 'link_method'"
        );

        return $row !== null && str_contains($row->COLUMN_TYPE, "'{$value}'");
    }
};
