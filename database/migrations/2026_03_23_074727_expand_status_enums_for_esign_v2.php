<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Cross-driver enum widen (MySQL prod + SQLite test DB). status columns
    // created via $table->enum() in 2026_02_26_600001 / 2026_02_26_600003.
    public function up(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->enum('status', [
                'waiting', 'pending', 'viewed', 'partially_signed',
                'completed', 'expired', 'declined', 'deferred',
            ])->default('waiting')->change();
        });

        Schema::table('signature_templates', function (Blueprint $table) {
            $table->enum('status', [
                'draft', 'ready', 'signing',
                'awaiting_tenant', 'awaiting_landlord', 'awaiting_buyer', 'awaiting_seller',
                'awaiting_supervisor', 'awaiting_supervisor_final',
                'pending_agent_approval', 'returned_to_candidate',
                'completed', 'expired', 'declined', 'rejected',
                'partial', 'awaiting_deferred', 'amendment_review',
            ])->default('draft')->change();
        });
    }

    public function down(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->enum('status', [
                'waiting', 'pending', 'viewed', 'partially_signed',
                'completed', 'expired', 'declined',
            ])->default('waiting')->change();
        });

        Schema::table('signature_templates', function (Blueprint $table) {
            $table->enum('status', [
                'draft', 'ready', 'signing',
                'awaiting_tenant', 'awaiting_landlord', 'awaiting_buyer', 'awaiting_seller',
                'awaiting_supervisor', 'awaiting_supervisor_final',
                'pending_agent_approval', 'returned_to_candidate',
                'completed', 'expired', 'declined', 'rejected',
            ])->default('draft')->change();
        });
    }
};
