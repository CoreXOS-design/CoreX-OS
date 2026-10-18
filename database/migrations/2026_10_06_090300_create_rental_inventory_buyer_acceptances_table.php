<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §24 ruling (Johan, 2026-09-29) — "once a buyer exists, the agent can send
 * the completed inventory to the buyer for a signature of acceptance of
 * what they get in the sale." A genuinely SEPARATE record from
 * `rental_inventory_signatures` — it must NOT reopen or edit the completed
 * inventory (RentalInventory::assertEditable() is never called anywhere in
 * this feature's own write path). Offered only once a committed deal
 * exists on the property (RentalInventory::buyerAcceptanceOfferedFor()) —
 * long after the inventory itself was captured and signed at mandate
 * stage, so it has no bearing on `markCompleted()`'s own gate.
 *
 * No DB-level unique constraint on (rental_inventory_id, buyer_contact_id):
 * a correction is soft-delete + a fresh row (non-negotiable #1, no hard
 * deletes/edits of evidence), and a hard unique index would block that
 * exact pattern in MySQL (soft-deleted rows still count). "At most one
 * LIVE row per buyer" is enforced in RentalInventoryBuyerAcceptance::
 * capture() instead — same discipline RentalInventorySignature::capture()
 * already uses for its own dedup check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inventory_buyer_acceptances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->foreignId('rental_inventory_id')->constrained('rental_inventories')->cascadeOnDelete();
            $table->foreignId('buyer_contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('disposition', 20); // signed | wet_ink
            $table->string('party_signature_path')->nullable();
            $table->string('wet_ink_upload_path')->nullable();
            $table->unsignedBigInteger('recorded_by_user_id')->nullable(); // null when the buyer signs themself via the public page
            $table->timestamp('accepted_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['agency_id', 'rental_inventory_id'], 'rental_inv_buyer_acceptances_agency_inventory_idx');

            $table->foreign('recorded_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inventory_buyer_acceptances');
    }
};
