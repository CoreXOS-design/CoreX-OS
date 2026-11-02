<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rental-applications.md "Fuller application (26 Oct 2026)": the facts a letting agency's own paper application
 * asks that CoreX's application did not - tax number, how married, employment length and pay day, the previous
 * landlord, banking, judgments, vehicle - plus the four repeating blocks (co-applicant, co-occupants, pets, the
 * tenant's own income/expense schedule). Every column is nullable: nothing here may block a save (BUILD_STANDARD 2).
 */
return new class extends Migration
{
    private const STRING_COLUMNS = [
        'income_tax_no' => 50,
        'marriage_regime' => 80,
        'years_employed' => 30,
        'pay_date' => 40,
        'previous_landlord_name' => 255,
        'previous_landlord_tel' => 50,
        'bank_name' => 100,
        'bank_account_no' => 50,
        'judgments' => 10,
        'car_make' => 100,
        'car_colour' => 50,
        'car_year' => 10,
        'car_reg' => 30,
    ];

    private const JSON_COLUMNS = ['co_applicant', 'co_occupants', 'pets', 'affordability'];

    public function up(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            foreach (self::STRING_COLUMNS as $column => $length) {
                if (! Schema::hasColumn('rental_applications', $column)) {
                    $table->string($column, $length)->nullable();
                }
            }
            if (! Schema::hasColumn('rental_applications', 'judgments_details')) {
                $table->text('judgments_details')->nullable();
            }
            foreach (self::JSON_COLUMNS as $column) {
                if (! Schema::hasColumn('rental_applications', $column)) {
                    $table->json($column)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $drop = array_merge(array_keys(self::STRING_COLUMNS), ['judgments_details'], self::JSON_COLUMNS);
            foreach ($drop as $column) {
                if (Schema::hasColumn('rental_applications', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
