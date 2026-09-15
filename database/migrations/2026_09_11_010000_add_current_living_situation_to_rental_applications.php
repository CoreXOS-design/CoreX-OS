<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, verbatim: "rental application - current landlord - we need to
 * include a part here for a person who has sold his house and is going to
 * rent for the first time now. and a free text section where the
 * applicant can capture their own explanation of where they live / lived."
 *
 * `current_living_situation` — which of the recognised situations applies
 * (see RentalApplication::CURRENT_LIVING_SITUATIONS); governs whether the
 * existing landlord fields are shown/relevant, never removes them.
 * `current_living_situation_notes` — the free-text section, genuinely
 * optional, always available regardless of the selected situation.
 *
 * Both nullable — every existing application has neither, and must still
 * render/review/PDF correctly with both absent (the PDF and review-screen
 * label fall back to the pre-existing landlord fields when this is null,
 * so an old application is never left with a blank "Current Living
 * Situation" line where a real landlord answer already exists).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->string('current_living_situation', 50)->nullable()->after('emergency_contact_work');
            $table->text('current_living_situation_notes')->nullable()->after('current_living_situation');
        });
    }

    public function down(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->dropColumn(['current_living_situation', 'current_living_situation_notes']);
        });
    }
};
