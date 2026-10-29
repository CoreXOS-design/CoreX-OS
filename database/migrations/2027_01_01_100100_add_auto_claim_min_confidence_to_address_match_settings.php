<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** property-identity.md section 15, C2: the lowest match confidence at which a deeds / manual capture claims the Market Intelligence record on its own. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('address_match_settings', function (Blueprint $t) {
            $t->string('auto_claim_min_confidence', 10)->default('high');
        });
    }

    public function down(): void
    {
        Schema::table('address_match_settings', function (Blueprint $t) {
            $t->dropColumn('auto_claim_min_confidence');
        });
    }
};
