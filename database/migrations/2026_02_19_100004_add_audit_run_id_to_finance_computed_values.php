<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_computed_values', function (Blueprint $table) {
            $table->unsignedBigInteger('audit_run_id')->nullable()->after('computed_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('finance_computed_values', function (Blueprint $table) {
            $table->dropIndex(['audit_run_id']);
            $table->dropColumn('audit_run_id');
        });
    }
};
