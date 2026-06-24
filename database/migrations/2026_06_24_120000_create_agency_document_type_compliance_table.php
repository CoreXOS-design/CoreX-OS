<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-agency marketing-compliance required document-type mapping.
 *
 * `document_types` is a GLOBAL catalogue (no agency_id). This pivot lets each
 * agency flag which document types are required for a property to be
 * marketing-compliant — read by the MarketingReadinessService gate via
 * AgencyComplianceDocTypeService. Backfills sensible defaults (mandate, fica,
 * disclosure) for every existing agency so nothing regresses at deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_document_type_compliance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained('document_types')->cascadeOnDelete();
            $table->boolean('is_compliance_required')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['agency_id', 'document_type_id'], 'agency_doc_type_compliance_unique');
            $table->index(['agency_id', 'is_compliance_required'], 'agency_doc_type_compliance_lookup');
        });

        // Backfill defaults for every existing agency so the gate behaves
        // sensibly from the moment it deploys. Matched by slug; any default
        // slug missing from this install is simply skipped.
        $defaultSlugs = config('corex-compliance.default_required_slugs', ['mandate', 'fica', 'disclosure']);

        $typeIds = DB::table('document_types')
            ->whereIn('slug', $defaultSlugs)
            ->whereNull('deleted_at')
            ->pluck('id');

        if ($typeIds->isEmpty()) {
            return;
        }

        $now = now();
        foreach (DB::table('agencies')->pluck('id') as $agencyId) {
            foreach ($typeIds as $typeId) {
                DB::table('agency_document_type_compliance')->updateOrInsert(
                    ['agency_id' => $agencyId, 'document_type_id' => $typeId],
                    [
                        'is_compliance_required' => true,
                        'deleted_at'             => null,
                        'created_at'             => $now,
                        'updated_at'             => $now,
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_document_type_compliance');
    }
};
