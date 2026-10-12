<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-432 Phase 1 — .ai/specs/auctions.md §5.2. The sale event. One auction
 * may carry many lots; a single-property auction is an auction with one lot
 * — there is no separate "single lot" code path (§5.2 opening line).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auctions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reference', 40);
            $table->string('title', 255);
            $table->foreignId('auction_type_id')->nullable()->constrained('property_setting_items')->nullOnDelete();
            $table->string('bidding_mode', 20); // in_room|online|hybrid

            $table->string('auctioneer_kind', 20); // internal|external
            $table->foreignId('auctioneer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('auctioneer_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('auctioneer_company', 255)->nullable();
            $table->string('auctioneer_licence_no', 60)->nullable();

            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->dateTime('registration_opens_at')->nullable();
            $table->dateTime('registration_closes_at')->nullable();

            $table->string('venue_name', 255)->nullable();
            $table->string('venue_address', 500)->nullable();
            $table->decimal('venue_lat', 10, 7)->nullable();
            $table->decimal('venue_lng', 10, 7)->nullable();
            $table->boolean('is_online_streamed')->default(false);
            $table->string('stream_url', 1000)->nullable();

            $table->string('status', 30)->default('draft'); // §6.1

            $table->unsignedBigInteger('rules_document_id')->nullable();
            $table->unsignedBigInteger('conditions_document_id')->nullable();
            $table->timestamp('catalogue_published_at')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['agency_id', 'reference'], 'auctions_agency_reference_unique');
            $table->index(['agency_id', 'status', 'starts_at'], 'auctions_agency_status_starts_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auctions');
    }
};
