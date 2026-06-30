<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-118 — record the real acting admin on the contact-access POPIA trail.
 *
 * contact_access_log.user_id reads as the IMPERSONATED user during switch-user
 * (full Auth::login swap). This nullable column captures the acting admin
 * (session impersonator_id) so a contact view performed by an admin-acting-as-X
 * is not silently attributed to X alone. NULL = a normal, non-impersonated access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_access_log', function (Blueprint $table) {
            $table->foreignId('impersonator_id')->nullable()->after('user_id')
                ->constrained('users', 'id', 'cal_impersonator_fk')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contact_access_log', function (Blueprint $table) {
            $table->dropForeign('cal_impersonator_fk');
            $table->dropColumn('impersonator_id');
        });
    }
};
