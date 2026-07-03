<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WS2 (AT-158 / DR2, decision D2) — the reusable agency preferred-supplier directory.
 *
 * Service providers (electrician for the COC, entomologist, transfer/bond
 * attorney, bond originator, …) can't be a CoreX contact type (the 4-parent
 * lock), so they live here — agency-scoped, reusable across deals. An agent
 * picks one from the directory (preferred first) or creates one inline (which
 * saves here for next time). A provider MAY also point at a CoreX contact
 * (contact_id) but need not. Soft-delete only (deactivate) so historic
 * distributions keep resolving.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_service_providers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('contact_id')->nullable(); // optional link to a CoreX contact
            $table->string('name', 191);
            $table->enum('specialty', [
                'electrician', 'entomologist', 'plumber', 'gas', 'electric_fence',
                'transfer_attorney', 'bond_attorney', 'conveyancer', 'bond_originator', 'other',
            ])->default('other');
            $table->string('company', 191)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_preferred')->default(false); // agency's default pick for the specialty
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'specialty', 'is_active']);
            $table->index(['agency_id', 'is_preferred']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_service_providers');
    }
};
