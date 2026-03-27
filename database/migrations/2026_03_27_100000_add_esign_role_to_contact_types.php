<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_types', function (Blueprint $table) {
            $table->string('esign_role', 20)->nullable()->default(null)->after('sort_order');
            $table->index('esign_role');
        });

        // Auto-set sensible defaults based on existing names
        DB::table('contact_types')
            ->where('name', 'like', '%Seller%')
            ->whereNull('esign_role')
            ->update(['esign_role' => 'seller']);

        DB::table('contact_types')
            ->where('name', 'like', '%Buyer%')
            ->where('name', 'not like', '%Seller%')
            ->whereNull('esign_role')
            ->update(['esign_role' => 'buyer']);

        DB::table('contact_types')
            ->where(function ($q) {
                $q->where('name', 'like', '%Lessor%')
                  ->orWhere('name', 'like', '%Landlord%');
            })
            ->whereNull('esign_role')
            ->update(['esign_role' => 'lessor']);

        DB::table('contact_types')
            ->where(function ($q) {
                $q->where('name', 'like', '%Lessee%')
                  ->orWhere('name', 'like', '%Tenant%');
            })
            ->whereNull('esign_role')
            ->update(['esign_role' => 'lessee']);
    }

    public function down(): void
    {
        Schema::table('contact_types', function (Blueprint $table) {
            $table->dropIndex(['esign_role']);
            $table->dropColumn('esign_role');
        });
    }
};
