<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-432 addendum — .ai/specs/auctions-advertising-mode.md. An agency may use
 * Auctions purely to ADVERTISE auction stock (someone else — or the agency's
 * own auctioneer off-system — runs the sale). All additive, all nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auctions', function (Blueprint $table) {
            $table->string('external_registration_url', 500)->nullable()->after('auctioneer_licence_no');
            $table->string('auctioneer_phone', 40)->nullable()->after('external_registration_url');
            $table->string('auctioneer_email', 255)->nullable()->after('auctioneer_phone');
            $table->string('rules_file_path', 500)->nullable()->after('conditions_document_id');
            $table->string('rules_file_name', 255)->nullable()->after('rules_file_path');
            $table->string('conditions_file_path', 500)->nullable()->after('rules_file_name');
            $table->string('conditions_file_name', 255)->nullable()->after('conditions_file_path');
        });

        Schema::table('agency_auction_settings', function (Blueprint $table) {
            // NULL = documented default (true: advertise only).
            $table->boolean('advertising_only')->nullable()->after('auctioneer_mode');
        });
    }

    public function down(): void
    {
        Schema::table('agency_auction_settings', function (Blueprint $table) {
            $table->dropColumn('advertising_only');
        });
        Schema::table('auctions', function (Blueprint $table) {
            $table->dropColumn([
                'external_registration_url', 'auctioneer_phone', 'auctioneer_email',
                'rules_file_path', 'rules_file_name', 'conditions_file_path', 'conditions_file_name',
            ]);
        });
    }
};
