<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inventory.md §3 — the inventory header. Its own document,
 * attached to the property and the lease — NOT a tab on RentalInspection.
 * Produced once at move-in; the move-out comparison mechanism (cc5, a
 * separate build) reads this table's lines rather than a second inventory
 * record. No `type` column: unlike RentalInspection (a genuinely repeated
 * event, in AND out), Johan's real document is produced once per tenancy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained('leases')->cascadeOnDelete();

            // Mirrors RentalInspection's own lifecycle shape — draft while
            // lines are being added, awaiting_signature once the walk is
            // done, completed once every required party has a disposition.
            $table->string('status', 30)->default('draft');

            $table->timestamp('signing_deadline_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('archived_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'property_id']);
            $table->index(['agency_id', 'lease_id']);
            $table->index(['agency_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inventories');
    }
};
