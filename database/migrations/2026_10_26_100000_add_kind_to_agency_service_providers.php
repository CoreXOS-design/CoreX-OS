<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-452 family (.ai/specs/rentals-faults-work-orders.md section 21.1) - the KIND of a supplier: contractor (every existing row), caretaker / complex
 * manager, owner's own maintenance team, insurer / assessor, body corporate / managing agent. Additive and idempotent; no existing behaviour reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('agency_service_providers', 'kind')) {
            Schema::table('agency_service_providers', function (Blueprint $table) {
                $table->string('kind', 40)->default('contractor');
                $table->index(['agency_id', 'kind'], 'asp_agency_kind_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('agency_service_providers', 'kind')) {
            Schema::table('agency_service_providers', function (Blueprint $table) {
                $table->dropIndex('asp_agency_kind_idx');
                $table->dropColumn('kind');
            });
        }
    }
};
