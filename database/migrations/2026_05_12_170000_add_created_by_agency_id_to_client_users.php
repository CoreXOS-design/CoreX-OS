<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_users', function (Blueprint $table) {
            $table->foreignId('created_by_agency_id')
                ->nullable()
                ->after('current_agency_id')
                ->constrained('agencies')
                ->nullOnDelete();
        });

        // Backfill: for each existing ClientUser, use the agency_id of the
        // earliest-linked contact as the origin agency.
        $rows = DB::table('client_users')->whereNull('created_by_agency_id')->pluck('id');
        foreach ($rows as $clientUserId) {
            $agencyId = DB::table('contacts')
                ->where('client_user_id', $clientUserId)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->value('agency_id');

            if ($agencyId) {
                DB::table('client_users')
                    ->where('id', $clientUserId)
                    ->update(['created_by_agency_id' => $agencyId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('client_users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_agency_id');
        });
    }
};
