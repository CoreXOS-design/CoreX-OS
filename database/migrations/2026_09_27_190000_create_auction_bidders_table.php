<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-432 Phase 2 — .ai/specs/auctions.md §5.4. Bidder registration against
 * an auction. Deliberately its own table, NOT a contact_property role — see
 * that section's full rationale (contact_property's UNIQUE (contact_id,
 * property_id) would collide the first time a registered bidder was also
 * already linked to the lot's property in another role).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_bidders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();

            $table->string('paddle_number', 20)->nullable();
            $table->string('status', 30)->default('draft'); // draft|submitted|fica_pending|approved|declined|withdrawn
            $table->string('bidding_for', 20)->default('self'); // self|entity|agent_for_third_party
            $table->foreignId('entity_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->unsignedBigInteger('authority_document_id')->nullable();

            $table->string('fica_status', 20)->nullable();
            $table->dateTime('fica_verified_at')->nullable();
            $table->foreignId('fica_verified_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('deposit_required')->default(false);
            $table->decimal('deposit_amount', 12, 2)->nullable();
            $table->dateTime('deposit_received_at')->nullable();
            $table->string('deposit_reference', 100)->nullable();
            $table->dateTime('deposit_refunded_at')->nullable();
            $table->string('deposit_refund_reference', 100)->nullable();

            $table->dateTime('rules_signed_at')->nullable();
            $table->unsignedBigInteger('rules_document_id')->nullable();

            $table->string('registration_source', 20)->default('staff'); // online|at_door|staff
            $table->decimal('max_proxy_bid', 15, 2)->nullable();
            $table->text('declined_reason')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'auction_id', 'status'], 'auction_bidders_agency_auction_status_idx');
            // Application-enforced (not a DB unique) — same reasoning as
            // auction_lots' (auction_id, property_id): a plain DB unique would
            // collide with a withdrawn-then-re-registered bidder's soft-deleted
            // predecessor.
            $table->index(['auction_id', 'contact_id'], 'auction_bidders_auction_contact_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_bidders');
    }
};
