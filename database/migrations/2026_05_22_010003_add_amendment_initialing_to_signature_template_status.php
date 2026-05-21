<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E-Sign V3 Phase 1B (ES-3).
 *
 * Add 'amendment_initialing' to the signature_templates.status ENUM so
 * SignatureService::requeueAllPartiesForInitialing() can flip the
 * template into the focused-initialing state. Doctrine's Blueprint can't
 * mutate ENUMs cleanly, so raw ALTER TABLE is used.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `signature_templates` MODIFY `status` ENUM(
            'draft','ready','signing','awaiting_tenant','awaiting_landlord',
            'awaiting_buyer','awaiting_seller','awaiting_supervisor',
            'awaiting_supervisor_final','pending_agent_approval',
            'returned_to_candidate','completed','expired','declined','rejected',
            'partial','awaiting_deferred','amendment_review','amendment_initialing',
            'cancelled'
        ) NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        // Reverting requires recoding rows that landed in the new state.
        // Forward-safe to leave the enum value in place.
    }
};
