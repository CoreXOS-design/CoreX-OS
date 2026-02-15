<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            // Zip-style register fields (all nullable for safety)
            $table->string('file_no')->nullable()->after('id');
            $table->unsignedBigInteger('branch_id')->nullable()->after('file_no');

            $table->string('property_address')->nullable()->after('deal_date');
            $table->string('seller_name')->nullable()->after('property_address');
            $table->string('buyer_name')->nullable()->after('seller_name');
            $table->string('attorney_name')->nullable()->after('buyer_name');

            // Status fields (zip: acceptedStatus + commStatus)
            $table->string('accepted_status', 1)->nullable()->after('attorney_name'); // P/D/G/R
            $table->string('commission_status')->nullable()->after('accepted_status'); // Not Paid / Paid / Loss
            $table->date('registration_date')->nullable()->after('commission_status');

            $table->text('remarks')->nullable()->after('registration_date');

            // External agency names (zip uses agency IDs; we store name now, no new tables yet)
            $table->string('listing_external_agency')->nullable()->after('listing_external');
            $table->string('selling_external_agency')->nullable()->after('selling_external');

            $table->index('branch_id');
            $table->index('file_no');
            $table->index('accepted_status');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropIndex(['branch_id']);
            $table->dropIndex(['file_no']);
            $table->dropIndex(['accepted_status']);

            $table->dropColumn([
                'file_no',
                'branch_id',
                'property_address',
                'seller_name',
                'buyer_name',
                'attorney_name',
                'accepted_status',
                'commission_status',
                'registration_date',
                'remarks',
                'listing_external_agency',
                'selling_external_agency',
            ]);
        });
    }
};
