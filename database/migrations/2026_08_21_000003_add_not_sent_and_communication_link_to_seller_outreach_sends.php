<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-323 — the outreach pitch-send used to be born outcome='sent' the moment the
 * pitch was submitted, with no way for the agent to say "WhatsApp never actually
 * went out". This adds:
 *
 *  1. a 'not_sent' value to the seller_outreach_sends.outcome enum — the honest
 *     terminal state the "No, I didn't send it" answer records on the sent page;
 *  2. a nullable communication_id link to the mirrored provisional Communication
 *     (created by OutboundProvisionalLogger), so the SAME true state can be
 *     mirrored to the comms archive (send_status -> not_delivered) — the tile
 *     counts and the outreach outcome never disagree.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL enum widen — doctrine/dbal cannot alter enums, so a raw MODIFY.
        // Preserves NOT NULL + default 'sent'; only appends 'not_sent'.
        DB::statement(
            "ALTER TABLE seller_outreach_sends MODIFY COLUMN outcome "
            . "ENUM('sent','clicked','replied','booked','no_response','not_interested','bounced','not_sent') "
            . "NOT NULL DEFAULT 'sent'"
        );

        Schema::table('seller_outreach_sends', function (Blueprint $table) {
            if (!Schema::hasColumn('seller_outreach_sends', 'communication_id')) {
                $table->unsignedBigInteger('communication_id')->nullable()->after('template_id');
                $table->foreign('communication_id')
                    ->references('id')->on('communications')
                    ->nullOnDelete();
                $table->index('communication_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('seller_outreach_sends', function (Blueprint $table) {
            if (Schema::hasColumn('seller_outreach_sends', 'communication_id')) {
                $table->dropForeign(['communication_id']);
                $table->dropIndex(['communication_id']);
                $table->dropColumn('communication_id');
            }
        });

        // Roll back any rows using the new value BEFORE narrowing the enum, else
        // MySQL would coerce them to '' (data loss). not_sent -> no_response is the
        // closest legacy meaning ("did not progress").
        DB::table('seller_outreach_sends')->where('outcome', 'not_sent')->update(['outcome' => 'no_response']);

        DB::statement(
            "ALTER TABLE seller_outreach_sends MODIFY COLUMN outcome "
            . "ENUM('sent','clicked','replied','booked','no_response','not_interested','bounced') "
            . "NOT NULL DEFAULT 'sent'"
        );
    }
};
