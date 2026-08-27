<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ESIGN recipient builder (Johan, 2026-08-15) — agency-configurable phrasing
 * templates for entity/company recipients. v1 = ONE default template per agency,
 * agent-pickable at compose; rich multi-template builder UI is a fast-follow.
 *
 * phrasing_template tokens: {entity_name} {rep_name} {capacity} {entity_reg_no}
 *   default: "{entity_name}, herein represented by {rep_name} ({capacity})"
 * signature_caption tokens: same set — rendered under the signer's signature to
 * attribute it to the entity, e.g. "on behalf of {entity_name} ({capacity})".
 *
 * A SETTINGS knob (not the onboarding wizard — Johan's call). Agency-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esign_recipient_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('applies_to', 20)->default('entity'); // entity | all
            $table->text('phrasing_template');
            $table->text('signature_caption')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esign_recipient_presets');
    }
};
