<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (! Schema::hasColumn('agencies', 'paye_registration_no')) {
                $table->string('paye_registration_no', 20)->nullable();
            }
            if (! Schema::hasColumn('agencies', 'uif_employer_no')) {
                $table->string('uif_employer_no', 20)->nullable();
            }
            if (! Schema::hasColumn('agencies', 'sdl_registration_no')) {
                $table->string('sdl_registration_no', 20)->nullable();
            }
            if (! Schema::hasColumn('agencies', 'employer_bank_name')) {
                $table->string('employer_bank_name', 100)->nullable();
            }
            if (! Schema::hasColumn('agencies', 'employer_bank_account')) {
                $table->string('employer_bank_account', 30)->nullable();
            }
            if (! Schema::hasColumn('agencies', 'employer_bank_branch_code')) {
                $table->string('employer_bank_branch_code', 10)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $columns = [
                'paye_registration_no', 'uif_employer_no', 'sdl_registration_no',
                'employer_bank_name', 'employer_bank_account', 'employer_bank_branch_code',
            ];
            foreach ($columns as $col) {
                if (Schema::hasColumn('agencies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
