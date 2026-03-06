<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('ffc_certificate_path');
            $table->string('cell')->nullable()->after('phone');
            $table->string('fax')->nullable()->after('cell');
            $table->string('ffc_number')->nullable()->after('fax');
            $table->string('website')->nullable()->after('ffc_number');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'cell', 'fax', 'ffc_number', 'website']);
        });
    }
};
