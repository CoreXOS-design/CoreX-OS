<?php

use App\Models\DealV2\AgencyServiceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-229 — agency-configurable COC / service-type list for supplier work orders.
 * Each agency owns its own list; seeded with the historical hardcoded set so no
 * configured step or dropdown breaks. Backfills every existing agency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_service_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->string('code', 40);          // stable stored value (service_type)
            $table->string('label', 100);        // agent-facing name
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // One live code per agency. Soft-deleted rows are excluded from the
            // guard in the controller, so uniqueness is enforced there (not a DB
            // constraint that a re-add after archive would trip).
            $table->index(['agency_id', 'code']);
        });

        // Backfill — every existing agency gets the default list so the
        // work-order dropdown is populated on day one (nothing breaks).
        foreach (DB::table('agencies')->pluck('id') as $agencyId) {
            AgencyServiceType::seedDefaultsFor((int) $agencyId);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_service_types');
    }
};
