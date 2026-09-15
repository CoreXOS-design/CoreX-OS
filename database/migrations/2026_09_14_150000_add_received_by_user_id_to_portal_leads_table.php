<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-Core-Matches, Johan's ruling 6 — "the board shows WHO GOT IT FIRST."
 * `existing_contact_agent_id` already freezes the receiving agent for a
 * RETURNING contact; a brand-new contact's receiving agent was only ever
 * available via a live join to the listing's CURRENT agent_id, which
 * silently rewrites who received a past lead if the listing changes
 * hands. This column is the one canonical, frozen-at-arrival fact for
 * BOTH cases, populated identically by all three ingestion paths.
 *
 * No backfill on existing rows — Johan's explicit instruction: there is
 * no honest way to reconstruct who received an old lead. Existing rows
 * keep answering the old way (live join for a new-contact lead, the
 * existing `existing_contact_agent_id` for a returning one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_leads', function (Blueprint $table) {
            $table->foreignId('received_by_user_id')->nullable()->after('existing_contact_agent_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('portal_leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('received_by_user_id');
        });
    }
};
