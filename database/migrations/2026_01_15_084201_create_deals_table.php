<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->id();

            $table->string('period');               // YYYY-MM
            $table->date('deal_date');

            $table->decimal('property_value', 12, 2);
            $table->decimal('total_commission', 12, 2);

            // Listing side
            $table->boolean('listing_external')->default(false);
            $table->decimal('listing_our_share_percent', 5, 2)->default(100.00);

            // Selling side
            $table->boolean('selling_external')->default(false);
            $table->decimal('selling_our_share_percent', 5, 2)->default(100.00);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};
