<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lane B step 1 (property-identity.md, B section): the portal-capture tables were global and the email-alert table's unique key ignored the
 * agency, so a second agency could read the first agency's captures and could not import an advert number the first agency already held.
 *
 *  - portal_captures, portal_listings, portal_listing_observations get an agency (backfill: the capturing user's agency, else the first
 *    agency - every row that exists today is HFC's).
 *  - portal_listings is unique per agency + site + portal id (was site + portal id).
 *  - p24_listings (email alerts) is unique per agency + advert number (was the number alone).
 */
return new class extends Migration
{
    public function up(): void
    {
        $first = DB::table('agencies')->orderBy('id')->value('id');

        foreach (['portal_captures', 'portal_listings', 'portal_listing_observations'] as $table) {
            if (! Schema::hasColumn($table, 'agency_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unsignedBigInteger('agency_id')->nullable()->after('id');
                });
            }
        }

        DB::statement('UPDATE portal_captures pc JOIN users u ON u.id = pc.user_id SET pc.agency_id = u.agency_id WHERE pc.agency_id IS NULL AND u.agency_id IS NOT NULL');
        DB::statement('UPDATE portal_listings pl JOIN portal_captures pc ON pc.id = pl.last_capture_id SET pl.agency_id = pc.agency_id WHERE pl.agency_id IS NULL AND pc.agency_id IS NOT NULL');
        DB::statement('UPDATE portal_listing_observations o JOIN portal_captures pc ON pc.id = o.capture_id SET o.agency_id = pc.agency_id WHERE o.agency_id IS NULL AND pc.agency_id IS NOT NULL');
        DB::statement('UPDATE portal_listing_observations o JOIN portal_listings pl ON pl.id = o.portal_listing_id SET o.agency_id = pl.agency_id WHERE o.agency_id IS NULL AND pl.agency_id IS NOT NULL');
        if ($first) {
            foreach (['portal_captures', 'portal_listings', 'portal_listing_observations'] as $table) {
                DB::table($table)->whereNull('agency_id')->update(['agency_id' => $first]);
            }
        }

        foreach (['portal_captures', 'portal_listings', 'portal_listing_observations'] as $table) {
            DB::statement("ALTER TABLE {$table} MODIFY agency_id BIGINT UNSIGNED NOT NULL");
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->index('agency_id', "{$table}_agency_id_index");
                $t->foreign('agency_id', "{$table}_agency_id_foreign")->references('id')->on('agencies')->restrictOnDelete();
            });
        }

        Schema::table('portal_listings', function (Blueprint $t) {
            $t->dropUnique('portal_listings_site_id_unique');
            $t->unique(['agency_id', 'source_site', 'portal_listing_id'], 'portal_listings_agency_site_id_unique');
        });

        Schema::table('p24_listings', function (Blueprint $t) {
            $t->dropUnique('p24_listings_p24_listing_number_unique');
            $t->unique(['agency_id', 'p24_listing_number'], 'p24_listings_agency_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('p24_listings', function (Blueprint $t) {
            $t->dropUnique('p24_listings_agency_number_unique');
            $t->unique('p24_listing_number', 'p24_listings_p24_listing_number_unique');
        });
        Schema::table('portal_listings', function (Blueprint $t) {
            $t->dropUnique('portal_listings_agency_site_id_unique');
            $t->unique(['source_site', 'portal_listing_id'], 'portal_listings_site_id_unique');
        });
        foreach (['portal_captures', 'portal_listings', 'portal_listing_observations'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropForeign("{$table}_agency_id_foreign");
                $t->dropIndex("{$table}_agency_id_index");
                $t->dropColumn('agency_id');
            });
        }
    }
};
