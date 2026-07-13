<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proforma Invoices (Accounting pillar) — per-agency numbering + issuing settings.
 * Letterhead/logo/VAT-number/VAT-registered are NOT duplicated here — they are
 * read live from the Agency branding settings (zero hardwiring, spec §2.1/§10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_proforma_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->unique();
            $table->string('number_prefix', 16)->default('PRO-');
            $table->unsignedInteger('next_number')->default(1);   // START NUMBER; sequence counter
            $table->unsignedTinyInteger('number_padding')->default(4);
            $table->enum('due_date_rule', ['end_of_month', 'days_after', 'on_receipt'])->default('end_of_month');
            $table->unsignedInteger('due_days')->default(30);
            $table->text('bank_details')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_proforma_settings');
    }
};
