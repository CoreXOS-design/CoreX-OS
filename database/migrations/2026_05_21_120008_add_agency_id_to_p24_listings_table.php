<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('p24_listings', 'agency_id')) {
            Schema::table('p24_listings', function (Blueprint $table) {
                $table->unsignedBigInteger('agency_id')->nullable()->after('id');
                $table->index('agency_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('p24_listings', function (Blueprint $table) {
            if (Schema::hasColumn('p24_listings', 'agency_id')) {
                $table->dropIndex(['agency_id']);
                $table->dropColumn('agency_id');
            }
        });
    }
};
