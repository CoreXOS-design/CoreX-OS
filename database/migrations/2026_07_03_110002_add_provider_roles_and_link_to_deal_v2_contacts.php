<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WS2 (AT-158 / DR2, decision D2) — provider roles on the deal-party pivot.
 *
 * A deal party is now EITHER a CoreX contact (contact_id) OR a directory
 * provider (agency_service_provider_id) — never a 5th contact type. Extend the
 * role enum with the provider roles and make contact_id nullable so a provider
 * party row can exist without a contact.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Widen the role enum with the provider roles (additive; existing rows keep their value).
        DB::statement(
            "ALTER TABLE `deal_v2_contacts` MODIFY `role` ENUM(".
            "'buyer','seller','co_buyer','co_seller','conveyancer','bond_originator','other',".
            "'transfer_attorney','bond_attorney','electrician_coc','entomologist','originator','service_provider'".
            ") NOT NULL"
        );

        // 2) contact_id → nullable (a provider party has no contact).
        DB::statement("ALTER TABLE `deal_v2_contacts` MODIFY `contact_id` BIGINT UNSIGNED NULL");

        // 3) Add the directory-provider reference.
        Schema::table('deal_v2_contacts', function (Blueprint $table) {
            if (! Schema::hasColumn('deal_v2_contacts', 'agency_service_provider_id')) {
                $table->unsignedBigInteger('agency_service_provider_id')->nullable()->after('contact_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('deal_v2_contacts', function (Blueprint $table) {
            if (Schema::hasColumn('deal_v2_contacts', 'agency_service_provider_id')) {
                $table->dropIndex(['agency_service_provider_id']);
                $table->dropColumn('agency_service_provider_id');
            }
        });
        // Collapse provider rows before narrowing (they can't map back to a contact role).
        DB::table('deal_v2_contacts')->whereIn('role', [
            'transfer_attorney', 'bond_attorney', 'electrician_coc', 'entomologist', 'originator', 'service_provider',
        ])->update(['role' => 'other']);
        DB::statement(
            "ALTER TABLE `deal_v2_contacts` MODIFY `role` ENUM(".
            "'buyer','seller','co_buyer','co_seller','conveyancer','bond_originator','other'".
            ") NOT NULL"
        );
        // contact_id stays nullable on down (safe; provider rows were collapsed).
    }
};
