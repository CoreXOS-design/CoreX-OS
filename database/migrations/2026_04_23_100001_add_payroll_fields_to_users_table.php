<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'employment_date')) {
                $table->date('employment_date')->nullable()->after('designation');
            }
            if (! Schema::hasColumn('users', 'tax_reference_number')) {
                $table->string('tax_reference_number', 20)->nullable()->after('id_number');
            }
            if (! Schema::hasColumn('users', 'date_of_birth')) {
                $table->date('date_of_birth')->nullable()->after('id_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'employment_date')) {
                $table->dropColumn('employment_date');
            }
            if (Schema::hasColumn('users', 'tax_reference_number')) {
                $table->dropColumn('tax_reference_number');
            }
            if (Schema::hasColumn('users', 'date_of_birth')) {
                $table->dropColumn('date_of_birth');
            }
        });
    }
};
