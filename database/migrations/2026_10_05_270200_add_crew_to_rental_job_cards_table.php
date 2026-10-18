<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-10-05 — Johan's ruling: agents/staff are never crew; the Assign
 * dropdown must list crews, not CoreX users. rental_work_order_id's own
 * decoupling precedent (2026_10_05_250000) is the model here too:
 * rental_job_cards.assigned_user_id is NEVER dropped or rewritten — it
 * stays exactly as every existing job card left it, displayed read-only
 * ("Previously assigned: <name>") wherever a card has a legacy user
 * assignment and no crew. New code never writes to that column again;
 * rental_crew_id is the only thing RentalJobCard::assignCrew() sets from
 * this point on.
 *
 * worker_sign_off_name — Johan: "Worker sign-off... record the signing-
 * off name/crew member as text/selection." A crew member has no CoreX
 * login to sign off themselves; the agent records who on the (already-
 * assigned) crew actually did the work, free text with the crew's own
 * member names offered as a convenience (native <datalist>, show.blade.php)
 * — not a new FK, since a crew member can be unnamed/informal and this is
 * evidence text, not a reporting dimension (that's the later build).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_job_cards', function (Blueprint $table) {
            $table->foreignId('rental_crew_id')->nullable()
                ->after('assigned_user_id')
                ->constrained(indexName: 'rjc_crew_fk')->nullOnDelete();
            $table->string('worker_sign_off_name', 191)->nullable()->after('worker_signed_off_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('rental_job_cards', function (Blueprint $table) {
            $table->dropColumn('worker_sign_off_name');
            $table->dropConstrainedForeignId('rental_crew_id');
        });
    }
};
