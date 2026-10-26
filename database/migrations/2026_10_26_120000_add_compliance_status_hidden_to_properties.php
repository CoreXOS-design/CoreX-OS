<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-property switch: when set, the property page does not show the "Compliance Status" button. Default off (0), so every
 * existing and new property behaves exactly as before. Additive; written only by a deliberate admin instruction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->boolean('compliance_status_hidden')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('compliance_status_hidden');
        });
    }
};
