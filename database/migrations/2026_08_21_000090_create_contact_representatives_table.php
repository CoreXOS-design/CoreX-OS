<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contact enhancement foundation — entity <-> natural-person representative
 * link (spec: .ai/specs/contact-entity-type.md §4.2 / §5).
 *
 * Many-to-many by design (Johan's decided model, option (a)): a director
 * can sit on multiple entities, an entity can have multiple representatives.
 * A row's representative_contact_id must reference a Contact with
 * type='natural_person' — enforced at the write path, not the schema.
 * The representative may be entirely absent for a given entity (the
 * scraper case: entity owner captured, director unknown) — that's a valid,
 * expected state, not an error.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('contact_representatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('representative_contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['entity_contact_id', 'representative_contact_id'], 'contact_representatives_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_representatives');
    }
};
