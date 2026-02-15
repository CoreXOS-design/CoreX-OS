<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_settlements', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('deductions_description');
        });
    }

    public function down(): void
    {
        Schema::table('deal_settlements', function (Blueprint $table) {
            $table->dropColumn('paid_at');
        });
    }
};
