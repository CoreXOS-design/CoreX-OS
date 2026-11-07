<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Auctions v2 (.ai/specs/auctions.md §23): the Property record a seller questionnaire created (status "Auction enquiry"). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('auction_seller_submissions') && ! Schema::hasColumn('auction_seller_submissions', 'property_id')) {
            Schema::table('auction_seller_submissions', fn (Blueprint $t) => $t->unsignedBigInteger('property_id')->nullable()->after('tracked_property_id'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('auction_seller_submissions', 'property_id')) {
            Schema::table('auction_seller_submissions', fn (Blueprint $t) => $t->dropColumn('property_id'));
        }
    }
};
