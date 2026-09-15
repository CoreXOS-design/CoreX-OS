<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392 authoriser flow — Johan: "there like on esign needs to be the ro
 * then co approval process? so admin or bm acts like the co. selected
 * agents act as ro... ro can approve / decline. but then lets say the
 * tenant speaks to admin and they decide they want to override ro, then
 * can approve / decline with reasons given... like an admin override." And:
 * "Both configured as agency settings, multi-select from users, exactly
 * like the existing CO and RO settings."
 *
 * Replaces the single flat rental_application_authoriser_user_ids column
 * (added 2026_09_08_120000, never wired to any shipped UI — the design
 * changed to two tiers before that column was ever used) with two, mirroring
 * FICA's real CO/RO shape: settings.blade.php's "Section B: MLROs /
 * Reporting Officers" (checkboxes → mlro_user_ids[] → multiple ROs) and the
 * Primary CO concept (one authority tier that can override). Deliberately
 * still the simpler whistleblow_approver_user_ids JSON-array shape, not
 * fica_officer_appointments' dated-appointment table — no legal
 * appointment-history requirement here, just "who currently holds this
 * tier."
 *
 * rental_applications.approved_rental_amount is the figure the authoriser
 * captures on approval — Johan: "accept flow should... update agent rental
 * screen - tenant approved for x amount."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('rental_application_authoriser_user_ids');
            $table->json('rental_application_ro_user_ids')->nullable()->after('whistleblow_approver_user_ids');
            $table->json('rental_application_co_user_ids')->nullable()->after('rental_application_ro_user_ids');
        });

        Schema::table('rental_applications', function (Blueprint $table) {
            $table->decimal('approved_rental_amount', 12, 2)->nullable()->after('submitted_for_approval_at');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn(['rental_application_ro_user_ids', 'rental_application_co_user_ids']);
            $table->json('rental_application_authoriser_user_ids')->nullable();
        });

        Schema::table('rental_applications', function (Blueprint $table) {
            $table->dropColumn('approved_rental_amount');
        });
    }
};
