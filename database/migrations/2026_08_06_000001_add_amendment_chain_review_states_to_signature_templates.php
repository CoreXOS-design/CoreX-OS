<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-373 — recipient wet-ink amend + edit-re-enters-the-loop (cc2 state machine).
 *
 * Adds two additive states to signature_templates.status so the generic approval-chain
 * amendment loop has first-class states (mirrors 2026_05_22_010003 / 2026_07_15_090000 —
 * Doctrine can't mutate an ENUM cleanly, so a raw ALTER TABLE is used):
 *
 *   - amendment_chain_review — a wet-ink edit authored at a party's turn is walking the
 *     ordered approval chain (A1..Am) for approval before the recipient re-initial cascade.
 *   - editor_reacceptance    — a chain node rejected the edit; it was reverted and the
 *     editing party must re-accept the reverted document (second mandatory ECT-Act tick).
 *
 * Additive + forward-safe. Base set = the 24 values live after 2026_07_15_090000
 * (20 original + 4 Track-C lapse states); this adds the 2 new AT-373 states → 26.
 */
return new class extends Migration
{
    /** Full status set AFTER this migration (24 existing + the 2 AT-373 states). */
    private const STATUS_ENUM = "'draft','ready','signing','awaiting_tenant','awaiting_landlord',"
        . "'awaiting_buyer','awaiting_seller','awaiting_supervisor','awaiting_supervisor_final',"
        . "'pending_agent_approval','returned_to_candidate','completed','expired','declined',"
        . "'rejected','partial','awaiting_deferred','amendment_review','amendment_initialing',"
        . "'cancelled','lapsed','extension_proposed','revived','re_lapsed',"
        . "'amendment_chain_review','editor_reacceptance'";

    /** The 24 values live before this migration, for a clean rollback. */
    private const STATUS_ENUM_ORIGINAL = "'draft','ready','signing','awaiting_tenant','awaiting_landlord',"
        . "'awaiting_buyer','awaiting_seller','awaiting_supervisor','awaiting_supervisor_final',"
        . "'pending_agent_approval','returned_to_candidate','completed','expired','declined',"
        . "'rejected','partial','awaiting_deferred','amendment_review','amendment_initialing',"
        . "'cancelled','lapsed','extension_proposed','revived','re_lapsed'";

    public function up(): void
    {
        DB::statement("ALTER TABLE signature_templates MODIFY COLUMN status ENUM(" . self::STATUS_ENUM . ") NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        // Guard the enum shrink: park any row in the new states on a safe live state first, so the
        // narrowing is lossless-ish. amendment_chain_review/editor_reacceptance are mid-loop, so the
        // closest existing resting state is amendment_review.
        DB::table('signature_templates')
            ->whereIn('status', ['amendment_chain_review', 'editor_reacceptance'])
            ->update(['status' => 'amendment_review']);

        DB::statement("ALTER TABLE signature_templates MODIFY COLUMN status ENUM(" . self::STATUS_ENUM_ORIGINAL . ") NOT NULL DEFAULT 'draft'");
    }
};
