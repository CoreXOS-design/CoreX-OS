<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-243 — the DR2 deal's OWN party list.
 *
 * THE HOLE THIS CLOSES
 * --------------------
 * DR2 capture already asks the agent WHO the buyers and sellers are
 * (`buyer_contact_ids` / `seller_contact_ids` on the capture form), but it never
 * kept the answer. The ids were used once, to link those people to the PROPERTY
 * (`contact_property`), and then thrown away — the deal itself retained only a
 * free-text `deals.buyer_name`.
 *
 * That is fine until a property carries more than one offer, which is the normal
 * case: four offers → four contacts all linked to the property with role='buyer',
 * and NOTHING recording which of them belongs to which deal. So when one deal is
 * granted, the system cannot say who actually bought. Verified on real data: a
 * property with three buyer-role contacts and a granted deal whose purchaser was
 * unknowable.
 *
 * The deal register is meant to be the one truth about a transaction, so the deal
 * owns its parties. That makes "the purchaser" a derived fact — the buyer of the
 * property's granted/registered deal — with no side flag to set, clear, or drift.
 *
 * WHY A NEW PIVOT AND NOT `deal_v2_contacts`
 * ------------------------------------------
 * `deal_v2_contacts` has the right shape but belongs to the sunsetting deals_v2
 * engine, and is empty in every environment. DR2 is a rebuild on the `deals`
 * table; its parties belong to `deals`. This mirrors `contact_property` exactly so
 * the two read the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('deal_id');
            $table->unsignedBigInteger('contact_id');
            $table->string('role', 50); // buyer | seller (the party's role ON THIS DEAL)
            $table->timestamps();

            // A person plays ONE role on a given deal. Re-syncing the same party is a no-op.
            $table->unique(['deal_id', 'contact_id', 'role'], 'deal_contacts_unique');
            $table->index(['deal_id', 'role']);

            $table->foreign('deal_id')->references('id')->on('deals')->onDelete('cascade');
            $table->foreign('contact_id')->references('id')->on('contacts')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_contacts');
    }
};
