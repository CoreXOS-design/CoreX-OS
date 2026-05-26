<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tracked_property_external_refs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('tracked_property_id')
                  ->constrained('tracked_properties')
                  ->cascadeOnDelete();

            // source_type vocabulary (extensible):
            //   p24 | pp | cmainfo | virtualagent | chrome_capture | deeds_office | manual
            $table->string('source_type', 50);
            // source-specific stable identifier:
            //   P24 listing number, PP listing ref, presentation_id, capture_id, etc.
            $table->string('source_ref', 200);
            // Optional raw source payload — e.g. the parsed P24 email fields or CMAInfo extraction_json.
            $table->json('source_payload')->nullable();

            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');

            $table->timestamps();
            $table->softDeletes();

            // One canonical row per (agency, source_type, source_ref). Re-ingest
            // of the same source updates last_seen_at + payload, never duplicates.
            $table->unique(['agency_id', 'source_type', 'source_ref'], 'unq_tracked_external_ref');
            $table->index(['tracked_property_id', 'source_type'], 'idx_tracked_ext_refs_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_property_external_refs');
    }
};
