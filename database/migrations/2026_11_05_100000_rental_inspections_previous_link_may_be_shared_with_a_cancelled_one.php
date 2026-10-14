<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §57 (14 Oct 2026, property 21222) — cancelling the inspection that was started on top
 * of a draft Out left the property tab blank: the cancelled row still held the draft's "next" slot, so the draft was
 * not the tail and the cancelled row (excluded as cancelled) was not either. A cancelled or archived inspection is no
 * longer part of the chain, but it keeps its previous_inspection_id as history — so the one-row-per-predecessor UNIQUE
 * index has to become a plain index. "One live successor, never a fork" is enforced in RentalInspection::startNext()
 * (serialised on the predecessor row) and read through RentalInspection::nextInChain().
 *
 * The foreign key needs an index on the column at all times, so the plain index is added BEFORE the unique one goes.
 *
 * Back-fill: an inspection that was cancelled or archived earlier while something already followed it — the follower is
 * re-pointed at the cancelled one's own predecessor (newest first, so a run of cancelled links collapses in one pass).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspections', function (Blueprint $table) {
            $table->index('previous_inspection_id', 'rental_insp_prev_insp_idx');
        });
        Schema::table('rental_inspections', function (Blueprint $table) {
            $table->dropUnique('rental_insp_prev_insp_unique');
        });

        DB::table('rental_inspections')
            ->where(fn ($q) => $q->where('status', 'cancelled')->orWhereNotNull('deleted_at'))
            ->orderByDesc('id')
            ->get(['id', 'previous_inspection_id'])
            ->each(function ($dead) {
                DB::table('rental_inspections')
                    ->where('previous_inspection_id', $dead->id)
                    ->where('status', '!=', 'cancelled')
                    ->whereNull('deleted_at')
                    ->update(['previous_inspection_id' => $dead->previous_inspection_id]);
            });
    }

    public function down(): void
    {
        // Not restorable without losing rows that share a predecessor; the plain index stays.
    }
};
