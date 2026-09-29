<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/leases.md §3.2 — N-party tenants, never assumed 1-2, per
 * Johan's e-sign doctrine. A real table from day one, never a single
 * tenant_contact_id column on leases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_tenants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['lease_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_tenants');
    }
};
