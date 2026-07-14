<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proforma invoice line items. The `commission` line is the system line locked to
 * the deal's truth (agents/BMs cannot edit). `adjustment` lines are admin-added
 * (e.g. "Discount on commission" — negative excl allowed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proforma_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('proforma_invoice_id');
            $table->unsignedBigInteger('agency_id')->index();
            $table->string('description');
            $table->decimal('amount_excl', 14, 2)->default(0);
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->decimal('amount_incl', 14, 2)->default(0);
            $table->enum('kind', ['commission', 'adjustment'])->default('adjustment');
            $table->boolean('is_locked')->default(false);
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('proforma_invoice_id')->references('id')->on('proforma_invoices')->cascadeOnDelete();
            $table->index(['proforma_invoice_id', 'sort_order'], 'pil_invoice_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proforma_invoice_lines');
    }
};
