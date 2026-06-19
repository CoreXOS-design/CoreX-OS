<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-61 — address-only seller-outreach sends.
 *
 * A pitch can now be composed off a contact's captured structured address
 * (AT-60) with NO linked Property. Such a send has no property_id, so:
 *   - property_id becomes nullable (FK kept; a null value is simply
 *     unconstrained), and
 *   - the composed address + suburb the pitch referenced are recorded on the
 *     send itself so the log stays complete and auditable without a Property.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop the FK so the column can be altered to nullable, then re-add it.
        Schema::table('seller_outreach_sends', function (Blueprint $table) {
            $table->dropForeign(['property_id']);
        });

        Schema::table('seller_outreach_sends', function (Blueprint $table) {
            $table->unsignedBigInteger('property_id')->nullable()->change();

            $table->string('address_snapshot', 255)->nullable()->after('recipient_email_snapshot');
            $table->string('suburb_snapshot', 120)->nullable()->after('address_snapshot');

            $table->foreign('property_id')
                ->references('id')->on('properties')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('seller_outreach_sends', function (Blueprint $table) {
            $table->dropForeign(['property_id']);
            $table->dropColumn(['address_snapshot', 'suburb_snapshot']);
        });

        Schema::table('seller_outreach_sends', function (Blueprint $table) {
            // Restore the NOT NULL constraint. Any address-only rows must be
            // resolved before rolling back (none exist on a fresh rollback).
            $table->unsignedBigInteger('property_id')->nullable(false)->change();

            $table->foreign('property_id')
                ->references('id')->on('properties')
                ->cascadeOnDelete();
        });
    }
};
