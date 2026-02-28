<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_document_recipients', function (Blueprint $table) {
            $table->string('id_number', 20)->nullable()->after('recipient_role');
        });
    }

    public function down(): void
    {
        Schema::table('sales_document_recipients', function (Blueprint $table) {
            $table->dropColumn('id_number');
        });
    }
};
