<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_stocks', function (Blueprint $table) {
            $table->id();

            // Ownership / scope
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable(); // keep nullable for now (branches table may differ)

            // Source identity (Propcon / advertising export)
            $table->string('source', 50)->default('propcon');
            $table->string('external_id', 100)->nullable();   // e.g. "Code"
            $table->string('external_ref', 100)->nullable();  // e.g. "Reference Code"

            // Listing core
            $table->string('property')->nullable(); // address/title
            $table->string('status', 50)->nullable();
            $table->bigInteger('price_cents')->nullable();

            // Optional descriptors
            $table->string('category', 50)->nullable();
            $table->string('type', 80)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('mandate', 80)->nullable();

            // Dates from source
            $table->timestamp('listed_at')->nullable();
            $table->timestamp('modified_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            // Full source row snapshot
            $table->json('raw_payload')->nullable();

            $table->timestamps();

            // Matching / lookup indexes
            $table->index(['user_id', 'status']);
            $table->index(['source', 'external_id']);
            $table->index(['source', 'external_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_stocks');
    }
};
