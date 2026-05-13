<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->decimal('preapproval_amount', 14, 2)->nullable()->after('bank_account_type');
            $table->date('preapproval_expires_at')->nullable()->after('preapproval_amount');
            $table->string('preapproval_institution', 100)->nullable()->after('preapproval_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['preapproval_institution', 'preapproval_expires_at', 'preapproval_amount']);
        });
    }
};
