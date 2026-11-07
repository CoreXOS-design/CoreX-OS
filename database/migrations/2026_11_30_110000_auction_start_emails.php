<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Auctions v2 (.ai/specs/auctions.md §19.7): the "auction is now live" email goes out once per auction, and the agency can switch it off. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('auctions') && ! Schema::hasColumn('auctions', 'start_notified_at')) {
            Schema::table('auctions', fn (Blueprint $t) => $t->timestamp('start_notified_at')->nullable());
        }
        if (Schema::hasTable('agency_auction_settings') && ! Schema::hasColumn('agency_auction_settings', 'start_emails_enabled')) {
            Schema::table('agency_auction_settings', fn (Blueprint $t) => $t->boolean('start_emails_enabled')->nullable());   // null = on
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('auctions', 'start_notified_at')) {
            Schema::table('auctions', fn (Blueprint $t) => $t->dropColumn('start_notified_at'));
        }
        if (Schema::hasColumn('agency_auction_settings', 'start_emails_enabled')) {
            Schema::table('agency_auction_settings', fn (Blueprint $t) => $t->dropColumn('start_emails_enabled'));
        }
    }
};
