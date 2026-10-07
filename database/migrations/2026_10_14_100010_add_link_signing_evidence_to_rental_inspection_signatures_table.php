<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §46 — what is recorded when a party signs from their own link (or on the agent's
 * device): how, from where, on what, by which link, and what they typed and ticked. All nullable: a signature recorded
 * the old way (on the agent's recording screen, on paper) carries none of it and is read exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_signatures', function (Blueprint $table) {
            // 'link' = the party's own phone/browser; 'agent_device' = the party signed on the agent's logged-in device.
            $table->string('signed_via', 20)->nullable();
            $table->unsignedBigInteger('signing_link_id')->nullable()->index();
            $table->unsignedBigInteger('signed_on_device_by_user_id')->nullable();
            $table->string('signed_typed_name', 191)->nullable();
            $table->timestamp('read_confirmed_at')->nullable();
            $table->text('signer_comment')->nullable();
            $table->string('signed_ip', 45)->nullable();
            $table->string('signed_user_agent', 500)->nullable();
            // A fingerprint of the report as the party saw it when they signed (see RentalInspection::reportFingerprint()).
            $table->string('signed_report_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_signatures', function (Blueprint $table) {
            $table->dropColumn([
                'signed_via', 'signing_link_id', 'signed_on_device_by_user_id', 'signed_typed_name',
                'read_confirmed_at', 'signer_comment', 'signed_ip', 'signed_user_agent', 'signed_report_fingerprint',
            ]);
        });
    }
};
