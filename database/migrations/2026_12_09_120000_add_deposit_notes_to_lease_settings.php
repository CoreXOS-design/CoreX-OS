<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rental-applications.md "Application terms": two free-text lines an agency may print with the deposit - a note on how the
 * deposit amount is set ("Rent x1.5 or x2, to be advised") and a note under the deposit banking details (for example when the
 * rent is paid to the landlord instead). Nullable, no default: blank prints nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lease_settings', function (Blueprint $table) {
            foreach (['deposit_note', 'deposit_bank_note'] as $column) {
                if (! Schema::hasColumn('lease_settings', $column)) {
                    $table->text($column)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('lease_settings', function (Blueprint $table) {
            foreach (['deposit_note', 'deposit_bank_note'] as $column) {
                if (Schema::hasColumn('lease_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
