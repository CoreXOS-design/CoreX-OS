<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-79 — a contact may belong to MULTIPLE parent types.
 *
 * e.g. a person who is a Seller on one deal and a Buyer on another. This pivot
 * is the source of truth for parent membership. `contacts.contact_type_id` is
 * retained as a denormalised "primary parent" mirror (lowest-sort assigned
 * parent) so the 36 existing readers and the e-sign reverse-mapping keep
 * working untouched; Contact::syncTypeAssignments() maintains it on every write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_contact_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_type_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['contact_id', 'contact_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_contact_type');
    }
};
