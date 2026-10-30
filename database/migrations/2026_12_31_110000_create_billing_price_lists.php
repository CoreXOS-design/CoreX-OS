<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency Billing: the band price table and the branch fee become owner-editable, each list with an effective-from date, so a
 * price change never rewrites history (spec agency-billing.md "Price list"). No row = the config/corex-billing.php defaults.
 * Also drops the interim revenue_price_list table (QA1 only) - Revenue reads its prices from Agency Billing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('billing_price_lists')) {
            Schema::create('billing_price_lists', function (Blueprint $t) {
                $t->id();
                $t->date('effective_from');
                $t->json('bands');                        // [{from,to,amount}, ...] contiguous from 1; above the last band = on quotation
                $t->decimal('branch_rate', 10, 2);        // per branch after the first, a month
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->index('effective_from');
            });
        }
        Schema::dropIfExists('revenue_price_list');
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_price_lists');
    }
};
