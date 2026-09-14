<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E-sign compliance approval gate — spec §5.4.
 *
 * Two additive states on signature_templates.status (raw ALTER TABLE, mirroring
 * 2026_08_06_000001 — Doctrine cannot mutate an ENUM cleanly):
 *
 *   - approval_pending  — the sender has signed; the document is HELD before it leaves the agency
 *                         until a Reporting Officer approves it (agency route = ro_co).
 *   - approval_declined — an officer declined with a reason; the sender may request approval
 *                         again, cancel, or the Compliance Officer may override.
 *
 * Base set = the 26 values live after 2026_08_06_000001; this adds 2 → 28.
 */
return new class extends Migration
{
    private const STATUS_ENUM = "'draft','ready','signing','awaiting_tenant','awaiting_landlord',"
        . "'awaiting_buyer','awaiting_seller','awaiting_supervisor','awaiting_supervisor_final',"
        . "'pending_agent_approval','returned_to_candidate','completed','expired','declined',"
        . "'rejected','partial','awaiting_deferred','amendment_review','amendment_initialing',"
        . "'cancelled','lapsed','extension_proposed','revived','re_lapsed',"
        . "'amendment_chain_review','editor_reacceptance',"
        . "'approval_pending','approval_declined'";

    private const STATUS_ENUM_ORIGINAL = "'draft','ready','signing','awaiting_tenant','awaiting_landlord',"
        . "'awaiting_buyer','awaiting_seller','awaiting_supervisor','awaiting_supervisor_final',"
        . "'pending_agent_approval','returned_to_candidate','completed','expired','declined',"
        . "'rejected','partial','awaiting_deferred','amendment_review','amendment_initialing',"
        . "'cancelled','lapsed','extension_proposed','revived','re_lapsed',"
        . "'amendment_chain_review','editor_reacceptance'";

    public function up(): void
    {
        DB::statement("ALTER TABLE signature_templates MODIFY COLUMN status ENUM(" . self::STATUS_ENUM . ") NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        // Park held / declined rows on 'ready' (the sender has signed but nothing has left the
        // agency) so the enum shrink is lossless for the ceremony.
        DB::table('signature_templates')
            ->whereIn('status', ['approval_pending', 'approval_declined'])
            ->update(['status' => 'ready']);

        DB::statement("ALTER TABLE signature_templates MODIFY COLUMN status ENUM(" . self::STATUS_ENUM_ORIGINAL . ") NOT NULL DEFAULT 'draft'");
    }
};
