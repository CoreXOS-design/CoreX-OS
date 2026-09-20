<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-20 — real QA1 walk found there was no supported way to archive an
 * inspection once it had any recorded observations at all: isDeletable()
 * blocked it, cancel() only flips status without hiding the record. That is
 * a dead end, not evidentiary rigour — Johan's own design standard makes
 * archive/restore the floor on every entity. Conductor's ruling: archive it
 * regardless of observations (delete() on this table is already a SOFT
 * delete via the existing softDeletes() column — restore() already existed
 * — so nothing about evidence retention changes), but the record must also
 * carry WHO archived it, not just WHEN (deleted_at already answers when).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspections', function (Blueprint $table) {
            $table->foreignId('archived_by_user_id')->nullable()->after('cancel_reason')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('archived_by_user_id');
        });
    }
};
