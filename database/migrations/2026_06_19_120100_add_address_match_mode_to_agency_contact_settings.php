<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-60 — Agency-configurable address-duplicate-guard criteria.
 *
 * When an agent transfers a contact's structured address onto a new Property
 * (PropertyController::create), CoreX checks whether a property already exists
 * at that address (reusing TrackedPropertyMatchOrCreateService::findExistingMatch)
 * and, if so, offers link-to-existing instead of minting a duplicate.
 *
 * The aggressiveness of that guard must NOT be hardcoded — it is an agency
 * decision (some agencies want every near-match flagged; others only want
 * dead-certain street matches). This column drives it:
 *   - off      → never warn (always go straight to the prefilled create form)
 *   - standard → warn whenever the matcher resolves to an existing stock property (default)
 *   - strict   → warn only when the matched property ALSO shares the same street
 *                (street_number + street_name) — fewest false positives
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('agency_contact_settings', function (Blueprint $t) {
            $t->enum('address_match_mode', ['off', 'standard', 'strict'])
                ->default('standard')
                ->after('duplicate_match_fields');
        });
    }

    public function down(): void
    {
        Schema::table('agency_contact_settings', function (Blueprint $t) {
            $t->dropColumn('address_match_mode');
        });
    }
};
