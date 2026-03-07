<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('bank_name', 255)->nullable()->after('address');
            $table->string('bank_account_name', 255)->nullable()->after('bank_name');
            $table->string('bank_account_number', 100)->nullable()->after('bank_account_name');
            $table->string('bank_branch_name', 255)->nullable()->after('bank_account_number');
            $table->string('bank_branch_code', 50)->nullable()->after('bank_branch_name');
            $table->string('bank_account_type', 50)->nullable()->after('bank_branch_code');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn([
                'bank_name', 'bank_account_name', 'bank_account_number',
                'bank_branch_name', 'bank_branch_code', 'bank_account_type',
            ]);
        });
    }
};
