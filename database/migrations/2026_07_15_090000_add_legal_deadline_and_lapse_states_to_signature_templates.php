<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Track C (HD-9/11) — a ceremony has a LEGAL deadline, distinct from its link TTL.
 *
 * The 14-day token expiry (`signature_requests.token_expires_at`) is a link-freshness clock. A
 * mandate/OTP also has a LEGAL clock — the mandate's expiry, the OTP's irrevocable date — and a
 * signature collected after that clock has run is null and void (§11-A). Nothing recorded that clock
 * before; this adds it, plus the first-class lapse states the sweeper records.
 */
return new class extends Migration
{
    /** The full status set AFTER this migration (existing 20 + the 4 lapse states). */
    private const STATUS_ENUM = "'draft','ready','signing','awaiting_tenant','awaiting_landlord',"
        . "'awaiting_buyer','awaiting_seller','awaiting_supervisor','awaiting_supervisor_final',"
        . "'pending_agent_approval','returned_to_candidate','completed','expired','declined',"
        . "'rejected','partial','awaiting_deferred','amendment_review','amendment_initialing',"
        . "'cancelled','lapsed','extension_proposed','revived','re_lapsed'";

    /** The original 20, for a clean rollback. */
    private const STATUS_ENUM_ORIGINAL = "'draft','ready','signing','awaiting_tenant','awaiting_landlord',"
        . "'awaiting_buyer','awaiting_seller','awaiting_supervisor','awaiting_supervisor_final',"
        . "'pending_agent_approval','returned_to_candidate','completed','expired','declined',"
        . "'rejected','partial','awaiting_deferred','amendment_review','amendment_initialing','cancelled'";

    public function up(): void
    {
        Schema::table('signature_templates', function (Blueprint $table) {
            $table->timestamp('legal_deadline_at')->nullable()->after('completed_at')
                ->comment('Track C — the LEGAL last-valid-signature date (mandate expiry / OTP irrevocable). A mark after this is void. Distinct from the 14-day link TTL.');
            $table->string('deadline_source', 32)->nullable()->after('legal_deadline_at')
                ->comment('Track C — where legal_deadline_at came from: mandate_expiry | otp_irrevocable | manual.');
            $table->index('legal_deadline_at');
        });

        DB::statement("ALTER TABLE signature_templates MODIFY COLUMN status ENUM(" . self::STATUS_ENUM . ") NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        // Guard the enum shrink: any row already carrying a new state would be truncated by the
        // narrowing. Park them on 'expired' (a terminal state) first so the rollback is lossless-ish.
        DB::table('signature_templates')
            ->whereIn('status', ['lapsed', 'extension_proposed', 'revived', 're_lapsed'])
            ->update(['status' => 'expired']);

        DB::statement("ALTER TABLE signature_templates MODIFY COLUMN status ENUM(" . self::STATUS_ENUM_ORIGINAL . ") NOT NULL DEFAULT 'draft'");

        Schema::table('signature_templates', function (Blueprint $table) {
            $table->dropIndex(['legal_deadline_at']);
            $table->dropColumn(['legal_deadline_at', 'deadline_source']);
        });
    }
};
