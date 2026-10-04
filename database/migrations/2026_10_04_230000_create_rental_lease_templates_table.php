<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-renewals.md §5(b) — GATE 1 (approved 2026-10-04). An
 * agency marks which of its own already-imported DocuPerfect templates are
 * its rental lease/renewal/addendum documents. No `field_mapping` column —
 * per the gate's own investigation, the real lease templates (render_type
 * 'pdf') have nothing to map (CoreX's canonical vocabulary already matches
 * their data-field attributes); a CDS template's mapping already lives on
 * `docuperfect_templates.field_mappings`, built via the existing importer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_lease_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->string('name', 191);
            // docuperfect_templates.id — no FK constraint across the Docuperfect
            // module boundary, matching the existing convention leases.
            // source_document_id already uses for the same reason.
            $table->unsignedBigInteger('docuperfect_template_id');
            // residential | commercial | renewal_addendum — free string, agency
            // picks the label; not an enum CoreX enforces.
            $table->string('category', 40);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'category', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_lease_templates');
    }
};
