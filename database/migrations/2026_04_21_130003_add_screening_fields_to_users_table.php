<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'risk_tier')) {
                $table->enum('risk_tier', ['high', 'medium', 'low'])->default('medium')
                      ->after('role');
            }
            if (!Schema::hasColumn('users', 'screening_status')) {
                $table->enum('screening_status', [
                    'never_screened', 'pre_employment_pending', 'clear',
                    'concerns_flagged', 'overdue', 'expired',
                ])->default('never_screened')->after('risk_tier');
            }
            if (!Schema::hasColumn('users', 'screening_due_on')) {
                $table->date('screening_due_on')->nullable()->after('screening_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['risk_tier', 'screening_status', 'screening_due_on']);
        });
    }
};
