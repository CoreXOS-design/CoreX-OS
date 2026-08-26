<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-319 — a supplier can be MORE THAN ONE type. This pivot links a directory
 * supplier (agency_service_providers) to 1..n agency-configurable service types
 * (agency_service_types.code). The work-order panel filters the supplier dropdown
 * by the required type against this list. The legacy single `specialty` enum on
 * agency_service_providers is UNTOUCHED (attorney/bond flows still use it).
 *
 * `service_type` stores the AgencyServiceType CODE (the stable value), so a label
 * rename never rewrites the link — same contract as deal_step_work_orders.service_type.
 */
return new class extends Migration
{
    /** Legacy specialty → the matching DEFAULT AgencyServiceType code (backfill only). */
    private const SPECIALTY_TO_CODE = [
        'electrician'    => 'COC',
        'entomologist'   => 'Beetle',
        'plumber'        => 'Plumbing',
        'gas'            => 'Gas',
        'electric_fence' => 'Electric Fence',
    ];

    public function up(): void
    {
        Schema::create('agency_service_provider_service_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('service_provider_id');
            $table->string('service_type', 40); // = agency_service_types.code (stable value)
            $table->timestamps();
            $table->softDeletes();

            // Explicit SHORT constraint/index names — the table name is long, so Laravel's
            // auto-generated "{table}_{col}_foreign" would exceed MySQL's 64-char identifier limit.
            $table->foreign('service_provider_id', 'aspst_provider_fk')
                ->references('id')->on('agency_service_providers')->cascadeOnDelete();
            $table->index(['agency_id', 'service_type'], 'aspst_agency_type_idx'); // the filter
            $table->index('service_provider_id', 'aspst_provider_idx');
        });

        $this->backfillFromSpecialty();
    }

    /**
     * Map each existing supplier's legacy `specialty` to the matching DEFAULT service-type code,
     * but only when that code still exists (active) for the supplier's agency — so a renamed/archived
     * type is never resurrected. Idempotent (firstOrCreate). Attorney/bond/conveyancer/other map to
     * no COC type (they are identified by `specialty`, not the COC list).
     */
    private function backfillFromSpecialty(): void
    {
        // active service-type codes per agency, so we only backfill codes that exist
        $codesByAgency = [];
        foreach (DB::table('agency_service_types')->whereNull('deleted_at')->where('is_active', 1)
                     ->get(['agency_id', 'code']) as $t) {
            $codesByAgency[$t->agency_id][$t->code] = true;
        }

        $now = now();
        DB::table('agency_service_providers')->whereNull('deleted_at')->orderBy('id')
            ->chunk(500, function ($providers) use ($codesByAgency, $now) {
                foreach ($providers as $p) {
                    $code = self::SPECIALTY_TO_CODE[$p->specialty] ?? null;
                    if (! $code || empty($codesByAgency[$p->agency_id][$code])) {
                        continue; // no obvious mapping, or the agency doesn't have that code — leave untagged
                    }
                    $exists = DB::table('agency_service_provider_service_types')
                        ->where('service_provider_id', $p->id)->where('service_type', $code)
                        ->whereNull('deleted_at')->exists();
                    if ($exists) {
                        continue;
                    }
                    DB::table('agency_service_provider_service_types')->insert([
                        'agency_id'           => $p->agency_id,
                        'service_provider_id' => $p->id,
                        'service_type'        => $code,
                        'created_at'          => $now,
                        'updated_at'          => $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_service_provider_service_types');
    }
};
