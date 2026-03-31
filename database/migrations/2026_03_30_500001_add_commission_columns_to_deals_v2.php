<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals_v2', function (Blueprint $table) {
            // Listing side
            $table->decimal('listing_split_percent', 5, 2)->default(50.00)->after('commission_vat');
            $table->boolean('listing_external')->default(false)->after('listing_split_percent');
            $table->decimal('listing_our_share_percent', 5, 2)->default(100.00)->after('listing_external');
            $table->string('listing_external_agency')->nullable()->after('listing_our_share_percent');

            // Selling side
            $table->decimal('selling_split_percent', 5, 2)->default(50.00)->after('listing_external_agency');
            $table->boolean('selling_external')->default(false)->after('selling_split_percent');
            $table->decimal('selling_our_share_percent', 5, 2)->default(100.00)->after('selling_external');
            $table->string('selling_external_agency')->nullable()->after('selling_our_share_percent');

            // Payment tracking
            $table->string('commission_status')->default('Not Paid')->after('overall_rag');
        });
    }

    public function down(): void
    {
        Schema::table('deals_v2', function (Blueprint $table) {
            $table->dropColumn([
                'listing_split_percent', 'listing_external', 'listing_our_share_percent', 'listing_external_agency',
                'selling_split_percent', 'selling_external', 'selling_our_share_percent', 'selling_external_agency',
                'commission_status',
            ]);
        });
    }
};
