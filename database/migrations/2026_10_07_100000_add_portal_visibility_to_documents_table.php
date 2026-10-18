<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-portal-access.md §2/§5 — AT-445. A document becomes
 * visible in the tenant or landlord portal only once an agent explicitly
 * flags it here, per document, per audience. Independent flags (a lease
 * document might be tenant-visible but not landlord-visible, or vice versa).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->boolean('tenant_portal_visible')->default(false)->after('checklist_item_id');
            $table->boolean('landlord_portal_visible')->default(false)->after('tenant_portal_visible');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['tenant_portal_visible', 'landlord_portal_visible']);
        });
    }
};
