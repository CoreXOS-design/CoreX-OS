<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan: "on approval then we have a way for the agent to link the
 * application to a property... rental - when an approved tenant is linked
 * the property changes to let out status. so we have done it already. we
 * just going to borrow it from the original place we built it and adapt it
 * to rentals." Adapted from DR2's own under-offer flow
 * (app/Listeners/Deal/FlagPropertyUnderOfferOnDealCreated.php), which
 * snapshots the prior on-market status into `pre_deal_offer_status` before
 * flipping to under_offer, so a later revert (RevertPropertyStatusOnDealDeclined)
 * knows exactly what to restore.
 *
 * A SEPARATE column, not a reuse of `pre_deal_offer_status` — that column
 * is deal-shaped (paired with under_offer, revert-on-decline) and a
 * property can in principle be walking through both an open deal AND a
 * rental application at once; sharing one snapshot slot between two
 * unrelated features would let one clobber the other's "what to restore"
 * memory. Nullable: null means "no rental-tenant link currently holds this
 * property off-market", the same way pre_deal_offer_status's own null
 * means "not under-offer via a deal".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('pre_tenant_link_status')->nullable()->after('pre_deal_offer_status');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('pre_tenant_link_status');
        });
    }
};
