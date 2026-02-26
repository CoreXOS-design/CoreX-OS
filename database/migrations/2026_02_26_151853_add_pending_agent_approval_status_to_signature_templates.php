<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add 'pending_agent_approval' to the status CHECK constraint on signature_templates.
     * SQLite doesn't support ALTER TABLE for CHECK constraints, so we modify the schema directly.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $row = DB::selectOne(
                "SELECT sql FROM sqlite_master WHERE type='table' AND name='signature_templates'"
            );

            if (! $row) {
                return;
            }

            $oldCheck = "check (\"status\" in ('draft', 'ready', 'signing', 'awaiting_tenant', 'awaiting_landlord', 'completed', 'expired', 'declined'))";
            $newCheck = "check (\"status\" in ('draft', 'ready', 'signing', 'awaiting_tenant', 'awaiting_landlord', 'pending_agent_approval', 'completed', 'expired', 'declined'))";

            $newSql = str_replace($oldCheck, $newCheck, $row->sql);

            if ($newSql === $row->sql) {
                // Constraint text didn't match — nothing to do (already updated or different format)
                return;
            }

            DB::statement('PRAGMA writable_schema = ON');
            DB::statement(
                "UPDATE sqlite_master SET sql = ? WHERE type='table' AND name='signature_templates'",
                [$newSql]
            );
            DB::statement('PRAGMA writable_schema = OFF');

            // Verify database integrity after schema modification
            $check = DB::selectOne('PRAGMA integrity_check');
            if ($check->integrity_check !== 'ok') {
                throw new \RuntimeException('SQLite integrity check failed after schema modification: ' . $check->integrity_check);
            }
        } else {
            // MySQL: modify the enum column to include the new value
            DB::statement("ALTER TABLE signature_templates MODIFY COLUMN status ENUM('draft','ready','signing','awaiting_tenant','awaiting_landlord','pending_agent_approval','completed','expired','declined') NOT NULL DEFAULT 'draft'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $row = DB::selectOne(
                "SELECT sql FROM sqlite_master WHERE type='table' AND name='signature_templates'"
            );

            if (! $row) {
                return;
            }

            $newCheck = "check (\"status\" in ('draft', 'ready', 'signing', 'awaiting_tenant', 'awaiting_landlord', 'pending_agent_approval', 'completed', 'expired', 'declined'))";
            $oldCheck = "check (\"status\" in ('draft', 'ready', 'signing', 'awaiting_tenant', 'awaiting_landlord', 'completed', 'expired', 'declined'))";

            $revertSql = str_replace($newCheck, $oldCheck, $row->sql);

            if ($revertSql === $row->sql) {
                return;
            }

            DB::statement('PRAGMA writable_schema = ON');
            DB::statement(
                "UPDATE sqlite_master SET sql = ? WHERE type='table' AND name='signature_templates'",
                [$revertSql]
            );
            DB::statement('PRAGMA writable_schema = OFF');
        } else {
            DB::statement("ALTER TABLE signature_templates MODIFY COLUMN status ENUM('draft','ready','signing','awaiting_tenant','awaiting_landlord','completed','expired','declined') NOT NULL DEFAULT 'draft'");
        }
    }
};
