<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signatures', function (Blueprint $table) {
            $table->text('text_value')->nullable()->after('signature_data');
            $table->longText('signature_data')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('signatures', function (Blueprint $table) {
            $table->dropColumn('text_value');
            $table->longText('signature_data')->nullable(false)->change();
        });
    }
};
