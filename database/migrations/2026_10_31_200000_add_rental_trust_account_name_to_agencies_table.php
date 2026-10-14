<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The FICA form's payment guidance used to name one agency's trust account in the wording ("the Reos trust account"). The name is
 * now an agency setting: how this agency refers to the trust account that rent and deposits are paid into. NULL means "say
 * nothing agency-specific" ("the agency's trust account") - never another agency's name. Read by App\Services\Compliance\FicaTrustAccount.
 * No data is written: an agency that has never opened the setting gets the neutral wording.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agencies') && ! Schema::hasColumn('agencies', 'rental_trust_account_name')) {
            Schema::table('agencies', function (Blueprint $table) {
                $table->string('rental_trust_account_name', 100)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('agencies') && Schema::hasColumn('agencies', 'rental_trust_account_name')) {
            Schema::table('agencies', function (Blueprint $table) {
                $table->dropColumn('rental_trust_account_name');
            });
        }
    }
};
