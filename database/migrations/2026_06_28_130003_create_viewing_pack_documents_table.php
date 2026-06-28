<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-XX Viewing Pack — Step 2: persistence spine (table 3 of 3).
 *
 * One row per attached document included under a pack property. `document_id`
 * points at the existing unified `documents` table (PK id, confirmed in the
 * Step-2 investigation). `document_type_slug` denormalises the catalogue slug
 * so eligibility (Step 1's buyer_pack_eligible) can be resolved without a join.
 * `redacted_file_path` holds the flattened/redacted artifact reference produced
 * in Step 5 (nullable until then). `included` lets the agent keep a row but drop
 * it from the output.
 *
 * agency_id denormalised for AgencyScope. Soft-deletes cascade from the parent
 * property (ViewingPackProperty::deleting), which itself cascades from the pack.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viewing_pack_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('viewing_pack_property_id')->constrained('viewing_pack_properties')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->string('document_type_slug', 50)->nullable();
            $table->string('redacted_file_path', 500)->nullable();
            $table->boolean('included')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index('viewing_pack_property_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viewing_pack_documents');
    }
};
