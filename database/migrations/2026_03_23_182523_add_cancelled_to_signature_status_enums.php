<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // signature_requests: add 'cancelled' to status enum
        DB::statement("ALTER TABLE signature_requests MODIFY COLUMN status ENUM('waiting','pending','viewed','partially_signed','completed','expired','declined','deferred','cancelled') NOT NULL DEFAULT 'waiting'");

        // signature_templates: add 'cancelled' to status enum
        DB::statement("ALTER TABLE signature_templates MODIFY COLUMN status ENUM('draft','ready','signing','awaiting_tenant','awaiting_landlord','awaiting_buyer','awaiting_seller','awaiting_supervisor','awaiting_supervisor_final','pending_agent_approval','returned_to_candidate','completed','expired','declined','rejected','partial','awaiting_deferred','amendment_review','cancelled') NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE signature_requests MODIFY COLUMN status ENUM('waiting','pending','viewed','partially_signed','completed','expired','declined','deferred') NOT NULL DEFAULT 'waiting'");

        DB::statement("ALTER TABLE signature_templates MODIFY COLUMN status ENUM('draft','ready','signing','awaiting_tenant','awaiting_landlord','awaiting_buyer','awaiting_seller','awaiting_supervisor','awaiting_supervisor_final','pending_agent_approval','returned_to_candidate','completed','expired','declined','rejected','partial','awaiting_deferred','amendment_review') NOT NULL DEFAULT 'draft'");
    }
};
