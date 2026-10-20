<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-459 (.ai/specs/rental-work-orders.md §14.32, rentals-faults-work-orders.md §25) — the single "Access notes" box becomes three
 * things the crew and the contractor can act on: WHO lets them in (name), HOW to reach them (phone) and the NOTE (gate code, dog on site).
 *
 * The access contact lives on the WORK ORDER (its job card, the crew link and the contractor link all read it from there). A job card with
 * no work order keeps its own copy of name + phone; its note stays in the existing `access_notes` column, so nothing already typed moves.
 *
 * Back-fill: a work order whose job card already holds an access note gets that note as its own, so the contractor link (which used to
 * read the property's note) and the crew link never lose what the office typed. Nothing is deleted; the card's column is left as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_work_orders', function (Blueprint $table) {
            $table->string('access_contact_name', 191)->nullable();
            $table->string('access_contact_phone', 50)->nullable();
            $table->text('access_note')->nullable();
        });

        Schema::table('rental_job_cards', function (Blueprint $table) {
            $table->string('access_contact_name', 191)->nullable();
            $table->string('access_contact_phone', 50)->nullable();
        });

        DB::statement(
            'UPDATE rental_work_orders wo
             JOIN rental_job_cards jc ON jc.rental_work_order_id = wo.id
             SET wo.access_note = jc.access_notes
             WHERE wo.access_note IS NULL AND jc.access_notes IS NOT NULL AND jc.access_notes <> \'\''
        );
    }

    public function down(): void
    {
        Schema::table('rental_job_cards', function (Blueprint $table) {
            $table->dropColumn(['access_contact_name', 'access_contact_phone']);
        });
        Schema::table('rental_work_orders', function (Blueprint $table) {
            $table->dropColumn(['access_contact_name', 'access_contact_phone', 'access_note']);
        });
    }
};
