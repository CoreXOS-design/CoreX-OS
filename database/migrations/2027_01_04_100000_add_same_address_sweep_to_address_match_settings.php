<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-520 step 4.2 (property-identity.md 17.3): the agency switch for the after-import same-address sweep. Default ON for every agency
 * (an agency with no row gets the default from AddressMatchSetting::DEFAULTS). Additive; nothing existing is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('address_match_settings', 'same_address_sweep')) {
            Schema::table('address_match_settings', function (Blueprint $t) {
                $t->boolean('same_address_sweep')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('address_match_settings', 'same_address_sweep')) {
            Schema::table('address_match_settings', function (Blueprint $t) {
                $t->dropColumn('same_address_sweep');
            });
        }
    }
};
