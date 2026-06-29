<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-122 — owning-agent provenance on the Communication Archive.
 *
 * Records WHICH agent's mailbox/device a message was ingested through:
 *   - email → the CommunicationMailbox's user_id
 *   - WhatsApp → the communication_wa_devices device's user_id
 *
 * This is PROVENANCE ONLY. Nothing reads or gates on it yet — the future
 * AT-118 access gate will key per-agent visibility off this column. Nullable:
 * a mailbox with no owner (agency-level mailbox) leaves it NULL gracefully and
 * ingest never fails on an unresolved owner. nullOnDelete keeps the archive row
 * (no hard deletes anywhere) if the user record is ever removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communications', function (Blueprint $table) {
            $table->foreignId('owner_user_id')
                ->nullable()
                ->after('source_ref')
                ->constrained('users', 'id', 'comm_owner_user_fk')
                ->nullOnDelete();

            $table->index(['agency_id', 'owner_user_id'], 'comm_agency_owner_idx');
        });
    }

    public function down(): void
    {
        Schema::table('communications', function (Blueprint $table) {
            $table->dropIndex('comm_agency_owner_idx');
            $table->dropForeign('comm_owner_user_fk');
            $table->dropColumn('owner_user_id');
        });
    }
};
