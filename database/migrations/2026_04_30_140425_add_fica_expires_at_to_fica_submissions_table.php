<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fica_submissions', function (Blueprint $table) {
            $table->date('fica_expires_at')->nullable()->after('verified_at');
            $table->index('fica_expires_at');
        });

        // Backfill: approved submissions get expiry = verified_at + 24 months.
        // Date expression differs per driver; only production (MySQL) has rows
        // to backfill — the SQLite test DB starts empty.
        $expr = DB::getDriverName() === 'sqlite'
            ? "datetime(verified_at, '+24 months')"
            : 'DATE_ADD(verified_at, INTERVAL 24 MONTH)';

        DB::table('fica_submissions')
            ->whereNotNull('verified_at')
            ->where('status', 'approved')
            ->whereNull('fica_expires_at')
            ->update([
                'fica_expires_at' => DB::raw($expr),
            ]);
    }

    public function down(): void
    {
        Schema::table('fica_submissions', function (Blueprint $table) {
            $table->dropIndex(['fica_expires_at']);
            $table->dropColumn('fica_expires_at');
        });
    }
};
